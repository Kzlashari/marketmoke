<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/seo.php';
header('Content-Type: application/xml; charset=utf-8');
try {
    $stored = getSiteSettings()['seo_status'] ?? [];
    $config = seoConfig();
    if (empty($config['sitemap']['auto_generate']) && !empty($stored['sitemap_snapshot']) && empty($_GET['part'])) { echo $stored['sitemap_snapshot']; exit; }
    $urls = seoSitemapUrls(Database::getInstance()->getConnection());
    $chunkSize = $config['sitemap']['chunk_size'];
    $part = max(0, (int)($_GET['part'] ?? 0));
    if (count($urls) > $chunkSize && $part === 0) {
        $locations = [];
        foreach (array_chunk($urls, $chunkSize) as $index => $_) $locations[] = seoUrl('/sitemap-' . ($index + 1) . '.xml');
        echo seoSitemapIndexXml($locations);
    } else echo seoSitemapXml($part > 0 ? (array_chunk($urls, $chunkSize)[$part - 1] ?? []) : $urls);
} catch (Throwable $e) {
    error_log('[SEO sitemap endpoint] ' . $e->getMessage()); http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
}
