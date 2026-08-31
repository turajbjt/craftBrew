<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_login();
require_admin();
$adminUser = current_user();
$db = get_db();

$message = '';
$error = '';
$details = [];

// Pre-selected Source User (from query param if clicked from users.php)
$preSelectedSource = (int)($_GET['source_id'] ?? 0);
$preSelectedTarget = (int)($_GET['target_id'] ?? 0);

// Fetch all users with their record counts
$usersStmt = $db->query("
    SELECT u.id, u.username, u.email, u.role, u.status,
           (SELECT COUNT(*) FROM recipes r WHERE r.user_id = u.id) AS recipe_count,
           (SELECT COUNT(*) FROM batches b WHERE b.user_id = u.id) AS batch_count,
           (SELECT COUNT(*) FROM inventory i WHERE i.user_id = u.id) AS inventory_count,
           (SELECT COUNT(*) FROM documents d WHERE d.user_id = u.id) AS document_count
    FROM users u
    ORDER BY u.username ASC
");
$allUsers = $usersStmt->fetchAll();

// Handle Migration / Copy Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transfer_user_data') {
    require_csrf_token();

    $sourceUserId = (int)($_POST['source_user_id'] ?? 0);
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $mode         = validate_enum($_POST['mode'] ?? 'migrate', ['migrate', 'copy'], 'migrate');

    $incRecipes   = !empty($_POST['include_recipes']);
    $incBatches   = !empty($_POST['include_batches']);
    $incInventory = !empty($_POST['include_inventory']);
    $incDocuments = !empty($_POST['include_documents']);

    if ($sourceUserId <= 0 || $targetUserId <= 0) {
        $error = "Please select both a valid Source User (User A) and Target User (User B).";
    } elseif ($sourceUserId === $targetUserId) {
        $error = "Source User and Target User cannot be the same account.";
    } elseif (!$incRecipes && !$incBatches && !$incInventory && !$incDocuments) {
        $error = "Please select at least one record type to copy or migrate.";
    } else {
        // Validate source and target exist
        $sUserStmt = $db->prepare("SELECT id, username, email FROM users WHERE id = ?");
        $sUserStmt->execute([$sourceUserId]);
        $sourceUser = $sUserStmt->fetch();

        $tUserStmt = $db->prepare("SELECT id, username, email FROM users WHERE id = ?");
        $tUserStmt->execute([$targetUserId]);
        $targetUser = $tUserStmt->fetch();

        if (!$sourceUser || !$targetUser) {
            $error = "One or both selected users could not be found in the database.";
        } else {
            try {
                init_schema();
                $db->beginTransaction();

                $stats = [
                    'recipes'     => 0,
                    'ingredients' => 0,
                    'supplies'    => 0,
                    'steps'       => 0,
                    'batches'     => 0,
                    'readings'    => 0,
                    'inventory'   => 0,
                    'documents'   => 0
                ];

                if ($mode === 'migrate') {
                    // ==========================================
                    // 1. REASSIGN OWNERSHIP (MOVE)
                    // ==========================================
                    if ($incRecipes) {
                        $stmt = $db->prepare("SELECT COUNT(*) FROM recipes WHERE user_id = ?");
                        $stmt->execute([$sourceUserId]);
                        $stats['recipes'] = (int)$stmt->fetchColumn();

                        $up = $db->prepare("UPDATE recipes SET user_id = ? WHERE user_id = ?");
                        $up->execute([$targetUserId, $sourceUserId]);
                    }

                    if ($incBatches) {
                        $stmt = $db->prepare("SELECT COUNT(*) FROM batches WHERE user_id = ?");
                        $stmt->execute([$sourceUserId]);
                        $stats['batches'] = (int)$stmt->fetchColumn();

                        $up = $db->prepare("UPDATE batches SET user_id = ? WHERE user_id = ?");
                        $up->execute([$targetUserId, $sourceUserId]);
                    }

                    if ($incInventory) {
                        $stmt = $db->prepare("SELECT COUNT(*) FROM inventory WHERE user_id = ?");
                        $stmt->execute([$sourceUserId]);
                        $stats['inventory'] = (int)$stmt->fetchColumn();

                        $up = $db->prepare("UPDATE inventory SET user_id = ? WHERE user_id = ?");
                        $up->execute([$targetUserId, $sourceUserId]);
                    }

                    if ($incDocuments) {
                        $stmt = $db->prepare("SELECT COUNT(*) FROM documents WHERE user_id = ?");
                        $stmt->execute([$sourceUserId]);
                        $stats['documents'] = (int)$stmt->fetchColumn();

                        $up = $db->prepare("UPDATE documents SET user_id = ? WHERE user_id = ?");
                        $up->execute([$targetUserId, $sourceUserId]);
                    }

                    $actionDescription = "Migrated ownership of {$stats['recipes']} recipes, {$stats['batches']} batches, {$stats['inventory']} inventory items, and {$stats['documents']} docs from '{$sourceUser['username']}' (ID #{$sourceUserId}) to '{$targetUser['username']}' (ID #{$targetUserId}).";
                    log_admin_action('migrate_user_records', $actionDescription, 'user', $targetUserId);

                    $message = "Successfully migrated all selected records from '{$sourceUser['username']}' to '{$targetUser['username']}'!";

                } else {
                    // ==========================================
                    // 2. DUPLICATE / CLONE (COPY)
                    // ==========================================
                    $recipeMap = []; // old_id => new_id

                    if ($incRecipes) {
                        $rStmt = $db->prepare("SELECT * FROM recipes WHERE user_id = ? ORDER BY id ASC");
                        $rStmt->execute([$sourceUserId]);
                        $sourceRecipes = $rStmt->fetchAll();

                        $insRecipe = $db->prepare("
                            INSERT INTO recipes (user_id, category_id, name, style, batch_size_gal, target_pre_og, target_og, target_fg, target_abv, ingredients, instructions, is_public)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $insIng = $db->prepare("
                            INSERT INTO recipe_ingredients (recipe_id, name, ingredient_type, amount, unit, stage_addition, notes)
                            SELECT ?, name, ingredient_type, amount, unit, stage_addition, notes
                            FROM recipe_ingredients WHERE recipe_id = ?
                        ");

                        $insSup = $db->prepare("
                            INSERT INTO recipe_supplies (recipe_id, item_name, category, quantity, is_required, notes)
                            SELECT ?, item_name, category, quantity, is_required, notes
                            FROM recipe_supplies WHERE recipe_id = ?
                        ");

                        $insStep = $db->prepare("
                            INSERT INTO recipe_steps (recipe_id, step_number, phase, title, duration, target_temp, instructions)
                            SELECT ?, step_number, phase, title, duration, target_temp, instructions
                            FROM recipe_steps WHERE recipe_id = ?
                        ");

                        foreach ($sourceRecipes as $r) {
                            $oldRId = (int)$r['id'];
                            $insRecipe->execute([
                                $targetUserId,
                                $r['category_id'],
                                $r['name'],
                                $r['style'] ?? '',
                                $r['batch_size_gal'] ?? 5.00,
                                $r['target_pre_og'] ?? null,
                                $r['target_og'] ?? null,
                                $r['target_fg'] ?? null,
                                $r['target_abv'] ?? null,
                                $r['ingredients'] ?? '',
                                $r['instructions'] ?? '',
                                $r['is_public'] ?? 1
                            ]);
                            $newRId = (int)$db->lastInsertId();
                            $recipeMap[$oldRId] = $newRId;
                            $stats['recipes']++;

                            // Clone child items
                            $insIng->execute([$newRId, $oldRId]);
                            $stats['ingredients'] += $insIng->rowCount();

                            $insSup->execute([$newRId, $oldRId]);
                            $stats['supplies'] += $insSup->rowCount();

                            $insStep->execute([$newRId, $oldRId]);
                            $stats['steps'] += $insStep->rowCount();
                        }
                    }

                    if ($incBatches) {
                        $bStmt = $db->prepare("SELECT * FROM batches WHERE user_id = ? ORDER BY id ASC");
                        $bStmt->execute([$sourceUserId]);
                        $sourceBatches = $bStmt->fetchAll();

                        $insBatch = $db->prepare("
                            INSERT INTO batches (user_id, recipe_id, category_id, batch_name, batch_type, batch_style, batch_size_gal, date_start, date_rack, date_rack_2, date_rack_3, date_bottle, pitch_temp_f, ferment_temp_f, gravity_pre_og, gravity_og, gravity_sg, gravity_tertiary, gravity_fg, calculated_abv, ingredients, boil_notes, reflections, rating, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $insReading = $db->prepare("
                            INSERT INTO fermentation_readings (batch_id, reading_date, gravity, temp_f, notes)
                            SELECT ?, reading_date, gravity, temp_f, notes
                            FROM fermentation_readings WHERE batch_id = ?
                        ");

                        foreach ($sourceBatches as $b) {
                            $oldBId = (int)$b['id'];
                            $origRecipeId = $b['recipe_id'] ? (int)$b['recipe_id'] : null;
                            $targetRecipeId = ($origRecipeId && isset($recipeMap[$origRecipeId])) ? $recipeMap[$origRecipeId] : $origRecipeId;

                            $insBatch->execute([
                                $targetUserId,
                                $targetRecipeId,
                                $b['category_id'],
                                $b['batch_name'],
                                $b['batch_type'] ?? '',
                                $b['batch_style'] ?? '',
                                $b['batch_size_gal'] ?? 5.00,
                                $b['date_start'] ?? null,
                                $b['date_rack'] ?? null,
                                $b['date_rack_2'] ?? null,
                                $b['date_rack_3'] ?? null,
                                $b['date_bottle'] ?? null,
                                $b['pitch_temp_f'] ?? '',
                                $b['ferment_temp_f'] ?? '',
                                $b['gravity_pre_og'] ?? null,
                                $b['gravity_og'] ?? null,
                                $b['gravity_sg'] ?? null,
                                $b['gravity_tertiary'] ?? null,
                                $b['gravity_fg'] ?? null,
                                $b['calculated_abv'] ?? null,
                                $b['ingredients'] ?? '',
                                $b['boil_notes'] ?? '',
                                $b['reflections'] ?? '',
                                $b['rating'] ?? 0,
                                $b['status'] ?? 'Primary'
                            ]);
                            $newBId = (int)$db->lastInsertId();
                            $stats['batches']++;

                            // Clone readings
                            $insReading->execute([$newBId, $oldBId]);
                            $stats['readings'] += $insReading->rowCount();
                        }
                    }

                    if ($incInventory) {
                        $insInv = $db->prepare("
                            INSERT INTO inventory (user_id, item_name, category, quantity, unit, notes)
                            SELECT ?, item_name, category, quantity, unit, notes
                            FROM inventory WHERE user_id = ?
                        ");
                        $insInv->execute([$targetUserId, $sourceUserId]);
                        $stats['inventory'] = $insInv->rowCount();
                    }

                    if ($incDocuments) {
                        $insDoc = $db->prepare("
                            INSERT INTO documents (user_id, title, category, filename, original_filename, file_type, description)
                            SELECT ?, title, category, filename, original_filename, file_type, description
                            FROM documents WHERE user_id = ?
                        ");
                        $insDoc->execute([$targetUserId, $sourceUserId]);
                        $stats['documents'] = $insDoc->rowCount();
                    }

                    $actionDescription = "Cloned {$stats['recipes']} recipes, {$stats['batches']} batches ({$stats['readings']} readings), {$stats['inventory']} inventory items, and {$stats['documents']} docs from '{$sourceUser['username']}' (ID #{$sourceUserId}) to '{$targetUser['username']}' (ID #{$targetUserId}).";
                    log_admin_action('copy_user_records', $actionDescription, 'user', $targetUserId);

                    $message = "Successfully created duplicate copies of selected records for '{$targetUser['username']}'!";
                }

                $db->commit();
                $details = $stats;

                // Refresh user list metrics
                $usersStmt = $db->query("
                    SELECT u.id, u.username, u.email, u.role, u.status,
                           (SELECT COUNT(*) FROM recipes r WHERE r.user_id = u.id) AS recipe_count,
                           (SELECT COUNT(*) FROM batches b WHERE b.user_id = u.id) AS batch_count,
                           (SELECT COUNT(*) FROM inventory i WHERE i.user_id = u.id) AS inventory_count,
                           (SELECT COUNT(*) FROM documents d WHERE d.user_id = u.id) AS document_count
                    FROM users u
                    ORDER BY u.username ASC
                ");
                $allUsers = $usersStmt->fetchAll();

            } catch (Exception $e) {
                $db->rollBack();
                $error = "Record transfer failed: " . $e->getMessage();
            }
        }
    }
}

$csrfToken = generate_csrf_token();
$pageTitle = "Transfer & Migrate User Data - Admin Portal";
$activePage = 'admin';
$adminSubPage = 'transfer';
require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/nav.php'; ?>

<div style="max-width: 900px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>🔄 User Record Migration &amp; Cloning Tool</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Reassign ownership or create full duplicate copies of recipes, fermentation batch logs, hydrometer drop curves, inventory, and documents between user accounts.
            </p>
        </div>
        <a href="users.php" class="btn btn-secondary">&laquo; Back to User List</a>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background: #dcfce7; color: #166534; padding: 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; border: 1px solid #bbf7d0; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
            <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700; font-size: 1.1rem; margin-bottom: 0.5rem;">
                <span>✅</span> <?= e($message) ?>
            </div>
            <?php if (!empty($details)): ?>
                <ul style="margin-left: 1.5rem; font-size: 0.95rem; line-height: 1.6;">
                    <li><strong>Recipes:</strong> <?= (int)$details['recipes'] ?> <?= $details['recipes'] > 0 ? "(with {$details['ingredients']} ingredients, {$details['supplies']} supplies, {$details['steps']} steps)" : "" ?></li>
                    <li><strong>Brew Batches:</strong> <?= (int)$details['batches'] ?> <?= $details['batches'] > 0 ? "(with {$details['readings']} hydrometer readings)" : "" ?></li>
                    <li><strong>Inventory Items:</strong> <?= (int)$details['inventory'] ?> items</li>
                    <li><strong>Document Library:</strong> <?= (int)$details['documents'] ?> documents</li>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div style="background: #ffe4e6; color: #9f1239; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #fecdd3;">
            <strong>⚠️ Error:</strong> <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 2rem;">
        <form method="POST" action="transfer.php" onsubmit="return confirmTransfer();">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="transfer_user_data">

            <!-- Step 1: User Selection -->
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem; margin-bottom: 1.5rem;">
                <h3 style="margin-bottom: 1rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem;">
                    <span>1️⃣</span> Select Users
                </h3>
                
                <div class="form-row">
                    <div class="form-group" style="flex: 1; min-width: 280px;">
                        <label class="form-label" style="font-weight: 700;">Source User (User A - Origin):</label>
                        <select name="source_user_id" id="sourceUserId" class="form-control" required onchange="updateSourceSummary()">
                            <option value="">-- Select Source User --</option>
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"
                                    data-recipes="<?= (int)$u['recipe_count'] ?>"
                                    data-batches="<?= (int)$u['batch_count'] ?>"
                                    data-inventory="<?= (int)$u['inventory_count'] ?>"
                                    data-docs="<?= (int)$u['document_count'] ?>"
                                    data-username="<?= e($u['username']) ?>"
                                    <?= ($preSelectedSource === (int)$u['id']) ? 'selected' : '' ?>>
                                    <?= e($u['username']) ?> (<?= e($u['email']) ?>) &bull; <?= (int)$u['recipe_count'] ?> recipes, <?= (int)$u['batch_count'] ?> batches
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-muted);">The user who currently owns the records.</small>
                    </div>

                    <div class="form-group" style="flex: 1; min-width: 280px;">
                        <label class="form-label" style="font-weight: 700;">Target User (User B - Destination):</label>
                        <select name="target_user_id" id="targetUserId" class="form-control" required onchange="updateTargetSummary()">
                            <option value="">-- Select Target User --</option>
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"
                                    data-username="<?= e($u['username']) ?>"
                                    <?= ($preSelectedTarget === (int)$u['id']) ? 'selected' : '' ?>>
                                    <?= e($u['username']) ?> (<?= e($u['email']) ?>) &bull; <?= ucfirst(e($u['role'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-muted);">The user who will receive the records.</small>
                    </div>
                </div>

                <!-- Live Summary Pill Box -->
                <div id="sourceSummaryBox" style="display: none; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem; margin-top: 1rem;">
                    <div style="font-weight: 700; color: var(--text-dark); margin-bottom: 0.5rem;">
                        📊 Current records held by <span id="summaryUsername" style="color: var(--primary-color);"></span>:
                    </div>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <span class="badge" style="background:#e0f2fe; color:#0369a1; font-size: 0.85rem; padding: 0.4rem 0.75rem;">
                            📜 Recipes: <strong id="sumRecipes">0</strong>
                        </span>
                        <span class="badge" style="background:#fef3c7; color:#92400e; font-size: 0.85rem; padding: 0.4rem 0.75rem;">
                            🍺 Brew Batches: <strong id="sumBatches">0</strong>
                        </span>
                        <span class="badge" style="background:#ecfccb; color:#365314; font-size: 0.85rem; padding: 0.4rem 0.75rem;">
                            📦 Cellar Stock: <strong id="sumInventory">0</strong>
                        </span>
                        <span class="badge" style="background:#f3e8ff; color:#6b21a8; font-size: 0.85rem; padding: 0.4rem 0.75rem;">
                            📄 Documents: <strong id="sumDocs">0</strong>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Step 2: Choose Operation Mode -->
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem; margin-bottom: 1.5rem;">
                <h3 style="margin-bottom: 1rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem;">
                    <span>2️⃣</span> Transfer Method
                </h3>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                    <label style="border: 2px solid #cbd5e1; border-radius: 10px; padding: 1.25rem; display: block; cursor: pointer; transition: all 0.2s;" id="modeMigrateLabel">
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                            <input type="radio" name="mode" value="migrate" id="modeMigrate" checked onchange="toggleModeStyle()" style="margin-top: 0.25rem;">
                            <div>
                                <strong style="font-size: 1.05rem; display: block; margin-bottom: 0.25rem; color: #1e293b;">🚚 Migrate &amp; Reassign (Move)</strong>
                                <p style="font-size: 0.88rem; color: var(--text-muted); margin: 0; line-height: 1.5;">
                                    Transfers full ownership of all selected records directly to User B. User A will <em>no longer own</em> these records.
                                </p>
                            </div>
                        </div>
                    </label>

                    <label style="border: 2px solid #cbd5e1; border-radius: 10px; padding: 1.25rem; display: block; cursor: pointer; transition: all 0.2s;" id="modeCopyLabel">
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                            <input type="radio" name="mode" value="copy" id="modeCopy" onchange="toggleModeStyle()" style="margin-top: 0.25rem;">
                            <div>
                                <strong style="font-size: 1.05rem; display: block; margin-bottom: 0.25rem; color: #1e293b;">📋 Copy &amp; Duplicate (Clone)</strong>
                                <p style="font-size: 0.88rem; color: var(--text-muted); margin: 0; line-height: 1.5;">
                                    Creates full duplicate copies of all selected records for User B. User A retains all their original records intact.
                                </p>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Step 3: Record Types Selection -->
            <div style="margin-bottom: 2rem;">
                <h3 style="margin-bottom: 1rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem;">
                    <span>3️⃣</span> Select Record Types to Include
                </h3>

                <div style="display: flex; flex-direction: column; gap: 0.75rem; background: #f8fafc; padding: 1.25rem; border-radius: 10px; border: 1px solid var(--border-color);">
                    <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; font-size: 0.95rem;">
                        <input type="checkbox" name="include_recipes" value="1" checked>
                        <span><strong>📜 Recipes &amp; Formulations</strong> <small style="color: var(--text-muted);">(Includes grain bills, hop additions, yeast schedules, supplies &amp; mashing steps)</small></span>
                    </label>

                    <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; font-size: 0.95rem;">
                        <input type="checkbox" name="include_batches" value="1" checked>
                        <span><strong>🍺 Brew Batches &amp; Fermentation Logs</strong> <small style="color: var(--text-muted);">(Includes hydrometer drop readings, gravity curves, ABV &amp; tasting reflections)</small></span>
                    </label>

                    <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; font-size: 0.95rem;">
                        <input type="checkbox" name="include_inventory" value="1" checked>
                        <span><strong>📦 Cellar &amp; Stock Inventory</strong> <small style="color: var(--text-muted);">(Includes fermentables, hops, yeasts, finings, and equipment stock)</small></span>
                    </label>

                    <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; font-size: 0.95rem;">
                        <input type="checkbox" name="include_documents" value="1" checked>
                        <span><strong>📄 Reference Document Library</strong> <small style="color: var(--text-muted);">(Includes uploaded PDF guides, style sheets, and reference notes)</small></span>
                    </label>
                </div>
            </div>

            <!-- Submit Button Area -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                <div style="font-size: 0.9rem; color: var(--text-muted);">
                    🛡️ All operations are wrapped in an atomic database transaction.
                </div>
                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                    ⚡ Execute Record Transfer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function updateSourceSummary() {
    const sel = document.getElementById('sourceUserId');
    const box = document.getElementById('sourceSummaryBox');
    const opt = sel.options[sel.selectedIndex];

    if (!opt || !opt.value) {
        box.style.display = 'none';
        return;
    }

    document.getElementById('summaryUsername').textContent = opt.getAttribute('data-username') || opt.text;
    document.getElementById('sumRecipes').textContent = opt.getAttribute('data-recipes') || '0';
    document.getElementById('sumBatches').textContent = opt.getAttribute('data-batches') || '0';
    document.getElementById('sumInventory').textContent = opt.getAttribute('data-inventory') || '0';
    document.getElementById('sumDocs').textContent = opt.getAttribute('data-docs') || '0';
    box.style.display = 'block';
}

function updateTargetSummary() {
    // Optional additional validation or styling
}

function toggleModeStyle() {
    const isMigrate = document.getElementById('modeMigrate').checked;
    const migLabel = document.getElementById('modeMigrateLabel');
    const copyLabel = document.getElementById('modeCopyLabel');

    if (isMigrate) {
        migLabel.style.borderColor = 'var(--primary-color)';
        migLabel.style.background = '#fffbeb';
        copyLabel.style.borderColor = '#cbd5e1';
        copyLabel.style.background = '#ffffff';
    } else {
        copyLabel.style.borderColor = 'var(--primary-color)';
        copyLabel.style.background = '#fffbeb';
        migLabel.style.borderColor = '#cbd5e1';
        migLabel.style.background = '#ffffff';
    }
}

function confirmTransfer() {
    const sSel = document.getElementById('sourceUserId');
    const tSel = document.getElementById('targetUserId');
    const isMigrate = document.getElementById('modeMigrate').checked;

    if (!sSel.value || !tSel.value) {
        alert('Please select both a Source User and a Target User.');
        return false;
    }
    if (sSel.value === tSel.value) {
        alert('Source User and Target User cannot be the same account.');
        return false;
    }

    const sName = sSel.options[sSel.selectedIndex].getAttribute('data-username') || sSel.options[sSel.selectedIndex].text;
    const tName = tSel.options[tSel.selectedIndex].getAttribute('data-username') || tSel.options[tSel.selectedIndex].text;
    const actionWord = isMigrate ? 'MIGRATE (MOVE OWNERSHIP OF)' : 'CLONE (CREATE DUPLICATE COPIES OF)';

    return confirm(`Are you sure you want to ${actionWord} all selected records from "${sName}" to "${tName}"?`);
}

document.addEventListener('DOMContentLoaded', function() {
    updateSourceSummary();
    toggleModeStyle();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
