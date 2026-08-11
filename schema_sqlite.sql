-- SQLite Database Creation Schema for Recurring Management System

-- Table: payment_plans
CREATE TABLE IF NOT EXISTS payment_plans (
    planid TEXT PRIMARY KEY,
    description TEXT NOT NULL,
    collect_unpw TEXT NOT NULL DEFAULT 'N',
    currency TEXT NOT NULL DEFAULT 'USD',
    initial_amount REAL NOT NULL DEFAULT 0.00,
    initial_months INTEGER NOT NULL DEFAULT 0,
    initial_days INTEGER NOT NULL DEFAULT 0,
    recurringfee REAL NOT NULL DEFAULT 0.00,
    balance REAL NULL,
    billcycle INTEGER NOT NULL DEFAULT 1,
    billcycle_type TEXT NOT NULL DEFAULT 'm',
    purchaseid TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table: customer_profiles
CREATE TABLE IF NOT EXISTS customer_profiles (
    saas_id TEXT PRIMARY KEY,
    orderid TEXT UNIQUE NOT NULL,
    username TEXT NULL,
    password TEXT NULL,
    card_name TEXT NOT NULL,
    phone TEXT NULL,
    email TEXT NOT NULL,
    accttype TEXT NOT NULL DEFAULT 'credit' CHECK(accttype IN ('checking', 'savings', 'credit')),
    card_number TEXT NOT NULL,
    card_exp TEXT NOT NULL,
    routingnum TEXT NULL,
    accountnum TEXT NULL,
    startdate TEXT NOT NULL,
    enddate TEXT NOT NULL,
    last_attempt TEXT NULL,
    last_billed TEXT NULL,
    billcycle INTEGER NOT NULL DEFAULT 0,
    billcycle_type TEXT NOT NULL DEFAULT 'm',
    currency TEXT NOT NULL DEFAULT 'USD',
    recurringfee REAL NOT NULL DEFAULT 0.00,
    balance REAL NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'pending', 'cancelled')),
    planid TEXT NULL,
    acct_code TEXT NULL,
    acct_code2 TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (planid) REFERENCES payment_plans(planid) ON DELETE SET NULL
);

-- Table: service_history
CREATE TABLE IF NOT EXISTS service_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    saas_id TEXT NOT NULL,
    datetime TEXT NOT NULL,
    action TEXT NOT NULL,
    reason TEXT NULL,
    ipaddress TEXT NULL,
    actor_username TEXT NULL,
    FOREIGN KEY (saas_id) REFERENCES customer_profiles(saas_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_service_saas ON service_history(saas_id);

-- Table: billing_history
CREATE TABLE IF NOT EXISTS billing_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    saas_id TEXT NOT NULL,
    datetime TEXT NOT NULL,
    orderID TEXT NOT NULL,
    description TEXT NULL,
    result TEXT NOT NULL,
    amount REAL NOT NULL,
    FOREIGN KEY (saas_id) REFERENCES customer_profiles(saas_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_billing_saas ON billing_history(saas_id);
CREATE INDEX IF NOT EXISTS idx_billing_order ON billing_history(orderID);

-- Table: users (Sub-logins & Admin Roles)
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    email TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'worker' CHECK(role IN ('owner', 'manager', 'auditor', 'worker')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'disabled')),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table: audit_logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    action TEXT NOT NULL,
    details TEXT NULL,
    ipaddress TEXT NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(username);

-- Seed Sample Payment Plans
INSERT OR IGNORE INTO payment_plans (planid, description, collect_unpw, currency, initial_amount, initial_months, initial_days, recurringfee, balance, billcycle, billcycle_type, purchaseid)
VALUES 
('PLAN-BASIC-M', 'Basic Monthly Plan', 'N', 'USD', 29.99, 1, 0, 29.99, NULL, 1, 'm', 'GROUP-BASIC'),
('PLAN-PRO-M', 'Pro Monthly Subscription', 'Y', 'USD', 79.99, 1, 0, 79.99, NULL, 1, 'm', 'GROUP-PRO'),
('PLAN-ANNUAL', 'Annual Premium Membership', 'Y', 'USD', 499.00, 12, 0, 499.00, NULL, 12, 'm', 'GROUP-ANNUAL'),
('PLAN-WEEKLY', 'Weekly Special Pass', 'N', 'USD', 9.99, 0, 7, 9.99, NULL, 7, 'd', 'GROUP-PASS');

-- Seed Initial Owner User (Username: admin, Password: adminPassword123!)
INSERT OR IGNORE INTO users (username, password_hash, email, role, status)
VALUES ('admin', '$2y$10$abcdefghijklmnopqrstuuV1FlKZj8phOjK35nHX/CZYJVSeP9mmW', 'owner@example.com', 'owner', 'active');

-- Table: system_settings
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Seed System Settings
INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES
('pnp_publisher_name', 'demo_publisher'),
('pnp_api_key', 'demo_api_key_12345'),
('pnp_mock_mode', 'true'),
('pnp_authprev_url', 'https://pay1.plugnpay.com/payment/pnpremote.cgi'),
('pnp_batch_upload_url', 'https://pay1.plugnpay.com/payment/batchupload.cgi'),
('pnp_query_trans_url', 'https://pay1.plugnpay.com/payment/querytrans.cgi'),
('pnp_smart_screens_url', 'https://pay1.plugnpay.com/smartscreens/v2/index.cgi'),
('alert_email_from', 'billing-alerts@example.com'),
('alert_email_to', 'merchant-admin@example.com'),
('send_email_notifications', 'true'),
('app_name', 'SaaS Recurring Billing & Management Portal'),
('app_url', 'http://localhost:8080');
