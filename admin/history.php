<?php
/**
 * Customer Service History & Billing History Reports (history.php)
 */

$pageTitle = 'Reports & History';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/CustomerService.php';

$saasId = trim($_GET['saas_id'] ?? '');
$customer = null;
$billingHistory = [];
$serviceHistory = [];

if (!empty($saasId)) {
    $customer = CustomerService::getCustomerBySaasId($saasId);
    if ($customer) {
        $billingHistory = CustomerService::getBillingHistory($saasId);
        $serviceHistory = CustomerService::getServiceHistory($saasId);
    }
}

$pdo = Database::getConnection();
$allCustomers = $pdo->query("SELECT saas_id, card_name, email FROM customer_profiles ORDER BY card_name ASC")->fetchAll();
?>

<style>
    .selector-card {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
    }

    .form-group {
        display: flex;
        gap: 15px;
        align-items: center;
    }

    .select-control {
        flex: 1;
        padding: 12px;
        background: #0f172a;
        border: 1px solid var(--panel-border);
        border-radius: 10px;
        color: white;
        font-size: 0.95rem;
    }

    .btn-submit {
        padding: 12px 24px;
        background: var(--accent);
        color: white;
        border: none;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
    }

    .report-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
    }

    @media (max-width: 950px) {
        .report-grid { grid-template-columns: 1fr; }
    }

    .history-card {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 16px;
        padding: 24px;
    }

    .history-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.2rem;
        font-weight: 600;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .timeline {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .timeline-item {
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid var(--panel-border);
        border-radius: 12px;
        padding: 16px;
    }

    .timeline-meta {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-bottom: 6px;
    }

    .res-success { color: #34d399; font-weight: 600; }
    .res-fail    { color: #fca5a5; font-weight: 600; }
</style>

<div style="margin-bottom: 25px;">
    <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 700;">Service & Billing History Reports</h1>
    <p style="color: var(--text-muted);">Inspect complete transaction logs and service audit trails per SaaS ID.</p>
</div>

<!-- SaaS ID Selector -->
<div class="selector-card">
    <form method="GET" action="/admin/history.php" class="form-group">
        <label style="font-weight: 500;">Select Customer Profile:</label>
        <select name="saas_id" class="select-control" onchange="this.form.submit()">
            <option value="">-- Choose SaaS ID --</option>
            <?php foreach ($allCustomers as $c): ?>
                <option value="<?= htmlspecialchars($c['saas_id']) ?>" <?= ($saasId === $c['saas_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['card_name']) ?> (SaaS ID: <?= htmlspecialchars($c['saas_id']) ?> - <?= htmlspecialchars($c['email']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-submit">Generate Report</button>
    </form>
</div>

<?php if ($customer): ?>
    <div style="background: rgba(99, 102, 241, 0.1); border: 1px solid var(--accent); padding: 18px 24px; border-radius: 14px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.3rem; font-weight: 600; color: #a5b4fc;"><?= htmlspecialchars($customer['card_name']) ?></h2>
            <div style="font-size: 0.88rem; color: var(--text-muted); margin-top: 4px;">SaaS ID: <span style="font-family: monospace; color: white;"><?= htmlspecialchars($customer['saas_id']) ?></span> | Order ID: <?= htmlspecialchars($customer['orderid']) ?></div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 0.85rem; color: var(--text-muted);">Recurring Fee</div>
            <div style="font-family: 'Outfit', sans-serif; font-size: 1.4rem; font-weight: 700; color: #34d399;">$<?= number_format((float)$customer['recurringfee'], 2) ?></div>
        </div>
    </div>

    <div class="report-grid">
        <!-- Billing History Column -->
        <div class="history-card">
            <div class="history-title">💳 Billing History Log</div>
            <div class="timeline">
                <?php if (empty($billingHistory)): ?>
                    <div style="color: var(--text-muted); font-size: 0.9rem;">No billing records found.</div>
                <?php else: ?>
                    <?php foreach ($billingHistory as $b): ?>
                        <div class="timeline-item">
                            <div class="timeline-meta">
                                <span><?= htmlspecialchars($b['datetime']) ?> GMT</span>
                                <span>Order: <?= htmlspecialchars($b['orderID']) ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div style="font-size: 0.92rem; font-weight: 500;"><?= htmlspecialchars($b['description'] ?? 'Charge') ?></div>
                                <div class="<?= $b['result'] === 'success' ? 'res-success' : 'res-fail' ?>">
                                    $<?= number_format((float)$b['amount'], 2) ?> (<?= htmlspecialchars(strtoupper($b['result'])) ?>)
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Service History Column -->
        <div class="history-card">
            <div class="history-title">🛠 Service History Log</div>
            <div class="timeline">
                <?php if (empty($serviceHistory)): ?>
                    <div style="color: var(--text-muted); font-size: 0.9rem;">No service history records found.</div>
                <?php else: ?>
                    <?php foreach ($serviceHistory as $s): ?>
                        <div class="timeline-item">
                            <div class="timeline-meta">
                                <span><?= htmlspecialchars($s['datetime']) ?> GMT</span>
                                <span>Actor: <?= htmlspecialchars($s['actor_username'] ?? 'SYSTEM') ?></span>
                            </div>
                            <div style="font-weight: 600; color: #818cf8; margin-bottom: 4px; font-size: 0.92rem;">
                                Action: <?= htmlspecialchars($s['action']) ?>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-muted);">
                                <?= htmlspecialchars($s['reason'] ?? '') ?>
                                <?php if (!empty($s['ipaddress'])): ?>
                                    <span style="display:block; font-size: 0.78rem; opacity: 0.7; margin-top:2px;">IP: <?= htmlspecialchars($s['ipaddress']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif (!empty($saasId)): ?>
    <div style="background: rgba(239, 68, 68, 0.2); border: 1px solid #ef4444; color: #fca5a5; padding: 16px; border-radius: 10px;">
        No customer profile found matching SaaS ID: <?= htmlspecialchars($saasId) ?>
    </div>
<?php else: ?>
    <div style="text-align: center; color: var(--text-muted); padding: 50px 20px; background: var(--panel-bg); border-radius: 16px; border: 1px dashed var(--panel-border);">
        Select a customer profile above to view service and billing history logs.
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
