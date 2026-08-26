-- MariaDB / MySQL Schema for Home & Craft Brewing System
-- Supports Multi-user Auth, Recipes, Structured Ingredients, Supplies, Steps, Batches, Readings, and Documents

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'brewer',
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

-- Structured Recipe Ingredients Table
CREATE TABLE IF NOT EXISTS recipe_ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    ingredient_type VARCHAR(50) NOT NULL DEFAULT 'Fermentable',
    amount DECIMAL(8,2) DEFAULT 0.00,
    unit VARCHAR(20) DEFAULT '',
    stage_addition VARCHAR(50) DEFAULT 'Primary',
    notes TEXT,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Structured Recipe Supplies & Equipment Table
CREATE TABLE IF NOT EXISTS recipe_supplies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) DEFAULT 'Equipment',
    quantity VARCHAR(50) DEFAULT '1 unit',
    is_required TINYINT(1) DEFAULT 1,
    notes TEXT,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Structured Recipe Process Steps Table
CREATE TABLE IF NOT EXISTS recipe_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT NOT NULL,
    step_number INT NOT NULL,
    phase VARCHAR(50) NOT NULL DEFAULT 'Brew Day',
    title VARCHAR(150) NOT NULL,
    duration VARCHAR(50) DEFAULT '',
    target_temp VARCHAR(30) DEFAULT '',
    instructions TEXT,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    recipe_id INT NULL,
    category_id INT NOT NULL,
    batch_code VARCHAR(50) DEFAULT '',
    batch_name VARCHAR(100) NOT NULL,
    batch_type VARCHAR(50) DEFAULT '',
    batch_style VARCHAR(100) DEFAULT '',
    batch_size_gal DECIMAL(6,2) DEFAULT 5.00,
    date_start DATE NULL,
    date_rack DATE NULL,
    date_rack_2 DATE NULL,
    date_rack_3 DATE NULL,
    date_bottle DATE NULL,
    pitch_temp_f VARCHAR(10) DEFAULT '',
    ferment_temp_f VARCHAR(10) DEFAULT '',
    gravity_og DECIMAL(4,3) DEFAULT NULL,
    gravity_sg DECIMAL(4,3) DEFAULT NULL,
    gravity_tertiary DECIMAL(4,3) DEFAULT NULL,
    gravity_fg DECIMAL(4,3) DEFAULT NULL,
    calculated_abv DECIMAL(4,2) DEFAULT NULL,
    ingredients TEXT,
    boil_notes TEXT,
    tasting_notes TEXT,
    reflections TEXT,
    rating TINYINT UNSIGNED DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'Primary',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
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
