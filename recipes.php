<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$catFilter = $_GET['category'] ?? '';

$sql = "
    SELECT r.*, c.name as category_name, u.username
    FROM recipes r
    JOIN categories c ON r.category_id = c.id
    JOIN users u ON r.user_id = u.id
    WHERE (r.user_id = ? OR r.is_public = 1)
";
$params = [$user['id']];

if (!empty($catFilter)) {
    $sql .= " AND c.name = ?";
    $params[] = $catFilter;
}

$sql .= " ORDER BY r.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$recipes = $stmt->fetchAll();

$pageTitle = "Recipes - " . APP_NAME;
$activePage = 'recipes';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>📖 Recipe Book</h1>
        <p style="color: var(--text-muted);">Manage and discover formulas for beer, wine, and cider.</p>
    </div>
    <a href="recipe_edit.php?action=new" class="btn btn-primary">+ Create New Recipe</a>
</div>

<!-- Category Filters -->
<div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
    <a href="recipes.php" class="btn <?= empty($catFilter) ? 'btn-primary' : 'btn-secondary' ?> btn-sm">All Recipes</a>
    <a href="recipes.php?category=Beer" class="btn <?= $catFilter === 'Beer' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">🍺 Beer</a>
    <a href="recipes.php?category=Wine" class="btn <?= $catFilter === 'Wine' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">🍷 Wine</a>
    <a href="recipes.php?category=Cider" class="btn <?= $catFilter === 'Cider' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">🍏 Cider</a>
    <a href="recipes.php?category=Fruit Wine" class="btn <?= $catFilter === 'Fruit Wine' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">🍇 Fruit Wine</a>
</div>

<?php if (empty($recipes)): ?>
    <div class="card" style="text-align: center; color: var(--text-muted); padding: 3rem;">
        No recipes saved yet. <a href="recipe_edit.php?action=new">Add your first brewing recipe</a>!
    </div>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($recipes as $r): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span class="badge badge-<?= strtolower($r['category_name']) ?>"><?= htmlspecialchars($r['category_name']) ?></span>
                    <small style="color: var(--text-muted);"><?= $r['batch_size_gal'] ?> Gal Batch</small>
                </div>
                <h3 class="card-title"><a href="recipe_detail.php?id=<?= $r['id'] ?>" style="color: inherit; text-decoration: none;"><?= htmlspecialchars($r['name']) ?></a></h3>
                <p class="card-subtitle"><?= htmlspecialchars($r['style'] ?: 'Craft Recipe') ?> &bull; By <?= htmlspecialchars($r['username']) ?></p>

                <div style="font-size: 0.9rem; margin-bottom: 1rem; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                    <div><strong>Target OG:</strong> <?= $r['target_og'] ? sprintf('%.3f', $r['target_og']) : 'N/A' ?></div>
                    <div><strong>Target FG:</strong> <?= $r['target_fg'] ? sprintf('%.3f', $r['target_fg']) : 'N/A' ?></div>
                    <div><strong>Est. ABV:</strong> <?= $r['target_abv'] ? $r['target_abv'] . '%' : 'N/A' ?></div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <a href="recipe_detail.php?id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">View Recipe</a>
                    <a href="batch_edit.php?action=new&recipe_id=<?= $r['id'] ?>" class="btn btn-primary btn-sm">🍺 Start Batch</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
