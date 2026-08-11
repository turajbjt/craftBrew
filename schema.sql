-- Database Creation Schema for Recurring Management System
CREATE DATABASE IF NOT EXISTS recurring_mgt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE recurring_mgt;

-- Table: payment_plans
CREATE TABLE IF NOT EXISTS payment_plans (
    planid VARCHAR(50) PRIMARY KEY,
    description TEXT NOT NULL,
    collect_unpw CHAR(1) NOT NULL DEFAULT 'N',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    initial_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    initial_months INT NOT NULL DEFAULT 0,
    initial_days INT NOT NULL DEFAULT 0,
    recurringfee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(10,2) NULL,
    billcycle INT NOT NULL DEFAULT 1,
    billcycle_type CHAR(1) NOT NULL DEFAULT 'm',
    purchaseid VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table: customer_profiles
CREATE TABLE IF NOT EXISTS customer_profiles (
    saas_id VARCHAR(36) PRIMARY KEY,
    orderid VARCHAR(64) UNIQUE NOT NULL,
    username VARCHAR(100) NULL,
    password VARCHAR(255) NULL,
    card_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NOT NULL,
    accttype ENUM('checking', 'savings', 'credit') NOT NULL DEFAULT 'credit',
    card_number VARCHAR(30) NOT NULL, -- Masked e.g. XXXX-XXXX-XXXX-1234
    card_exp VARCHAR(10) NOT NULL,    -- MMYY
    routingnum VARCHAR(30) NULL,      -- Masked
    accountnum VARCHAR(30) NULL,      -- Masked
    startdate CHAR(8) NOT NULL,       -- YYYYMMDD
    enddate CHAR(8) NOT NULL,         -- YYYYMMDD
    last_attempt CHAR(14) NULL,       -- YYYYMMDDhhmmss GMT
    last_billed CHAR(14) NULL,        -- YYYYMMDDhhmmss GMT
    billcycle INT NOT NULL DEFAULT 0,
    billcycle_type CHAR(1) NOT NULL DEFAULT 'm', -- 'd' = days, 'm' = months
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    recurringfee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(10,2) NULL,
    status ENUM('active', 'pending', 'cancelled') NOT NULL DEFAULT 'active',
    planid VARCHAR(50) NULL,
    acct_code VARCHAR(100) NULL,
    acct_code2 VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (planid) REFERENCES payment_plans(planid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table: service_history
CREATE TABLE IF NOT EXISTS service_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    saas_id VARCHAR(36) NOT NULL,
    datetime CHAR(14) NOT NULL,       -- YYYYMMDDhhmmss GMT
    action VARCHAR(100) NOT NULL,
    reason TEXT NULL,
    ipaddress VARCHAR(45) NULL,
    actor_username VARCHAR(100) NULL,
    INDEX idx_service_saas (saas_id),
    FOREIGN KEY (saas_id) REFERENCES customer_profiles(saas_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table: billing_history
CREATE TABLE IF NOT EXISTS billing_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    saas_id VARCHAR(36) NOT NULL,
    datetime CHAR(14) NOT NULL,       -- YYYYMMDDhhmmss GMT
    orderID VARCHAR(64) NOT NULL,
    description TEXT NULL,
    result VARCHAR(50) NOT NULL,       -- success, hard_fail, soft_fail, pending
    amount DECIMAL(10,2) NOT NULL,
    INDEX idx_billing_saas (saas_id),
    INDEX idx_billing_order (orderID),
    FOREIGN KEY (saas_id) REFERENCES customer_profiles(saas_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table: users (Sub-logins & Admin Roles)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(150) NOT NULL,
    role ENUM('owner', 'manager', 'auditor', 'worker') NOT NULL DEFAULT 'worker',
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table: audit_logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ipaddress VARCHAR(45) NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Sample Payment Plans
INSERT INTO payment_plans (planid, description, collect_unpw, currency, initial_amount, initial_months, initial_days, recurringfee, balance, billcycle, billcycle_type, purchaseid)
VALUES 
('PLAN-BASIC-M', 'Basic Monthly Plan', 'N', 'USD', 29.99, 1, 0, 29.99, NULL, 1, 'm', 'GROUP-BASIC'),
('PLAN-PRO-M', 'Pro Monthly Subscription', 'Y', 'USD', 79.99, 1, 0, 79.99, NULL, 1, 'm', 'GROUP-PRO'),
('PLAN-ANNUAL', 'Annual Premium Membership', 'Y', 'USD', 499.00, 12, 0, 499.00, NULL, 12, 'm', 'GROUP-ANNUAL'),
('PLAN-WEEKLY', 'Weekly Special Pass', 'N', 'USD', 9.99, 0, 7, 9.99, NULL, 7, 'd', 'GROUP-PASS')
ON DUPLICATE KEY UPDATE description=VALUES(description);

-- Seed Initial Owner User (Username: admin, Password: adminPassword123!)
INSERT INTO users (username, password_hash, email, role, status)
VALUES ('admin', '$2y$10$abcdefghijklmnopqrstuuV1FlKZj8phOjK35nHX/CZYJVSeP9mmW', 'owner@example.com', 'owner', 'active')
ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), email=VALUES(email);
