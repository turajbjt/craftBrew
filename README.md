# Plug'n'Pay (PnP) SaaS Recurring Billing & Management Portal

A self-contained, enterprise-grade PHP & MySQL platform for collecting card subscriptions via **Smart Screens v2**, automated 2x-daily recurring billing processing (**`sendbill.php`**), role-based administrative portal, visual dashboard analytics, data export, manual API queries, and automated End-of-Day transaction reconciliation (**`eod_check.php`**).

---

## Table of Contents

- [Architecture & Key Features](#architecture--key-features)
- [Directory Structure](#directory-structure)
- [Database Schema & Seed Data](#database-schema--seed-data)
- [Configuration & Environment Variables](#configuration--environment-variables)
- [Server Deployment & Cron Setup](#server-deployment--cron-setup)
- [Optional Docker Setup](#optional-docker-setup)
- [Role-Based Access Control (RBAC)](#role-based-access-control-rbac)
- [API Integration Modes](#api-integration-modes)
- [CLI Cron Scripts](#cli-cron-scripts)

---

## Architecture & Key Features

1. **Smart Screens v2 Order Collection**:
   - Public order form (`index.php`) displaying offered payment plans.
   - Secure payment checkout loaded inside an **iframe**.
   - Callback handler (`callback.php`) validates response, generates unique `saas_id` (UUID v4), stores customer profile, and initializes service & billing history.

2. **Automated 2x-Daily Recurring Billing Engine (`cron/sendbill.php`)**:
   - **Run 1 (2:30 AM GMT)**: 3-day lookahead/retry window (`enddate <= today + 2 days`).
   - **Run 2 (2:30 PM GMT)**: Missed-payment sweep checking active accounts due today whose `last_attempt` date is not today.
   - Formats batch file with Credentials-on-File (**COF=R**) flags, submits to PnP batch upload API, and parses retrieval status.
   - On success: extends `enddate` by `billcycle` and `billcycle_type` ('d' or 'm') and sets `last_billed` timestamp.
   - On failure: leaves `enddate` unchanged for retry window; dispatches email alert if failed across all 3 days.

3. **End-of-Day Transaction Audit (`cron/eod_check.php`)**:
   - Post-day-end cron script querying gateway `query_trans` API to reconcile local billing history against processor records and email discrepancy alerts.

4. **Secure Administrative Web Portal (`/admin/`)**:
   - **Executive Dashboard** (`admin/dashboard.php`): Real-time MRR, active subscription tallies, success rate, and interactive Chart.js graphs (success/failure ratio and retention).
   - **Customer Profile Manager** (`admin/customers.php`): Search by SaaS ID, Order ID, name, or email. Edit dates, plans, and credentials; execute manual COF `authprev` charges; disable recurring billing (`billcycle = 0`, status='cancelled'); delete profiles; or email credentials.
   - **Reports & History** (`admin/history.php`): Service history and billing history reports per `saas_id`.
   - **Data Export Center** (`admin/export.php`): Download CSV or JSON datasets of customer records, transaction logs, and service audit trails.
   - **Manual API Query Tool** (`admin/query_trans.php`): Direct live gateway transaction status lookup by `orderID`.
   - **Sub-login Management & Audits** (`admin/users.php`): Restricted Owner interface to create sub-logins (`manager`, `auditor`, `worker`) and inspect system security audit logs.

---

## Directory Structure

```
recurringMgt/
├── README.md                   # System Documentation & Instructions
├── config.php                  # System configuration & environment constants
├── config.example.php          # Configuration template
├── db.php                      # PDO Database singleton driver
├── schema.sql                  # MySQL database schema & initial seed data
├── index.php                   # Public order form with Smart Screens v2 iframe
├── callback.php                # Smart Screens v2 callback ingestion endpoint
├── Dockerfile                  # Optional Docker container specification
├── docker-compose.yml          # Optional Docker Compose setup
├── includes/
│   ├── header.php              # Shared admin header & navigation bar
│   ├── footer.php              # Shared admin footer
│   ├── auth_check.php          # Session management & RBAC role guards
│   ├── PnpApiService.php       # PnP authprev, batch upload, query_trans driver
│   ├── CustomerService.php    # Customer profile CRUD & billing logic manager
│   └── EmailService.php       # Email notification service helper
├── cron/
│   ├── sendbill.php            # 2x daily recurring billing execution script
│   └── eod_check.php           # End-of-Day query_trans transaction audit script
└── admin/
    ├── login.php               # Admin login page with bcrypt authentication
    ├── logout.php              # Session logout handler
    ├── dashboard.php           # Visual analytics & metrics dashboard
    ├── customers.php           # Customer profile management & manual charge UI
    ├── history.php             # Service & billing history report viewer
    ├── export.php              # Data export tool (CSV / JSON)
    ├── query_trans.php         # Manual orderID gateway query_trans tool
    └── users.php              # Owner sub-login manager & audit logs
```

---

## Database Schema & Seed Data

The system uses **SQLite by default** zero-configuration storage (`data/recurring_mgt.sqlite`), which automatically initializes the database tables and seed data upon first access.

### MySQL Engine Option:
To use MySQL instead of SQLite, set `DB_ENGINE=mysql` in your environment or `config.php` and import `schema.sql`:

```bash
mysql -u root -p < schema.sql
```

### Initial Seed Data Provided:
- **Default Owner Account**:
  - **Username**: `admin`
  - **Password**: `adminPassword123!`
  - **Role**: `owner`

> **Note on Existing Databases**: If you previously imported the database before this fix, execute `php reset_admin.php` via CLI (or open `http://localhost:8080/reset_admin.php` in your browser) to update the admin hash in your database. Alternatively, run:
> ```sql
> UPDATE users SET password_hash = '$2y$10$abcdefghijklmnopqrstuuV1FlKZj8phOjK35nHX/CZYJVSeP9mmW' WHERE username = 'admin';
> ```
- **Sample Offered Payment Plans**:
  - `PLAN-BASIC-M`: Basic Monthly Plan ($29.99/mo)
  - `PLAN-PRO-M`: Pro Monthly Subscription ($79.99/mo)
  - `PLAN-ANNUAL`: Annual Premium Membership ($499.00/yr)
  - `PLAN-WEEKLY`: Weekly Pass ($9.99/wk)

---

## Configuration & Environment Variables

Edit `config.php` (or pass environment variables to your PHP runtime):

| Constant / Env Variable | Default | Description |
| :--- | :--- | :--- |
| `DB_HOST` | `127.0.0.1` | MySQL Database Host |
| `DB_PORT` | `3306` | MySQL Database Port |
| `DB_NAME` | `recurring_mgt` | Database Name |
| `DB_USER` | `root` | Database Username |
| `DB_PASS` | `rootpassword` | Database Password |
| `PNP_PUBLISHER_NAME` | `demo_publisher` | Plug'n'Pay Publisher Name |
| `PNP_API_KEY` | `demo_api_key_12345` | Plug'n'Pay Remote API Key |
| `PNP_MOCK_MODE` | `true` | Sandbox mode toggle for offline testing |
| `ALERT_EMAIL_FROM` | `billing-alerts@example.com` | Notification sender email address |
| `ALERT_EMAIL_TO` | `merchant-admin@example.com` | Notification recipient email address |
| `APP_URL` | `http://localhost:8080` | Public Web Root URL |

---

## Server Deployment & Cron Setup

### 1. Web Server Deployment
Upload all files to your web server DocumentRoot (e.g. `/var/www/html/`). Ensure PHP 8.0+ is installed with `pdo_mysql`, `curl`, and `json` extensions enabled.

### 2. Configure System Crontab
Add the following entries to `/etc/crontab` or `crontab -e`:

```cron
# 1st Recurring Billing Run (2:30 AM GMT)
30 2 * * * php /var/www/html/cron/sendbill.php run1 >> /var/log/sendbill.log 2>&1

# 2nd Recurring Billing Run - Missed Payments Sweep (2:30 PM GMT)
30 14 * * * php /var/www/html/cron/sendbill.php run2 >> /var/log/sendbill.log 2>&1

# End-of-Day Transaction Reconciliation Audit (11:00 PM GMT)
0 23 * * * php /var/www/html/cron/eod_check.php >> /var/log/eod_check.log 2>&1
```

---

## Optional Docker Setup

To run locally using Docker Compose:

```bash
# Build and launch containers
docker-compose up -d --build

# View application in browser
http://localhost:8080
```

---

## Role-Based Access Control (RBAC)

The portal provides 4 administrative access levels:

1. **Owner**:
   - Access to all features.
   - Exclusive ability to create sub-logins and view security audit logs (`admin/users.php`).
2. **Manager**:
   - Full operational access: search, edit profiles, manual `authprev` card-on-file charges, disable recurring billing, delete profiles, reports, and data exports.
3. **Auditor**:
   - Read-only audit access: view customer profiles, view history reports, execute `query_trans` manual queries, and download CSV/JSON data exports.
   - Cannot edit customer records, process manual charges, or alter settings.
4. **Worker**:
   - Operational access to view and manage customer profiles and process card-on-file payments.

---

## API Integration Modes

1. **Smart Screens v2**: Hosted checkout payment initialization and webhook callback parsing.
2. **Authprev Mode (Card-on-File)**: Single charges for manual portal billing and batch upload file submission with `COF=R` flags.
3. **Batch Retrieve**: Polling/parsing engine for recurring billing responses.
4. **Query Trans Mode**: Real-time transaction verification by `orderID`.

---

## CLI Cron Scripts

You can manually execute the background scripts from the terminal for testing:

```bash
# Run 1st daily recurring billing execution
php cron/sendbill.php run1

# Run 2nd daily sweep for missed payments
php cron/sendbill.php run2

# Run End-of-Day transaction audit
php cron/eod_check.php
```
