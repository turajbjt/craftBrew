<?php
/**
 * System Configuration File
 */

// Timezone setup - system operates on GMT/UTC as specified
date_default_timezone_set('UTC');

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'recurring_mgt');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: 'rootpassword');

// Plug'n'Pay (PnP) API Settings
define('PNP_PUBLISHER_NAME', getenv('PNP_PUBLISHER_NAME') ?: 'demo_publisher');
define('PNP_API_KEY', getenv('PNP_API_KEY') ?: 'demo_api_key_12345');
define('PNP_AUTHPREV_URL', getenv('PNP_AUTHPREV_URL') ?: 'https://pay1.plugnpay.com/payment/pnpremote.cgi');
define('PNP_BATCH_UPLOAD_URL', getenv('PNP_BATCH_UPLOAD_URL') ?: 'https://pay1.plugnpay.com/payment/batchupload.cgi');
define('PNP_QUERY_TRANS_URL', getenv('PNP_QUERY_TRANS_URL') ?: 'https://pay1.plugnpay.com/payment/querytrans.cgi');
define('PNP_SMART_SCREENS_URL', getenv('PNP_SMART_SCREENS_URL') ?: 'https://pay1.plugnpay.com/smartscreens/v2/index.cgi');
define('PNP_MOCK_MODE', getenv('PNP_MOCK_MODE') !== false ? filter_var(getenv('PNP_MOCK_MODE'), FILTER_VALIDATE_BOOLEAN) : true);

// Notification Settings
define('ALERT_EMAIL_FROM', getenv('ALERT_EMAIL_FROM') ?: 'billing-alerts@example.com');
define('ALERT_EMAIL_TO', getenv('ALERT_EMAIL_TO') ?: 'merchant-admin@example.com');
define('SEND_EMAIL_NOTIFICATIONS', true);

// Application Settings
define('APP_NAME', 'SaaS Recurring Billing & Management Portal');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost:8080');

// Date & Time Utility Functions
function get_gmt_now_formatted() {
    return date('YmdHis'); // YYYYMMDDhhmmss GMT
}

function get_gmt_today_date() {
    return date('Ymd'); // YYYYMMDD GMT
}

function mask_card_number($cardNumber) {
    $clean = preg_replace('/\D/', '', $cardNumber);
    if (strlen($clean) < 4) return 'XXXX-XXXX-XXXX-XXXX';
    $last4 = substr($clean, -4);
    return 'XXXX-XXXX-XXXX-' . $last4;
}

function mask_account_number($accNum) {
    if (empty($accNum)) return '';
    $clean = preg_replace('/\D/', '', $accNum);
    if (strlen($clean) < 4) return 'XXXX-XXXX';
    return 'XXXX-' . substr($clean, -4);
}
