<?php
/** Shared, public-safe SEO configuration and dynamic document builders. */
require_once __DIR__ . '/site_settings.php';

if (!function_exists('seoDefaults')) {
    function seoDefaults(): array {
        $url = defined('APP_URL') ? APP_URL : '/';
        return [
            'seo' => ['base_url' => $url, 'default_meta_title' => 'MarketMoke - Guest Post Marketplace', 'default_meta_description' => '', 'robots_meta' => 'index, follow', 'og_title' => '', 'og_description' => '', 'og_image' => '', 'twitter_title' => '', 'twitter_description' => '', 'twitter_image' => '', 'google_search_console_code' => '', 'google_analytics_id' => '', 'google_tag_manager_id' => '', 'bing_webmaster_code' => ''],
            'robots' => ['mode' => 'generated', 'enabled' => true, 'auto_generate' => true, 'content' => '', 'custom_rules' => ''],
            'sitemap' => ['enabled' => true, 'auto_generate' => true, 'include_marketplace' => true, 'chunk_size' => 45000],
            'rss' => ['enabled' => true, 'auto_update' => true, 'title' => 'MarketMoke Blog', 'description' => 'Latest MarketMoke articles and insights.', 'item_count' => 20],
            'schema' => ['enabled' => true, 'organization_name' => 'MarketMoke', 'logo_url' => '', 'description' => '', 'email' => '', 'phone' => '', 'same_as' => [], 'business_type' => 'Organization', 'custom_schemas' => []],
            'custom_code' => ['custom_head_code' => '', 'custom_body_code' => ''],
        ];
    }
}

if (!function_exists('seoConfig')) {
    function seoConfig(): array {
        $raw = getSiteSettings(); $config = seoDefaults();
        foreach (['seo', 'robots', 'sitemap', 'rss', 'schema', 'custom_code'] as $name) $config[$name] = array_replace($config[$name], is_array($raw[$name . '_settings'] ?? null) ? $raw[$name . '_settings'] : []);
        $config['seo']['base_url'] = rtrim(filter_var($config['seo']['base_url'], FILTER_VALIDATE_URL) ? $config['seo']['base_url'] : APP_URL, '/');
        $config['sitemap']['chunk_size'] = max(1, min(50000, (int)$config['sitemap']['chunk_size']));
        $config['rss']['item_count'] = max(1, min(100, (int)$config['rss']['item_count'])); return $config;
    }
}
if (!function_exists('seoEscapeXml')) { function seoEscapeXml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('seoUrl')) { function seoUrl(string $path = '/'): string { return rtrim(seoConfig()['seo']['base_url'], '/') . '/' . ltrim($path, '/'); } }
if (!function_exists('seoCanonical')) { function seoCanonical(string $path, string $override = ''): string { $path = preg_replace('#/index\.php$#i', '/', '/' . ltrim($path, '/')); $fallback = seoUrl($path); return ($override !== '' && filter_var($override, FILTER_VALIDATE_URL) && parse_url($override, PHP_URL_HOST) === parse_url(seoUrl('/'), PHP_URL_HOST)) ? $override : $fallback; } }

if (!function_exists('seoBuildRobots')) {
    function seoBuildRobots(): string {
        $robots = seoConfig()['robots']; if (empty($robots['enabled'])) return "User-agent: *\nDisallow: /\n";
        if (($robots['mode'] ?? 'generated') === 'manual' && trim((string)$robots['content']) !== '') return rtrim((string)$robots['content']) . "\n";
        $rules = ["User-agent: *", "Allow: /", '', 'Disallow: /admin/', 'Disallow: /api/', 'Disallow: /auth/', 'Disallow: /user/', 'Disallow: /dashboard', 'Disallow: /author-dashboard.php', 'Disallow: /publisher-dashboard.php', 'Disallow: /user-dashboard.php', 'Disallow: /login.php', 'Disallow: /register.php', 'Disallow: /verify-otp.php'];
        if (trim((string)($robots['custom_rules'] ?? '')) !== '') $rules[] = trim((string)$robots['custom_rules']);
        $rules[] = ''; $rules[] = 'Sitemap: ' . seoUrl('/sitemap.xml'); return implode("\n", $rules) . "\n";
    }
}
if (!function_exists('seoSameSiteCanonical')) { function seoSameSiteCanonical(string $canonical, string $fallback): ?string { return ($canonical === '' || !filter_var($canonical, FILTER_VALIDATE_URL)) ? $fallback : (parse_url($canonical, PHP_URL_HOST) === parse_url(seoUrl('/'), PHP_URL_HOST) ? $canonical : null); } }
if (!function_exists('seoHasTable')) { function seoHasTable(PDO $db, string $table): bool { static $known = []; if (array_key_exists($table, $known)) return $known[$table]; $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $stmt->execute([$table]); return $known[$table] = (bool)$stmt->fetchColumn(); } }

if (!function_exists('seoSitemapUrls')) {
    function seoSitemapUrls(PDO $db): array {
        $config = seoConfig(); if (empty($config['sitemap']['enabled'])) return []; $urls = [];
        $add = static function (array &$target, string $url, ?string $date, string $frequency, string $priority): void { $target[$url] = ['loc' => $url, 'lastmod' => $date, 'changefreq' => $frequency, 'priority' => $priority]; };
        $add($urls, seoUrl('/'), date('Y-m-d'), 'daily', '1.0'); foreach (['/marketplace', '/browse-websites', '/services', '/blog', '/contact'] as $path) $add($urls, seoUrl($path), null, 'weekly', '0.7');
        try {
            if (seoHasTable($db, 'blog_posts')) foreach ($db->query("SELECT slug, canonical_url, updated_at, created_at FROM blog_posts WHERE status = 'published'")->fetchAll(PDO::FETCH_ASSOC) as $post) { $url = seoSameSiteCanonical(trim((string)$post['canonical_url']), seoUrl('/blog/' . rawurlencode($post['slug']))); if ($url) $add($urls, $url, substr((string)($post['updated_at'] ?: $post['created_at']), 0, 10), 'weekly', '0.8'); }
            if (seoHasTable($db, 'pages')) foreach ($db->query("SELECT slug, canonical_url, updated_at, created_at FROM pages WHERE status = 'published'")->fetchAll(PDO::FETCH_ASSOC) as $page) { $url = seoSameSiteCanonical(trim((string)$page['canonical_url']), seoUrl('/' . rawurlencode($page['slug']))); if ($url) $add($urls, $url, substr((string)($page['updated_at'] ?: $page['created_at']), 0, 10), 'monthly', '0.7'); }
        } catch (Throwable $e) { error_log('[SEO sitemap] ' . $e->getMessage()); }
        return array_values($urls);
    }
}
if (!function_exists('seoSitemapXml')) {
    function seoSitemapXml(array $urls): string { $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']; foreach ($urls as $url) { $xml[] = '  <url>'; $xml[] = '    <loc>' . seoEscapeXml($url['loc']) . '</loc>'; if (!empty($url['lastmod'])) $xml[] = '    <lastmod>' . seoEscapeXml($url['lastmod']) . '</lastmod>'; $xml[] = '    <changefreq>' . seoEscapeXml($url['changefreq']) . '</changefreq>'; $xml[] = '    <priority>' . seoEscapeXml($url['priority']) . '</priority>'; $xml[] = '  </url>'; } $xml[] = '</urlset>'; return implode("\n", $xml) . "\n"; }
}
if (!function_exists('seoSitemapIndexXml')) {
    function seoSitemapIndexXml(array $locations): string { $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']; foreach ($locations as $location) $xml[] = '  <sitemap><loc>' . seoEscapeXml($location) . '</loc><lastmod>' . date('Y-m-d') . '</lastmod></sitemap>'; $xml[] = '</sitemapindex>'; return implode("\n", $xml) . "\n"; }
}
if (!function_exists('seoBuildRss')) {
    function seoBuildRss(PDO $db): string {
        $rss = seoConfig()['rss']; $title = trim((string)$rss['title']) ?: 'MarketMoke Blog'; $description = trim((string)$rss['description']) ?: 'Latest MarketMoke articles and insights.'; $items = [];
        if (!empty($rss['enabled']) && seoHasTable($db, 'blog_posts')) try { $stmt = $db->prepare("SELECT title, slug, content, meta_description, author, publish_date, updated_at, created_at FROM blog_posts WHERE status = 'published' ORDER BY publish_date DESC, id DESC LIMIT :limit"); $stmt->bindValue(':limit', (int)$rss['item_count'], PDO::PARAM_INT); $stmt->execute(); $items = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { error_log('[SEO RSS] ' . $e->getMessage()); }
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">', '<channel>', '<title>' . seoEscapeXml($title) . '</title>', '<link>' . seoEscapeXml(seoUrl('/')) . '</link>', '<description>' . seoEscapeXml($description) . '</description>', '<atom:link href="' . seoEscapeXml(seoUrl('/rss.xml')) . '" rel="self" type="application/rss+xml"/>'];
        foreach ($items as $item) { $link = seoCanonical('/blog/' . rawurlencode($item['slug'])); $desc = trim((string)($item['meta_description'] ?: strip_tags($item['content']))); $date = $item['updated_at'] ?: ($item['publish_date'] ?: $item['created_at']); $xml[] = '<item><title>' . seoEscapeXml($item['title']) . '</title><link>' . seoEscapeXml($link) . '</link><guid isPermaLink="true">' . seoEscapeXml($link) . '</guid><description>' . seoEscapeXml(mb_substr($desc, 0, 500)) . '</description><author>' . seoEscapeXml((string)$item['author']) . '</author><pubDate>' . gmdate(DATE_RSS, strtotime((string)$date)) . '</pubDate></item>'; }
        $xml[] = '</channel>'; $xml[] = '</rss>'; return implode("\n", $xml) . "\n";
    }
}
if (!function_exists('seoBuildSchemas')) {
    function seoBuildSchemas(string $pageType, string $canonicalUrl, array $entity = []): array {
        $schema = seoConfig()['schema']; if (empty($schema['enabled'])) return []; $orgName = trim((string)$schema['organization_name']) ?: 'MarketMoke';
        $organization = ['@context' => 'https://schema.org', '@type' => $schema['business_type'] ?: 'Organization', '@id' => seoUrl('/#organization'), 'name' => $orgName, 'url' => seoUrl('/')]; foreach (['description', 'email', 'phone'] as $field) if (!empty($schema[$field])) $organization[$field === 'phone' ? 'telephone' : $field] = $schema[$field]; if (!empty($schema['logo_url'])) $organization['logo'] = $schema['logo_url']; $sameAs = array_values(array_filter(array_map('trim', (array)($schema['same_as'] ?? [])))); if ($sameAs) $organization['sameAs'] = $sameAs;
        if ($pageType === 'home') $result = [$organization, ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $orgName, 'url' => seoUrl('/')]]; else $result = [['@context' => 'https://schema.org', '@type' => $pageType === 'service' ? 'Service' : ($pageType === 'article' ? 'BlogPosting' : 'WebPage'), 'name' => $entity['title'] ?? $orgName, 'headline' => $entity['title'] ?? null, 'description' => $entity['description'] ?? null, 'url' => $canonicalUrl, 'mainEntityOfPage' => $canonicalUrl], ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => seoUrl('/')], ['@type' => 'ListItem', 'position' => 2, 'name' => $entity['title'] ?? ucfirst($pageType), 'item' => $canonicalUrl]]]];
        foreach ((array)($schema['custom_schemas'] ?? []) as $custom) { if (empty($custom['enabled']) || !in_array($custom['assignment'] ?? 'all', ['all', $pageType], true)) continue; $decoded = json_decode((string)($custom['json'] ?? ''), true); if (is_array($decoded)) $result[] = $decoded; }
        return array_values(array_filter(array_map(static fn($item) => array_filter($item, static fn($v) => $v !== null && $v !== ''), $result)));
    }
}
if (!function_exists('seoStatus')) {
    function seoStatus(PDO $db): array {
        $config = seoConfig(); $urls = seoSitemapUrls($db); $raw = getSiteSettings()['seo_status'] ?? [];
        return ['robots' => !empty($config['robots']['enabled']) ? 'active' : 'disabled', 'sitemap' => !empty($config['sitemap']['enabled']) ? 'active' : 'disabled', 'rss' => !empty($config['rss']['enabled']) ? 'active' : 'disabled', 'schema' => !empty($config['schema']['enabled']) ? 'active' : 'disabled', 'canonical' => 'active', 'search_console' => !empty($config['seo']['google_search_console_code']) ? 'configured' : 'not_configured', 'analytics' => !empty($config['seo']['google_analytics_id']) ? 'configured' : 'not_configured', 'sitemap_url' => seoUrl('/sitemap.xml'), 'rss_url' => seoUrl('/rss.xml'), 'robots_url' => seoUrl('/robots.txt'), 'total_urls' => count($urls), 'last_sitemap_generation' => $raw['last_sitemap_generation'] ?? null, 'last_robots_generation' => $raw['last_robots_generation'] ?? null, 'last_rss_generation' => $raw['last_rss_generation'] ?? null, 'last_schema_generation' => $raw['last_schema_generation'] ?? null];
    }
}
