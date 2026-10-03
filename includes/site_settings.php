<?php
/**
 * Small, read-only facade for public site configuration.
 *
 * Settings remain in the existing site_settings key/value table.  Keeping the
 * lookup here makes the public shell, robots.txt and sitemap.xml agree on the
 * same configuration without exposing the settings API to visitors.
 */

require_once __DIR__ . '/db.php';

if (!function_exists('getSiteSettings')) {
    function getSiteSettings(): array {
        static $settings = null;
        if ($settings !== null) {
            return $settings;
        }

        $settings = [];
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('general_settings', 'seo_settings', 'custom_code_settings', 'robots_settings', 'sitemap_settings', 'rss_settings', 'schema_settings', 'seo_status')");
            foreach ($stmt->fetchAll() as $row) {
                $decoded = json_decode((string)$row['setting_value'], true);
                $settings[$row['setting_key']] = is_array($decoded) ? $decoded : [];
            }
        } catch (Throwable $e) {
            // The application remains usable before the settings table exists.
        }

        return $settings;
    }
}

if (!function_exists('getPublicSiteConfig')) {
    function getPublicSiteConfig(): array {
        $settings = getSiteSettings();
        $general = $settings['general_settings'] ?? [];
        $seo = $settings['seo_settings'] ?? [];
        $custom = $settings['custom_code_settings'] ?? [];

        return [
            'website_name' => trim((string)($general['website_name'] ?? '')) ?: (defined('APP_NAME') ? APP_NAME : 'MarketMoke'),
            'website_title' => trim((string)($general['website_title'] ?? '')),
            'meta_description' => trim((string)($general['website_description'] ?? '')),
            'contact_email' => trim((string)($general['contact_email'] ?? '')),
            'logo_url' => trim((string)($general['logo_url'] ?? '')),
            'favicon_url' => trim((string)($general['favicon_url'] ?? '')),
            'google_search_console_code' => trim((string)($seo['google_search_console_code'] ?? '')),
            'google_analytics_id' => trim((string)($seo['google_analytics_id'] ?? '')),
            'bing_webmaster_code' => trim((string)($seo['bing_webmaster_code'] ?? '')),
            'base_url' => trim((string)($seo['base_url'] ?? '')),
            'robots_meta' => trim((string)($seo['robots_meta'] ?? '')),
            'custom_head_code' => (string)($custom['custom_head_code'] ?? ''),
        ];
    }
}

if (!function_exists('getConfiguredSiteUrl')) {
    function getConfiguredSiteUrl(): string {
        $configured = getPublicSiteConfig()['base_url'] ?? '';
        return rtrim(filter_var($configured, FILTER_VALIDATE_URL) ? $configured : APP_URL, '/');
    }
}
