<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$batchId = sanitize_int($_GET['id'] ?? 0);

// Handle new gravity reading submission
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_reading') {
    require_csrf_token();

    $gravity = sanitize_float($_POST['gravity'] ?? 0);
    $tempF   = sanitize_text($_POST['temp_f'] ?? '', 10);
    $notes   = sanitize_text($_POST['notes'] ?? '', 1000);
    $readingDate = sanitize_text($_POST['reading_date'] ?? date('Y-m-d H:i:s'), 20);

    if ($gravity > 0) {
        $insR = $db->prepare("INSERT INTO fermentation_readings (batch_id, reading_date, gravity, temp_f, notes) VALUES (?, ?, ?, ?, ?)");
        $insR->execute([$batchId, $readingDate, $gravity, $tempF, $notes]);

        // Update latest SG and calculated ABV in batch
        $bChk = $db->prepare("SELECT gravity_og, gravity_fg FROM batches WHERE id = ?");
        $bChk->execute([$batchId]);
        $bRow = $bChk->fetch();
        if ($bRow && $bRow['gravity_og']) {
            $fgVal = $bRow['gravity_fg'] ?: $gravity;
            $newAbv = calculate_abv((float)$bRow['gravity_og'], (float)$fgVal);
            $upB = $db->prepare("UPDATE batches SET gravity_sg = ?, calculated_abv = ? WHERE id = ?");
            $upB->execute([$gravity, $newAbv, $batchId]);
        } else {
            $upB = $db->prepare("UPDATE batches SET gravity_sg = ? WHERE id = ?");
            $upB->execute([$gravity, $batchId]);
        }

        $msg = "Specific Gravity reading recorded successfully!";
    }
}

// Fetch batch info
$stmt = $db->prepare("
    SELECT b.*, c.name as category_name, r.name as recipe_name
    FROM batches b
    JOIN categories c ON b.category_id = c.id
    LEFT JOIN recipes r ON b.recipe_id = r.id
    WHERE b.id = ? AND b.user_id = ?
");
$stmt->execute([$batchId, $user['id']]);
$b = $stmt->fetch();

if (!$b) {
    die("Batch log not found or access denied.");
}

// Fetch structured recipe components if batch linked to a recipe
$recipeDetails = ['ingredients' => [], 'supplies' => [], 'steps' => []];
if (!empty($b['recipe_id'])) {
    $recipeDetails = get_recipe_details($b['recipe_id']);
}

// Fetch gravity readings history
$stmtR = $db->prepare("SELECT * FROM fermentation_readings WHERE batch_id = ? ORDER BY reading_date ASC");
$stmtR->execute([$batchId]);
$readings = $stmtR->fetchAll();

// Prepare chart datasets
$chartLabels = [];
$chartGravity = [];
$chartTemp = [];

if ($b['gravity_og']) {
    $chartLabels[] = 'Start (OG)';
    $chartGravity[] = (float)$b['gravity_og'];
    $chartTemp[] = (float)preg_replace('/[^\d\.]/', '', $b['pitch_temp_f'] ?: '70');
}

foreach ($readings as $r) {
    $chartLabels[] = date('M d, H:i', strtotime($r['reading_date']));
    $chartGravity[] = (float)$r['gravity'];
    $chartTemp[] = (float)preg_replace('/[^\d\.]/', '', $r['temp_f'] ?: '70');
}

if ($b['gravity_fg']) {
    $chartLabels[] = 'Final (FG)';
    $chartGravity[] = (float)$b['gravity_fg'];
    $chartTemp[] = (float)preg_replace('/[^\d\.]/', '', $b['ferment_temp_f'] ?: '70');
}

$csrfToken = generate_csrf_token();
$pageTitle = e($b['batch_name']) . " - Brew Log";
$activePage = 'batches';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="batches.php" style="color: var(--text-muted); text-decoration: none;">&laquo; Back to All Brew Logs</a>
        <h1>🍺 <?= e($b['batch_name']) ?></h1>
        <p style="color: var(--text-muted);">
            <span class="badge badge-<?= strtolower(e($b['category_name'])) ?>"><?= e($b['category_name']) ?></span>
            <span class="badge badge-<?= strtolower(str_replace(['/', ' '], '', $b['status'])) ?>"><?= e($b['status']) ?></span>
            &bull; <?= e($b['batch_style'] ?: 'Craft Brew') ?> &bull; <?= (float)$b['batch_size_gal'] ?> Gal
            <?php if (!empty($b['recipe_name'])): ?>
                &bull; Recipe: <strong><a href="recipe_detail.php?id=<?= (int)$b['recipe_id'] ?>" style="color: inherit;"><?= e($b['recipe_name']) ?></a></strong>
            <?php endif; ?>
        </p>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <a href="batch_edit.php?action=edit&id=<?= (int)$b['id'] ?>" class="btn btn-secondary">✏️ Edit Batch</a>
        <a href="export_pdf.php?type=batch&id=<?= (int)$b['id'] ?>" class="btn btn-primary" target="_blank">📄 Export PDF Sheet</a>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: #dcfce7; color: #166534; padding: 0.75rem; border-radius: 8px; margin-bottom: 1.5rem;">
        <?= e($msg) ?>
    </div>
<?php endif; ?>

<!-- 📅 Batch Timeline Calendar Bar -->
<div class="card" style="margin-bottom: 2rem;">
    <h3 class="card-title" style="margin-bottom: 1rem;">📅 Fermentation Milestone Timeline</h3>
    <div style="display: flex; justify-content: space-between; align-items: center; position: relative; gap: 0.5rem; flex-wrap: wrap;">
        <!-- Timeline Step 1: Brew Day -->
        <div style="flex: 1; min-width: 110px; text-align: center; background: <?= $b['date_start'] ? '#f0fdf4' : '#f8fafc' ?>; padding: 0.75rem; border-radius: 8px; border: 1px solid <?= $b['date_start'] ? '#bbf7d0' : '#e2e8f0' ?>;">
            <div style="font-size: 1.2rem;">📍</div>
            <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-dark);">Brew Day</div>
            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= $b['date_start'] ? date('M d, Y', strtotime($b['date_start'])) : 'Pending' ?></div>
        </div>

        <div style="color: var(--text-muted); font-size: 1.2rem;">➔</div>

        <!-- Timeline Step 2: Primary -->
        <div style="flex: 1; min-width: 110px; text-align: center; background: <?= ($b['status'] === 'Primary' || $b['date_rack'] || $b['date_bottle']) ? '#eff6ff' : '#f8fafc' ?>; padding: 0.75rem; border-radius: 8px; border: 1px solid <?= ($b['status'] === 'Primary' || $b['date_rack'] || $b['date_bottle']) ? '#bfdbfe' : '#e2e8f0' ?>;">
            <div style="font-size: 1.2rem;">🍺</div>
            <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-dark);">Primary</div>
            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= $b['pitch_temp_f'] ? e($b['pitch_temp_f']) : 'Active' ?></div>
        </div>

        <div style="color: var(--text-muted); font-size: 1.2rem;">➔</div>

        <!-- Timeline Step 3: 1st Rack -->
        <div style="flex: 1; min-width: 110px; text-align: center; background: <?= $b['date_rack'] ? '#fef3c7' : '#f8fafc' ?>; padding: 0.75rem; border-radius: 8px; border: 1px solid <?= $b['date_rack'] ? '#fde68a' : '#e2e8f0' ?>;">
            <div style="font-size: 1.2rem;">🧪</div>
            <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-dark);">1st Rack</div>
            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= $b['date_rack'] ? date('M d, Y', strtotime($b['date_rack'])) : 'Not Racked' ?></div>
        </div>

        <?php if ($b['date_rack_2']): ?>
            <div style="color: var(--text-muted); font-size: 1.2rem;">➔</div>
            <div style="flex: 1; min-width: 110px; text-align: center; background: #fef3c7; padding: 0.75rem; border-radius: 8px; border: 1px solid #fde68a;">
                <div style="font-size: 1.2rem;">🧪</div>
                <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-dark);">2nd Rack</div>
                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= date('M d, Y', strtotime($b['date_rack_2'])) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($b['date_rack_3']): ?>
            <div style="color: var(--text-muted); font-size: 1.2rem;">➔</div>
            <div style="flex: 1; min-width: 110px; text-align: center; background: #fef3c7; padding: 0.75rem; border-radius: 8px; border: 1px solid #fde68a;">
                <div style="font-size: 1.2rem;">🧪</div>
                <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-dark);">3rd Rack</div>
                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= date('M d, Y', strtotime($b['date_rack_3'])) ?></div>
            </div>
        <?php endif; ?>

        <div style="color: var(--text-muted); font-size: 1.2rem;">➔</div>

        <!-- Timeline Step 4: Bottled -->
        <div style="flex: 1; min-width: 110px; text-align: center; background: <?= $b['date_bottle'] ? '#f0fdf4' : '#f8fafc' ?>; padding: 0.75rem; border-radius: 8px; border: 1px solid <?= $b['date_bottle'] ? '#bbf7d0' : '#e2e8f0' ?>;">
            <div style="font-size: 1.2rem;">🍾</div>
            <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-dark);">Bottled / Kegged</div>
            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= $b['date_bottle'] ? date('M d, Y', strtotime($b['date_bottle'])) : 'Pending' ?></div>
        </div>

        <div style="color: var(--text-muted); font-size: 1.2rem;">➔</div>

        <!-- Timeline Step 5: Peak Drinkability -->
        <div style="flex: 1; min-width: 110px; text-align: center; background: <?= $b['date_bottle'] ? '#fae8ff' : '#f8fafc' ?>; padding: 0.75rem; border-radius: 8px; border: 1px solid <?= $b['date_bottle'] ? '#f5d0fe' : '#e2e8f0' ?>;">
            <div style="font-size: 1.2rem;">✨</div>
            <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-dark);">Peak Ready</div>
            <div style="font-size: 0.75rem; color: var(--text-muted);">
                <?= $b['date_bottle'] ? date('M d, Y', strtotime($b['date_bottle'] . ' +14 days')) : 'Est. ~14d Post-Bottle' ?>
            </div>
        </div>
    </div>
</div>

<!-- Core Metrics Grid -->
<div class="card-grid" style="margin-bottom: 2rem;">
    <div class="card">
        <div class="card-subtitle">Original Gravity (OG)</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: var(--primary-color);">
            <?= $b['gravity_og'] ? sprintf('%.3f', $b['gravity_og']) : 'Not Set' ?>
        </div>
        <small style="color: var(--text-muted);">Pitch Temp: <?= e($b['pitch_temp_f'] ?: 'N/A') ?></small>
    </div>

    <div class="card">
        <div class="card-subtitle">Final Gravity (FG)</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #10b981;">
            <?= $b['gravity_fg'] ? sprintf('%.3f', $b['gravity_fg']) : 'Fermenting...' ?>
        </div>
        <small style="color: var(--text-muted);">Ferment Temp: <?= e($b['ferment_temp_f'] ?: 'N/A') ?></small>
    </div>

    <div class="card">
        <div class="card-subtitle">Calculated ABV</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #3b82f6;">
            <?= $b['calculated_abv'] ? e($b['calculated_abv']) . '%' : '--%' ?>
        </div>
        <small style="color: var(--text-muted);">Standard Formula: (OG - FG) × 131.25</small>
    </div>

    <div class="card">
        <div class="card-subtitle">Batch Rating</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #f59e0b;">
            <?= $b['rating'] > 0 ? "⭐ " . (int)$b['rating'] . " / 10" : "Unrated" ?>
        </div>
        <small style="color: var(--text-muted);">Tasting rating score</small>
    </div>
</div>

<!-- Fermentation Chart -->
<?php if (count($chartGravity) > 1): ?>
    <div class="card" style="margin-bottom: 2rem;">
        <h3 class="card-title">📈 Fermentation Gravity Curve</h3>
        <canvas id="fermentChart" height="100"></canvas>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            renderFermentationChart(
                'fermentChart',
                <?= json_encode($chartLabels) ?>,
                <?= json_encode($chartGravity) ?>,
                <?= json_encode($chartTemp) ?>
            );
        });
    </script>
<?php endif; ?>

<!-- Details & Log Section -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; align-items: start;">
    <div>
        <!-- Ingredients & Recipe Specs -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <h3 class="card-title">🌾 Ingredients Specs</h3>
            <?php if (!empty($recipeDetails['ingredients'])): ?>
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
                            <?php foreach ($recipeDetails['ingredients'] as $ing): ?>
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
            <?php else: ?>
                <pre style="white-space: pre-wrap; font-family: inherit; background: #f8fafc; padding: 1rem; border-radius: 8px; font-size: 0.95rem; border: 1px solid #e2e8f0;"><?= e($b['ingredients'] ?: 'No ingredients recorded.') ?></pre>
            <?php endif; ?>
        </div>

        <?php if (!empty($recipeDetails['steps'])): ?>
            <!-- 📋 Interactive Brew Day & Fermentation Step Schedule -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 class="card-title">📋 Brewing Step Schedule Checklist</h3>
                <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1rem;">
                    <?php foreach ($recipeDetails['steps'] as $stp): ?>
                        <div style="background: #f8fafc; border-left: 4px solid var(--primary-color); padding: 0.85rem; border-radius: 0 8px 8px 0; border: 1px solid #e2e8f0; display: flex; gap: 0.75rem; align-items: flex-start;">
                            <input type="checkbox" style="width: 20px; height: 20px; margin-top: 2px;">
                            <div style="flex: 1;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <strong>Step <?= (int)$stp['step_number'] ?>: <?= e($stp['title']) ?></strong>
                                    <span class="badge badge-secondary"><?= e($stp['phase']) ?></span>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-muted);">
                                    <?php if (!empty($stp['duration'])): ?>⏱️ <?= e($stp['duration']) ?> &bull; <?php endif; ?>
                                    <?php if (!empty($stp['target_temp'])): ?>🌡️ <?= e($stp['target_temp']) ?><?php endif; ?>
                                </div>
                                <?php if (!empty($stp['instructions'])): ?>
                                    <p style="font-size: 0.9rem; color: var(--text-dark); margin: 0.25rem 0 0 0;"><?= e($stp['instructions']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif (!empty($b['boil_notes'])): ?>
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 class="card-title">🔥 Boil / Process Notes</h3>
                <pre style="white-space: pre-wrap; font-family: inherit; background: #f8fafc; padding: 1rem; border-radius: 8px; font-size: 0.95rem; border: 1px solid #e2e8f0;"><?= e($b['boil_notes']) ?></pre>
            </div>
        <?php endif; ?>

        <!-- Reflections & Tasting Comments -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <h3 class="card-title">🍷 Tasting Notes & Reflections</h3>
            <pre style="white-space: pre-wrap; font-family: inherit; background: #f8fafc; padding: 1rem; border-radius: 8px; font-size: 0.95rem; border: 1px solid #e2e8f0;"><?= e($b['reflections'] ?: 'No tasting notes recorded yet.') ?></pre>
        </div>

        <!-- Gravity Readings Log Table -->
        <div class="card">
            <h3 class="card-title">📊 Hydrometer Gravity & Temperature Readings</h3>
            <?php if (empty($readings)): ?>
                <p style="color: var(--text-muted); font-size: 0.9rem;">No interim SG readings logged yet.</p>
            <?php else: ?>
                <table style="margin-top: 1rem;">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Gravity (SG)</th>
                            <th>Temp (°F)</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($readings as $r): ?>
                            <tr>
                                <td><?= date('M d, Y H:i', strtotime($r['reading_date'])) ?></td>
                                <td><strong><?= sprintf('%.3f', $r['gravity']) ?></strong></td>
                                <td><?= e($r['temp_f'] ?: 'N/A') ?></td>
                                <td><?= e($r['notes'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar Info & Add Reading Form -->
    <div>
        <?php if (!empty($recipeDetails['supplies'])): ?>
            <!-- 🛠️ Equipment Checklist Sidebar -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 class="card-title">🛠️ Equipment Checklist</h3>
                <ul style="list-style: none; margin-top: 0.75rem; padding: 0;">
                    <?php foreach ($recipeDetails['supplies'] as $sup): ?>
                        <li style="padding: 0.4rem 0; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;">
                            <input type="checkbox" style="width: 16px; height: 16px;">
                            <div>
                                <strong><?= e($sup['item_name']) ?></strong>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($sup['quantity']) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Add Reading Form -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <h3 class="card-title">+ Add SG Reading</h3>
            <form method="POST" action="batch_detail.php?id=<?= (int)$b['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="add_reading">
                
                <div class="form-group">
                    <label class="form-label">Specific Gravity (SG)</label>
                    <input type="number" step="0.001" name="gravity" class="form-control" placeholder="1.015" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Temp (°F)</label>
                    <input type="text" name="temp_f" class="form-control" placeholder="68F">
                </div>

                <div class="form-group">
                    <label class="form-label">Reading Date/Time</label>
                    <input type="datetime-local" name="reading_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Active bubbling, clear sample"></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">Record Reading</button>
            </form>
        </div>

        <!-- Key Dates & Timeline Sidebar -->
        <div class="card">
            <h3 class="card-title">📅 Key Dates & Timeline</h3>
            <ul style="list-style: none; line-height: 2;">
                <li><strong>Start Date:</strong> <?= $b['date_start'] ? date('M d, Y', strtotime($b['date_start'])) : 'N/A' ?></li>
                <li><strong>1st Rack Date:</strong> <?= $b['date_rack'] ? date('M d, Y', strtotime($b['date_rack'])) : 'N/A' ?></li>
                <?php if (!empty($b['date_rack_2'])): ?>
                    <li><strong>2nd Rack Date:</strong> <?= date('M d, Y', strtotime($b['date_rack_2'])) ?></li>
                <?php endif; ?>
                <?php if (!empty($b['date_rack_3'])): ?>
                    <li><strong>3rd Rack Date (Tertiary):</strong> <?= date('M d, Y', strtotime($b['date_rack_3'])) ?></li>
                <?php endif; ?>
                <li><strong>Bottled Date:</strong> <?= $b['date_bottle'] ? date('M d, Y', strtotime($b['date_bottle'])) : 'N/A' ?></li>
                <li><strong>Created:</strong> <?= date('M d, Y', strtotime($b['created_at'])) ?></li>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
