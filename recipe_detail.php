<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$recipeId = sanitize_int($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT r.*, c.name as category_name, u.username
    FROM recipes r
    JOIN categories c ON r.category_id = c.id
    JOIN users u ON r.user_id = u.id
    WHERE r.id = ? AND (r.user_id = ? OR r.is_public = 1)
");
$stmt->execute([$recipeId, $user['id']]);
$r = $stmt->fetch();

if (!$r) {
    die("Recipe not found or access denied.");
}

$details = get_recipe_details($recipeId);
$ingredients = $details['ingredients'];
$supplies    = $details['supplies'];
$steps       = $details['steps'];

// Handle Recipe Exports (BeerXML & JSON)
if (isset($_GET['export'])) {
    $expType = $_GET['export'];
    $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $r['name']);

    if ($expType === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $cleanName . '_recipe.json"');
        $exportData = [
            'generator'       => APP_NAME . ' v' . APP_VERSION,
            'name'            => $r['name'],
            'category'        => $r['category_name'],
            'style'           => $r['style'],
            'batch_size_gal'  => (float)$r['batch_size_gal'],
            'target_og'       => $r['target_og'] ? (float)$r['target_og'] : null,
            'target_fg'       => $r['target_fg'] ? (float)$r['target_fg'] : null,
            'target_abv'      => $r['target_abv'] ? (float)$r['target_abv'] : null,
            'ingredients_raw' => $r['ingredients'],
            'instructions'    => $r['instructions'],
            'ingredients'     => $ingredients,
            'supplies'        => $supplies,
            'steps'           => $steps
        ];
        echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($expType === 'beerxml') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $cleanName . '_recipe.xml"');
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<RECIPES>\n";
        echo "  <RECIPE>\n";
        echo "    <NAME>" . htmlspecialchars($r['name']) . "</NAME>\n";
        echo "    <VERSION>1</VERSION>\n";
        echo "    <TYPE>All Grain</TYPE>\n";
        echo "    <STYLE><NAME>" . htmlspecialchars($r['style'] ?: 'Craft') . "</NAME></STYLE>\n";
        echo "    <BATCH_SIZE>" . round((float)$r['batch_size_gal'] * 3.78541, 2) . "</BATCH_SIZE>\n"; // Liters
        if ($r['target_og']) echo "    <OG>" . htmlspecialchars($r['target_og']) . "</OG>\n";
        if ($r['target_fg']) echo "    <FG>" . htmlspecialchars($r['target_fg']) . "</FG>\n";
        if ($r['target_abv']) echo "    <EST_ABV>" . htmlspecialchars($r['target_abv']) . "</EST_ABV>\n";
        echo "    <NOTES>" . htmlspecialchars($r['instructions'] ?? '') . "</NOTES>\n";

        if (!empty($ingredients)) {
            echo "    <FERMENTABLES>\n";
            foreach ($ingredients as $ing) {
                if ($ing['ingredient_type'] === 'Fermentable') {
                    echo "      <FERMENTABLE>\n";
                    echo "        <NAME>" . htmlspecialchars($ing['name']) . "</NAME>\n";
                    echo "        <AMOUNT>" . round((float)$ing['amount'] * 0.453592, 3) . "</AMOUNT>\n"; // kg
                    echo "      </FERMENTABLE>\n";
                }
            }
            echo "    </FERMENTABLES>\n";

            echo "    <HOPS>\n";
            foreach ($ingredients as $ing) {
                if ($ing['ingredient_type'] === 'Hop') {
                    echo "      <HOP>\n";
                    echo "        <NAME>" . htmlspecialchars($ing['name']) . "</NAME>\n";
                    echo "        <AMOUNT>" . round((float)$ing['amount'] * 0.0283495, 4) . "</AMOUNT>\n"; // kg
                    echo "        <USE>Boil</USE>\n";
                    echo "      </HOP>\n";
                }
            }
            echo "    </HOPS>\n";

            echo "    <YEASTS>\n";
            foreach ($ingredients as $ing) {
                if ($ing['ingredient_type'] === 'Yeast') {
                    echo "      <YEAST>\n";
                    echo "        <NAME>" . htmlspecialchars($ing['name']) . "</NAME>\n";
                    echo "      </YEAST>\n";
                }
            }
            echo "    </YEASTS>\n";
        }

        echo "  </RECIPE>\n";
        echo "</RECIPES>\n";
        exit;
    }
}

$pageTitle = e($r['name']) . " - Recipe";
$activePage = 'recipes';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="recipes.php" style="color: var(--text-muted); text-decoration: none;">&laquo; Back to All Recipes</a>
        <h1>📖 <?= e($r['name']) ?></h1>
        <p style="color: var(--text-muted);">
            <span class="badge badge-<?= strtolower(e($r['category_name'])) ?>"><?= e($r['category_name']) ?></span>
            &bull; <?= e($r['style'] ?: 'Craft Recipe') ?> &bull; Formulated by <?= e($r['username']) ?>
        </p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <?php if ($r['user_id'] == $user['id']): ?>
            <a href="recipe_edit.php?action=edit&id=<?= (int)$r['id'] ?>" class="btn btn-secondary">✏️ Edit</a>
        <?php endif; ?>
        <a href="batch_edit.php?action=new&recipe_id=<?= (int)$r['id'] ?>" class="btn btn-primary">🍺 Start Batch</a>
        <a href="export_pdf.php?type=recipe&id=<?= (int)$r['id'] ?>" class="btn btn-secondary" target="_blank">📄 PDF</a>
        <a href="recipe_detail.php?id=<?= (int)$r['id'] ?>&export=beerxml" class="btn btn-secondary" title="Export standard BeerXML format">📤 BeerXML</a>
        <a href="recipe_detail.php?id=<?= (int)$r['id'] ?>&export=json" class="btn btn-secondary" title="Export CraftBrew JSON format">📥 JSON</a>
    </div>
</div>

<!-- Specs Header Cards -->
<div class="card-grid" style="margin-bottom: 2rem;">
    <div class="card">
        <div class="card-subtitle">Target Batch Size</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: var(--primary-color);"><?= (float)$r['batch_size_gal'] ?> Gal</div>
    </div>
    <div class="card">
        <div class="card-subtitle">Target Original Gravity</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #1e293b;"><?= $r['target_og'] ? sprintf('%.3f', $r['target_og']) : 'N/A' ?></div>
    </div>
    <div class="card">
        <div class="card-subtitle">Target Final Gravity</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #10b981;"><?= $r['target_fg'] ? sprintf('%.3f', $r['target_fg']) : 'N/A' ?></div>
    </div>
    <div class="card">
        <div class="card-subtitle">Estimated ABV</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #3b82f6;"><?= $r['target_abv'] ? e($r['target_abv']) . '%' : 'N/A' ?></div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; align-items: start;">
    <div>
        <!-- 🌾 Structured Ingredients Table -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <h3 class="card-title">🌾 Ingredients Breakdown</h3>
            <?php if (empty($ingredients)): ?>
                <pre style="white-space: pre-wrap; font-family: inherit; background: #f8fafc; padding: 1rem; border-radius: 8px; font-size: 0.95rem; border: 1px solid #e2e8f0;"><?= e($r['ingredients'] ?: 'No ingredients specified.') ?></pre>
            <?php else: ?>
                <div class="table-container" style="margin-top: 1rem;">
                    <table>
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
                                    <td><span class="badge badge-primary"><?= e($ing['ingredient_type']) ?></span></td>
                                    <td><?= (float)$ing['amount'] ?> <?= e($ing['unit']) ?></td>
                                    <td><?= e($ing['stage_addition']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- 📋 Step-by-Step Brewing Process Schedule -->
        <div class="card">
            <h3 class="card-title">📋 Brewing Process Schedule</h3>
            <?php if (empty($steps)): ?>
                <pre style="white-space: pre-wrap; font-family: inherit; background: #f8fafc; padding: 1rem; border-radius: 8px; font-size: 0.95rem; border: 1px solid #e2e8f0;"><?= e($r['instructions'] ?: 'No detailed steps specified.') ?></pre>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 1rem;">
                    <?php foreach ($steps as $stp): ?>
                        <div style="background: #f8fafc; border-left: 4px solid var(--primary-color); padding: 1rem; border-radius: 0 8px 8px 0; border-top: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                                <strong style="font-size: 1.05rem;">Step <?= (int)$stp['step_number'] ?>: <?= e($stp['title']) ?></strong>
                                <span class="badge badge-secondary"><?= e($stp['phase']) ?></span>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                                <?php if (!empty($stp['duration'])): ?>⏱️ Duration: <strong><?= e($stp['duration']) ?></strong> &bull; <?php endif; ?>
                                <?php if (!empty($stp['target_temp'])): ?>🌡️ Target Temp: <strong><?= e($stp['target_temp']) ?></strong><?php endif; ?>
                            </div>
                            <?php if (!empty($stp['instructions'])): ?>
                                <p style="font-size: 0.95rem; color: var(--text-dark); margin: 0;"><?= e($stp['instructions']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar: Equipment & Supplies Checklist -->
    <div>
        <div class="card">
            <h3 class="card-title">🛠️ Equipment & Supplies</h3>
            <?php if (empty($supplies)): ?>
                <p style="color: var(--text-muted); font-size: 0.9rem;">No equipment list specified.</p>
            <?php else: ?>
                <ul style="list-style: none; margin-top: 1rem; padding: 0;">
                    <?php foreach ($supplies as $sup): ?>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" style="width: 18px; height: 18px;">
                            <div>
                                <strong><?= e($sup['item_name']) ?></strong>
                                <div style="font-size: 0.8rem; color: var(--text-muted);"><?= e($sup['category']) ?> &bull; <?= e($sup['quantity']) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
