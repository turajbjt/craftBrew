<?php
/**
 * Payment Plan Management Portal (admin/plans.php)
 * Accessible by Owner and Manager Roles
 */

$pageTitle = 'Payment Plan Management';
require_once __DIR__ . '/../includes/header.php';
require_role(['owner', 'manager']); // RBAC Guard

$actionMsg = null;
$errorMsg = null;
$pdo = Database::getConnection();

// Handle POST actions (Create, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_plan') {
        $planid        = strtoupper(trim($_POST['planid'] ?? ''));
        $description   = trim($_POST['description'] ?? '');
        $collectUnpw   = ($_POST['collect_unpw'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $currency      = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $initialAmount = (float)($_POST['initial_amount'] ?? 0.00);
        $initialMonths = (int)($_POST['initial_months'] ?? 0);
        $initialDays   = (int)($_POST['initial_days'] ?? 0);
        $recurringfee  = (float)($_POST['recurringfee'] ?? 0.00);
        $balanceRaw    = trim($_POST['balance'] ?? '');
        $balance       = ($balanceRaw !== '' && is_numeric($balanceRaw)) ? (float)$balanceRaw : null;
        $billcycle     = (int)($_POST['billcycle'] ?? 1);
        $billcycleType = strtolower(trim($_POST['billcycle_type'] ?? 'm')) === 'd' ? 'd' : 'm';
        $purchaseid    = trim($_POST['purchaseid'] ?? '');
        if ($purchaseid === '') { $purchaseid = null; }

        if (!empty($planid) && !empty($description)) {
            if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $planid)) {
                $errorMsg = "Plan ID must be 3-32 characters long and contain only letters, numbers, hyphens, or underscores.";
            } elseif ($recurringfee < 0 || $initialAmount < 0 || $billcycle < 1) {
                $errorMsg = "Fees must be non-negative and billing cycle must be at least 1.";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO payment_plans 
                        (planid, description, collect_unpw, currency, initial_amount, initial_months, initial_days, recurringfee, balance, billcycle, billcycle_type, purchaseid)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $planid, $description, $collectUnpw, $currency, 
                        $initialAmount, $initialMonths, $initialDays, 
                        $recurringfee, $balance, $billcycle, $billcycleType, $purchaseid
                    ]);

                    audit_log('create_plan', "Created payment plan '$planid' ($description) @ $" . number_format($recurringfee, 2) . "/cycle");
                    $actionMsg = "Payment plan <strong>" . htmlspecialchars($planid) . "</strong> created successfully!";
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $errorMsg = "A payment plan with Plan ID '" . htmlspecialchars($planid) . "' already exists.";
                    } else {
                        $errorMsg = "Error creating payment plan: " . $e->getMessage();
                    }
                }
            }
        } else {
            $errorMsg = "Please provide both a Plan ID and a Description.";
        }
    } elseif ($action === 'edit_plan') {
        $originalPlanid= trim($_POST['original_planid'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $collectUnpw   = ($_POST['collect_unpw'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $currency      = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $initialAmount = (float)($_POST['initial_amount'] ?? 0.00);
        $initialMonths = (int)($_POST['initial_months'] ?? 0);
        $initialDays   = (int)($_POST['initial_days'] ?? 0);
        $recurringfee  = (float)($_POST['recurringfee'] ?? 0.00);
        $balanceRaw    = trim($_POST['balance'] ?? '');
        $balance       = ($balanceRaw !== '' && is_numeric($balanceRaw)) ? (float)$balanceRaw : null;
        $billcycle     = (int)($_POST['billcycle'] ?? 1);
        $billcycleType = strtolower(trim($_POST['billcycle_type'] ?? 'm')) === 'd' ? 'd' : 'm';
        $purchaseid    = trim($_POST['purchaseid'] ?? '');
        if ($purchaseid === '') { $purchaseid = null; }

        if (!empty($originalPlanid) && !empty($description)) {
            if ($recurringfee < 0 || $initialAmount < 0 || $billcycle < 1) {
                $errorMsg = "Fees must be non-negative and billing cycle must be at least 1.";
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE payment_plans SET 
                        description = ?, collect_unpw = ?, currency = ?, initial_amount = ?, 
                        initial_months = ?, initial_days = ?, recurringfee = ?, balance = ?, 
                        billcycle = ?, billcycle_type = ?, purchaseid = ?
                        WHERE planid = ?");
                    $stmt->execute([
                        $description, $collectUnpw, $currency, $initialAmount, 
                        $initialMonths, $initialDays, $recurringfee, $balance, 
                        $billcycle, $billcycleType, $purchaseid, $originalPlanid
                    ]);

                    audit_log('edit_plan', "Updated payment plan '$originalPlanid' ($description)");
                    $actionMsg = "Payment plan <strong>" . htmlspecialchars($originalPlanid) . "</strong> updated successfully!";
                } catch (PDOException $e) {
                    $errorMsg = "Error updating payment plan: " . $e->getMessage();
                }
            }
        } else {
            $errorMsg = "Required fields missing for plan update.";
        }
    } elseif ($action === 'delete_plan') {
        $targetPlanid = trim($_POST['planid'] ?? '');

        if (!empty($targetPlanid)) {
            // Count active subscribers currently tied to this plan
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM customer_profiles WHERE planid = ?");
            $stmtCount->execute([$targetPlanid]);
            $subCount = (int)$stmtCount->fetchColumn();

            try {
                $stmt = $pdo->prepare("DELETE FROM payment_plans WHERE planid = ?");
                $stmt->execute([$targetPlanid]);

                audit_log('delete_plan', "Deleted payment plan '$targetPlanid' (Subscribers affected: $subCount)");
                $actionMsg = "Payment plan <strong>" . htmlspecialchars($targetPlanid) . "</strong> deleted successfully." . 
                             ($subCount > 0 ? " ($subCount existing customer profile(s) retained recurring settings)." : "");
            } catch (PDOException $e) {
                $errorMsg = "Error deleting payment plan: " . $e->getMessage();
            }
        }
    }
}

// Fetch all payment plans
$plans = $pdo->query("SELECT * FROM payment_plans ORDER BY recurringfee ASC")->fetchAll();

// Count active subscribers by planid
$subCountsRaw = $pdo->query("SELECT planid, COUNT(*) as cnt FROM customer_profiles GROUP BY planid")->fetchAll();
$subscriberMap = [];
foreach ($subCountsRaw as $row) {
    if (!empty($row['planid'])) {
        $subscriberMap[$row['planid']] = (int)$row['cnt'];
    }
}

// Calculate summary stats
$totalPlans = count($plans);
$monthlyPlans = 0;
$dailyPlans = 0;
$totalSubscribedInPlans = 0;

foreach ($plans as $p) {
    if ($p['billcycle_type'] === 'm') {
        $monthlyPlans++;
    } else {
        $dailyPlans++;
    }
    $totalSubscribedInPlans += ($subscriberMap[$p['planid']] ?? 0);
}
?>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .page-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--text-main);
    }

    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .metric-card {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 16px;
        padding: 20px;
        backdrop-filter: blur(12px);
    }

    .metric-title {
        font-size: 0.85rem;
        color: var(--text-muted);
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .metric-value {
        font-family: 'Outfit', sans-serif;
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--text-main);
    }

    .btn-create {
        background: linear-gradient(135deg, var(--accent) 0%, #818cf8 100%);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        font-size: 0.95rem;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .btn-create:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
    }

    .alert {
        padding: 16px;
        border-radius: 12px;
        margin-bottom: 25px;
        font-size: 0.95rem;
    }
    .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; }
    .alert-danger { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; }

    .table-card {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 16px;
        backdrop-filter: blur(12px);
        overflow: hidden;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    th, td {
        padding: 16px 20px;
        border-bottom: 1px solid var(--panel-border);
        font-size: 0.92rem;
    }

    th {
        background: rgba(15, 23, 42, 0.6);
        color: var(--text-muted);
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.78rem;
        letter-spacing: 0.5px;
    }

    tr:hover {
        background: rgba(255, 255, 255, 0.02);
    }

    .plan-code {
        font-family: monospace;
        font-weight: 700;
        color: #818cf8;
        background: rgba(99, 102, 241, 0.15);
        padding: 4px 8px;
        border-radius: 6px;
    }

    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 600;
        display: inline-block;
    }
    .badge-monthly { background: rgba(99, 102, 241, 0.2); color: #a5b4fc; }
    .badge-daily   { background: rgba(245, 158, 11, 0.2); color: #fde047; }
    .badge-yes     { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; }
    .badge-no      { background: rgba(148, 163, 184, 0.2); color: #cbd5e1; }

    .action-btn-group {
        display: flex;
        gap: 8px;
    }

    .btn-action {
        padding: 6px 12px;
        border-radius: 8px;
        border: 1px solid var(--panel-border);
        background: rgba(255, 255, 255, 0.05);
        color: var(--text-main);
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-action:hover {
        background: rgba(255, 255, 255, 0.12);
    }
    .btn-action-delete:hover {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.4);
    }

    /* Modal Backdrop & Dialog */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(11, 15, 25, 0.85);
        backdrop-filter: blur(8px);
        z-index: 1000;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-dialog {
        background: #131b2e;
        border: 1px solid var(--panel-border);
        border-radius: 20px;
        width: 100%;
        max-width: 650px;
        max-height: 90vh;
        overflow-y: auto;
        padding: 30px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 1px solid var(--panel-border);
        padding-bottom: 15px;
    }

    .modal-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.4rem;
        font-weight: 700;
    }

    .btn-close {
        background: none;
        border: none;
        color: var(--text-muted);
        font-size: 1.5rem;
        cursor: pointer;
    }
    .btn-close:hover { color: var(--text-main); }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .form-group.full-width {
        grid-column: span 2;
    }

    label {
        font-size: 0.85rem;
        color: var(--text-muted);
        font-weight: 500;
    }

    input, select, textarea {
        background: rgba(15, 23, 42, 0.8);
        border: 1px solid var(--panel-border);
        border-radius: 10px;
        padding: 10px 14px;
        color: var(--text-main);
        font-family: inherit;
        font-size: 0.92rem;
    }

    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 25px;
        border-top: 1px solid var(--panel-border);
        padding-top: 20px;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">💳 Payment Plan Offerings</h1>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
            Manage customer subscription plans offered on public order forms.
        </p>
    </div>
    <button class="btn-create" onclick="openCreateModal()">+ Create New Plan</button>
</div>

<?php if ($actionMsg): ?>
    <div class="alert alert-success"><?= $actionMsg ?></div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<!-- Summary Metrics -->
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-title">Total Active Plans</div>
        <div class="metric-value"><?= $totalPlans ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-title">Monthly Plans</div>
        <div class="metric-value" style="color: #818cf8;"><?= $monthlyPlans ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-title">Daily / Short-Term Plans</div>
        <div class="metric-value" style="color: #fde047;"><?= $dailyPlans ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-title">Plan Subscribers</div>
        <div class="metric-value" style="color: #6ee7b7;"><?= $totalSubscribedInPlans ?></div>
    </div>
</div>

<!-- Payment Plans Table -->
<div class="table-card">
    <table>
        <thead>
            <tr>
                <th>Plan ID</th>
                <th>Title / Description</th>
                <th>Recurring Fee</th>
                <th>Billing Cycle</th>
                <th>Initial Fee</th>
                <th>Collect Login</th>
                <th>Purchase ID</th>
                <th>Subscribers</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($plans)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 40px;">
                        No payment plans found. Click "+ Create New Plan" to add one.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($plans as $p): ?>
                    <?php 
                        $subs = $subscriberMap[$p['planid']] ?? 0;
                        $intervalText = $p['billcycle'] . ' ' . ($p['billcycle_type'] === 'm' ? ($p['billcycle'] > 1 ? 'Months' : 'Month') : ($p['billcycle'] > 1 ? 'Days' : 'Day'));
                    ?>
                    <tr>
                        <td><span class="plan-code"><?= htmlspecialchars($p['planid']) ?></span></td>
                        <td style="font-weight: 600;"><?= htmlspecialchars($p['description']) ?></td>
                        <td style="font-weight: 700; color: #6ee7b7;">
                            $<?= number_format((float)$p['recurringfee'], 2) ?> <?= htmlspecialchars($p['currency']) ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $p['billcycle_type'] === 'm' ? 'monthly' : 'daily' ?>">
                                🔄 <?= htmlspecialchars($intervalText) ?>
                            </span>
                        </td>
                        <td>
                            $<?= number_format((float)$p['initial_amount'], 2) ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $p['collect_unpw'] === 'Y' ? 'yes' : 'no' ?>">
                                <?= $p['collect_unpw'] === 'Y' ? 'Yes (Y)' : 'No (N)' ?>
                            </span>
                        </td>
                        <td>
                            <?= !empty($p['purchaseid']) ? '<code style="color:#cbd5e1;">' . htmlspecialchars($p['purchaseid']) . '</code>' : '<span style="color:var(--text-muted);">-</span>' ?>
                        </td>
                        <td style="font-weight: 600;">
                            <?= $subs ?>
                        </td>
                        <td>
                            <div class="action-btn-group">
                                <button class="btn-action" onclick='openEditModal(<?= json_encode($p) ?>)'>✏️ Edit</button>
                                <button class="btn-action btn-action-delete" onclick="openDeleteModal('<?= htmlspecialchars($p['planid'], ENT_QUOTES) ?>', '<?= htmlspecialchars($p['description'], ENT_QUOTES) ?>', <?= $subs ?>)">🗑️ Delete</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Create Plan Modal -->
<div id="createModal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2 class="modal-title">✨ Create Payment Plan</h2>
            <button class="btn-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_plan">
            <div class="form-grid">
                <div class="form-group">
                    <label>Plan ID (Code)*</label>
                    <input type="text" name="planid" placeholder="e.g. PLAN-PRO-M" required pattern="[A-Za-z0-9_-]{3,32}" title="Uppercase letters, numbers, hyphens or underscores">
                </div>
                <div class="form-group">
                    <label>Plan Title / Description*</label>
                    <input type="text" name="description" placeholder="e.g. Pro Monthly Subscription" required>
                </div>
                <div class="form-group">
                    <label>Recurring Fee ($)*</label>
                    <input type="number" step="0.01" min="0" name="recurringfee" placeholder="79.99" required>
                </div>
                <div class="form-group">
                    <label>Initial Upfront Fee ($)</label>
                    <input type="number" step="0.01" min="0" name="initial_amount" value="0.00" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Billing Cycle Interval*</label>
                    <input type="number" min="1" name="billcycle" value="1" required>
                </div>
                <div class="form-group">
                    <label>Billing Cycle Unit*</label>
                    <select name="billcycle_type">
                        <option value="m">Months (m)</option>
                        <option value="d">Days (d)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Collect Username/Password at Signup?</label>
                    <select name="collect_unpw">
                        <option value="N">No (N)</option>
                        <option value="Y">Yes (Y)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Currency Code</label>
                    <input type="text" name="currency" value="USD" required>
                </div>
                <div class="form-group">
                    <label>Initial Period Months</label>
                    <input type="number" min="0" name="initial_months" value="0">
                </div>
                <div class="form-group">
                    <label>Initial Period Days</label>
                    <input type="number" min="0" name="initial_days" value="0">
                </div>
                <div class="form-group">
                    <label>Purchase ID / Gateway Group Classifier</label>
                    <input type="text" name="purchaseid" placeholder="e.g. GROUP-PRO">
                </div>
                <div class="form-group">
                    <label>Total Plan Balance Cap ($ optional)</label>
                    <input type="number" step="0.01" min="0" name="balance" placeholder="Leave empty for unlimited">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-action" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn-create">Save Payment Plan</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Plan Modal -->
<div id="editModal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2 class="modal-title">✏️ Edit Payment Plan</h2>
            <button class="btn-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_plan">
            <input type="hidden" name="original_planid" id="edit_original_planid">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Plan ID (Immutable)</label>
                    <input type="text" id="edit_planid_display" disabled style="opacity: 0.6; cursor: not-allowed;">
                </div>
                <div class="form-group">
                    <label>Plan Title / Description*</label>
                    <input type="text" name="description" id="edit_description" required>
                </div>
                <div class="form-group">
                    <label>Recurring Fee ($)*</label>
                    <input type="number" step="0.01" min="0" name="recurringfee" id="edit_recurringfee" required>
                </div>
                <div class="form-group">
                    <label>Initial Upfront Fee ($)</label>
                    <input type="number" step="0.01" min="0" name="initial_amount" id="edit_initial_amount">
                </div>
                <div class="form-group">
                    <label>Billing Cycle Interval*</label>
                    <input type="number" min="1" name="billcycle" id="edit_billcycle" required>
                </div>
                <div class="form-group">
                    <label>Billing Cycle Unit*</label>
                    <select name="billcycle_type" id="edit_billcycle_type">
                        <option value="m">Months (m)</option>
                        <option value="d">Days (d)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Collect Username/Password at Signup?</label>
                    <select name="collect_unpw" id="edit_collect_unpw">
                        <option value="N">No (N)</option>
                        <option value="Y">Yes (Y)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Currency Code</label>
                    <input type="text" name="currency" id="edit_currency" required>
                </div>
                <div class="form-group">
                    <label>Initial Period Months</label>
                    <input type="number" min="0" name="initial_months" id="edit_initial_months">
                </div>
                <div class="form-group">
                    <label>Initial Period Days</label>
                    <input type="number" min="0" name="initial_days" id="edit_initial_days">
                </div>
                <div class="form-group">
                    <label>Purchase ID / Gateway Group Classifier</label>
                    <input type="text" name="purchaseid" id="edit_purchaseid">
                </div>
                <div class="form-group">
                    <label>Total Plan Balance Cap ($ optional)</label>
                    <input type="number" step="0.01" min="0" name="balance" id="edit_balance">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-action" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-create">Update Payment Plan</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Plan Confirmation Modal -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <h2 class="modal-title" style="color: #ef4444;">🗑️ Confirm Plan Deletion</h2>
            <button class="btn-close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete_plan">
            <input type="hidden" name="planid" id="delete_planid">
            
            <p style="font-size: 0.95rem; line-height: 1.5; color: var(--text-main); margin-bottom: 15px;">
                Are you sure you want to delete payment plan <strong id="delete_planid_display" style="color: #818cf8;"></strong> (<span id="delete_desc_display"></span>)?
            </p>

            <div id="delete_subscriber_warning" style="display: none; padding: 12px; border-radius: 10px; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); color: #fde047; font-size: 0.88rem; margin-bottom: 15px;">
                ⚠️ <strong>Notice:</strong> This plan has <span id="delete_sub_count"></span> active subscriber profile(s). Deleting the plan definition will set their `planid` foreign reference to NULL, but will preserve all active recurring billing dates and rates.
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-action" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn-action btn-action-delete" style="padding: 10px 20px; font-weight: 600;">Delete Plan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.add('active');
}

function openEditModal(plan) {
    document.getElementById('edit_original_planid').value = plan.planid;
    document.getElementById('edit_planid_display').value = plan.planid;
    document.getElementById('edit_description').value = plan.description || '';
    document.getElementById('edit_recurringfee').value = plan.recurringfee || 0;
    document.getElementById('edit_initial_amount').value = plan.initial_amount || 0;
    document.getElementById('edit_billcycle').value = plan.billcycle || 1;
    document.getElementById('edit_billcycle_type').value = plan.billcycle_type || 'm';
    document.getElementById('edit_collect_unpw').value = plan.collect_unpw || 'N';
    document.getElementById('edit_currency').value = plan.currency || 'USD';
    document.getElementById('edit_initial_months').value = plan.initial_months || 0;
    document.getElementById('edit_initial_days').value = plan.initial_days || 0;
    document.getElementById('edit_purchaseid').value = plan.purchaseid || '';
    document.getElementById('edit_balance').value = plan.balance !== null ? plan.balance : '';

    document.getElementById('editModal').classList.add('active');
}

function openDeleteModal(planid, description, subCount) {
    document.getElementById('delete_planid').value = planid;
    document.getElementById('delete_planid_display').innerText = planid;
    document.getElementById('delete_desc_display').innerText = description;

    const warnBox = document.getElementById('delete_subscriber_warning');
    if (subCount > 0) {
        document.getElementById('delete_sub_count').innerText = subCount;
        warnBox.style.display = 'block';
    } else {
        warnBox.style.display = 'none';
    }

    document.getElementById('deleteModal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Close modals when clicking outside dialog
window.onclick = function(event) {
    if (event.target.classList.contains('modal-overlay')) {
        event.target.classList.remove('active');
    }
};
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
