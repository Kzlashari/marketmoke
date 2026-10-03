-- Marketplace is the only inventory source for Admin and public Browse Websites.
-- Run once on managed databases before deploying the public catalog endpoint.
ALTER TABLE marketplace_websites
  ADD COLUMN IF NOT EXISTS link_type ENUM('dofollow', 'nofollow') NOT NULL DEFAULT 'dofollow' AFTER single_website_price,
  ADD COLUMN IF NOT EXISTS is_sponsored TINYINT(1) NOT NULL DEFAULT 0 AFTER link_type,
  ADD COLUMN IF NOT EXISTS turnaround_days SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER is_sponsored;

CREATE INDEX IF NOT EXISTS idx_mkt_public_status_id ON marketplace_websites (status, id);
CREATE INDEX IF NOT EXISTS idx_mkt_public_status_da ON marketplace_websites (status, da, id);
CREATE INDEX IF NOT EXISTS idx_mkt_public_status_dr ON marketplace_websites (status, dr, id);
CREATE INDEX IF NOT EXISTS idx_mkt_public_status_traffic ON marketplace_websites (status, traffic, id);
CREATE INDEX IF NOT EXISTS idx_mkt_public_status_price ON marketplace_websites (status, guest_post_price, id);
CREATE INDEX IF NOT EXISTS idx_mkt_public_status_country ON marketplace_websites (status, country, id);
CREATE INDEX IF NOT EXISTS idx_mkt_public_status_niche ON marketplace_websites (status, niche, id);
CREATE INDEX IF NOT EXISTS idx_mkt_public_status_language ON marketplace_websites (status, language, id);
CREATE INDEX IF NOT EXISTS idx_mkt_public_status_link ON marketplace_websites (status, link_type, id);
CREATE FULLTEXT INDEX idx_mkt_public_search ON marketplace_websites (website_name, domain, niche, description);
