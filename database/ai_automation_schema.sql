-- MarketMoke - AI Guest Post & Content Automation Agent Schema
-- Migration script for MySQL 8.0+ / MariaDB 10.4+

-- Run this migration against the database configured in config.php. It must not
-- select or create a differently named database on behalf of an installation.

-- 1. AI Automation Settings & API Configs
CREATE TABLE IF NOT EXISTS `ai_automation_settings` (
  `id` INT PRIMARY KEY DEFAULT 1,
  `active_provider` ENUM('gemini', 'custom') NOT NULL DEFAULT 'gemini',
  `gemini_api_key` VARCHAR(255) DEFAULT NULL,
  `gemini_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-2.5-flash',
  `gemini_temperature` DECIMAL(3,2) NOT NULL DEFAULT 0.70,
  `gemini_max_tokens` INT NOT NULL DEFAULT 4096,
  `custom_base_url` VARCHAR(255) DEFAULT 'https://api.openai.com/v1',
  `custom_api_key` VARCHAR(255) DEFAULT NULL,
  `custom_model` VARCHAR(100) DEFAULT 'gpt-4o-mini',
  `publishing_mode` ENUM('manual', 'auto') NOT NULL DEFAULT 'manual',
  `schedule_interval_type` ENUM('hours', 'days', 'specific_days') NOT NULL DEFAULT 'days',
  `schedule_interval_value` INT NOT NULL DEFAULT 1,
  `schedule_specific_days` VARCHAR(100) DEFAULT 'Monday,Wednesday,Friday',
  `schedule_time` TIME DEFAULT '09:00:00',
  `target_category` VARCHAR(100) DEFAULT 'SEO & Link Building',
  `target_author` VARCHAR(100) DEFAULT 'MarketMoke AI Editorial',
  `auto_publish_status` ENUM('published', 'draft') NOT NULL DEFAULT 'published',
  `cron_token` VARCHAR(64) DEFAULT NULL,
  `last_cron_run` DATETIME DEFAULT NULL,
  `active_seed_topics` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Smart Keyword Research & Auto-Replenishment Table
CREATE TABLE IF NOT EXISTS `ai_keywords_list` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `topic_seed` VARCHAR(150) NOT NULL,
  `keyword` VARCHAR(255) NOT NULL,
  `normalized_keyword` VARCHAR(255) NOT NULL UNIQUE,
  `search_volume` VARCHAR(50) DEFAULT 'N/A',
  `kd_estimate` VARCHAR(50) DEFAULT 'N/A',
  `search_intent` ENUM('informational', 'commercial', 'transactional', 'navigational') NOT NULL DEFAULT 'informational',
  `competitor_source` VARCHAR(255) DEFAULT NULL,
  `ranking_url` VARCHAR(255) DEFAULT NULL,
  `ranking_position` INT DEFAULT NULL,
  `metrics_source` VARCHAR(80) DEFAULT NULL,
  `metrics_updated_at` DATETIME DEFAULT NULL,
  `status` ENUM('pending', 'processing', 'unused', 'used', 'published', 'failed') NOT NULL DEFAULT 'pending',
  `used_in_post_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `used_at` DATETIME DEFAULT NULL,
  INDEX `idx_topic_seed_status` (`topic_seed`, `status`),
  INDEX `idx_kw_status` (`status`),
  INDEX `idx_normalized_keyword` (`normalized_keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Competitor Analysis & Content Gap Cache
CREATE TABLE IF NOT EXISTS `ai_competitor_analyses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `competitor_url` VARCHAR(500) NOT NULL,
  `topic_seed` VARCHAR(150) DEFAULT NULL,
  `analysis_data` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_comp_url` (`competitor_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. AI Generated & Published Articles Table (Extended tracking)
CREATE TABLE IF NOT EXISTS `ai_published_articles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `blog_post_id` INT NOT NULL,
  `topic_seed` VARCHAR(150) NOT NULL,
  `primary_keyword` VARCHAR(255) NOT NULL UNIQUE,
  `secondary_keyword` VARCHAR(255) NOT NULL,
  `lsi_keyword` VARCHAR(255) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `word_count` INT NOT NULL DEFAULT 0,
  `status` ENUM('published', 'draft', 'scheduled', 'failed') NOT NULL DEFAULT 'published',
  `schedule_date` DATETIME NOT NULL,
  `improvement_count` INT NOT NULL DEFAULT 0,
  `last_improved_at` DATETIME DEFAULT NULL,
  `improvement_notes` TEXT DEFAULT NULL,
  `quality_score` INT DEFAULT 100,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_articles_post` (`blog_post_id`),
  INDEX `idx_ai_articles_status` (`status`),
  INDEX `idx_ai_articles_date` (`schedule_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. AI Automation Activity & Audit Logs
CREATE TABLE IF NOT EXISTS `ai_automation_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_type` VARCHAR(50) NOT NULL,
  `message` TEXT NOT NULL,
  `details` LONGTEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_log_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Separately managed AI and research providers. API credentials are stored
-- encrypted by the application, never as browser-visible values.
CREATE TABLE IF NOT EXISTS `ai_provider_configs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `provider_name` VARCHAR(120) NOT NULL,
  `provider_type` ENUM('gemini', 'openai_compatible', 'dataforseo') NOT NULL,
  `api_base_url` VARCHAR(500) DEFAULT NULL,
  `api_key_encrypted` TEXT DEFAULT NULL,
  `model` VARCHAR(160) DEFAULT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_provider_type_enabled` (`provider_type`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Default Settings record if not present
INSERT IGNORE INTO `ai_automation_settings` (
  `id`, `active_provider`, `gemini_model`, `gemini_temperature`, `gemini_max_tokens`,
  `custom_base_url`, `custom_model`, `publishing_mode`, `schedule_interval_type`,
  `schedule_interval_value`, `schedule_specific_days`, `schedule_time`,
  `target_category`, `target_author`, `auto_publish_status`, `cron_token`, `active_seed_topics`
) VALUES (
  1, 'gemini', 'gemini-2.5-flash', 0.70, 4096,
  'https://api.openai.com/v1', 'gpt-4o-mini', 'manual', 'days',
  1, 'Monday,Wednesday,Friday', '09:00:00',
  'SEO & Link Building', 'MarketMoke AI Editorial', 'published', NULL,
  'Guest Posting, Contextual Backlinks, SaaS Link Building, Digital PR Outreach'
);
