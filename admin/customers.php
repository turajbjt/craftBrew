<?php
/**
 * Customer Profile Management Portal (customers.php)
 */

$pageTitle = 'Customer Profiles';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/CustomerService.php';
require_once __DIR__ . '/../includes/PnpApiService.php';

$user = get_logged_user();
$isAuditor = ($user['role'] === 'auditor');

$actionMsg = null;
$errorMsg = null;

// Handle Actions (Manual Charge, Edit Profile, Disable Recurring, Delete Profile, Send Email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAuditor) {
    $action = $_POST['action'] ?? '';
    $targetSaasId = $_POST['saas_id'] ?? '';

    $customer = CustomerService::getCustomerBySaasId($targetSaasId);

    if ($customer) {
        if ($action === 'manual_charge') {
            $amount = (float)($_POST['amount'] ?? $customer['recurringfee']);
            if ($amount > 0) {
                $chargeResult = PnpApiService::processSingleAuthprev($customer, $amount);
                CustomerService::recordRecurringResult($customer, $chargeResult, $user['username']);
                audit_log('manual_charge', "Processed manual authprev charge of $$amount for SaaS ID $targetSaasId");
                $actionMsg = "Manual authprev charge processed! Result: " . strtoupper($chargeResult['result']);
            } else {
                $errorMsg = "Invalid charge amount.";
            }
        } elseif ($action === 'disable_recurring') {
            CustomerService::disableRecurring($targetSaasId, $user['username']);
            audit_log('disable_recurring', "Disabled recurring billing for SaaS ID $targetSaasId");
            $actionMsg = "Recurring billing disabled for customer $targetSaasId.";
        } elseif ($action === 'delete_profile') {
            CustomerService::deleteCustomer($targetSaasId, $user['username']);
            audit_log('delete_customer', "Deleted customer profile $targetSaasId");
            $actionMsg = "Customer profile $targetSaasId deleted successfully.";
        } elseif ($action === 'edit_profile') {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                UPDATE customer_profiles SET 
                    card_name = ?, email = ?, phone = ?, 
                    enddate = ?, billcycle = ?, billcycle_type = ?, 
                    status = ?, acct_code = ?, acct_code2 = ?
                WHERE saas_id = ?
            ");
            $stmt->execute([
                trim($_POST['card_name']),
                trim($_POST['email']),
                trim($_POST['phone']),
                trim($_POST['enddate']),
                (int)$_POST['billcycle'],
                trim($_POST['billcycle_type']),
                trim($_POST['status']),
                trim($_POST['acct_code']),
                trim($_POST['acct_code2']),
                $targetSaasId
            ]);
            audit_log('edit_customer', "Updated details for customer $targetSaasId");
            $actionMsg = "Customer profile updated successfully.";
        } elseif ($action === 'send_credentials') {
            if (!empty($customer['username'])) {
                EmailService::sendCredentialsEmail($customer['email'], $customer['username'], '*** Encrypted ***');
                audit_log('send_credentials', "Sent credentials email to " . $customer['email']);
                $actionMsg = "Credentials email dispatched to " . htmlspecialchars($customer['email']) . ".";
            } else {
                $errorMsg = "No username stored for this customer profile.";
            }
        }
    }
}

// Search and list logic
$searchQuery = trim($_GET['q'] ?? '');
$pdo = Database::getConnection();

if (!empty($searchQuery)) {
    $stmt = $pdo->prepare("
        SELECT * FROM customer_profiles 
        WHERE saas_id LIKE :q 
           OR orderid LIKE :q 
           OR card_name LIKE :q 
           OR email LIKE :q 
           OR username LIKE :q
        ORDER BY created_at DESC
    ");
    $stmt->execute(['q' => "%{$searchQuery}%"]);
} else {
    $stmt = $pdo->query("SELECT * FROM customer_profiles ORDER BY created_at DESC LIMIT 100");
}
$customers = $stmt->fetchAll();
?>

<style>
    .search-bar {
        display: flex;
        gap: 12px;
        margin-bottom: 25px;
    }
    .search-input {
        flex: 1;
        padding: 12px 18px;
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 12px;
        color: white;
        font-size: 0.95rem;
        outline: none;
    }
    .btn {
        padding: 10px 18px;
        border-radius: 10px;
        font-size: 0.88rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }
    .btn-primary { background: var(--accent); color: white; }
    .btn-primary:hover { background: var(--accent-hover); }
    .btn-danger { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
    .btn-danger:hover { background: #ef4444; color: white; }
    .btn-warning { background: rgba(245, 158, 11, 0.2); color: #fde047; border: 1px solid rgba(245, 158, 11, 0.3); }
    .btn-warning:hover { background: #f59e0b; color: white; }
    .btn-secondary { background: rgba(255, 255, 255, 0.08); color: var(--text-main); }

    .table-card {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 16px;
        overflow: hidden;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: 0.9rem;
    }

    th {
        background: rgba(15, 23, 42, 0.6);
        color: var(--text-muted);
        padding: 16px 20px;
        font-weight: 600;
        border-bottom: 1px solid var(--panel-border);
    }

    td {
        padding: 16px 20px;
        border-bottom: 1px solid var(--panel-border);
    }

    tr:hover td { background: rgba(255, 255, 255, 0.02); }

    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .status-active    { background: rgba(16, 185, 129, 0.2); color: #34d399; }
    .status-cancelled { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }
    .status-pending   { background: rgba(245, 158, 11, 0.2); color: #fde047; }

    /* Edit Modal styling */
    .modal {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.7);
        backdrop-filter: blur(8px);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }
    .modal-content {
        background: #1e293b;
        border: 1px solid var(--panel-border);
        border-radius: 20px;
        padding: 30px;
        max-width: 600px;
        width: 90%;
        color: white;
    }
</style>

<div style="margin-bottom: 25px;">
    <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 700;">Customer Profiles</h1>
    <p style="color: var(--text-muted);">Lookup, edit, run manual COF charges, or manage subscription cycles.</p>
</div>

<?php if ($actionMsg): ?>
    <div style="background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #34d399; padding: 14px; border-radius: 10px; margin-bottom: 20px;">
        ✓ <?= htmlspecialchars($actionMsg) ?>
    </div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div style="background: rgba(239, 68, 68, 0.2); border: 1px solid #ef4444; color: #fca5a5; padding: 14px; border-radius: 10px; margin-bottom: 20px;">
        ⚠ <?= htmlspecialchars($errorMsg) ?>
    </div>
<?php endif; ?>

<!-- Search Bar -->
<form method="GET" action="/admin/customers.php" class="search-bar">
    <input type="text" name="q" class="search-input" placeholder="Search by SaaS ID, Order ID, Name, Email, Username..." value="<?= htmlspecialchars($searchQuery) ?>">
    <button type="submit" class="btn btn-primary">Search</button>
    <?php if (!empty($searchQuery)): ?>
        <a href="/admin/customers.php" class="btn btn-secondary">Clear</a>
    <?php endif; ?>
</form>

<!-- Customers Table -->
<div class="table-card">
    <table>
        <thead>
            <tr>
                <th>SaaS ID & Name</th>
                <th>Card / Account</th>
                <th>Fee & Cycle</th>
                <th>Scheduled EndDate</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">No customer profiles found matching criteria.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td>
                            <div style="font-weight: 600; color: #a5b4fc; font-family: monospace;"><?= htmlspecialchars($c['saas_id']) ?></div>
                            <div style="font-size: 0.95rem; font-weight: 500; margin-top: 2px;"><?= htmlspecialchars($c['card_name']) ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($c['email']) ?></div>
                        </td>
                        <td>
                            <div style="font-weight: 500;"><?= htmlspecialchars(strtoupper($c['accttype'])) ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($c['card_number']) ?> (Exp: <?= htmlspecialchars($c['card_exp']) ?>)</div>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: #34d399;">$<?= number_format((float)$c['recurringfee'], 2) ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">Cycle: <?= (int)$c['billcycle'] ?> <?= $c['billcycle_type'] === 'd' ? 'day(s)' : 'month(s)' ?></div>
                        </td>
                        <td>
                            <div style="font-weight: 500;"><?= htmlspecialchars($c['enddate']) ?></div>
                            <div style="font-size: 0.78rem; color: var(--text-muted);">Last Attempt: <?= htmlspecialchars($c['last_attempt'] ?? 'None') ?></div>
                        </td>
                        <td>
                            <span class="status-badge status-<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <a href="/admin/history.php?saas_id=<?= urlencode($c['saas_id']) ?>" class="btn btn-secondary" title="View History">📜 History</a>
                                
                                <?php if (!$isAuditor): ?>
                                    <!-- Manual Charge Form -->
                                    <form method="POST" inline style="display:inline;" onsubmit="return confirm('Charge card on file $<?= number_format((float)$c['recurringfee'], 2) ?> now?');">
                                        <input type="hidden" name="action" value="manual_charge">
                                        <input type="hidden" name="saas_id" value="<?= htmlspecialchars($c['saas_id']) ?>">
                                        <input type="hidden" name="amount" value="<?= htmlspecialchars($c['recurringfee']) ?>">
                                        <button type="submit" class="btn btn-primary" title="Charge Card on File">💳 Charge</button>
                                    </form>

                                    <!-- Edit Trigger Button -->
                                    <button class="btn btn-warning" onclick="openEditModal(<?= htmlspecialchars(json_encode($c)) ?>)">✏️ Edit</button>

                                    <!-- Disable Recurring -->
                                    <?php if ($c['billcycle'] > 0): ?>
                                        <form method="POST" inline style="display:inline;" onsubmit="return confirm('Disable recurring billing for this customer?');">
                                            <input type="hidden" name="action" value="disable_recurring">
                                            <input type="hidden" name="saas_id" value="<?= htmlspecialchars($c['saas_id']) ?>">
                                            <button type="submit" class="btn btn-secondary" style="color: #fde047;">🚫 Disable</button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Delete Profile -->
                                    <form method="POST" inline style="display:inline;" onsubmit="return confirm('PERMANENTLY delete this customer profile?');">
                                        <input type="hidden" name="action" value="delete_profile">
                                        <input type="hidden" name="saas_id" value="<?= htmlspecialchars($c['saas_id']) ?>">
                                        <button type="submit" class="btn btn-danger">🗑 Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Edit Customer Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <h2 style="font-family: 'Outfit', sans-serif; margin-bottom: 20px;">Edit Customer Profile</h2>
        <form method="POST">
            <input type="hidden" name="action" value="edit_profile">
            <input type="hidden" name="saas_id" id="edit_saas_id">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted);">Card Name</label>
                    <input type="text" name="card_name" id="edit_card_name" required style="width:100%; padding:10px; background:#0f172a; border:1px solid var(--panel-border); border-radius:8px; color:white;">
                </div>
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted);">Email</label>
                    <input type="email" name="email" id="edit_email" required style="width:100%; padding:10px; background:#0f172a; border:1px solid var(--panel-border); border-radius:8px; color:white;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted);">Phone</label>
                    <input type="text" name="phone" id="edit_phone" style="width:100%; padding:10px; background:#0f172a; border:1px solid var(--panel-border); border-radius:8px; color:white;">
                </div>
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted);">Next EndDate (YYYYMMDD)</label>
                    <input type="text" name="enddate" id="edit_enddate" required style="width:100%; padding:10px; background:#0f172a; border:1px solid var(--panel-border); border-radius:8px; color:white;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted);">Billcycle (0=off)</label>
                    <input type="number" name="billcycle" id="edit_billcycle" required style="width:100%; padding:10px; background:#0f172a; border:1px solid var(--panel-border); border-radius:8px; color:white;">
                </div>
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted);">Cycle Type</label>
                    <select name="billcycle_type" id="edit_billcycle_type" style="width:100%; padding:10px; background:#0f172a; border:1px solid var(--panel-border); border-radius:8px; color:white;">
                        <option value="m">Months (m)</option>
                        <option value="d">Days (d)</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted);">Status</label>
                    <select name="status" id="edit_status" style="width:100%; padding:10px; background:#0f172a; border:1px solid var(--panel-border); border-radius:8px; color:white;">
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px;">
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted);">Acct Code 1</label>
                    <input type="text" name="acct_code" id="edit_acct_code" style="width:100%; padding:10px; background:#0f172a; border:1px solid var(--panel-border); border-radius:8px; color:white;">
                </div>
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted);">Acct Code 2</label>
                    <input type="text" name="acct_code2" id="edit_acct_code2" style="width:100%; padding:10px; background:#0f172a; border:1px solid var(--panel-border); border-radius:8px; color:white;">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(customer) {
    document.getElementById('edit_saas_id').value = customer.saas_id;
    document.getElementById('edit_card_name').value = customer.card_name;
    document.getElementById('edit_email').value = customer.email;
    document.getElementById('edit_phone').value = customer.phone || '';
    document.getElementById('edit_enddate').value = customer.enddate;
    document.getElementById('edit_billcycle').value = customer.billcycle;
    document.getElementById('edit_billcycle_type').value = customer.billcycle_type;
    document.getElementById('edit_status').value = customer.status;
    document.getElementById('edit_acct_code').value = customer.acct_code || '';
    document.getElementById('edit_acct_code2').value = customer.acct_code2 || '';

    document.getElementById('editModal').style.display = 'flex';
}
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
