<?php
/**
 * System Configuration Example Template
 */

date_default_timezone_set('UTC');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'recurring_mgt');
define('DB_USER', 'recurring_user');
define('DB_PASS', 'secretpassword');

define('PNP_PUBLISHER_NAME', 'your_publisher_name');
define('PNP_API_KEY', 'your_api_key');
define('PNP_AUTHPREV_URL', 'https://pay1.plugnpay.com/payment/pnpremote.cgi');
define('PNP_BATCH_UPLOAD_URL', 'https://pay1.plugnpay.com/payment/batchupload.cgi');
define('PNP_QUERY_TRANS_URL', 'https://pay1.plugnpay.com/payment/querytrans.cgi');
define('PNP_SMART_SCREENS_URL', 'https://pay1.plugnpay.com/smartscreens/v2/index.cgi');
define('PNP_MOCK_MODE', false);

define('ALERT_EMAIL_FROM', 'billing-alerts@example.com');
define('ALERT_EMAIL_TO', 'merchant-admin@example.com');
define('SEND_EMAIL_NOTIFICATIONS', true);

define('APP_NAME', 'SaaS Recurring Billing & Management Portal');
define('APP_URL', 'http://localhost:8080');
