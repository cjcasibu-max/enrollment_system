# NCST Maritime Academy — Enrollment System

A PHP-based enrollment and student management system for the National College of Science and Technology Maritime Academy.

---

## 🚀 Requirements

- **XAMPP** (Apache + MySQL + PHP 8.x)
- **Git**

---

## ⚙️ Setup Instructions

### 1. Clone the repository

```bash
git clone https://github.com/ladicaaleekhine-dotcom/marine.academy.git
```

Move the folder into your XAMPP `htdocs`:

```
C:\xampp\htdocs\marine.academy
```

### 2. Start XAMPP

Open **XAMPP Control Panel** and start both:
- ✅ Apache
- ✅ MySQL

### 3. Create the Database

1. Open your browser → go to [http://localhost/phpmyadmin](http://localhost/phpmyadmin)
2. Create a new database named: `marine_academy`
3. Import the schema:
   - Click on the `marine_academy` database
   - Go to **Import** tab
   - Select `database/schema.sql` → click **Go**
4. Import seed data (optional but recommended):
   - Import `database/seed_data.sql`
   - Import `database/seed_subjects.sql`

### 4. Configure the Database

Open `config/database.php` and update if needed:

```php
$host = 'localhost';
$dbname = 'marine_academy';
$username = 'root';
$password = '';  // Leave blank for default XAMPP
```

### 5. Configure the Mailer (Email)

Open `config/mailer.php` — the SMTP credentials are already filled in:

```php
'host'     => 'smtp.gmail.com',
'port'     => 587,
'username' => 'ncst.marine.academy@gmail.com',
'password' => 'cetw ndai vkqz qhfw',   // Gmail App Password
```

> No changes needed — just leave it as is and email features will work.

### 6. Run Database Migration (if needed)

Open your browser and go to:
```
http://localhost/marine.academy/database/migrate.php
```

### 7. Seed Curriculum Data

Run this once to populate curriculum and subjects:
```
http://localhost/marine.academy/database/seed_curriculum.php
```

### 8. Open the App

Go to: [http://localhost/marine.academy](http://localhost/marine.academy)

---

## Default Accounts

| Role       | Email                            | Password   |
|------------|----------------------------------|------------|
| Admin      | admin@ncst.edu.ph                | Admin@123  |
| Registrar  | registrar@ncst.edu.ph            | Admin@123  |
| Cashier    | cashier@ncst.edu.ph              | Admin@123  |

---

## Folder Structure

```
marine.academy/
├── actions/          # Form action handlers (PHP)
├── admin/            # Admin panel pages
├── auth/             # Login, register, logout
├── cashier/          # Cashier portal
├── config/           # Database & mailer config
├── database/         # SQL schema, seeds, migrations
├── enrollee/         # Applicant/enrollee portal
├── includes/         # Shared PHP includes & PHPMailer
├── registrar/        # Registrar portal
├── student/          # Student portal
├── teacher/          # Teacher portal
├── assets/           # CSS, JS, images, vendor libraries
└── uploads/          # Uploaded student documents
```

---

## Email / PHPMailer

PHPMailer is included locally in `includes/vendor/phpmailer/`. No Composer installation needed — it works out of the box.

The Gmail account used for sending emails: `ncst.marine.academy@gmail.com`

---

## Tech Stack

- **Backend**: PHP 8.x
- **Database**: MySQL (via PDO)
- **Frontend**: Bootstrap 5, Tabulator, SweetAlert2
- **Email**: PHPMailer (local copy, no Composer needed)
- **Server**: Apache (XAMPP)
