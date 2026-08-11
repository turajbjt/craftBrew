<?php
/**
 * Manual API Transaction Lookup (query_trans mode)
 */

$pageTitle = 'Manual API Query';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/PnpApiService.php';

$orderId = trim($_GET['orderid'] ?? '');
$queryResult = null;

if (!empty($orderId)) {
    $queryResult = PnpApiService::queryTransaction($orderId);
    audit_log('query_trans_lookup', "Executed manual query_trans API lookup for Order ID: " . $orderId);
}
?>

<div style="margin-bottom: 25px;">
    <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 700;">Plug'n'Pay API Query Tool</h1>
    <p style="color: var(--text-muted);">Execute manual query_trans mode lookups directly against payment gateway records by Order ID.</p>
</div>

<div style="background: var(--panel-bg); border: 1px solid var(--panel-border); border-radius: 16px; padding: 24px; margin-bottom: 30px;">
    <form method="GET" action="/admin/query_trans.php" style="display: flex; gap: 15px; align-items: center;">
        <input type="text" name="orderid" placeholder="Enter PnP Order ID (e.g. SS-20260808-1234)..." value="<?= htmlspecialchars($orderId) ?>" required style="flex: 1; padding: 12px 18px; background: #0f172a; border: 1px solid var(--panel-border); border-radius: 10px; color: white; font-size: 0.95rem;">
        <button type="submit" class="btn btn-primary">Execute query_trans</button>
    </form>
</div>

<?php if ($queryResult): ?>
    <div style="background: var(--panel-bg); border: 1px solid var(--panel-border); border-radius: 16px; padding: 28px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--panel-border); padding-bottom: 15px;">
            <div>
                <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.3rem;">Query Result for Order: <span style="color: #a5b4fc; font-family: monospace;"><?= htmlspecialchars($orderId) ?></span></h3>
            </div>
            <div>
                <span class="status-badge status-<?= $queryResult['success'] ? 'active' : 'cancelled' ?>">
                    <?= htmlspecialchars(strtoupper($queryResult['status'] ?? ($queryResult['success'] ? 'SUCCESS' : 'FAILED'))) ?>
                </span>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px;">
            <div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">Amount</div>
                <div style="font-weight: 700; font-size: 1.2rem; color: #34d399;">$<?= number_format((float)($queryResult['amount'] ?? 0), 2) ?> <?= htmlspecialchars($queryResult['currency'] ?? 'USD') ?></div>
            </div>
            <div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">Auth Code</div>
                <div style="font-weight: 600; font-size: 1.1rem;"><?= htmlspecialchars($queryResult['auth_code'] ?? 'N/A') ?></div>
            </div>
            <div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">Gateway Response Text</div>
                <div style="font-weight: 500; font-size: 0.95rem; color: #cbd5e1;"><?= htmlspecialchars($queryResult['response_text'] ?? 'N/A') ?></div>
            </div>
        </div>

        <div style="margin-top: 20px;">
            <h4 style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 8px;">Raw Gateway Payload Response</h4>
            <pre style="background: #0f172a; padding: 15px; border-radius: 10px; border: 1px solid var(--panel-border); font-family: monospace; font-size: 0.85rem; color: #64748b; overflow-x: auto;"><?= htmlspecialchars($queryResult['raw_response'] ?? '') ?></pre>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
