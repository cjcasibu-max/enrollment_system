# Render Deployment Guide: NCST Maritime Academy Enrollment System & LMS

This guide explains how to deploy this PHP/MySQL project to **Render** using Docker.

---

## Architecture Overview

```
                      ┌─────────────────────────────────────────┐
                      │            Render Cloud                 │
                      │                                         │
GitHub (Repository) ─►│  Web Service (Docker: php:8.2-apache)   │
                      │  - Apache mod_rewrite & mod_headers     │
                      │  - Clean extensionless URLs (.htaccess) │
                      │  - Persistent / ephemeral upload dirs   │
                      └────────────────────┬────────────────────┘
                                           │ (PDO MySQL connection)
                                           ▼
                      ┌─────────────────────────────────────────┐
                      │    Cloud MySQL Database                 │
                      │    (Aiven / TiDB / Railway / Clever)    │
                      └─────────────────────────────────────────┘
```

---

## Step 1: Set Up a Free Cloud MySQL Database

Render provides managed PostgreSQL, but this application uses **MySQL / MariaDB**. You can connect to any free cloud MySQL database. Recommended free options:

### Option A: Aiven (Recommended - Free Tier MySQL)
1. Go to [aiven.io](https://aiven.io/) and sign up.
2. Create a **MySQL** service on the Free Plan.
3. Note your connection details:
   - **Host** (e.g. `mysql-xxxx.aivencloud.com`)
   - **Port** (e.g. `12345`)
   - **Database Name** (e.g. `defaultdb` or create `enrollment_system`)
   - **User** (e.g. `avnadmin`)
   - **Password**
   - **SSL**: Set `DB_SSL=true`

### Option B: TiDB Cloud Serverless (Free Forever MySQL-Compatible)
1. Go to [tidbcloud.com](https://tidbcloud.com/) and create a free Serverless cluster.
2. Under Connection details, choose **General / MySQL**.
3. Note Host, Port (`4000`), User, Password, and set `DB_SSL=true`.

### Option C: Railway (MySQL Service)
1. Go to [railway.app](https://railway.app/).
2. Create a new project -> Add a **MySQL** database.
3. Copy the `DATABASE_URL` (format: `mysql://root:password@host:port/railway`).

---

## Step 2: Deploy to Render

### Method 1: Using Render Dashboard (Recommended)

1. Push your latest code to your GitHub repository:
   ```bash
   git add .
   git commit -m "Add Docker and Render deployment configuration"
   git push origin main
   ```

2. Go to the [Render Dashboard](https://dashboard.render.com/).
3. Click **New +** -> **Web Service**.
4. Connect your GitHub repository: `cjcasibu-max/enrollment_system`.
5. Configure the service settings:
   - **Name**: `enrollment-system` (or your preferred name)
   - **Region**: `Singapore` (closest to Philippines for best latency)
   - **Branch**: `main`
   - **Runtime**: **Docker**
   - **Plan**: **Free**
   - **Health Check Path**: `/healthz.php`

6. Under **Environment Variables**, add the following keys:

| Key | Example Value | Notes |
|---|---|---|
| `DB_HOST` | `mysql-xxxx.aivencloud.com` | From your MySQL provider |
| `DB_PORT` | `3306` (or `12345` / `4000`) | Cloud MySQL port |
| `DB_NAME` | `defaultdb` or `enrollment_system` | Cloud database name |
| `DB_USER` | `avnadmin` or `root` | Database username |
| `DB_PASS` | `YourPassword123` | Database password |
| `DB_SSL` | `true` | Required for Aiven/TiDB Cloud |
| `DB_AUTO_INIT` | `true` | Automatically creates tables & seeds data on first boot |
| `SEED_DEMO_DATA` | `true` | Seeds demo admin, teachers, students, and LMS data |

*(Alternatively, if using Railway or a provider with a connection string, you can simply set `DATABASE_URL=mysql://user:pass@host:port/dbname`)*

7. Click **Create Web Service**. Render will build the Docker container and start your application.

---

### Method 2: Using Render Blueprint (`render.yaml`)

1. In Render Dashboard, click **New +** -> **Blueprint**.
2. Select your `cjcasibu-max/enrollment_system` repository.
3. Render reads `render.yaml` automatically and prompts you to fill in your `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
4. Click **Apply**.

---

## Step 3: Database Initialization & Seeding

The application includes an automated cloud database setup script: `database/setup_cloud_db.php`.

When `DB_AUTO_INIT=true` is set, the container automatically runs this script on its first start:
1. Creates all tables from `database/schema.sql` (compatible with cloud database names).
2. Applies all non-destructive migrations from `database/migrate.php`.
3. Seeds programs, curriculum, and subject courses from `database/seed_curriculum.php`.
4. Seeds demo accounts (Admin, Registrar, Cashier, Teachers, Students) and LMS content from `database/seed_lms_demo.php`.

### Manual Trigger (via Render Shell)
If you ever want to re-run or reset database seeding manually:
1. Open your service in the Render Dashboard.
2. Click on the **Shell** tab.
3. Run:
   ```bash
   php database/setup_cloud_db.php --seed-demo
   ```

---

## Step 4: Verify Deployment

1. Visit your Render URL: `https://your-service-name.onrender.com/`
2. Test the health check endpoint: `https://your-service-name.onrender.com/healthz.php`
   - It will return `{"status":"ok", "database":"connected"}`.
3. Test login with demo credentials:

| Role | Username | Password |
|---|---|---|
| **Admin** | `demo_admin` | `Demo@12345` |
| **Registrar** | `demo_registrar` | `Demo@12345` |
| **Cashier** | `demo_cashier` | `Demo@12345` |
| **Teacher 1** (Navigation) | `demo_teacher1` | `Demo@12345` |
| **Teacher 2** (Marine Eng) | `demo_teacher2` | `Demo@12345` |
| **Student 1** (BSMT-1A) | `demo_student1` | `Demo@12345` |
| **Student 13** (BSMarE-1A) | `demo_student13` | `Demo@12345` |

---

## File Uploads Note (Render Free Tier)

Render Free Web Services use ephemeral disks: uploaded files in `uploads/` and `private_uploads/` persist while the service is running, but are reset if the container restarts after inactivity.

For persistent file storage in production:
- You can attach a **Persistent Disk** on Render (paid plan: Disk tab -> Add Disk mount at `/var/www/html/uploads` and `/var/www/html/private_uploads`).
- Or integrate cloud object storage (e.g. AWS S3, Cloudflare R2, Supabase Storage).
