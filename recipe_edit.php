<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$action   = $_GET['action'] ?? 'new';
$recipeId = sanitize_int($_GET['id'] ?? 0);
$error    = '';

$r = [
    'id' => 0,
    'category_id' => 1,
    'name' => '',
    'style' => '',
    'batch_size_gal' => 5.0,
    'target_og' => '',
    'target_fg' => '',
    'target_abv' => '',
    'ingredients' => '',
    'instructions' => '',
    'is_public' => 1
];

$ingredients = [];
$supplies    = [];
$steps       = [];

if ($action === 'edit' && $recipeId > 0) {
    $stmt = $db->prepare("SELECT * FROM recipes WHERE id = ? AND user_id = ?");
    $stmt->execute([$recipeId, $user['id']]);
    $fetched = $stmt->fetch();
    if ($fetched) {
        $r = array_merge($r, $fetched);
        $details = get_recipe_details($recipeId);
        $ingredients = $details['ingredients'];
        $supplies    = $details['supplies'];
        $steps       = $details['steps'];
    } else {
        die("Recipe not found or access denied.");
    }
}

$categories = $db->query("SELECT * FROM categories ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $catId        = sanitize_int($_POST['category_id'] ?? 1);
    $name         = sanitize_text($_POST['name'] ?? '', 100);
    $style        = sanitize_text($_POST['style'] ?? '', 100);
    $batchSize    = sanitize_float($_POST['batch_size_gal'] ?? 5.0);
    $targetOg     = !empty($_POST['target_og']) ? sanitize_float($_POST['target_og']) : null;
    $targetFg     = !empty($_POST['target_fg']) ? sanitize_float($_POST['target_fg']) : null;
    $instructions = sanitize_text($_POST['instructions'] ?? '', 5000);
    $isPublic     = isset($_POST['is_public']) ? 1 : 0;

    // Process POSTed ingredients array
    $postIngredients = [];
    if (isset($_POST['ing_name']) && is_array($_POST['ing_name'])) {
        foreach ($_POST['ing_name'] as $idx => $ingName) {
            $ingNameClean = sanitize_text($ingName, 100);
            if (!empty($ingNameClean)) {
                $postIngredients[] = [
                    'name'            => $ingNameClean,
                    'ingredient_type' => sanitize_text($_POST['ing_type'][$idx] ?? 'Fermentable', 50),
                    'amount'          => sanitize_float($_POST['ing_amount'][$idx] ?? 0),
                    'unit'            => sanitize_text($_POST['ing_unit'][$idx] ?? '', 20),
                    'stage_addition'  => sanitize_text($_POST['ing_stage'][$idx] ?? 'Primary', 50),
                    'notes'           => sanitize_text($_POST['ing_notes'][$idx] ?? '', 500)
                ];
            }
        }
    }

    // Process POSTed supplies array
    $postSupplies = [];
    if (isset($_POST['sup_name']) && is_array($_POST['sup_name'])) {
        foreach ($_POST['sup_name'] as $idx => $supName) {
            $supNameClean = sanitize_text($supName, 100);
            if (!empty($supNameClean)) {
                $postSupplies[] = [
                    'item_name'   => $supNameClean,
                    'category'    => sanitize_text($_POST['sup_cat'][$idx] ?? 'Equipment', 50),
                    'quantity'    => sanitize_text($_POST['sup_qty'][$idx] ?? '1 unit', 50),
                    'is_required' => !empty($_POST['sup_req'][$idx]) ? 1 : 0,
                    'notes'       => sanitize_text($_POST['sup_notes'][$idx] ?? '', 500)
                ];
            }
        }
    }

    // Process POSTed steps array
    $postSteps = [];
    if (isset($_POST['stp_title']) && is_array($_POST['stp_title'])) {
        foreach ($_POST['stp_title'] as $idx => $stpTitle) {
            $stpTitleClean = sanitize_text($stpTitle, 150);
            if (!empty($stpTitleClean)) {
                $postSteps[] = [
                    'phase'        => sanitize_text($_POST['stp_phase'][$idx] ?? 'Brew Day', 50),
                    'title'        => $stpTitleClean,
                    'duration'     => sanitize_text($_POST['stp_duration'][$idx] ?? '', 50),
                    'target_temp'  => sanitize_text($_POST['stp_temp'][$idx] ?? '', 30),
                    'instructions' => sanitize_text($_POST['stp_inst'][$idx] ?? '', 2000)
                ];
            }
        }
    }

    // Build raw text ingredients summary for backwards compatibility
    $ingTextLines = [];
    foreach ($postIngredients as $pi) {
        $ingTextLines[] = "- {$pi['amount']} {$pi['unit']} {$pi['name']} ({$pi['ingredient_type']}, {$pi['stage_addition']})";
    }
    $ingredientsTextSummary = implode("\n", $ingTextLines);

    if (empty($name)) {
        $error = "Recipe Name is required.";
    } else {
        $targetAbv = calculate_abv($targetOg, $targetFg);

        if ($r['id'] > 0) {
            $up = $db->prepare("
                UPDATE recipes SET
                    category_id = ?, name = ?, style = ?, batch_size_gal = ?,
                    target_og = ?, target_fg = ?, target_abv = ?, ingredients = ?,
                    instructions = ?, is_public = ?
                WHERE id = ? AND user_id = ?
            ");
            $up->execute([
                $catId, $name, $style, $batchSize,
                $targetOg, $targetFg, $targetAbv, $ingredientsTextSummary,
                $instructions, $isPublic, $r['id'], $user['id']
            ]);
            $targetRecipeId = $r['id'];
        } else {
            $ins = $db->prepare("
                INSERT INTO recipes (
                    user_id, category_id, name, style, batch_size_gal,
                    target_og, target_fg, target_abv, ingredients, instructions, is_public
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?
                )
            ");
            $ins->execute([
                $user['id'], $catId, $name, $style, $batchSize,
                $targetOg, $targetFg, $targetAbv, $ingredientsTextSummary, $instructions, $isPublic
            ]);
            $targetRecipeId = $db->lastInsertId();
        }

        // Save structured components
        save_recipe_details($targetRecipeId, $postIngredients, $postSupplies, $postSteps);

        header("Location: recipe_detail.php?id=" . $targetRecipeId);
        exit;
    }
}

$csrfToken = generate_csrf_token();
$pageTitle = ($r['id'] > 0 ? "Edit" : "New") . " Recipe - " . APP_NAME;
$activePage = 'recipes';
require_once __DIR__ . '/includes/header.php';
?>

<div style="width: 100%; max-width: 100%;">
    <div class="card">
        <h2 class="card-title"><?= $r['id'] > 0 ? "✏️ Edit Recipe" : "📖 Create New Recipe" ?></h2>
        <p class="card-subtitle">Define target gravities, structured ingredients, equipment checklist, and brewing steps schedule.</p>

        <?php if (!empty($error)): ?>
            <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="recipe_edit.php?action=<?= e($action) ?>&id=<?= (int)$r['id'] ?>" id="recipeForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <!-- Recipe Core Info -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= $r['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Recipe Name</label>
                    <input type="text" name="name" class="form-control" value="<?= e($r['name']) ?>" required placeholder="e.g. English Stout, Hard Apple Cider, Fruit Blush">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Brew Style</label>
                    <input type="text" name="style" class="form-control" value="<?= e($r['style']) ?>" placeholder="e.g. Dry Cider, Imperial IPA, Merlot">
                </div>

                <div class="form-group">
                    <label class="form-label">Target Batch Size (Gal)</label>
                    <input type="number" step="0.1" name="batch_size_gal" class="form-control" value="<?= (float)$r['batch_size_gal'] ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Target OG</label>
                    <input type="number" step="0.001" id="calc_og" name="target_og" class="form-control" value="<?= e($r['target_og']) ?>" placeholder="1.055">
                </div>

                <div class="form-group">
                    <label class="form-label">Target FG</label>
                    <input type="number" step="0.001" id="calc_fg" name="target_fg" class="form-control" value="<?= e($r['target_fg']) ?>" placeholder="1.010">
                </div>

                <div class="form-group">
                    <label class="form-label">Estimated ABV</label>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); padding: 0.4rem 0;" id="calc_abv_result">
                        <?= $r['target_abv'] ? e($r['target_abv']) . '%' : '--%' ?>
                    </div>
                </div>
            </div>

            <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e2e8f0;">

            <!-- 🌾 Structured Ingredients Section -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="font-size: 1.25rem;">🌾 1. Recipe Ingredients Breakdown</h3>
                <button type="button" class="btn btn-secondary btn-sm" onclick="addIngredientRow()">+ Add Ingredient</button>
            </div>

            <div style="display: flex; gap: 1rem; align-items: center; background: #f8fafc; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 1rem; flex-wrap: wrap;">
                <div style="font-size: 0.95rem; font-weight: 700; color: #166534;">
                    🌿 Estimated Bitterness: <span id="live_ibu_badge">-- IBU</span>
                </div>
                <div style="font-size: 0.95rem; font-weight: 700; color: #b45309;">
                    🎨 Estimated Color: <span id="live_srm_badge">-- SRM</span>
                </div>
            </div>
            
            <div class="table-container" style="margin-bottom: 2rem;">
                <table id="ingredientsTable">
                    <thead>
                        <tr>
                            <th style="width: 28%; min-width: 200px;">Ingredient Name</th>
                            <th style="width: 16%; min-width: 150px;">Type</th>
                            <th style="width: 12%; min-width: 90px;">Amount</th>
                            <th style="width: 12%; min-width: 100px;">Unit</th>
                            <th style="width: 18%; min-width: 150px;">Stage Addition</th>
                            <th style="width: 14%; min-width: 130px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ingredients)): ?>
                            <!-- Empty default row -->
                            <tr>
                                <td><input type="text" name="ing_name[]" class="form-control" placeholder="e.g. Liquid Malt Extract"></td>
                                <td>
                                    <select name="ing_type[]" class="form-control">
                                        <option value="Fermentable">Fermentable</option>
                                        <option value="Hop">Hop</option>
                                        <option value="Yeast">Yeast</option>
                                        <option value="Additive/Finings">Additive/Finings</option>
                                        <option value="Water/Other">Water/Other</option>
                                    </select>
                                </td>
                                <td><input type="number" step="0.01" name="ing_amount[]" class="form-control" placeholder="5.0"></td>
                                <td><input type="text" name="ing_unit[]" class="form-control" placeholder="Gal / lbs / oz / pkt"></td>
                                <td>
                                    <select name="ing_stage[]" class="form-control">
                                        <option value="Preparation">Preparation</option>
                                        <option value="Sanitation">Sanitation</option>
                                        <option value="Mash/Steep">Mash/Steep</option>
                                        <option value="Boil">Boil</option>
                                        <option value="Pitching">Pitching</option>
                                        <option value="Primary">Primary</option>
                                        <option value="Secondary">Secondary</option>
                                        <option value="Tertiary">Tertiary</option>
                                        <option value="Bottling">Bottling</option>
                                    </select>
                                </td>
                                <td style="white-space: nowrap; text-align: center;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="moveRowUp(this)" title="Move up">▲</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="moveRowDown(this)" title="Move down">▼</button>
                                    <button type="button" class="btn btn-logout btn-sm" onclick="removeRow(this)" title="Remove">Remove</button>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ingredients as $ing): ?>
                                <tr>
                                    <td><input type="text" name="ing_name[]" class="form-control" value="<?= e($ing['name']) ?>" required></td>
                                    <td>
                                        <select name="ing_type[]" class="form-control">
                                            <option value="Fermentable" <?= $ing['ingredient_type'] === 'Fermentable' ? 'selected' : '' ?>>Fermentable</option>
                                            <option value="Hop" <?= $ing['ingredient_type'] === 'Hop' ? 'selected' : '' ?>>Hop</option>
                                            <option value="Yeast" <?= $ing['ingredient_type'] === 'Yeast' ? 'selected' : '' ?>>Yeast</option>
                                            <option value="Additive/Finings" <?= $ing['ingredient_type'] === 'Additive/Finings' ? 'selected' : '' ?>>Additive/Finings</option>
                                            <option value="Water/Other" <?= $ing['ingredient_type'] === 'Water/Other' ? 'selected' : '' ?>>Water/Other</option>
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" name="ing_amount[]" class="form-control" value="<?= (float)$ing['amount'] ?>"></td>
                                    <td><input type="text" name="ing_unit[]" class="form-control" value="<?= e($ing['unit']) ?>"></td>
                                    <td>
                                        <select name="ing_stage[]" class="form-control">
                                            <option value="Preparation" <?= $ing['stage_addition'] === 'Preparation' ? 'selected' : '' ?>>Preparation</option>
                                            <option value="Sanitation" <?= $ing['stage_addition'] === 'Sanitation' ? 'selected' : '' ?>>Sanitation</option>
                                            <option value="Mash/Steep" <?= $ing['stage_addition'] === 'Mash/Steep' ? 'selected' : '' ?>>Mash/Steep</option>
                                            <option value="Boil" <?= $ing['stage_addition'] === 'Boil' ? 'selected' : '' ?>>Boil</option>
                                            <option value="Pitching" <?= $ing['stage_addition'] === 'Pitching' ? 'selected' : '' ?>>Pitching</option>
                                            <option value="Primary" <?= $ing['stage_addition'] === 'Primary' ? 'selected' : '' ?>>Primary</option>
                                            <option value="Secondary" <?= $ing['stage_addition'] === 'Secondary' ? 'selected' : '' ?>>Secondary</option>
                                            <option value="Tertiary" <?= $ing['stage_addition'] === 'Tertiary' ? 'selected' : '' ?>>Tertiary</option>
                                            <option value="Bottling" <?= $ing['stage_addition'] === 'Bottling' ? 'selected' : '' ?>>Bottling</option>
                                        </select>
                                    </td>
                                    <td style="white-space: nowrap; text-align: center;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="moveRowUp(this)" title="Move up">▲</button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="moveRowDown(this)" title="Move down">▼</button>
                                        <button type="button" class="btn btn-logout btn-sm" onclick="removeRow(this)" title="Remove">Remove</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e2e8f0;">

            <!-- 🛠️ Equipment & Supplies Checklist Section -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="font-size: 1.25rem;">🛠️ 2. Equipment & Supplies Checklist</h3>
                <button type="button" class="btn btn-secondary btn-sm" onclick="addSupplyRow()">+ Add Supply Item</button>
            </div>

            <div class="table-container" style="margin-bottom: 2rem;">
                <table id="suppliesTable">
                    <thead>
                        <tr>
                            <th style="width: 35%; min-width: 220px;">Item / Equipment Name</th>
                            <th style="width: 25%; min-width: 170px;">Category</th>
                            <th style="width: 20%; min-width: 120px;">Quantity</th>
                            <th style="width: 10%; min-width: 90px;">Required?</th>
                            <th style="width: 10%; min-width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplies)): ?>
                            <tr>
                                <td><input type="text" name="sup_name[]" class="form-control" placeholder="e.g. 5 Gallon Carboy"></td>
                                <td>
                                    <select name="sup_cat[]" class="form-control">
                                        <option value="Equipment">Equipment</option>
                                        <option value="Sanitation">Sanitation</option>
                                        <option value="Measuring">Measuring Tools</option>
                                        <option value="Packaging">Bottling/Packaging</option>
                                    </select>
                                </td>
                                <td><input type="text" name="sup_qty[]" class="form-control" placeholder="1 unit"></td>
                                <td><input type="checkbox" name="sup_req[0]" value="1" checked> Yes</td>
                                <td><button type="button" class="btn btn-logout btn-sm" onclick="removeRow(this)">Remove</button></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($supplies as $idx => $sup): ?>
                                <tr>
                                    <td><input type="text" name="sup_name[]" class="form-control" value="<?= e($sup['item_name']) ?>" required></td>
                                    <td>
                                        <select name="sup_cat[]" class="form-control">
                                            <option value="Equipment" <?= $sup['category'] === 'Equipment' ? 'selected' : '' ?>>Equipment</option>
                                            <option value="Sanitation" <?= $sup['category'] === 'Sanitation' ? 'selected' : '' ?>>Sanitation</option>
                                            <option value="Measuring" <?= $sup['category'] === 'Measuring' ? 'selected' : '' ?>>Measuring Tools</option>
                                            <option value="Packaging" <?= $sup['category'] === 'Packaging' ? 'selected' : '' ?>>Bottling/Packaging</option>
                                        </select>
                                    </td>
                                    <td><input type="text" name="sup_qty[]" class="form-control" value="<?= e($sup['quantity']) ?>"></td>
                                    <td><input type="checkbox" name="sup_req[<?= $idx ?>]" value="1" <?= $sup['is_required'] ? 'checked' : '' ?>> Yes</td>
                                    <td><button type="button" class="btn btn-logout btn-sm" onclick="removeRow(this)">Remove</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e2e8f0;">

            <!-- 📋 Process Steps & Schedule Section -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="font-size: 1.25rem;">📋 3. Step-by-Step Brewing Schedule</h3>
                <button type="button" class="btn btn-secondary btn-sm" onclick="addStepRow()">+ Add Brew Step</button>
            </div>

            <div class="table-container" style="margin-bottom: 2rem;">
                <table id="stepsTable" style="width: 100%;">
                    <tbody>
                        <?php 
                        $stepList = empty($steps) ? [['phase'=>'Sanitation', 'title'=>'', 'duration'=>'', 'target_temp'=>'', 'instructions'=>'']] : $steps;
                        foreach ($stepList as $i => $stp): 
                            $stepNum = $i + 1;
                        ?>
                            <tr class="step-tr">
                                <td style="width: 100%; padding: 1rem; border-bottom: 2px solid #e2e8f0; background: #ffffff;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.4rem;">
                                        <span style="font-weight: 700; font-size: 1rem; color: #1e293b;">
                                            📍 Step #<span class="step-num"><?= $stepNum ?></span>
                                        </span>
                                        <div style="display: flex; gap: 0.35rem; align-items: center;">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="moveStepUp(this)" title="Move step up">▲ Up</button>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="moveStepDown(this)" title="Move step down">▼ Down</button>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertStepAfter(this)" title="Insert step below">+ Insert Step</button>
                                            <button type="button" class="btn btn-logout btn-sm" onclick="removeStepRow(this)" title="Remove step">Remove</button>
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap;">
                                        <div style="flex: 1; min-width: 140px;">
                                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Phase</label>
                                            <select name="stp_phase[]" class="form-control">
                                                <option value="Preparation" <?= $stp['phase'] === 'Preparation' ? 'selected' : '' ?>>Preparation</option>
                                                <option value="Sanitation" <?= $stp['phase'] === 'Sanitation' ? 'selected' : '' ?>>Sanitation</option>
                                                <option value="Mash/Steep" <?= $stp['phase'] === 'Mash/Steep' ? 'selected' : '' ?>>Mash/Steep</option>
                                                <option value="Boil" <?= $stp['phase'] === 'Boil' ? 'selected' : '' ?>>Boil Schedule</option>
                                                <option value="Pitching" <?= $stp['phase'] === 'Pitching' ? 'selected' : '' ?>>Chilling/Pitching</option>
                                                <option value="Primary" <?= $stp['phase'] === 'Primary' ? 'selected' : '' ?>>Primary Fermentation</option>
                                                <option value="Secondary" <?= $stp['phase'] === 'Secondary' ? 'selected' : '' ?>>Secondary/Racking</option>
                                                <option value="Tertiary" <?= $stp['phase'] === 'Tertiary' ? 'selected' : '' ?>>Tertiary/Racking</option>
                                                <option value="Bottling" <?= $stp['phase'] === 'Bottling' ? 'selected' : '' ?>>Bottling & Carbonation</option>
                                            </select>
                                        </div>
                                        <div style="flex: 2; min-width: 180px;">
                                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Step Title</label>
                                            <input type="text" name="stp_title[]" class="form-control" value="<?= e($stp['title']) ?>" placeholder="e.g. Sanitize Carboy & Air Lock" required>
                                        </div>
                                        <div style="flex: 1; min-width: 110px;">
                                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Duration</label>
                                            <input type="text" name="stp_duration[]" class="form-control" value="<?= e($stp['duration']) ?>" placeholder="e.g. 15 mins">
                                        </div>
                                        <div style="flex: 1; min-width: 110px;">
                                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Target Temp (°F)</label>
                                            <input type="text" name="stp_temp[]" class="form-control" value="<?= e($stp['target_temp']) ?>" placeholder="e.g. 70F">
                                        </div>
                                    </div>
                                    <div>
                                        <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Instructions / Notes</label>
                                        <textarea name="stp_inst[]" class="form-control auto-resize-inst" rows="2" oninput="autoResizeInst(this)" style="overflow-y: hidden; resize: vertical; min-height: 55px;" placeholder="Step instructions & detailed notes..."><?= e($stp['instructions']) ?></textarea>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="is_public" value="1" <?= $r['is_public'] ? 'checked' : '' ?>>
                    <span>Share recipe publicly in the community recipe library</span>
                </label>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary">Save Complete Recipe</button>
                <a href="recipes.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function removeRow(btn) {
    const row = btn.closest('tr');
    if (row) row.remove();
}

function moveRowUp(btn) {
    const tr = btn.closest('tr');
    if (tr && tr.previousElementSibling) {
        tr.parentNode.insertBefore(tr, tr.previousElementSibling);
    }
}

function moveRowDown(btn) {
    const tr = btn.closest('tr');
    if (tr && tr.nextElementSibling) {
        tr.parentNode.insertBefore(tr.nextElementSibling, tr);
    }
}

function addIngredientRow() {
    const tbody = document.querySelector('#ingredientsTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="ing_name[]" class="form-control" placeholder="Ingredient name" required></td>
        <td>
            <select name="ing_type[]" class="form-control">
                <option value="Fermentable">Fermentable</option>
                <option value="Hop">Hop</option>
                <option value="Yeast">Yeast</option>
                <option value="Additive/Finings">Additive/Finings</option>
                <option value="Water/Other">Water/Other</option>
            </select>
        </td>
        <td><input type="number" step="0.01" name="ing_amount[]" class="form-control" placeholder="1.0"></td>
        <td><input type="text" name="ing_unit[]" class="form-control" placeholder="lbs/oz/pkt"></td>
        <td>
            <select name="ing_stage[]" class="form-control">
                <option value="Preparation">Preparation</option>
                <option value="Sanitation">Sanitation</option>
                <option value="Mash/Steep">Mash/Steep</option>
                <option value="Boil">Boil</option>
                <option value="Pitching">Pitching</option>
                <option value="Primary">Primary</option>
                <option value="Secondary">Secondary</option>
                <option value="Tertiary">Tertiary</option>
                <option value="Bottling">Bottling</option>
            </select>
        </td>
        <td style="white-space: nowrap; text-align: center;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="moveRowUp(this)" title="Move up">▲</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="moveRowDown(this)" title="Move down">▼</button>
            <button type="button" class="btn btn-logout btn-sm" onclick="removeRow(this)" title="Remove">Remove</button>
        </td>
    `;
    tbody.appendChild(tr);
}

function addSupplyRow() {
    const tbody = document.querySelector('#suppliesTable tbody');
    const idx = tbody.children.length;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="sup_name[]" class="form-control" placeholder="Item name" required></td>
        <td>
            <select name="sup_cat[]" class="form-control">
                <option value="Equipment">Equipment</option>
                <option value="Sanitation">Sanitation</option>
                <option value="Measuring">Measuring Tools</option>
                <option value="Packaging">Bottling/Packaging</option>
            </select>
        </td>
        <td><input type="text" name="sup_qty[]" class="form-control" placeholder="1 unit"></td>
        <td><input type="checkbox" name="sup_req[${idx}]" value="1" checked> Yes</td>
        <td><button type="button" class="btn btn-logout btn-sm" onclick="removeRow(this)">Remove</button></td>
    `;
    tbody.appendChild(tr);
}

function renumberSteps() {
    const stepNums = document.querySelectorAll('#stepsTable .step-num');
    stepNums.forEach((span, idx) => {
        span.textContent = idx + 1;
    });
}

function removeStepRow(btn) {
    const tr = btn.closest('tr');
    if (tr) {
        tr.remove();
        renumberSteps();
    }
}

function moveStepUp(btn) {
    const tr = btn.closest('tr');
    if (tr && tr.previousElementSibling) {
        tr.parentNode.insertBefore(tr, tr.previousElementSibling);
        renumberSteps();
    }
}

function moveStepDown(btn) {
    const tr = btn.closest('tr');
    if (tr && tr.nextElementSibling) {
        tr.parentNode.insertBefore(tr.nextElementSibling, tr);
        renumberSteps();
    }
}

function getStepRowHtml(stepNum = 1) {
    return `
        <td style="width: 100%; padding: 1rem; border-bottom: 2px solid #e2e8f0; background: #ffffff;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.4rem;">
                <span style="font-weight: 700; font-size: 1rem; color: #1e293b;">
                    📍 Step #<span class="step-num">${stepNum}</span>
                </span>
                <div style="display: flex; gap: 0.35rem; align-items: center;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="moveStepUp(this)" title="Move step up">▲ Up</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="moveStepDown(this)" title="Move step down">▼ Down</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="insertStepAfter(this)" title="Insert step below">+ Insert Step</button>
                    <button type="button" class="btn btn-logout btn-sm" onclick="removeStepRow(this)" title="Remove step">Remove</button>
                </div>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 140px;">
                    <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Phase</label>
                    <select name="stp_phase[]" class="form-control">
                        <option value="Preparation">Preparation</option>
                        <option value="Sanitation">Sanitation</option>
                        <option value="Mash/Steep">Mash/Steep</option>
                        <option value="Boil">Boil Schedule</option>
                        <option value="Pitching">Chilling/Pitching</option>
                        <option value="Primary">Primary Fermentation</option>
                        <option value="Secondary">Secondary/Racking</option>
                        <option value="Tertiary">Tertiary/Racking</option>
                        <option value="Bottling">Bottling & Carbonation</option>
                    </select>
                </div>
                <div style="flex: 2; min-width: 180px;">
                    <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Step Title</label>
                    <input type="text" name="stp_title[]" class="form-control" placeholder="e.g. Mash Grains at 152°F" required>
                </div>
                <div style="flex: 1; min-width: 110px;">
                    <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Duration</label>
                    <input type="text" name="stp_duration[]" class="form-control" placeholder="e.g. 60 mins">
                </div>
                <div style="flex: 1; min-width: 110px;">
                    <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Target Temp (°F)</label>
                    <input type="text" name="stp_temp[]" class="form-control" placeholder="e.g. 152°F">
                </div>
            </div>
            <div>
                <label style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 0.2rem; display: block; text-transform: uppercase;">Instructions / Notes</label>
                <textarea name="stp_inst[]" class="form-control auto-resize-inst" rows="2" oninput="autoResizeInst(this)" style="overflow-y: hidden; resize: vertical; min-height: 55px;" placeholder="Step instructions & detailed notes..."></textarea>
            </div>
        </td>
    `;
}

function autoResizeInst(el) {
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.max(55, el.scrollHeight + 2) + 'px';
}

function addStepRow() {
    const tbody = document.querySelector('#stepsTable tbody');
    const stepNum = tbody.children.length + 1;
    const tr = document.createElement('tr');
    tr.className = 'step-tr';
    tr.innerHTML = getStepRowHtml(stepNum);
    tbody.appendChild(tr);
    const ta = tr.querySelector('.auto-resize-inst');
    if (ta) autoResizeInst(ta);
}

function insertStepAfter(btn) {
    const tr = btn.closest('tr');
    if (tr) {
        const newTr = document.createElement('tr');
        newTr.className = 'step-tr';
        newTr.innerHTML = getStepRowHtml();
        tr.after(newTr);
        renumberSteps();
        const ta = newTr.querySelector('.auto-resize-inst');
        if (ta) autoResizeInst(ta);
    }
}

function updateLiveIbuSrm() {
    const batchSizeInput = document.querySelector('input[name="batch_size_gal"]');
    const batchGal = batchSizeInput ? (parseFloat(batchSizeInput.value) || 5.0) : 5.0;

    let totalIbu = 0;
    let totalMcu = 0;

    const rows = document.querySelectorAll('#ingredientsTable tbody tr');
    rows.forEach(tr => {
        const nameInput = tr.querySelector('input[name="ing_name[]"]');
        const typeSelect = tr.querySelector('select[name="ing_type[]"]');
        const amountInput = tr.querySelector('input[name="ing_amount[]"]');
        
        if (!typeSelect || !amountInput) return;
        const type = typeSelect.value;
        const amount = parseFloat(amountInput.value) || 0;
        const name = nameInput ? nameInput.value.toLowerCase() : '';

        if (type === 'Hop' && amount > 0) {
            const aa = (name.includes('citra') || name.includes('mosaic') || name.includes('simcoe')) ? 12.0 : 5.5;
            totalIbu += (0.25 * (amount * (aa / 100.0) * 7490)) / Math.max(0.1, batchGal);
        } else if (type === 'Fermentable' && amount > 0) {
            let colorL = 2.0;
            if (name.includes('caramel') || name.includes('crystal')) colorL = 40.0;
            else if (name.includes('chocolate') || name.includes('dark')) colorL = 350.0;
            else if (name.includes('black') || name.includes('roasted')) colorL = 500.0;
            else if (name.includes('munich') || name.includes('vienna')) colorL = 9.0;

            totalMcu += (amount * colorL) / Math.max(0.1, batchGal);
        }
    });

    const ibuSpan = document.getElementById('live_ibu_badge');
    if (ibuSpan) ibuSpan.textContent = Math.round(totalIbu) + ' IBU';

    const srmSpan = document.getElementById('live_srm_badge');
    if (srmSpan) {
        const srm = totalMcu > 0 ? Math.round(1.4922 * Math.pow(totalMcu, 0.6859)) : 0;
        let colorName = '🟡 Pale Straw';
        if (srm > 30) colorName = '⬛ Black / Stout';
        else if (srm > 18) colorName = '🟤 Dark Brown';
        else if (srm > 10) colorName = '🟠 Amber / Copper';
        else if (srm > 5) colorName = '🟡 Golden';
        srmSpan.textContent = srm > 0 ? srm + ' SRM (' + colorName + ')' : '-- SRM';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.auto-resize-inst').forEach(el => autoResizeInst(el));
    updateLiveIbuSrm();
});
document.addEventListener('input', updateLiveIbuSrm);
document.addEventListener('change', updateLiveIbuSrm);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
