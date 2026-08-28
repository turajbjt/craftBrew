-- MariaDB / MySQL Schema for Home & Craft Brewing System
-- Supports Multi-user Auth, RBAC, User Management, Security Governance, Recipes, Ingredients, Batches, and Documents

CREATE TABLE IF NOT EXISTS users (
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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default categories
INSERT INTO categories (name, description) VALUES
('Beer', 'Malt and hop based fermented beverages'),
('Wine', 'Grape and fruit based wines'),
('Cider', 'Apple and fruit cider brews'),
('Mead', 'Honey based fermented drinks'),
('Fruit Wine', 'Specialty fruit & berry wines')
ON DUPLICATE KEY UPDATE description=VALUES(description);

CREATE TABLE IF NOT EXISTS recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    style VARCHAR(100) DEFAULT '',
    batch_size_gal DECIMAL(6,2) DEFAULT 5.00,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_ingredients (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_supplies (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_steps (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batches (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fermentation_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    reading_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    gravity DECIMAL(4,3) NOT NULL,
    temp_f VARCHAR(10) DEFAULT '',
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory & Stock Management Table
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'Fermentable',
    quantity DECIMAL(8,2) DEFAULT 0.00,
    unit VARCHAR(20) DEFAULT '',
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-Force Rate Limiting Table
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_user (ip_address, username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Self-Service Account Recovery Attempts
CREATE TABLE IF NOT EXISTS recovery_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    request_type VARCHAR(20) NOT NULL, -- 'username' or 'password'
    identifier VARCHAR(100) NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recovery (ip_address, request_type, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global IP Blocklist Table
CREATE TABLE IF NOT EXISTS blocked_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL UNIQUE,
    reason VARCHAR(255) DEFAULT '',
    blocked_by_admin_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    INDEX idx_blocked_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Site Settings Table
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (setting_key, setting_value) VALUES
('password_rotation_days', '0'),
('password_min_length', '8'),
('password_require_complex', '0'),
('username_require_alphanumeric', '0'),
('registration_mode', 'open'),
('max_login_attempts', '5'),
('lockout_minutes', '15'),
('max_recovery_attempts', '3'),
('recovery_lockout_minutes', '15')
ON DUPLICATE KEY UPDATE setting_key=setting_key;
