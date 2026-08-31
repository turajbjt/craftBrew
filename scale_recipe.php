<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/bjcp_styles.php';

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
$origIngredients = $details['ingredients'];
$origSupplies    = $details['supplies'];
$origSteps       = $details['steps'];

$origSize = max(0.1, (float)($r['batch_size_gal'] ?: 5.0));
$targetSize = (float)($_POST['target_size'] ?? $_GET['target_size'] ?? $origSize);
$origEff = (float)($_POST['orig_eff'] ?? 72.0);
$targetEff = (float)($_POST['target_eff'] ?? 72.0);

$volFactor = $targetSize / $origSize;
$effFactor = ($origEff > 0 && $targetEff > 0) ? ($origEff / $targetEff) : 1.0;
$grainFactor = $volFactor * $effFactor;
$hopFactor = $volFactor;

// Calculate scaled ingredients
$scaledIngredients = [];
foreach ($origIngredients as $ing) {
    $item = $ing;
    $origAmt = (float)$ing['amount'];
    if ($ing['ingredient_type'] === 'Fermentable') {
        $item['scaled_amount'] = round($origAmt * $grainFactor, 2);
    } elseif ($ing['ingredient_type'] === 'Hop') {
        $item['scaled_amount'] = round($origAmt * $hopFactor, 2);
    } else {
        $item['scaled_amount'] = round($origAmt * $volFactor, 2);
    }
    $scaledIngredients[] = $item;
}

// Calculate estimated water requirements for all-grain recipes
$totalGrainsLbs = 0;
foreach ($scaledIngredients as $ing) {
    if ($ing['ingredient_type'] === 'Fermentable' && strtolower($ing['unit']) === 'lbs') {
        $totalGrainsLbs += (float)$ing['scaled_amount'];
    }
}
$strikeWaterGal = round(($totalGrainsLbs * 1.33) / 4.0, 2); // 1.33 qt/lb
$grainAbsorbGal = round($totalGrainsLbs * 0.125, 2);
$estSpargeGal   = round(max(0, ($targetSize * 1.25) + $grainAbsorbGal - $strikeWaterGal), 2);

$message = '';
$error = '';

// Handle "Save as New Scaled Recipe"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_scaled') {
    require_csrf_token();
    
    $newName = sanitize_text($_POST['new_recipe_name'] ?? ($r['name'] . " (" . $targetSize . " Gal Scaled)"), 100);
    
    try {
        $db->beginTransaction();
        
        $insStmt = $db->prepare("
            INSERT INTO recipes (user_id, category_id, name, style, batch_size_gal, target_pre_og, target_og, target_fg, target_abv, ingredients, instructions, is_public)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $insStmt->execute([
            $user['id'],
            $r['category_id'],
            $newName,
            $r['style'],
            $targetSize,
            $r['target_pre_og'],
            $r['target_og'],
            $r['target_fg'],
            $r['target_abv'],
            $r['ingredients'],
            $r['instructions'],
            0 // Default to private clone
        ]);
        
        $newRecipeId = (int)$db->lastInsertId();
        
        // Save structured components using platform helper
        save_recipe_details($newRecipeId, $scaledIngredients, $origSupplies, $origSteps);
        
        $db->commit();
        header("Location: recipe_detail.php?id=" . $newRecipeId . "&scaled_success=1");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error saving scaled recipe: " . $e->getMessage();
    }
}

$csrfToken = generate_csrf_token();
$pageTitle = "Scale Recipe: " . e($r['name']) . " - " . APP_NAME;
$activePage = 'recipes';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="recipe_detail.php?id=<?= $recipeId ?>" style="color: var(--text-muted); text-decoration: none;">&laquo; Back to Recipe</a>
        <h1 style="margin-top: 0.5rem;">⚖️ Recipe Auto-Scaler</h1>
        <p style="color: var(--text-muted);">Scale grain bills, hop charges, and water volumes for different batch sizes and brewhouse efficiencies.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.5rem;"><?= e($error) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom: 1.5rem; background: var(--bg-card); border: 1px solid var(--border-color); padding: 1.5rem; border-radius: 8px;">
    <form method="POST" action="scale_recipe.php?id=<?= $recipeId ?>" id="scaleForm">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="calculate">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; align-items: end;">
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 0.35rem;">Original Batch Size</label>
                <input type="text" class="form-control" value="<?= number_format($origSize, 2) ?> Gallons" readonly style="background: rgba(0,0,0,0.05);">
            </div>
            
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 0.35rem;">Target Batch Size (Gal)</label>
                <input type="number" step="0.1" min="0.5" max="500" name="target_size" class="form-control" value="<?= htmlspecialchars($targetSize) ?>" onchange="document.getElementById('scaleForm').submit();" required>
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 0.35rem;">Original Efficiency (%)</label>
                <input type="number" step="1" min="50" max="95" name="orig_eff" class="form-control" value="<?= htmlspecialchars($origEff) ?>" onchange="document.getElementById('scaleForm').submit();">
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 0.35rem;">Target System Efficiency (%)</label>
                <input type="number" step="1" min="50" max="95" name="target_eff" class="form-control" value="<?= htmlspecialchars($targetEff) ?>" onchange="document.getElementById('scaleForm').submit();">
            </div>
        </div>

        <div style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" class="btn btn-outline" onclick="setPresetSize(1.0)">1.0 Gal (Small Batch)</button>
            <button type="button" class="btn btn-outline" onclick="setPresetSize(2.5)">2.5 Gal (Half Batch)</button>
            <button type="button" class="btn btn-outline" onclick="setPresetSize(5.0)">5.0 Gal (Standard)</button>
            <button type="button" class="btn btn-outline" onclick="setPresetSize(10.0)">10.0 Gal (Double)</button>
            <button type="button" class="btn btn-outline" onclick="setPresetSize(15.5)">15.5 Gal (1/2 BBL)</button>
            <button type="submit" class="btn btn-primary" style="margin-left: auto;">⚡ Recalculate</button>
        </div>
    </form>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;" class="scale-grid">
    <!-- Scaled Ingredients Table -->
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 1.5rem; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="margin: 0;">📋 Scaled Recipe Formulation</h3>
            <span class="badge badge-info" style="font-size: 0.9rem;">Scale Factor: <?= round($volFactor, 2) ?>x (Vol) / <?= round($grainFactor, 2) ?>x (Malt)</span>
        </div>

        <?php if (!empty($scaledIngredients)): ?>
            <div style="overflow-x: auto;">
                <table class="table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                            <th style="padding: 8px;">Ingredient</th>
                            <th style="padding: 8px;">Type</th>
                            <th style="padding: 8px;">Original (<?= $origSize ?> Gal)</th>
                            <th style="padding: 8px; background: rgba(16, 185, 129, 0.1); color: #10b981;">Scaled (<?= $targetSize ?> Gal)</th>
                            <th style="padding: 8px;">Stage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scaledIngredients as $ing): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 8px; font-weight: 600;"><?= e($ing['name']) ?></td>
                                <td style="padding: 8px;"><span class="badge badge-secondary"><?= e($ing['ingredient_type']) ?></span></td>
                                <td style="padding: 8px; color: var(--text-muted);"><?= number_format((float)$ing['amount'], 2) ?> <?= e($ing['unit']) ?></td>
                                <td style="padding: 8px; font-weight: bold; background: rgba(16, 185, 129, 0.05); color: #059669;">
                                    <?= number_format((float)$ing['scaled_amount'], 2) ?> <?= e($ing['unit']) ?>
                                </td>
                                <td style="padding: 8px; color: var(--text-muted);"><?= e($ing['stage_addition']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color: var(--text-muted);">No structured ingredients found in this recipe to scale.</p>
        <?php endif; ?>
    </div>

    <!-- Scaled Water & Action Sidebar -->
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
        <?php if ($totalGrainsLbs > 0): ?>
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 1.5rem; border-radius: 8px;">
                <h3 style="margin-top: 0;">💧 Estimated Water Volumes</h3>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border-color); padding-bottom: 0.35rem;">
                        <span>Total Scaled Grain:</span>
                        <strong><?= number_format($totalGrainsLbs, 2) ?> lbs</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border-color); padding-bottom: 0.35rem;">
                        <span>Strike Water (1.33 qt/lb):</span>
                        <strong><?= number_format($strikeWaterGal, 2) ?> gal</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border-color); padding-bottom: 0.35rem;">
                        <span>Grain Absorption Loss:</span>
                        <strong><?= number_format($grainAbsorbGal, 2) ?> gal</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Estimated Sparge Water:</span>
                        <strong><?= number_format($estSpargeGal, 2) ?> gal</strong>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Save Scaled Recipe Form -->
        <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 1.5rem; border-radius: 8px;">
            <h3 style="margin-top: 0;">💾 Save to Recipe Library</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                Create a permanent clone of this scaled recipe with adjusted ingredient quantities in your account.
            </p>
            <form method="POST" action="scale_recipe.php?id=<?= $recipeId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save_scaled">
                <input type="hidden" name="target_size" value="<?= htmlspecialchars($targetSize) ?>">
                <input type="hidden" name="orig_eff" value="<?= htmlspecialchars($origEff) ?>">
                <input type="hidden" name="target_eff" value="<?= htmlspecialchars($targetEff) ?>">

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">Scaled Recipe Name</label>
                    <input type="text" name="new_recipe_name" class="form-control" value="<?= e($r['name']) ?> (<?= $targetSize ?> Gal Scaled)" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    💾 Save as New Scaled Recipe
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function setPresetSize(size) {
    document.querySelector('input[name="target_size"]').value = size;
    document.getElementById('scaleForm').submit();
}
</script>

<style>
@media (max-width: 850px) {
    .scale-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
