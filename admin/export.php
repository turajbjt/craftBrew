<?php
/**
 * Customer & Transaction Audit Data Export Tool (export.php)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth_check.php';
require_login();

// Handle Export File Generation
if (isset($_GET['download'])) {
    $type   = $_GET['type'] ?? 'customers';
    $format = $_GET['format'] ?? 'csv';

    $pdo = Database::getConnection();
    audit_log('data_export', "Exported dataset '$type' in format '$format'");

    $filename = sprintf("export_%s_%s.%s", $type, date('Ymd_His'), $format);

    if ($type === 'billing') {
        $stmt = $pdo->query("SELECT * FROM billing_history ORDER BY id DESC");
        $data = $stmt->fetchAll();
    } elseif ($type === 'service') {
        $stmt = $pdo->query("SELECT * FROM service_history ORDER BY id DESC");
        $data = $stmt->fetchAll();
    } else {
        // Customer profiles
        $stmt = $pdo->query("SELECT saas_id, orderid, username, card_name, phone, email, accttype, card_number, card_exp, startdate, enddate, last_attempt, last_billed, billcycle, billcycle_type, currency, recurringfee, balance, status, planid, created_at FROM customer_profiles ORDER BY created_at DESC");
        $data = $stmt->fetchAll();
    }

    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    } else {
        // CSV Export
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        if (!empty($data)) {
            // Write Header
            fputcsv($output, array_keys($data[0]));
            // Write Rows
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
        exit;
    }
}

$pageTitle = 'Export Audit Data';
require_once __DIR__ . '/../includes/header.php';
?>

<div style="margin-bottom: 25px;">
    <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 700;">Data Export & Audit Center</h1>
    <p style="color: var(--text-muted);">Export customer records, billing histories, and service audit logs for external auditing.</p>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px;">
    <!-- Export Profiles -->
    <div style="background: var(--panel-bg); border: 1px solid var(--panel-border); border-radius: 16px; padding: 24px;">
        <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.2rem; margin-bottom: 10px;">👥 Customer Profiles Dataset</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px;">Complete table export of customer records including SaaS ID, masked cards, dates, and billing cycles.</p>
        <div style="display: flex; gap: 10px;">
            <a href="?download=1&type=customers&format=csv" class="btn btn-primary">Download CSV</a>
            <a href="?download=1&type=customers&format=json" class="btn btn-secondary">Download JSON</a>
        </div>
    </div>

    <!-- Export Billing History -->
    <div style="background: var(--panel-bg); border: 1px solid var(--panel-border); border-radius: 16px; padding: 24px;">
        <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.2rem; margin-bottom: 10px;">💳 Billing Transactions Dataset</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px;">All transaction log entries, amounts, statuses, and order IDs across all customer accounts.</p>
        <div style="display: flex; gap: 10px;">
            <a href="?download=1&type=billing&format=csv" class="btn btn-primary">Download CSV</a>
            <a href="?download=1&type=billing&format=json" class="btn btn-secondary">Download JSON</a>
        </div>
    </div>

    <!-- Export Service Logs -->
    <div style="background: var(--panel-bg); border: 1px solid var(--panel-border); border-radius: 16px; padding: 24px;">
        <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.2rem; margin-bottom: 10px;">🛠 Service History Audit Logs</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px;">Audit trails of profile creations, status edits, recurring billing attempts, and admin actions.</p>
        <div style="display: flex; gap: 10px;">
            <a href="?download=1&type=service&format=csv" class="btn btn-primary">Download CSV</a>
            <a href="?download=1&type=service&format=json" class="btn btn-secondary">Download JSON</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
