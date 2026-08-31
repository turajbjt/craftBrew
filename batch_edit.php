<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$action   = $_GET['action'] ?? 'new';
$batchId  = sanitize_int($_GET['id'] ?? 0);
$recipeId = sanitize_int($_GET['recipe_id'] ?? 0);
$error    = '';

$b = [
    'id' => 0,
    'category_id' => 1,
    'recipe_id' => null,
    'batch_name' => '',
    'batch_type' => '',
    'batch_style' => '',
    'batch_size_gal' => 5.0,
    'date_start' => date('Y-m-d'),
    'date_rack' => '',
    'date_rack_2' => '',
    'date_rack_3' => '',
    'date_bottle' => '',
    'pitch_temp_f' => '72F',
    'ferment_temp_f' => '68F',
    'gravity_pre_og' => '',
    'gravity_og' => '',
    'gravity_sg' => '',
    'gravity_fg' => '',
    'ingredients' => '',
    'boil_notes' => '',
    'reflections' => '',
    'rating' => 0,
    'status' => 'Primary'
];

if ($action === 'edit' && $batchId > 0) {
    $stmt = $db->prepare("SELECT * FROM batches WHERE id = ? AND user_id = ?");
    $stmt->execute([$batchId, $user['id']]);
    $fetched = $stmt->fetch();
    if ($fetched) {
        $b = array_merge($b, $fetched);
    } else {
        die("Batch log not found or access denied.");
    }
} elseif ($action === 'new' && $recipeId > 0) {
    // Prefill from selected recipe
    $stmtR = $db->prepare("SELECT * FROM recipes WHERE id = ? AND (user_id = ? OR is_public = 1)");
    $stmtR->execute([$recipeId, $user['id']]);
    $rec = $stmtR->fetch();
    if ($rec) {
        $b['recipe_id']      = $rec['id'];
        $b['category_id']    = $rec['category_id'];
        $b['batch_name']      = $rec['name'] . ' Batch #' . date('Ymd');
        $b['batch_type']      = $rec['name'];
        $b['batch_style']     = $rec['style'];
        $b['batch_size_gal']  = $rec['batch_size_gal'];
        $b['gravity_pre_og']  = $rec['target_pre_og'] ?? '';
        $b['gravity_og']      = $rec['target_og'];
        $b['gravity_fg']      = $rec['target_fg'];
        $b['ingredients']     = $rec['ingredients'];
        $b['boil_notes']      = $rec['instructions'];
    }
}

// Fetch categories
$categories = $db->query("SELECT * FROM categories ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $catId       = sanitize_int($_POST['category_id'] ?? 1);
    $recId       = !empty($_POST['recipe_id']) ? sanitize_int($_POST['recipe_id']) : null;
    $batchName   = sanitize_text($_POST['batch_name'] ?? '', 100);
    $batchType   = sanitize_text($_POST['batch_type'] ?? '', 50);
    $batchStyle  = sanitize_text($_POST['batch_style'] ?? '', 100);
    $batchSize   = validate_batch_size($_POST['batch_size_gal'] ?? 5.0, 5.0);
    $dateStart   = validate_date($_POST['date_start'] ?? null);
    $dateRack    = validate_date($_POST['date_rack'] ?? null);
    $dateRack2   = validate_date($_POST['date_rack_2'] ?? null);
    $dateRack3   = validate_date($_POST['date_rack_3'] ?? null);
    $dateBottle  = validate_date($_POST['date_bottle'] ?? null);
    $pitchTemp   = validate_temp($_POST['pitch_temp_f'] ?? '');
    $fermentTemp = validate_temp($_POST['ferment_temp_f'] ?? '');
    $preOg       = validate_gravity($_POST['gravity_pre_og'] ?? null);
    $og          = validate_gravity($_POST['gravity_og'] ?? null);
    $sg          = validate_gravity($_POST['gravity_sg'] ?? null);
    $fg          = validate_gravity($_POST['gravity_fg'] ?? null);
    $ingredients = sanitize_text($_POST['ingredients'] ?? '', 5000);
    $boilNotes   = sanitize_text($_POST['boil_notes'] ?? '', 5000);
    $reflections = sanitize_text($_POST['reflections'] ?? '', 5000);
    $rating      = validate_rating($_POST['rating'] ?? 0);
    
    $allowedStatuses = ['Planning', 'Must Prep', 'Primary', 'Secondary', 'Bottling/Aging', 'Completed'];
    $status      = validate_enum($_POST['status'] ?? '', $allowedStatuses, 'Primary');

    if (empty($batchName)) {
        $error = "Batch Name is required.";
    } else {
        $abv = calculate_abv($og, $fg);

        if ($b['id'] > 0) {
            $up = $db->prepare("
                UPDATE batches SET
                    recipe_id = ?, category_id = ?, batch_name = ?, batch_type = ?, batch_style = ?,
                    batch_size_gal = ?, date_start = ?, date_rack = ?, date_rack_2 = ?, date_rack_3 = ?, date_bottle = ?,
                    pitch_temp_f = ?, ferment_temp_f = ?, gravity_pre_og = ?, gravity_og = ?, gravity_sg = ?,
                    gravity_fg = ?, calculated_abv = ?, ingredients = ?, boil_notes = ?,
                    reflections = ?, rating = ?, status = ?
                WHERE id = ? AND user_id = ?
            ");
            $up->execute([
                $recId, $catId, $batchName, $batchType, $batchStyle,
                $batchSize, $dateStart ?: null, $dateRack ?: null, $dateRack2 ?: null, $dateRack3 ?: null, $dateBottle ?: null,
                $pitchTemp, $fermentTemp, $preOg, $og, $sg,
                $fg, $abv, $ingredients, $boilNotes,
                $reflections, $rating, $status,
                $b['id'], $user['id']
            ]);
            header("Location: batch_detail.php?id=" . $b['id']);
            exit;
        } else {
            $ins = $db->prepare("
                INSERT INTO batches (
                    user_id, recipe_id, category_id, batch_name, batch_type, batch_style,
                    batch_size_gal, date_start, date_rack, date_rack_2, date_rack_3, date_bottle,
                    pitch_temp_f, ferment_temp_f, gravity_pre_og, gravity_og, gravity_sg,
                    gravity_fg, calculated_abv, ingredients, boil_notes,
                    reflections, rating, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?
                )
            ");
            $ins->execute([
                $user['id'], $recId, $catId, $batchName, $batchType, $batchStyle,
                $batchSize, $dateStart ?: null, $dateRack ?: null, $dateRack2 ?: null, $dateRack3 ?: null, $dateBottle ?: null,
                $pitchTemp, $fermentTemp, $preOg, $og, $sg,
                $fg, $abv, $ingredients, $boilNotes,
                $reflections, $rating, $status
            ]);
            $newId = $db->lastInsertId();
            if (!empty($_POST['deduct_inventory']) && $recId > 0) {
                deduct_inventory_for_batch($user['id'], $recId);
            }
            header("Location: batch_detail.php?id=" . $newId);
            exit;
        }
    }
}

$csrfToken = generate_csrf_token();
$pageTitle = ($b['id'] > 0 ? "Edit" : "New") . " Brew Batch - " . APP_NAME;
$activePage = 'batches';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div class="card">
        <h2 class="card-title"><?= $b['id'] > 0 ? "✏️ Edit Brew Batch" : "🍺 Start / Log New Brew Batch" ?></h2>
        <p class="card-subtitle">Document your brew specs, target gravities, ingredients, and process notes.</p>

        <?php if (!empty($error)): ?>
            <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="batch_edit.php?action=<?= e($action) ?>&id=<?= (int)$b['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="recipe_id" value="<?= (int)$b['recipe_id'] ?>">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= $b['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Batch Name / Kit</label>
                    <input type="text" name="batch_name" class="form-control" value="<?= e($b['batch_name']) ?>" required placeholder="e.g. Fire Island Pale Ale or Orchard Hard Cider">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Brew Type / Style</label>
                    <input type="text" name="batch_type" class="form-control" value="<?= e($b['batch_type']) ?>" placeholder="e.g. IPA, Stout, Hard Cider, Blush Wine">
                </div>

                <div class="form-group">
                    <label class="form-label">Batch Size (Gallons)</label>
                    <input type="number" step="0.1" name="batch_size_gal" class="form-control" value="<?= (float)$b['batch_size_gal'] ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Current Stage / Status</label>
                    <select name="status" class="form-control">
                        <option value="Planning" <?= $b['status'] === 'Planning' ? 'selected' : '' ?>>Planning</option>
                        <option value="Must Prep" <?= $b['status'] === 'Must Prep' ? 'selected' : '' ?>>Must Prep / Sulfiting (Pre-Ferment)</option>
                        <option value="Primary" <?= $b['status'] === 'Primary' ? 'selected' : '' ?>>Primary Fermentation</option>
                        <option value="Secondary" <?= $b['status'] === 'Secondary' ? 'selected' : '' ?>>Secondary / Racking</option>
                        <option value="Bottling/Aging" <?= $b['status'] === 'Bottling/Aging' ? 'selected' : '' ?>>Bottling / Aging</option>
                        <option value="Completed" <?= $b['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="date_start" class="form-control" value="<?= e($b['date_start']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">1st Rack Date (Secondary)</label>
                    <input type="date" name="date_rack" class="form-control" value="<?= e($b['date_rack']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">2nd Rack Date</label>
                    <input type="date" name="date_rack_2" class="form-control" value="<?= e($b['date_rack_2']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">3rd Rack Date (Tertiary)</label>
                    <input type="date" name="date_rack_3" class="form-control" value="<?= e($b['date_rack_3']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Bottled / Kegged Date</label>
                    <input type="date" name="date_bottle" class="form-control" value="<?= e($b['date_bottle']) ?>">
                </div>
            </div>

            <div class="form-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; justify-content: space-between;">
                        <span>Raw Must/Juice OG</span>
                        <small style="color: var(--text-muted); font-weight: normal;">(Pre-Sugar/Optional)</small>
                    </label>
                    <input type="number" step="0.001" id="calc_pre_og" name="gravity_pre_og" class="form-control" value="<?= e($b['gravity_pre_og'] ?? '') ?>" placeholder="e.g. 1.045 (Raw pressed juice)">
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.25rem;">Initial raw juice reading before adding sugar/chaptalization.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; justify-content: space-between;">
                        <span>Starting OG</span>
                        <small style="color: var(--text-muted); font-weight: normal;">(Post-Sugar/Inoculated)</small>
                    </label>
                    <input type="number" step="0.001" id="calc_og" name="gravity_og" class="form-control" value="<?= e($b['gravity_og']) ?>" placeholder="e.g. 1.085 (Pitch gravity)">
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.25rem;">Starting OG when fermentation begins.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Final Gravity (FG)</label>
                    <input type="number" step="0.001" id="calc_fg" name="gravity_fg" class="form-control" value="<?= e($b['gravity_fg']) ?>" placeholder="e.g. 0.998">
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.25rem;">Final finished gravity measurement.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Calculated Total ABV</label>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); padding: 0.4rem 0;" id="calc_abv_result">
                        <?= $b['calculated_abv'] ? e($b['calculated_abv']) . '%' : '--%' ?>
                    </div>
                    <small id="chaptalization_badge" style="color: #b45309; font-size: 0.8rem; font-weight: 700; display: none;"></small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Pitch Temp (°F)</label>
                    <input type="text" name="pitch_temp_f" class="form-control" value="<?= e($b['pitch_temp_f']) ?>" placeholder="74F">
                </div>

                <div class="form-group">
                    <label class="form-label">Ferment Temp (°F)</label>
                    <input type="text" name="ferment_temp_f" class="form-control" value="<?= e($b['ferment_temp_f']) ?>" placeholder="68F">
                </div>

                <div class="form-group">
                    <label class="form-label">Batch Rating (0 - 10)</label>
                    <input type="number" min="0" max="10" name="rating" class="form-control" value="<?= (int)$b['rating'] ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Ingredients List</label>
                <textarea name="ingredients" class="form-control" rows="4" placeholder="List malts, grains, juices, sugars, yeasts, hops..."><?= e($b['ingredients']) ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Boil & Process Notes</label>
                <textarea name="boil_notes" class="form-control" rows="3" placeholder="Steeping times, boil schedule, chilling method, carboy additions..."><?= e($b['boil_notes']) ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Tasting Notes, Reflections & Modifications</label>
                <textarea name="reflections" class="form-control" rows="3" placeholder="Aroma, clarity, mouthfeel, carbonation, things to change next batch..."><?= e($b['reflections']) ?></textarea>
            </div>

            <?php if ($action === 'new' && $recipeId > 0): ?>
                <div class="form-group" style="margin-top: 1rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="deduct_inventory" value="1" checked>
                        <span>📦 Auto-deduct recipe ingredients from cellar inventory</span>
                    </label>
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary">Save Brew Batch</button>
                <a href="batches.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
