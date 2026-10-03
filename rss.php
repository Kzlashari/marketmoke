<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/seo.php';
header('Content-Type: application/rss+xml; charset=utf-8');
try { $stored = getSiteSettings()['seo_status'] ?? []; $config = seoConfig(); echo empty($config['rss']['auto_update']) && !empty($stored['rss_snapshot']) ? $stored['rss_snapshot'] : seoBuildRss(Database::getInstance()->getConnection()); }
catch (Throwable $e) { error_log('[SEO RSS endpoint] ' . $e->getMessage()); http_response_code(500); echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Feed unavailable</title></channel></rss>'; }
