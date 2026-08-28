<?php
/**
 * Database Helper Module using PHP PDO (MariaDB / MySQL)
 * Includes Helpers for Structured Ingredients, Supplies, and Brewing Steps
 */

require_once __DIR__ . '/config.php';

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            try {
                $rootDsn = sprintf("mysql:host=%s;port=%s;charset=%s", DB_HOST, DB_PORT, DB_CHARSET);
                $rootPdo = new PDO($rootDsn, DB_USER, DB_PASS, $options);
                $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (Exception $e2) {
                // If not installed and in web context, redirect to installer
                if (!file_exists(__DIR__ . '/installed.lock') && php_sapi_name() !== 'cli') {
                    if (basename($_SERVER['PHP_SELF'] ?? '') !== 'install.php') {
                        header('Location: install.php');
                        exit;
                    }
                }
                die("Database Connection Error: " . $e->getMessage() . "<br><br>If this is a new installation, please run <a href='install.php'>install.php</a> to configure the system.");
            }
        }
    }
    return $pdo;
}

/**
 * Execute schema setup and migrations
 */
function init_schema() {
    return run_migrations();
}

/**
 * Run safe non-destructive migrations for new tables, columns, and categories.
 * Returns an array of log messages describing applied changes.
 */
function run_migrations($db = null) {
    if ($db === null) {
        $db = get_db();
    }
    $logs = [];

    // 1. Run schema.sql base CREATE TABLE IF NOT EXISTS
    $schemaFile = __DIR__ . '/schema.sql';
    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        $db->exec($sql);
        $logs[] = "Base schema tables verified.";
    }

    // 2. Incremental column migrations on batches table
    try {
        $db->exec("ALTER TABLE batches ADD COLUMN date_rack_2 DATE NULL AFTER date_rack");
        $logs[] = "Verified column: batches.date_rack_2";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE batches ADD COLUMN date_rack_3 DATE NULL AFTER date_rack_2");
        $logs[] = "Verified column: batches.date_rack_3";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE batches ADD COLUMN gravity_sg DECIMAL(4,3) DEFAULT NULL AFTER gravity_og");
        $logs[] = "Verified column: batches.gravity_sg";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE batches ADD COLUMN gravity_tertiary DECIMAL(4,3) DEFAULT NULL AFTER gravity_sg");
        $logs[] = "Verified column: batches.gravity_tertiary";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE documents ADD COLUMN original_filename VARCHAR(255) DEFAULT '' AFTER filename");
        $logs[] = "Verified column: documents.original_filename";
    } catch (Exception $e) {}

    // Users table RBAC and security columns
    try {
        $db->exec("ALTER TABLE users ADD COLUMN status ENUM('active', 'suspended', 'banned') NOT NULL DEFAULT 'active' AFTER role");
        $logs[] = "Verified column: users.status";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE users ADD COLUMN can_manage_docs TINYINT(1) DEFAULT 0 AFTER status");
        $logs[] = "Verified column: users.can_manage_docs";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) DEFAULT 0 AFTER can_manage_docs");
        $logs[] = "Verified column: users.must_change_password";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE users ADD COLUMN password_changed_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER must_change_password");
        $logs[] = "Verified column: users.password_changed_at";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(64) NULL AFTER api_token");
        $logs[] = "Verified column: users.two_factor_secret";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) DEFAULT 0 AFTER two_factor_secret");
        $logs[] = "Verified column: users.two_factor_enabled";
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE users ADD COLUMN two_factor_backup_codes TEXT NULL AFTER two_factor_enabled");
        $logs[] = "Verified column: users.two_factor_backup_codes";
    } catch (Exception $e) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            username VARCHAR(50) NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_user (ip_address, username, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $logs[] = "Verified table: login_attempts";
    } catch (Exception $e) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS recovery_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            request_type VARCHAR(20) NOT NULL,
            identifier VARCHAR(100) NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recovery (ip_address, request_type, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $logs[] = "Verified table: recovery_attempts";
    } catch (Exception $e) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS blocked_ips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            reason VARCHAR(255) DEFAULT '',
            blocked_by_admin_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            INDEX idx_blocked_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $logs[] = "Verified table: blocked_ips";
    } catch (Exception $e) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) DEFAULT '',
            target_id INT NULL,
            details TEXT,
            ip_address VARCHAR(45) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_log (admin_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $logs[] = "Verified table: admin_audit_logs";
    } catch (Exception $e) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $defaultSettings = [
            'password_rotation_days'        => '0',
            'password_min_length'           => '8',
            'password_require_complex'      => '0',
            'username_require_alphanumeric' => '0',
            'registration_mode'             => 'open',
            'max_login_attempts'            => '5',
            'lockout_minutes'               => '15',
            'max_recovery_attempts'         => '3',
            'recovery_lockout_minutes'      => '15',
            'smtp_enabled'                  => '0',
            'smtp_host'                     => '',
            'smtp_port'                     => '587',
            'smtp_encryption'               => 'tls',
            'smtp_user'                     => '',
            'smtp_pass'                     => '',
            'smtp_from_email'               => '',
            'smtp_from_name'                => 'CraftBrew Platform',
            'max_doc_upload_mb'             => '25',
            'enforce_admin_2fa'             => '0'
        ];
        $setStmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_key=setting_key");
        foreach ($defaultSettings as $k => $v) {
            $setStmt->execute([$k, $v]);
        }
        $logs[] = "Verified table & defaults: site_settings";
    } catch (Exception $e) {}

    // 3. Ensure standard categories exist
    $defaultCategories = [
        'Beer'       => 'Malt and hop based fermented beverages',
        'Wine'       => 'Grape and fruit based wines',
        'Cider'      => 'Apple and fruit cider brews',
        'Mead'       => 'Honey based fermented drinks',
        'Fruit Wine' => 'Specialty fruit & berry wines'
    ];
    $catStmt = $db->prepare("INSERT INTO categories (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description=VALUES(description)");
    foreach ($defaultCategories as $name => $desc) {
        $catStmt->execute([$name, $desc]);
    }
    $logs[] = "Default categories verified.";

    // 4. Ensure upload directory exists with write permissions & script lockdown
    if (!is_dir(DOC_UPLOAD_DIR)) {
        @mkdir(DOC_UPLOAD_DIR, 0777, true);
        @chmod(DOC_UPLOAD_DIR, 0777);
        $logs[] = "Document storage directory created at assets/docs/";
    }
    $storageHtaccess = rtrim(DOC_UPLOAD_DIR, '/\\') . '/.htaccess';
    if (!file_exists($storageHtaccess)) {
        $secContent = "# Disable script execution in storage\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\nSetHandler default-handler\n<FilesMatch \"\.(php|phtml|php3|php4|php5|php7|php8|phps|pl|py|cgi|sh|bash|exe|asp|aspx|jsp|shtml)$\">\nOrder Allow,Deny\nDeny from all\n</FilesMatch>\nOptions -Indexes -ExecCGI\n";
        @file_put_contents($storageHtaccess, $secContent);
        $logs[] = "Storage security lock (.htaccess) placed in assets/docs/";
    }

    return $logs;
}

/**
 * Calculate ABV from Original Gravity and Final Gravity
 * Supports standard formula: (OG - FG) * 131.25
 * Supports alternate high-gravity formula: (76.08 * (OG - FG) / (1.775 - OG)) * (FG / 0.794)
 */
function calculate_abv($og, $fg, $formula = 'standard') {
    if (!$og || !$fg || $og <= 1.0 || $fg <= 0 || $og <= $fg) return 0.0;
    if ($formula === 'alternate') {
        if ($og >= 1.775) return round(($og - $fg) * 131.25, 2);
        $abv = (76.08 * ($og - $fg) / (1.775 - $og)) * ($fg / 0.794);
        return round($abv, 2);
    }
    $abv = ($og - $fg) * 131.25;
    return round($abv, 2);
}

/**
 * Fetch structured recipe details (ingredients, supplies, steps)
 */
function get_recipe_details($recipeId) {
    $db = get_db();
    
    // Ingredients
    $stIng = $db->prepare("SELECT * FROM recipe_ingredients WHERE recipe_id = ? ORDER BY id ASC");
    $stIng->execute([$recipeId]);
    $ingredients = $stIng->fetchAll();

    // Supplies & Equipment
    $stSup = $db->prepare("SELECT * FROM recipe_supplies WHERE recipe_id = ? ORDER BY id ASC");
    $stSup->execute([$recipeId]);
    $supplies = $stSup->fetchAll();

    // Steps / Schedule
    $stStp = $db->prepare("SELECT * FROM recipe_steps WHERE recipe_id = ? ORDER BY step_number ASC, id ASC");
    $stStp->execute([$recipeId]);
    $steps = $stStp->fetchAll();

    return [
        'ingredients' => $ingredients,
        'supplies'    => $supplies,
        'steps'       => $steps
    ];
}

/**
 * Save structured recipe details
 */
function save_recipe_details($recipeId, $ingredients = [], $supplies = [], $steps = []) {
    $db = get_db();

    // Delete existing items for clean replacement
    $db->prepare("DELETE FROM recipe_ingredients WHERE recipe_id = ?")->execute([$recipeId]);
    $db->prepare("DELETE FROM recipe_supplies WHERE recipe_id = ?")->execute([$recipeId]);
    $db->prepare("DELETE FROM recipe_steps WHERE recipe_id = ?")->execute([$recipeId]);

    // Save Ingredients
    if (!empty($ingredients)) {
        $insIng = $db->prepare("INSERT INTO recipe_ingredients (recipe_id, name, ingredient_type, amount, unit, stage_addition, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($ingredients as $ing) {
            $name = trim($ing['name'] ?? '');
            if (!empty($name)) {
                $insIng->execute([
                    $recipeId,
                    $name,
                    $ing['ingredient_type'] ?? 'Fermentable',
                    (float)($ing['amount'] ?? 0),
                    trim($ing['unit'] ?? ''),
                    trim($ing['stage_addition'] ?? 'Primary'),
                    trim($ing['notes'] ?? '')
                ]);
            }
        }
    }

    // Save Supplies
    if (!empty($supplies)) {
        $insSup = $db->prepare("INSERT INTO recipe_supplies (recipe_id, item_name, category, quantity, is_required, notes) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($supplies as $sup) {
            $itemName = trim($sup['item_name'] ?? '');
            if (!empty($itemName)) {
                $insSup->execute([
                    $recipeId,
                    $itemName,
                    $sup['category'] ?? 'Equipment',
                    trim($sup['quantity'] ?? '1 unit'),
                    !empty($sup['is_required']) ? 1 : 0,
                    trim($sup['notes'] ?? '')
                ]);
            }
        }
    }

    // Save Steps
    if (!empty($steps)) {
        $insStp = $db->prepare("INSERT INTO recipe_steps (recipe_id, step_number, phase, title, duration, target_temp, instructions) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $num = 1;
        foreach ($steps as $stp) {
            $title = trim($stp['title'] ?? '');
            if (!empty($title)) {
                $insStp->execute([
                    $recipeId,
                    $num++,
                    $stp['phase'] ?? 'Brew Day',
                    $title,
                    trim($stp['duration'] ?? ''),
                    trim($stp['target_temp'] ?? ''),
                    trim($stp['instructions'] ?? '')
                ]);
            }
        }
    }
}

/**
 * Inventory Helpers
 */
function get_inventory($userId) {
    $db = get_db();
    $st = $db->prepare("SELECT * FROM inventory WHERE user_id = ? ORDER BY category ASC, item_name ASC");
    $st->execute([$userId]);
    return $st->fetchAll();
}

function save_inventory_item($userId, $data) {
    $db = get_db();
    $id = (int)($data['id'] ?? 0);
    $name = trim($data['item_name'] ?? '');
    $cat  = trim($data['category'] ?? 'Fermentable');
    $qty  = (float)($data['quantity'] ?? 0);
    $unit = trim($data['unit'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if (empty($name)) return false;

    if ($id > 0) {
        $st = $db->prepare("UPDATE inventory SET item_name=?, category=?, quantity=?, unit=?, notes=? WHERE id=? AND user_id=?");
        return $st->execute([$name, $cat, $qty, $unit, $notes, $id, $userId]);
    } else {
        $st = $db->prepare("INSERT INTO inventory (user_id, item_name, category, quantity, unit, notes) VALUES (?, ?, ?, ?, ?, ?)");
        return $st->execute([$userId, $name, $cat, $qty, $unit, $notes]);
    }
}

function delete_inventory_item($userId, $itemId) {
    $db = get_db();
    $st = $db->prepare("DELETE FROM inventory WHERE id = ? AND user_id = ?");
    return $st->execute([$itemId, $userId]);
}

function deduct_inventory_for_batch($userId, $recipeId) {
    if (!$recipeId) return 0;
    $details = get_recipe_details($recipeId);
    $ingredients = $details['ingredients'] ?? [];
    if (empty($ingredients)) return 0;

    $db = get_db();
    $deductedCount = 0;

    foreach ($ingredients as $ing) {
        $name = trim($ing['name']);
        $amount = (float)$ing['amount'];
        if ($amount <= 0 || empty($name)) continue;

        $st = $db->prepare("SELECT id, quantity FROM inventory WHERE user_id = ? AND LOWER(item_name) = LOWER(?) LIMIT 1");
        $st->execute([$userId, $name]);
        $inv = $st->fetch();

        if ($inv) {
            $newQty = max(0, (float)$inv['quantity'] - $amount);
            $up = $db->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
            $up->execute([$newQty, $inv['id']]);
            $deductedCount++;
        }
    }
    return $deductedCount;
}
