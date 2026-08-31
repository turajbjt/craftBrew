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
 * Safe column verification and migration helper.
 */
function ensure_table_column($db, $table, $column, $definition, $afterColumn = null) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
        $exists = $stmt && $stmt->fetch();
        if (!$exists) {
            $afterSql = $afterColumn ? " AFTER `{$afterColumn}`" : "";
            try {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}{$afterSql}");
            } catch (Throwable $t) {
                // If AFTER clause failed, append to table end
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
            return "Added column: {$table}.{$column}";
        }
        return "Verified column: {$table}.{$column}";
    } catch (Throwable $e) {
        return "Warning on {$table}.{$column}: " . $e->getMessage();
    }
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

    // 1. Base table creation individually (safe from multi-query limitations)
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'brewer',
            status ENUM('active', 'suspended', 'banned') NOT NULL DEFAULT 'active',
            can_manage_docs TINYINT(1) DEFAULT 0,
            must_change_password TINYINT(1) DEFAULT 0,
            password_changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            api_token VARCHAR(64) UNIQUE NULL,
            two_factor_secret VARCHAR(64) NULL,
            two_factor_enabled TINYINT(1) DEFAULT 0,
            two_factor_backup_codes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            description TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS recipes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            category_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            style VARCHAR(100) DEFAULT '',
            batch_size_gal DECIMAL(6,2) DEFAULT 5.00,
            target_pre_og DECIMAL(4,3) DEFAULT NULL,
            target_og DECIMAL(4,3) DEFAULT NULL,
            target_fg DECIMAL(4,3) DEFAULT NULL,
            target_abv DECIMAL(4,2) DEFAULT NULL,
            ingredients TEXT,
            instructions TEXT,
            is_public TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES categories(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS recipe_ingredients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            ingredient_type ENUM('Fermentable', 'Hop', 'Yeast', 'Additive', 'Fining', 'Water', 'Other') DEFAULT 'Fermentable',
            amount DECIMAL(8,3) NOT NULL DEFAULT 0.000,
            unit VARCHAR(20) DEFAULT 'lbs',
            stage_addition VARCHAR(50) DEFAULT 'Primary',
            notes VARCHAR(255) DEFAULT '',
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS recipe_supplies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INT NOT NULL,
            item_name VARCHAR(100) NOT NULL,
            category VARCHAR(50) DEFAULT 'Equipment',
            quantity VARCHAR(50) DEFAULT '1 unit',
            is_required TINYINT(1) DEFAULT 1,
            notes VARCHAR(255) DEFAULT '',
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS recipe_steps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INT NOT NULL,
            step_number INT NOT NULL,
            step_name VARCHAR(100) NOT NULL,
            target_temp_f VARCHAR(10) DEFAULT '',
            duration_minutes INT DEFAULT 0,
            description TEXT,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            recipe_id INT DEFAULT NULL,
            category_id INT NOT NULL,
            batch_name VARCHAR(100) NOT NULL,
            batch_type VARCHAR(50) DEFAULT '',
            batch_style VARCHAR(100) DEFAULT '',
            batch_size_gal DECIMAL(6,2) DEFAULT 5.00,
            date_start DATE DEFAULT NULL,
            date_rack DATE DEFAULT NULL,
            date_rack_2 DATE DEFAULT NULL,
            date_rack_3 DATE DEFAULT NULL,
            date_bottle DATE DEFAULT NULL,
            pitch_temp_f VARCHAR(10) DEFAULT '',
            ferment_temp_f VARCHAR(10) DEFAULT '',
            gravity_pre_og DECIMAL(4,3) DEFAULT NULL,
            gravity_og DECIMAL(4,3) DEFAULT NULL,
            gravity_sg DECIMAL(4,3) DEFAULT NULL,
            gravity_tertiary DECIMAL(4,3) DEFAULT NULL,
            gravity_fg DECIMAL(4,3) DEFAULT NULL,
            calculated_abv DECIMAL(4,2) DEFAULT NULL,
            ingredients TEXT,
            boil_notes TEXT,
            reflections TEXT,
            rating INT DEFAULT 0,
            status VARCHAR(30) DEFAULT 'Primary',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
            FOREIGN KEY (category_id) REFERENCES categories(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fermentation_readings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            reading_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            gravity DECIMAL(4,3) NOT NULL,
            temp_f VARCHAR(10) DEFAULT '',
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(50) DEFAULT 'General',
            filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) DEFAULT '',
            file_type VARCHAR(50) NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            username VARCHAR(50) NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_user (ip_address, username, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS recovery_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            request_type VARCHAR(20) NOT NULL,
            identifier VARCHAR(100) NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recovery (ip_address, request_type, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS blocked_ips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            reason VARCHAR(255) DEFAULT '',
            blocked_by_admin_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            INDEX idx_blocked_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) DEFAULT '',
            target_id INT NULL,
            details TEXT,
            ip_address VARCHAR(45) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_log (admin_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($tables as $tblSql) {
        try {
            $db->exec($tblSql);
        } catch (Throwable $e) {}
    }
    $logs[] = "Base schema tables verified.";

    // 2. Incremental column migrations for existing databases
    $colMigrations = [
        ['recipes', 'target_pre_og', 'DECIMAL(4,3) DEFAULT NULL', 'batch_size_gal'],
        ['recipes', 'target_og', 'DECIMAL(4,3) DEFAULT NULL', 'target_pre_og'],
        ['recipes', 'target_fg', 'DECIMAL(4,3) DEFAULT NULL', 'target_og'],
        ['recipes', 'target_abv', 'DECIMAL(4,2) DEFAULT NULL', 'target_fg'],
        ['batches', 'date_rack_2', 'DATE NULL', 'date_rack'],
        ['batches', 'date_rack_3', 'DATE NULL', 'date_rack_2'],
        ['batches', 'gravity_pre_og', 'DECIMAL(4,3) DEFAULT NULL', 'ferment_temp_f'],
        ['batches', 'gravity_sg', 'DECIMAL(4,3) DEFAULT NULL', 'gravity_og'],
        ['batches', 'gravity_tertiary', 'DECIMAL(4,3) DEFAULT NULL', 'gravity_sg'],
        ['documents', 'original_filename', 'VARCHAR(255) DEFAULT \'\'', 'filename'],
        ['users', 'status', 'ENUM(\'active\', \'suspended\', \'banned\') NOT NULL DEFAULT \'active\'', 'role'],
        ['users', 'can_manage_docs', 'TINYINT(1) DEFAULT 0', 'status'],
        ['users', 'must_change_password', 'TINYINT(1) DEFAULT 0', 'can_manage_docs'],
        ['users', 'password_changed_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP', 'must_change_password'],
        ['users', 'two_factor_secret', 'VARCHAR(64) NULL', 'api_token'],
        ['users', 'two_factor_enabled', 'TINYINT(1) DEFAULT 0', 'two_factor_secret'],
        ['users', 'two_factor_backup_codes', 'TEXT NULL', 'two_factor_enabled']
    ];

    foreach ($colMigrations as $cm) {
        $res = ensure_table_column($db, $cm[0], $cm[1], $cm[2], $cm[3]);
        if ($res) {
            $logs[] = $res;
        }
    }

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
