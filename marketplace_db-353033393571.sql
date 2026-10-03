-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: sdb-65.hosting.stackcp.net
-- Generation Time: Sep 29, 2026 at 02:41 PM
-- Server version: 10.11.18-MariaDB-log
-- PHP Version: 8.3.35

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `marketplace_db-353033393571`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text NOT NULL,
  `ip_address` varchar(50) DEFAULT '127.0.0.1',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_automation_settings`
--

CREATE TABLE `ai_automation_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `active_provider` enum('gemini','custom') NOT NULL DEFAULT 'gemini',
  `gemini_api_key` varchar(255) DEFAULT NULL,
  `gemini_model` varchar(100) NOT NULL DEFAULT 'gemini-2.5-flash',
  `gemini_temperature` decimal(3,2) NOT NULL DEFAULT 0.70,
  `gemini_max_tokens` int(11) NOT NULL DEFAULT 4096,
  `custom_base_url` varchar(255) DEFAULT 'https://api.openai.com/v1',
  `custom_api_key` varchar(255) DEFAULT NULL,
  `custom_model` varchar(100) DEFAULT 'gpt-4o-mini',
  `publishing_mode` enum('manual','auto') NOT NULL DEFAULT 'manual',
  `schedule_interval_type` enum('hours','days','specific_days') NOT NULL DEFAULT 'days',
  `schedule_interval_value` int(11) NOT NULL DEFAULT 1,
  `schedule_specific_days` varchar(100) DEFAULT 'Monday,Wednesday,Friday',
  `schedule_time` time DEFAULT '09:00:00',
  `target_category` varchar(100) DEFAULT 'SEO & Link Building',
  `target_author` varchar(100) DEFAULT 'MarketMoke AI Editorial',
  `auto_publish_status` enum('published','draft') NOT NULL DEFAULT 'published',
  `cron_token` varchar(64) NOT NULL DEFAULT 'moke_cron_sec_8f9811f236524cb8',
  `last_cron_run` datetime DEFAULT NULL,
  `active_seed_topics` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_keywords_list`
--

CREATE TABLE `ai_keywords_list` (
  `id` int(11) NOT NULL,
  `topic_seed` varchar(150) NOT NULL,
  `keyword` varchar(255) NOT NULL,
  `normalized_keyword` varchar(255) NOT NULL,
  `search_intent` enum('informational','commercial','transactional','navigational') NOT NULL DEFAULT 'informational',
  `kd_estimate` int(11) NOT NULL DEFAULT 35,
  `status` enum('unused','used','skipped') NOT NULL DEFAULT 'unused',
  `used_in_post_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_published_articles`
--

CREATE TABLE `ai_published_articles` (
  `id` int(11) NOT NULL,
  `blog_post_id` int(11) NOT NULL,
  `topic_seed` varchar(150) NOT NULL,
  `primary_keyword` varchar(255) NOT NULL,
  `secondary_keyword` varchar(255) NOT NULL,
  `lsi_keyword` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `word_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('published','draft','scheduled') NOT NULL DEFAULT 'published',
  `schedule_date` datetime NOT NULL,
  `improvement_count` int(11) NOT NULL DEFAULT 0,
  `last_improved_at` datetime DEFAULT NULL,
  `improvement_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_categories`
--

CREATE TABLE `blog_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_posts`
--

CREATE TABLE `blog_posts` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `featured_image` varchar(500) DEFAULT NULL,
  `author` varchar(100) NOT NULL DEFAULT 'Admin',
  `content` longtext NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'General',
  `tags` varchar(255) DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `seo_keywords` varchar(255) DEFAULT NULL,
  `publish_date` date NOT NULL,
  `status` enum('published','draft','archived') NOT NULL DEFAULT 'published',
  `canonical_url` varchar(255) DEFAULT NULL,
  `is_featured` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `escrow_transactions`
--

CREATE TABLE `escrow_transactions` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('hold','release','refund') NOT NULL,
  `status` enum('held','released','refunded') NOT NULL DEFAULT 'held',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `status` enum('Unpaid','Paid','Cancelled','Refunded') NOT NULL DEFAULT 'Unpaid',
  `business_name` varchar(150) NOT NULL DEFAULT 'MarketMoke Marketplace LLC',
  `business_email` varchar(150) NOT NULL DEFAULT 'billing@marketmoke.com',
  `business_address` text DEFAULT NULL,
  `bill_to_name` varchar(150) NOT NULL,
  `bill_to_email` varchar(150) NOT NULL,
  `bill_to_address` text DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `tx_hash` varchar(255) DEFAULT NULL,
  `memo` text DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `invoice_number`, `status`, `business_name`, `business_email`, `business_address`, `bill_to_name`, `bill_to_email`, `bill_to_address`, `invoice_date`, `due_date`, `currency`, `subtotal`, `discount`, `tax_total`, `total`, `payment_method`, `tx_hash`, `memo`, `terms`, `created_at`, `updated_at`) VALUES
(1, 'INV-2026-001', 'Paid', 'MarketMoke Marketplace LLC', 'billing@marketmoke.com', NULL, 'Michael Client', 'client@marketmoke.com', NULL, '2026-09-01', '2026-09-15', 'USD', 140.00, 0.00, 0.00, 140.00, 'PayPal', 'PAYID-MTG8294178', NULL, NULL, '2026-09-07 04:10:01', '2026-09-07 04:10:01'),
(1, 'INV-2026-001', 'Paid', 'MarketMoke Marketplace LLC', 'billing@marketmoke.com', NULL, 'Michael Client', 'client@marketmoke.com', NULL, '2026-09-01', '2026-09-15', 'USD', 140.00, 0.00, 0.00, 140.00, 'PayPal', 'PAYID-MTG8294178', NULL, NULL, '2026-09-29 10:35:42', '2026-09-29 10:35:42');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_items`
--

INSERT INTO `invoice_items` (`id`, `invoice_id`, `description`, `quantity`, `price`, `tax`, `created_at`) VALUES
(1, 1, 'Guest Post on TechCrunchy Global (DA 74)', 1, 140.00, 0.00, '2026-09-07 04:10:01'),
(1, 1, 'Guest Post on TechCrunchy Global (DA 74)', 1, 140.00, 0.00, '2026-09-29 10:35:42');

-- --------------------------------------------------------

--
-- Table structure for table `marketplace_websites`
--

CREATE TABLE `marketplace_websites` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT 1,
  `website_name` varchar(150) NOT NULL,
  `website_url` varchar(255) NOT NULL,
  `domain` varchar(150) NOT NULL,
  `da` int(11) DEFAULT 0,
  `dr` int(11) DEFAULT 0,
  `traffic` int(11) DEFAULT 0,
  `traffic_source` varchar(100) DEFAULT 'Organic Search',
  `country` varchar(80) DEFAULT 'United States',
  `language` varchar(50) DEFAULT 'English',
  `niche` varchar(80) NOT NULL,
  `guest_post_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'USD',
  `single_website_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `status` enum('active','paused','pending','rejected') DEFAULT 'active',
  `is_featured` tinyint(1) DEFAULT 0,
  `contact_info` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `marketplace_websites`
--

INSERT INTO `marketplace_websites` (`id`, `user_id`, `website_name`, `website_url`, `domain`, `da`, `dr`, `traffic`, `traffic_source`, `country`, `language`, `niche`, `guest_post_price`, `currency`, `single_website_price`, `description`, `status`, `is_featured`, `contact_info`, `created_at`, `updated_at`) VALUES
(1, 3, 'TechCrunchy Global', 'https://techcrunchy.com', 'techcrunchy.com', 74, 76, 320000, 'Organic Search', 'United States', 'English', 'Technology', 140.00, 'USD', 95.00, 'Premier tech news and SaaS software reviews blog.', 'active', 0, NULL, '2026-09-07 04:10:01', '2026-09-07 04:10:01'),
(2, 3, 'Finance Savvy Hub', 'https://financesavvy.org', 'financesavvy.org', 68, 71, 185000, 'Organic Search', 'United States', 'English', 'Finance & Crypto', 195.00, 'USD', 130.00, 'Authoritative personal finance, cryptocurrency, and investing portal.', 'active', 0, NULL, '2026-09-07 04:10:01', '2026-09-07 04:10:01'),
(3, 3, 'Healthy Living Digest', 'https://healthydigest.net', 'healthydigest.net', 62, 65, 95000, 'Organic Search', 'United States', 'English', 'Health & Wellness', 110.00, 'USD', 75.00, 'Wellness, biohacking, and holistic lifestyle magazine.', 'active', 0, NULL, '2026-09-07 04:10:01', '2026-09-07 04:10:01'),
(4, 1, 'Sosoactive Media', 'https://sosoactive.net', 'sosoactive.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(5, 1, 'Eurogamersonline Media', 'https://eurogamersonline.org', 'eurogamersonline.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(6, 1, 'Lookwhatmomfoundgiveaways Media', 'https://lookwhatmomfoundgiveaways.com', 'lookwhatmomfoundgiveaways.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(7, 1, 'Decoratorsadvice Media', 'https://decoratorsadvice.com', 'decoratorsadvice.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3500.00, 'USD', 2450.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(8, 1, 'Decoradyard Media', 'https://decoradyard.com', 'decoradyard.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(9, 1, 'Mintpaldecor Media', 'https://mintpaldecor.com', 'mintpaldecor.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(10, 1, 'Parentingtipsfpmomhacks Media', 'https://parentingtipsfpmomhacks.com', 'parentingtipsfpmomhacks.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(11, 1, 'Lwmfhotels Media', 'https://lwmfhotels.blog', 'lwmfhotels.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(12, 1, 'Rcsdassk Media', 'https://rcsdassk.com', 'rcsdassk.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3500.00, 'USD', 2450.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(13, 1, 'Decoradtech Media', 'https://decoradtech.org', 'decoradtech.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(14, 1, 'Feedworldtech Media', 'https://feedworldtech.org', 'feedworldtech.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(15, 1, 'Designmode24 Media', 'https://designmode24.org', 'designmode24.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(16, 1, 'Justalittlebite Media', 'https://justalittlebite.co.uk', 'justalittlebite.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(17, 1, 'Iodaracing Media', 'https://iodaracing.org', 'iodaracing.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(18, 1, 'Trendingmagazine Media', 'https://trendingmagazine.org', 'trendingmagazine.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(19, 1, 'Fpmomlife Media', 'https://fpmomlife.org', 'fpmomlife.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(20, 1, 'Wutawhealth Media', 'https://wutawhealth.com', 'wutawhealth.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(21, 1, 'Cwbiancaparenting Media', 'https://cwbiancaparenting.net', 'cwbiancaparenting.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(22, 1, 'Traveltweaks Media', 'https://traveltweaks.uk', 'traveltweaks.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(23, 1, 'Jalbitesnacks Media', 'https://jalbitesnacks.org', 'jalbitesnacks.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(24, 1, 'Wavetechglobal Media', 'https://wavetechglobal.co.uk', 'wavetechglobal.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(25, 1, 'Undergrowthgameline Media', 'https://undergrowthgameline.org', 'undergrowthgameline.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(26, 1, 'Fpmomtips Media', 'https://fpmomtips.net', 'fpmomtips.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(27, 1, 'Lookwhatmomfound Media', 'https://lookwhatmomfound.org', 'lookwhatmomfound.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3500.00, 'USD', 2450.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(28, 1, 'Teamaelftech Media', 'https://teamaelftech.org', 'teamaelftech.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(29, 1, 'Decoradhous Media', 'https://decoradhous.com', 'decoradhous.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(30, 1, 'Cwbiancavoyage Media', 'https://cwbiancavoyage.com', 'cwbiancavoyage.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(31, 1, 'Thehometrotterscom Media', 'https://thehometrotterscom.com', 'thehometrotterscom.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(32, 1, 'Llblogkids Media', 'https://llblogkids.com', 'llblogkids.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(33, 1, 'Lovelolablog Media', 'https://lovelolablog.co.uk', 'lovelolablog.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(34, 1, 'Blueflamepublishing Media', 'https://blueflamepublishing.blog', 'blueflamepublishing.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(35, 1, 'Tgarchivegaming Media', 'https://tgarchivegaming.net', 'tgarchivegaming.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(36, 1, 'Ftasiamanagement Media', 'https://ftasiamanagement.com', 'ftasiamanagement.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(37, 1, 'Etruesportscom Media', 'https://etruesportscom.com', 'etruesportscom.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(38, 1, 'Ttweakhotel Media', 'https://ttweakhotel.org', 'ttweakhotel.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(39, 1, 'Clearskinstudy Media', 'https://clearskinstudy.org', 'clearskinstudy.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(40, 1, 'Fpmomlife Media', 'https://fpmomlife.com', 'fpmomlife.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(41, 1, 'Decoradhouse Media', 'https://decoradhouse.net', 'decoradhouse.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(42, 1, 'Drovenio Media', 'https://drovenio.us', 'drovenio.us', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(43, 1, 'Undergrowthgameline Media', 'https://undergrowthgameline.com', 'undergrowthgameline.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3500.00, 'USD', 2450.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(44, 1, 'Whatutalkingboutfamily Media', 'https://whatutalkingboutfamily.com', 'whatutalkingboutfamily.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(45, 1, 'Jalbitehealth Media', 'https://jalbitehealth.com', 'jalbitehealth.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(46, 1, 'Wizzydigitalorg Media', 'https://wizzydigitalorg.org', 'wizzydigitalorg.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(47, 1, 'Betterthistechs Media', 'https://betterthistechs.org', 'betterthistechs.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(48, 1, 'Pblinuxtech Media', 'https://pblinuxtech.net', 'pblinuxtech.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(49, 1, 'Zardgadjets Media', 'https://zardgadjets.org', 'zardgadjets.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(50, 1, 'Jalbitehealth Media', 'https://jalbitehealth.net', 'jalbitehealth.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(51, 1, 'Pblinuxtech Media', 'https://pblinuxtech.com', 'pblinuxtech.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(52, 1, 'Ftasiamanagement Media', 'https://ftasiamanagement.net', 'ftasiamanagement.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(53, 1, 'Artsthunderonthegulf Media', 'https://artsthunderonthegulf.com', 'artsthunderonthegulf.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(54, 1, 'Ftasiastock Media', 'https://ftasiastock.com', 'ftasiastock.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(55, 1, 'Zvodeps Media', 'https://zvodeps.org', 'zvodeps.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(56, 1, 'Whatutalkingboutfamily Media', 'https://whatutalkingboutfamily.co', 'whatutalkingboutfamily.co', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(57, 1, 'Ustudiobytes Media', 'https://ustudiobytes.com', 'ustudiobytes.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(58, 1, 'Drovenio Media', 'https://drovenio.net', 'drovenio.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(59, 1, 'Thehaketech Media', 'https://thehaketech.org', 'thehaketech.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(60, 1, 'Lovelolablog Media', 'https://lovelolablog.info', 'lovelolablog.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(61, 1, 'Plugboxlinuxorg Media', 'https://plugboxlinuxorg.org', 'plugboxlinuxorg.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(62, 1, 'Zeromagtech Media', 'https://zeromagtech.org', 'zeromagtech.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(63, 1, 'Decadgarden Media', 'https://decadgarden.com', 'decadgarden.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3500.00, 'USD', 2450.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(64, 1, 'Eurogamersonlinecom Media', 'https://eurogamersonlinecom.com', 'eurogamersonlinecom.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(65, 1, 'Truebiohub Media', 'https://truebiohub.com', 'truebiohub.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(66, 1, 'Ftasiaeconomy Media', 'https://ftasiaeconomy.info', 'ftasiaeconomy.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(67, 1, 'Ontpeconomy Media', 'https://ontpeconomy.blog', 'ontpeconomy.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(68, 1, 'Gamificationsummit Media', 'https://gamificationsummit.net', 'gamificationsummit.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(69, 1, 'Jalbiteblogfoodtrends Media', 'https://jalbiteblogfoodtrends.com', 'jalbiteblogfoodtrends.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(70, 1, 'Impocoolmom Media', 'https://impocoolmom.org', 'impocoolmom.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(71, 1, 'Interworldradio Media', 'https://interworldradio.com', 'interworldradio.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(72, 1, 'Btwletternews Media', 'https://btwletternews.blog', 'btwletternews.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(73, 1, 'Decoradhouse Media', 'https://decoradhouse.org', 'decoradhouse.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(74, 1, 'Decoradtech Media', 'https://decoradtech.net', 'decoradtech.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(75, 1, 'Fintechasia Media', 'https://fintechasia.co.uk', 'fintechasia.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(76, 1, 'Wutawhealth Media', 'https://wutawhealth.org', 'wutawhealth.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(77, 1, 'Lumondefinition Media', 'https://lumondefinition.com', 'lumondefinition.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(78, 1, 'Kongotechs Media', 'https://kongotechs.net', 'kongotechs.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(79, 1, 'Uggworldtech Media', 'https://uggworldtech.org', 'uggworldtech.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(80, 1, 'Etsgamevent Media', 'https://etsgamevent.com', 'etsgamevent.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(81, 1, 'Prosuccessguide Media', 'https://prosuccessguide.com', 'prosuccessguide.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(82, 1, 'Boring-magazine Media', 'https://boring-magazine.com', 'boring-magazine.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(83, 1, 'Thehakevent Media', 'https://thehakevent.org', 'thehakevent.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(84, 1, 'Qullnowisfap Media', 'https://qullnowisfap.org', 'qullnowisfap.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(85, 1, 'Zixyurevay Media', 'https://zixyurevay.org', 'zixyurevay.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(86, 1, 'Lwmfcrafts Media', 'https://lwmfcrafts.com', 'lwmfcrafts.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(87, 1, 'Homenumental Media', 'https://homenumental.com', 'homenumental.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(88, 1, 'Betterthisworld Media', 'https://betterthisworld.us', 'betterthisworld.us', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(89, 1, 'Lcfgamevent Media', 'https://lcfgamevent.org', 'lcfgamevent.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(90, 1, 'Famparentlife Media', 'https://famparentlife.org', 'famparentlife.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(91, 1, 'Wimpykid Media', 'https://wimpykid.org', 'wimpykid.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(92, 1, 'Beaconsoftnet Media', 'https://beaconsoftnet.net', 'beaconsoftnet.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(93, 1, 'Lcfgamestick Media', 'https://lcfgamestick.com', 'lcfgamestick.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(94, 1, 'Lovelolablog Media', 'https://lovelolablog.blog', 'lovelolablog.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(95, 1, 'Hernlen Media', 'https://hernlen.org', 'hernlen.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(96, 1, 'Hearthssgaming Media', 'https://hearthssgaming.org', 'hearthssgaming.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(97, 1, 'Dailyvibesnow Media', 'https://dailyvibesnow.com', 'dailyvibesnow.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(98, 1, 'Fameperson Media', 'https://fameperson.com', 'fameperson.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(99, 1, 'Pexascope Media', 'https://pexascope.com', 'pexascope.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(100, 1, 'Starsstocks Media', 'https://starsstocks.org', 'starsstocks.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(101, 1, 'Wutawhacks Media', 'https://wutawhacks.org', 'wutawhacks.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(102, 1, 'Nippydrive Media', 'https://nippydrive.org', 'nippydrive.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(103, 1, 'Famefactshub Media', 'https://famefactshub.com', 'famefactshub.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(104, 1, 'Llblogkids Media', 'https://llblogkids.org', 'llblogkids.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(105, 1, 'Uggcontroman Media', 'https://uggcontroman.com', 'uggcontroman.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(106, 1, 'Ftasiafinance Media', 'https://ftasiafinance.net', 'ftasiafinance.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(107, 1, 'Cilfqtacmitd Media', 'https://cilfqtacmitd.com', 'cilfqtacmitd.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(108, 1, 'Gingerbreadnails Media', 'https://gingerbreadnails.org', 'gingerbreadnails.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(109, 1, 'Wutawhelp Media', 'https://wutawhelp.org', 'wutawhelp.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(110, 1, 'Wutawhealth Media', 'https://wutawhealth.net', 'wutawhealth.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(111, 1, 'Undergrowthgameline Media', 'https://undergrowthgameline.co.uk', 'undergrowthgameline.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(112, 1, 'Thehometrotters Media', 'https://thehometrotters.co', 'thehometrotters.co', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(113, 1, 'Designmode24com Media', 'https://designmode24com.org', 'designmode24com.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(114, 1, 'Thesoundstour Media', 'https://thesoundstour.co.uk', 'thesoundstour.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(115, 1, 'Ittbetterthisworld Media', 'https://ittbetterthisworld.com', 'ittbetterthisworld.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(116, 1, 'Aajkitajikhabar Media', 'https://aajkitajikhabar.net', 'aajkitajikhabar.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(117, 1, 'Etruegames Media', 'https://etruegames.com', 'etruegames.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(118, 1, 'Homesphere Media', 'https://homesphere.blog', 'homesphere.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(119, 1, 'Playbattlesquare Media', 'https://playbattlesquare.net', 'playbattlesquare.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(120, 1, 'Clearskinstudycom Media', 'https://clearskinstudycom.com', 'clearskinstudycom.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(121, 1, 'Etesportech Media', 'https://etesportech.org', 'etesportech.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(122, 1, 'Valueyournetwork Media', 'https://valueyournetwork.co', 'valueyournetwork.co', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(123, 1, 'Traveltweakshotels Media', 'https://traveltweakshotels.blog', 'traveltweakshotels.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(124, 1, 'Ttweakhotel Media', 'https://ttweakhotel.com', 'ttweakhotel.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(125, 1, 'Meltingtopgames Media', 'https://meltingtopgames.org', 'meltingtopgames.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(126, 1, 'Fumbleboard Media', 'https://fumbleboard.org', 'fumbleboard.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(127, 1, 'Pulsamento Media', 'https://pulsamento.net', 'pulsamento.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(128, 1, 'Droven-io Media', 'https://droven-io.org', 'droven-io.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(129, 1, 'Theserpentrogue Media', 'https://theserpentrogue.org', 'theserpentrogue.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(130, 1, 'Kdarchistyle Media', 'https://kdarchistyle.org', 'kdarchistyle.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(131, 1, 'Jalbitehealth Media', 'https://jalbitehealth.org', 'jalbitehealth.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(132, 1, 'Ontpeconomy Media', 'https://ontpeconomy.com', 'ontpeconomy.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(133, 1, 'Lookwhatmomfoundgiveaway Media', 'https://lookwhatmomfoundgiveaway.com', 'lookwhatmomfoundgiveaway.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(134, 1, 'Kdarchitectsnet Media', 'https://kdarchitectsnet.com', 'kdarchitectsnet.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3500.00, 'USD', 2450.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(135, 1, 'Whatutalkingboutwillistyle Media', 'https://whatutalkingboutwillistyle.com', 'whatutalkingboutwillistyle.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(136, 1, 'Etherions Media', 'https://etherions.net', 'etherions.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(137, 1, 'Simplysevennet Media', 'https://simplysevennet.net', 'simplysevennet.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(138, 1, 'Riproar Media', 'https://riproar.org', 'riproar.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(139, 1, 'Roarleveraging Media', 'https://roarleveraging.com', 'roarleveraging.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(140, 1, 'Blueflamepublishing Media', 'https://blueflamepublishing.org', 'blueflamepublishing.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(141, 1, 'Lwmfcrafts Media', 'https://lwmfcrafts.net', 'lwmfcrafts.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(142, 1, 'Btwradiovent Media', 'https://btwradiovent.org', 'btwradiovent.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(143, 1, 'Myfavouriteplacesorg Media', 'https://myfavouriteplacesorg.org', 'myfavouriteplacesorg.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(144, 1, 'Healthsciencesforum Media', 'https://healthsciencesforum.co.uk', 'healthsciencesforum.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(145, 1, 'Betterthisfacts Media', 'https://betterthisfacts.com', 'betterthisfacts.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(146, 1, 'Tgagamestick Media', 'https://tgagamestick.co.uk', 'tgagamestick.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(147, 1, 'Whitemagz Media', 'https://whitemagz.net', 'whitemagz.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(148, 1, 'Whitemagz Media', 'https://whitemagz.com', 'whitemagz.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(149, 1, 'Nothing2hidenet Media', 'https://nothing2hidenet.net', 'nothing2hidenet.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(150, 1, 'Lcfmodgeeks Media', 'https://lcfmodgeeks.org', 'lcfmodgeeks.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(151, 1, 'Kazview Media', 'https://kazview.com', 'kazview.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3500.00, 'USD', 2450.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(152, 1, 'Springhillmedgroup Media', 'https://springhillmedgroup.net', 'springhillmedgroup.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(153, 1, 'Sffarehockey Media', 'https://sffarehockey.com', 'sffarehockey.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(154, 1, 'Webfreen Media', 'https://webfreen.net', 'webfreen.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(155, 1, 'Keloland Media', 'https://keloland.org', 'keloland.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(156, 1, 'Susbluezilla Media', 'https://susbluezilla.org', 'susbluezilla.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(157, 1, 'Bvostfus Media', 'https://bvostfus.org', 'bvostfus.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(158, 1, 'Traveltweaks Media', 'https://traveltweaks.co.uk', 'traveltweaks.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(159, 1, 'Disbusinessfied Media', 'https://disbusinessfied.co.uk', 'disbusinessfied.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(160, 1, 'Myinteriorpalace Media', 'https://myinteriorpalace.co.uk', 'myinteriorpalace.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(161, 1, 'Blueflamepublishing Media', 'https://blueflamepublishing.co.uk', 'blueflamepublishing.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(162, 1, 'Betterthisfactstips Media', 'https://betterthisfactstips.org', 'betterthisfactstips.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3500.00, 'USD', 2450.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(163, 1, 'Qushvolpix Media', 'https://qushvolpix.net', 'qushvolpix.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(164, 1, 'Artthunderonthegulf Media', 'https://artthunderonthegulf.com', 'artthunderonthegulf.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(165, 1, 'Myfastbrokeres Media', 'https://myfastbrokeres.com', 'myfastbrokeres.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(166, 1, 'Mygreenbucks Media', 'https://mygreenbucks.us', 'mygreenbucks.us', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(167, 1, 'Gfxmaker Media', 'https://gfxmaker.co.uk', 'gfxmaker.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(168, 1, 'Logicalshout Media', 'https://logicalshout.org', 'logicalshout.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(169, 1, 'Gamefallout Media', 'https://gamefallout.net', 'gamefallout.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(170, 1, 'Betterthistechsnews Media', 'https://betterthistechsnews.com', 'betterthistechsnews.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(171, 1, 'Homelifestylehub Media', 'https://homelifestylehub.org', 'homelifestylehub.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(172, 1, 'Sffarehockey Media', 'https://sffarehockey.org', 'sffarehockey.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(173, 1, 'Whatutalkingboutwillis Media', 'https://whatutalkingboutwillis.co.uk', 'whatutalkingboutwillis.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(174, 1, 'Uggcontroman Media', 'https://uggcontroman.net', 'uggcontroman.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(175, 1, 'Pboxcomputers Media', 'https://pboxcomputers.com', 'pboxcomputers.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(176, 1, 'Scookievent Media', 'https://scookievent.org', 'scookievent.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48');
INSERT INTO `marketplace_websites` (`id`, `user_id`, `website_name`, `website_url`, `domain`, `da`, `dr`, `traffic`, `traffic_source`, `country`, `language`, `niche`, `guest_post_price`, `currency`, `single_website_price`, `description`, `status`, `is_featured`, `contact_info`, `created_at`, `updated_at`) VALUES
(177, 1, 'Undergrowthgameline Media', 'https://undergrowthgameline.net', 'undergrowthgameline.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(178, 1, 'Carlahallbakes Media', 'https://carlahallbakes.net', 'carlahallbakes.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(179, 1, 'Feedworldtech Media', 'https://feedworldtech.com', 'feedworldtech.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(180, 1, 'Onpresscapital Media', 'https://onpresscapital.us', 'onpresscapital.us', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(181, 1, 'Feedcryptobuzz Media', 'https://feedcryptobuzz.org', 'feedcryptobuzz.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(182, 1, 'Mygardenandpatio Media', 'https://mygardenandpatio.org', 'mygardenandpatio.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(183, 1, 'Techkjb Media', 'https://techkjb.com', 'techkjb.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(184, 1, 'Dsnews Media', 'https://dsnews.co.uk', 'dsnews.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(185, 1, 'Thetanel Media', 'https://thetanel.co.uk', 'thetanel.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(186, 1, 'Doopermagazine Media', 'https://doopermagazine.co.uk', 'doopermagazine.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(187, 1, 'Elevatedmagazines Media', 'https://elevatedmagazines.com', 'elevatedmagazines.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(188, 1, 'Theactionelite Media', 'https://theactionelite.com', 'theactionelite.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(189, 1, 'Bignewsnetwork Media', 'https://bignewsnetwork.com', 'bignewsnetwork.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(190, 1, 'Siit Media', 'https://siit.co', 'siit.co', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 500.00, 'USD', 350.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(191, 1, 'Ukheadlines Media', 'https://ukheadlines.co.uk', 'ukheadlines.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(192, 1, 'Nvtimes Media', 'https://nvtimes.co.uk', 'nvtimes.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(193, 1, 'Latemagazine Media', 'https://latemagazine.com', 'latemagazine.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(194, 1, 'Infoimpact Media', 'https://infoimpact.co.uk', 'infoimpact.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(195, 1, 'Lasermagazine Media', 'https://lasermagazine.co.uk', 'lasermagazine.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(196, 1, 'Funnynameslab Media', 'https://funnynameslab.com', 'funnynameslab.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(197, 1, 'Fantasynamesaura Media', 'https://fantasynamesaura.com', 'fantasynamesaura.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(198, 1, 'Trans4mind Media', 'https://trans4mind.com', 'trans4mind.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(199, 1, 'Dreamwithjeffcom Media', 'https://dreamwithjeffcom.com', 'dreamwithjeffcom.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(200, 1, 'Thelaptopadviser Media', 'https://thelaptopadviser.org', 'thelaptopadviser.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(201, 1, 'Etsgamevent Media', 'https://etsgamevent.net', 'etsgamevent.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(202, 1, 'Alternativewaynet Media', 'https://alternativewaynet.net', 'alternativewaynet.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(203, 1, 'Fapelli Media', 'https://fapelli.net', 'fapelli.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(204, 1, 'Thespoonathletic Media', 'https://thespoonathletic.org', 'thespoonathletic.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(205, 1, 'Heartumental Media', 'https://heartumental.org', 'heartumental.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(206, 1, 'Ftasiaeconomy Media', 'https://ftasiaeconomy.com', 'ftasiaeconomy.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(207, 1, 'Ttweakairline Media', 'https://ttweakairline.com', 'ttweakairline.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(208, 1, 'Businessviral Media', 'https://businessviral.org', 'businessviral.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(209, 1, 'Onpresscapital Media', 'https://onpresscapital.org', 'onpresscapital.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(210, 1, 'Designmode24com Media', 'https://designmode24com.com', 'designmode24com.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(211, 1, 'Bouncemediagroup Media', 'https://bouncemediagroup.org', 'bouncemediagroup.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(212, 1, 'Zero1magazine Media', 'https://zero1magazine.net', 'zero1magazine.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(213, 1, 'Homehearted Media', 'https://homehearted.net', 'homehearted.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(214, 1, 'Etesportech Media', 'https://etesportech.net', 'etesportech.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(215, 1, 'Fpmomtips Media', 'https://fpmomtips.org', 'fpmomtips.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(216, 1, 'Popculturechat Media', 'https://popculturechat.net', 'popculturechat.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(217, 1, 'Homedecorzone Media', 'https://homedecorzone.org', 'homedecorzone.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(218, 1, 'Btwletternews Media', 'https://btwletternews.org', 'btwletternews.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(219, 1, 'Snokid Media', 'https://snokid.org', 'snokid.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(220, 1, 'Startingblockonline Media', 'https://startingblockonline.org', 'startingblockonline.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(221, 1, 'Itwizzydigital Media', 'https://itwizzydigital.org', 'itwizzydigital.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(222, 1, 'Theweeklyhealthiness Media', 'https://theweeklyhealthiness.co', 'theweeklyhealthiness.co', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(223, 1, 'Springhillmedgroup Media', 'https://springhillmedgroup.co.uk', 'springhillmedgroup.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(224, 1, 'Misalignment Media', 'https://misalignment.net', 'misalignment.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(225, 1, 'Whatutalkingboutwilis Media', 'https://whatutalkingboutwilis.org', 'whatutalkingboutwilis.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(226, 1, 'Etruegames Media', 'https://etruegames.org', 'etruegames.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(227, 1, 'Etruesports Media', 'https://etruesports.uk', 'etruesports.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(228, 1, 'Silicon-insider Media', 'https://silicon-insider.net', 'silicon-insider.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(229, 1, 'Cwbiancamarket Media', 'https://cwbiancamarket.org', 'cwbiancamarket.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(230, 1, 'Lwmftravel Media', 'https://lwmftravel.org', 'lwmftravel.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(231, 1, 'Globaltravelmag Media', 'https://globaltravelmag.com', 'globaltravelmag.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 4000.00, 'USD', 2800.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(232, 1, 'Wordlehinttoday Media', 'https://wordlehinttoday.co.uk', 'wordlehinttoday.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(233, 1, 'Imagefaps Media', 'https://imagefaps.co.uk', 'imagefaps.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(234, 1, 'Magicalmagazine Media', 'https://magicalmagazine.com', 'magicalmagazine.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(235, 1, 'Tipsfpmomhacks Media', 'https://tipsfpmomhacks.blog', 'tipsfpmomhacks.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(236, 1, 'Worldoutclass Media', 'https://worldoutclass.com', 'worldoutclass.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(237, 1, 'Techfivers Media', 'https://techfivers.com', 'techfivers.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(238, 1, 'Redandwhitemagzs Media', 'https://redandwhitemagzs.org', 'redandwhitemagzs.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(239, 1, 'Hearthssgaming Media', 'https://hearthssgaming.net', 'hearthssgaming.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(240, 1, 'Theturbogeek Media', 'https://theturbogeek.org', 'theturbogeek.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(241, 1, 'Magazineoutstanders Media', 'https://magazineoutstanders.com', 'magazineoutstanders.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3500.00, 'USD', 2450.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(242, 1, 'Ftasiafinance Media', 'https://ftasiafinance.com', 'ftasiafinance.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(243, 1, 'Worldoutstanders Media', 'https://worldoutstanders.com', 'worldoutstanders.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(244, 1, 'Kazlegacy Media', 'https://kazlegacy.com', 'kazlegacy.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(245, 1, 'Logicshout Media', 'https://logicshout.com', 'logicshout.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(246, 1, 'Thejalbiteblog Media', 'https://thejalbiteblog.com', 'thejalbiteblog.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(247, 1, 'Kazpankey Media', 'https://kazpankey.com', 'kazpankey.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(248, 1, 'Designmode24 Media', 'https://designmode24.info', 'designmode24.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(249, 1, 'Pblemulator Media', 'https://pblemulator.co.uk', 'pblemulator.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(250, 1, 'Betterthiscosmos Media', 'https://betterthiscosmos.org', 'betterthiscosmos.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(251, 1, 'Betterthistechs Media', 'https://betterthistechs.net', 'betterthistechs.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(252, 1, 'Lcfmodgeeks Media', 'https://lcfmodgeeks.net', 'lcfmodgeeks.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(253, 1, 'Theweeklyhealthiness Media', 'https://theweeklyhealthiness.com', 'theweeklyhealthiness.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(254, 1, 'Cyroket2585 Media', 'https://cyroket2585.net', 'cyroket2585.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(255, 1, 'Thunderonthegulf Media', 'https://thunderonthegulf.info', 'thunderonthegulf.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(256, 1, 'Whatmomfound Media', 'https://whatmomfound.com', 'whatmomfound.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(257, 1, 'Ftasiatrading Media', 'https://ftasiatrading.net', 'ftasiatrading.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(258, 1, 'Ustudiobytes Media', 'https://ustudiobytes.net', 'ustudiobytes.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(259, 1, 'Feedcryptobuzz Media', 'https://feedcryptobuzz.net', 'feedcryptobuzz.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(260, 1, 'Susbluezilla Media', 'https://susbluezilla.com', 'susbluezilla.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(261, 1, 'Financeville Media', 'https://financeville.net', 'financeville.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(262, 1, 'Lcfgamevent Media', 'https://lcfgamevent.net', 'lcfgamevent.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(263, 1, 'Ontpeconomy Media', 'https://ontpeconomy.org', 'ontpeconomy.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(264, 1, 'Famousparenting Media', 'https://famousparenting.info', 'famousparenting.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(265, 1, 'Loguytren Media', 'https://loguytren.com', 'loguytren.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(266, 1, 'Teckaya Media', 'https://teckaya.com', 'teckaya.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(267, 1, 'Undergrowthgameline Media', 'https://undergrowthgameline.info', 'undergrowthgameline.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(268, 1, 'Bouncemediagroup Media', 'https://bouncemediagroup.net', 'bouncemediagroup.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(269, 1, 'Jalbiteblog Media', 'https://jalbiteblog.info', 'jalbiteblog.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(270, 1, 'Uggworldtech Media', 'https://uggworldtech.net', 'uggworldtech.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(271, 1, 'Ttweakairline Media', 'https://ttweakairline.net', 'ttweakairline.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(272, 1, 'Hssgamepad Media', 'https://hssgamepad.com', 'hssgamepad.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(273, 1, 'Ththomideas Media', 'https://ththomideas.com', 'ththomideas.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(274, 1, 'Hearthssgaming Media', 'https://hearthssgaming.com', 'hearthssgaming.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(275, 1, 'Thesoundstour Media', 'https://thesoundstour.net', 'thesoundstour.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(276, 1, 'Tportstick Media', 'https://tportstick.com', 'tportstick.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(277, 1, 'Lcftechmods Media', 'https://lcftechmods.com', 'lcftechmods.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(278, 1, 'Pushyourdesign Media', 'https://pushyourdesign.co.uk', 'pushyourdesign.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(279, 1, 'Hometrotters Media', 'https://hometrotters.org', 'hometrotters.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(280, 1, 'Theeurogamers Media', 'https://theeurogamers.com', 'theeurogamers.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(281, 1, 'Truerealtyblogs Media', 'https://truerealtyblogs.com', 'truerealtyblogs.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(282, 1, 'Whatutallkingboutwillis Media', 'https://whatutallkingboutwillis.com', 'whatutallkingboutwillis.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(283, 1, 'Inventivelwmfcrafts Media', 'https://inventivelwmfcrafts.com', 'inventivelwmfcrafts.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(284, 1, 'Theeurogamersonline Media', 'https://theeurogamersonline.com', 'theeurogamersonline.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(285, 1, 'Lwmfpets Media', 'https://lwmfpets.blog', 'lwmfpets.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(286, 1, 'Gardentipsdecoradhouse Media', 'https://gardentipsdecoradhouse.blog', 'gardentipsdecoradhouse.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(287, 1, 'Famousparentingmomlife Media', 'https://famousparentingmomlife.blog', 'famousparentingmomlife.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(288, 1, 'Todaydinehours Media', 'https://todaydinehours.com', 'todaydinehours.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(289, 1, 'Drovenio Media', 'https://drovenio.blog', 'drovenio.blog', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2000.00, 'USD', 1400.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(290, 1, 'Scookievent Media', 'https://scookievent.net', 'scookievent.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(291, 1, 'Theweeklyspooncom Media', 'https://theweeklyspooncom.org', 'theweeklyspooncom.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(292, 1, 'Etrstrading Media', 'https://etrstrading.com', 'etrstrading.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(293, 1, 'Toptenlast Media', 'https://toptenlast.net', 'toptenlast.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(294, 1, 'Btwradiovent Media', 'https://btwradiovent.com', 'btwradiovent.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(295, 1, 'Zeromagtech Media', 'https://zeromagtech.com', 'zeromagtech.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(296, 1, 'Famparentlife Media', 'https://famparentlife.com', 'famparentlife.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(297, 1, 'Techoelite Media', 'https://techoelite.net', 'techoelite.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(298, 1, 'Bottlecrunch Media', 'https://bottlecrunch.net', 'bottlecrunch.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(299, 1, 'Betterthisfacts Media', 'https://betterthisfacts.net', 'betterthisfacts.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(300, 1, 'Ttweakmaps Media', 'https://ttweakmaps.net', 'ttweakmaps.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(301, 1, 'Meetshaxs Media', 'https://meetshaxs.com', 'meetshaxs.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(302, 1, 'Pblinuxgaming Media', 'https://pblinuxgaming.com', 'pblinuxgaming.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(303, 1, 'Btwletternews Media', 'https://btwletternews.com', 'btwletternews.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(304, 1, 'Heartumental Media', 'https://heartumental.net', 'heartumental.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(305, 1, 'Thespoonathletic Media', 'https://thespoonathletic.net', 'thespoonathletic.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(306, 1, 'Whatutalkingboutfamily Media', 'https://whatutalkingboutfamily.info', 'whatutalkingboutfamily.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(307, 1, 'Termclear Media', 'https://termclear.net', 'termclear.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(308, 1, 'Anywherestory Media', 'https://anywherestory.org', 'anywherestory.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(309, 1, 'Fpmomtips Media', 'https://fpmomtips.com', 'fpmomtips.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(310, 1, 'Modernvibemagazine Media', 'https://modernvibemagazine.com', 'modernvibemagazine.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(311, 1, 'Thelaptopadviser Media', 'https://thelaptopadviser.net', 'thelaptopadviser.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(312, 1, 'Traveltweaks Media', 'https://traveltweaks.info', 'traveltweaks.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(313, 1, 'Hssgamestick Media', 'https://hssgamestick.com', 'hssgamestick.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(314, 1, 'Rcsdassk Media', 'https://rcsdassk.net', 'rcsdassk.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(315, 1, 'Thehakegamer Media', 'https://thehakegamer.com', 'thehakegamer.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(316, 1, 'Hernlen Media', 'https://hernlen.com', 'hernlen.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(317, 1, 'Lcfgamenews Media', 'https://lcfgamenews.com', 'lcfgamenews.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(318, 1, 'Ttweakflight Media', 'https://ttweakflight.com', 'ttweakflight.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(319, 1, 'Freewayget Media', 'https://freewayget.net', 'freewayget.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(320, 1, 'Qawermoni Media', 'https://qawermoni.com', 'qawermoni.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(321, 1, 'Zryly Media', 'https://zryly.org', 'zryly.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(322, 1, 'Emberslasvegas Media', 'https://emberslasvegas.net', 'emberslasvegas.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(323, 1, 'Projectrethink Media', 'https://projectrethink.co.uk', 'projectrethink.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(324, 1, 'Zardgadjets Media', 'https://zardgadjets.net', 'zardgadjets.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(325, 1, 'Icostamp Media', 'https://icostamp.net', 'icostamp.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(326, 1, 'Wutawhacks Media', 'https://wutawhacks.com', 'wutawhacks.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(327, 1, 'Pblinuxtech Media', 'https://pblinuxtech.org', 'pblinuxtech.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(328, 1, 'Motoassure Media', 'https://motoassure.net', 'motoassure.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(329, 1, 'Onpresscapital Media', 'https://onpresscapital.com', 'onpresscapital.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(330, 1, 'Jalbitedrinks Media', 'https://jalbitedrinks.com', 'jalbitedrinks.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(331, 1, 'Cwbiancaparenting Media', 'https://cwbiancaparenting.com', 'cwbiancaparenting.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(332, 1, 'Thehaketech Media', 'https://thehaketech.com', 'thehaketech.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(333, 1, 'Decadgarden Media', 'https://decadgarden.net', 'decadgarden.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(334, 1, 'Betterthiscosmos Media', 'https://betterthiscosmos.net', 'betterthiscosmos.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(335, 1, 'Impocoolmom Media', 'https://impocoolmom.com', 'impocoolmom.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(336, 1, 'Cwbiancamarket Media', 'https://cwbiancamarket.com', 'cwbiancamarket.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(337, 1, 'That-bites Media', 'https://that-bites.com', 'that-bites.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(338, 1, 'Zeromaggaming Media', 'https://zeromaggaming.com', 'zeromaggaming.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(339, 1, 'Etesportech Media', 'https://etesportech.com', 'etesportech.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(340, 1, 'Onpresscapital Media', 'https://onpresscapital.net', 'onpresscapital.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(341, 1, 'Trendspotmagazine Media', 'https://trendspotmagazine.com', 'trendspotmagazine.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(342, 1, 'Roarleveraging Media', 'https://roarleveraging.net', 'roarleveraging.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(343, 1, 'Wutawhelp Media', 'https://wutawhelp.net', 'wutawhelp.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(344, 1, 'Lwmftravel Media', 'https://lwmftravel.com', 'lwmftravel.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(345, 1, 'G15gadjets Media', 'https://g15gadjets.com', 'g15gadjets.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(346, 1, 'Jivbcqu Media', 'https://jivbcqu.com', 'jivbcqu.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(347, 1, 'Munjoff1445 Media', 'https://munjoff1445.org', 'munjoff1445.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(348, 1, 'Conversationswithbianca Media', 'https://conversationswithbianca.net', 'conversationswithbianca.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(349, 1, 'Solarhomepower Media', 'https://solarhomepower.org', 'solarhomepower.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(350, 1, 'Jalbiteworldfood Media', 'https://jalbiteworldfood.com', 'jalbiteworldfood.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(351, 1, 'Playbattlesquare Media', 'https://playbattlesquare.org', 'playbattlesquare.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(352, 1, 'Exploreyouraim Media', 'https://exploreyouraim.com', 'exploreyouraim.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48');
INSERT INTO `marketplace_websites` (`id`, `user_id`, `website_name`, `website_url`, `domain`, `da`, `dr`, `traffic`, `traffic_source`, `country`, `language`, `niche`, `guest_post_price`, `currency`, `single_website_price`, `description`, `status`, `is_featured`, `contact_info`, `created_at`, `updated_at`) VALUES
(353, 1, 'Mygamerank Media', 'https://mygamerank.org', 'mygamerank.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(354, 1, 'Flpmarkable Media', 'https://flpmarkable.info', 'flpmarkable.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(355, 1, 'Altwaygamers Media', 'https://altwaygamers.com', 'altwaygamers.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(356, 1, 'Crewdisquantified Media', 'https://crewdisquantified.com', 'crewdisquantified.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(357, 1, 'Kdarchistyle Media', 'https://kdarchistyle.com', 'kdarchistyle.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(358, 1, 'Cwbiancavoyage Media', 'https://cwbiancavoyage.net', 'cwbiancavoyage.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(359, 1, 'Shopnaclo Media', 'https://shopnaclo.info', 'shopnaclo.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(360, 1, 'Gamificationsummit Media', 'https://gamificationsummit.info', 'gamificationsummit.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(361, 1, 'Btwletternews Media', 'https://btwletternews.net', 'btwletternews.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(362, 1, 'Dailyvisitmagazine Media', 'https://dailyvisitmagazine.com', 'dailyvisitmagazine.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(363, 1, 'Adventuretwo Media', 'https://adventuretwo.org', 'adventuretwo.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(364, 1, 'Feedworldtech Media', 'https://feedworldtech.net', 'feedworldtech.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(365, 1, 'Solarsolutionsystem Media', 'https://solarsolutionsystem.org', 'solarsolutionsystem.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(366, 1, 'Shortengine Media', 'https://shortengine.net', 'shortengine.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(367, 1, 'Tgarchivegaming Media', 'https://tgarchivegaming.com', 'tgarchivegaming.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(368, 1, 'Llbloghome Media', 'https://llbloghome.com', 'llbloghome.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(369, 1, 'Justalittlebite Media', 'https://justalittlebite.info', 'justalittlebite.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(370, 1, 'Harmonicodecom Media', 'https://harmonicodecom.com', 'harmonicodecom.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(371, 1, 'Abithelp Media', 'https://abithelp.org', 'abithelp.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(372, 1, 'Famousparenting Media', 'https://famousparenting.net', 'famousparenting.net', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 3000.00, 'USD', 2100.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(373, 1, 'Skipthegerms Media', 'https://skipthegerms.org', 'skipthegerms.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(374, 1, 'Dailyjoytips Media', 'https://dailyjoytips.com', 'dailyjoytips.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(375, 1, 'Traveltweakshotels Media', 'https://traveltweakshotels.info', 'traveltweakshotels.info', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(376, 1, 'Globalifestories Media', 'https://globalifestories.com', 'globalifestories.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(377, 1, 'Fpmomlife Media', 'https://fpmomlife.co.uk', 'fpmomlife.co.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 1500.00, 'USD', 1050.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(378, 1, 'Babesproduct Media', 'https://babesproduct.org', 'babesproduct.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(379, 1, 'Pushyourdesign Media', 'https://pushyourdesign.org.uk', 'pushyourdesign.org.uk', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(380, 1, 'Thedisquantified Media', 'https://thedisquantified.org', 'thedisquantified.org', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(381, 1, 'Thelyncconf Media', 'https://thelyncconf.com', 'thelyncconf.com', 30, 40, 20000, 'Organic Search', 'United States', 'English', 'General', 2500.00, 'USD', 1750.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-08 13:18:48', '2026-09-08 13:18:48'),
(382, 1, 'Healthlinepulse Media', 'https://healthlinepulse.org', 'healthlinepulse.org', 64, 68, 185000, 'Organic Search', 'US', 'English', 'Health', 95.00, 'USD', 50.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-09 06:17:30', '2026-09-09 06:17:30'),
(383, 1, 'Financenode Media', 'https://financenode.net', 'financenode.net', 58, 62, 92000, 'Organic Search', 'UK', 'English', 'Finance', 85.00, 'USD', 45.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-09 06:17:30', '2026-09-09 06:17:30'),
(384, 1, 'Saasgrowthblog Media', 'https://saasgrowthblog.io', 'saasgrowthblog.io', 69, 71, 210000, 'Organic Search', 'CA', 'English', 'SaaS', 110.00, 'USD', 60.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-09 06:17:30', '2026-09-09 06:17:30'),
(385, 1, 'Traveltheworld Media', 'https://traveltheworld.co', 'traveltheworld.co', 55, 60, 150000, 'Organic Search', 'AU', 'English', 'Travel', 80.00, 'USD', 40.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-09 06:17:30', '2026-09-09 06:17:30'),
(386, 1, 'Biznewsdaily Media', 'https://biznewsdaily.com', 'biznewsdaily.com', 66, 69, 175000, 'Organic Search', 'US', 'English', 'Business', 100.00, 'USD', 55.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-09 06:17:30', '2026-09-09 06:17:30'),
(387, 1, 'Marketingdigest Media', 'https://marketingdigest.com', 'marketingdigest.com', 63, 65, 120000, 'Organic Search', 'US', 'English', 'Marketing', 90.00, 'USD', 50.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-09 06:17:30', '2026-09-09 06:17:30'),
(389, 1, 'Wellnessjournal Media', 'https://wellnessjournal.org', 'wellnessjournal.org', 61, 63, 110000, 'Organic Search', 'US', 'English', 'Health', 85.00, 'USD', 45.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-09 06:17:30', '2026-09-09 06:17:30'),
(390, 1, 'Realestatetrends Media', 'https://realestatetrends.net', 'realestatetrends.net', 62, 66, 140000, 'Organic Search', 'US', 'English', 'Real Estate', 95.00, 'USD', 55.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-09 06:17:30', '2026-09-09 06:17:30'),
(391, 1, 'Eduinsighthub Media', 'https://eduinsighthub.com', 'eduinsighthub.com', 57, 59, 90000, 'Organic Search', 'UK', 'English', 'Education', 75.00, 'USD', 40.00, 'Standard editorial guidelines apply.', 'active', 0, '', '2026-09-09 06:17:30', '2026-09-09 06:17:30'),
(1, 3, 'TechCrunchy Global', 'https://techcrunchy.com', 'techcrunchy.com', 74, 76, 320000, 'Organic Search', 'United States', 'English', 'Technology', 140.00, 'USD', 95.00, 'Premier tech news and SaaS software reviews blog.', 'active', 0, NULL, '2026-09-29 10:35:42', '2026-09-29 10:35:42'),
(2, 3, 'Finance Savvy Hub', 'https://financesavvy.org', 'financesavvy.org', 68, 71, 185000, 'Organic Search', 'United States', 'English', 'Finance & Crypto', 195.00, 'USD', 130.00, 'Authoritative personal finance, cryptocurrency, and investing portal.', 'active', 0, NULL, '2026-09-29 10:35:42', '2026-09-29 10:35:42'),
(3, 3, 'Healthy Living Digest', 'https://healthydigest.net', 'healthydigest.net', 62, 65, 95000, 'Organic Search', 'United States', 'English', 'Health & Wellness', 110.00, 'USD', 75.00, 'Wellness, biohacking, and holistic lifestyle magazine.', 'active', 0, NULL, '2026-09-29 10:35:42', '2026-09-29 10:35:42');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_code` varchar(50) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `website_id` int(11) DEFAULT NULL,
  `service_type` enum('guest_post','link_insertion') NOT NULL DEFAULT 'guest_post',
  `article_title` varchar(255) DEFAULT NULL,
  `article_body` longtext DEFAULT NULL,
  `target_url` varchar(500) NOT NULL,
  `anchor_text` varchar(255) NOT NULL,
  `link_type` enum('dofollow','nofollow') NOT NULL DEFAULT 'dofollow',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `platform_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `publisher_payout` decimal(10,2) NOT NULL DEFAULT 0.00,
  `published_url` varchar(500) DEFAULT NULL,
  `link_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`verification_details`)),
  `status` enum('pending','accepted','submitted','verified','completed','cancelled','disputed') NOT NULL DEFAULT 'pending',
  `deadline_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otp_verifications`
--

CREATE TABLE `otp_verifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `otp_code` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pages`
--

CREATE TABLE `pages` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` longtext DEFAULT NULL,
  `custom_html` longtext DEFAULT NULL,
  `custom_css` longtext DEFAULT NULL,
  `custom_js` longtext DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `canonical_url` varchar(255) DEFAULT NULL,
  `publish_date` date NOT NULL,
  `status` enum('published','draft') NOT NULL DEFAULT 'published',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pages`
--

INSERT INTO `pages` (`id`, `title`, `slug`, `content`, `custom_html`, `custom_css`, `custom_js`, `meta_title`, `meta_description`, `canonical_url`, `publish_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Zaman lashari', 'zaman', '', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <title>Zaman | Web Developer</title>\n\n    <link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n\n    <header class=\"header\">\n        <a href=\"#\" class=\"logo\">Zaman<span>.</span></a>\n\n        <nav class=\"nav\">\n            <a href=\"#home\">Home</a>\n            <a href=\"#about\">About</a>\n            <a href=\"#skills\">Skills</a>\n            <a href=\"#contact\">Contact</a>\n        </nav>\n\n        <button class=\"menu-btn\" id=\"menuBtn\">☰</button>\n    </header>\n\n    <main>\n\n        <!-- Hero -->\n        <section class=\"hero\" id=\"home\">\n            <div class=\"hero-content\">\n                <p class=\"tag\">WEB DEVELOPER</p>\n\n                <h1>\n                    Hi, I\'m <span>Zaman</span>\n                </h1>\n\n                <p class=\"description\">\n                    I build modern, responsive and user-friendly websites\n                    using clean and efficient web technologies.\n                </p>\n\n                <div class=\"buttons\">\n                    <a href=\"#contact\" class=\"btn primary\">Hire Me</a>\n                    <a href=\"#skills\" class=\"btn secondary\">My Skills</a>\n                </div>\n            </div>\n\n            <div class=\"profile-card\">\n                <div class=\"avatar\">Z</div>\n                <h2>Zaman</h2>\n                <p>Web Developer</p>\n\n                <div class=\"status\">\n                    <span></span> Available for work\n                </div>\n            </div>\n        </section>\n\n        <!-- About -->\n        <section class=\"section\" id=\"about\">\n            <p class=\"section-label\">ABOUT ME</p>\n            <h2>Building websites that work.</h2>\n\n            <p class=\"about-text\">\n                I\'m a web developer focused on creating clean, responsive\n                and practical websites. I work with frontend technologies\n                as well as PHP and MySQL for dynamic web applications.\n            </p>\n        </section>\n\n        <!-- Skills -->\n        <section class=\"section\" id=\"skills\">\n            <p class=\"section-label\">MY SKILLS</p>\n            <h2>Technologies I use</h2>\n\n            <div class=\"skills\">\n                <div class=\"skill\">HTML5</div>\n                <div class=\"skill\">CSS3</div>\n                <div class=\"skill\">JavaScript</div>\n                <div class=\"skill\">PHP</div>\n                <div class=\"skill\">MySQL</div>\n                <div class=\"skill\">Responsive Design</div>\n            </div>\n        </section>\n\n        <!-- Contact -->\n        <section class=\"section contact\" id=\"contact\">\n            <p class=\"section-label\">CONTACT</p>\n            <h2>Let\'s work together.</h2>\n\n            <p>\n                Have a project in mind? Get in touch and let\'s discuss it.\n            </p>\n\n            <a href=\"mailto:your@email.com\" class=\"btn primary\">\n                Contact Me\n            </a>\n        </section>\n\n    </main>\n\n    <footer>\n        <p>© <span id=\"year\"></span> Zaman. All rights reserved.</p>\n    </footer>\n\n    <script src=\"script.js\"></script>\n</body>\n</html>', '* {\n    margin: 0;\n    padding: 0;\n    box-sizing: border-box;\n}\n\nhtml {\n    scroll-behavior: smooth;\n}\n\nbody {\n    font-family: Arial, Helvetica, sans-serif;\n    background: #f8fafc;\n    color: #0f172a;\n    line-height: 1.6;\n}\n\n.header {\n    width: 100%;\n    max-width: 1100px;\n    margin: auto;\n    padding: 22px 25px;\n\n    display: flex;\n    align-items: center;\n    justify-content: space-between;\n}\n\n.logo {\n    font-size: 25px;\n    font-weight: 800;\n    color: #0f172a;\n    text-decoration: none;\n}\n\n.logo span {\n    color: #2563eb;\n}\n\n.nav {\n    display: flex;\n    gap: 28px;\n}\n\n.nav a {\n    color: #475569;\n    text-decoration: none;\n    font-size: 14px;\n    font-weight: 600;\n    transition: 0.3s;\n}\n\n.nav a:hover {\n    color: #2563eb;\n}\n\n.menu-btn {\n    display: none;\n    border: 0;\n    background: transparent;\n    font-size: 25px;\n    cursor: pointer;\n}\n\n/* Hero */\n\n.hero {\n    max-width: 1100px;\n    min-height: 620px;\n    margin: auto;\n    padding: 70px 25px;\n\n    display: flex;\n    align-items: center;\n    justify-content: space-between;\n    gap: 60px;\n}\n\n.hero-content {\n    max-width: 650px;\n}\n\n.tag,\n.section-label {\n    color: #2563eb;\n    font-size: 12px;\n    font-weight: 800;\n    letter-spacing: 2px;\n    margin-bottom: 12px;\n}\n\n.hero h1 {\n    font-size: clamp(45px, 7vw, 75px);\n    line-height: 1.05;\n    margin-bottom: 22px;\n}\n\n.hero h1 span {\n    color: #2563eb;\n}\n\n.description {\n    max-width: 560px;\n    color: #64748b;\n    font-size: 18px;\n}\n\n.buttons {\n    display: flex;\n    gap: 12px;\n    margin-top: 30px;\n}\n\n.btn {\n    display: inline-block;\n    padding: 12px 22px;\n    border-radius: 8px;\n    text-decoration: none;\n    font-weight: 700;\n    font-size: 14px;\n    transition: 0.3s;\n}\n\n.primary {\n    background: #2563eb;\n    color: white;\n}\n\n.primary:hover {\n    background: #1d4ed8;\n    transform: translateY(-2px);\n}\n\n.secondary {\n    border: 1px solid #cbd5e1;\n    color: #334155;\n}\n\n.secondary:hover {\n    border-color: #2563eb;\n    color: #2563eb;\n}\n\n/* Profile Card */\n\n.profile-card {\n    width: 280px;\n    padding: 35px 25px;\n    text-align: center;\n\n    background: white;\n    border: 1px solid #e2e8f0;\n    border-radius: 20px;\n\n    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);\n}\n\n.avatar {\n    width: 100px;\n    height: 100px;\n    margin: auto auto 18px;\n\n    display: flex;\n    align-items: center;\n    justify-content: center;\n\n    border-radius: 50%;\n    background: #2563eb;\n    color: white;\n\n    font-size: 40px;\n    font-weight: 800;\n}\n\n.profile-card h2 {\n    font-size: 23px;\n}\n\n.profile-card p {\n    color: #64748b;\n}\n\n.status {\n    margin-top: 20px;\n    padding: 8px;\n    background: #f1f5f9;\n    border-radius: 7px;\n    font-size: 12px;\n    color: #475569;\n}\n\n.status span {\n    width: 7px;\n    height: 7px;\n    display: inline-block;\n\n    background: #22c55e;\n    border-radius: 50%;\n    margin-right: 5px;\n}\n\n/* Sections */\n\n.section {\n    max-width: 900px;\n    margin: auto;\n    padding: 90px 25px;\n}\n\n.section h2 {\n    font-size: 38px;\n    margin-bottom: 20px;\n}\n\n.about-text {\n    max-width: 700px;\n    color: #64748b;\n    font-size: 17px;\n}\n\n.skills {\n    margin-top: 30px;\n\n    display: flex;\n    flex-wrap: wrap;\n    gap: 12px;\n}\n\n.skill {\n    padding: 11px 17px;\n    background: white;\n    border: 1px solid #e2e8f0;\n    border-radius: 8px;\n    color: #334155;\n    font-size: 14px;\n    font-weight: 600;\n}\n\n.contact {\n    text-align: center;\n}\n\n.contact > p:not(.section-label) {\n    color: #64748b;\n    margin-bottom: 25px;\n}\n\n/* Footer */\n\nfooter {\n    padding: 30px 20px;\n    text-align: center;\n    border-top: 1px solid #e2e8f0;\n    color: #64748b;\n    font-size: 13px;\n}\n\n/* Mobile */\n\n@media (max-width: 700px) {\n\n    .header {\n        position: relative;\n    }\n\n    .nav {\n        display: none;\n\n        position: absolute;\n        top: 70px;\n        left: 20px;\n        right: 20px;\n\n        padding: 20px;\n        flex-direction: column;\n        gap: 18px;\n\n        background: white;\n        border: 1px solid #e2e8f0;\n        border-radius: 12px;\n\n        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);\n    }\n\n    .nav.active {\n        display: flex;\n    }\n\n    .menu-btn {\n        display: block;\n    }\n\n    .hero {\n        min-height: auto;\n        padding-top: 60px;\n        padding-bottom: 70px;\n\n        flex-direction: column;\n        align-items: flex-start;\n    }\n\n    .hero h1 {\n        font-size: 48px;\n    }\n\n    .description {\n        font-size: 16px;\n    }\n\n    .profile-card {\n        width: 100%;\n        max-width: 320px;\n        align-self: center;\n    }\n\n    .section {\n        padding: 65px 25px;\n    }\n\n    .section h2 {\n        font-size: 30px;\n    }\n\n    .buttons {\n        flex-wrap: wrap;\n    }\n}', '// Mobile navigation\nconst menuBtn = document.getElementById(\"menuBtn\");\nconst nav = document.querySelector(\".nav\");\n\nmenuBtn.addEventListener(\"click\", () => {\n    nav.classList.toggle(\"active\");\n\n    if (nav.classList.contains(\"active\")) {\n        menuBtn.textContent = \"✕\";\n    } else {\n        menuBtn.textContent = \"☰\";\n    }\n});\n\n// Close mobile menu after clicking a link\ndocument.querySelectorAll(\".nav a\").forEach(link => {\n    link.addEventListener(\"click\", () => {\n        nav.classList.remove(\"active\");\n        menuBtn.textContent = \"☰\";\n    });\n});\n\n// Dynamic year\ndocument.getElementById(\"year\").textContent = new Date().getFullYear();', 'Zaman lashari', '', '', '2026-09-07', 'published', '2026-09-07 05:59:06', '2026-09-07 05:59:06');

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `endpoint` varchar(60) NOT NULL,
  `request_count` int(11) NOT NULL DEFAULT 1,
  `last_request` datetime NOT NULL,
  `reset_time` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`id`, `ip_address`, `endpoint`, `request_count`, `last_request`, `reset_time`) VALUES
(1, '39.34.141.183', 'forgot_password', 1, '2026-09-29 11:40:56', '2026-09-29 11:55:56'),
(2, '39.34.141.183', 'registration', 3, '2026-09-29 11:41:39', '2026-09-29 11:56:37');

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `id` int(11) NOT NULL,
  `setting_group` varchar(50) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` longtext NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `site_settings`
--

INSERT INTO `site_settings` (`id`, `setting_group`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'general', 'site_name', 'MarketMoke - Premium Guest Posting & Link Building Marketplace', '2026-09-29 10:35:42'),
(2, 'general', 'site_tagline', 'High DA/DR Contextual Backlinks with Instant Escrow Protection', '2026-09-29 10:35:42'),
(3, 'general', 'admin_email', 'admin@marketmoke.com', '2026-09-29 10:35:42'),
(4, 'financial', 'platform_fee_percent', '10.00', '2026-09-29 10:35:42'),
(5, 'financial', 'escrow_hold_days', '3', '2026-09-29 10:35:42'),
(6, 'financial', 'default_currency', 'USD', '2026-09-29 10:35:42');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','editor','author','user','publisher') NOT NULL DEFAULT 'user',
  `status` enum('active','inactive','pending') NOT NULL DEFAULT 'active',
  `company` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `status`, `company`, `phone`, `bio`, `balance`, `created_at`) VALUES
(1, 'Admin Manager', 'admin@marketmoke.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHeF5bB/Zc9hJb.KqBvY5w2K2/U3Qy7q6e', 'admin', 'active', 'MarketMoke HQ', '+1 (555) 019-2834', 'Lead Administrator and Platform Supervisor.', 0.00, '2026-09-29 10:35:42'),
(2, 'Michael Client', 'client@marketmoke.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHeF5bB/Zc9hJb.KqBvY5w2K2/U3Qy7q6e', 'user', 'active', 'Apex Digital Agency', '+1 (555) 321-4567', 'SEO Buyer & Link Builder.', 0.00, '2026-09-29 10:35:42'),
(3, 'Sarah Publisher', 'publisher@marketmoke.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHeF5bB/Zc9hJb.KqBvY5w2K2/U3Qy7q6e', 'publisher', 'active', 'TechCrunchy Media', '+1 (555) 789-0123', 'Verified publisher and site owner.', 0.00, '2026-09-29 10:35:42'),
(4, 'Zaman', 'farhanzama45@gmail.com', '$2y$10$xdF6eMQij1bazzFDaMK1lO4jchm4TVPaKAzqfbarvu25K5lhURFia', 'user', 'active', 'Zaman Agency', NULL, NULL, 0.00, '2026-09-29 10:41:37');

-- --------------------------------------------------------

--
-- Table structure for table `user_edit_requests`
--

CREATE TABLE `user_edit_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL,
  `user_email` varchar(150) NOT NULL,
  `current_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`current_data`)),
  `requested_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`requested_data`)),
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_otps`
--

CREATE TABLE `user_otps` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `otp_code` varchar(255) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `reset_token` varchar(64) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_otps`
--

INSERT INTO `user_otps` (`id`, `user_id`, `email`, `otp_code`, `attempts`, `reset_token`, `expires_at`, `is_used`, `created_at`) VALUES
(1, 4, 'farhanzama45@gmail.com', '$2y$10$R0IlK9fTfOD9b8IADhVsA.53Z8oL5YdGFdT8sixiXei.QaZCKCpQu', 0, NULL, '2026-09-29 12:03:34', 1, '2026-09-29 10:43:34'),
(2, 4, 'farhanzama45@gmail.com', '$2y$10$KKWsSu81UAvE5izC0SpXDuUcCCqBNDvIoDA1JQ4q3U5IWwvdlB.B6', 0, NULL, '2026-09-29 12:05:03', 1, '2026-09-29 10:45:03'),
(3, 4, 'farhanzama45@gmail.com', '$2y$10$zS3udQJWC8CsaGtRAH/ziulKrzFYVtq6tbUwFh637ha5qqPl0PD0G', 0, NULL, '2026-09-29 12:07:11', 1, '2026-09-29 10:47:11'),
(4, 4, 'farhanzama45@gmail.com', '$2y$10$iSj7Kh.I0syVBx4PXfm3FuSaAodWgCUlzibyJW2famY1inxm/.Dqm', 0, NULL, '2026-09-29 12:07:14', 1, '2026-09-29 10:47:14'),
(5, 4, 'farhanzama45@gmail.com', '$2y$10$KsZ86CgZTgVNLGQTcl4Go.IRDWugVz8HHYrKuTwPzi7YRw63gpfuu', 0, NULL, '2026-09-29 12:10:51', 1, '2026-09-29 10:50:51'),
(6, 4, 'farhanzama45@gmail.com', '$2y$10$3NHmPBGR6JFIsHRzd6.vQuMgmEzOuci8P8ciSIrUtMpAJwrfnlQu2', 0, NULL, '2026-09-29 12:13:45', 1, '2026-09-29 10:53:45'),
(7, 4, 'farhanzama45@gmail.com', '$2y$10$EzGu6njUgqwz8OwqPbFRd.i4KiGETSvVzjY57Yl36jwtSFt/qr73.', 0, NULL, '2026-09-29 12:13:47', 1, '2026-09-29 10:53:47'),
(8, 4, 'farhanzama45@gmail.com', '$2y$10$zc/fwe9rSKyHIhJkgiYjNOQ6WC6QZkFMXnrxlx0UgE7HryYpqoYHS', 0, NULL, '2026-09-29 12:13:52', 1, '2026-09-29 10:53:52'),
(9, 4, 'farhanzama45@gmail.com', '$2y$10$SHhl.0N1DaA1Xte4Agmr4eJpuYLwiTdotBFeMjyfLGnJ5NLwaSzsS', 0, NULL, '2026-09-29 12:20:05', 1, '2026-09-29 11:00:05');

-- --------------------------------------------------------

--
-- Table structure for table `user_wallets`
--

CREATE TABLE `user_wallets` (
  `user_id` int(11) NOT NULL,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `escrow_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_wallets`
--

INSERT INTO `user_wallets` (`user_id`, `balance`, `escrow_balance`, `updated_at`) VALUES
(1, 9999.00, 0.00, '2026-09-29 10:35:42'),
(2, 2500.00, 140.00, '2026-09-29 10:35:42'),
(3, 1450.00, 0.00, '2026-09-29 10:35:42');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_automation_settings`
--
ALTER TABLE `ai_automation_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ai_keywords_list`
--
ALTER TABLE `ai_keywords_list`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `normalized_keyword` (`normalized_keyword`),
  ADD KEY `idx_topic_seed_status` (`topic_seed`,`status`),
  ADD KEY `idx_kw_status` (`status`),
  ADD KEY `idx_normalized_keyword` (`normalized_keyword`);

--
-- Indexes for table `ai_published_articles`
--
ALTER TABLE `ai_published_articles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ai_articles_post` (`blog_post_id`),
  ADD KEY `idx_ai_articles_status` (`status`),
  ADD KEY `idx_ai_articles_date` (`schedule_date`),
  ADD KEY `idx_ai_primary_keyword` (`primary_keyword`);

--
-- Indexes for table `blog_categories`
--
ALTER TABLE `blog_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `escrow_transactions`
--
ALTER TABLE `escrow_transactions`
  ADD KEY `idx_escrow_order_status` (`order_id`,`status`),
  ADD KEY `idx_escrow_created_type` (`created_at`,`type`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD KEY `idx_orders_status_users` (`status`,`buyer_id`,`seller_id`),
  ADD KEY `idx_orders_created_status` (`created_at`,`status`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_ip_endpoint` (`ip_address`,`endpoint`),
  ADD KEY `idx_rate_limits_reset` (`reset_time`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_settings_group` (`setting_group`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`);

--
-- Indexes for table `user_edit_requests`
--
ALTER TABLE `user_edit_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_user_edits_status` (`status`);

--
-- Indexes for table `user_otps`
--
ALTER TABLE `user_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_otps_lookup` (`email`,`is_used`,`expires_at`),
  ADD KEY `idx_user_otps_user_id` (`user_id`),
  ADD KEY `idx_otps_lookup` (`email`,`is_used`,`expires_at`);

--
-- Indexes for table `user_wallets`
--
ALTER TABLE `user_wallets`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_wallets_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_keywords_list`
--
ALTER TABLE `ai_keywords_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_published_articles`
--
ALTER TABLE `ai_published_articles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blog_categories`
--
ALTER TABLE `blog_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `site_settings`
--
ALTER TABLE `site_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_edit_requests`
--
ALTER TABLE `user_edit_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_otps`
--
ALTER TABLE `user_otps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `user_edit_requests`
--
ALTER TABLE `user_edit_requests`
  ADD CONSTRAINT `user_edit_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_wallets`
--
ALTER TABLE `user_wallets`
  ADD CONSTRAINT `user_wallets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
