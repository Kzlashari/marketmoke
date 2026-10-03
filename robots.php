<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/seo.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
$stored = getSiteSettings()['seo_status'] ?? [];
$config = seoConfig();
echo empty($config['robots']['auto_generate']) && !empty($stored['robots_snapshot']) ? $stored['robots_snapshot'] : seoBuildRobots();
