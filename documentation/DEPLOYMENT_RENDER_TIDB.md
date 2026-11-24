# Berning Essentials: Render + TiDB Cloud Deployment Guide

This guide provides complete instructions for deploying the **Berning Essentials Management System** to **Render Web Services** connected securely to a **TiDB Cloud** MySQL-compatible database cluster using TLS/SSL encryption and environment variables.

---

## 1. Prerequisites

1. A **Render** account ([render.com](https://render.com)).
2. A **TiDB Cloud** account ([tidbcloud.com](https://tidbcloud.com)) with a Serverless or Dedicated cluster.
3. Your Git repository (GitHub / GitLab) containing the Berning Essentials source code.

---

## 2. TiDB Cloud Setup

### 2.1. Cluster Information
- **Host:** `gateway01.ap-southeast-1.prod.aws.tidbcloud.com`
- **Port:** `4000`
- **Database Name:** `berning_essentials`
- **TLS/SSL Encryption:** Enforced (TLS 1.2 / TLS 1.3)

### 2.2. Create Database & Import Schema
1. Open the **TiDB Cloud Web SQL Shell** or connect via your preferred MySQL client (e.g., MySQL Workbench, DBeaver, or CLI):
   ```bash
   mysql -u '<your-tidb-user>' -h gateway01.ap-southeast-1.prod.aws.tidbcloud.com -P 4000 -D test --ssl-mode=VERIFY_IDENTITY --ssl-ca=/path/to/isrgrootx1.pem -p
   ```
2. Create the production database if not already created:
   ```sql
   CREATE DATABASE IF NOT EXISTS `berning_essentials` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   USE `berning_essentials`;
   ```
3. Import the canonical schema file `sql/schema.sql` (or `sql/infinityfree_production_import.sql` without `CREATE DATABASE` / `USE` directives).
4. Verify all 11 tables are created:
   ```sql
   SHOW TABLES;
   ```

### 2.3. Obtain the CA Certificate (ISRG Root X1)
TiDB Cloud server certificates are signed by **Let's Encrypt (ISRG Root X1)**.
- Download the official root certificate from Let's Encrypt:
  - URL: [https://letsencrypt.org/certs/isrgrootx1.pem](https://letsencrypt.org/certs/isrgrootx1.pem)
- Save this file as `isrgrootx1.pem`.

---

## 3. Render Web Service Deployment

### Option A: Using Render Blueprints (`render.yaml`) — Automated
1. Push the updated project (including `Dockerfile` and `render.yaml`) to your Git repository.
2. In the Render Dashboard, click **New +** $\rightarrow$ **Blueprint**.
3. Select your repository.
4. Render will detect `render.yaml` and prompt you to input the secret credentials (`DB_USER` and `DB_PASS`) and upload the `isrgrootx1.pem` Secret File.
5. Click **Apply**.

---

### Option B: Manual Web Service Setup via Render Dashboard

1. In the Render Dashboard, click **New +** $\rightarrow$ **Web Service**.
2. Select your Git repository.
3. Configure the service settings:
   - **Name:** `berning-essentials`
   - **Region:** `Singapore (Southeast Asia)` *(Matches TiDB Cloud ap-southeast-1 for lowest latency)*
   - **Runtime:** `Docker` (Render automatically uses the repository's `Dockerfile`)
   - **Instance Type:** `Free` (or higher tier)

4. **Add Environment Variables:**
   Navigate to the **Environment** tab and add the following variables:

   | Key | Value | Notes |
   |---|---|---|
   | `APP_ENV` | `production` | Enforces production mode and strict security |
   | `DB_HOST` | `gateway01.ap-southeast-1.prod.aws.tidbcloud.com` | TiDB Cloud Gateway |
   | `DB_PORT` | `4000` | TiDB Cloud Port |
   | `DB_NAME` | `berning_essentials` | TiDB Database Name |
   | `DB_USER` | *(Your TiDB Username)* | E.g., `xxxxxx.root` |
   | `DB_PASS` | *(Your TiDB Password)* | Never committed to Git |
   | `DB_SSL_CA_PATH` | `/etc/secrets/isrgrootx1.pem` | Mount path of Secret File |

5. **Upload the CA Certificate as a Secret File:**
   - In the **Environment** tab, scroll down to the **Secret Files** section.
   - Click **Add Secret File**.
   - **Filename / Mount Path:** `/etc/secrets/isrgrootx1.pem`
   - **Contents:** Open your downloaded `isrgrootx1.pem` file, copy all text (including `-----BEGIN CERTIFICATE-----` and `-----END CERTIFICATE-----`), and paste it into the editor.
   - Click **Save**.

6. **Deploy:**
   - Click **Create Web Service** (or **Manual Deploy** $\rightarrow$ **Deploy latest commit**).
   - Render will build the Docker image, install `pdo_mysql`, enable Apache `mod_rewrite`, mount the Secret File, and start the service.

---

## 4. Verification & Testing

Once deployment completes and the status is **Live**:

1. **System Health Check:**
   Visit: `https://<your-render-url>.onrender.com/api/owner_register.php?action=status`
   - Should return HTTP 200 with JSON: `{"success":true,"owner_exists":true,"can_register":false}`
2. **Access Clean URLs:**
   - Unified Login: `https://<your-render-url>.onrender.com/login/`
   - Cashier Terminal: `https://<your-render-url>.onrender.com/cashier/`
   - Owner Management: `https://<your-render-url>.onrender.com/admin/`
3. **Legacy URL Redirection:**
   - Visiting `/cashier.html` should automatically 301-redirect to `/cashier/`.
   - Visiting `/login.html` should automatically 301-redirect to `/login/`.
4. **Log In:**
   - Log in with existing credentials to confirm full database authentication via TiDB Cloud TLS.
