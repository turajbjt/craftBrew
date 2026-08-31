<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_login();
require_admin();
$db = get_db();

// Handle CSV Exports
if (isset($_GET['export'])) {
    $export = validate_enum($_GET['export'] ?? '', ['users_csv', 'batches_csv'], '');

    if ($export === 'users_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_header_filename('craftbrew_users_' . date('Y-m-d') . '.csv') . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['User ID', 'Username', 'Email', 'Role', 'Status', 'Can Manage Docs', 'Registered Date']);
        $users = $db->query("SELECT id, username, email, role, status, can_manage_docs, created_at FROM users ORDER BY id ASC")->fetchAll();
        foreach ($users as $u) {
            fputcsv($out, [
                sanitize_csv_cell($u['id']),
                sanitize_csv_cell($u['username']),
                sanitize_csv_cell($u['email']),
                sanitize_csv_cell($u['role']),
                sanitize_csv_cell($u['status']),
                sanitize_csv_cell($u['can_manage_docs'] ? 'Yes' : 'No'),
                sanitize_csv_cell($u['created_at'])
            ]);
        }
        fclose($out);
        exit;
    }

    if ($export === 'batches_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_header_filename('craftbrew_batches_' . date('Y-m-d') . '.csv') . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Batch ID', 'User', 'Batch Name', 'Category', 'Style', 'Volume (Gal)', 'Start Date', 'OG', 'FG', 'ABV (%)', 'Rating', 'Status']);
        $batches = $db->query("
            SELECT b.id, u.username, b.batch_name, c.name as category_name, b.batch_style, b.batch_size_gal, b.date_start, b.gravity_og, b.gravity_fg, b.calculated_abv, b.rating, b.status 
            FROM batches b 
            JOIN users u ON b.user_id = u.id 
            JOIN categories c ON b.category_id = c.id 
            ORDER BY b.id DESC
        ")->fetchAll();
        foreach ($batches as $b) {
            fputcsv($out, [
                sanitize_csv_cell($b['id']),
                sanitize_csv_cell($b['username']),
                sanitize_csv_cell($b['batch_name']),
                sanitize_csv_cell($b['category_name']),
                sanitize_csv_cell($b['batch_style']),
                sanitize_csv_cell($b['batch_size_gal']),
                sanitize_csv_cell($b['date_start']),
                sanitize_csv_cell($b['gravity_og']),
                sanitize_csv_cell($b['gravity_fg']),
                sanitize_csv_cell($b['calculated_abv']),
                sanitize_csv_cell($b['rating']),
                sanitize_csv_cell($b['status'])
            ]);
        }
        fclose($out);
        exit;
    }
}

// 1. Fetch Category Demographics
$catStats = $db->query("
    SELECT c.name, COUNT(b.id) as batch_count, COALESCE(SUM(b.batch_size_gal), 0) as total_volume 
    FROM categories c 
    LEFT JOIN batches b ON c.id = b.category_id 
    GROUP BY c.id, c.name 
    ORDER BY batch_count DESC
")->fetchAll();

$catLabels = [];
$catCounts = [];
$catVolumes = [];
foreach ($catStats as $cs) {
    $catLabels[] = $cs['name'];
    $catCounts[] = (int)$cs['batch_count'];
    $catVolumes[] = round((float)$cs['total_volume'], 1);
}

// 2. Fetch ABV Demographics Breakdown
$abvStats = [
    'Session (< 5%)'       => (int)$db->query("SELECT COUNT(*) FROM batches WHERE calculated_abv > 0 AND calculated_abv < 5.0")->fetchColumn(),
    'Standard (5.0 - 7.5%)' => (int)$db->query("SELECT COUNT(*) FROM batches WHERE calculated_abv >= 5.0 AND calculated_abv <= 7.5")->fetchColumn(),
    'Strong (7.6 - 10.0%)'  => (int)$db->query("SELECT COUNT(*) FROM batches WHERE calculated_abv > 7.5 AND calculated_abv <= 10.0")->fetchColumn(),
    'High-Gravity (> 10%)'  => (int)$db->query("SELECT COUNT(*) FROM batches WHERE calculated_abv > 10.0")->fetchColumn(),
];

// 3. User Growth Timeline (Monthly registrations in last 6 months)
$userGrowth = $db->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month_str, COUNT(*) as new_users 
    FROM users 
    GROUP BY month_str 
    ORDER BY month_str ASC 
    LIMIT 6
")->fetchAll();

$growthLabels = [];
$growthData = [];
foreach ($userGrowth as $ug) {
    $growthLabels[] = date('M Y', strtotime($ug['month_str'] . '-01'));
    $growthData[] = (int)$ug['new_users'];
}

// 4. Top Brewed Styles
$topStyles = $db->query("
    SELECT batch_style, COUNT(*) as total_brews, AVG(calculated_abv) as avg_abv, AVG(rating) as avg_rating 
    FROM batches 
    WHERE batch_style != '' 
    GROUP BY batch_style 
    ORDER BY total_brews DESC 
    LIMIT 6
")->fetchAll();

$pageTitle = "Demographics & Analytics - Admin Portal";
$activePage = 'admin';
$adminSubPage = 'analytics';
require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/nav.php'; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2>📈 Platform Demographics &amp; Brewing Telemetry</h2>
        <p style="color: var(--text-muted);">Visual statistics on production volume, recipe trends, beverage categories, and user growth.</p>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <a href="analytics.php?export=users_csv" class="btn btn-secondary">📥 Export Users CSV</a>
        <a href="analytics.php?export=batches_csv" class="btn btn-secondary">📥 Export Batches CSV</a>
    </div>
</div>

<!-- Charts Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <!-- Beverage Category Breakdown -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">🍺 Beverage Category Demographics</h3>
        <div style="position: relative; height: 260px;">
            <canvas id="categoryChart"></canvas>
        </div>
    </div>

    <!-- ABV Strength Distribution -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">🔥 Batch ABV Strength Distribution</h3>
        <div style="position: relative; height: 260px;">
            <canvas id="abvChart"></canvas>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <!-- User Growth Curve -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">👥 User Registration Growth</h3>
        <div style="position: relative; height: 260px;">
            <canvas id="growthChart"></canvas>
        </div>
    </div>

    <!-- Top Styles Leaderboard -->
    <div class="card" style="padding: 0; overflow-x: auto;">
        <div style="padding: 1.25rem 1.25rem 0.5rem 1.25rem;">
            <h3 class="card-title">🏆 Most Popular Brewed Styles</h3>
        </div>
        <table class="data-table" style="font-size: 0.9rem;">
            <thead>
                <tr>
                    <th>Style</th>
                    <th>Batches</th>
                    <th>Avg ABV</th>
                    <th>Avg Score</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($topStyles)): ?>
                    <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 2rem;">No style logs recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($topStyles as $ts): ?>
                        <tr>
                            <td><strong><?= e($ts['batch_style']) ?></strong></td>
                            <td><?= (int)$ts['total_brews'] ?></td>
                            <td><?= number_format((float)$ts['avg_abv'], 1) ?>%</td>
                            <td><?= $ts['avg_rating'] > 0 ? number_format((float)$ts['avg_rating'], 1) . ' / 10' : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Category Chart
    const catCtx = document.getElementById('categoryChart').getContext('2d');
    new Chart(catCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($catLabels) ?>,
            datasets: [{
                data: <?= json_encode($catCounts) ?>,
                backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#64748b']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    // 2. ABV Distribution Chart
    const abvCtx = document.getElementById('abvChart').getContext('2d');
    new Chart(abvCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_keys($abvStats)) ?>,
            datasets: [{
                label: 'Batches',
                data: <?= json_encode(array_values($abvStats)) ?>,
                backgroundColor: '#3b82f6',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    // 3. User Growth Chart
    const growthCtx = document.getElementById('growthChart').getContext('2d');
    new Chart(growthCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($growthLabels) ?>,
            datasets: [{
                label: 'New Registrations',
                data: <?= json_encode($growthData) ?>,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
