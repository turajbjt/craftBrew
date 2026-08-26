<?php
/**
 * Home & Craft Brewing System Configuration
 * Database & Application Security Settings
 */

// Application Info
define('APP_NAME', 'CraftBrew Log & Recipe Manager');
define('APP_VERSION', '2.0.0');

// MariaDB / MySQL Configuration
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'craftbrew');
define('DB_USER', getenv('DB_USER') ?: 'brewuser');
define('DB_PASS', getenv('DB_PASS') ?: 'brewpassword');
define('DB_CHARSET', 'utf8mb4');

// Secure Session Cookie Settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// Upload & Document Settings
define('DOC_UPLOAD_DIR', __DIR__ . '/assets/docs/');
define('MAX_UPLOAD_SIZE', 25 * 1024 * 1024); // 25 MB

// Error reporting settings
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide errors from public output for security
ini_set('log_errors', 1);
