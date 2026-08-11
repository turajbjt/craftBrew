<?php
/**
 * Recurring Billing Process Engine (sendbill.php)
 * Runs 2x daily: 2:30 AM GMT (Run 1) and 2:30 PM GMT (Run 2 missed-payment check)
 * Usage via CLI: php cron/sendbill.php [run1|run2]
 */

if (php_sapi_name() !== 'cli') {
    die("Error: This script must be executed via PHP CLI.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/CustomerService.php';
require_once __DIR__ . '/../includes/PnpApiService.php';
require_once __DIR__ . '/../includes/EmailService.php';

$runType = strtolower($argv[1] ?? 'run1');
if (!in_array($runType, ['run1', 'run2'], true)) {
    $runType = 'run1';
}

$gmtNow = get_gmt_now_formatted();
echo sprintf("[%s GMT] Starting sendbill.php recurring billing engine execution (Mode: %s)...\n", $gmtNow, strtoupper($runType));

// Step 1: Query eligible customer profiles
$eligibleProfiles = CustomerService::getCustomersForRecurringBilling($runType);
$count = count($eligibleProfiles);

echo sprintf("[%s GMT] Found %d customer profile(s) due for recurring processing.\n", $gmtNow, $count);

if ($count === 0) {
    echo sprintf("[%s GMT] No recurring payments due. Job completed successfully.\n", $gmtNow);
    exit(0);
}

// Step 2: Submit batch upload using PnP authprev COF flags
echo sprintf("[%s GMT] Building PnP COF batch upload file for %d profiles...\n", $gmtNow, $count);
$batchResult = PnpApiService::processBatchAuthprev($eligibleProfiles);

echo sprintf("[%s GMT] Batch Upload complete. Batch ID: %s. Processing individual results...\n", $gmtNow, $batchResult['batch_id']);

// Step 3: Process results and update profile state
$successCount = 0;
$failCount = 0;

foreach ($eligibleProfiles as $profile) {
    $saasId = $profile['saas_id'];
    $attemptResult = $batchResult['results'][$saasId] ?? [
        'saas_id'  => $saasId,
        'orderID'  => 'REC-FAIL-' . $gmtNow,
        'result'   => 'hard_fail',
        'reason'   => 'Batch processing error or missing response',
        'amount'   => (float)$profile['recurringfee']
    ];

    CustomerService::recordRecurringResult($profile, $attemptResult, 'cron_sendbill_' . $runType);

    if ($attemptResult['result'] === 'success') {
        $successCount++;
        echo sprintf("  [SUCCESS] SaaS ID: %s | Customer: %s | Amount: $%.2f\n", $saasId, $profile['card_name'], $profile['recurringfee']);
    } else {
        $failCount++;
        echo sprintf("  [FAILED]  SaaS ID: %s | Customer: %s | Reason: %s\n", $saasId, $profile['card_name'], $attemptResult['reason']);
    }
}

echo sprintf("[%s GMT] Recurring execution complete. Total: %d | Success: %d | Failed: %d\n", get_gmt_now_formatted(), $count, $successCount, $failCount);
exit(0);
