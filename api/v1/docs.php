<?php
/**
 * RESTful API Interactive Documentation & OpenAPI 3.0 Explorer
 * - GET /api/v1/index.php?route=docs
 * - GET /api/v1/index.php?route=docs&format=json (OpenAPI 3.0 JSON specification)
 */

require_once __DIR__ . '/../../config.php';

$format = $_GET['format'] ?? 'html';

$apiSpec = [
    'openapi' => '3.0.3',
    'info' => [
        'title'       => APP_NAME . ' REST API',
        'version'     => APP_VERSION,
        'description' => 'Comprehensive RESTful API for CraftBrew to formulate recipes, manage fermentation logs, ingest IoT telemetry (Tilt/iSpindel/Rapt Pill), track cellar inventory, and query BJCP style guidelines.'
    ],
    'servers' => [
        ['url' => '/api/v1/index.php', 'description' => 'Production API Gateway']
    ],
    'components' => [
        'securitySchemes' => [
            'BearerAuth' => [
                'type'         => 'http',
                'scheme'       => 'bearer',
                'bearerFormat' => 'API Token'
            ]
        ]
    ],
    'endpoints' => [
        'Authentication' => [
            ['method' => 'POST', 'route' => 'auth/login', 'auth' => false, 'desc' => 'Sign in with username/email, password & optional 2FA code; returns API bearer token.'],
            ['method' => 'GET',  'route' => 'auth/profile', 'auth' => true,  'desc' => 'Retrieve authenticated profile information, permissions, and cellar metrics.'],
            ['method' => 'POST', 'route' => 'auth/token/regenerate', 'auth' => true, 'desc' => 'Invalidate current token and generate a new secure API token.'],
            ['method' => 'POST', 'route' => 'auth/logout', 'auth' => true, 'desc' => 'Revoke active API session and clear token.']
        ],
        'Batches & Brew Logs' => [
            ['method' => 'GET',    'route' => 'batches', 'auth' => true, 'desc' => 'List all user batch logs (filterable by status, category_id, search, limit, offset).'],
            ['method' => 'GET',    'route' => 'batches&id={id}', 'auth' => true, 'desc' => 'Get full batch details with chronological hydrometer drop readings and days in fermentation.'],
            ['method' => 'POST',   'route' => 'batches', 'auth' => true, 'desc' => 'Create a new brew batch log with Must Prep, pre-OG, and optional auto-inventory deduction.'],
            ['method' => 'PUT',    'route' => 'batches&id={id}', 'auth' => true, 'desc' => 'Update existing batch properties, status progression, racking dates, or ratings.'],
            ['method' => 'DELETE', 'route' => 'batches&id={id}', 'auth' => true, 'desc' => 'Delete batch log and associated fermentation drop readings.']
        ],
        'Fermentation Readings & IoT' => [
            ['method' => 'GET',    'route' => 'readings&batch_id={batch_id}', 'auth' => true, 'desc' => 'List all recorded specific gravity drop readings for a batch.'],
            ['method' => 'POST',   'route' => 'readings', 'auth' => true, 'desc' => 'Log new gravity reading; auto-calculates ABV and supports Tilt/iSpindel/Rapt Pill telemetry payloads.'],
            ['method' => 'DELETE', 'route' => 'readings&id={id}', 'auth' => true, 'desc' => 'Delete an incorrect fermentation reading entry.']
        ],
        'Recipe Library' => [
            ['method' => 'GET',    'route' => 'recipes', 'auth' => true, 'desc' => 'List user and public recipes with search and category filtering.'],
            ['method' => 'GET',    'route' => 'recipes&id={id}', 'auth' => true, 'desc' => 'Get detailed recipe specs with structured ingredients, supplies, and process steps.'],
            ['method' => 'POST',   'route' => 'recipes', 'auth' => true, 'desc' => 'Formulate and save a new recipe with structured multi-stage schedule and BJCP bounds.'],
            ['method' => 'PUT',    'route' => 'recipes&id={id}', 'auth' => true, 'desc' => 'Update recipe parameters, grain bill, hop schedule, and instructions.'],
            ['method' => 'DELETE', 'route' => 'recipes&id={id}', 'auth' => true, 'desc' => 'Delete recipe and cascade child ingredients/steps.']
        ],
        'Cellar Stock & Inventory' => [
            ['method' => 'GET',    'route' => 'inventory', 'auth' => true, 'desc' => 'List stock items categorized by Fermentable, Hop, Yeast, Additive, Supply, or Equipment.'],
            ['method' => 'GET',    'route' => 'inventory&id={id}', 'auth' => true, 'desc' => 'Retrieve single inventory stock record.'],
            ['method' => 'POST',   'route' => 'inventory', 'auth' => true, 'desc' => 'Add new ingredient or cellar supply item.'],
            ['method' => 'PUT',    'route' => 'inventory&id={id}', 'auth' => true, 'desc' => 'Update item details, set stock quantity, or apply relative adjustment (adjust_quantity).'],
            ['method' => 'DELETE', 'route' => 'inventory&id={id}', 'auth' => true, 'desc' => 'Remove inventory item from cellar.']
        ],
        'BJCP Guidelines & Starter Templates' => [
            ['method' => 'GET', 'route' => 'bjcp', 'auth' => false, 'desc' => 'List all 23 official BJCP styles with OG/FG/ABV/IBU/SRM ranges and hex color swatches.'],
            ['method' => 'GET', 'route' => 'bjcp&style={style_name}', 'auth' => false, 'desc' => 'Get single style specifications + auto-generated 1-click recipe formulation starter template.']
        ],
        'Brewing Calculators & Scaling' => [
            ['method' => 'POST', 'route' => 'calculators/abv', 'auth' => false, 'desc' => 'Calculate standard ABV, advanced Hall equation ABV, attenuation, and calories from OG & FG.'],
            ['method' => 'POST', 'route' => 'calculators/scale', 'auth' => false, 'desc' => 'Scale recipe volume (V1 to V2) and brewhouse efficiency; returns water requirements.'],
            ['method' => 'POST', 'route' => 'calculators/hydrometer-temp', 'auth' => false, 'desc' => 'Correct hydrometer gravity reading based on wort sample temperature and calibration temp.'],
            ['method' => 'POST', 'route' => 'calculators/priming-sugar', 'auth' => false, 'desc' => 'Calculate corn sugar, table sugar, DME, or honey dosage for target CO2 volumes.']
        ]
    ]
];

if ($format === 'json' || $format === 'openapi') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($apiSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Render HTML API Documentation
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - REST API v1 Reference & Explorer</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .api-badge { display: inline-block; padding: 0.25rem 0.6rem; border-radius: 4px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; }
        .api-get { background: #dbeafe; color: #1e40af; }
        .api-post { background: #dcfce7; color: #166534; }
        .api-put { background: #fef3c7; color: #92400e; }
        .api-delete { background: #fee2e2; color: #991b1b; }
        .api-route { font-family: monospace; font-size: 0.95rem; font-weight: 600; color: var(--text-main); }
        .api-card { background: var(--card-bg, #ffffff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .api-auth-pill { font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 9999px; font-weight: 600; }
        .auth-required { background: #fed7aa; color: #9a3412; }
        .auth-public { background: #e0f2fe; color: #0369a1; }
        code { background: #f1f5f9; padding: 0.2rem 0.4rem; border-radius: 4px; font-size: 0.85rem; }
    </style>
</head>
<body style="background: #f8fafc; color: #1e293b; font-family: system-ui, -apple-system, sans-serif; line-height: 1.5; padding: 2rem 1rem;">
    <div style="max-width: 1000px; margin: 0 auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="margin: 0; font-size: 2rem;">🍺 <?= APP_NAME ?> REST API</h1>
                <div style="color: #64748b; font-size: 0.95rem;">Version <?= APP_VERSION ?> &bull; JSON RESTful Architecture</div>
            </div>
            <div>
                <a href="?route=docs&format=json" class="btn btn-secondary btn-sm" target="_blank">📄 OpenAPI 3.0 JSON Spec</a>
                <a href="../../index.php" class="btn btn-outline-light btn-sm" style="margin-left: 0.5rem; background: #0284c7; color: white;">🏠 Back to App</a>
            </div>
        </div>

        <div class="api-card" style="background: #eff6ff; border-color: #bfdbfe;">
            <h3 style="margin-top: 0; color: #1e3a8a;">🔑 Authentication & Authorization</h3>
            <p style="margin-bottom: 0.5rem; font-size: 0.9rem;">
                Authenticate via <code>POST /api/v1/index.php?route=auth/login</code> to receive your personal Bearer Token. Include this token in all authorized HTTP requests using the <code>Authorization</code> header:
            </p>
            <pre style="background: #1e293b; color: #f8fafc; padding: 0.75rem; border-radius: 6px; overflow-x: auto; font-size: 0.85rem;"><code>Authorization: Bearer YOUR_API_TOKEN_HERE</code></pre>
            <p style="margin-bottom: 0; font-size: 0.85rem; color: #475569;">
                <em>Note: You can also generate and copy your API Token directly inside your CraftBrew User Profile settings.</em>
            </p>
        </div>

        <h2 style="margin-top: 2rem; border-bottom: 1px solid #cbd5e1; padding-bottom: 0.5rem;">API Endpoints Directory</h2>

        <?php foreach ($apiSpec['endpoints'] as $groupName => $routes): ?>
            <h3 style="margin-top: 1.5rem; color: #334155;"><?= htmlspecialchars($groupName) ?></h3>
            <?php foreach ($routes as $r): ?>
                <div class="api-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <div>
                            <span class="api-badge api-<?= strtolower($r['method']) ?>"><?= $r['method'] ?></span>
                            <span class="api-route">/api/v1/index.php?route=<?= htmlspecialchars($r['route']) ?></span>
                        </div>
                        <div>
                            <?php if ($r['auth']): ?>
                                <span class="api-auth-pill auth-required">🔒 Bearer Token Required</span>
                            <?php else: ?>
                                <span class="api-auth-pill auth-public">🌐 Public Endpoint</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="color: #475569; font-size: 0.9rem;"><?= htmlspecialchars($r['desc']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div style="text-align: center; margin-top: 3rem; color: #94a3b8; font-size: 0.85rem;">
            <?= APP_NAME ?> API v<?= APP_VERSION ?> &bull; Designed for Companion Mobile Apps, IoT Smart Hydrometers, and Home Automation.
        </div>
    </div>
</body>
</html>
