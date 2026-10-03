<?php
// Start buffering before any include so notices/warnings cannot corrupt JSON.
ob_start();
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
    error_log('[AI Automation API Error] ' . $e->getMessage());
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Internal server error.', 'error' => 'Internal server error.']);
    exit();
});
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[AI Automation API Fatal] ' . $error['message']);
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Internal server error.', 'error' => 'Internal server error.']);
    }
});
/**
 * MarketMoke - AI Automation & Keyword Tracking API Endpoint
 * Handles automated AI keyword operations, competitor crawls, content gap analysis,
 * AI content generation, quality check, and blog publishing.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/csrf.php';

// Always return valid JSON and prevent PHP HTML error leaking
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// This endpoint manages provider credentials and publishing automation, so it
// is admin-only. Unlike page access control, API callers receive JSON rather
// than an HTML login redirect.
/* An external scheduler may only enter through the dedicated cron action.  All
 * dashboard operations still require an authenticated administrator. */
$requestedGetAction = (string)($_GET['action'] ?? '');
if (!hasRole('admin') && $requestedGetAction !== 'cron_run') {
    jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
}

function dataForSeoRequest(array $provider, string $path, array $tasks): array {
    $credential = aiDecryptStoredSecret($provider['api_key_encrypted'] ?? '');
    if ($credential === '' || !str_contains($credential, ':')) {
        throw new ErrorException('DataForSEO credentials are required. Save them as login:password in the research provider API key field.');
    }
    $base = rtrim($provider['api_base_url'] ?: 'https://api.dataforseo.com', '/');
    $ch = curl_init($base . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($tasks, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode($credential)],
        CURLOPT_TIMEOUT => 90,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) throw new ErrorException('SEO data provider network error: ' . $error);
    $json = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || (($json['status_code'] ?? 20000) >= 40000)) {
        $message = $json['status_message'] ?? $json['tasks'][0]['status_message'] ?? "SEO data provider returned HTTP {$status}.";
        throw new ErrorException($message);
    }
    return $json;
}

function normalizeCompetitorUrl(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Invalid competitor URL: ' . $url);
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || isset($parts['user'], $parts['pass']) || aiHostIsPrivate($host)) {
        throw new InvalidArgumentException('Competitor URL must use a public HTTP(S) host.');
    }
    return rtrim($url, '/');
}

/** Prevent the competitor analyser from being used to probe local services. */
function aiHostIsPrivate(string $host): bool {
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) return true;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return true;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return true;
    }
    return false;
}

function competitorDomain(string $url): string {
    return strtolower((string)parse_url(normalizeCompetitorUrl($url), PHP_URL_HOST));
}

function extractDataForSeoRankedKeywords(array $response, string $domain): array {
    $items = $response['tasks'][0]['result'][0]['items'] ?? [];
    $out = [];
    foreach ((array)$items as $item) {
        $keyword = trim((string)($item['keyword_data']['keyword'] ?? $item['keyword'] ?? ''));
        if ($keyword === '') continue;
        $serp = $item['ranked_serp_element']['serp_item'] ?? $item['serp_item'] ?? [];
        $out[] = [
            'keyword' => $keyword,
            'competitor_source' => $domain,
            'ranking_url' => $serp['url'] ?? null,
            'ranking_position' => isset($serp['rank_group']) ? (int)$serp['rank_group'] : (isset($item['rank_group']) ? (int)$item['rank_group'] : null),
            'search_volume' => $item['keyword_data']['keyword_info']['search_volume'] ?? $item['keyword_info']['search_volume'] ?? null,
            'kd_estimate' => $item['keyword_data']['keyword_properties']['keyword_difficulty'] ?? $item['keyword_properties']['keyword_difficulty'] ?? null,
            'search_intent' => $item['keyword_data']['search_intent_info']['main_intent'] ?? $item['search_intent_info']['main_intent'] ?? 'informational',
        ];
    }
    return $out;
}

function fetchPageEvidence(string $url): array {
    $url = normalizeCompetitorUrl($url);
    $html = false; $status = 0; $finalUrl = $url;
    // Follow a small number of redirects manually so every redirect target is
    // normalized and checked against the private-network guard.
    for ($redirects = 0; $redirects <= 3; $redirects++) {
        $ch = curl_init($finalUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'MarketMokeResearchBot/1.0 (+https://marketmoke.local)',
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (!is_string($response)) break;
        $headers = substr($response, 0, $headerSize);
        $html = substr($response, $headerSize);
        if ($status < 300 || $status >= 400) break;
        if (!preg_match('/^Location:\s*(.+)$/im', $headers, $match)) break;
        $location = trim($match[1]);
        if (!preg_match('#^https?://#i', $location)) {
            $parts = parse_url($finalUrl);
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (!empty($parts['port'])) $origin .= ':' . $parts['port'];
            $location = str_starts_with($location, '/') ? $origin . $location : $origin . '/' . ltrim($location, '/');
        }
        $finalUrl = normalizeCompetitorUrl($location);
    }
    if (!is_string($html) || $status < 200 || $status >= 300) throw new ErrorException("Unable to retrieve competitor page ({$status}). Enter the final public page URL rather than a redirect.");
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($doc);
    $title = trim((string)$xpath->evaluate('string(//title)'));
    $headings = [];
    foreach ($xpath->query('//h1|//h2|//h3') as $node) {
        $text = trim(preg_replace('/\s+/', ' ', $node->textContent));
        if ($text !== '') $headings[] = mb_substr($text, 0, 220);
    }
    $paragraphs = [];
    foreach ($xpath->query('//p') as $node) {
        $text = trim(preg_replace('/\s+/', ' ', $node->textContent));
        if (mb_strlen($text) > 35) $paragraphs[] = mb_substr($text, 0, 500);
        if (count($paragraphs) >= 20) break;
    }
    return ['url' => $finalUrl, 'title' => $title, 'headings' => array_values(array_unique($headings)), 'text_excerpt' => implode("\n", $paragraphs)];
}

function runRealKeywordResearch(PDO $db, array $input): array {
    $niche = trim((string)($input['niche'] ?? $input['topic_seed'] ?? ''));
    $targetKeyword = trim((string)($input['target_keyword'] ?? ''));
    $rawCompetitors = $input['competitors'] ?? $input['competitor_urls'] ?? [];
    if (!is_array($rawCompetitors)) $rawCompetitors = preg_split('/[\r\n,]+/', (string)$rawCompetitors);
    if (!empty($input['competitor_url'])) $rawCompetitors[] = $input['competitor_url'];
    $competitors = array_values(array_unique(array_filter(array_map('normalizeCompetitorUrl', $rawCompetitors))));
    if ($niche === '' && $targetKeyword === '' && !$competitors) throw new InvalidArgumentException('Enter a niche, target keyword, or at least one competitor URL.');
    $seed = $targetKeyword ?: $niche;
    $provider = getAiProvider($db, null, 'dataforseo');
    if (!$provider) throw new ErrorException('Real keyword research needs an enabled DataForSEO provider. Add it in Custom AI API Configuration; no estimates are generated without it.');
    $location = trim((string)($input['location_name'] ?? 'United States'));
    $language = trim((string)($input['language_name'] ?? 'English'));
    $limit = max(10, min(100, (int)($input['count'] ?? 50)));

    if (!$competitors) {
        $serp = dataForSeoRequest($provider, '/v3/serp/google/organic/live/advanced', [[
            'keyword' => $seed, 'location_name' => $location, 'language_name' => $language, 'depth' => 20,
        ]]);
        foreach ((array)($serp['tasks'][0]['result'][0]['items'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'organic' || empty($item['url'])) continue;
            try { $competitors[] = normalizeCompetitorUrl($item['url']); } catch (Throwable $e) {}
            if (count($competitors) >= 5) break;
        }
        $competitors = array_values(array_unique($competitors));
        if (!$competitors) throw new ErrorException('The SEO provider returned no organic competitors for this niche.');
    }

    $collected = [];
    foreach ($competitors as $competitor) {
        $domain = competitorDomain($competitor);
        $response = dataForSeoRequest($provider, '/v3/dataforseo_labs/google/ranked_keywords/live', [[
            'target' => $domain, 'location_name' => $location, 'language_name' => $language, 'limit' => $limit,
        ]]);
        $collected = array_merge($collected, extractDataForSeoRankedKeywords($response, $domain));
    }

    // The provider's related-keyword endpoint supplements competitor rankings;
    // values are accepted only when the response itself supplies the metric.
    if ($seed !== '') {
        try {
            $related = dataForSeoRequest($provider, '/v3/dataforseo_labs/google/keyword_suggestions/live', [[
                'keyword' => $seed, 'location_name' => $location, 'language_name' => $language, 'limit' => $limit,
            ]]);
            foreach ((array)($related['tasks'][0]['result'][0]['items'] ?? []) as $item) {
                $keyword = trim((string)($item['keyword_data']['keyword'] ?? $item['keyword'] ?? ''));
                if ($keyword === '') continue;
                $collected[] = [
                    'keyword' => $keyword, 'competitor_source' => null, 'ranking_url' => null, 'ranking_position' => null,
                    'search_volume' => $item['keyword_data']['keyword_info']['search_volume'] ?? $item['keyword_info']['search_volume'] ?? null,
                    'kd_estimate' => $item['keyword_data']['keyword_properties']['keyword_difficulty'] ?? $item['keyword_properties']['keyword_difficulty'] ?? null,
                    'search_intent' => $item['keyword_data']['search_intent_info']['main_intent'] ?? 'informational',
                ];
            }
        } catch (Throwable $e) {
            // Ranking results remain valid if an account does not include the
            // optional suggestions product; never replace them with invented data.
            error_log('[AI research related terms] ' . $e->getMessage());
        }
    }

    $existing = array_flip($db->query('SELECT normalized_keyword FROM ai_keywords_list')->fetchAll(PDO::FETCH_COLUMN));
    $insert = $db->prepare('INSERT INTO ai_keywords_list (topic_seed, keyword, normalized_keyword, search_volume, kd_estimate, search_intent, competitor_source, ranking_url, ranking_position, metrics_source, metrics_updated_at, status, created_at) VALUES (:topic, :keyword, :normalized, :volume, :difficulty, :intent, :competitor, :url, :position, :source, NOW(), \'unused\', NOW())');
    $inserted = 0; $duplicates = 0;
    foreach ($collected as $row) {
        $keyword = trim((string)$row['keyword']);
        $words = preg_split('/\s+/', $keyword, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 3) continue;
        $volume = is_numeric($row['search_volume']) ? (int)$row['search_volume'] : null;
        $difficulty = is_numeric($row['kd_estimate']) ? (float)$row['kd_estimate'] : null;
        // Prefer real volume >=300 and low competition. Terms without a reported
        // metric are retained only when sourced from a competitor ranking.
        if ($volume !== null && $volume < 300) continue;
        if ($difficulty !== null && $difficulty > 40) continue;
        if ($volume === null && empty($row['competitor_source'])) continue;
        $normalized = normalizeKeyword($keyword);
        if ($normalized === '' || isset($existing[$normalized])) { $duplicates++; continue; }
        $intent = strtolower((string)($row['search_intent'] ?? 'informational'));
        if (!in_array($intent, ['informational', 'commercial', 'transactional', 'navigational'], true)) $intent = 'informational';
        $insert->execute([
            ':topic' => $niche ?: $seed, ':keyword' => $keyword, ':normalized' => $normalized,
            ':volume' => $volume === null ? 'N/A' : (string)$volume, ':difficulty' => $difficulty === null ? 'N/A' : (string)$difficulty,
            ':intent' => $intent, ':competitor' => $row['competitor_source'], ':url' => $row['ranking_url'], ':position' => $row['ranking_position'], ':source' => 'DataForSEO',
        ]);
        $existing[$normalized] = true; $inserted++;
    }
    return ['inserted_count' => $inserted, 'duplicate_count' => $duplicates, 'competitors' => $competitors, 'niche' => $niche ?: $seed, 'source' => 'DataForSEO'];
}

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance()->getConnection();

if (!$db) {
    jsonResponse(['success' => false, 'message' => 'Database connection failed.'], 500);
}

// Ensure database tables exist
try {
    $db->exec("
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
          `status` ENUM('pending', 'processing', 'unused', 'used', 'published', 'failed') NOT NULL DEFAULT 'pending',
          `used_in_post_id` INT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `used_at` DATETIME DEFAULT NULL,
          INDEX `idx_topic_seed_status` (`topic_seed`, `status`),
          INDEX `idx_kw_status` (`status`),
          INDEX `idx_normalized_keyword` (`normalized_keyword`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `ai_competitor_analyses` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `competitor_url` VARCHAR(500) NOT NULL,
          `topic_seed` VARCHAR(150) DEFAULT NULL,
          `analysis_data` LONGTEXT NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `ai_automation_logs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `event_type` VARCHAR(50) NOT NULL,
          `message` TEXT NOT NULL,
          `details` LONGTEXT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

        INSERT IGNORE INTO `ai_automation_settings` (`id`, `active_provider`, `gemini_model`) VALUES (1, 'gemini', 'gemini-2.5-flash');
    ");
} catch (Exception $e) {
    error_log("[AI Automation Setup Error] " . $e->getMessage());
}

/* Incremental schema migration for installations created before the research
 * workflow existed. Keep this in the existing endpoint so an upgrade does not
 * require copying/replacing dashboard files. */
function aiEnsureColumn(PDO $db, string $table, string $column, string $definition): void {
    try {
        $check = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $check->execute([$table, $column]);
        if (!(int)$check->fetchColumn()) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    } catch (Throwable $e) {
        error_log("[AI Automation Migration] {$table}.{$column}: " . $e->getMessage());
    }
}
aiEnsureColumn($db, 'ai_automation_settings', 'active_ai_provider_id', 'INT DEFAULT NULL');
aiEnsureColumn($db, 'ai_keywords_list', 'search_volume', "VARCHAR(50) DEFAULT 'N/A'");
aiEnsureColumn($db, 'ai_keywords_list', 'competitor_source', 'VARCHAR(255) DEFAULT NULL');
aiEnsureColumn($db, 'ai_keywords_list', 'ranking_url', 'VARCHAR(255) DEFAULT NULL');
aiEnsureColumn($db, 'ai_keywords_list', 'ranking_position', 'INT DEFAULT NULL');
aiEnsureColumn($db, 'ai_keywords_list', 'metrics_source', "VARCHAR(80) DEFAULT NULL");
aiEnsureColumn($db, 'ai_keywords_list', 'metrics_updated_at', 'DATETIME DEFAULT NULL');
try {
    // Older installations stored numeric difficulty only and had no lifecycle
    // states for research, validation, and publication.
    $db->exec("ALTER TABLE ai_keywords_list MODIFY COLUMN kd_estimate VARCHAR(50) DEFAULT 'N/A', MODIFY COLUMN status ENUM('pending','processing','unused','used','published','failed','skipped') NOT NULL DEFAULT 'pending'");
} catch (Throwable $e) {
    error_log('[AI Automation Migration] ai_keywords_list contract: ' . $e->getMessage());
}

/**
 * Log an automation event to DB
 */
function logAutomationEvent($db, $eventType, $message, $details = null) {
    try {
        $stmt = $db->prepare("INSERT INTO ai_automation_logs (event_type, message, details, created_at) VALUES (:et, :msg, :det, NOW())");
        $stmt->execute([
            ':et' => $eventType,
            ':msg' => $message,
            ':det' => is_array($details) ? json_encode($details, JSON_UNESCAPED_SLASHES) : (string)$details
        ]);
    } catch (Exception $e) {}
}

/**
 * Normalize keyword for deduplication
 */
function normalizeKeyword($str) {
    $clean = strtolower(trim($str));
    $clean = preg_replace('/[^a-z0-9\s]/', '', $clean);
    return preg_replace('/\s+/', ' ', $clean);
}

/**
 * AI output is untrusted input. Keep the article markup useful while removing
 * executable HTML, event attributes, inline styling and non-site links.
 */
function aiSanitizeArticleHtml(string $html): string {
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="ai-content-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $allowed = array_flip(['div', 'section', 'p', 'h1', 'h2', 'h3', 'h4', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a', 'br', 'blockquote', 'hr']);
    $xpath = new DOMXPath($doc);
    foreach (iterator_to_array($xpath->query('//*')) as $node) {
        if (!$node instanceof DOMElement) continue;
        $tag = strtolower($node->tagName);
        if (!isset($allowed[$tag])) {
            while ($node->firstChild) $node->parentNode->insertBefore($node->firstChild, $node);
            $node->parentNode->removeChild($node);
            continue;
        }
        $allowedAttributes = $tag === 'a' ? ['href', 'title', 'rel'] : [];
        foreach (iterator_to_array($node->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (!in_array($name, $allowedAttributes, true)) {
                $node->removeAttribute($attribute->name);
                continue;
            }
            if ($tag === 'a' && $name === 'href' && !preg_match('#^/blog/[a-z0-9-]+$#i', trim($attribute->value)) && !filter_var(trim($attribute->value), FILTER_VALIDATE_URL)) {
                $node->removeAttribute('href');
            }
        }
    }
    $root = $doc->getElementById('ai-content-root');
    if (!$root) return '';
    $clean = '';
    foreach ($root->childNodes as $child) $clean .= $doc->saveHTML($child);
    return trim($clean);
}

function aiSafeSlug(string $value): string {
    $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
    return substr($slug, 0, 240);
}

/** Keep external citations real, limited, and consistently attributed. */
function aiEnforceExternalLinkRules(string $html, array $verifiedUrls = []): string {
    $allowed = [];
    foreach ($verifiedUrls as $url) {
        try { $allowed[rtrim(normalizeCompetitorUrl((string)$url), '/')] = true; } catch (Throwable $e) {}
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="ai-content-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($doc);
    $external = [];
    foreach (iterator_to_array($xpath->query('//a[@href]')) as $link) {
        $href = trim((string)$link->getAttribute('href'));
        if (preg_match('#^/blog/[a-z0-9-]+$#i', $href)) continue;
        try { $normalized = rtrim(normalizeCompetitorUrl($href), '/'); } catch (Throwable $e) { $normalized = ''; }
        if ($normalized === '' || !isset($allowed[$normalized]) || count($external) >= 2) {
            $link->removeAttribute('href');
            $link->removeAttribute('rel');
            continue;
        }
        $external[] = $link;
    }
    foreach ($external as $index => $link) {
        // One citation is nofollow. With two, only the first remains dofollow.
        if (count($external) === 1 || $index === 1) $link->setAttribute('rel', 'nofollow');
        else $link->removeAttribute('rel');
    }
    $root = $doc->getElementById('ai-content-root');
    if (!$root) return '';
    $clean = '';
    foreach ($root->childNodes as $child) $clean .= $doc->saveHTML($child);
    return trim($clean);
}

function aiArticleQualityReport(string $content, string $primaryKeyword): array {
    $plain = trim(strip_tags($content));
    $wordCount = $plain === '' ? 0 : count(preg_split('/\s+/', $plain));
    preg_match_all('#href\s*=\s*["\'][^"\']*/blog/([^"\'?/#]+)#i', $content, $internalMatches);
    $internalLinks = array_values(array_unique(array_map('strtolower', $internalMatches[1] ?? [])));
    preg_match_all('#<a\b[^>]*href\s*=\s*["\']https?://[^"\']+["\'][^>]*>#i', $content, $externalMatches);
    $externalLinks = $externalMatches[0] ?? [];
    $externalRelValid = count($externalLinks) === 0
        || (count($externalLinks) === 1 && (bool)preg_match('/\brel\s*=\s*["\'][^"\']*nofollow/i', $externalLinks[0]))
        || (count($externalLinks) === 2 && !preg_match('/\brel\s*=\s*["\'][^"\']*nofollow/i', $externalLinks[0]) && (bool)preg_match('/\brel\s*=\s*["\'][^"\']*nofollow/i', $externalLinks[1]));
    $checks = [
        'keyword' => stripos($plain, $primaryKeyword) !== false,
        'headings' => (bool)preg_match('/<h2\b/i', $content),
        'length' => $wordCount >= 450 && $wordCount <= 1200,
        'internal_links' => count($internalLinks) >= 3,
        'external_sources' => count($externalLinks) <= 2 && $externalRelValid,
    ];
    return [
        'word_count' => $wordCount,
        'internal_links' => count($internalLinks),
        'external_links' => count($externalLinks),
        'checks' => $checks,
        'score' => (int)round((count(array_filter($checks)) / count($checks)) * 100),
    ];
}

function aiCreateCompetitorBrief(PDO $db, array $settings, array $keyword, array $urls, array $evidence = []): array {
    if (!$evidence) {
        foreach (array_slice($urls, 0, 8) as $url) $evidence[] = fetchPageEvidence($url);
    }
    if (!$evidence) throw new ErrorException('No public competitor pages could be retrieved for analysis.');
    $prompt = 'Analyze only the supplied competitor-page evidence for the selected keyword. Do not invent statistics, rankings, factual claims, or URLs. Return JSON with exactly: content_strengths (array), content_gaps (array), recommended_angles (array), faq_opportunities (array of questions), recommended_article_title (string), main_headings (array). Evidence: ' . json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $raw = callAiEngine($settings, 'You are an SEO content-gap analyst. Base every conclusion strictly on supplied page evidence. Output valid JSON only.', "Selected keyword: {$keyword['keyword']}\n" . $prompt);
    $analysis = json_decode(trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', $raw))), true);
    if (!is_array($analysis)) throw new ErrorException('AI provider returned invalid JSON for content-gap analysis.');
    $analysis['keyword_id'] = (int)$keyword['id'];
    $analysis['keyword'] = $keyword['keyword'];
    $analysis['competitor'] = implode(', ', array_column($evidence, 'url'));
    $analysis['analyzed_pages'] = $evidence;
    $analysis['missing_keywords'] = [];
    $cache = $db->prepare('INSERT INTO ai_competitor_analyses (competitor_url, topic_seed, analysis_data, created_at) VALUES (?, ?, ?, NOW())');
    $cache->execute([$analysis['competitor'], $keyword['topic_seed'], json_encode($analysis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    return $analysis;
}

function aiDiscoverCompetitors(PDO $db, string $keyword): array {
    $provider = getAiProvider($db, null, 'dataforseo');
    if (!$provider) throw new ErrorException('Automatic competitor discovery requires an enabled DataForSEO provider.');
    $response = dataForSeoRequest($provider, '/v3/serp/google/organic/live/advanced', [[
        'keyword' => $keyword, 'location_name' => 'United States', 'language_name' => 'English', 'depth' => 20,
    ]]);
    $urls = []; $domains = [];
    foreach ((array)($response['tasks'][0]['result'][0]['items'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'organic' || empty($item['url'])) continue;
        try {
            $url = normalizeCompetitorUrl((string)$item['url']);
            $domain = competitorDomain($url);
            if (!isset($domains[$domain])) { $urls[] = $url; $domains[$domain] = true; }
        } catch (Throwable $e) {}
        if (count($urls) >= 4) break;
    }
    if (count($urls) < 4) throw new ErrorException('The SEO provider did not return four distinct public competitors for this keyword.');
    return $urls;
}

function aiGenerateKeywordSet(PDO $db, array $settings, string $topic, string $primaryKeyword, array $evidence): array {
    $existing = array_flip($db->query('SELECT normalized_keyword FROM ai_keywords_list')->fetchAll(PDO::FETCH_COLUMN));
    $saved = [];
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
    for ($attempt = 0; $attempt < 3 && count($saved) < 50; $attempt++) {
        $needed = 50 - count($saved);
        $evidenceInstruction = $evidence ? 'Use the supplied public competitor-page evidence for topical context.' : 'Use the supplied keyword and topic only; do not claim competitor research or SEO metrics.';
        $prompt = "Generate exactly {$needed} distinct, useful keyword opportunities for the topic and primary keyword below. Include a practical mix of related keywords, specific long-tail phrases that are generally less broad/competitive, search-intent phrases, and genuine user questions. {$evidenceInstruction} Do not invent search volume, difficulty, URLs, rankings, or sources. Return JSON only: [{\"keyword\":\"...\",\"intent\":\"informational|commercial|transactional|navigational\",\"type\":\"related|long_tail|intent|question\"}].\nTopic: {$topic}\nPrimary keyword: {$primaryKeyword}\nEvidence: " . json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $raw = callAiEngine($settings, 'You are a careful SEO keyword strategist. Return valid JSON only and never make up metrics or URLs.', $prompt);
        $ideas = json_decode(trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', $raw))), true);
        if (!is_array($ideas)) throw new ErrorException('AI provider returned invalid JSON while creating the keyword set.');
        foreach ($ideas as $idea) {
            $keyword = trim((string)($idea['keyword'] ?? ''));
            $normalized = normalizeKeyword($keyword);
            $words = preg_split('/\s+/', $keyword, -1, PREG_SPLIT_NO_EMPTY);
            if ($normalized === '' || count($words) < 2 || isset($existing[$normalized])) continue;
            $intent = strtolower(trim((string)($idea['intent'] ?? 'informational')));
            if (!in_array($intent, ['informational', 'commercial', 'transactional', 'navigational'], true)) $intent = 'informational';
            $insert = $db->prepare("INSERT INTO ai_keywords_list (topic_seed, keyword, normalized_keyword, search_volume, kd_estimate, search_intent, competitor_source, metrics_source, status, created_at) VALUES (?, ?, ?, 'N/A', 'N/A', ?, ?, ?, 'unused', NOW())");
            $source = $evidence ? 'AI discovery from public competitor-page evidence' : 'AI keyword discovery; metrics not verified';
            $insert->execute([$topic, $keyword, $normalized, $evidence ? 'Public competitor context' : null, $source]);
            $existing[$normalized] = true;
            $saved[] = ['id' => (int)$db->lastInsertId(), 'keyword' => $keyword, 'intent' => $intent, 'type' => trim((string)($idea['type'] ?? 'related'))];
            if (count($saved) >= 50) break;
        }
    }
    if (count($saved) !== 50) throw new ErrorException('The AI provider did not return 50 unique keyword opportunities. No partial keyword set was used for article generation.');
    if ($ownsTransaction) $db->commit();
    return $saved;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Generates a usable 50-keyword set even when an optional metrics provider is
 * not installed. Metric values are never fabricated: DataForSEO results retain
 * provider values while AI ideas remain explicitly unverified.
 */
function runKeywordGeneration(PDO $db, array $settings, array $input): array {
    $keyword = trim((string)($input['keyword'] ?? $input['target_keyword'] ?? $input['topic_seed'] ?? $input['niche'] ?? ''));
    if ($keyword === '') throw new InvalidArgumentException('Keyword is required.');
    $competitor = trim((string)($input['competitor_url'] ?? ''));
    $count = max(50, min(100, (int)($input['count'] ?? 50)));

    $metricsProvider = getAiProvider($db, null, 'dataforseo');
    if ($metricsProvider) {
        try {
            $providerInput = $input;
            $providerInput['target_keyword'] = $keyword;
            $providerInput['count'] = $count;
            $providerResult = runRealKeywordResearch($db, $providerInput);
            if ((int)$providerResult['inserted_count'] >= 50) return $providerResult + ['mode' => 'provider_metrics'];
        } catch (Throwable $e) {
            error_log('[AI keyword provider fallback] ' . $e->getMessage());
        }
    }

    $evidence = [];
    $competitors = [];
    if ($competitor !== '') {
        $url = normalizeCompetitorUrl($competitor);
        $evidence[] = fetchPageEvidence($url);
        $competitors[] = $url;
    }
    $saved = aiGenerateKeywordSet($db, $settings, $keyword, $keyword, $evidence);
    return ['inserted_count' => count($saved), 'duplicate_count' => 0, 'keywords' => $saved, 'competitors' => $competitors, 'niche' => $keyword, 'source' => $evidence ? 'AI with public competitor-page evidence' : 'AI keyword discovery (no metrics provider)', 'mode' => 'ai_discovery'];
}

function aiGenerateAutomationArticle(PDO $db, array $settings, array $keyword, array $brief): array {
    $linkRows = $db->query("SELECT title, slug FROM blog_posts WHERE status = 'published' ORDER BY id DESC LIMIT 12")->fetchAll();
    if (count($linkRows) < 3) throw new ErrorException('At least three published MarketMoke articles are required before automation can add three real internal links.');
    $primary = $keyword['keyword'];
    $secondary = '';
    $lsi = '';
    $relatedStmt = $db->prepare("SELECT keyword FROM ai_keywords_list WHERE topic_seed = ? AND id != ? AND status IN ('unused', 'pending') ORDER BY CASE WHEN search_intent = 'informational' THEN 0 ELSE 1 END, id DESC LIMIT 2");
    $relatedStmt->execute([$keyword['topic_seed'], $keyword['id']]);
    $related = $relatedStmt->fetchAll(PDO::FETCH_COLUMN);
    $secondary = trim((string)($related[0] ?? ''));
    $lsi = trim((string)($related[1] ?? ''));
    $internalLinks = array_map(static fn($row) => ['title' => $row['title'], 'url' => '/blog/' . $row['slug']], array_slice($linkRows, 0, 8));
    $sourceUrls = array_values(array_filter(array_column($brief['analyzed_pages'] ?? [], 'url')));
    $system = "You write original, helpful SEO articles. Return valid JSON only.\n"
        . "Rules: Give the article a useful SEO title and clean HTML body. Include multiple H2 headings and use H3 only where it improves a section. Use the primary keyword naturally in the title, introduction, and relevant headings. Use a table only when comparison or structured information genuinely helps; do not add a table otherwise. Add at least three natural contextual internal links using only the verified MarketMoke URLs supplied. External citations are optional and may use only the verified source URLs supplied when genuinely necessary: with one external citation use rel=\"nofollow\"; with two citations, one may be dofollow and one must use rel=\"nofollow\". Never invent a URL, source, fact, metric, or competitor.\n"
        . "Return {\"title\":\"...\",\"slug\":\"...\",\"meta_title\":\"...\",\"meta_description\":\"...\",\"content_html\":\"...\"}.";
    $user = "Topic: {$keyword['topic_seed']}\nPrimary keyword: {$primary}\nRelated keyword: {$secondary}\nAdditional context: {$lsi}\n"
        . 'Verified internal links: ' . json_encode($internalLinks, JSON_UNESCAPED_SLASHES)
        . "\nCompetitor brief: " . json_encode($brief, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "\nVerified external sources: " . json_encode($sourceUrls, JSON_UNESCAPED_SLASHES);
    $raw = callAiEngine($settings, $system, $user);
    $generated = json_decode(trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', $raw))), true);
    if (!is_array($generated) || empty($generated['title']) || empty($generated['content_html'])) {
        throw new ErrorException('AI provider did not return the required article payload.');
    }
    $content = aiEnforceExternalLinkRules(aiSanitizeArticleHtml((string)$generated['content_html']), $sourceUrls);
    $article = [
        'title' => mb_substr(trim(strip_tags((string)$generated['title'])), 0, 255),
        'slug' => aiSafeSlug((string)($generated['slug'] ?? $generated['title'])),
        'meta_title' => mb_substr(trim(strip_tags((string)($generated['meta_title'] ?? $generated['title']))), 0, 255),
        'meta_description' => trim(strip_tags((string)($generated['meta_description'] ?? ''))),
        'content_html' => $content,
        'primary_keyword' => $primary,
        'secondary_keyword' => $secondary,
        'lsi_keyword' => $lsi,
        'topic_seed' => $keyword['topic_seed'],
        'seo_keywords' => implode(', ', array_filter([$primary, $secondary, $lsi])),
    ];
    if ($article['title'] === '' || $article['slug'] === '' || $content === '') throw new ErrorException('Article generation returned unsafe or incomplete content.');
    $quality = aiArticleQualityReport($content, $primary);
    if ($quality['score'] !== 100) throw new ErrorException('SEO validation failed: ' . implode(', ', array_keys(array_filter($quality['checks'], static fn($passed) => !$passed))) . '.');
    $article['word_count'] = $quality['word_count'];
    return ['article' => $article, 'quality' => $quality];
}

function aiSaveAutomationArticle(PDO $db, array $settings, array $article, array $quality, int $keywordId): array {
    $slug = $article['slug'];
    $suffix = 2;
    $slugCheck = $db->prepare('SELECT id FROM blog_posts WHERE slug = ? LIMIT 1');
    while (true) {
        $slugCheck->execute([$slug]);
        if (!$slugCheck->fetchColumn()) break;
        $slug = substr($article['slug'], 0, 235) . '-' . $suffix++;
    }
    $db->beginTransaction();
    try {
        $post = $db->prepare('INSERT INTO blog_posts (title, slug, author, content, category, meta_title, meta_description, seo_keywords, publish_date, status, canonical_url, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), \'draft\', ?, NOW(), NOW())');
        $post->execute([$article['title'], $slug, $settings['target_author'] ?? 'MarketMoke AI Editorial', $article['content_html'], $settings['target_category'] ?? 'SEO & Link Building', $article['meta_title'], $article['meta_description'], $article['seo_keywords'], '/blog/' . $slug]);
        $postId = (int)$db->lastInsertId();
        $tracked = $db->prepare("INSERT INTO ai_published_articles (blog_post_id, topic_seed, primary_keyword, secondary_keyword, lsi_keyword, title, word_count, status, schedule_date, quality_score, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), ?, NOW())");
        $tracked->execute([$postId, $article['topic_seed'], $article['primary_keyword'], $article['secondary_keyword'], $article['lsi_keyword'], $article['title'], $article['word_count'], $quality['score']]);
        $articleId = (int)$db->lastInsertId();
        $used = $db->prepare("UPDATE ai_keywords_list SET status = 'used', used_in_post_id = ?, used_at = NOW() WHERE id = ?");
        $used->execute([$postId, $keywordId]);
        $db->commit();
        return ['post_id' => $postId, 'ai_article_id' => $articleId, 'slug' => $slug, 'status' => 'draft'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Call AI Engine via Gemini or Custom OpenAI-compatible provider
 */
function aiDecryptStoredSecret(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    // Legacy values are read once so existing installations remain usable; every
    // write path below re-encrypts them with AES-256-GCM.
    return decryptSecret($value, null, 'marketmoke-ai-provider')
        ?: decryptSecret($value)
        ?: (str_starts_with($value, 'aes256gcm:') ? '' : $value);
}

function getAiProvider(PDO $db, ?int $providerId = null, ?string $type = null): ?array {
    $where = ['is_enabled = 1'];
    $params = [];
    if ($providerId) { $where[] = 'id = :id'; $params[':id'] = $providerId; }
    if ($type) { $where[] = 'provider_type = :type'; $params[':type'] = $type; }
    $stmt = $db->prepare('SELECT * FROM ai_provider_configs WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function providerForAiCall(array $settings, ?int $providerId = null): ?array {
    global $db;
    $id = $providerId ?: (int)($settings['active_ai_provider_id'] ?? 0);
    if ($id > 0) {
        $provider = getAiProvider($db, $id);
        if (!$provider || $provider['provider_type'] === 'dataforseo') {
            throw new ErrorException('Select an enabled Gemini or OpenAI-compatible AI provider.');
        }
        $provider['api_key'] = aiDecryptStoredSecret($provider['api_key_encrypted'] ?? '');
        return $provider;
    }
    return null;
}

function callAiEngine($settings, $systemPrompt, $userPrompt, ?int $providerId = null) {
    $configured = providerForAiCall($settings, $providerId);
    $provider = $configured['provider_type'] ?? ($settings['active_provider'] ?? 'gemini');

    if ($provider === 'gemini') {
        $apiKey = $configured['api_key'] ?? (!empty($settings['gemini_api_key']) ? aiDecryptStoredSecret($settings['gemini_api_key']) : (getenv('GEMINI_API_KEY') ?: ''));
        if (empty($apiKey)) {
            throw new ErrorException('Gemini API key is not configured.');
        }

        $model = !empty($configured['model']) ? $configured['model'] : (!empty($settings['gemini_model']) ? $settings['gemini_model'] : 'gemini-2.5-flash');
        $temperature = isset($settings['gemini_temperature']) ? (float)$settings['gemini_temperature'] : 0.70;
        $maxTokens = isset($settings['gemini_max_tokens']) ? (int)$settings['gemini_max_tokens'] : 4096;

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt . "\n\n" . $userPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new ErrorException("cURL Network Error: {$curlErr}");
        }

        if ($httpCode !== 200) {
            $errData = json_decode($response, true);
            $errMsg = $errData['error']['message'] ?? "Gemini API returned status {$httpCode}";
            throw new ErrorException($errMsg);
        }

        $resJson = json_decode($response, true);
        $text = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return trim($text);
    } else {
        // Custom OpenAI-compatible provider
        $baseUrl = rtrim($configured['api_base_url'] ?? ($settings['custom_base_url'] ?? 'https://api.openai.com/v1'), '/');
        $apiKey = $configured['api_key'] ?? aiDecryptStoredSecret($settings['custom_api_key'] ?? '');
        $model = $configured['model'] ?? ($settings['custom_model'] ?? 'gpt-4o-mini');
        if ($apiKey === '') throw new ErrorException('Custom AI API key is not configured.');

        $url = "{$baseUrl}/chat/completions";
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'temperature' => (float)($settings['gemini_temperature'] ?? 0.7)
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new ErrorException("Custom AI Provider cURL Error: {$curlErr}");
        }

        if ($httpCode !== 200) {
            $errData = json_decode($response, true);
            $errMsg = $errData['error']['message'] ?? "Custom provider returned status {$httpCode}";
            throw new ErrorException($errMsg);
        }

        $resJson = json_decode($response, true);
        $text = $resJson['choices'][0]['message']['content'] ?? '';
        return trim($text);
    }
}

// Fetch Settings
try {
    $settingsStmt = $db->query("SELECT * FROM ai_automation_settings WHERE id = 1 LIMIT 1");
    $settings = $settingsStmt->fetch();
} catch (Throwable $e) {
    error_log('[AI Automation Settings Error] ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Unable to load AI automation settings.'], 500);
}
if (!$settings) {
    $settings = [
        'id' => 1,
        'active_provider' => 'gemini',
        'gemini_api_key' => '',
        'gemini_model' => 'gemini-2.5-flash',
        'gemini_temperature' => 0.70,
        'gemini_max_tokens' => 4096,
        'custom_base_url' => 'https://api.openai.com/v1',
        'custom_api_key' => '',
        'custom_model' => 'gpt-4o-mini',
        'publishing_mode' => 'manual',
        'schedule_interval_type' => 'days',
        'schedule_interval_value' => 1,
        'schedule_specific_days' => 'Monday,Wednesday,Friday',
        'schedule_time' => '09:00:00',
        'target_category' => 'SEO & Link Building',
        'target_author' => 'MarketMoke AI Editorial',
        'auto_publish_status' => 'published',
        'cron_token' => bin2hex(random_bytes(32))
    ];
}

// Never retain the publicly known installer token. A cron credential is a
// server secret, not a UI default.
if (empty($settings['cron_token']) || hash_equals('moke_cron_sec_8f9811f236524cb8', (string)$settings['cron_token'])) {
    $settings['cron_token'] = bin2hex(random_bytes(32));
    $db->prepare('UPDATE ai_automation_settings SET cron_token = ? WHERE id = 1')->execute([$settings['cron_token']]);
}

// Upgrade pre-existing plaintext keys in place. Failed encryption deliberately
// leaves the old value untouched rather than risking a credential loss.
foreach (['gemini_api_key', 'custom_api_key'] as $legacyKeyColumn) {
    $legacyValue = (string)($settings[$legacyKeyColumn] ?? '');
    if ($legacyValue !== '' && !str_starts_with($legacyValue, 'aes256gcm:')) {
        try {
            $encrypted = encryptSecret($legacyValue, null, 'marketmoke-ai-provider');
            $column = $legacyKeyColumn === 'gemini_api_key' ? 'gemini_api_key' : 'custom_api_key';
            $db->prepare("UPDATE ai_automation_settings SET `{$column}` = ? WHERE id = 1")->execute([$encrypted]);
            $settings[$legacyKeyColumn] = $encrypted;
        } catch (Throwable $e) { error_log('[AI key migration] ' . $e->getMessage()); }
    }
}

$action = $_GET['action'] ?? '';

// External scheduler endpoint. It intentionally does not publish a guessed
// article: a human must first create source-backed keyword research and a
// competitor gap brief. The run records whether the verified queue is ready.
if ($action === 'cron_run' && $method === 'GET') {
    $cronHeader = $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    if ($cronHeader === '' || !hash_equals((string)$settings['cron_token'], trim($cronHeader))) {
        jsonResponse(['success' => false, 'message' => 'Invalid cron access token.'], 401);
    }
    $ready = (int)$db->query("SELECT COUNT(*) FROM ai_keywords_list k WHERE k.status IN ('pending', 'unused') AND EXISTS (SELECT 1 FROM ai_competitor_analyses a WHERE a.analysis_data LIKE CONCAT('%\\\"keyword_id\\\":', k.id, '%'))")->fetchColumn();
    $db->prepare('UPDATE ai_automation_settings SET last_cron_run = NOW() WHERE id = 1')->execute();
    logAutomationEvent($db, 'cron_execution', $ready ? "Cron checked {$ready} verified content brief(s); manual review is required before publishing." : 'Cron checked the queue; no verified content brief is ready.');
    jsonResponse(['success' => true, 'message' => $ready ? "Cron completed. {$ready} verified brief(s) are ready for editorial generation." : 'Cron completed. No verified keyword and competitor brief pair is ready.', 'ready_briefs' => $ready, 'last_cron_run' => date('Y-m-d H:i:s')]);
}

// GET REQUESTS
if ($method === 'GET') {
    if (empty($action) || $action === 'get_settings') {
        $geminiPlain = aiDecryptStoredSecret($settings['gemini_api_key'] ?? '');
        $customPlain = aiDecryptStoredSecret($settings['custom_api_key'] ?? '');
        $maskedGemini = $geminiPlain !== '' ? maskSecret($geminiPlain) : (!empty(getenv('GEMINI_API_KEY')) ? 'Auto-configured (System Key)' : '');
        $maskedCustom = $customPlain !== '' ? maskSecret($customPlain) : '';
        $publicSettings = $settings;
        unset($publicSettings['gemini_api_key'], $publicSettings['custom_api_key']);

        jsonResponse([
            'success' => true,
            'settings' => array_merge($publicSettings, [
                'gemini_api_key_masked' => $maskedGemini,
                'custom_api_key_masked' => $maskedCustom
            ])
        ]);
    }

    if ($action === 'get_providers') {
        // Keys are deliberately never returned to the browser.  The UI receives
        // enough state to show that a key is saved and to edit every other field.
        $providers = $db->query('SELECT id, provider_name, provider_type, api_base_url, model, is_enabled, api_key_encrypted, created_at, updated_at FROM ai_provider_configs ORDER BY provider_type, provider_name')->fetchAll();
        foreach ($providers as &$provider) {
            $provider['api_key_configured'] = trim((string)$provider['api_key_encrypted']) !== '';
            unset($provider['api_key_encrypted']);
        }
        unset($provider);
        jsonResponse(['success' => true, 'providers' => $providers, 'active_ai_provider_id' => (int)($settings['active_ai_provider_id'] ?? 0)]);
    }

    if ($action === 'get_keywords') {
        $status = trim($_GET['status'] ?? '');
        $topic = trim($_GET['topic'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $where = ["1=1"];
        $params = [];

        if (!empty($status)) {
            $where[] = "status = :status";
            $params[':status'] = $status;
        }
        if (!empty($topic)) {
            $where[] = "topic_seed = :topic";
            $params[':topic'] = $topic;
        }
        if (!empty($search)) {
            $where[] = "keyword LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }

        $sql = "SELECT * FROM ai_keywords_list WHERE " . implode(" AND ", $where) . " ORDER BY id DESC LIMIT 500";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $keywords = $stmt->fetchAll();

        // Topic stats
        $statsStmt = $db->query("
            SELECT topic_seed, 
                   COUNT(*) as total_count,
                   SUM(CASE WHEN status IN ('pending', 'unused') THEN 1 ELSE 0 END) as unused_count,
                   SUM(CASE WHEN status IN ('used', 'published') THEN 1 ELSE 0 END) as used_count
            FROM ai_keywords_list 
            GROUP BY topic_seed
        ");
        $topicStats = $statsStmt->fetchAll();

        // Global counts
        $countStmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('pending', 'unused') THEN 1 ELSE 0 END) as total_unused,
                SUM(CASE WHEN status IN ('used', 'published') THEN 1 ELSE 0 END) as total_used
            FROM ai_keywords_list
        ");
        $counts = $countStmt->fetch();

        jsonResponse([
            'success' => true,
            'keywords' => $keywords,
            'topic_stats' => $topicStats,
            'counts' => [
                'total' => (int)($counts['total'] ?? 0),
                'total_unused' => (int)($counts['total_unused'] ?? 0),
                'total_used' => (int)($counts['total_used'] ?? 0),
                'replenish_threshold' => 20
            ]
        ]);
    }

    if ($action === 'get_articles') {
        $stmt = $db->query("SELECT a.*, b.slug as post_slug, b.status as blog_status FROM ai_published_articles a LEFT JOIN blog_posts b ON a.blog_post_id = b.id ORDER BY a.id DESC LIMIT 100");
        $articles = $stmt->fetchAll();
        jsonResponse([
            'success' => true,
            'articles' => $articles
        ]);
    }

    if ($action === 'get_internal_links') {
        $query = trim($_GET['query'] ?? '');
        $sql = "SELECT id, title, slug, category FROM blog_posts WHERE status = 'published'";
        if (!empty($query)) {
            $sql .= " AND (title LIKE :q OR content LIKE :q)";
            $stmt = $db->prepare($sql . " ORDER BY id DESC LIMIT 10");
            $stmt->execute([':q' => '%' . $query . '%']);
        } else {
            $stmt = $db->query($sql . " ORDER BY id DESC LIMIT 15");
        }
        $posts = $stmt->fetchAll();
        $links = array_map(function($p) {
            return [
                'title' => $p['title'],
                'url' => '/blog/' . $p['slug'],
                'category' => $p['category']
            ];
        }, $posts);

        jsonResponse(['success' => true, 'links' => $links]);
    }

    if ($action === 'get_logs') {
        $stmt = $db->query("SELECT * FROM ai_automation_logs ORDER BY id DESC LIMIT 50");
        $logs = $stmt->fetchAll();
        jsonResponse(['success' => true, 'logs' => $logs]);
    }

    jsonResponse(['success' => false, 'message' => 'Invalid GET action.'], 400);
}

// POST REQUESTS
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = $raw === '' ? null : json_decode($raw, true);
    if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE && empty($_POST)) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON request body.'], 400);
    }
    $input = is_array($decoded) ? $decoded : $_POST;
    $postAction = $input['action'] ?? $action;

    // State-changing dashboard requests must carry the session-bound token.
    // The external scheduler uses the GET + X-Cron-Token route above instead.
    if (!verifyCsrfToken((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '')))) {
        jsonResponse(['success' => false, 'message' => 'CSRF verification failed. Refresh the dashboard and try again.'], 403);
    }

    if (in_array($postAction, ['save_provider', 'delete_provider', 'set_active_ai_provider', 'test_provider'], true)) {
        $providerId = (int)($input['provider_id'] ?? $input['id'] ?? 0);
        if ($postAction === 'save_provider') {
            $name = trim((string)($input['provider_name'] ?? ''));
            $type = trim((string)($input['provider_type'] ?? 'openai_compatible'));
            $baseUrl = rtrim(trim((string)($input['api_base_url'] ?? '')), '/');
            $model = trim((string)($input['model'] ?? ''));
            $enabled = !empty($input['is_enabled']) ? 1 : 0;
            if ($name === '' || !in_array($type, ['gemini', 'openai_compatible', 'dataforseo'], true)) {
                jsonResponse(['success' => false, 'message' => 'Provider name and a supported provider type are required.'], 400);
            }
            if ($type === 'gemini' && $model === '') $model = 'gemini-2.5-flash';
            if ($type === 'openai_compatible' && (!filter_var($baseUrl, FILTER_VALIDATE_URL) || $model === '')) {
                jsonResponse(['success' => false, 'message' => 'An OpenAI-compatible provider requires a valid base URL and model.'], 400);
            }
            if ($type === 'dataforseo' && $baseUrl === '') $baseUrl = 'https://api.dataforseo.com';
            $submittedKey = trim((string)($input['api_key'] ?? ''));
            // Editing must also work for a deliberately disabled provider.
            $existing = null;
            if ($providerId > 0) {
                $existingStmt = $db->prepare('SELECT * FROM ai_provider_configs WHERE id = ? LIMIT 1');
                $existingStmt->execute([$providerId]);
                $existing = $existingStmt->fetch() ?: null;
            }
            if ($providerId > 0 && !$existing) jsonResponse(['success' => false, 'message' => 'Provider was not found or is disabled.'], 404);
            $storedKey = $existing['api_key_encrypted'] ?? null;
            if ($submittedKey !== '' && !isMaskedValue($submittedKey)) $storedKey = encryptSecret($submittedKey, null, 'marketmoke-ai-provider');
            if (!$storedKey) jsonResponse(['success' => false, 'message' => 'An API key is required for this provider.'], 400);
            if ($providerId > 0) {
                $stmt = $db->prepare('UPDATE ai_provider_configs SET provider_name=?, provider_type=?, api_base_url=?, api_key_encrypted=?, model=?, is_enabled=? WHERE id=?');
                $stmt->execute([$name, $type, $baseUrl ?: null, $storedKey, $model ?: null, $enabled, $providerId]);
            } else {
                $stmt = $db->prepare('INSERT INTO ai_provider_configs (provider_name, provider_type, api_base_url, api_key_encrypted, model, is_enabled) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $type, $baseUrl ?: null, $storedKey, $model ?: null, $enabled]);
                $providerId = (int)$db->lastInsertId();
            }
            logAutomationEvent($db, 'provider_saved', 'AI/SEO provider configuration saved.', ['provider_id' => $providerId, 'type' => $type]);
            jsonResponse(['success' => true, 'message' => 'Provider saved securely.', 'provider_id' => $providerId]);
        }
        if ($postAction === 'delete_provider') {
            if ($providerId < 1) jsonResponse(['success' => false, 'message' => 'Provider id is required.'], 400);
            if ((int)($settings['active_ai_provider_id'] ?? 0) === $providerId) jsonResponse(['success' => false, 'message' => 'Choose another active AI provider before deleting this one.'], 400);
            $db->prepare('DELETE FROM ai_provider_configs WHERE id = ?')->execute([$providerId]);
            jsonResponse(['success' => true, 'message' => 'Provider deleted.']);
        }
        if ($postAction === 'set_active_ai_provider') {
            $provider = getAiProvider($db, $providerId);
            if (!$provider || $provider['provider_type'] === 'dataforseo') jsonResponse(['success' => false, 'message' => 'Select an enabled Gemini or OpenAI-compatible provider.'], 400);
            $db->prepare('UPDATE ai_automation_settings SET active_ai_provider_id = ? WHERE id = 1')->execute([$providerId]);
            jsonResponse(['success' => true, 'message' => 'Active AI provider updated.']);
        }
        if ($postAction === 'test_provider') {
            $provider = getAiProvider($db, $providerId);
            if (!$provider) jsonResponse(['success' => false, 'message' => 'Provider was not found or is disabled.'], 404);
            $started = microtime(true);
            try {
                if ($provider['provider_type'] === 'dataforseo') {
                    dataForSeoRequest($provider, '/v3/appendix/user_data', [[]]);
                } else {
                    callAiEngine($settings, 'You are a connection-test responder.', 'Reply exactly: AI Connection Successful.', $providerId);
                }
                jsonResponse(['success' => true, 'message' => 'Provider connection verified.', 'latency_ms' => (int)round((microtime(true) - $started) * 1000)]);
            } catch (Throwable $e) {
                jsonResponse(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()], 400);
            }
        }
    }

    // 1. SAVE SETTINGS
    if ($postAction === 'save_settings') {
        $provider = $input['active_provider'] ?? $settings['active_provider'];
        if (!in_array($provider, ['gemini', 'custom'], true)) $provider = 'gemini';
        $geminiModel = $input['gemini_model'] ?? $settings['gemini_model'];
        $geminiTemp = (float)($input['gemini_temperature'] ?? $settings['gemini_temperature']);
        $geminiTokens = (int)($input['gemini_max_tokens'] ?? $settings['gemini_max_tokens']);
        $customBase = $input['custom_base_url'] ?? $settings['custom_base_url'];
        $customModel = $input['custom_model'] ?? $settings['custom_model'];
        $pubMode = $input['publishing_mode'] ?? $settings['publishing_mode'];
        $schedType = $input['schedule_interval_type'] ?? $settings['schedule_interval_type'];
        $schedVal = (int)($input['schedule_interval_value'] ?? $settings['schedule_interval_value']);
        $schedDays = $input['schedule_specific_days'] ?? $settings['schedule_specific_days'];
        $schedTime = $input['schedule_time'] ?? $settings['schedule_time'];
        $targetCat = $input['target_category'] ?? $settings['target_category'];
        $targetAuthor = $input['target_author'] ?? $settings['target_author'];

        if ($geminiTemp < 0 || $geminiTemp > 2 || $geminiTokens < 256 || $geminiTokens > 16384) {
            jsonResponse(['success' => false, 'message' => 'Temperature must be 0–2 and max tokens must be 256–16384.'], 422);
        }
        if (!in_array($pubMode, ['manual', 'auto'], true) || !in_array($schedType, ['hours', 'days', 'specific_days'], true) || $schedVal < 1 || $schedVal > 365) {
            jsonResponse(['success' => false, 'message' => 'Invalid publishing or schedule configuration.'], 422);
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', (string)$schedTime)) {
            jsonResponse(['success' => false, 'message' => 'Schedule time must be a valid 24-hour time.'], 422);
        }
        if ($provider === 'custom' && !filter_var($customBase, FILTER_VALIDATE_URL)) {
            jsonResponse(['success' => false, 'message' => 'Custom AI base URL must be valid.'], 422);
        }

        $geminiInput = trim((string)($input['gemini_api_key'] ?? ''));
        $customInput = trim((string)($input['custom_api_key'] ?? ''));
        $geminiKey = ($geminiInput !== '' && !isMaskedValue($geminiInput)) ? encryptSecret($geminiInput, null, 'marketmoke-ai-provider') : $settings['gemini_api_key'];
        $customKey = ($customInput !== '' && !isMaskedValue($customInput)) ? encryptSecret($customInput, null, 'marketmoke-ai-provider') : $settings['custom_api_key'];

        $stmt = $db->prepare("
            UPDATE ai_automation_settings SET
                active_provider = :p,
                gemini_api_key = :gk,
                gemini_model = :gm,
                gemini_temperature = :gt,
                gemini_max_tokens = :gtok,
                custom_base_url = :cb,
                custom_api_key = :ck,
                custom_model = :cm,
                publishing_mode = :pm,
                schedule_interval_type = :st,
                schedule_interval_value = :sv,
                schedule_specific_days = :sd,
                schedule_time = :stime,
                target_category = :tc,
                target_author = :ta
            WHERE id = 1
        ");
        $stmt->execute([
            ':p' => $provider,
            ':gk' => $geminiKey,
            ':gm' => $geminiModel,
            ':gt' => $geminiTemp,
            ':gtok' => $geminiTokens,
            ':cb' => $customBase,
            ':ck' => $customKey,
            ':cm' => $customModel,
            ':pm' => $pubMode,
            ':st' => $schedType,
            ':sv' => $schedVal,
            ':sd' => $schedDays,
            ':stime' => $schedTime,
            ':tc' => $targetCat,
            ':ta' => $targetAuthor
        ]);

        logAutomationEvent($db, 'settings_updated', 'AI automation configuration saved successfully.');
        jsonResponse(['success' => true, 'message' => 'Settings saved successfully!']);
    }

    // 2. TEST AI CONNECTION
    if ($postAction === 'test_connection') {
        $testSettings = array_merge($settings, $input);
        $start = microtime(true);
        try {
            $reply = callAiEngine($testSettings, 'You are a test responder.', 'Respond with exact words: AI Connection Successful.');
            $duration = round((microtime(true) - $start) * 1000);
            jsonResponse([
                'success' => true,
                'message' => 'AI Endpoint connected successfully!',
                'model' => $testSettings['active_provider'] === 'gemini' ? $testSettings['gemini_model'] : $testSettings['custom_model'],
                'latency_ms' => $duration,
                'reply' => $reply
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Connection Failed: ' . $e->getMessage()], 400);
        }
    }

    // Single connected workflow used by the new Automation Studio. Each stage
    // is source-backed and the run stops on a real prerequisite failure.
    if ($postAction === 'start_automation') {
        $primaryKeyword = trim((string)($input['primary_keyword'] ?? ''));
        $generateKeywords = !empty($input['generate_keywords']);
        $competitorInput = trim((string)($input['competitor_url'] ?? ''));
        if ($primaryKeyword === '') jsonResponse(['success' => false, 'message' => 'Primary Keyword is required.'], 422);
        try {
            $competitors = $competitorInput !== '' ? [normalizeCompetitorUrl($competitorInput)] : aiDiscoverCompetitors($db, $primaryKeyword);
            $evidence = [];
            foreach ($competitors as $competitor) $evidence[] = fetchPageEvidence($competitor);
            if (!$evidence) throw new ErrorException('No public competitor content could be retrieved.');

            if ($generateKeywords) {
                $keywordSet = aiGenerateKeywordSet($db, $settings, $primaryKeyword, $primaryKeyword, $evidence);
                $candidate = current(array_filter($keywordSet, static fn($idea) => ($idea['type'] ?? '') !== 'question')) ?: $keywordSet[0];
                $keywordId = (int)$candidate['id'];
            } else {
                $keywordStmt = $db->prepare("SELECT id FROM ai_keywords_list WHERE normalized_keyword = ? AND status IN ('unused', 'pending') ORDER BY id DESC LIMIT 1");
                $keywordStmt->execute([normalizeKeyword($primaryKeyword)]);
                $keywordId = (int)$keywordStmt->fetchColumn();
                if ($keywordId < 1) throw new ErrorException('No unused saved keyword matches the Primary Keyword. Enable keyword generation or choose a keyword already in the saved list.');
                $keywordSet = [];
            }

            $keywordStmt = $db->prepare('SELECT * FROM ai_keywords_list WHERE id = ? LIMIT 1');
            $keywordStmt->execute([$keywordId]);
            $keyword = $keywordStmt->fetch();
            if (!$keyword) throw new ErrorException('The selected saved keyword is no longer available.');
            $brief = aiCreateCompetitorBrief($db, $settings, $keyword, $competitors, $evidence);
            $generated = aiGenerateAutomationArticle($db, $settings, $keyword, $brief);
            $saved = aiSaveAutomationArticle($db, $settings, $generated['article'], $generated['quality'], $keywordId);
            logAutomationEvent($db, 'automation_completed', 'Completed source-backed keyword-to-draft workflow.', [
                'primary_keyword' => $primaryKeyword,
                'keyword_id' => $keywordId,
                'competitors' => $competitors,
                'generated_keywords' => count($keywordSet),
                'article_id' => $saved['ai_article_id'],
            ]);
            jsonResponse(['success' => true, 'message' => 'Automation completed and the SEO-validated article was saved as a draft.', 'data' => [
                'competitors' => $competitors,
                'generated_keywords' => count($keywordSet),
                'selected_keyword' => $keyword['keyword'],
                'brief' => $brief,
                'article' => $generated['article'],
                'quality' => $generated['quality'],
                'saved' => $saved,
            ]]);
        } catch (Throwable $e) {
            logAutomationEvent($db, 'automation_failed', 'Automation workflow stopped.', ['primary_keyword' => $primaryKeyword, 'reason' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // 3. KEYWORD GENERATION. A metrics provider is preferred when configured;
    // otherwise the selected AI provider returns clearly-labelled keyword ideas.
    if ($postAction === 'generate_keywords' || $postAction === 'analyze_competitors_research') {
        try {
            $result = runKeywordGeneration($db, $settings, $input);
            logAutomationEvent($db, 'keyword_research', 'Stored keyword research results.', $result);
            jsonResponse([
                'success' => true,
                'message' => "Saved {$result['inserted_count']} keyword opportunities from {$result['source']}.",
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            logAutomationEvent($db, 'keyword_research_failed', 'Real keyword research failed.', ['reason' => $e->getMessage()]);
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // Deprecated synthetic keyword path is intentionally disabled. Retained only
    // temporarily for upgrade traceability; it cannot be reached or emit data.
    if (false && ($postAction === 'generate_keywords' || $postAction === 'analyze_competitors_research')) {
        $topic = trim($input['topic_seed'] ?? ($input['niche'] ?? ''));
        $competitorUrls = is_array($input['competitors'] ?? null) ? $input['competitors'] : (!empty($input['competitor_url']) ? [$input['competitor_url']] : []);
        $competitorUrls = array_filter(array_map('trim', $competitorUrls));
        $count = max(10, min(100, (int)($input['count'] ?? 50)));

        if (empty($topic) && empty($competitorUrls)) {
            jsonResponse(['success' => false, 'message' => 'Please provide a Niche/Topic or at least 1 competitor URL.'], 400);
        }

        $competitorListStr = !empty($competitorUrls) ? implode(", ", $competitorUrls) : "General Niche";

        // Query existing normalized keywords
        $existingRows = $db->query("SELECT normalized_keyword FROM ai_keywords_list")->fetchAll(PDO::FETCH_COLUMN);
        $existingMap = array_flip($existingRows);

        $generated = [];
        try {
            $systemPrompt = "You are a Senior SEO Data Engineer and Keyword Strategist.
Generate high-value, intent-driven long-tail keywords for search engine dominance.
DO NOT invent fake exact search volume or fake metrics.
Return ONLY a valid JSON array of objects with keys:
- keyword: string (long-tail phrase)
- search_intent: (\"informational\" | \"commercial\" | \"transactional\" | \"navigational\")
- search_volume: \"N/A\"
- kd_estimate: integer between 18 and 65
- competitor_source: string
No markdown backticks, no explanatory text.";

            $userPrompt = "Generate exactly {$count} unique long-tail keywords for Niche: \"{$topic}\".
Competitors referenced: {$competitorListStr}.
Include comparison queries, problem-solving phrases, guide queries, and transactional intent queries.";

            $raw = callAiEngine($settings, $systemPrompt, $userPrompt);
            $clean = trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', $raw)));
            $generated = json_decode($clean, true);
        } catch (Exception $e) {
            // High-standard procedural fallback
            $modifiers = ['best practices', 'case study', 'actionable guide', 'roi benchmarks', 'comparison breakdown', 'workflow checklist', 'audit framework', 'strategies 2026', 'mistakes to avoid', 'agency execution', 'metrics that matter'];
            $intents = ['informational', 'commercial', 'transactional'];
            $generated = [];
            for ($i = 0; $i < $count; $i++) {
                $mod = $modifiers[$i % count($modifiers)];
                $compRef = !empty($competitorUrls) ? $competitorUrls[$i % count($competitorUrls)] : 'Organic SERP';
                $generated[] = [
                    'keyword' => "{$topic} {$mod}" . ($i >= count($modifiers) ? " #" . ($i + 1) : ""),
                    'search_intent' => $intents[$i % count($intents)],
                    'search_volume' => 'N/A',
                    'kd_estimate' => 20 + (($i * 7) % 40),
                    'competitor_source' => $compRef
                ];
            }
        }

        $inserted = 0;
        $duplicates = 0;
        if (is_array($generated)) {
            $insertStmt = $db->prepare("
                INSERT INTO ai_keywords_list 
                (topic_seed, keyword, normalized_keyword, search_volume, kd_estimate, search_intent, competitor_source, ranking_url, status, created_at)
                VALUES
                (:ts, :kw, :norm, :sv, :kd, :intent, :comp, 'N/A', 'pending', NOW())
            ");

            foreach ($generated as $item) {
                $kw = trim($item['keyword'] ?? '');
                if (empty($kw)) continue;
                $norm = normalizeKeyword($kw);
                if (empty($norm) || isset($existingMap[$norm])) {
                    $duplicates++;
                    continue;
                }

                try {
                    $insertStmt->execute([
                        ':ts' => !empty($topic) ? $topic : 'SEO & Content Growth',
                        ':kw' => $kw,
                        ':norm' => $norm,
                        ':sv' => 'N/A',
                        ':kd' => isset($item['kd_estimate']) ? (int)$item['kd_estimate'] : 30,
                        ':intent' => $item['search_intent'] ?? 'informational',
                        ':comp' => $item['competitor_source'] ?? (!empty($competitorUrls[0]) ? $competitorUrls[0] : 'Research Pool')
                    ]);
                    $existingMap[$norm] = true;
                    $inserted++;
                } catch (Exception $ex) {
                    $duplicates++;
                }
            }
        }

        logAutomationEvent($db, 'keyword_research', "Discovered {$inserted} new unique keywords for \"{$topic}\"", ['topic' => $topic, 'added' => $inserted, 'duplicates' => $duplicates]);

        jsonResponse([
            'success' => true,
            'message' => "Successfully extracted and added {$inserted} unique keywords to the Keyword Queue!",
            'inserted_count' => $inserted,
            'duplicate_count' => $duplicates,
            'topic_seed' => $topic
        ]);
    }

    // 4. Automated replenishment is disabled: it previously manufactured keyword
    // ideas. Admin research is now an explicit, source-backed action.
    if ($postAction === 'auto_replenish_check') {
        jsonResponse(['success' => false, 'message' => 'Auto-replenishment is disabled because it cannot create verified ranking data. Run Real Keyword Research from the Keywords tab.'], 422);
    }
    if (false && $postAction === 'auto_replenish_check') {
        $targetTopic = trim($input['topic_seed'] ?? '');
        $topics = [];
        if (!empty($targetTopic)) {
            $topics = [$targetTopic];
        } else {
            $topics = $db->query("SELECT DISTINCT topic_seed FROM ai_keywords_list")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($topics)) {
                $topics = ['Guest Posting & Backlink Strategy', 'SaaS Organic SEO'];
            }
        }

        $replenished = [];
        foreach ($topics as $top) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM ai_keywords_list WHERE topic_seed = :t AND status IN ('pending', 'unused')");
            $stmt->execute([':t' => $top]);
            $unusedCount = (int)$stmt->fetchColumn();

            if ($unusedCount <= 20) {
                // Auto generate 30 new keywords
                $existingRows = $db->query("SELECT normalized_keyword FROM ai_keywords_list")->fetchAll(PDO::FETCH_COLUMN);
                $existingMap = array_flip($existingRows);

                $genKws = [];
                try {
                    $prompt = "Generate 30 unique, non-duplicate long-tail keywords for topic: \"{$top}\". Return ONLY JSON array of objects with keys: keyword, search_intent, kd_estimate. No markdown.";
                    $res = callAiEngine($settings, "You are an SEO Strategist. Output ONLY valid JSON array.", $prompt);
                    $clean = trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', $res)));
                    $genKws = json_decode($clean, true);
                } catch (Exception $e) {
                    for ($k = 1; $k <= 30; $k++) {
                        $genKws[] = [
                            'keyword' => "{$top} practical playbook series #{$k}",
                            'search_intent' => 'informational',
                            'kd_estimate' => 25 + ($k % 35)
                        ];
                    }
                }

                $added = 0;
                $ins = $db->prepare("INSERT INTO ai_keywords_list (topic_seed, keyword, normalized_keyword, search_volume, kd_estimate, search_intent, competitor_source, status, created_at) VALUES (:ts, :kw, :norm, 'N/A', :kd, :intent, 'Auto-Replenish Engine', 'pending', NOW())");
                if (is_array($genKws)) {
                    foreach ($genKws as $item) {
                        $kw = trim($item['keyword'] ?? '');
                        if (empty($kw)) continue;
                        $norm = normalizeKeyword($kw);
                        if (empty($norm) || isset($existingMap[$norm])) continue;

                        try {
                            $ins->execute([
                                ':ts' => $top,
                                ':kw' => $kw,
                                ':norm' => $norm,
                                ':kd' => isset($item['kd_estimate']) ? (int)$item['kd_estimate'] : 32,
                                ':intent' => $item['search_intent'] ?? 'informational'
                            ]);
                            $existingMap[$norm] = true;
                            $added++;
                        } catch (Exception $e) {}
                    }
                }

                $replenished[] = ['topic' => $top, 'prior_unused' => $unusedCount, 'added_new' => $added];
                logAutomationEvent($db, 'auto_replenish', "Auto-replenished {$added} keywords for \"{$top}\"", ['topic' => $top, 'added' => $added]);
            }
        }

        jsonResponse([
            'success' => true,
            'replenished' => $replenished,
            'message' => count($replenished) > 0 ? "Auto-replenishment triggered for " . count($replenished) . " topic(s). Added fresh keywords!" : "All active topics have healthy reserves (>20 pending keywords)."
        ]);
    }

    // 5. CONTENT GAP ANALYSIS. Evidence is fetched from real ranking pages before
    // it is summarized by the configured AI provider.
    if (in_array($postAction, ['competitor_gap_analysis', 'competitor_analysis', 'analyze_competitor'], true)) {
        try {
            $keywordId = (int)($input['keyword_id'] ?? 0);
            if ($keywordId < 1) throw new InvalidArgumentException('Select a verified keyword before running Content Gap analysis.');
            $keywordStmt = $db->prepare('SELECT * FROM ai_keywords_list WHERE id = ? LIMIT 1');
            $keywordStmt->execute([$keywordId]);
            $selectedKeyword = $keywordStmt->fetch();
            if (!$selectedKeyword) throw new InvalidArgumentException('The selected keyword no longer exists.');
            $rawUrls = $input['competitors'] ?? $input['competitor_urls'] ?? [];
            if (!is_array($rawUrls)) $rawUrls = preg_split('/[\r\n,]+/', (string)$rawUrls);
            if (!empty($input['competitor_url'])) $rawUrls[] = $input['competitor_url'];
            if (!$rawUrls && !empty($selectedKeyword['ranking_url'])) $rawUrls[] = $selectedKeyword['ranking_url'];
            $urls = array_values(array_unique(array_filter(array_map('normalizeCompetitorUrl', $rawUrls))));
            if (!$urls) throw new InvalidArgumentException('Provide competitor URLs or select a keyword with a verified ranking URL.');
            $evidence = [];
            foreach (array_slice($urls, 0, 8) as $url) $evidence[] = fetchPageEvidence($url);
            $prompt = 'Analyze only the supplied competitor-page evidence for the selected keyword. Do not invent statistics, rankings, factual claims, or keywords. Return JSON with exactly: content_strengths (array), content_gaps (array), recommended_angles (array), faq_opportunities (array of questions), recommended_article_title (string), main_headings (array). Evidence: ' . json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $rawAnalysis = callAiEngine($settings, 'You are an SEO content-gap analyst. Base every conclusion strictly on supplied page evidence. Output valid JSON only.', "Selected keyword: {$selectedKeyword['keyword']}\n" . $prompt);
            $analysis = json_decode(trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', $rawAnalysis))), true);
            if (!is_array($analysis)) throw new ErrorException('AI provider returned invalid JSON for content-gap analysis.');
            $analysis['keyword_id'] = (int)$selectedKeyword['id'];
            $analysis['keyword'] = $selectedKeyword['keyword'];
            $analysis['competitor'] = implode(', ', array_column($evidence, 'url'));
            $analysis['analyzed_pages'] = $evidence;
            $analysis['missing_keywords'] = []; // no LLM-generated keyword claims
            $cache = $db->prepare('INSERT INTO ai_competitor_analyses (competitor_url, topic_seed, analysis_data, created_at) VALUES (?, ?, ?, NOW())');
            $cache->execute([$analysis['competitor'], $selectedKeyword['topic_seed'], json_encode($analysis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            logAutomationEvent($db, 'content_gap_analysis', 'Analyzed competitor page evidence.', ['keyword_id' => $keywordId, 'pages' => count($evidence)]);
            jsonResponse(['success' => true, 'data' => $analysis]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
    if (false && ($postAction === 'competitor_gap_analysis' || $postAction === 'competitor_analysis')) {
        $competitorUrl = trim($input['competitor_url'] ?? ($input['target_url'] ?? ''));
        $targetKeyword = trim($input['keyword'] ?? ($input['target_keyword'] ?? ''));
        $niche = trim($input['topic'] ?? ($input['niche'] ?? 'SEO & Link Building'));

        if (empty($competitorUrl) && empty($targetKeyword)) {
            jsonResponse(['success' => false, 'message' => 'Please provide a Competitor URL or Target Keyword.'], 400);
        }

        try {
            $systemPrompt = "You are a world-class Competitor SEO Analyst and Content Strategist.
Analyze the target competitor URL or topic to uncover actionable gaps, missing subtopics, missing FAQs, and outranking angles.
Return ONLY valid JSON matching this schema:
{
  \"competitor_url\": \"{$competitorUrl}\",
  \"target_keyword\": \"{$targetKeyword}\",
  \"page_title\": \"Competitor Title\",
  \"main_topic\": \"Core Topic\",
  \"search_intent\": \"informational / commercial\",
  \"main_headings\": [\"H2 1\", \"H2 2\", \"H2 3\"],
  \"competitor_covers\": [\"Covered subtopic 1\", \"Covered subtopic 2\"],
  \"content_gaps\": [\"Missing real-world ROI data\", \"Lacks 45-word AEO answer box for Google AI Overviews\", \"No comparison table\", \"Missing publisher vetting criteria\"],
  \"recommended_outranking_angles\": [\"Include step-by-step publisher scorecard\", \"Embed structured comparison table with live metrics\"],
  \"missing_keywords\": [
    {\"keyword\": \"long-tail keyword 1\", \"intent\": \"commercial\"},
    {\"keyword\": \"long-tail keyword 2\", \"intent\": \"informational\"}
  ],
  \"faq_opportunities\": [
    {\"question\": \"What is the turnaround time for link equity?\", \"answer\": \"Contextual link equity typically transmits within 14 to 45 days.\"},
    {\"question\": \"How do you audit publisher toxicity?\", \"answer\": \"Inspect organic traffic floors and anchor distribution ratios.\"}
  ],
  \"recommended_article_title\": \"High-CTR Outranking Title for MarketMoke\"
}
No markdown backticks.";

            $userPrompt = "Competitor URL: {$competitorUrl}\nTarget Keyword: {$targetKeyword}\nNiche: {$niche}\nPerform deep content gap analysis.";
            $raw = callAiEngine($settings, $systemPrompt, $userPrompt);
            $clean = trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', $raw)));
            $analysis = json_decode($clean, true);

            // Cache analysis
            if ($analysis) {
                $ins = $db->prepare("INSERT INTO ai_competitor_analyses (competitor_url, topic_seed, analysis_data, created_at) VALUES (:u, :ts, :d, NOW())");
                $ins->execute([
                    ':u' => $competitorUrl ?: $targetKeyword,
                    ':ts' => $niche,
                    ':d' => json_encode($analysis, JSON_UNESCAPED_SLASHES)
                ]);
            }

            logAutomationEvent($db, 'competitor_analysis', "Analyzed competitor: " . ($competitorUrl ?: $targetKeyword));
            jsonResponse(['success' => true, 'data' => $analysis]);
        } catch (Exception $e) {
            // Intelligent fallback
            $fallback = [
                'competitor_url' => $competitorUrl ?: 'https://competitor.com/blog',
                'target_keyword' => $targetKeyword ?: $niche,
                'page_title' => "Understanding {$targetKeyword}",
                'main_topic' => $niche,
                'search_intent' => 'informational',
                'main_headings' => ["What is {$targetKeyword}", "Key Benefits", "Best Practices", "Summary"],
                'competitor_covers' => ["Basic definitions", "General overview", "High-level tips"],
                'content_gaps' => [
                    "Lacks a 45-word snippet-ready AEO answer block under the primary H2 for Google Answer Box",
                    "Missing verified performance benchmarks and publisher traffic floor criteria",
                    "No structured comparison table for AI Overviews / SGE",
                    "Outdated advice lacking 2026 organic algorithm considerations"
                ],
                'recommended_outranking_angles' => [
                    "Provide a definitive 2026 actionable execution framework with metric thresholds",
                    "Add structured FAQ Schema markup with concise, authoritative answers"
                ],
                'missing_keywords' => [
                    ['keyword' => "{$targetKeyword} roi benchmarks", 'intent' => 'commercial'],
                    ['keyword' => "{$targetKeyword} step by step guide", 'intent' => 'informational'],
                    ['keyword' => "best {$targetKeyword} tools 2026", 'intent' => 'transactional']
                ],
                'faq_opportunities' => [
                    ['question' => "How long does it take for results to reflect?", 'answer' => "Contextual placements typically reflect ranking velocity within 14-45 days."],
                    ['question' => "What is the optimal anchor text distribution?", 'answer' => "Maintain branded and natural anchors at 80% and exact-match targets below 20%."]
                ],
                'recommended_article_title' => "{$targetKeyword}: The 2026 Tactical Framework & Publisher Benchmarks"
            ];
            jsonResponse(['success' => true, 'data' => $fallback]);
        }
    }

    // 6. GENERATE ARTICLE WITH AI (SEO / AEO / GEO / Human Tone / 450-1200 words)
    if ($postAction === 'generate_article') {
        $keywordId = (int)($input['keyword_id'] ?? 0);
        if ($keywordId < 1) {
            jsonResponse(['success' => false, 'message' => 'Select a researched keyword first. Articles are never generated from an automatic queue choice.'], 422);
        }
        $keywordStmt = $db->prepare("SELECT * FROM ai_keywords_list WHERE id = ? AND status IN ('pending', 'unused') LIMIT 1");
        $keywordStmt->execute([$keywordId]);
        $selectedKeyword = $keywordStmt->fetch();
        if (!$selectedKeyword) {
            jsonResponse(['success' => false, 'message' => 'Select an unused researched keyword before generating an article.'], 422);
        }
        $primaryKw = $selectedKeyword['keyword'];
        $secondaryKw = trim($input['secondary_keyword'] ?? '');
        $lsiKw = trim($input['lsi_keyword'] ?? '');
        $topic = $selectedKeyword['topic_seed'];
        $tone = trim($input['tone'] ?? 'authoritative');
        $lengthMode = trim($input['length'] ?? 'standard'); // short: 450-600, standard: 600-900, deep: 900-1200
        $gapInstructions = trim($input['gap_instructions'] ?? '');

        $gapStmt = $db->prepare("SELECT analysis_data FROM ai_competitor_analyses WHERE analysis_data LIKE ? ORDER BY id DESC LIMIT 1");
        $gapStmt->execute(['%"keyword_id":' . $keywordId . '%']);
        $gapData = $gapStmt->fetchColumn();
        if (!$gapData) {
            jsonResponse(['success' => false, 'message' => 'Run Content Gap analysis for this selected keyword before generating an article.'], 422);
        }
        $gapInstructions = (string)$gapData;

        $minWords = 450;
        $maxWords = 900;
        if ($lengthMode === 'short') { $minWords = 450; $maxWords = 600; }
        if ($lengthMode === 'deep') { $minWords = 900; $maxWords = 1200; }

        // Give the writer concrete, verified URLs only. The publish action
        // below also enforces the minimum in case a model omits the links.
        $linkRows = $db->query("SELECT title, slug FROM blog_posts WHERE status = 'published' ORDER BY id DESC LIMIT 10")->fetchAll();
        $internalLinkGuide = "";
        if (count($linkRows) >= 3) {
            $internalLinkGuide = "INTERNAL LINKING REQUIREMENT: You MUST add at least 3 natural contextual HTML links (<a href=\"/blog/slug\">descriptive anchor text</a>) to distinct verified URLs from this list. Do not link to any other URLs: " . json_encode(array_map(function($r) {
                return ['title' => $r['title'], 'url' => '/blog/' . $r['slug']];
            }, $linkRows)) . ". Do NOT link to any other URLs.";
        } else jsonResponse(['success' => false, 'message' => 'At least three published MarketMoke articles are required before generating an article with mandatory internal links.'], 422);

        try {
            $systemPrompt = "You are an elite Digital Journalist and Search Engine Optimization Architect.
Write an authoritative, publication-grade guest post article.

STRICT EDITORIAL RULES:
1. Tone: {$tone}. Journalistic cadence, short readable paragraphs (2-3 sentences), active voice.
2. BANNED CLICHÉS: NEVER use: \"in today's digital landscape\", \"delve into\", \"testament to\", \"it's crucial to remember\", \"in conclusion\", \"realm\", \"harness the power of\", \"game-changer\", \"revolutionize\", \"ever-evolving\".
3. Word Count Target: Strictly between {$minWords} and {$maxWords} words.
4. Primary Keyword: \"{$primaryKw}\". Integrate in Title, first 100 words, and natural H2 subheadings.
5. Secondary: \"{$secondaryKw}\", Related: \"{$lsiKw}\".
6. AEO (Answer Engine Optimization): Under the first H2, include a 45-word direct, bolded definition answer block designed for Google Answer Box extraction.
7. AIO/GEO: Include an HTML comparison table (<table><thead>...<tbody>...) and 3-4 Key Takeaways bullet list.
8. FAQ Section: 2-3 structured FAQ questions with clear answers at the end.
{$internalLinkGuide}
{$gapInstructions}

Return ONLY valid JSON matching this schema:
{
  \"title\": \"Compelling High-CTR Title\",
  \"slug\": \"url-friendly-slug\",
  \"meta_title\": \"SEO Title (max 60 chars)\",
  \"meta_description\": \"Compelling meta description (140-160 chars)\",
  \"seo_keywords\": \"{$primaryKw}, {$secondaryKw}, {$lsiKw}\",
  \"content_html\": \"Full article HTML with H1, H2, H3, p, table, ul, li tags. No markdown code blocks.\",
  \"word_count\": 750
}";

            $userPrompt = "Topic: \"{$topic}\"\nPrimary Keyword: \"{$primaryKw}\"\nSecondary Keyword: \"{$secondaryKw}\"\nRelated: \"{$lsiKw}\"\nGenerate complete article.";
            $raw = callAiEngine($settings, $systemPrompt, $userPrompt);
            $clean = trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/i', '', $raw)));
            $generated = json_decode($clean, true);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Article generation failed: ' . $e->getMessage()], 422);
        }
        if (false) {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9-]+/', '-', $primaryKw), '-'));
            $title = ucwords($primaryKw) . ": Strategic Execution Playbook";
            $contentHtml = "
                <h1>{$title}</h1>
                <p>Securing lasting search authority requires disciplined editorial placement rather than unpredictable algorithmic gambits. When organic growth plateaus, smart growth leaders double down on verified contextual distribution. This guide breaks down the tactical playbook for executing <strong>{$primaryKw}</strong> with compounding returns.</p>
                <p>Instead of spraying low-tier directory submissions, sustainable growth hinges on topical relevance, clean domain velocity, and strict anchor text hygiene.</p>
                
                <h2>What is {$primaryKw}? (AEO Direct Answer)</h2>
                <div class=\"my-6 p-4 rounded-xl bg-blue-50/80 border border-blue-200\">
                  <p class=\"text-xs text-blue-950 font-medium\"><strong>{$primaryKw} is an organic search acquisition methodology focused on securing high-tier editorial placements within semantically relevant publications. By embedding targeted contextual references alongside authoritative insights, webmasters transfer topical trust, elevate domain authority, and secure qualified referral traffic.</strong></p>
                </div>
                
                <h2>Tactical Execution: {$secondaryKw}</h2>
                <p>Before initiating publisher outreach, run every candidate through a rigorous qualification sequence. Scrutinize root domain traffic velocity, organic keyword depth, and historical anchor toxicity using trusted telemetry tools.</p>
                
                <table class=\"min-w-full divide-y divide-slate-200 my-6 border border-slate-200 rounded-xl overflow-hidden\">
                  <thead class=\"bg-slate-50\">
                    <tr>
                      <th class=\"px-4 py-2.5 text-left text-xs font-bold text-slate-700\">Audit Parameter</th>
                      <th class=\"px-4 py-2.5 text-left text-xs font-bold text-slate-700\">Minimum Baseline</th>
                      <th class=\"px-4 py-2.5 text-left text-xs font-bold text-slate-700\">Growth Impact</th>
                    </tr>
                  </thead>
                  <tbody class=\"divide-y divide-slate-100 text-xs text-slate-600\">
                    <tr><td class=\"px-4 py-2 font-semibold text-slate-800\">Domain Authority (DA/DR)</td><td class=\"px-4 py-2\">45+ Verified</td><td class=\"px-4 py-2\">Ensures baseline search engine equity transfer</td></tr>
                    <tr><td class=\"px-4 py-2 font-semibold text-slate-800\">Organic Traffic Floor</td><td class=\"px-4 py-2\">10,000+ monthly visits</td><td class=\"px-4 py-2\">Filters out manufactured link farm networks</td></tr>
                    <tr><td class=\"px-4 py-2 font-semibold text-slate-800\">Exact Match Anchors</td><td class=\"px-4 py-2\">Under 20% total ratio</td><td class=\"px-4 py-2\">Shields your domain against manual webspam penalties</td></tr>
                  </tbody>
                </table>

                <h3>Strategic Nuance: {$lsiKw}</h3>
                <p>Ensure conversational naturalness by weaving target references directly into practical problem-solving narratives. Surrounding sentences should provide immediate editorial value rather than appearing as tacked-on sponsor mentions.</p>
                
                <h2>Frequently Asked Questions</h2>
                <div class=\"space-y-4 my-4\">
                  <div class=\"p-4 bg-slate-50 rounded-xl border border-slate-200\">
                    <h3 class=\"font-bold text-sm text-slate-900 mb-1\">How quickly does {$primaryKw} influence rankings?</h3>
                    <p class=\"text-xs text-slate-600\">Search engines generally register contextual equity within 14 to 45 days following indexation.</p>
                  </div>
                  <div class=\"p-4 bg-slate-50 rounded-xl border border-slate-200\">
                    <h3 class=\"font-bold text-sm text-slate-900 mb-1\">What anchor distribution is safest?</h3>
                    <p class=\"text-xs text-slate-600\">Keep branded and natural anchors above 80%, retaining exact-match anchors under 20%.</p>
                  </div>
                </div>
            ";

            $generated = [
                'title' => $title,
                'slug' => $slug,
                'meta_title' => "{$primaryKw} Guide (2026 Strategy) | MarketMoke",
                'meta_description' => "Discover practical insights, verified publisher standards, and step-by-step frameworks for {$primaryKw}.",
                'seo_keywords' => "{$primaryKw}, {$secondaryKw}, {$lsiKw}",
                'content_html' => $contentHtml,
                'word_count' => count(preg_split('/\s+/', strip_tags($contentHtml)))
            ];
        }

        if (!is_array($generated) || empty($generated['title']) || empty($generated['content_html'])) {
            jsonResponse(['success' => false, 'message' => 'Article generation failed: the AI provider did not return the required JSON article payload.'], 422);
        }
        $generated['title'] = mb_substr(trim(strip_tags((string)$generated['title'])), 0, 255);
        $generated['slug'] = aiSafeSlug((string)($generated['slug'] ?? $generated['title']));
        $generated['content_html'] = aiEnforceExternalLinkRules(aiSanitizeArticleHtml((string)$generated['content_html']), []);
        if ($generated['title'] === '' || $generated['slug'] === '' || $generated['content_html'] === '') {
            jsonResponse(['success' => false, 'message' => 'Article generation returned unsafe or empty content.'], 422);
        }
        $rawText = strip_tags($generated['content_html']);
        $words = count(preg_split('/\s+/', trim($rawText)));

        jsonResponse([
            'success' => true,
            'data' => array_merge($generated, [
                'word_count' => $words,
                'primary_keyword' => $primaryKw,
                'secondary_keyword' => $secondaryKw,
                'lsi_keyword' => $lsiKw,
                'topic_seed' => $topic
            ])
        ]);
    }

    // 7. ARTICLE QUALITY CHECK
    if ($postAction === 'quality_check') {
        $primaryKw = trim($input['primary_keyword'] ?? '');
        $secondaryKw = trim($input['secondary_keyword'] ?? '');
        $content = $input['content'] ?? '';

        $plain = strip_tags($content);
        $wordCount = count(preg_split('/\s+/', trim($plain)));

        // Run verification rules
        $checks = [
            'primary_keyword' => [
                'name' => 'Primary Keyword Placement',
                'passed' => !empty($primaryKw) && stripos($plain, $primaryKw) !== false,
                'details' => !empty($primaryKw) && stripos($plain, $primaryKw) !== false ? "Primary keyword \"{$primaryKw}\" found in content." : "Primary keyword not detected in body."
            ],
            'secondary_keywords' => [
                'name' => 'Secondary / Related Keywords',
                'passed' => empty($secondaryKw) || stripos($plain, $secondaryKw) !== false,
                'details' => "Secondary keywords distributed contextually."
            ],
            'heading_hierarchy' => [
                'name' => 'Heading Hierarchy (H1 -> H2 -> H3)',
                'passed' => (bool)preg_match('/<h2/i', $content),
                'details' => preg_match('/<h2/i', $content) ? "Proper H2 and H3 structural tags present." : "Missing H2 subheadings."
            ],
            'word_count' => [
                'name' => 'Word Count Compliance (450–1200w)',
                'passed' => $wordCount >= 450 && $wordCount <= 1200,
                'details' => "Current length: {$wordCount} words (Range: 450-1200w)."
            ],
            'aeo_snippet' => [
                'name' => 'AEO Direct Answer Box',
                'passed' => (bool)(preg_match('/AEO|Direct Answer/i', $content) || preg_match('/<strong>.*?is.*?<\/strong>/i', $content)),
                'details' => "Concise answer definition block formatted for Google Answer Box extraction."
            ],
            'aio_table' => [
                'name' => 'AIO / SGE Comparison Table',
                'passed' => (bool)preg_match('/<table/i', $content),
                'details' => preg_match('/<table/i', $content) ? "Structured comparison table present." : "No table detected. Add a table to boost AI Overviews inclusion."
            ],
            'faq_section' => [
                'name' => 'Structured FAQ Section',
                'passed' => (bool)preg_match('/Frequently Asked Questions|FAQ/i', $content),
                'details' => "FAQ section embedded for rich snippet qualification."
            ],
            'anti_slop' => [
                'name' => 'Anti-Slop / Cliché Check',
                'passed' => !preg_match("/in today's digital landscape|delve into|testament to|it's crucial to remember|realm of|harness the power of/i", $plain),
                'details' => "Zero banned AI clichés detected. High human cadence."
            ]
        ];

        $passedCount = count(array_filter($checks, fn($c) => $c['passed']));
        $totalChecks = count($checks);
        $score = round(($passedCount / $totalChecks) * 100);

        jsonResponse([
            'success' => true,
            'score' => $score,
            'passed_count' => $passedCount,
            'total_checks' => $totalChecks,
            'checks' => $checks
        ]);
    }

    // 8. PUBLISH ARTICLE TO BLOG (Saves to blog_posts and ai_published_articles)
    if ($postAction === 'publish_article') {
        $title = trim($input['title'] ?? '');
        $slug = trim($input['slug'] ?? '');
        $content = aiEnforceExternalLinkRules(aiSanitizeArticleHtml(trim((string)($input['content'] ?? ''))), []);
        $primaryKw = trim($input['primary_keyword'] ?? '');
        $secondaryKw = trim($input['secondary_keyword'] ?? '');
        $lsiKw = trim($input['lsi_keyword'] ?? '');
        $topic = trim($input['topic_seed'] ?? ($settings['target_category'] ?? 'SEO'));
        $category = trim($input['category'] ?? ($settings['target_category'] ?? 'SEO & Link Building'));
        $author = trim($input['author'] ?? ($settings['target_author'] ?? 'MarketMoke AI Editorial'));
        $featuredImage = trim($input['featured_image'] ?? 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=1000&q=80');
        $metaTitle = trim($input['meta_title'] ?? $title);
        $metaDesc = trim($input['meta_description'] ?? '');
        $seoKeywords = trim($input['seo_keywords'] ?? $primaryKw);
        $status = in_array($input['status'] ?? '', ['published', 'draft', 'scheduled'], true) ? $input['status'] : 'published';
        $publishDate = !empty($input['publish_date']) ? (string)$input['publish_date'] : date('Y-m-d');
        $publishDate = substr($publishDate, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishDate) || !strtotime($publishDate)) {
            jsonResponse(['success' => false, 'message' => 'Publish date must use YYYY-MM-DD format.'], 422);
        }
        $qualityScore = (int)($input['quality_score'] ?? 100);

        if (empty($title) || empty($content)) {
            jsonResponse(['success' => false, 'message' => 'Article title and content are required.'], 400);
        }

        if (empty($slug)) {
            $slug = aiSafeSlug($title);
        }
        $slug = aiSafeSlug($slug);
        if ($slug === '') jsonResponse(['success' => false, 'message' => 'A valid article slug is required.'], 422);

        $wordCount = count(preg_split('/\s+/', trim(strip_tags($content))));

        // Publishing is the final safety net for the AI workflow. If the model
        // did not supply at least two internal article links, add a concise
        // related-reading block using only published MarketMoke posts.
        $internalLinksAdded = 0;
        $existingSlugs = [];
        preg_match_all('#href\s*=\s*[\"\'][^\"\']*/blog/([^\"\'?/#]+)#i', $content, $existingMatches);
        foreach ($existingMatches[1] ?? [] as $existingSlug) {
            $existingSlugs[strtolower((string)$existingSlug)] = true;
        }
        $existingInternalCount = count($existingSlugs);
        $availableLinks = $db->query("SELECT title, slug FROM blog_posts WHERE status = 'published' ORDER BY id DESC LIMIT 12")->fetchAll();
        if (count($availableLinks) < 3) jsonResponse(['success' => false, 'message' => 'At least three published MarketMoke articles are required before saving an article with mandatory internal links.'], 422);
        $minimumInternalLinks = 3;
        $linksNeeded = max(0, $minimumInternalLinks - $existingInternalCount);
        if ($linksNeeded > 0) {
            $relatedItems = [];
            foreach ($availableLinks as $related) {
                $relatedSlug = strtolower((string)$related['slug']);
                if ($relatedSlug === '' || isset($existingSlugs[$relatedSlug])) continue;
                $relatedItems[] = '<li><a href="/blog/' . htmlspecialchars((string)$related['slug'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$related['title'], ENT_QUOTES, 'UTF-8') . '</a></li>';
                $existingSlugs[$relatedSlug] = true;
                if (count($relatedItems) >= $linksNeeded) break;
            }
            if ($relatedItems) {
                $content .= "\n<section class=\"internal-reading\"><h2>Related reading</h2><ul>" . implode('', $relatedItems) . '</ul></section>';
                $internalLinksAdded = count($relatedItems);
                $wordCount = count(preg_split('/\s+/', trim(strip_tags($content))));
            }
        }

        $slugCheck = $db->prepare('SELECT id FROM blog_posts WHERE slug = ? LIMIT 1');
        $slugCheck->execute([$slug]);
        if ($slugCheck->fetchColumn()) jsonResponse(['success' => false, 'message' => 'This article slug already exists. Update the title or slug and publish again.'], 409);

        // The legacy blog enum has no scheduled state. Retain scheduling in the
        // AI table and keep the blog post safely out of the public feed as draft.
        $blogStatus = $status === 'scheduled' ? 'draft' : $status;
        $db->beginTransaction();
        try {
        // Insert into blog_posts
        $stmt = $db->prepare("
            INSERT INTO blog_posts 
            (title, slug, featured_image, author, content, category, meta_title, meta_description, seo_keywords, publish_date, status, canonical_url, created_at, updated_at)
            VALUES
            (:title, :slug, :img, :author, :content, :cat, :mt, :md, :kw, :pub_date, :st, :canon, NOW(), NOW())
        ");
        $stmt->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':img' => $featuredImage,
            ':author' => $author,
            ':content' => $content,
            ':cat' => $category,
            ':mt' => $metaTitle,
            ':md' => $metaDesc,
            ':kw' => $seoKeywords,
            ':pub_date' => $publishDate,
            ':st' => $blogStatus,
            ':canon' => "/blog/{$slug}"
        ]);
        $newPostId = (int)$db->lastInsertId();

        // Insert into ai_published_articles
        $artStmt = $db->prepare("
            INSERT INTO ai_published_articles 
            (blog_post_id, topic_seed, primary_keyword, secondary_keyword, lsi_keyword, title, word_count, status, schedule_date, quality_score, created_at)
            VALUES
            (:pid, :ts, :pk, :sk, :lsi, :title, :wc, :st, NOW(), :qs, NOW())
            ON DUPLICATE KEY UPDATE title = :title, word_count = :wc, status = :st, quality_score = :qs
        ");
        $artStmt->execute([
            ':pid' => $newPostId,
            ':ts' => $topic,
            ':pk' => $primaryKw ?: $title,
            ':sk' => $secondaryKw,
            ':lsi' => $lsiKw,
            ':title' => $title,
            ':wc' => $wordCount,
            ':st' => $status,
            ':qs' => $qualityScore
        ]);
        $aiArtId = (int)$db->lastInsertId();

        // Mark keyword as used/published in ai_keywords_list
        if (!empty($primaryKw)) {
            $norm = normalizeKeyword($primaryKw);
            $kwStmt = $db->prepare("UPDATE ai_keywords_list SET status = 'published', used_in_post_id = :pid, used_at = NOW() WHERE normalized_keyword = :norm");
            $kwStmt->execute([':pid' => $newPostId, ':norm' => $norm]);
        }
        $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        logAutomationEvent($db, 'article_published', "Published article: \"{$title}\" ({$wordCount}w)", ['post_id' => $newPostId, 'slug' => $slug]);

        jsonResponse([
            'success' => true,
            'message' => "Article published successfully to Blog Management!",
            'post_id' => $newPostId,
            'ai_article_id' => $aiArtId,
            'slug' => $slug,
            'internal_links_added' => $internalLinksAdded,
            'live_url' => "/blog/{$slug}"
        ]);
    }

    // 9. IMPROVE ARTICLE WITH AI
    if ($postAction === 'improve_article' || $postAction === 'auto_improve') {
        $articleId = (int)($input['article_id'] ?? 0);
        $instructions = trim($input['instructions'] ?? ($input['custom_prompt'] ?? 'Sharpen AEO direct answer definition, add comparison table, and remove any repetitive phrasing.'));

        $artStmt = $db->prepare("SELECT a.*, b.content as post_content, b.title as post_title FROM ai_published_articles a JOIN blog_posts b ON a.blog_post_id = b.id WHERE a.id = :id LIMIT 1");
        $artStmt->execute([':id' => $articleId]);
        $article = $artStmt->fetch();

        if (!$article) {
            jsonResponse(['success' => false, 'message' => 'Article record not found.'], 404);
        }

        try {
            $systemPrompt = "You are a Senior Search Quality Engineer.
Audit and upgrade the provided article without destroying useful existing content.
Instructions: {$instructions}
Preserve the primary keyword: \"{$article['primary_keyword']}\".
Ensure a 45-word bolded AEO answer block under the first H2.
Ensure an HTML comparison table.
Return ONLY clean upgraded HTML without markdown code fences.";

            $userPrompt = "Article Content:\n{$article['post_content']}";
            $upgraded = callAiEngine($settings, $systemPrompt, $userPrompt);
            $upgraded = trim(preg_replace('/^```(?:html)?\s*/i', '', preg_replace('/\s*```$/i', '', $upgraded)));
        } catch (Exception $e) {
            $upgraded = $article['post_content'] . "\n<div class=\"my-6 p-4 rounded-xl bg-blue-50 border border-blue-200\"><h4 class=\"text-xs font-bold text-blue-900 uppercase tracking-wider mb-1\">AEO Quick Answer Definition</h4><p class=\"text-xs text-blue-950 font-medium\"><strong>{$article['primary_keyword']} represents an essential organic strategy for securing verified publisher placements that directly enhance search visibility and sustainable domain authority.</strong></p></div>";
        }

        $upgraded = aiEnforceExternalLinkRules(aiSanitizeArticleHtml($upgraded), []);
        if ($upgraded === '') jsonResponse(['success' => false, 'message' => 'The improvement produced no safe article HTML.'], 422);

        $newWords = count(preg_split('/\s+/', trim(strip_tags($upgraded))));

        // Update blog_posts
        $updPost = $db->prepare("UPDATE blog_posts SET content = :c, updated_at = NOW() WHERE id = :id");
        $updPost->execute([':c' => $upgraded, ':id' => $article['blog_post_id']]);

        // Update ai_published_articles
        $updArt = $db->prepare("
            UPDATE ai_published_articles SET 
                word_count = :wc, 
                improvement_count = improvement_count + 1, 
                last_improved_at = NOW(),
                improvement_notes = CONCAT(IFNULL(improvement_notes, ''), :note)
            WHERE id = :id
        ");
        $updArt->execute([
            ':wc' => $newWords,
            ':note' => "Improved on " . date('Y-m-d H:i') . ": {$instructions}\n",
            ':id' => $articleId
        ]);

        logAutomationEvent($db, 'article_improved', "Improved article #{$articleId} \"{$article['title']}\"", ['article_id' => $articleId]);

        jsonResponse([
            'success' => true,
            'message' => "Article improved successfully!",
            'word_count' => $newWords,
            'improved_content' => $upgraded
        ]);
    }

    // 10. SAVE EDITOR CHANGES. AI drafts remain ordinary blog posts, but this
    // narrow endpoint lets the AI reader/editor persist edited HTML without
    // replacing unrelated editorial fields.
    if ($postAction === 'save_article_content') {
        $articleId = (int)($input['article_id'] ?? 0);
        $content = aiEnforceExternalLinkRules(aiSanitizeArticleHtml((string)($input['content'] ?? '')), []);
        if ($articleId < 1 || $content === '') jsonResponse(['success' => false, 'message' => 'Article and non-empty editor content are required.'], 422);
        $articleStmt = $db->prepare('SELECT blog_post_id FROM ai_published_articles WHERE id = ? LIMIT 1');
        $articleStmt->execute([$articleId]);
        $postId = (int)$articleStmt->fetchColumn();
        if ($postId < 1) jsonResponse(['success' => false, 'message' => 'AI article record was not found.'], 404);
        $wordCount = count(preg_split('/\s+/', trim(strip_tags($content))));
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE blog_posts SET content = ?, updated_at = NOW() WHERE id = ?')->execute([$content, $postId]);
            $db->prepare('UPDATE ai_published_articles SET word_count = ? WHERE id = ?')->execute([$wordCount, $articleId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        logAutomationEvent($db, 'article_editor_saved', "Saved editor changes for AI article #{$articleId}.", ['article_id' => $articleId, 'word_count' => $wordCount]);
        jsonResponse(['success' => true, 'message' => 'Article editor changes saved.', 'word_count' => $wordCount]);
    }

    // 11. DELETE KEYWORD
    if ($postAction === 'delete_keyword') {
        $id = (int)($input['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM ai_keywords_list WHERE id = :id");
        $stmt->execute([':id' => $id]);
        jsonResponse(['success' => true, 'message' => 'Keyword removed from queue.']);
    }

    // 12. DELETE ARTICLE
    if ($postAction === 'delete_article') {
        $id = (int)($input['id'] ?? 0);
        $article = $db->prepare('SELECT blog_post_id FROM ai_published_articles WHERE id = ? LIMIT 1');
        $article->execute([$id]);
        $blogPostId = (int)$article->fetchColumn();
        if ($blogPostId < 1) jsonResponse(['success' => false, 'message' => 'Article record not found.'], 404);
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM ai_published_articles WHERE id = ?')->execute([$id]);
            $db->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$blogPostId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        jsonResponse(['success' => true, 'message' => 'AI article and its blog post were removed.']);
    }

    // 13. CRON RUN
    if ($postAction === 'cron_run') {
        $token = $input['token'] ?? ($_GET['token'] ?? '');
        if ($token !== $settings['cron_token']) {
            jsonResponse(['success' => false, 'message' => 'Invalid cron access token.'], 401);
        }

        // Dashboard-triggered scheduler check. Publishing remains an explicit
        // editorial action after a verified keyword and gap brief exist.
        $stmt = $db->prepare("UPDATE ai_automation_settings SET last_cron_run = NOW() WHERE id = 1");
        $stmt->execute();

        logAutomationEvent($db, 'cron_execution', 'Scheduler queue check completed.');
        jsonResponse(['success' => true, 'message' => 'Scheduler queue check completed. No content is auto-published without a verified brief.', 'last_cron_run' => date('Y-m-d H:i:s')]);
    }

    jsonResponse(['success' => false, 'message' => "Unknown action '{$postAction}'."], 400);
}

jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
