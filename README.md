# CraftBrew - Home & Craft Brewing Platform (v2.4.0)

A secure, multi-user PHP, HTML5, CSS3, and MariaDB SQL application designed for craft brewers to formulate recipes, track fermentation logs, record hydrometer readings, manage cellar inventory, analyze brewing demographics, and access reference documentation.

---

## 🌟 Key Platform Features (v2.4.0)

1. **👑 Site Owner & Administrator Portal (`/admin/`)**:
   - **User Management & Lifecycle**: Provision, edit, block, or delete users; change passwords directly; generate 1-time temporary passphrases; force password reset on next login.
   - **Security Policies & Password Rotation**: Enforce password rotation (60/90/180/365 days), password complexity rules, registration governance (Open/Invite/Closed), and brute-force lockout thresholds.
   - **🏷️ Username Security Governance**: Automatic blocklist for reserved staff titles and system commands/routes, with optional alphanumeric enforcement (letters + numbers).
   - **IP Firewall & Security Threat Alerts**: Manual and automated IP blocklist with threat alert banners for suspicious authentication activity.
   - **Demographics & Analytics Dashboard**: Chart.js telemetry covering beverage categories, ABV distributions, user growth timeline, top styles, and 1-click CSV exports.
   - **System Legacy Importer**: Relocated to admin area for secured historical log importing.

2. **🔑 Self-Service Account Recovery**:
   - **Username Recovery (`forgot_username.php`)**: Anti-enumeration zero-information design with rate-limiting.
   - **Password Reset (`forgot_password.php`)**: Dispatches secure 1-time temporary passwords without leaking account existence.
   - **Mandatory Password Reset (`change_password.php`)**: Enforces complexity rules for temporary/expired credentials.

3. **Brew Batch & Fermentation Tracker**:
   - Stages: **Planning** -> **Primary Fermentation** -> **Secondary / Racking** -> **Bottling / Aging** -> **Completed**.
   - Track Original Gravity (OG), Specific Gravity (SG), Final Gravity (FG), Pitch/Ferment Temps, and calculated ABV (\( (OG - FG) \times 131.25 \)).
   - Interactive **Chart.js** fermentation gravity drop curve over time.
   - Tasting reflections, rating scores (0–10 scale), and modification notes.

3. **Brewing Calculators**:
   - **ABV & Attenuation**: Standard and Alternate high-gravity formulas.
   - **Hydrometer Temperature Correction**: Adjust gravity readings for sample temperature vs calibration.
   - **Priming Sugar Calculator**: Calculate dextrose / sucrose needed for bottle carbonation based on target $CO_2$ volumes and beer temperature.
   - **Gravity Boost / Mash Sugar Addition**: Calculate exact sugar or extract amounts (Table Sugar, Corn Sugar, Honey, DME, LME) required to raise mash/must gravity to a target OG for any batch size.

4. **Reference Library & Document Manager**:
   - Accessible repository for uploaded/imported PDF brewing handbooks, recipe guides, and text notes.
   - Path-traversal protected file viewer and downloader.

5. **Printable PDF Exporter**:
   - Formatted PDF export generator for recipes and brew batch logs.

6. **Companion RESTful JSON API (`/api/v1/`)**:
   - Mobile-ready API endpoints (`/api/v1/auth/login`, `/api/v1/batches`, `/api/v1/recipes`, `/api/v1/readings`).
   - Secured by Bearer token authentication header (`Authorization: Bearer <api_token>`).

7. **Security Hardening**:
   - **SQL Injection Prevention**: 100% PDO prepared statements.
   - **XSS Prevention**: HTML output escaping using `e()`.
   - **CSRF Protection**: Form token generation and validation.
   - **Session Security**: `session_regenerate_id(true)` and HttpOnly/SameSite cookie policies.

---

## 🚀 Installation & Deployment

CraftBrew can be installed directly on **Bare-Metal LAMP servers** (like OpenCart or WordPress) or deployed via **Docker Compose**.

---

### Option 1: Bare-Metal LAMP Server Installation (Recommended)

#### Prerequisites:
- **Web Server**: Apache 2.4+ (or Nginx) with PHP 8.0+
- **Database**: MariaDB 10.3+ or MySQL 8.0+
- **PHP Extensions**: `pdo`, `pdo_mysql`, `mbstring` (e.g. `sudo apt install php php-mysql php-mbstring`)

#### A. Fresh Installation (Web Setup Wizard)
1. **Copy Files**: Extract or clone the project files directly into your web root or subfolder:
   ```bash
   cd /var/www/html/
   git clone https://github.com/turajbjt/brewSite.git brewsite
   cd brewsite
   ```

2. **Set Storage Permissions**:
   ```bash
   chmod -R 775 assets/docs/
   # Or run the included helper:
   chmod +x install.sh && ./install.sh
   ```

3. **Run Web Setup Wizard**:
   - Open your browser to `http://<your-server-ip>/brewsite/install.php`
   - Enter your **Database Host, Database Name, User, and Password**.
   - Set up your initial **Admin Username, Email, and Password**.
   - Click **Install CraftBrew Platform**. The wizard will automatically create the database, import tables, seed starter categories/recipes, and configure `config.php`!

---

#### B. Upgrading an Existing Installation
When upgrading from an earlier version:
1. **Copy New Files**: Extract/copy the updated files over your existing installation directory.
   *(Your `config.php`, user accounts, recipes, batches, and uploaded documents in `assets/docs/` are 100% preserved).*

2. **Run One-Click Web Upgrader**:
   - Open `http://<your-server-ip>/brewsite/install.php?mode=upgrade` in your browser.
   - Click **⚡ Apply Database Upgrades & Complete**.
   
3. **Alternative CLI Upgrade**:
   - From your server terminal, simply run:
     ```bash
     ./install.sh --upgrade
     ```

---

### Option 2: Docker Compose Deployment

1. Clone or navigate to the project directory:
   ```bash
   cd ./brewSite
   ```

2. Launch Docker services (MariaDB 10.11 + PHP 8.2 Apache):
   ```bash
   docker-compose up -d
   ```

3. Seed database and import historical logs & reference documents:
   ```bash
   docker exec craftbrew_web php /var/www/html/import_legacy_logs.php
   ```

4. Open browser:
   - **URL**: `http://localhost:8080`
   - **Default Login**: Username: `brewer` | Password: `password123`

---

## 📂 Project Directory Overview

```
./brewSite/
├── config.php                  # Application security & MariaDB PDO configuration
├── db.php                      # MariaDB PDO connection helper & ABV calculator
├── schema.sql                  # MariaDB database schema definition
├── import_legacy_logs.php      # Importer for historical logs & reference PDFs
├── index.php                   # Brewer Dashboard
├── login.php                   # User login with CSRF & session fixation guard
├── register.php                # User registration
├── logout.php                  # Session logout
├── recipes.php                 # Recipe book (Beer, Wine, Cider)
├── recipe_detail.php           # Recipe detail view with structured ingredients, equipment & steps
├── recipe_edit.php              # Dynamic recipe editor
├── batches.php                 # Brew log tracker index with stage filtering
├── batch_detail.php            # Fermentation log, Chart.js gravity curve, tasting notes
├── batch_edit.php              # Create/edit brew logs with recipe prefill
├── calculators.php             # Brewing calculators (ABV, Temp Correction, Priming Sugar)
├── documents.php               # Reference document library
├── export_pdf.php              # PDF export generator for batches & recipes
├── Dockerfile                  # PHP 8.2 Apache container definition
├── docker-compose.yml          # Docker Compose configuration (MariaDB 10.11 + PHP 8.2 Apache)
├── assets/
│   ├── css/style.css           # Responsive mobile-friendly CSS theme
│   └── js/app.js               # Dynamic calculator & Chart.js graph renderer
├── legacy_import/              # Historical PDFs, text logs, and images imported into MariaDB
├── api/
│   └── v1/                     # REST API endpoints for companion Android app
└── includes/
    ├── header.php              # Responsive navigation header
    ├── footer.php              # Page footer
    └── auth_check.php          # Session guard, CSRF helper, & API Bearer token validator
```
