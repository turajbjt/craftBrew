# Plug'n Pay SaaS Recurring Billing & Management Portal

A self-contained, enterprise-grade PHP & SQLite/MySQL platform for collecting card subscriptions via **Smart Screens v2**, automated 2x-daily recurring billing processing (**`sendbill.php`**), role-based administrative portal, dynamic system settings management, visual dashboard analytics, data export, manual API queries, customer gateway resynchronization, and automated End-of-Day transaction reconciliation (**`eod_check.php`**).

---

## Table of Contents

- [Architecture & Key Features](#architecture--key-features)
- [Directory Structure](#directory-structure)
- [Database Schema & Default SQLite Setup](#database-schema--default-sqlite-setup)
- [System Settings & Configuration](#system-settings--configuration)
- [Remote API & Smart Screens v2 Integration Modes](#remote-api--smart-screens-v2-integration-modes)
- [Server Deployment & Cron Setup](#server-deployment--cron-setup)
- [Optional Docker Setup](#optional-docker-setup)
- [Role-Based Access Control (RBAC)](#role-based-access-control-rbac)
- [CLI Cron Scripts](#cli-cron-scripts)

---

## Architecture & Key Features

1. **Smart Screens v2 Order Collection**:
   - Public order form (`index.php`) displaying offered payment plans.
   - Secure payment checkout loaded inside an **iframe** pointing to `https://pay1.plugnpay.com/pay/`.
   - Constructs query string using official Plug'n Pay field names (`pt_gateway_account`, `pt_order_classifier`, `pr_plan_id`, `pr_recurring_amount`, `pt_transaction_amount`, `pt_item_cost_1`, `pt_item_description_1`, `pt_item_identifier_1`, `pt_customer_username`, `pb_customer_password`, `pb_customer_password_confirmation`, `pt_account_code_1`, etc.).
   - Callback handler (`callback.php`) validates response, generates unique `saas_id` (UUID v4), stores customer profile, and initializes service & billing history.

2. **Automated 2x-Daily Recurring Billing Engine (`cron/sendbill.php`)**:
   - **Run 1 (2:30 AM GMT)**: 3-day lookahead/retry window (`enddate <= today + 2 days`).
   - **Run 2 (2:30 PM GMT)**: Missed-payment sweep checking active accounts due today whose `last_attempt` date is not today.
   - Formats batch file with Credentials-on-File (**COF=R**) flags, submits to Plug'n Pay remote API, and parses retrieval status.
   - On success: extends `enddate` by `billcycle` and `billcycle_type` ('d' or 'm') and sets `last_billed` timestamp.
   - On failure: leaves `enddate` unchanged for retry window; dispatches email alert if failed across all 3 days.

3. **End-of-Day Transaction Audit (`cron/eod_check.php`)**:
   - Post-day-end cron script querying gateway `query_trans` API to reconcile local billing history against processor records and email discrepancy alerts.

4. **Secure Administrative Web Portal (`/admin/`)**:
   - **Executive Dashboard** (`admin/dashboard.php`): Real-time MRR, active subscription tallies, success rate, and interactive Chart.js graphs (success/failure ratio and retention).
   - **Customer Profile Manager** (`admin/customers.php`): Search by SaaS ID, Order ID, name, email, or username. Includes **Resync Gateway Profiles** action button to update all customer profiles directly from Plug'n Pay via `list_members` API. Edit dates, plans, and credentials; execute manual COF `bill_member` charges; disable recurring billing (`billcycle = 0`, status='cancelled', issuing `cancel_member` call); delete profiles; or email credentials.
   - **System Settings Manager** (`admin/settings.php`): Dynamic UI allowing `owner` and `manager` roles to edit Plug'n Pay gateway credentials, API URLs, mock mode toggle, alert emails, and application parameters without editing source files.
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
├── db.php                      # PDO Database singleton driver (SQLite default / MySQL option)
├── schema_sqlite.sql           # SQLite database schema & initial seed data
├── schema.sql                  # MySQL database schema & initial seed data
├── index.php                   # Public order form with Smart Screens v2 iframe
├── callback.php                # Smart Screens v2 callback ingestion endpoint
├── Dockerfile                  # Optional Docker container specification
├── docker-compose.yml          # Optional Docker Compose setup
├── data/
│   └── .gitkeep                # Directory container for recurring_mgt.sqlite
├── includes/
│   ├── header.php              # Shared admin header & navigation bar
│   ├── footer.php              # Shared admin footer
│   ├── auth_check.php          # Session management & RBAC role guards
│   ├── SettingsService.php     # System settings key-value driver
│   ├── PnpApiService.php       # Plug'n Pay Remote API driver
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
    ├── settings.php            # Dynamic system settings & gateway config portal
    ├── history.php             # Service & billing history report viewer
    ├── export.php              # Data export tool (CSV / JSON)
    ├── query_trans.php         # Manual orderID gateway query_trans tool
    └── users.php              # Owner sub-login manager & audit logs
```

---

## Database Schema & Default SQLite Setup

The system uses **SQLite by default** with zero-configuration storage located at `data/recurring_mgt.sqlite`. The database singleton (`db.php`) automatically creates the `data/` directory with `0777` permissions and initializes the database tables & seed data from `schema_sqlite.sql` upon first access.

### MySQL Engine Option:
To use MySQL instead of SQLite, set `DB_ENGINE=mysql` in `config.php` and import `schema.sql`:

```bash
mysql -u root -p recurring_mgt < schema.sql
```

### Initial Seed Data:
- **Default Owner Account**:
  - **Username**: `admin`
  - **Password**: `adminPassword123!`
  - **Role**: `owner`

- **Sample Subscription Plans**:
  - `PLAN-BASIC-M`: Basic Monthly Plan ($29.99/mo)
  - `PLAN-PRO-M`: Pro Monthly Subscription ($79.99/mo)
  - `PLAN-ANNUAL`: Annual Premium Membership ($499.00/yr)
  - `PLAN-WEEKLY`: Weekly Pass ($9.99/wk)

---

## System Settings & Configuration

Settings can be managed directly via the Admin Portal at **Admin Settings** (`/admin/settings.php`) or configured via environment variables / `config.php`:

| Setting Key | Default Value | Description |
| :--- | :--- | :--- |
| `pnp_publisher_name` | `demo_publisher` | Plug'n Pay Gateway Account Username |
| `pnp_api_key` | `demo_api_key_12345` | Plug'n Pay Remote Client Password / API Key |
| `pnp_authprev_url` | `https://pay1.plugnpay.com/payment/pnpremote.cgi` | Plug'n Pay Remote API Endpoint |
| `pnp_smart_screens_url` | `https://pay1.plugnpay.com/pay/` | Plug'n Pay Smart Screens v2 Checkout Endpoint |
| `pnp_mock_mode` | `true` | Sandbox mode toggle for offline testing |
| `alert_email_from` | `billing-alerts@example.com` | Alert sender email address |
| `alert_email_to` | `merchant-admin@example.com` | Alert recipient email address |
| `app_url` | `http://localhost:8080` | Public Web Root URL |

---

## Remote API & Smart Screens v2 Integration Modes

All remote API calls target `https://pay1.plugnpay.com/payment/pnpremote.cgi` and Smart Screens checkout targets `https://pay1.plugnpay.com/pay/`.

### 1. Smart Screens v2 (`https://pay1.plugnpay.com/pay/`)
Initializes iframe hosted checkout using exact specification fields: `pt_gateway_account`, `pt_order_classifier`, `pr_plan_id`, `pr_recurring_amount`, `pt_transaction_amount`, `pt_item_cost_1`, `pt_item_description_1`, `pt_item_identifier_1`, `pt_item_is_taxable_1`, `pt_item_quantity_1`, `pd_collect_credentials`, `pd_display_items`, `pb_cards_allowed`, `pt_customer_username`, `pb_customer_password`, `pb_customer_password_confirmation`, `pt_account_code_1`.

### 2. Card-on-File Recurring Charge (`mode=bill_member`)
Executes recurring charges against stored profile:
- Parameters: `publisher-name`, `publisher-password`, `mode=bill_member`, `username`, `card-amount`, `currency=USD`, `transflags=cit,recurring`.

### 3. Transaction Status Query (`mode=query_trans`)
Queries live transaction details by Order ID:
- Parameters: `publisher-name`, `publisher-password`, `mode=query_trans`, `orderID`, `startdate`, `enddate`.

### 4. List Members (`mode=list_members`)
Resynchronizes local database with gateway member records:
- Parameters: `publisher-name`, `publisher-password`, `mode=list_members`, `status=all`, `crypt=omit`, `alldata=yes`.

### 5. Query Member Info (`mode=query_member`)
Looks up customer profile details by username:
- Parameters: `publisher-name`, `publisher-password`, `mode=query_member`, `username`.

### 6. Cancel Member (`mode=cancel_member`)
Cancels member profile at Plug'n Pay gateway:
- Parameters: `publisher-name`, `publisher-password`, `mode=cancel_member`, `username`.

### 7. Query Member Billing History (`mode=query_billing`)
Queries historical billing records for a username with automatic double `urldecode` parsing:
- Parameters: `publisher-name`, `publisher-password`, `mode=query_billing`, `username`, `startdate`, `enddate`.

---

## Server Deployment & Cron Setup

### 1. Web Server Deployment
Upload all files to your web server DocumentRoot (e.g. `/var/www/html/`). Ensure PHP 8.0+ is installed with `pdo_sqlite`, `pdo_mysql`, `curl`, and `json` extensions enabled. Ensure `data/` directory is writable (`chmod 0777 data`).

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

1. **Owner**: Full access, sub-login management (`admin/users.php`), audit logs, system settings (`admin/settings.php`).
2. **Manager**: Full operational access: search, edit profiles, process manual `bill_member` charges, gateway resync, disable recurring billing, delete profiles, reports, settings, data exports.
3. **Auditor**: Read-only audit access: view customer profiles, view history reports, execute `query_trans` manual queries, download CSV/JSON exports.
4. **Worker**: Operational access to view and manage customer profiles and process card-on-file payments.

---

## CLI Cron Scripts

Execute background scripts manually from the terminal for testing:

```bash
# Run 1st daily recurring billing execution
php cron/sendbill.php run1

# Run 2nd daily missed payment sweep
php cron/sendbill.php run2

# Run End-of-Day transaction audit check
php cron/eod_check.php
```
