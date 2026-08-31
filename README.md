# CraftBrew - Home & Craft Brewing Platform (v2.8.0)

A secure, multi-user PHP, HTML5, CSS3, and MariaDB SQL application designed for craft brewers and winemakers to formulate recipes, track multi-stage fermentation logs, analyze hydrometer readings against official BJCP style targets, scale batch sizes, print custom bottle/keg labels, manage cellar inventory, and access reference documentation.

---

## 🌟 Key Platform Features (v2.8.0)

1. **🎯 BJCP Style Guidelines & Visual Target Gauges (v2.8.0)**:
   - **Official 2021 Guidelines Database**: Comprehensive dataset covering IPAs, Stouts, Porters, Pilsners, Saisons, Belgian Ales, Wheats, Ciders, and Meads.
   - **Real-Time Visual Target Gauges**: Dynamic, color-coded min/max comparison bars for **OG**, **FG**, **ABV**, **IBU**, and **SRM Color** with live status badges (`✓ In Style`, `▼ Low`, `▲ High`).
   - **1-Click Starter Formulation**: Clicking "Formulate Recipe for this Style" automatically creates a baseline recipe pre-populated with guideline midpoint gravities, grain bills, hop charges, and fermentation step schedules.
   - **SRM Beer Color Palette**: Realistic hexadecimal color rendering computed directly from SRM values (1–40).

2. **⚖️ Recipe Auto-Scaling Tool (`scale_recipe.php`) (v2.8.0)**:
   - **Volume Scaling**: Seamlessly scale recipes up or down between 1-gallon test batches and 15.5-gallon half barrels.
   - **System Efficiency Adjustments**: Compensates fermentable malt quantities when moving between systems with different Brewhouse Efficiencies (e.g. 70% $\to$ 82%).
   - **Dynamic Water Volume Calculator**: Automatically estimates strike water, grain absorption losses, and sparge requirements.
   - **1-Click Clone to Library**: Save scaled formulations directly into your recipe book with proportional ingredients and step schedules preserved.

3. **🏷️ Printable Bottle & Keg Label Generator (`labels.php`) (v2.8.0)**:
   - **Multi-Format Print Presets**:
     - **12oz Standard Bottle Labels** (6-up sheet layout with crop/cut lines).
     - **22oz Bomber / Large Bottle Labels** (4-up sheet layout).
     - **Cornelius Keg Collars & Handle Tags** (3-up circular hang-tag format with hole-punch markers).
   - **Accurate SRM Color Accent Bands**: Displays true-to-style color swatches.
   - **Dynamic Zero-Dependency Offline QR Codes**: Scannable QR codes linking directly to batch tasting reflections or recipe formulations without third-party tracking.
   - **Dedicated Print Styles**: Formatted `@media print` CSS for standard US Letter and A4 sticker paper or cardstock.

4. **🍇 Pre-Fermentation "Must Prep" Stage (v2.8.0)**:
   - Dedicated **Must Prep / Sulfiting** stage between **Planning** and **Primary Fermentation** for fruit wine, mead, and cider makers to record maceration, pectin enzyme additions, and 24–48hr sulfiting wait times before yeast pitching.
   - Integrated into visual fermentation milestone calendars, dashboard metrics, and batch filters.

5. **👑 Administrator Portal (`/admin/`)**:
   - **User Management & Lifecycle**: Provision, edit, block, or delete users; change passwords directly; generate 1-time temporary passphrases; force password reset on next login.
   - **Security Policies & Password Rotation**: Enforce password rotation (60/90/180/365 days), password complexity rules, registration governance (Open/Invite/Closed), and brute-force lockout thresholds.
   - **🔐 Two-Factor Authentication (2FA) Governance**: Admin policy to enforce 2FA for all administrator accounts.
   - **🏷️ Username Security Governance**: Automatic blocklist for reserved staff titles and system commands/routes, with optional alphanumeric enforcement.
   - **✉️ Authenticated SMTP Mailer**: Native socket SMTP transport with STARTTLS, SSL, and AUTH LOGIN support plus live socket diagnostic testing.
   - **💾 1-Click Database SQL Backup Tool (`admin/backup.php`)**: Download complete database schema & data snapshots instantly.
   - **👑 Administrator Action Audit Trail**: Comprehensive logging tracking all admin actions, password resets, and policy modifications.
   - **IP Firewall & Security Threat Alerts**: Manual and automated IP blocklist with threat alert banners for suspicious authentication activity.
   - **Demographics & Analytics Dashboard**: Chart.js telemetry covering beverage categories, ABV distributions, user growth timeline, top styles, and 1-click CSV exports.

6. **🔐 Two-Factor Authentication (2FA / TOTP) & Bot Defense**:
   - **RFC 6238 TOTP**: Standard authenticator app integration (Google Authenticator, Microsoft Authenticator, 1Password, Authy, Bitwarden).
   - **Emergency Backup Recovery Codes**: 8 single-use codes generated upon enrollment to prevent lockout.
   - **🤖 Zero-Dependency Bot Defense**: Invisible Honeypot and sub-second Time-Trap active across all public forms (`login.php`, `register.php`, `forgot_username.php`, `forgot_password.php`).

7. **👤 User Profile & Account Management (`profile.php`)**:
   - Update registered email with password confirmation.
   - 2FA enrollment, QR code scanner, backup code regeneration, and 2FA disabling.
   - View, copy, or regenerate personal companion Android app REST API tokens.
   - Direct password changes and user statistics.

8. **📥 BeerXML, JSON & Full Cellar Archive Portability (`data_manager.php`)**:
   - 1-click export of recipes in standard **BeerXML (.xml)** and **CraftBrew JSON (.json)** format.
   - Universal recipe importer supporting BeerXML (hops, fermentables, yeasts) and JSON formulations.
   - Full Cellar ZIP backup and restore with document and image preservation.

9. **🔑 Self-Service Account Recovery**:
   - **Username Recovery (`forgot_username.php`)**: Anti-enumeration zero-information design with rate-limiting.
   - **Password Reset (`forgot_password.php`)**: Dispatches secure 1-time temporary passwords without leaking account existence.
   - **Mandatory Password Reset (`change_password.php`)**: Enforces complexity rules for temporary/expired credentials.

10. **🍺 Brew Batch & Fermentation Tracker**:
    - Stages: **Planning** $\to$ **Must Prep** $\to$ **Primary Fermentation** $\to$ **Secondary / Racking** $\to$ **Bottling / Aging** $\to$ **Completed**.
    - Track Original Gravity (OG), Specific Gravity (SG), Final Gravity (FG), Pitch/Ferment Temps, and calculated ABV (\( (OG - FG) \times 131.25 \)).
    - Interactive **Chart.js** fermentation gravity drop curve over time.
    - Tasting reflections, rating scores (0–10 scale), and modification notes.

11. **🧮 Brewing Calculators Suite**:
    - **BJCP Style Guidelines Explorer**: Search and inspect official BJCP style profiles.
    - **ABV & Attenuation**: Standard and Alternate high-gravity formulas.
    - **Hydrometer Temperature Correction**: Adjust gravity readings for sample temperature vs calibration.
    - **Priming Sugar Calculator**: Calculate dextrose / sucrose needed for bottle carbonation based on target $CO_2$ volumes and beer temperature.
    - **Gravity Boost / Mash Sugar Addition**: Calculate exact sugar or extract amounts (Table Sugar, Corn Sugar, Honey, DME, LME) required to raise mash/must gravity to a target OG.
    - **Strike Water & Mineral Salts**: Mash temperature strike calcs and target water salt additions.

12. **📚 Reference Library & Document Manager (`documents.php`)**:
    - Accessible repository for uploaded/imported PDF brewing handbooks, recipe guides, and text notes with MIME verification and configurable file size limits.

13. **📄 Printable PDF Exporter (`export_pdf.php`)**:
    - Formatted PDF brew day logs and recipe summary printouts.

14. **📱 Companion RESTful JSON API (`/api/v1/`)**:
    - Mobile-ready API endpoints (`/api/v1/auth/login`, `/api/v1/batches`, `/api/v1/recipes`, `/api/v1/readings`).
    - Secured by Bearer token authentication header (`Authorization: Bearer <api_token>`).

15. **🛡️ Security Hardening**:
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

3. Seed database and import starter data:
   ```bash
   docker exec craftbrew_web php /var/www/html/install.php
   ```

4. Open browser:
   - **URL**: `http://localhost:8080`
   - **Default Login**: Username: `brewer` | Password: `password123`

---

## 📂 Project Directory Overview

```
./brewSite/
├── config.php                  # Application security & MariaDB PDO configuration (v2.8.0)
├── db.php                      # MariaDB PDO connection helper & ABV calculators
├── schema.sql                  # MariaDB database schema definition
├── index.php                   # Splash Landing Page & Brewer Dashboard
├── login.php                   # User login with CSRF & bot defense
├── register.php                # User registration
├── logout.php                  # Session logout
├── recipes.php                 # Recipe book (Beer, Wine, Cider, Mead)
├── recipe_detail.php           # Recipe details, BJCP compliance gauge & export
├── recipe_edit.php              # Dynamic recipe editor with BJCP target preview & in-line scaling
├── scale_recipe.php            # Recipe Auto-Scaler (Volume & Efficiency scaling)
├── labels.php                  # Printable Bottle & Keg Label Designer (with QR code)
├── batches.php                 # Brew log tracker index with stage filtering
├── batch_detail.php            # Fermentation log, milestone timeline & Chart.js graph
├── batch_edit.php              # Create/edit brew logs (Must Prep, Primary, Secondary)
├── calculators.php             # Brewing calculators & BJCP Style Explorer
├── documents.php               # Reference document library
├── export_pdf.php              # PDF export generator for batches & recipes
├── data_manager.php            # User archive backup/restore & BeerXML manager
├── Dockerfile                  # PHP 8.2 Apache container definition
├── docker-compose.yml          # Docker Compose configuration (MariaDB 10.11 + PHP 8.2)
├── assets/
│   ├── css/style.css           # Responsive mobile-friendly CSS theme & badge styles
│   └── js/app.js               # Dynamic calculator & Chart.js graph renderer
├── legacy_import/              # Historical PDFs, text logs, and images imported into MariaDB
├── api/
│   └── v1/                     # REST API endpoints for companion Android app
└── includes/
    ├── header.php              # Responsive navigation header
    ├── footer.php              # Page footer
    ├── bjcp_styles.php         # BJCP 2021 style dataset, SRM color mapper & target gauges
    ├── auth_check.php          # Session guard, CSRF helper, & API Bearer token validator
    ├── ZipHelper.php           # ZIP archive export/import helper
    └── TotpService.php         # RFC 6238 TOTP two-factor authentication engine
```
