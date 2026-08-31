<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/bjcp_styles.php';

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
    'target_pre_og' => '',
    'target_og' => '',
    'target_fg' => '',
    'target_abv' => '',
    'ingredients' => '',
    'instructions' => '',
    'is_public' => 1
];

$categories = $db->query("SELECT * FROM categories ORDER BY id")->fetchAll();

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
} elseif ($action === 'new' && !empty($_GET['style'])) {
    $styleParam = trim($_GET['style']);
    $bjcp = find_bjcp_style($styleParam);
    if ($bjcp) {
        $r['style'] = $bjcp['name'];
        $r['name'] = 'My ' . $bjcp['name'];
        $r['target_og'] = sprintf('%.3f', ($bjcp['og_min'] + $bjcp['og_max']) / 2);
        $r['target_fg'] = sprintf('%.3f', ($bjcp['fg_min'] + $bjcp['fg_max']) / 2);
        $r['target_abv'] = sprintf('%.1f', ($bjcp['abv_min'] + $bjcp['abv_max']) / 2);

        // Map Category
        foreach ($categories as $cat) {
            if (strcasecmp($cat['name'], $bjcp['category']) === 0 || 
                (stripos($bjcp['category'], 'Cider') !== false && strcasecmp($cat['name'], 'Cider') === 0) ||
                (stripos($bjcp['category'], 'Mead') !== false && strcasecmp($cat['name'], 'Mead') === 0)) {
                $r['category_id'] = $cat['id'];
                break;
            }
        }

        // Preload baseline starter formulation based on beverage style
        if (stripos($bjcp['name'], 'Cider') !== false) {
            $r['target_pre_og'] = '1.045';
            $ingredients = [
                ['name' => 'Fresh Pressed Apple Cider / Juice', 'ingredient_type' => 'Fermentable', 'amount' => 5.0, 'unit' => 'Gal', 'stage_addition' => 'Primary', 'notes' => 'Preservative-free juice (no potassium sorbate)'],
                ['name' => 'Corn Sugar / Dextrose (Optional for ABV boost)', 'ingredient_type' => 'Fermentable', 'amount' => 1.0, 'unit' => 'lbs', 'stage_addition' => 'Primary', 'notes' => 'Dissolved in warm cider if higher ABV desired'],
                ['name' => 'Yeast Nutrient (DAP / Fermaid-O)', 'ingredient_type' => 'Additive', 'amount' => 1.0, 'unit' => 'tsp', 'stage_addition' => 'Primary', 'notes' => 'Add at pitch time'],
                ['name' => 'Cider / Wine Yeast (SafCider or EC-1118)', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkg', 'stage_addition' => 'Primary', 'notes' => 'Rehydrated at 90-95°F']
            ];
            $steps = [
                ['phase' => 'Preparation', 'title' => 'Sanitize Fermenter & Equipment', 'duration' => '15 mins', 'target_temp' => '', 'instructions' => 'Clean and sanitize carboy, funnel, airlock, and hydrometer with Star San.'],
                ['phase' => 'Pitching', 'title' => 'Aerate Juice & Pitch Yeast', 'duration' => '10 mins', 'target_temp' => '65°F - 68°F', 'instructions' => 'Aerate juice thoroughly, record Starting OG hydrometer reading, and pitch yeast with nutrient.'],
                ['phase' => 'Primary', 'title' => 'Primary Fermentation', 'duration' => '14 days', 'target_temp' => '65°F', 'instructions' => 'Ferment in dark temperature-controlled area until specific gravity drops to target FG.'],
                ['phase' => 'Bottling', 'title' => 'Bottling & Carbonation', 'duration' => '60 mins', 'target_temp' => '70°F', 'instructions' => 'Prime with corn sugar for bottle carbonation (or stabilize with sorbate/sulfite if backsweetening).']
            ];
        } elseif (stripos($bjcp['name'], 'Mead') !== false) {
            $ingredients = [
                ['name' => 'Raw Clover / Wildflower Honey', 'ingredient_type' => 'Fermentable', 'amount' => 12.0, 'unit' => 'lbs', 'stage_addition' => 'Primary', 'notes' => 'Pure unpasteurized honey'],
                ['name' => 'Spring / Filtered Water', 'ingredient_type' => 'Water', 'amount' => 4.0, 'unit' => 'Gal', 'stage_addition' => 'Primary', 'notes' => 'Dechlorinated spring water'],
                ['name' => 'Fermaid-O Yeast Nutrient', 'ingredient_type' => 'Additive', 'amount' => 5.0, 'unit' => 'g', 'stage_addition' => 'Primary', 'notes' => 'TOSNA staggered addition'],
                ['name' => 'Wine / Mead Yeast (Lalvin D-47 or 71B)', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkg', 'stage_addition' => 'Primary', 'notes' => 'Rehydrated with Go-Ferm']
            ];
            $steps = [
                ['phase' => 'Preparation', 'title' => 'Mix Must & Dissolve Honey', 'duration' => '30 mins', 'target_temp' => '70°F', 'instructions' => 'Warm water to 95°F to dissolve honey into must, aerate vigorously, and record Starting OG.'],
                ['phase' => 'Primary', 'title' => 'Pitch Yeast & Staggered Nutrients', 'duration' => '7 days', 'target_temp' => '64°F - 68°F', 'instructions' => 'Add nutrients at 24h, 48h, 72h, and 1/3 sugar break.'],
                ['phase' => 'Secondary', 'title' => 'Secondary Racking & Bulk Aging', 'duration' => '60 days', 'target_temp' => '60°F - 65°F', 'instructions' => 'Rack off yeast lees once clear and allow to age and mellow.']
            ];
        } elseif (stripos($bjcp['name'], 'Stout') !== false || stripos($bjcp['name'], 'Porter') !== false) {
            $ingredients = [
                ['name' => 'Pale 2-Row / Maris Otter Malt', 'ingredient_type' => 'Fermentable', 'amount' => 9.0, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Base malt (75%)'],
                ['name' => 'Roasted Barley (300L)', 'ingredient_type' => 'Fermentable', 'amount' => 1.0, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Provides dry coffee roast character'],
                ['name' => 'Chocolate Malt (350L)', 'ingredient_type' => 'Fermentable', 'amount' => 0.75, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Adds dark chocolate notes'],
                ['name' => 'Flaked Barley / Oats', 'ingredient_type' => 'Fermentable', 'amount' => 0.75, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Enhances body and creamy head retention'],
                ['name' => 'East Kent Goldings / Fuggle Hops', 'ingredient_type' => 'Hop', 'amount' => 1.5, 'unit' => 'oz', 'stage_addition' => 'Boil', 'notes' => '60 min bittering addition (~35 IBU)'],
                ['name' => 'English Ale Yeast (WLP004 / S-04)', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkg', 'stage_addition' => 'Primary', 'notes' => 'Pitch at 66°F']
            ];
            $steps = [
                ['phase' => 'Mash/Steep', 'title' => 'Mash-In at 154°F', 'duration' => '60 mins', 'target_temp' => '154°F', 'instructions' => 'Strike grains with 3.75 gal of water at 165°F to achieve 154°F mash rest.'],
                ['phase' => 'Boil', 'title' => '60-Minute Boil & Bittering Addition', 'duration' => '60 mins', 'target_temp' => '212°F', 'instructions' => 'Bring wort to rolling boil and add 1.5 oz hops at 60 mins.'],
                ['phase' => 'Pitching', 'title' => 'Chill Wort & Inoculate Yeast', 'duration' => '20 mins', 'target_temp' => '66°F', 'instructions' => 'Rapidly chill to 66°F, aerate with oxygen/shaking, and pitch English ale yeast.'],
                ['phase' => 'Primary', 'title' => 'Primary Fermentation', 'duration' => '10 days', 'target_temp' => '66°F - 68°F', 'instructions' => 'Ferment until specific gravity reaches final gravity.']
            ];
        } elseif (stripos($bjcp['name'], 'IPA') !== false || stripos($bjcp['name'], 'Pale Ale') !== false) {
            $ingredients = [
                ['name' => 'American 2-Row Pale Malt', 'ingredient_type' => 'Fermentable', 'amount' => 10.5, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Base grain (85%)'],
                ['name' => 'Crystal / Caramel 40L Malt', 'ingredient_type' => 'Fermentable', 'amount' => 0.75, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Adds subtle sweetness and amber hue'],
                ['name' => 'Munich Malt 10L', 'ingredient_type' => 'Fermentable', 'amount' => 0.75, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Adds malt backbone to balance hops'],
                ['name' => 'Centennial / Cascade Hops (Bittering)', 'ingredient_type' => 'Hop', 'amount' => 1.0, 'unit' => 'oz', 'stage_addition' => 'Boil', 'notes' => '60 min bittering addition (~35 IBU)'],
                ['name' => 'Citra / Mosaic Hops (Flavor/Aroma)', 'ingredient_type' => 'Hop', 'amount' => 1.5, 'unit' => 'oz', 'stage_addition' => 'Boil', 'notes' => '10 min / Flameout addition (~20 IBU)'],
                ['name' => 'Citra / Mosaic Hops (Dry Hop)', 'ingredient_type' => 'Hop', 'amount' => 2.0, 'unit' => 'oz', 'stage_addition' => 'Secondary', 'notes' => 'Dry hop for 4 days before packaging'],
                ['name' => 'American Ale Yeast (US-05 / WLP001)', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkg', 'stage_addition' => 'Primary', 'notes' => 'Clean neutral fermentation']
            ];
            $steps = [
                ['phase' => 'Mash/Steep', 'title' => 'Single Infusion Mash at 152°F', 'duration' => '60 mins', 'target_temp' => '152°F', 'instructions' => 'Strike grains with 4.0 gal of strike water at 163°F to achieve 152°F rest.'],
                ['phase' => 'Boil', 'title' => '60-Minute Boil & Hop Schedule', 'duration' => '60 mins', 'target_temp' => '212°F', 'instructions' => 'Add 1.0 oz bittering hops at 60 mins, 1.5 oz aroma hops at flameout/whirlpool.'],
                ['phase' => 'Pitching', 'title' => 'Chill Wort & Pitch Yeast', 'duration' => '20 mins', 'target_temp' => '67°F', 'instructions' => 'Chill to 67°F, oxygenate wort, and pitch American ale yeast.'],
                ['phase' => 'Primary', 'title' => 'Primary Fermentation & Dry Hop', 'duration' => '10 days', 'target_temp' => '67°F - 69°F', 'instructions' => 'Add dry hops on day 7 for 3-4 days before packaging.']
            ];
        } else {
            // German / Pilsner / Belgian / Wheat / Craft Ale generic baseline
            $ingredients = [
                ['name' => 'Pilsner / Pale 2-Row Malt', 'ingredient_type' => 'Fermentable', 'amount' => 9.5, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Base grain'],
                ['name' => 'Vienna / Munich Malt', 'ingredient_type' => 'Fermentable', 'amount' => 1.0, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Malt complexity'],
                ['name' => 'Hallertau / Saaz Hops', 'ingredient_type' => 'Hop', 'amount' => 1.25, 'unit' => 'oz', 'stage_addition' => 'Boil', 'notes' => '60 min bittering'],
                ['name' => 'Hallertau / Saaz Hops', 'ingredient_type' => 'Hop', 'amount' => 0.75, 'unit' => 'oz', 'stage_addition' => 'Boil', 'notes' => '15 min aroma addition'],
                ['name' => 'Style Appropriate Yeast', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkg', 'stage_addition' => 'Primary', 'notes' => 'Ferment within style temperature range']
            ];
            $steps = [
                ['phase' => 'Mash/Steep', 'title' => 'Mash Rest at 150-153°F', 'duration' => '60 mins', 'target_temp' => '152°F', 'instructions' => 'Mash grains in hot water for saccharification rest.'],
                ['phase' => 'Boil', 'title' => '60-Minute Boil Schedule', 'duration' => '60 mins', 'target_temp' => '212°F', 'instructions' => 'Boil wort and add hops per schedule.'],
                ['phase' => 'Pitching', 'title' => 'Chill & Pitch Yeast', 'duration' => '20 mins', 'target_temp' => '65°F', 'instructions' => 'Chill wort to pitching temperature and pitch yeast.'],
                ['phase' => 'Primary', 'title' => 'Fermentation Schedule', 'duration' => '14 days', 'target_temp' => '65°F - 68°F', 'instructions' => 'Maintain steady fermentation temperature.']
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $catId        = sanitize_int($_POST['category_id'] ?? 1);
    $name         = sanitize_text($_POST['name'] ?? '', 100);
    $style        = sanitize_text($_POST['style'] ?? '', 100);
    $batchSize    = validate_batch_size($_POST['batch_size_gal'] ?? 5.0, 5.0);
    $targetPreOg  = validate_gravity($_POST['target_pre_og'] ?? null);
    $targetOg     = validate_gravity($_POST['target_og'] ?? null);
    $targetFg     = validate_gravity($_POST['target_fg'] ?? null);
    $instructions = sanitize_text($_POST['instructions'] ?? '', 5000);
    $isPublic     = isset($_POST['is_public']) ? 1 : 0;

    $allowedIngTypes = ['Fermentable', 'Hop', 'Yeast', 'Additive', 'Fining', 'Water', 'Other'];
    $allowedStages   = ['Mash', 'Boil', 'Primary', 'Secondary', 'Tertiary', 'Bottling', 'Kegging', 'Aging'];

    // Process POSTed ingredients array
    $postIngredients = [];
    if (isset($_POST['ing_name']) && is_array($_POST['ing_name'])) {
        foreach ($_POST['ing_name'] as $idx => $ingName) {
            $ingNameClean = sanitize_text($ingName, 100);
            if (!empty($ingNameClean)) {
                $postIngredients[] = [
                    'name'            => $ingNameClean,
                    'ingredient_type' => validate_enum($_POST['ing_type'][$idx] ?? '', $allowedIngTypes, 'Other'),
                    'amount'          => max(0.0, sanitize_float($_POST['ing_amount'][$idx] ?? 0)),
                    'unit'            => sanitize_text($_POST['ing_unit'][$idx] ?? '', 20),
                    'stage_addition'  => validate_enum($_POST['ing_stage'][$idx] ?? '', $allowedStages, 'Primary'),
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
                    target_pre_og = ?, target_og = ?, target_fg = ?, target_abv = ?, ingredients = ?,
                    instructions = ?, is_public = ?
                WHERE id = ? AND user_id = ?
            ");
            $up->execute([
                $catId, $name, $style, $batchSize,
                $targetPreOg, $targetOg, $targetFg, $targetAbv, $ingredientsTextSummary,
                $instructions, $isPublic, $r['id'], $user['id']
            ]);
            $targetRecipeId = $r['id'];
        } else {
            $ins = $db->prepare("
                INSERT INTO recipes (
                    user_id, category_id, name, style, batch_size_gal,
                    target_pre_og, target_og, target_fg, target_abv, ingredients, instructions, is_public
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?
                )
            ");
            $ins->execute([
                $user['id'], $catId, $name, $style, $batchSize,
                $targetPreOg, $targetOg, $targetFg, $targetAbv, $ingredientsTextSummary, $instructions, $isPublic
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
                    <label class="form-label">Brew Style (BJCP)</label>
                    <input type="text" name="style" id="recipe_style_input" list="bjcpStylesList" class="form-control" value="<?= e($r['style']) ?>" placeholder="Select or type BJCP Style (e.g. American IPA, Dry Stout)">
                    <datalist id="bjcpStylesList">
                        <?php foreach (get_bjcp_styles() as $sName => $sData): ?>
                            <option value="<?= e($sName) ?>"><?= e($sData['category']) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label class="form-label">Target Batch Size (Gal)</label>
                    <input type="number" step="0.1" name="batch_size_gal" class="form-control" value="<?= (float)$r['batch_size_gal'] ?>" required>
                </div>
            </div>

            <div class="form-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; justify-content: space-between;">
                        <span>Base Juice / Must OG</span>
                        <small style="color: var(--text-muted); font-weight: normal;">(Pre-Sugar/Optional)</small>
                    </label>
                    <input type="number" step="0.001" id="calc_pre_og" name="target_pre_og" class="form-control" value="<?= e($r['target_pre_og'] ?? '') ?>" placeholder="e.g. 1.045 (Raw Juice)">
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.25rem;">For wine/cider: Initial fresh pressed juice gravity before adding sugar.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; justify-content: space-between;">
                        <span>Target Starting OG</span>
                        <small style="color: var(--text-muted); font-weight: normal;">(Post-Sugar/Inoculated)</small>
                    </label>
                    <input type="number" step="0.001" id="calc_og" name="target_og" class="form-control" value="<?= e($r['target_og']) ?>" placeholder="e.g. 1.085 (Adjusted Must)">
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.25rem;">Starting OG when fermentation begins.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Target FG (Final)</label>
                    <input type="number" step="0.001" id="calc_fg" name="target_fg" class="form-control" value="<?= e($r['target_fg']) ?>" placeholder="e.g. 0.998">
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.25rem;">Expected final finished gravity.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Estimated Total ABV</label>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); padding: 0.4rem 0;" id="calc_abv_result">
                        <?= $r['target_abv'] ? e($r['target_abv']) . '%' : '--%' ?>
                    </div>
                    <small id="chaptalization_badge" style="color: #b45309; font-size: 0.8rem; font-weight: 700; display: none;"></small>
                </div>
            </div>

            <!-- Dynamic BJCP Target Gauges Preview Block -->
            <div id="bjcpTargetPreviewBox" style="display: none; background: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #d97706; padding: 1rem 1.25rem; border-radius: 8px; margin-top: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <strong style="font-size: 0.95rem; color: #1e293b;">🎯 BJCP Style Target Compliance (<span id="bjcpStyleDisplayName">--</span>)</strong>
                    <span class="badge badge-warning" id="bjcpCategoryBadge">--</span>
                </div>
                <p id="bjcpStyleDesc" style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.75rem;"></p>
                <div id="bjcpGaugesContainer" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.75rem;"></div>
            </div>

            <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e2e8f0;">

            <!-- 🌾 Structured Ingredients Section -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                <h3 style="font-size: 1.25rem; margin: 0;">🌾 1. Recipe Ingredients Breakdown</h3>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="quickScaleIngredients()" title="Scale ingredient amounts by factor">⚖️ Scale Quantities</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="addIngredientRow()">+ Add Ingredient</button>
                </div>
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

const BJCP_DB = <?= json_encode(get_bjcp_styles(), JSON_UNESCAPED_SLASHES) ?>;

function findBjcpStyleData(name) {
    if (!name) return null;
    const clean = name.trim().toLowerCase();
    for (let key in BJCP_DB) {
        if (key.toLowerCase() === clean) return { name: key, ...BJCP_DB[key] };
    }
    for (let key in BJCP_DB) {
        if (key.toLowerCase().includes(clean) || clean.includes(key.toLowerCase())) {
            return { name: key, ...BJCP_DB[key] };
        }
    }
    return null;
}

function renderJsTargetGauge(label, actual, min, max, unit = '', decimals = 3) {
    let statusClass = 'badge-secondary';
    let statusText = 'Not Set';
    let markerPct = 50;

    const span = Math.max(0.001, max - min);
    const viewMin = min - (span * 0.25);
    const viewMax = max + (span * 0.25);
    const viewSpan = Math.max(0.001, viewMax - viewMin);

    const rangeStartPct = Math.round(((min - viewMin) / viewSpan) * 100);
    const rangeWidthPct = Math.round((span / viewSpan) * 100);

    let markerHtml = '';
    if (actual !== null && !isNaN(actual) && actual > 0) {
        markerPct = Math.max(2, Math.min(98, Math.round(((actual - viewMin) / viewSpan) * 100)));
        if (actual >= min && actual <= max) {
            statusClass = 'badge-success';
            statusText = '✓ In Style';
        } else if (actual < min) {
            statusClass = 'badge-warning';
            statusText = '▼ Low (' + actual.toFixed(decimals) + ')';
        } else {
            statusClass = 'badge-danger';
            statusText = '▲ High (' + actual.toFixed(decimals) + ')';
        }
        markerHtml = `<div style="position: absolute; left: ${markerPct}%; top: -3px; width: 6px; height: 18px; background: #0f172a; border: 1px solid #fff; border-radius: 3px; transform: translateX(-50%); box-shadow: 0 1px 4px rgba(0,0,0,0.3);" title="Your Value: ${actual.toFixed(decimals)}"></div>`;
    }

    return `
        <div style="background: #ffffff; padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem; font-size: 0.8rem;">
                <strong>${label}</strong>
                <div>
                    <span style="color: #64748b; font-size: 0.75rem; margin-right: 0.4rem;">${min.toFixed(decimals)} - ${max.toFixed(decimals)} ${unit}</span>
                    <span class="badge ${statusClass}">${statusText}</span>
                </div>
            </div>
            <div style="position: relative; height: 12px; background: #e2e8f0; border-radius: 6px;">
                <div style="position: absolute; left: ${rangeStartPct}%; width: ${rangeWidthPct}%; height: 100%; background: #10b981; opacity: 0.75; border-radius: 4px;"></div>
                ${markerHtml}
            </div>
        </div>
    `;
}

function updateBjcpStylePreview() {
    const styleInput = document.getElementById('recipe_style_input');
    const previewBox = document.getElementById('bjcpTargetPreviewBox');
    if (!styleInput || !previewBox) return;

    const styleData = findBjcpStyleData(styleInput.value);
    if (!styleData) {
        previewBox.style.display = 'none';
        return;
    }

    previewBox.style.display = 'block';
    document.getElementById('bjcpStyleDisplayName').textContent = styleData.name;
    document.getElementById('bjcpCategoryBadge').textContent = styleData.category;
    document.getElementById('bjcpStyleDesc').textContent = styleData.description;

    const og = parseFloat(document.getElementById('calc_og')?.value) || null;
    const fg = parseFloat(document.getElementById('calc_fg')?.value) || null;
    const abv = (og && fg && og > fg) ? parseFloat(((og - fg) * 131.25).toFixed(1)) : null;

    let gaugesHtml = '';
    gaugesHtml += renderJsTargetGauge('Original Gravity (OG)', og, styleData.og_min, styleData.og_max, '', 3);
    gaugesHtml += renderJsTargetGauge('Final Gravity (FG)', fg, styleData.fg_min, styleData.fg_max, '', 3);
    gaugesHtml += renderJsTargetGauge('Estimated ABV', abv, styleData.abv_min, styleData.abv_max, '%', 1);

    document.getElementById('bjcpGaugesContainer').innerHTML = gaugesHtml;
}

function quickScaleIngredients() {
    const batchInput = document.querySelector('input[name="batch_size_gal"]');
    const curSize = parseFloat(batchInput?.value) || 5.0;
    const targetPrompt = prompt(`Scale ingredients for a new batch volume?\nCurrent Batch Size: ${curSize} Gal\nEnter new target volume (Gal):`, curSize.toString());
    
    if (!targetPrompt) return;
    const targetSize = parseFloat(targetPrompt);
    if (isNaN(targetSize) || targetSize <= 0) {
        alert('Please enter a valid positive number for the target batch size.');
        return;
    }

    const factor = targetSize / curSize;
    if (Math.abs(factor - 1.0) < 0.001) return;

    const amounts = document.querySelectorAll('input[name="ing_amount[]"]');
    amounts.forEach(inp => {
        const val = parseFloat(inp.value);
        if (!isNaN(val) && val > 0) {
            inp.value = Math.round((val * factor) * 100) / 100;
        }
    });

    if (batchInput) batchInput.value = targetSize;
    updateLiveIbuSrm();
    alert(`✅ Scaled ${amounts.length} ingredient quantities by factor of ${factor.toFixed(2)}x (to ${targetSize} Gal).`);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.auto-resize-inst').forEach(el => autoResizeInst(el));
    updateLiveIbuSrm();
    updateBjcpStylePreview();
});
document.addEventListener('input', () => {
    updateLiveIbuSrm();
    updateBjcpStylePreview();
});
document.addEventListener('change', () => {
    updateLiveIbuSrm();
    updateBjcpStylePreview();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
