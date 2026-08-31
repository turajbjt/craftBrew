<?php
/**
 * RESTful API v1 Master Gateway & Router
 * Supports Companion Mobile Apps, IoT Smart Hydrometers (Tilt, iSpindel, Rapt Pill), and Automation.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/auth_check.php';

// Extract requested route
$route = trim($_GET['route'] ?? '');

// Parse subroute (e.g. 'auth/login' -> mainRoute = 'auth', subRoute = 'login')
$parts = explode('/', $route);
$mainRoute = strtolower($parts[0] ?? '');
$subRoute = implode('/', array_slice($parts, 1));

// Route Dispatcher
switch ($mainRoute) {
    case 'auth':
        require_once __DIR__ . '/auth.php';
        break;

    case 'batches':
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/batches.php';
        break;

    case 'recipes':
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/recipes.php';
        break;

    case 'readings':
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/readings.php';
        break;

    case 'inventory':
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/inventory.php';
        break;

    case 'bjcp':
        require_once __DIR__ . '/bjcp.php';
        break;

    case 'calculators':
        require_once __DIR__ . '/calculators.php';
        break;

    case 'docs':
    case 'openapi':
    case 'swagger':
        require_once __DIR__ . '/docs.php';
        break;

    case '':
        // Default API Root Info
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'      => 'online',
            'app'         => APP_NAME,
            'version'     => APP_VERSION,
            'api_version' => 'v1',
            'endpoints'   => [
                'auth'        => '/api/v1/index.php?route=auth/login',
                'batches'     => '/api/v1/index.php?route=batches',
                'recipes'     => '/api/v1/index.php?route=recipes',
                'readings'    => '/api/v1/index.php?route=readings',
                'inventory'   => '/api/v1/index.php?route=inventory',
                'bjcp'        => '/api/v1/index.php?route=bjcp',
                'calculators' => '/api/v1/index.php?route=calculators/abv',
                'docs'        => '/api/v1/index.php?route=docs'
            ],
            'documentation_url' => '/api/v1/index.php?route=docs'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        break;

    default:
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode([
            'status'    => 'error',
            'error'     => 'route_not_found',
            'message'   => "API endpoint '{$route}' not found.",
            'available_routes' => ['auth', 'batches', 'recipes', 'readings', 'inventory', 'bjcp', 'calculators', 'docs'],
            'docs_url'  => '/api/v1/index.php?route=docs'
        ]);
        break;
}
