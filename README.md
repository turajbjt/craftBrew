# CraftBrew - Home & Craft Brewing Log & Recipe Management System

A multi-user PHP, HTML5, CSS3, and MariaDB SQL application designed for homebrewers to formulate recipes, track fermentation logs, record hydrometer readings, generate printable PDF sheets, access reference documents, and connect companion Android applications via RESTful JSON API.

---

## 🌟 Features

1. **Recipe Book & Formulator**:
   - Create, edit, and share brewing formulas for **Beer**, **Wine**, **Cider**, **Mead**, and **Fruit Wine**.
   - Structured **Ingredients Breakdown** (Fermentables, Hops, Yeast, Additives/Finings, Water).
   - Structured **Equipment & Supplies Checklist** (Carboys, Air Locks, Hydrometers, StarSan, Auto-Siphons, Caps).
   - Structured **Step-by-Step Brewing Schedule** with target temperatures (°F) and step durations.
   - 1-click **"Start Batch from Recipe"** button to launch new brew logs.

2. **Brew Batch & Fermentation Tracker**:
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

## 🚀 Quick Start & Deployment

### Docker Deployment (Recommended)

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
