<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

// Filter parameters
$catFilter   = $_GET['category'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$search      = trim($_GET['q'] ?? '');

$sql = "
    SELECT b.*, c.name as category_name
    FROM batches b
    JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ?
";
$params = [$user['id']];

if (!empty($catFilter)) {
    $sql .= " AND c.name = ?";
    $params[] = $catFilter;
}

if (!empty($statusFilter)) {
    $sql .= " AND b.status = ?";
    $params[] = $statusFilter;
}

if (!empty($search)) {
    $sql .= " AND (b.batch_name LIKE ? OR b.batch_style LIKE ? OR b.ingredients LIKE ? OR b.reflections LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql .= " ORDER BY b.date_start DESC, b.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll();

$pageTitle = "Brew Logs & Batches - " . APP_NAME;
$activePage = 'batches';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>📜 Brew Batch Logs</h1>
        <p style="color: var(--text-muted);">Track making, gravity readings, racking dates, bottling & tasting reflections.</p>
    </div>
    <a href="batch_edit.php?action=new" class="btn btn-primary">+ Log New Brew Batch</a>
</div>

<!-- Filter Bar -->
<div class="card" style="margin-bottom: 1.5rem; padding: 1rem;">
    <form method="GET" action="batches.php" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
        <div style="flex: 1; min-width: 200px;">
            <input type="text" name="q" class="form-control" placeholder="Search batch name, style, ingredients..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div>
            <select name="category" class="form-control">
                <option value="">All Categories</option>
                <option value="Beer" <?= $catFilter === 'Beer' ? 'selected' : '' ?>>Beer</option>
                <option value="Wine" <?= $catFilter === 'Wine' ? 'selected' : '' ?>>Wine</option>
                <option value="Cider" <?= $catFilter === 'Cider' ? 'selected' : '' ?>>Cider</option>
                <option value="Fruit Wine" <?= $catFilter === 'Fruit Wine' ? 'selected' : '' ?>>Fruit Wine</option>
            </select>
        </div>
        <div>
            <select name="status" class="form-control">
                <option value="">All Stages</option>
                <option value="Planning" <?= $statusFilter === 'Planning' ? 'selected' : '' ?>>Planning</option>
                <option value="Must Prep" <?= $statusFilter === 'Must Prep' ? 'selected' : '' ?>>Must Prep / Sulfiting</option>
                <option value="Primary" <?= $statusFilter === 'Primary' ? 'selected' : '' ?>>Primary</option>
                <option value="Secondary" <?= $statusFilter === 'Secondary' ? 'selected' : '' ?>>Secondary</option>
                <option value="Bottling/Aging" <?= $statusFilter === 'Bottling/Aging' ? 'selected' : '' ?>>Bottling / Aging</option>
                <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($catFilter || $statusFilter || $search): ?>
            <a href="batches.php" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Batches Table -->
<?php if (empty($batches)): ?>
    <div class="card" style="text-align: center; color: var(--text-muted); padding: 3rem;">
        No brew logs match your query. <a href="batch_edit.php?action=new">Create a new batch</a> or import historical logs!
    </div>
<?php else: ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Batch Name & Style</th>
                    <th>Category</th>
                    <th>Size</th>
                    <th>Start Date</th>
                    <th>OG / FG</th>
                    <th>ABV</th>
                    <th>Rating</th>
                    <th>Stage</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batches as $b): ?>
                    <tr>
                        <td>
                            <strong><a href="batch_detail.php?id=<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_name']) ?></a></strong>
                            <br><small style="color: var(--text-muted);"><?= htmlspecialchars($b['batch_style'] ?: 'Custom Brew') ?></small>
                        </td>
                        <td>
                            <span class="badge badge-<?= strtolower($b['category_name']) ?>"><?= htmlspecialchars($b['category_name']) ?></span>
                        </td>
                        <td><?= $b['batch_size_gal'] ?> Gal</td>
                        <td><?= $b['date_start'] ? date('M d, Y', strtotime($b['date_start'])) : 'N/A' ?></td>
                        <td>
                            <?= $b['gravity_og'] ? sprintf('%.3f', $b['gravity_og']) : '--' ?> / 
                            <?= $b['gravity_fg'] ? sprintf('%.3f', $b['gravity_fg']) : '--' ?>
                        </td>
                        <td><strong><?= $b['calculated_abv'] ? $b['calculated_abv'] . '%' : '--' ?></strong></td>
                        <td><?= $b['rating'] > 0 ? "⭐ {$b['rating']}/10" : "-" ?></td>
                        <td>
                            <span class="badge badge-<?= strtolower(str_replace(['/', ' '], '', $b['status'])) ?>"><?= htmlspecialchars($b['status']) ?></span>
                        </td>
                        <td>
                            <a href="batch_detail.php?id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm">View Log</a>
                            <a href="export_pdf.php?type=batch&id=<?= $b['id'] ?>" class="btn btn-primary btn-sm" target="_blank">PDF</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
