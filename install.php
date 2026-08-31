<?php
/**
 * CraftBrew Setup & Upgrade Wizard
 * Supports Fresh Bare-Metal LAMP Installation and Seamless Version Upgrades
 */

// Error reporting: Suppressed in production, enabled only during initial bare-metal installation
error_reporting(E_ALL);
$lockFile = __DIR__ . '/installed.lock';
$isInstalled = file_exists($lockFile);
ini_set('display_errors', $isInstalled ? 0 : 1);

define('INSTALL_VERSION', '2.7.0');
$configFile = __DIR__ . '/config.php';
$schemaFile = __DIR__ . '/schema.sql';
$docsDir = __DIR__ . '/assets/docs/';

// If application is already installed, require active Administrator session for upgrades
if ($isInstalled) {
    if (file_exists($configFile)) {
        require_once $configFile;
    }
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/includes/auth_check.php';
    require_login();
    require_admin();
}

// 1. Check Server Prerequisites
$reqs = [
    'php_version' => [
        'name'   => 'PHP Version >= 8.0',
        'status' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'detail' => 'Current: ' . PHP_VERSION
    ],
    'pdo' => [
        'name'   => 'PDO Extension',
        'status' => extension_loaded('pdo'),
        'detail' => extension_loaded('pdo') ? 'Installed' : 'Missing'
    ],
    'pdo_mysql' => [
        'name'   => 'PDO MySQL / MariaDB Driver',
        'status' => extension_loaded('pdo_mysql'),
        'detail' => extension_loaded('pdo_mysql') ? 'Installed' : 'Missing (apt install php-mysql)'
    ],
    'mbstring' => [
        'name'   => 'Multibyte String (mbstring)',
        'status' => extension_loaded('mbstring'),
        'detail' => extension_loaded('mbstring') ? 'Installed' : 'Missing (apt install php-mbstring)'
    ],
    'docs_writable' => [
        'name'   => 'Document Storage Writable (assets/docs/)',
        'status' => is_writable(is_dir($docsDir) ? $docsDir : __DIR__ . '/assets/'),
        'detail' => is_writable(is_dir($docsDir) ? $docsDir : __DIR__ . '/assets/') ? 'Writable' : 'Not Writable (chmod 777 assets/docs/)'
    ],
    'config_writable' => [
        'name'   => 'Configuration Writable (config.php)',
        'status' => (file_exists($configFile) && is_writable($configFile)) || is_writable(__DIR__),
        'detail' => (file_exists($configFile) && is_writable($configFile)) || is_writable(__DIR__) ? 'Writable' : 'Not Writable'
    ]
];

$allPassed = true;
foreach ($reqs as $r) {
    if (!$r['status']) {
        $allPassed = false;
        break;
    }
}

// 2. Check if already configured and connected
$isConfigured = false;
$existingDbPdo = null;
$existingDbError = null;

if (file_exists($configFile)) {
    try {
        require_once $configFile;
        if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
            $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", DB_HOST, DB_PORT, DB_NAME);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ];
            $existingDbPdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $isConfigured = true;
        }
    } catch (Exception $e) {
        $existingDbError = $e->getMessage();
    }
}

$isInstalled = file_exists($lockFile);

$mode = (isset($_GET['mode']) && in_array($_GET['mode'], ['install', 'upgrade'])) 
    ? $_GET['mode'] 
    : ($isConfigured ? 'upgrade' : 'install');

// If locked down and user attempts install mode, force upgrade mode
if ($isInstalled && $mode === 'install') {
    $mode = 'upgrade';
}

$message = '';
$error = '';
$migrationLogs = [];
$setupComplete = false;

// 3. Process Fresh Install Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fresh_install') {
    if ($isInstalled) {
        $error = "Security Lockdown: System is already installed. If you wish to re-install, please remove installed.lock from your server.";
    } else {
        $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
        $dbPort = trim($_POST['db_port'] ?? '3306');
        $dbName = trim($_POST['db_name'] ?? 'craftbrew');
        $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = $_POST['db_pass'] ?? '';

    $adminUser  = trim($_POST['admin_user'] ?? 'admin');
    $adminEmail = trim($_POST['admin_email'] ?? 'admin@example.com');
    $adminPass  = $_POST['admin_pass'] ?? '';

    if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
        $error = "Please fill in all required database settings.";
    } elseif (empty($adminUser) || empty($adminEmail) || strlen($adminPass) < 6) {
        $error = "Admin username, email, and a password (minimum 6 characters) are required.";
    } else {
        try {
            // Step A: Connect to MySQL Server
            $serverDsn = sprintf("mysql:host=%s;port=%s;charset=utf8mb4", $dbHost, $dbPort);
            $pdo = new PDO($serverDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Step B: Create Database if not existing
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // Step C: Connect to created database
            $dbDsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", $dbHost, $dbPort, $dbName);
            $dbPdo = new PDO($dbDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            // Step D: Execute schema.sql
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $dbPdo->exec($sql);
            }

            // Step E: Create Admin User
            $passHash = password_hash($adminPass, PASSWORD_DEFAULT);
            $apiToken = bin2hex(random_bytes(32));
            $userStmt = $dbPdo->prepare("INSERT INTO users (username, email, password_hash, role, api_token) VALUES (?, ?, ?, 'admin', ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)");
            $userStmt->execute([$adminUser, $adminEmail, $passHash, $apiToken]);
            $adminId = $dbPdo->lastInsertId() ?: 1;

            // Step F: Seed Starter Recipe if requested
            if (!empty($_POST['seed_starter'])) {
                $catStmt = $dbPdo->query("SELECT id FROM categories WHERE name = 'Cider' LIMIT 1");
                $ciderCat = $catStmt->fetch();
                $catId = $ciderCat ? $ciderCat['id'] : 1;

                $recStmt = $dbPdo->prepare("INSERT INTO recipes (user_id, category_id, name, style, batch_size_gal, target_og, target_fg, target_abv, ingredients, instructions, is_public) VALUES (?, ?, 'Classic Hard Apple Cider', 'Hard Cider', 5.0, 1.064, 1.005, 9.00, ?, ?, 1)");
                $recStmt->execute([
                    $adminId,
                    $catId,
                    "- 5 gal Fresh Apple Juice\n- 4 lbs Table Sugar\n- 1 pkt Nottingham Ale Yeast",
                    "1. Boil sugar in filtered water for 15 minutes.\n2. Mix into apple juice in fermenter.\n3. Cool to 70F and pitch yeast.\n4. Ferment 2-3 months until clear."
                ]);
                $recipeId = $dbPdo->lastInsertId();

                // Recipe Ingredients & Steps
                $ingStmt = $dbPdo->prepare("INSERT INTO recipe_ingredients (recipe_id, name, ingredient_type, amount, unit, stage_addition, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ingStmt->execute([$recipeId, 'Fresh Orchard Apple Juice', 'Fermentable', 5.0, 'Gal', 'Primary', 'Unfiltered']);
                $ingStmt->execute([$recipeId, 'Table Sugar (Sucrose)', 'Fermentable', 4.0, 'lbs', 'Primary', 'Boiled 15 min']);
                $ingStmt->execute([$recipeId, 'Nottingham Ale Yeast', 'Yeast', 1.0, 'pkt', 'Primary', 'Pitch at 70°F']);
            }

            // Step G: Write config.php
            $configContent = "<?php\n"
                . "/**\n * Home & Craft Brewing System Configuration\n * Auto-generated by install.php on " . date('Y-m-d H:i:s') . "\n */\n\n"
                . "// Application Info\n"
                . "define('APP_NAME', 'CraftBrew Log & Recipe Manager');\n"
                . "define('APP_VERSION', '" . INSTALL_VERSION . "');\n\n"
                . "// MariaDB / MySQL Configuration\n"
                . "define('DB_HOST', getenv('DB_HOST') ?: " . var_export($dbHost, true) . ");\n"
                . "define('DB_PORT', getenv('DB_PORT') ?: " . var_export($dbPort, true) . ");\n"
                . "define('DB_NAME', getenv('DB_NAME') ?: " . var_export($dbName, true) . ");\n"
                . "define('DB_USER', getenv('DB_USER') ?: " . var_export($dbUser, true) . ");\n"
                . "define('DB_PASS', getenv('DB_PASS') ?: " . var_export($dbPass, true) . ");\n"
                . "define('DB_CHARSET', 'utf8mb4');\n\n"
                . "// Secure Session Cookie Settings\n"
                . "if (session_status() === PHP_SESSION_NONE) {\n"
                . "    ini_set('session.cookie_httponly', 1);\n"
                . "    ini_set('session.use_only_cookies', 1);\n"
                . "    ini_set('session.cookie_samesite', 'Lax');\n"
                . "    if (isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on') {\n"
                . "        ini_set('session.cookie_secure', 1);\n"
                . "    }\n"
                . "    session_start();\n"
                . "}\n\n"
                . "// Upload & Document Settings\n"
                . "define('DOC_UPLOAD_DIR', __DIR__ . '/assets/docs/');\n"
                . "define('MAX_UPLOAD_SIZE', 25 * 1024 * 1024); // 25 MB\n\n"
                . "// Error reporting settings\n"
                . "error_reporting(E_ALL);\n"
                . "ini_set('display_errors', 0);\n"
                . "ini_set('log_errors', 1);\n";

            file_put_contents($configFile, $configContent);

            // Step H: Create assets/docs/ if missing
            if (!is_dir($docsDir)) {
                @mkdir($docsDir, 0777, true);
                @chmod($docsDir, 0777);
            }

            // Step I: Create installed.lock
            file_put_contents($lockFile, "Installed on " . date('Y-m-d H:i:s') . " - Version " . INSTALL_VERSION . "\n");

            $setupComplete = true;
            $message = "CraftBrew has been successfully installed and configured!";
        } catch (Exception $e) {
            $error = "Installation Error: " . $e->getMessage();
        }
    }
}
}

// 4. Process Upgrade Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_upgrade') {
    if (!$isConfigured || !$existingDbPdo) {
        $error = "Cannot upgrade: Database connection is not available in config.php.";
    } else {
        try {
            require_once __DIR__ . '/db.php';
            $migrationLogs = run_migrations($existingDbPdo);
            file_put_contents($lockFile, "Upgraded to " . INSTALL_VERSION . " on " . date('Y-m-d H:i:s') . "\n");
            $setupComplete = true;
            $message = "System upgraded successfully to Version " . INSTALL_VERSION . "!";
        } catch (Exception $e) {
            $error = "Upgrade Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CraftBrew Setup & Upgrade Wizard</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #166534;
            --success-bg: #dcfce7;
            --danger: #991b1b;
            --danger-bg: #fee2e2;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text-main);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 2rem 1rem;
        }
        .installer-card {
            background: var(--card-bg);
            width: 100%;
            max-width: 680px;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            border: 1px solid var(--border);
            padding: 2.5rem;
        }
        .header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .header h1 {
            font-size: 1.8rem;
            color: var(--text-main);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .header p { color: var(--text-muted); font-size: 0.95rem; }
        .mode-nav {
            display: flex;
            background: var(--bg);
            padding: 0.35rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
            gap: 0.35rem;
        }
        .mode-nav a {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            text-decoration: none;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.9rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        .mode-nav a.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        .alert-success { background: var(--success-bg); color: var(--success); border: 1px solid #bbf7d0; }
        .alert-danger { background: var(--danger-bg); color: var(--danger); border: 1px solid #fecdd3; }
        .alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 1.5rem 0 0.75rem 0;
            padding-bottom: 0.35rem;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
        }
        .req-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .req-table td {
            padding: 0.6rem 0.5rem;
            border-bottom: 1px solid var(--border);
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.75rem;
        }
        .badge-ok { background: var(--success-bg); color: var(--success); }
        .badge-err { background: var(--danger-bg); color: var(--danger); }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-row {
            display: flex;
            gap: 1rem;
        }
        .form-row .form-group { flex: 1; }
        label {
            display: block;
            margin-bottom: 0.35rem;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-main);
        }
        input[type="text"], input[type="password"], input[type="email"], input[type="number"] {
            width: 100%;
            padding: 0.65rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.95rem;
            background: #ffffff;
            color: var(--text-main);
        }
        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            text-align: center;
            transition: background 0.2s ease;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .log-box {
            background: #0f172a;
            color: #10b981;
            font-family: monospace;
            padding: 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            max-height: 200px;
            overflow-y: auto;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>

<div class="installer-card">
    <div class="header">
        <h1>🍺 CraftBrew Setup & Upgrade</h1>
        <p>Version <?= INSTALL_VERSION ?> &bull; Bare-Metal LAMP Server Deployment</p>
    </div>

    <?php if ($setupComplete): ?>
        <div class="alert alert-success">
            <strong>🎉 <?= htmlspecialchars($message) ?></strong>
        </div>

        <?php if (!empty($migrationLogs)): ?>
            <div class="section-title">Upgrade Summary</div>
            <div class="log-box">
                <?php foreach ($migrationLogs as $log): ?>
                    <div>&gt; <?= htmlspecialchars($log) ?></div>
                <?php endforeach; ?>
                <div>&gt; System database is up to date.</div>
            </div>
        <?php endif; ?>

        <a href="login.php" class="btn">🚀 Proceed to Login &amp; Dashboard</a>

    <?php else: ?>

        <div class="mode-nav">
            <a href="install.php?mode=install" class="<?= $mode === 'install' ? 'active' : '' ?>">📦 Fresh Installation</a>
            <a href="install.php?mode=upgrade" class="<?= $mode === 'upgrade' ? 'active' : '' ?>">⚡ Upgrade Existing Install</a>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Environment Health Checks -->
        <div class="section-title">Server Requirements Check</div>
        <table class="req-table">
            <?php foreach ($reqs as $r): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                    <td style="color: var(--text-muted); font-size: 0.8rem;"><?= htmlspecialchars($r['detail']) ?></td>
                    <td style="text-align: right;">
                        <span class="badge <?= $r['status'] ? 'badge-ok' : 'badge-err' ?>">
                            <?= $r['status'] ? '✓ Passed' : '✗ Failed' ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php if (!$allPassed): ?>
            <div class="alert alert-danger">
                Please resolve the failed server requirements above before proceeding.
            </div>
        <?php endif; ?>

        <?php if ($mode === 'upgrade'): ?>
            <!-- UPGRADE MODE -->
            <div class="section-title">Database Upgrade &amp; Migration</div>
            <?php if ($isConfigured): ?>
                <div class="alert alert-info">
                    Detected existing configuration in <code>config.php</code>.<br>
                    Connected to Database: <strong><?= htmlspecialchars(DB_NAME) ?></strong> on <strong><?= htmlspecialchars(DB_HOST) ?></strong>.
                </div>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
                    Clicking the button below will safely apply all new tables (inventory, structured ingredients, document references) and racking dates without touching your existing recipes, batches, or user data.
                </p>
                <form method="POST" action="install.php?mode=upgrade">
                    <input type="hidden" name="action" value="run_upgrade">
                    <button type="submit" class="btn" <?= !$allPassed ? 'disabled' : '' ?>>⚡ Apply Database Upgrades &amp; Complete</button>
                </form>
            <?php else: ?>
                <div class="alert alert-danger">
                    No active database connection found in <code>config.php</code>.<br>
                    <?= htmlspecialchars($existingDbError ?: 'Please switch to Fresh Installation tab to configure database credentials.') ?>
                </div>
                <a href="install.php?mode=install" class="btn">Go to Fresh Installation</a>
            <?php endif; ?>

        <?php else: ?>
            <!-- FRESH INSTALL MODE -->
            <form method="POST" action="install.php?mode=install">
                <input type="hidden" name="action" value="fresh_install">

                <div class="section-title">1. MariaDB / MySQL Database Credentials</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Database Host</label>
                        <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? '127.0.0.1') ?>" required>
                    </div>
                    <div class="form-group" style="flex: 0 0 100px;">
                        <label>Port</label>
                        <input type="number" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Database Name (will be created if missing)</label>
                    <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'craftbrew') ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Database Username</label>
                        <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Database Password</label>
                        <input type="password" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
                    </div>
                </div>

                <div class="section-title">2. Initial Administrator Account</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Admin Username</label>
                        <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Admin Email</label>
                        <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? 'admin@example.com') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Admin Password (minimum 6 characters)</label>
                    <input type="password" name="admin_pass" placeholder="••••••••" required>
                </div>

                <div class="form-group" style="margin-top: 1rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: normal;">
                        <input type="checkbox" name="seed_starter" value="1" checked>
                        <span>Seed default categories and sample hard cider recipe</span>
                    </label>
                </div>

                <button type="submit" class="btn" style="margin-top: 1rem;" <?= !$allPassed ? 'disabled' : '' ?>>Install CraftBrew Platform</button>
            </form>
        <?php endif; ?>

    <?php endif; ?>
</div>

</body>
</html>
