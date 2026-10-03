-- ============================================================================
-- MarketMoke Phase 3: High-Performance Database Indexes & Query Optimization
-- File: schema_indexes.sql
-- Engine: MySQL 8.0+ / MariaDB 10.5+ / AWS Aurora / Google Cloud SQL
-- ============================================================================

USE `marketmoke_db`;

-- ----------------------------------------------------------------------------
-- 1. ORDERS TABLE OPTIMIZATIONS
-- Speeds up Buyer and Publisher dashboard queries filtering by lifecycle state
-- (`pending`, `accepted`, `submitted`, `verified`, `completed`, `cancelled`)
-- alongside buyer_id and seller_id. Eliminates filesorts and temporary tables.
-- ----------------------------------------------------------------------------
ALTER TABLE `orders`
  ADD INDEX `idx_orders_status_users` (`status`, `buyer_id`, `seller_id`),
  ADD INDEX `idx_orders_created_status` (`created_at`, `status`);

-- ----------------------------------------------------------------------------
-- 2. ESCROW TRANSACTIONS TABLE OPTIMIZATIONS
-- Speeds up atomic fund release and refund audits by checking both order_id
-- and escrow hold status simultaneously during payment settlements.
-- ----------------------------------------------------------------------------
ALTER TABLE `escrow_transactions`
  ADD INDEX `idx_escrow_order_status` (`order_id`, `status`),
  ADD INDEX `idx_escrow_created_type` (`created_at`, `type`);

-- ----------------------------------------------------------------------------
-- 3. USER OTPS TABLE OPTIMIZATIONS
-- Optimizes high-throughput OTP lookups during passwordless authentication
-- and login verification by indexing email, single-use state, and expiration.
-- ----------------------------------------------------------------------------
ALTER TABLE `user_otps`
  ADD INDEX `idx_otps_lookup` (`email`, `is_used`, `expires_at`);

-- ----------------------------------------------------------------------------
-- 4. USER WALLETS TABLE OPTIMIZATIONS
-- Enhances lookup speeds when querying user balances, pessimistic row-level
-- locks (SELECT ... FOR UPDATE), and concurrent transaction verification.
-- ----------------------------------------------------------------------------
ALTER TABLE `user_wallets`
  ADD INDEX `idx_wallets_user` (`user_id`);

-- ----------------------------------------------------------------------------
-- 5. INVOICES & REVENUE AUDIT OPTIMIZATIONS
-- Accelerates invoice status filtering (Unpaid, Paid, Cancelled) by due date
-- and instant lookups by invoice number during gateway webhook callbacks.
-- ----------------------------------------------------------------------------
ALTER TABLE `invoices`
  ADD INDEX `idx_invoices_status_date` (`status`, `invoice_date`),
  ADD INDEX `idx_invoices_number` (`invoice_number`),
  ADD INDEX `idx_invoices_tx_hash` (`tx_hash`(32));

-- ----------------------------------------------------------------------------
-- 6. MARKETPLACE WEBSITES SEARCH & FILTERING OPTIMIZATIONS
-- Accelerates multi-filter catalog queries combining active status, niche,
-- DA/DR metrics, and guest post price ranges.
-- ----------------------------------------------------------------------------
ALTER TABLE `marketplace_websites`
  ADD INDEX `idx_mkt_search_composite` (`status`, `niche`, `guest_post_price`, `da`),
  ADD INDEX `idx_mkt_featured_status` (`is_featured`, `status`);

-- ----------------------------------------------------------------------------
-- 7. SECURITY & ACTIVITY AUDIT TRAIL OPTIMIZATIONS
-- Accelerates brute-force rate-limiting checks and administrative security log
-- reviews by user and timestamp.
-- ----------------------------------------------------------------------------
ALTER TABLE `rate_limits`
  ADD INDEX `idx_rate_limits_lookup` (`ip_address`, `endpoint`, `reset_time`);

ALTER TABLE `activity_logs`
  ADD INDEX `idx_activity_perf` (`action`, `created_at`);
