<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$type = $_GET['type'] ?? 'batch';
$id   = sanitize_int($_GET['id'] ?? 0);

if ($type === 'batch') {
    $stmt = $db->prepare("
        SELECT b.*, c.name as category_name, r.name as recipe_name
        FROM batches b
        JOIN categories c ON b.category_id = c.id
        LEFT JOIN recipes r ON b.recipe_id = r.id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->execute([$id, $user['id']]);
    $b = $stmt->fetch();

    if (!$b) die("Batch log not found.");

    // Fetch structured recipe components if batch linked to a recipe
    $recipeDetails = ['ingredients' => [], 'supplies' => [], 'steps' => []];
    if (!empty($b['recipe_id'])) {
        $recipeDetails = get_recipe_details($b['recipe_id']);
    }

    // Fetch gravity readings
    $stmtR = $db->prepare("SELECT * FROM fermentation_readings WHERE batch_id = ? ORDER BY reading_date ASC");
    $stmtR->execute([$id]);
    $readings = $stmtR->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Brew Log Sheet - <?= e($b['batch_name']) ?></title>
        <style>
            body { font-family: Arial, sans-serif; margin: 30px; color: #1e293b; line-height: 1.5; }
            .header { border-bottom: 3px solid #d97706; padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
            h1 { margin: 0; color: #d97706; }
            .meta-table, .readings-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .meta-table td, .readings-table th, .readings-table td { border: 1px solid #cbd5e1; padding: 8px 12px; }
            .readings-table th { background: #f1f5f9; text-align: left; }
            .section-title { font-size: 18px; font-weight: bold; border-left: 4px solid #d97706; padding-left: 8px; margin: 20px 0 10px 0; }
            .box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 6px; white-space: pre-wrap; margin-bottom: 15px; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 20px; text-align: right;">
            <button onclick="window.print()" style="background: #d97706; color: white; border: none; padding: 10px 20px; font-size: 16px; border-radius: 5px; cursor: pointer;">🖨️ Print / Save as PDF</button>
        </div>

        <div class="header">
            <div>
                <h1>🍺 CraftBrew Batch Log Sheet</h1>
                <div style="font-size: 14px; color: #64748b;"><?= APP_NAME ?> &bull; Log ID #<?= (int)$b['id'] ?></div>
            </div>
            <div style="text-align: right;">
                <strong>Date:</strong> <?= date('Y-m-d') ?>
            </div>
        </div>

        <h2><?= e($b['batch_name']) ?> (<?= e($b['category_name']) ?>)</h2>

        <table class="meta-table">
            <tr>
                <td><strong>Brew Style:</strong> <?= e($b['batch_style'] ?: 'Custom') ?></td>
                <td><strong>Batch Size:</strong> <?= (float)$b['batch_size_gal'] ?> Gallons</td>
                <td><strong>Status:</strong> <?= e($b['status']) ?></td>
            </tr>
            <tr>
                <td><strong>Start Date:</strong> <?= e($b['date_start'] ?: 'N/A') ?></td>
                <td><strong>1st Rack:</strong> <?= e($b['date_rack'] ?: 'N/A') ?></td>
                <td><strong>2nd Rack:</strong> <?= e($b['date_rack_2'] ?: 'N/A') ?></td>
                <td><strong>3rd Rack (Tertiary):</strong> <?= e($b['date_rack_3'] ?: 'N/A') ?></td>
                <td><strong>Bottled Date:</strong> <?= e($b['date_bottle'] ?: 'N/A') ?></td>
            </tr>
            <tr>
                <td><strong>Original Gravity (OG):</strong> <?= $b['gravity_og'] ? sprintf('%.3f', $b['gravity_og']) : 'N/A' ?></td>
                <td><strong>Final Gravity (FG):</strong> <?= $b['gravity_fg'] ? sprintf('%.3f', $b['gravity_fg']) : 'N/A' ?></td>
                <td><strong>Calculated ABV:</strong> <strong><?= $b['calculated_abv'] ? e($b['calculated_abv']) . '%' : 'N/A' ?></strong></td>
            </tr>
            <tr>
                <td><strong>Pitch Temp:</strong> <?= e($b['pitch_temp_f'] ?: 'N/A') ?></td>
                <td><strong>Ferment Temp:</strong> <?= e($b['ferment_temp_f'] ?: 'N/A') ?></td>
                <td><strong>Batch Rating:</strong> <?= $b['rating'] > 0 ? (int)$b['rating'] . " / 10" : 'Unrated' ?></td>
            </tr>
        </table>

        <div class="section-title">Ingredients Specs</div>
        <?php if (!empty($recipeDetails['ingredients'])): ?>
            <table class="readings-table">
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th>Type</th>
                        <th>Amount & Unit</th>
                        <th>Stage Addition</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recipeDetails['ingredients'] as $ing): ?>
                        <tr>
                            <td><strong><?= e($ing['name']) ?></strong></td>
                            <td><?= e($ing['ingredient_type']) ?></td>
                            <td><?= (float)$ing['amount'] ?> <?= e($ing['unit']) ?></td>
                            <td><?= e($ing['stage_addition']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="box"><?= e($b['ingredients'] ?: 'No ingredients recorded.') ?></div>
        <?php endif; ?>

        <?php if (!empty($recipeDetails['steps'])): ?>
            <div class="section-title">Brewing Step Schedule</div>
            <table class="readings-table">
                <thead>
                    <tr>
                        <th>Step #</th>
                        <th>Phase</th>
                        <th>Step Title</th>
                        <th>Duration / Temp</th>
                        <th>Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recipeDetails['steps'] as $stp): ?>
                        <tr>
                            <td><?= (int)$stp['step_number'] ?></td>
                            <td><?= e($stp['phase']) ?></td>
                            <td><strong><?= e($stp['title']) ?></strong></td>
                            <td><?= e($stp['duration']) ?> <?= $stp['target_temp'] ? ' @ ' . e($stp['target_temp']) : '' ?></td>
                            <td><?= e($stp['instructions']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($b['boil_notes']): ?>
            <div class="section-title">Boil & Process Notes</div>
            <div class="box"><?= e($b['boil_notes']) ?></div>
        <?php endif; ?>

        <div class="section-title">Tasting Notes & Reflections</div>
        <div class="box"><?= e($b['reflections'] ?: 'No tasting notes recorded.') ?></div>

        <?php if (!empty($readings)): ?>
            <div class="section-title">Hydrometer Gravity Readings History</div>
            <table class="readings-table">
                <thead>
                    <tr>
                        <th>Reading Date / Time</th>
                        <th>Specific Gravity (SG)</th>
                        <th>Temperature (°F)</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($readings as $r): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i', strtotime($r['reading_date'])) ?></td>
                            <td><strong><?= sprintf('%.3f', $r['gravity']) ?></strong></td>
                            <td><?= e($r['temp_f'] ?: '-') ?></td>
                            <td><?= e($r['notes'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </body>
    </html>
    <?php
} elseif ($type === 'recipe') {
    $stmt = $db->prepare("
        SELECT r.*, c.name as category_name, u.username
        FROM recipes r
        JOIN categories c ON r.category_id = c.id
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND (r.user_id = ? OR r.is_public = 1)
    ");
    $stmt->execute([$id, $user['id']]);
    $r = $stmt->fetch();

    if (!$r) die("Recipe not found.");

    $details = get_recipe_details($id);
    $ingredients = $details['ingredients'];
    $supplies    = $details['supplies'];
    $steps       = $details['steps'];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Recipe Sheet - <?= e($r['name']) ?></title>
        <style>
            body { font-family: Arial, sans-serif; margin: 30px; color: #1e293b; line-height: 1.5; }
            .header { border-bottom: 3px solid #d97706; padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
            h1 { margin: 0; color: #d97706; }
            .meta-table, .grid-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .meta-table td, .grid-table th, .grid-table td { border: 1px solid #cbd5e1; padding: 8px 12px; }
            .grid-table th { background: #f1f5f9; text-align: left; }
            .section-title { font-size: 18px; font-weight: bold; border-left: 4px solid #d97706; padding-left: 8px; margin: 20px 0 10px 0; }
            .box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 6px; white-space: pre-wrap; margin-bottom: 15px; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 20px; text-align: right;">
            <button onclick="window.print()" style="background: #d97706; color: white; border: none; padding: 10px 20px; font-size: 16px; border-radius: 5px; cursor: pointer;">🖨️ Print / Save as PDF</button>
        </div>

        <div class="header">
            <div>
                <h1>📖 CraftBrew Recipe Sheet</h1>
                <div style="font-size: 14px; color: #64748b;"><?= APP_NAME ?></div>
            </div>
            <div style="text-align: right;">
                <strong>Author:</strong> <?= e($r['username']) ?>
            </div>
        </div>

        <h2><?= e($r['name']) ?> (<?= e($r['category_name']) ?>)</h2>

        <table class="meta-table">
            <tr>
                <td><strong>Brew Style:</strong> <?= e($r['style'] ?: 'Custom') ?></td>
                <td><strong>Target Size:</strong> <?= (float)$r['batch_size_gal'] ?> Gallons</td>
            </tr>
            <tr>
                <td><strong>Target Original Gravity:</strong> <?= $r['target_og'] ? sprintf('%.3f', $r['target_og']) : 'N/A' ?></td>
                <td><strong>Target Final Gravity:</strong> <?= $r['target_fg'] ? sprintf('%.3f', $r['target_fg']) : 'N/A' ?></td>
            </tr>
            <tr>
                <td colspan="2"><strong>Estimated ABV:</strong> <strong><?= $r['target_abv'] ? e($r['target_abv']) . '%' : 'N/A' ?></strong></td>
            </tr>
        </table>

        <div class="section-title">🌾 Ingredients Breakdown</div>
        <?php if (!empty($ingredients)): ?>
            <table class="grid-table">
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th>Type</th>
                        <th>Amount & Unit</th>
                        <th>Stage Addition</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ingredients as $ing): ?>
                        <tr>
                            <td><strong><?= e($ing['name']) ?></strong></td>
                            <td><?= e($ing['ingredient_type']) ?></td>
                            <td><?= (float)$ing['amount'] ?> <?= e($ing['unit']) ?></td>
                            <td><?= e($ing['stage_addition']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="box"><?= e($r['ingredients'] ?: 'No ingredients specified.') ?></div>
        <?php endif; ?>

        <?php if (!empty($supplies)): ?>
            <div class="section-title">🛠️ Equipment & Supplies Checklist</div>
            <table class="grid-table">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplies as $sup): ?>
                        <tr>
                            <td><strong>[  ] <?= e($sup['item_name']) ?></strong></td>
                            <td><?= e($sup['category']) ?></td>
                            <td><?= e($sup['quantity']) ?></td>
                            <td><?= $sup['is_required'] ? 'Required' : 'Optional' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="section-title">📋 Brewing & Process Instructions</div>
        <?php if (!empty($steps)): ?>
            <table class="grid-table">
                <thead>
                    <tr>
                        <th>Step #</th>
                        <th>Phase</th>
                        <th>Step Title</th>
                        <th>Duration / Temp</th>
                        <th>Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($steps as $stp): ?>
                        <tr>
                            <td><?= (int)$stp['step_number'] ?></td>
                            <td><?= e($stp['phase']) ?></td>
                            <td><strong><?= e($stp['title']) ?></strong></td>
                            <td><?= e($stp['duration']) ?> <?= $stp['target_temp'] ? ' @ ' . e($stp['target_temp']) : '' ?></td>
                            <td><?= e($stp['instructions']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="box"><?= e($r['instructions'] ?: 'No instructions specified.') ?></div>
        <?php endif; ?>
    </body>
    </html>
    <?php
}
