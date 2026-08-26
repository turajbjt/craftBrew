<?php
/**
 * RESTful API v1 Router for Companion Android / Mobile App
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$route = $_GET['route'] ?? '';

// Basic Endpoint Switcher
switch ($route) {
    case 'auth/login':
        require_once __DIR__ . '/auth.php';
        break;
    case 'batches':
        require_once __DIR__ . '/batches.php';
        break;
    case 'recipes':
        require_once __DIR__ . '/recipes.php';
        break;
    case 'readings':
        require_once __DIR__ . '/readings.php';
        break;
    default:
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'API endpoint not found. Available routes: auth/login, batches, recipes, readings',
            'app' => APP_NAME,
            'version' => APP_VERSION
        ]);
        break;
}
