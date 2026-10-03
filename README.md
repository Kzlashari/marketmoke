# MarketMoke - Pure PHP & MySQL Marketplace Platform

A production-ready Guest Post & Link Insertion Marketplace application designed to run natively on Apache / cPanel / XAMPP with MySQL via PDO.

---

## 📁 Directory Structure

```
Marketmoke/
├── admin/                     # Admin suite pages & management views
│   ├── overview.php           # Admin overview & system metrics
│   ├── marketplace.php        # Listings & publisher domain management
│   ├── blog.php               # Editorial blog manager
│   ├── users.php              # User & role management
│   ├── ai-automation.php      # AI content & keyword automation
│   ├── invoices.php           # Invoices & billing suite
│   ├── gateways.php           # Payment gateways configuration
│   ├── pages.php              # Custom page builder
│   ├── services.php           # Services management
│   └── settings.php           # General, SEO, and custom code settings
├── api/                       # Standalone PHP API Endpoints
│   ├── admin.php              # Admin statistics & approval API
│   ├── ai_automation.php      # AI keyword tracking & automation API
│   ├── blogs.php              # Blog CRUD, category filter & pagination API
│   ├── contact.php            # Contact form inquiry API with database storage
│   ├── dashboard.php          # Admin & user dashboard metrics API
│   ├── forgot_password.php    # OTP request & password recovery API
│   ├── gateways.php           # Payment gateway settings API
│   ├── invoices.php           # Invoices & billing API
│   ├── login.php              # Authentication & OTP dispatch API
│   ├── logout.php             # Session destruction API
│   ├── marketplace.php        # Marketplace inventory & website listing API
│   ├── orders.php             # Order lifecycle & escrow wallet API
│   ├── pages.php              # CMS custom pages API
│   ├── reset_password.php     # Password reset API
│   ├── send_otp.php           # 6-digit OTP dispatch API
│   ├── services.php           # Platform services catalog API
│   ├── settings.php           # System & SEO settings API
│   ├── users.php              # User accounts & profile edit approval API
│   ├── verify_link.php        # Real-time backlink & anchor verification crawler
│   ├── verify_otp.php         # OTP verification & session generator API
│   └── websites.php           # Website search, filtering & submission API
├── assets/                    # Static Assets (HTML, CSS, JS)
│   ├── css/
│   │   └── style.css          # Custom styling & utility classes
│   └── js/
│       ├── app.js             # Main client-side frontend application (Vanilla JS)
│       ├── admin-spa.js       # Admin suite dashboard logic
│       └── user-spa.js        # User / Publisher portal logic
├── auth/                      # Authentication views
│   ├── login.php              # User & admin login view
│   ├── register.php           # User & publisher registration view
│   ├── verify-otp.php         # OTP entry & verification view
│   ├── forgot-password.php    # Password recovery request view
│   ├── reset-password.php     # Password reset view
│   └── logout.php             # Logout action redirect
├── database/                  # MySQL Relational Database Schemas
│   ├── schema.sql             # Primary database schema & seed data
│   ├── schema_indexes.sql     # Database optimization indexes
│   └── ai_automation_schema.sql # AI automation tables & keyword tracking
├── includes/                  # Core PHP Classes & Helpers
│   ├── db.php                 # Database PDO singleton with prepared statements
│   ├── auth.php               # Session & role-based access control
│   ├── mailer.php             # Email notifications & OTP dispatcher
│   ├── MarketMokeMailer.php   # System mailer wrapper
│   ├── rate_limiter.php       # Brute-force rate limiting
│   └── wallet.php             # Atomic wallet & escrow transaction engine
├── upload/                    # User & site uploads
├── uploads/                   # Media & verification proofs
├── user/                      # User & publisher dashboard views
│   └── dashboard.php          # User / Client dashboard template
├── .htaccess                  # Apache mod_rewrite rules for clean URLs
├── auth_check.php             # Security guard helper
├── author-dashboard.php       # Author / Editor portal
├── config.php                 # Centralized configuration (DB_HOST, DB_USER, etc.)
├── db.php                     # Root database bridge
├── index.html                 # Frontend SPA HTML template
├── index.php                  # Primary Apache entry point & clean URL router
├── login.php                  # Root login gateway
├── logout.php                 # Root logout handler
├── publisher-dashboard.php    # Publisher management portal
├── register.php               # Root registration gateway
├── robots.php                 # Dynamic robots.txt generator
├── sitemap.php                # Dynamic XML sitemap generator
└── verify-otp.php             # Root OTP verification gateway
```

---

## 🚀 Setup & Installation (XAMPP / Apache / cPanel)

### 1. Database Setup
1. Open **phpMyAdmin** in your local XAMPP (`http://localhost/phpmyadmin`) or cPanel.
2. Create a new database named `marketmoke_db` (or any database name of your choice).
3. Import the SQL files from the `database/` folder:
   - `database/schema.sql` (Creates all tables and seeds default admin, user, and demo data)
   - `database/schema_indexes.sql` (Optional: performance indexes)
   - `database/ai_automation_schema.sql` (Optional: AI automation tables)

### 2. Configuration (`config.php`)
Open `config.php` and set your MySQL credentials:
```php
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'marketmoke_db');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 3. Web Server Configuration (Apache)
Ensure `mod_rewrite` is enabled in Apache. The included `.htaccess` file automatically routes all clean API and frontend requests to `index.php`.

If running in a subfolder (e.g., `http://localhost/Marketmoke`), the system automatically detects the base directory via `getAppBasePath()` in `config.php`.

### 4. First administrator
No demo or hard-coded login credentials are included. Import [`database/schema.sql`](database/schema.sql), then create one administrator with a unique password:

```powershell
C:\xampp\php\php.exe database\bootstrap_admin.php --name="Site Admin" --email="admin@example.com" --password="use-a-unique-password-of-at-least-12-characters"
```

The bootstrap refuses to run if an administrator already exists. All subsequent sign-ins are verified only against the `users` table.
