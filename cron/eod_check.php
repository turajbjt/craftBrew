<?php
/**
 * End-of-Day Transaction Check Engine (eod_check.php)
 * Runs after day ends (e.g. 11:00 PM GMT) to verify all transactions using PnP query_trans API mode
 * Usage via CLI: php cron/eod_check.php
 */

if (php_sapi_name() !== 'cli') {
    die("Error: This script must be executed via PHP CLI.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/PnpApiService.php';
require_once __DIR__ . '/../includes/EmailService.php';

$gmtNow = get_gmt_now_formatted();
$todayPrefix = get_gmt_today_date(); // YYYYMMDD

echo sprintf("[%s GMT] Starting End-of-Day (EOD) Transaction Audit using query_trans mode...\n", $gmtNow);

$pdo = Database::getConnection();

// Fetch all billing history records for today
$stmt = $pdo->prepare("
    SELECT * FROM billing_history 
    WHERE LEFT(datetime, 8) = ? 
    ORDER BY id ASC
");
$stmt->execute([$todayPrefix]);
$todaysTransactions = $stmt->fetchAll();

$totalCount = count($todaysTransactions);
echo sprintf("[%s GMT] Found %d transaction(s) recorded locally today (%s GMT).\n", $gmtNow, $totalCount, $todayPrefix);

$discrepancyCount = 0;
$verifiedCount = 0;
$discrepancies = [];

foreach ($todaysTransactions as $tx) {
    $orderId = $tx['orderID'];
    $apiResult = PnpApiService::queryTransaction($orderId);

    if (!$apiResult['success']) {
        $discrepancyCount++;
        $errMsg = sprintf("Order ID %s: Local status is '%s', but API query failed or returned error: %s", $orderId, $tx['result'], $apiResult['error_message']);
        $discrepancies[] = $errMsg;
        echo "  [DISCREPANCY] " . $errMsg . "\n";
    } else {
        $verifiedCount++;
        echo sprintf("  [VERIFIED]    Order ID: %s | Status: %s | Amount: $%.2f\n", $orderId, $apiResult['status'], $tx['amount']);
    }
}

echo sprintf("[%s GMT] EOD Reconciliation Summary: Total Checked: %d | Verified: %d | Discrepancies: %d\n", get_gmt_now_formatted(), $totalCount, $verifiedCount, $discrepancyCount);

if ($discrepancyCount > 0) {
    $subject = sprintf("[%s] Alert: EOD Transaction Discrepancies Detected", APP_NAME);
    $body = "END OF DAY TRANSACTION AUDIT DISCREPANCY REPORT\n";
    $body .= "Date: " . $todayPrefix . " GMT\n";
    $body .= "----------------------------------------\n";
    $body .= implode("\n", $discrepancies) . "\n";
    $body .= "----------------------------------------\n";
    $body .= "Please review the portal manual query_trans page for details.\n";
    
    EmailService::sendMail(ALERT_EMAIL_TO, $subject, $body);
}

exit(0);
