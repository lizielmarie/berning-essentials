# BERNING ESSENTIALS MANAGEMENT SYSTEM
## Official Production Deployment Guide for InfinityFree (https://berningstore.rf.gd)

---

### 1. Overview & Architecture

The **Berning Essentials Management System** is a full-stack, secure, database-driven grocery store POS and administrative web application built with PHP, MySQL/MariaDB (PDO), JavaScript, HTML5, and CSS3.

The system provides **two interfaces only**:
1. **Owner/Admin Management Portal** — Full administrative control, dashboard, reports, inventory, users, and audit logs.
2. **Cashier Point-of-Sale (POS)** — Product search, barcode scanning, cart, checkout, payment processing, and receipt generation.

- **Production Domain**: `https://berningstore.rf.gd`
- **Hosting Target**: InfinityFree Shared Hosting (Apache + PHP 8.x + MySQL/MariaDB)
- **Local Development**: XAMPP (`http://localhost/berning_essentials`)

---

### 2. Default Access Accounts & Credentials

All test accounts are pre-configured in the database with secure bcrypt password hashing:

| Portal | Role | Username | Email | Password | Access URL |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Owner / Admin Portal** | Owner | `owner_admin` | `liziel@berning.store` | `12345` | `/admin_login.html` or `/admin.php` |
| **Cashier Terminal** | Cashier | `liziel_cashier` | N/A | `12345` | `/cashier.html` |
| **Floor Attendant** | Store Attendant | `chrystal_attendant` | N/A | `12345` | `/cashier.html` |

> **Important**: Once you log into the Owner Portal for the first time, go to **Settings & Profile** (`admin.html#settings`) and change your password to a strong personal password.

**Login Flow**: The unified login page (`admin_login.html`) automatically routes users based on their role:
- **Owner** → Owner Management Portal (`admin.html`)
- **Cashier / Store Attendant** → Cashier POS (`cashier.html`)
- **Unauthorized roles** → Access Denied

---

### 3. Step-by-Step InfinityFree Deployment Instructions

#### Step 1: Create your MySQL Database on InfinityFree
1. Log in to your **InfinityFree Client Area** (https://dash.infinityfree.com).
2. Click on your active hosting account for `berningstore.rf.gd`.
3. Open the **Control Panel (vPanel)**.
4. Under the **Databases** section, click **MySQL Databases**.
5. Under **Create a New Database**, enter a name (e.g. `berning_store` or `store`) and click **Create Database**.
6. Take note of the connection parameters displayed on the page:
   - **MySQL Host / Server Name**: e.g. `sql101.infinityfree.com` (or `sqlXXX.epizy.com`)
   - **MySQL Database Name**: e.g. `if0_38123456_berning_store`
   - **MySQL Username**: e.g. `if0_38123456`
   - **MySQL Password**: Your InfinityFree vPanel account password (can be viewed/unhidden in the Client Area under Account Details).
   - **MySQL Port**: `3306`

#### Step 2: Import the Database Schema & Seed Data
1. In the InfinityFree Control Panel, click **phpMyAdmin**.
2. Click the **Connect** button next to your newly created database.
3. In phpMyAdmin, click on your database name on the left sidebar (e.g. `if0_38123456_berning_store`).
4. Click the **Import** tab at the top.
5. Under **File to import**, click **Choose File** and select:
   `sql/infinityfree_production_import.sql`
   *(This clean SQL file has no `CREATE DATABASE` or `USE` statements and imports cleanly without permission errors).*
6. Scroll down and click **Import** (or **Go**).
7. You should see a green success message stating that all 11 tables and initial records were imported successfully.

#### Step 3: Configure Database Credentials in `backend/config.php`
1. Open the file `backend/config.php` on your computer in an editor.
2. Locate lines 35–40:
   ```php
   defined('PROD_DB_HOST') || define('PROD_DB_HOST', 'sql101.infinityfree.com'); // Replace with your MySQL Host
   defined('PROD_DB_NAME') || define('PROD_DB_NAME', 'if0_38123456_berning_store'); // Replace with your DB Name
   defined('PROD_DB_USER') || define('PROD_DB_USER', 'if0_38123456'); // Replace with your DB Username
   defined('PROD_DB_PASS') || define('PROD_DB_PASS', 'YOUR_INFINITYFREE_PASSWORD'); // Enter your DB Password
   ```
3. Enter your actual InfinityFree MySQL Host, Database Name, Username, and Password.
4. Save the file.

*(Note: In local XAMPP or local LAN Wi-Fi testing, the system automatically detects development mode and connects to your local database without requiring changes).*

#### Step 4: Upload Application Files to InfinityFree `htdocs`
1. Use an FTP client such as **FileZilla** or the InfinityFree **Monsta File Manager**:
   - **FTP Host**: `ftpupload.net` (or the host shown in your Client Area)
   - **FTP Username**: Your `if0_...` account username
   - **FTP Password**: Your account password
   - **Port**: `21`
2. Navigate to the **`htdocs/`** directory of your domain `berningstore.rf.gd`.
   *(Ensure you are inside the `htdocs/` folder, NOT the root)*.
3. Upload all the contents of `berning_essentials` into `htdocs/`:
   ```
   htdocs/
   ├── .htaccess
   ├── admin.html
   ├── admin.php
   ├── admin_login.html
   ├── cashier.html
   ├── index.php
   ├── owner_register.html
   ├── api/
   ├── backend/
   ├── css/
   ├── frontend/
   ├── js/
   └── sql/
   ```

#### Step 5: Test the Live Website
1. Visit `https://berningstore.rf.gd/` — You will be redirected to the Staff & Management Login page.
2. Log in with `liziel_cashier` / `12345` to test the **Cashier POS**:
   - Test scanning a product barcode (e.g. `480001001` for Coca-Cola).
   - Test adding to cart and completing a cash sale.
   - Print or view the receipt.
3. Log in with `owner_admin` / `12345` to test the **Owner Portal**:
   - Verify that all real-time stats, product catalog, sales reports, inventory levels, customer list, and audit logs are visible and fully functional.

---

### 4. Security & Production Features Implemented

1. **Password Security**: Passwords are encrypted using PHP `password_hash()` with `PASSWORD_BCRYPT`. Legacy passwords are auto-upgraded on login.
2. **Role-Based Access Control (RBAC)**: Strict role separation. Only Owner, Cashier, and Store Attendant roles are permitted. Cashiers cannot access administrative endpoints or the Owner Management Portal. Unauthorized roles are denied at login.
3. **Prepared Statements**: 100% of SQL queries use PDO prepared statements to completely eliminate SQL injection risks.
4. **Transaction Integrity**: The POS checkout system wraps sales, items, and inventory deductions in ACID database transactions (`beginTransaction()`, `commit()`, `rollBack()`) with row locking (`SELECT ... FOR UPDATE`) to prevent negative inventory and race conditions.
5. **Session Management**: Session tokens are cryptographically secure 64-character hexadecimal strings stored in MySQL with automatic expiration and secure HTTP-only cookies.
6. **Error Masking in Production**: Database credentials and internal server traces are masked in production so users never see sensitive system details if a database outage occurs.
7. **Directory Protection**: The root `.htaccess` disables directory browsing (`Options -Indexes`) and blocks direct access to configuration files and SQL dumps.
