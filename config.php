<?php
/**
 * Home & Craft Brewing System Configuration
 * Database & Application Security Settings
 */

// Application Info
define('APP_NAME', 'CraftBrew Log & Recipe Manager');
define('APP_VERSION', '2.5.0');

// MariaDB / MySQL Configuration
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'craftbrew');
define('DB_USER', getenv('DB_USER') ?: 'brewuser');
define('DB_PASS', getenv('DB_PASS') ?: 'brewpassword');
define('DB_CHARSET', 'utf8mb4');

// Detect HTTPS
$isHttps = (
    (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (isset($_SERVER['SERVER_PORT']) && in_array((int)$_SERVER['SERVER_PORT'], [443, 8443]))
);
define('IS_HTTPS', $isHttps);

// Session Inactivity Timeout (60 Minutes)
define('SESSION_TIMEOUT_SECONDS', 3600);

// Secure Session Cookie Settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    if (IS_HTTPS) {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// Upload & Document Settings
define('DOC_UPLOAD_DIR', __DIR__ . '/assets/docs/');
define('MAX_UPLOAD_SIZE', 25 * 1024 * 1024); // 25 MB

// Error reporting settings (Strict suppression in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide all errors from public output
ini_set('log_errors', 1);
