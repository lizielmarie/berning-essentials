# BERNING ESSENTIALS MANAGEMENT SYSTEM

A full-stack, database-driven grocery store Point-of-Sale (POS) and administrative management application built for **Berning Essentials Store**. Developed with native PHP 8.2 (PDO), MySQL/MariaDB, JavaScript, HTML5, and CSS3. 

Engineered for dual-environment deployment: runs locally on **XAMPP** and deploys seamlessly on **InfinityFree** shared hosting (`https://berningstore.rf.gd`).

---

## 1. System Overview & Architecture

The application provides two distinct, role-protected interfaces:

1. **Owner / Admin Management Portal** (`admin.html` via `admin.php`)
   - Real-time business dashboard with revenue, sales volume, and transaction trends.
   - Comprehensive Product Catalog CRUD (with unique barcode validation).
   - Category and Supplier management directories.
   - Stock Inventory management, Stock-In batch recording, and manual stock adjustments.
   - Customer ledger and transaction tracking.
   - Staff User management (Owner, Cashier, Store Attendant) with bcrypt password hashing.
   - Sales reporting, period filtering (daily, weekly, monthly, custom), and CSV data export.
   - Void transaction capability with automatic inventory restoration.
   - Complete audit trail logging every administrative and sales action.
   - Owner profile settings and password change.

2. **Cashier Point-of-Sale (POS)** (`cashier.html`)
   - Fast, interactive POS terminal with live keyboard shortcuts.
   - Barcode scanning with instant product lookup.
   - Shopping cart management, live subtotal, tax, and discount calculation.
   - Multi-payment support: Cash (with automatic change calculation) and GCash e-wallet.
   - Atomic checkout with ACID database transactions and row-level locking (`SELECT ... FOR UPDATE`) to prevent negative inventory.
   - Automatic receipt generation, formatted for thermal receipt printing.
   - Customer attachment during checkout.
   - Cashier shift metrics (today's revenue, transaction count, low-stock alerts).

---

## 2. Technology Stack

- **Backend Runtime**: PHP 8.0 – 8.2 (Pure native PHP; zero external frameworks or composers).
- **Database**: MySQL 5.7+ / MariaDB 10.4+ (InnoDB tables, Foreign Keys, ACID transactions).
- **Frontend**: HTML5, Vanilla JavaScript (ES6+ async/await fetch), CSS3 (Responsive Grid/Flexbox).
- **Icons & Visualization**: FontAwesome 6.4 (CDN), Chart.js (CDN).
- **Web Server**: Apache 2.4 with `.htaccess` (mod_rewrite, FastCGI header forwarding, security rules).
- **Hosting Targets**: XAMPP (Local Development) & InfinityFree (Production Shared Hosting).

---

## 3. Project Directory Structure

```text
BERNING_ESSENTIALS/
│
├── .htaccess                           # Apache configuration, security guards & URL routing
├── index.php                           # Central router (routes based on session & role)
├── admin.php                           # Server-side security gateway for Owner Portal
├── admin.html                          # Owner / Admin Management Portal Interface
├── admin_login.html                    # Unified Staff & Management Login Portal
├── login.html                          # Unified Login alias
├── login.php                           # Root login endpoint (serves API & browser navigation)
├── cashier.html                        # Cashier Point-of-Sale (POS) Terminal Interface
├── owner_register.html                 # First-time Owner Setup & Registration Interface
├── README.md                           # System documentation and operational guide
│
├── api/                                # Public API Gateways (Forwarders to Backend Logic)
│   ├── admin.php                       # Admin gateway proxy
│   ├── admin_audit.php                 # System audit logs endpoint
│   ├── admin_categories.php            # Category management CRUD API
│   ├── admin_customers.php             # Customer management CRUD API
│   ├── admin_inventory.php             # Stock inventory and Stock-In API
│   ├── admin_products.php              # Product catalog CRUD API
│   ├── admin_reports.php               # Sales reporting & analytics API
│   ├── admin_sales.php                 # Sales transaction ledger & void API
│   ├── admin_stats.php                 # Real-time dashboard KPI analytics API
│   ├── admin_stock_adjustments.php     # Stock audit adjustments API
│   ├── admin_suppliers.php             # Supplier directory CRUD API
│   ├── admin_users.php                 # Staff and cashier management API
│   ├── auth.php                        # Session validation helper
│   ├── cashier_stats.php               # POS cashier shift metrics API
│   ├── db.php                          # Central database connection bridge
│   ├── get_products.php                # Active product catalog for POS
│   ├── get_product_by_barcode.php      # Barcode scanner lookup API
│   ├── login.php                       # Unified user authentication API
│   ├── logout.php                      # User session termination API
│   ├── owner_profile.php               # Store owner profile & password API
│   ├── owner_register.php              # One-time owner setup verification API
│   └── process_sale.php                # Live POS checkout & inventory deduction API
│
├── backend/                            # Core Business Logic & Database Services
│   ├── config.php                      # Centralized configuration & environment detector
│   ├── db.php                          # Central PDO connection & timezone synchronization
│   ├── auth.php                        # Session security, token generation, RBAC & cookies
│   ├── login.php                       # Unified authentication engine with alias resolution
│   ├── logout.php                      # Session invalidation & cookie cleanup
│   ├── process_sale.php                # ACID transaction processor for sales & inventory
│   ├── get_products.php                # Product catalog query handler
│   ├── get_product_by_barcode.php      # Barcode query handler
│   ├── cashier_stats.php               # POS shift KPI calculator
│   ├── owner_register.php              # First-time owner setup logic
│   ├── owner_profile.php               # Owner account settings handler
│   ├── admin_products.php              # Product inventory database queries
│   ├── admin_categories.php            # Category database queries
│   ├── admin_inventory.php             # Stock-in and replenishment queries
│   ├── admin_stock_adjustments.php     # Discrepancy adjustment handler
│   ├── admin_sales.php                 # Sales ledger and void processor
│   ├── admin_customers.php             # Customer profile & history handler
│   ├── admin_suppliers.php             # Supplier directory handler
│   ├── admin_users.php                 # Staff RBAC account manager
│   ├── admin_stats.php                 # Dashboard business metric aggregator
│   ├── admin_reports.php               # Report generation & CSV exporter
│   ├── admin_audit.php                 # Audit trail event logger
│   └── admin.php                       # Server-side HTML renderer
│
├── assets/                             # Static Frontend Assets
│   ├── css/
│   │   └── cashier.css                 # Standalone POS stylesheet
│   └── js/
│       └── cashier.js                  # Standalone POS script
│
├── css/                                # Legacy Asset Compatibility Folder
│   └── cashier.css                     # Maintained for backward compatibility
│
├── js/                                 # Legacy Script Compatibility Folder
│   └── cashier.js                      # Maintained for backward compatibility
│
├── sql/                                # Database Schemas & Production Imports
│   ├── infinityfree_production_import.sql # Official clean SQL import (no CREATE DATABASE / USE)
│   ├── schema.sql                      # Base relational database schema
│   └── archive/                        # Historical migration scripts & development dumps
│       ├── backup_before_debug.sql
│       ├── berning_store_dump.sql
│       ├── berning_store_production.sql
│       ├── migration_v2.sql
│       ├── migration_v3_owner.sql
│       ├── run_migration.php
│       ├── run_migration_v3.php
│       └── setup_db.php
│
├── documentation/                      # Detailed System Documentation & Guides
│   └── DEPLOYMENT_INFINITYFREE.md      # Step-by-step InfinityFree deployment guide
│
└── _backup_pre_cleanup/                # Protected Pre-Cleanup Workspace Backup
```

---

## 4. Default Credentials & Access Portals

All user accounts use bcrypt password encryption:

| Portal | Role | Username / Login | Email | Password | Access URL |
|:---|:---|:---|:---|:---|:---|
| **Owner / Admin Portal** | Owner | `owner_admin` | `liziel@berning.store` | `12345` | `/admin_login.html` or `/admin.php` |
| **Cashier Terminal** | Cashier | `liziel_cashier` | `cashier@berning.store` | `12345` | `/admin_login.html` $\rightarrow$ `/cashier.html` |
| **Floor Attendant** | Store Attendant | `chrystal_attendant` | `chrystal@berning.store` | `12345` | `/admin_login.html` $\rightarrow$ `/cashier.html` |

> **Note**: Both Owner and Cashier authenticate through the same **Unified Login Portal** (`admin_login.html`). The backend queries the MySQL `users` table and automatically directs users to their assigned interface. Cashiers cannot access the Owner Management Portal.

---

## 5. Local Setup Instructions (XAMPP)

1. Clone or copy `berning_essentials` into your XAMPP web root:
   ```text
   C:\xampp\htdocs\berning_essentials
   ```
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open **phpMyAdmin** (`http://localhost/phpmyadmin`).
4. Create a database named `berning_store` with collation `utf8mb4_unicode_ci`.
5. Import `sql/infinityfree_production_import.sql`.
6. Open your browser and navigate to:
   ```text
   http://localhost/berning_essentials/
   ```
   You will be automatically directed to the Unified Login Portal.

---

## 6. InfinityFree Production Deployment

1. **Create MySQL Database**:
   - Log in to your InfinityFree vPanel $\rightarrow$ **MySQL Databases**.
   - Create a database (e.g., `if0_XXXXXXXX_berning_store`).
2. **Import Database**:
   - Open **phpMyAdmin** from vPanel.
   - Select your database and click **Import**.
   - Choose `sql/infinityfree_production_import.sql` and click **Import**.
3. **Configure Database Credentials**:
   - Open `backend/config.php` and fill in lines 35–40:
     ```php
     defined('PROD_DB_HOST') || define('PROD_DB_HOST', 'sql101.infinityfree.com');
     defined('PROD_DB_NAME') || define('PROD_DB_NAME', 'if0_38123456_berning_store');
     defined('PROD_DB_USER') || define('PROD_DB_USER', 'if0_38123456');
     defined('PROD_DB_PASS') || define('PROD_DB_PASS', 'YOUR_VPANEL_PASSWORD');
     ```
4. **Upload Files**:
   - Upload the project files directly into the **`htdocs/`** directory via FTP (FileZilla) or Monsta File Manager.
5. **Visit Website**:
   - Navigate to `https://berningstore.rf.gd/` to start using the system online.

---

## 7. Verification Test Suite

The system includes automated test suites covering 187 end-to-end assertions:

```bash
# Full System Health & CRUD Verification (49 assertions)
php test_full_system.php

# Unified Login & Routing Verification (31 assertions)
php test_unified_login.php

# Multi-Role RBAC Security Verification (19 assertions)
php test_role_rbac.php

# Authentication Scenarios Verification (36 assertions)
php test_login_scenarios.php

# Cashier POS & Inventory Synchronization (26 assertions)
php test_cashier_pos_complete.php

# API Integration & Ledger Verification (26 assertions)
php test_integration.php
```
