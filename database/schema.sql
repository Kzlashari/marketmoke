-- MarketMoke Guest Post Marketplace - Database Schema
-- Pure MySQL / MariaDB Schema for cPanel / phpMyAdmin / Cloud SQL

CREATE DATABASE IF NOT EXISTS `marketmoke_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `marketmoke_db`;

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'editor', 'author', 'publisher', 'user') NOT NULL DEFAULT 'user',
  `status` ENUM('active', 'inactive', 'pending', 'suspended', 'rejected') NOT NULL DEFAULT 'active',
  `company` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_users_email` (`email`),
  INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User Edit Requests Table (Pending Admin Approval Workflow)
CREATE TABLE IF NOT EXISTS `user_edit_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `user_name` VARCHAR(100) NOT NULL,
  `user_email` VARCHAR(150) NOT NULL,
  `current_data` JSON NOT NULL,
  `requested_data` JSON NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_edits_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User OTPs Table (Hashed 6-Digit Codes & Expiry Timestamp)
CREATE TABLE IF NOT EXISTS `user_otps` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `email` VARCHAR(150) NOT NULL,
  `otp_code` VARCHAR(255) NOT NULL COMMENT 'Hashed OTP via password_hash()',
  `attempts` INT NOT NULL DEFAULT 0,
  `reset_token` VARCHAR(64) DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_otps_lookup` (`email`, `is_used`, `expires_at`),
  INDEX `idx_user_otps_user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate Limits Table (Brute-Force & Denial-of-Service Defense)
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `endpoint` VARCHAR(60) NOT NULL,
  `request_count` INT NOT NULL DEFAULT 1,
  `last_request` DATETIME NOT NULL,
  `reset_time` DATETIME NOT NULL,
  UNIQUE KEY `uk_ip_endpoint` (`ip_address`, `endpoint`),
  INDEX `idx_rate_limits_reset` (`reset_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OTP Verifications Legacy Compatibility Table
CREATE TABLE IF NOT EXISTS `otp_verifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `otp_code` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Marketplace Websites Table
CREATE TABLE IF NOT EXISTS `marketplace_websites` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT 1,
  `website_name` VARCHAR(150) NOT NULL,
  `website_url` VARCHAR(255) NOT NULL,
  `domain` VARCHAR(150) NOT NULL UNIQUE,
  `da` INT DEFAULT 0,
  `dr` INT DEFAULT 0,
  `traffic` INT DEFAULT 0,
  `traffic_source` VARCHAR(100) DEFAULT 'Organic Search',
  `country` VARCHAR(80) DEFAULT 'United States',
  `language` VARCHAR(50) DEFAULT 'English',
  `niche` VARCHAR(80) NOT NULL,
  `guest_post_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'USD',
  `single_website_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `link_type` ENUM('dofollow', 'nofollow') NOT NULL DEFAULT 'dofollow',
  `is_sponsored` TINYINT(1) NOT NULL DEFAULT 0,
  `turnaround_days` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('active', 'paused', 'pending', 'rejected') DEFAULT 'active',
  `is_featured` TINYINT(1) DEFAULT 0,
  `contact_info` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_mkt_domain` (`domain`),
  INDEX `idx_mkt_niche` (`niche`),
  INDEX `idx_mkt_country` (`country`),
  INDEX `idx_mkt_status` (`status`),
  INDEX `idx_mkt_price` (`guest_post_price`),
  INDEX `idx_mkt_public_status_id` (`status`, `id`),
  INDEX `idx_mkt_public_status_da` (`status`, `da`, `id`),
  INDEX `idx_mkt_public_status_dr` (`status`, `dr`, `id`),
  INDEX `idx_mkt_public_status_traffic` (`status`, `traffic`, `id`),
  INDEX `idx_mkt_public_status_price` (`status`, `guest_post_price`, `id`),
  INDEX `idx_mkt_public_status_country` (`status`, `country`, `id`),
  INDEX `idx_mkt_public_status_niche` (`status`, `niche`, `id`),
  INDEX `idx_mkt_public_status_language` (`status`, `language`, `id`),
  INDEX `idx_mkt_public_status_link` (`status`, `link_type`, `id`),
  FULLTEXT INDEX `idx_mkt_public_search` (`website_name`, `domain`, `niche`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Blog Posts Table
CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL UNIQUE,
  `featured_image` VARCHAR(500) DEFAULT NULL,
  `author` VARCHAR(100) NOT NULL DEFAULT 'Admin',
  `content` LONGTEXT NOT NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'General',
  `tags` VARCHAR(255) DEFAULT NULL,
  `meta_title` VARCHAR(255) DEFAULT NULL,
  `meta_description` TEXT DEFAULT NULL,
  `seo_keywords` VARCHAR(255) DEFAULT NULL,
  `publish_date` DATE NOT NULL,
  `status` ENUM('published', 'draft', 'archived') NOT NULL DEFAULT 'published',
  `canonical_url` VARCHAR(255) DEFAULT NULL,
  `is_featured` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_blog_slug` (`slug`),
  INDEX `idx_blog_status` (`status`),
  INDEX `idx_blog_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Custom Pages Table (With Separate HTML / CSS / JS Editors)
CREATE TABLE IF NOT EXISTS `pages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL UNIQUE,
  `content` LONGTEXT DEFAULT NULL,
  `custom_html` LONGTEXT DEFAULT NULL,
  `custom_css` LONGTEXT DEFAULT NULL,
  `custom_js` LONGTEXT DEFAULT NULL,
  `meta_title` VARCHAR(255) DEFAULT NULL,
  `meta_description` TEXT DEFAULT NULL,
  `canonical_url` VARCHAR(255) DEFAULT NULL,
  `publish_date` DATE NOT NULL,
  `status` ENUM('published', 'draft') NOT NULL DEFAULT 'published',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_page_slug` (`slug`),
  INDEX `idx_page_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Site Settings Table (Key-Value Store for General, SEO, and Custom Code)
CREATE TABLE IF NOT EXISTS `site_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_group` VARCHAR(50) NOT NULL,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` LONGTEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity Logs Table
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_name` VARCHAR(100) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NOT NULL,
  `ip_address` VARCHAR(50) DEFAULT '127.0.0.1',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_logs_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User Wallets Table (Atomic Escrow & Cash Balances)
CREATE TABLE IF NOT EXISTS `user_wallets` (
  `user_id` INT PRIMARY KEY,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `escrow_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_wallets_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders Table (Guest Post & Link Insertion Lifecycle Management)
CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_code` VARCHAR(50) NOT NULL UNIQUE,
  `buyer_id` INT NOT NULL,
  `seller_id` INT NOT NULL,
  `website_id` INT DEFAULT NULL,
  `service_type` ENUM('guest_post', 'link_insertion') NOT NULL DEFAULT 'guest_post',
  `article_title` VARCHAR(255) DEFAULT NULL,
  `article_body` LONGTEXT DEFAULT NULL,
  `target_url` VARCHAR(500) NOT NULL,
  `anchor_text` VARCHAR(255) NOT NULL,
  `link_type` ENUM('dofollow', 'nofollow') NOT NULL DEFAULT 'dofollow',
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `platform_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `publisher_payout` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `published_url` VARCHAR(500) DEFAULT NULL,
  `link_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verification_details` JSON DEFAULT NULL,
  `status` ENUM('pending', 'accepted', 'submitted', 'verified', 'completed', 'cancelled', 'disputed') NOT NULL DEFAULT 'pending',
  `deadline_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_orders_status_users` (`status`, `buyer_id`, `seller_id`),
  INDEX `idx_orders_buyer` (`buyer_id`),
  INDEX `idx_orders_seller` (`seller_id`),
  INDEX `idx_orders_website` (`website_id`),
  INDEX `idx_orders_status` (`status`),
  INDEX `idx_orders_code` (`order_code`),
  FOREIGN KEY (`buyer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Escrow Transactions Table (Immutable Audit Ledger)
CREATE TABLE IF NOT EXISTS `escrow_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `buyer_id` INT NOT NULL,
  `seller_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `type` ENUM('hold', 'release', 'refund') NOT NULL,
  `status` ENUM('held', 'released', 'refunded') NOT NULL DEFAULT 'held',
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_escrow_order_status` (`order_id`, `status`),
  INDEX `idx_escrow_order` (`order_id`),
  INDEX `idx_escrow_buyer` (`buyer_id`),
  INDEX `idx_escrow_seller` (`seller_id`),
  INDEX `idx_escrow_type` (`type`),
  INDEX `idx_escrow_status` (`status`),
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`buyer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices Table (Financial & Billing Management)
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
  `status` ENUM('Unpaid', 'Paid', 'Cancelled', 'Refunded') NOT NULL DEFAULT 'Unpaid',
  `business_name` VARCHAR(150) NOT NULL DEFAULT 'MarketMoke Marketplace LLC',
  `business_email` VARCHAR(150) NOT NULL DEFAULT 'billing@marketmoke.com',
  `business_address` TEXT DEFAULT NULL,
  `bill_to_name` VARCHAR(150) NOT NULL,
  `bill_to_email` VARCHAR(150) NOT NULL,
  `bill_to_address` TEXT DEFAULT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `tx_hash` VARCHAR(255) DEFAULT NULL,
  `memo` TEXT DEFAULT NULL,
  `terms` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_invoices_number` (`invoice_number`),
  INDEX `idx_invoices_status` (`status`),
  INDEX `idx_invoices_email` (`bill_to_email`),
  INDEX `idx_invoices_date` (`invoice_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice Items Table
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_items_invoice` (`invoice_id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Default Seed Data for Local XAMPP Environment (INSERT IGNORE)
-- ============================================================================

-- No login accounts are seeded. Create the first administrator through the
-- deployment/bootstrap process and use a unique password_hash() value.

-- Seed Default Site Settings
INSERT IGNORE INTO `site_settings` (`setting_group`, `setting_key`, `setting_value`) VALUES
('general', 'site_name', 'MarketMoke - Premium Guest Posting & Link Building Marketplace'),
('general', 'site_tagline', 'High DA/DR Contextual Backlinks with Instant Escrow Protection'),
('general', 'admin_email', 'support@marketmoke.local'),
('financial', 'platform_fee_percent', '10.00'),
('financial', 'escrow_hold_days', '3'),
('financial', 'default_currency', 'USD');

-- Seed Sample Marketplace Websites
INSERT IGNORE INTO `marketplace_websites` (`id`, `user_id`, `website_name`, `website_url`, `domain`, `description`, `niche`, `da`, `dr`, `traffic`, `guest_post_price`, `single_website_price`, `status`) VALUES
(1, 3, 'TechCrunchy Global', 'https://techcrunchy.com', 'techcrunchy.com', 'Premier tech news and SaaS software reviews blog.', 'Technology', 74, 76, 320000, 140.00, 95.00, 'active'),
(2, 3, 'Finance Savvy Hub', 'https://financesavvy.org', 'financesavvy.org', 'Authoritative personal finance, cryptocurrency, and investing portal.', 'Finance & Crypto', 68, 71, 185000, 195.00, 130.00, 'active'),
(3, 3, 'Healthy Living Digest', 'https://healthydigest.net', 'healthydigest.net', 'Wellness, biohacking, and holistic lifestyle magazine.', 'Health & Wellness', 62, 65, 95000, 110.00, 75.00, 'active');

-- Seed Sample Invoice
INSERT IGNORE INTO `invoices` (`id`, `invoice_number`, `status`, `business_name`, `business_email`, `bill_to_name`, `bill_to_email`, `invoice_date`, `due_date`, `currency`, `subtotal`, `discount`, `tax_total`, `total`, `payment_method`, `tx_hash`) VALUES
(1, 'INV-2026-001', 'Paid', 'MarketMoke Marketplace LLC', 'billing@marketmoke.com', 'Michael Client', 'client@marketmoke.com', '2026-09-01', '2026-09-15', 'USD', 140.00, 0.00, 0.00, 140.00, 'PayPal', 'PAYID-MTG8294178');

INSERT IGNORE INTO `invoice_items` (`id`, `invoice_id`, `description`, `quantity`, `price`, `tax`) VALUES
(1, 1, 'Guest Post on TechCrunchy Global (DA 74)', 1, 140.00, 0.00);
