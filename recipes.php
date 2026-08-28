<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$message = '';
$error = '';

// Handle Recipe Import (BeerXML & JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_recipe') {
    require_csrf_token();

    if (!empty($_FILES['recipe_file']['name'])) {
        $tmpFile = $_FILES['recipe_file']['tmp_name'];
        $origName = basename($_FILES['recipe_file']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['xml', 'json'])) {
            $error = "Unsupported file format. Please upload a BeerXML (.xml) or CraftBrew (.json) file.";
        } else {
            $content = file_get_contents($tmpFile);

            // Handle JSON import
            if ($ext === 'json') {
                $data = json_decode($content, true);
                if (!$data || empty($data['name'])) {
                    $error = "Invalid or corrupted CraftBrew JSON recipe file.";
                } else {
                    $recipeName = sanitize_text($data['name'], 100);
                    $style = sanitize_text($data['style'] ?? '', 100);
                    $batchSize = validate_batch_size($data['batch_size_gal'] ?? 5.0);
                    $og = validate_gravity($data['target_og'] ?? null);
                    $fg = validate_gravity($data['target_fg'] ?? null);
                    $abv = !empty($data['target_abv']) ? round((float)$data['target_abv'], 2) : null;
                    $instructions = sanitize_text($data['instructions'] ?? '', 5000);
                    $rawIng = sanitize_text($data['ingredients_raw'] ?? '', 5000);

                    // Category lookup
                    $catName = sanitize_text($data['category'] ?? 'Beer', 50);
                    $cStmt = $db->prepare("SELECT id FROM categories WHERE name = ?");
                    $cStmt->execute([$catName]);
                    $catId = (int)$cStmt->fetchColumn() ?: 1;

                    $ins = $db->prepare("INSERT INTO recipes (user_id, category_id, name, style, batch_size_gal, target_og, target_fg, target_abv, ingredients, instructions, is_public) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $ins->execute([$user['id'], $catId, $recipeName, $style, $batchSize, $og, $fg, $abv, $rawIng, $instructions]);
                    $newRecipeId = (int)$db->lastInsertId();

                    // Save structured ingredients
                    if (!empty($data['ingredients']) && is_array($data['ingredients'])) {
                        $ingIns = $db->prepare("INSERT INTO recipe_ingredients (recipe_id, name, ingredient_type, amount, unit, stage_addition, notes, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        foreach ($data['ingredients'] as $idx => $ing) {
                            $ingIns->execute([
                                $newRecipeId,
                                sanitize_text($ing['name'] ?? '', 100),
                                validate_enum($ing['ingredient_type'] ?? 'Fermentable', ['Fermentable', 'Hop', 'Yeast', 'Additive', 'Fining', 'Water', 'Other'], 'Fermentable'),
                                sanitize_float($ing['amount'] ?? 0),
                                sanitize_text($ing['unit'] ?? 'lbs', 20),
                                sanitize_text($ing['stage_addition'] ?? 'Primary', 50),
                                sanitize_text($ing['notes'] ?? '', 255),
                                $idx
                            ]);
                        }
                    }

                    // Save structured steps
                    if (!empty($data['steps']) && is_array($data['steps'])) {
                        $stepIns = $db->prepare("INSERT INTO recipe_steps (recipe_id, step_number, step_name, target_temp_f, duration_minutes, description, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        foreach ($data['steps'] as $idx => $st) {
                            $stepIns->execute([
                                $newRecipeId,
                                $idx + 1,
                                sanitize_text($st['step_name'] ?? 'Step', 100),
                                validate_temp($st['target_temp_f'] ?? ''),
                                sanitize_int($st['duration_minutes'] ?? 0),
                                sanitize_text($st['description'] ?? '', 2000),
                                $idx
                            ]);
                        }
                    }

                    $message = "Recipe '{$recipeName}' imported successfully from JSON!";
                }
            }

            // Handle BeerXML import
            if ($ext === 'xml') {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($content);

                if (!$xml || !isset($xml->RECIPE)) {
                    $error = "Invalid or unparseable BeerXML format.";
                } else {
                    $rec = $xml->RECIPE;
                    $recipeName = sanitize_text((string)$rec->NAME, 100) ?: 'Imported BeerXML Recipe';
                    $style = sanitize_text((string)($rec->STYLE->NAME ?? 'Craft Beer'), 100);
                    $liters = (float)($rec->BATCH_SIZE ?? 18.927);
                    $batchSizeGal = round($liters * 0.264172, 2);
                    $og = validate_gravity((string)($rec->OG ?? ''));
                    $fg = validate_gravity((string)($rec->FG ?? ''));
                    $abv = !empty($rec->EST_ABV) ? round((float)$rec->EST_ABV, 2) : null;
                    $notes = sanitize_text((string)($rec->NOTES ?? ''), 5000);

                    // Category lookup
                    $cStmt = $db->prepare("SELECT id FROM categories WHERE name = 'Beer'");
                    $cStmt->execute();
                    $catId = (int)$cStmt->fetchColumn() ?: 1;

                    $ins = $db->prepare("INSERT INTO recipes (user_id, category_id, name, style, batch_size_gal, target_og, target_fg, target_abv, instructions, is_public) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $ins->execute([$user['id'], $catId, $recipeName, $style, $batchSizeGal, $og, $fg, $abv, $notes]);
                    $newRecipeId = (int)$db->lastInsertId();

                    $ingIns = $db->prepare("INSERT INTO recipe_ingredients (recipe_id, name, ingredient_type, amount, unit, stage_addition, notes, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $order = 0;

                    if (isset($rec->FERMENTABLES->FERMENTABLE)) {
                        foreach ($rec->FERMENTABLES->FERMENTABLE as $f) {
                            $kg = (float)($f->AMOUNT ?? 0);
                            $lbs = round($kg * 2.20462, 3);
                            $ingIns->execute([$newRecipeId, sanitize_text((string)$f->NAME, 100), 'Fermentable', $lbs, 'lbs', 'Mash', '', $order++]);
                        }
                    }

                    if (isset($rec->HOPS->HOP)) {
                        foreach ($rec->HOPS->HOP as $h) {
                            $kg = (float)($h->AMOUNT ?? 0);
                            $oz = round($kg * 35.274, 3);
                            $use = sanitize_text((string)($h->USE ?? 'Boil'), 50);
                            $time = (string)($h->TIME ?? '');
                            $ingIns->execute([$newRecipeId, sanitize_text((string)$h->NAME, 100), 'Hop', $oz, 'oz', $use, $time ? "{$time} min" : '', $order++]);
                        }
                    }

                    if (isset($rec->YEASTS->YEAST)) {
                        foreach ($rec->YEASTS->YEAST as $y) {
                            $ingIns->execute([$newRecipeId, sanitize_text((string)$y->NAME, 100), 'Yeast', 1.000, 'pkg', 'Primary', '', $order++]);
                        }
                    }

                    $message = "BeerXML Recipe '{$recipeName}' parsed and imported successfully!";
                }
            }
        }
    }
}

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

$csrfToken = generate_csrf_token();
$pageTitle = "Recipes - " . APP_NAME;
$activePage = 'recipes';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>📖 Recipe Book</h1>
        <p style="color: var(--text-muted);">Manage, formulate, and import formulas for beer, wine, cider, and mead.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <button type="button" class="btn btn-secondary" onclick="openImportModal()">📥 Import Recipe (XML / JSON)</button>
        <a href="recipe_edit.php?action=new" class="btn btn-primary">+ Create New Recipe</a>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div style="background: #dcfce7; color: #166534; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #bbf7d0;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Category Filters -->
<div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
    <a href="recipes.php" class="btn <?= empty($catFilter) ? 'btn-primary' : 'btn-secondary' ?> btn-sm">All Recipes</a>
    <a href="recipes.php?category=Beer" class="btn <?= $catFilter === 'Beer' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">🍺 Beer</a>
    <a href="recipes.php?category=Wine" class="btn <?= $catFilter === 'Wine' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">🍷 Wine</a>
    <a href="recipes.php?category=Cider" class="btn <?= $catFilter === 'Cider' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">🍏 Cider</a>
    <a href="recipes.php?category=Mead" class="btn <?= $catFilter === 'Mead' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">🍯 Mead</a>
    <a href="recipes.php?category=Fruit+Wine" class="btn <?= $catFilter === 'Fruit Wine' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">🍓 Fruit Wine</a>
</div>

<!-- Recipes Grid -->
<?php if (empty($recipes)): ?>
    <div class="card" style="text-align: center; color: var(--text-muted); padding: 3rem;">
        No recipes found in this category. Click <strong>+ Create New Recipe</strong> or <strong>📥 Import Recipe</strong> to get started!
    </div>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($recipes as $r): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span class="badge badge-<?= strtolower(e($r['category_name'])) ?>"><?= e($r['category_name']) ?></span>
                    <small style="color: var(--text-muted);"><?= (float)$r['batch_size_gal'] ?> Gal</small>
                </div>
                <h3 class="card-title"><?= e($r['name']) ?></h3>
                <p class="card-subtitle"><?= e($r['style'] ?: 'Craft Recipe') ?></p>

                <div style="display: flex; gap: 1rem; margin: 1rem 0; font-size: 0.85rem; color: var(--text-muted);">
                    <div>OG: <strong><?= $r['target_og'] ? e($r['target_og']) : '--' ?></strong></div>
                    <div>FG: <strong><?= $r['target_fg'] ? e($r['target_fg']) : '--' ?></strong></div>
                    <div>ABV: <strong><?= $r['target_abv'] ? e($r['target_abv']) . '%' : '--' ?></strong></div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; border-top: 1px solid var(--border); padding-top: 0.75rem;">
                    <small style="color: var(--text-muted);">By: <?= e($r['username']) ?></small>
                    <a href="recipe_detail.php?id=<?= (int)$r['id'] ?>" class="btn btn-secondary btn-sm">View Details &raquo;</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Recipe Import Modal -->
<div id="importRecipeModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 500px; padding: 1.5rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-bottom: 1rem;">📥 Import Recipe File</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
            Upload a standard <strong>BeerXML (.xml)</strong> file or a <strong>CraftBrew JSON (.json)</strong> file exported from Beersmith, Brewfather, or CraftBrew.
        </p>

        <form method="POST" action="recipes.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="import_recipe">

            <div class="form-group">
                <label class="form-label">Select Recipe File (.xml or .json)</label>
                <input type="file" name="recipe_file" class="form-control" accept=".xml,.json" required>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeImportModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Import Recipe</button>
            </div>
        </form>
    </div>
</div>

<script>
function openImportModal() {
    document.getElementById('importRecipeModal').style.display = 'flex';
}
function closeImportModal() {
    document.getElementById('importRecipeModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
