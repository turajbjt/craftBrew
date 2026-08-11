<?php
/**
 * System Configuration File
 */

// Timezone setup - system operates on GMT/UTC as specified
date_default_timezone_set('UTC');

// Database Configuration (Supported engines: 'sqlite', 'mysql')
define('DB_ENGINE', getenv('DB_ENGINE') ?: 'sqlite');
define('DB_SQLITE_PATH', getenv('DB_SQLITE_PATH') ?: __DIR__ . '/data/recurring_mgt.sqlite');

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'recurring_mgt');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: 'rootpassword');

// Initialize dynamic settings service
require_once __DIR__ . '/includes/SettingsService.php';
SettingsService::initConstants();

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
