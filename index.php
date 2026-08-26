<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();

$db = get_db();
init_schema();

// Fetch summary metrics
$totalBatches = $db->query("SELECT COUNT(*) FROM batches WHERE user_id = {$user['id']}")->fetchColumn();
$activeBatches = $db->query("SELECT COUNT(*) FROM batches WHERE user_id = {$user['id']} AND status IN ('Primary', 'Secondary', 'Bottling/Aging')")->fetchColumn();
$totalRecipes = $db->query("SELECT COUNT(*) FROM recipes WHERE user_id = {$user['id']}")->fetchColumn();
$totalDocs = $db->query("SELECT COUNT(*) FROM documents WHERE user_id = {$user['id']}")->fetchColumn();

// Fetch active fermentations
$stmtActive = $db->prepare("
    SELECT b.*, c.name as category_name
    FROM batches b
    JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ? AND b.status IN ('Primary', 'Secondary', 'Bottling/Aging')
    ORDER BY b.date_start DESC
    LIMIT 5
");
$stmtActive->execute([$user['id']]);
$activeList = $stmtActive->fetchAll();

// Fetch recent completed batches
$stmtRecent = $db->prepare("
    SELECT b.*, c.name as category_name
    FROM batches b
    JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
    LIMIT 6
");
$stmtRecent->execute([$user['id']]);
$recentList = $stmtRecent->fetchAll();

$pageTitle = "Dashboard - " . APP_NAME;
$activePage = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>🍺 Brewer Dashboard</h1>
        <p style="color: var(--text-muted);">Welcome back, <strong><?= htmlspecialchars($user['username']) ?></strong>! Track your beer, wine, and cider craft brews.</p>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <a href="batch_edit.php?action=new" class="btn btn-primary">+ New Brew Batch</a>
        <a href="recipe_edit.php?action=new" class="btn btn-secondary">+ New Recipe</a>
        <a href="import_legacy_logs.php" class="btn btn-secondary">📥 Import Legacy Data</a>
    </div>
</div>

<!-- Stats Row -->
<div class="card-grid">
    <div class="card">
        <div class="card-subtitle">Active Fermentations</div>
        <div style="font-size: 2rem; font-weight: 800; color: var(--primary-color);"><?= $activeBatches ?></div>
        <small style="color: var(--text-muted);">In primary, secondary or aging</small>
    </div>
    <div class="card">
        <div class="card-subtitle">Total Brew Logs</div>
        <div style="font-size: 2rem; font-weight: 800; color: #1e293b;"><?= $totalBatches ?></div>
        <small style="color: var(--text-muted);">Recorded brew batches</small>
    </div>
    <div class="card">
        <div class="card-subtitle">Saved Recipes</div>
        <div style="font-size: 2rem; font-weight: 800; color: #3b82f6;"><?= $totalRecipes ?></div>
        <small style="color: var(--text-muted);">Beer, Wine & Cider formulas</small>
    </div>
    <div class="card">
        <div class="card-subtitle">Reference Library</div>
        <div style="font-size: 2rem; font-weight: 800; color: #10b981;"><?= $totalDocs ?></div>
        <small style="color: var(--text-muted);">Imported PDFs & brewing guides</small>
    </div>
</div>

<!-- Active Fermentations Section -->
<div style="margin-bottom: 2.5rem;">
    <h2>🔥 Active Fermentations</h2>
    <?php if (empty($activeList)): ?>
        <div class="card" style="text-align: center; color: var(--text-muted); padding: 2rem;">
            No active fermentations currently in progress. <a href="batch_edit.php?action=new">Start a new batch</a> to track gravity and logs!
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Batch Name</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>OG</th>
                        <th>Latest SG</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeList as $b): ?>
                        <tr>
                            <td>
                                <strong><a href="batch_detail.php?id=<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_name']) ?></a></strong>
                                <br><small style="color: var(--text-muted);"><?= htmlspecialchars($b['batch_style']) ?></small>
                            </td>
                            <td>
                                <span class="badge badge-<?= strtolower($b['category_name']) ?>"><?= htmlspecialchars($b['category_name']) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?= strtolower(str_replace(['/', ' '], '', $b['status'])) ?>"><?= htmlspecialchars($b['status']) ?></span>
                            </td>
                            <td><?= $b['date_start'] ? date('M d, Y', strtotime($b['date_start'])) : 'N/A' ?></td>
                            <td><?= $b['gravity_og'] ? sprintf('%.3f', $b['gravity_og']) : '--' ?></td>
                            <td><?= $b['gravity_sg'] ? sprintf('%.3f', $b['gravity_sg']) : ($b['gravity_og'] ? sprintf('%.3f', $b['gravity_og']) : '--') ?></td>
                            <td>
                                <a href="batch_detail.php?id=<?= $b['id'] ?>" class="btn btn-primary btn-sm">Log Readings</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Recent Brew Batches Grid -->
<div>
    <h2>📚 Recent Brew Logs</h2>
    <?php if (empty($recentList)): ?>
        <p style="color: var(--text-muted);">No brew logs found. Run <a href="import_legacy_logs.php">Import Legacy Data</a> to seed your historical 10 batches!</p>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($recentList as $b): ?>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span class="badge badge-<?= strtolower($b['category_name']) ?>"><?= htmlspecialchars($b['category_name']) ?></span>
                        <span class="badge badge-<?= strtolower(str_replace(['/', ' '], '', $b['status'])) ?>"><?= htmlspecialchars($b['status']) ?></span>
                    </div>
                    <h3 class="card-title"><a href="batch_detail.php?id=<?= $b['id'] ?>" style="color: inherit; text-decoration: none;"><?= htmlspecialchars($b['batch_name']) ?></a></h3>
                    <p class="card-subtitle"><?= htmlspecialchars($b['batch_style'] ?: 'Craft Brew') ?> &bull; <?= $b['batch_size_gal'] ?> Gal</p>
                    
                    <div style="font-size: 0.9rem; margin-bottom: 1rem; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                        <div><strong>Start:</strong> <?= $b['date_start'] ? date('M d, Y', strtotime($b['date_start'])) : 'N/A' ?></div>
                        <div><strong>ABV:</strong> <?= $b['calculated_abv'] ? $b['calculated_abv'] . '%' : 'N/A' ?></div>
                        <div><strong>Rating:</strong> <?= $b['rating'] > 0 ? "⭐ {$b['rating']}/10" : "Unrated" ?></div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <a href="batch_detail.php?id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm">View Log</a>
                        <a href="export_pdf.php?type=batch&id=<?= $b['id'] ?>" class="btn btn-primary btn-sm" target="_blank">📄 Export PDF</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
