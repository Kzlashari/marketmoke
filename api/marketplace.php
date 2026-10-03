<?php
// JSON endpoints must never leak PHP warnings or include output into fetch().
ob_start();
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
    error_log('[MarketMoke Marketplace API Error] ' . $e->getMessage());
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Internal server error.', 'error' => 'Internal server error.']);
    exit();
});
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[MarketMoke Marketplace API Fatal] ' . $error['message']);
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Internal server error.', 'error' => 'Internal server error.']);
    }
});
/**
 * MarketMoke - Marketplace & Inventory Management API
 * Provides catalog browsing, filtering, website listing, and admin approvals
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$db = Database::getInstance()->getConnection();

function normalizeMarketplaceDomain($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . $value;
    }
    $host = parse_url($value, PHP_URL_HOST) ?: '';
    // A URL can be valid syntactically while still not being a usable domain
    // (for example https://localhost).  Keep the importer error actionable.
    $host = strtolower(preg_replace('/^www\./i', '', trim($host)));
    return (str_contains($host, '.') && !preg_match('/\s/', $host)) ? $host : '';
}

/** Convert the common human spreadsheet formats without silently changing data. */
function marketplaceImportNumber($value, bool $integer = false): ?float {
    if ($value === null || trim((string)$value) === '') return null;
    $value = str_replace([',', ' '], '', trim((string)$value));
    if (preg_match('/^([+-]?[0-9]*\.?[0-9]+)k$/i', $value, $m)) $value = (string)((float)$m[1] * 1000);
    if (preg_match('/^([+-]?[0-9]*\.?[0-9]+)m$/i', $value, $m)) $value = (string)((float)$m[1] * 1000000);
    $value = preg_replace('/[^0-9.+-]/', '', $value);
    if ($value === '' || !is_numeric($value)) return null;
    $number = (float)$value;
    return $integer ? (float)(int)round($number) : $number;
}

function marketplaceImportCurrency($value): ?string {
    $value = strtoupper(trim((string)$value));
    if ($value === '') return null;
    if (preg_match('/\b(PKR|USD|EUR|GBP|CAD|AUD)\b/', $value, $m)) return $m[1];
    if (str_contains($value, '€')) return 'EUR';
    if (str_contains($value, '£')) return 'GBP';
    if (preg_match('/(?:RS\.?|₨)/i', $value)) return 'PKR';
    if (str_contains($value, '$')) return 'USD';
    return null;
}

function fetchGoogleSheetCsv(string $url): array {
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if ($host !== 'docs.google.com') {
        throw new InvalidArgumentException('Please provide a Google Sheets URL from docs.google.com.');
    }

    if (!preg_match('~/spreadsheets/d/([a-zA-Z0-9_-]+)~', $parts['path'] ?? '', $matches)) {
        throw new InvalidArgumentException('The Google Sheets document ID could not be found in the URL.');
    }
    parse_str($parts['query'] ?? '', $query);
    $fragment = $parts['fragment'] ?? '';
    if ($fragment !== '') {
        parse_str($fragment, $fragmentQuery);
        $query = array_merge($query, $fragmentQuery);
    }
    $gid = preg_replace('/\D/', '', (string)($query['gid'] ?? '0'));
    $exportUrl = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($matches[1]) . '/export?format=csv&gid=' . ($gid === '' ? '0' : $gid);

    $ch = curl_init($exportUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'MarketMoke Marketplace Importer/1.0'
    ]);
    $csv = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($csv === false || $status !== 200) {
        throw new RuntimeException($error ?: 'Google Sheets could not be downloaded. Confirm that the sheet is shared for viewing.');
    }

    $rows = preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $csv));
    $parsed = [];
    foreach ($rows as $row) {
        if ($row !== '') {
            $parsed[] = str_getcsv($row);
        }
    }
    if (count($parsed) < 2 || empty(array_filter($parsed[0], fn($cell) => trim((string)$cell) !== ''))) {
        throw new RuntimeException('The Google Sheet is empty or does not contain a header row.');
    }
    $headers = array_map(fn($header) => trim((string)$header), array_shift($parsed));
    return ['headers' => $headers, 'rows' => $parsed];
}

function logMarketplaceActivity(PDO $db, string $action, string $details): void {
    try {
        $userName = trim((string)($_SESSION['user_name'] ?? 'Administrator')) ?: 'Administrator';
        $stmt = $db->prepare('INSERT INTO activity_logs (user_name, action, details, ip_address, created_at) VALUES (:user_name, :action, :details, :ip_address, NOW())');
        $stmt->execute([
            ':user_name' => $userName,
            ':action' => $action,
            ':details' => $details,
            ':ip_address' => getClientIp()
        ]);
    } catch (Throwable $e) {
        // Activity logging must not make a valid marketplace operation fail.
        error_log('[MarketMoke Marketplace Activity Log Error] ' . $e->getMessage());
    }
}

// Ensure marketplace_websites table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `marketplace_websites` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT DEFAULT 1,
      `website_name` VARCHAR(150) NOT NULL,
      `website_url` VARCHAR(255) NOT NULL,
      `domain` VARCHAR(150) NOT NULL UNIQUE,
      `da` INT DEFAULT 0,
      `dr` INT DEFAULT 0,
      `traffic` INT DEFAULT 0,
      `traffic_source` VARCHAR(100) DEFAULT 'Organic Search',
      `country` VARCHAR(80) DEFAULT 'United States',
      `language` VARCHAR(50) DEFAULT 'English',
      `niche` VARCHAR(80) NOT NULL,
      `guest_post_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `currency` VARCHAR(10) DEFAULT 'USD',
      `single_website_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `link_type` ENUM('dofollow', 'nofollow') NOT NULL DEFAULT 'dofollow',
      `is_sponsored` TINYINT(1) NOT NULL DEFAULT 0,
      `turnaround_days` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
      `description` TEXT DEFAULT NULL,
      `status` ENUM('active', 'paused', 'pending', 'rejected') DEFAULT 'active',
      `is_featured` TINYINT(1) DEFAULT 0,
      `contact_info` VARCHAR(255) DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_mkt_domain` (`domain`),
      INDEX `idx_mkt_niche` (`niche`),
      INDEX `idx_mkt_country` (`country`),
      INDEX `idx_mkt_status` (`status`),
      INDEX `idx_mkt_public_status_id` (`status`, `id`),
      INDEX `idx_mkt_public_status_da` (`status`, `da`, `id`),
      INDEX `idx_mkt_public_status_dr` (`status`, `dr`, `id`),
      INDEX `idx_mkt_public_status_traffic` (`status`, `traffic`, `id`),
      INDEX `idx_mkt_public_status_price` (`status`, `guest_post_price`, `id`),
      INDEX `idx_mkt_public_status_country` (`status`, `country`, `id`),
      INDEX `idx_mkt_public_status_niche` (`status`, `niche`, `id`),
      INDEX `idx_mkt_public_status_language` (`status`, `language`, `id`),
      INDEX `idx_mkt_public_status_link` (`status`, `link_type`, `id`),
      FULLTEXT INDEX `idx_mkt_public_search` (`website_name`, `domain`, `niche`, `description`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // Table already exists or handled by schema
}

// Existing installations predate some public-catalog fields and indexes. Keep
// this migration idempotent so every API consumer reads the same inventory.
try {
    $columns = $db->query('SHOW COLUMNS FROM marketplace_websites')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'link_type' => "ALTER TABLE marketplace_websites ADD COLUMN link_type ENUM('dofollow', 'nofollow') NOT NULL DEFAULT 'dofollow' AFTER single_website_price",
        'is_sponsored' => 'ALTER TABLE marketplace_websites ADD COLUMN is_sponsored TINYINT(1) NOT NULL DEFAULT 0 AFTER link_type',
        'turnaround_days' => 'ALTER TABLE marketplace_websites ADD COLUMN turnaround_days SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER is_sponsored',
    ] as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $db->exec($sql);
        }
    }

    $indexes = $db->query('SHOW INDEX FROM marketplace_websites')->fetchAll(PDO::FETCH_COLUMN, 2);
    foreach ([
        'idx_mkt_public_status_id' => 'CREATE INDEX idx_mkt_public_status_id ON marketplace_websites (status, id)',
        'idx_mkt_public_status_da' => 'CREATE INDEX idx_mkt_public_status_da ON marketplace_websites (status, da, id)',
        'idx_mkt_public_status_dr' => 'CREATE INDEX idx_mkt_public_status_dr ON marketplace_websites (status, dr, id)',
        'idx_mkt_public_status_traffic' => 'CREATE INDEX idx_mkt_public_status_traffic ON marketplace_websites (status, traffic, id)',
        'idx_mkt_public_status_price' => 'CREATE INDEX idx_mkt_public_status_price ON marketplace_websites (status, guest_post_price, id)',
        'idx_mkt_public_status_country' => 'CREATE INDEX idx_mkt_public_status_country ON marketplace_websites (status, country, id)',
        'idx_mkt_public_status_niche' => 'CREATE INDEX idx_mkt_public_status_niche ON marketplace_websites (status, niche, id)',
        'idx_mkt_public_status_language' => 'CREATE INDEX idx_mkt_public_status_language ON marketplace_websites (status, language, id)',
        'idx_mkt_public_status_link' => 'CREATE INDEX idx_mkt_public_status_link ON marketplace_websites (status, link_type, id)',
        'idx_mkt_public_search' => 'CREATE FULLTEXT INDEX idx_mkt_public_search ON marketplace_websites (website_name, domain, niche, description)',
    ] as $index => $sql) {
        if (!in_array($index, $indexes, true)) {
            $db->exec($sql);
        }
    }
} catch (Throwable $e) {
    // The base table remains usable where the DB account cannot alter schema.
    // The checked-in migration contains the same changes for managed deploys.
    error_log('[MarketMoke Marketplace Schema Migration] ' . $e->getMessage());
}

// ----------------------------------------------------------------------------
// GET: Catalog, Filters, and Single Item
// ----------------------------------------------------------------------------
if ($method === 'GET') {
    // 1. Single Item Detail
    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Missing website ID.'], 400);
        }

        try {
            $visibility = hasRole('admin') ? '' : " AND status = 'active'";
            $stmt = $db->prepare("SELECT * FROM marketplace_websites WHERE id = :id{$visibility} LIMIT 1");
            $stmt->execute([':id' => $id]);
            $item = $stmt->fetch();

            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Website listing not found.'], 404);
            }

            jsonResponse(['success' => true, 'data' => $item]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    // 2. Categories & Niches Filter List
    elseif ($action === 'categories') {
        try {
            $stmt = $db->query("SELECT DISTINCT niche FROM marketplace_websites WHERE niche IS NOT NULL AND niche != '' AND status = 'active' ORDER BY niche ASC");
            $niches = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $stmtCountries = $db->query("SELECT DISTINCT country FROM marketplace_websites WHERE country IS NOT NULL AND country != '' AND status = 'active' ORDER BY country ASC");
            $countries = $stmtCountries->fetchAll(PDO::FETCH_COLUMN);

            jsonResponse([
                'success' => true,
                'data' => [
                    'niches' => $niches,
                    'countries' => $countries
                ]
            ]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    // 3. Catalog List with Advanced Multi-Dimensional Filtering
    else {
        try {
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $limit    = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset   = ($page - 1) * $limit;

            $niche    = trim($_GET['niche'] ?? '');
            $country  = trim($_GET['country'] ?? '');
            $language = trim($_GET['language'] ?? '');
            $search   = trim($_GET['search'] ?? '');
            $minDa    = isset($_GET['min_da']) && $_GET['min_da'] !== '' ? (int)$_GET['min_da'] : null;
            $maxDa    = isset($_GET['max_da']) && $_GET['max_da'] !== '' ? (int)$_GET['max_da'] : null;
            $minDr    = isset($_GET['min_dr']) && $_GET['min_dr'] !== '' ? (int)$_GET['min_dr'] : null;
            $maxDr    = isset($_GET['max_dr']) && $_GET['max_dr'] !== '' ? (int)$_GET['max_dr'] : null;
            $minTraffic = isset($_GET['min_traffic']) && $_GET['min_traffic'] !== '' ? max(0, (int)$_GET['min_traffic']) : null;
            $maxTraffic = isset($_GET['max_traffic']) && $_GET['max_traffic'] !== '' ? max(0, (int)$_GET['max_traffic']) : null;
            $minPrice = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : null;
            $maxPrice = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : null;
            $linkType = strtolower(trim($_GET['link_type'] ?? ''));
            $sponsored = $_GET['sponsored'] ?? '';
            $maxTurnaround = isset($_GET['max_turnaround']) && $_GET['max_turnaround'] !== '' ? max(0, (int)$_GET['max_turnaround']) : null;
            $status   = trim($_GET['status'] ?? 'active');

            // Build dynamic WHERE clause
            $where = ["1=1"];
            $params = [];

            // Status filter (Non-admins can only see 'active' sites)
            if (!hasRole('admin')) {
                $where[] = "status = 'active'";
            } elseif (!empty($status) && $status !== 'all') {
                $where[] = "status = :status";
                $params[':status'] = $status;
            }

            if (!empty($niche) && $niche !== 'all') {
                $where[] = "niche = :niche";
                $params[':niche'] = $niche;
            }

            if (!empty($country) && $country !== 'all') {
                $where[] = "country = :country";
                $params[':country'] = $country;
            }

            if (!empty($language) && $language !== 'all') {
                $where[] = "language = :language";
                $params[':language'] = $language;
            }

            if (!empty($search)) {
                // Use the catalog full-text index for normal queries. Very
                // short strings intentionally use a bounded prefix fallback.
                if (mb_strlen($search) >= 3) {
                    $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
                    $booleanQuery = implode(' ', array_map(
                        static fn(string $term): string => '+' . preg_replace('/[^\pL\pN._-]/u', '', $term) . '*',
                        $terms
                    ));
                    $where[] = "MATCH(website_name, domain, niche, description) AGAINST (:search IN BOOLEAN MODE)";
                    $params[':search'] = $booleanQuery;
                } else {
                    $where[] = "(website_name LIKE :search_prefix OR domain LIKE :search_prefix OR niche LIKE :search_prefix)";
                    $params[':search_prefix'] = "{$search}%";
                }
            }

            if ($minDa !== null) {
                $where[] = "da >= :min_da";
                $params[':min_da'] = $minDa;
            }
            if ($maxDa !== null) {
                $where[] = "da <= :max_da";
                $params[':max_da'] = $maxDa;
            }
            if ($minDr !== null) {
                $where[] = "dr >= :min_dr";
                $params[':min_dr'] = max(0, min(100, $minDr));
            }
            if ($maxDr !== null) {
                $where[] = "dr <= :max_dr";
                $params[':max_dr'] = max(0, min(100, $maxDr));
            }
            if ($minTraffic !== null) {
                $where[] = "traffic >= :min_traffic";
                $params[':min_traffic'] = $minTraffic;
            }
            if ($maxTraffic !== null) {
                $where[] = "traffic <= :max_traffic";
                $params[':max_traffic'] = $maxTraffic;
            }
            if ($minPrice !== null) {
                $where[] = "guest_post_price >= :min_price";
                $params[':min_price'] = $minPrice;
            }
            if ($maxPrice !== null) {
                $where[] = "guest_post_price <= :max_price";
                $params[':max_price'] = $maxPrice;
            }
            if (in_array($linkType, ['dofollow', 'nofollow'], true)) {
                $where[] = "link_type = :link_type";
                $params[':link_type'] = $linkType;
            }
            if ($sponsored === '0' || $sponsored === '1') {
                $where[] = "is_sponsored = :sponsored";
                $params[':sponsored'] = (int)$sponsored;
            }
            if ($maxTurnaround !== null) {
                $where[] = "turnaround_days <= :max_turnaround";
                $params[':max_turnaround'] = $maxTurnaround;
            }

            $whereSql = implode(' AND ', $where);

            // Sorting
            $sort = $_GET['sort'] ?? 'id_desc';
            $sortMap = [
                'id_desc'      => 'id DESC',
                'da_desc'      => 'da DESC, id DESC',
                'da_asc'       => 'da ASC, id DESC',
                'dr_desc'      => 'dr DESC, id DESC',
                'dr_asc'       => 'dr ASC, id DESC',
                'price_asc'    => 'guest_post_price ASC, id DESC',
                'price_desc'   => 'guest_post_price DESC, id DESC',
                'traffic_desc' => 'traffic DESC, id DESC',
                'traffic_asc'  => 'traffic ASC, id DESC'
            ];
            $orderBy = $sortMap[$sort] ?? 'id DESC';

            // Count total matching
            $countStmt = $db->prepare("SELECT COUNT(*) FROM marketplace_websites WHERE {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Fetch records
            // This is the public/admin inventory contract. Keep the response
            // page-sized; clients must never receive the complete catalog.
            $dataStmt = $db->prepare("SELECT
                id, website_name, website_url, domain, niche, da, dr,
                traffic, traffic AS monthly_traffic, traffic_source, country, language,
                guest_post_price, guest_post_price AS price_guest_post,
                single_website_price, single_website_price AS price_link_insertion,
                CASE WHEN link_type = 'nofollow' THEN 'NoFollow' ELSE 'DoFollow' END AS link_type,
                is_sponsored, turnaround_days, is_featured, status, description,
                1 AS is_verified, created_at, updated_at
                FROM marketplace_websites WHERE {$whereSql}
                ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}");
            $dataStmt->execute($params);
            $items = $dataStmt->fetchAll();

            $filterOptions = [
                'niches' => $db->query("SELECT DISTINCT niche FROM marketplace_websites WHERE niche IS NOT NULL AND niche != '' AND status = 'active' ORDER BY niche ASC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN),
                'countries' => $db->query("SELECT DISTINCT country FROM marketplace_websites WHERE country IS NOT NULL AND country != '' AND status = 'active' ORDER BY country ASC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN),
                'languages' => $db->query("SELECT DISTINCT language FROM marketplace_websites WHERE language IS NOT NULL AND language != '' AND status = 'active' ORDER BY language ASC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN)
            ];
            $pagination = [
                'current_page' => $page,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int)ceil($total / $limit))
            ];
            jsonResponse([
                'success' => true,
                'message' => 'Marketplace listings retrieved',
                'data' => [
                    // `websites` is the established admin dashboard contract;
                    // preserve `items` for existing public API consumers.
                    'websites' => $items,
                    'items' => $items,
                    'pagination' => $pagination,
                    'filter_options' => $filterOptions,
                    'source' => 'marketplace_websites'
                ]
            ]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }
}

// ----------------------------------------------------------------------------
// POST: Add, Edit, Delete, or Batch Import Listings
// ----------------------------------------------------------------------------
elseif ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? $_POST;
    $postAction = $input['action'] ?? $action;

    if (!verifyCsrfToken((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '')))) {
        jsonResponse(['success' => false, 'message' => 'CSRF verification failed. Refresh the page and try again.'], 403);
    }

    // Import a public Google Sheet as CSV. The browser continues to present
    // its normal mapping UI, and the subsequent import_batch persists data.
    if ($postAction === 'fetch_google_sheet') {
        if (!hasRole('admin')) {
            jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
        }
        try {
            $sheet = fetchGoogleSheetCsv(trim((string)($input['url'] ?? '')));
            jsonResponse([
                'success' => true,
                'data' => [
                    'sheet_names' => ['Sheet1'],
                    'sheets' => ['Sheet1' => ['headers' => $sheet['headers'], 'all_rows' => $sheet['rows']]]
                ]
            ]);
        } catch (Throwable $e) {
            error_log('[MarketMoke Google Sheet Import Error] ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to read the Google Sheet. Make sure it is shared for viewing.'], 422);
        }
    }

    // The Excel/CSV browser parser sends normalized records here. Persist the
    // actual records; do not report a successful import when it failed.
    if ($postAction === 'import_batch') {
        if (!hasRole('admin')) {
            jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
        }
        $items = $input['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            jsonResponse(['success' => false, 'message' => 'No valid website rows were supplied for import.'], 400);
        }
        if (count($items) > 100000) {
            jsonResponse(['success' => false, 'message' => 'A maximum of 1,000 websites can be imported in one request. Large files are sent in chunks automatically.'], 400);
        }

        $currentUser = getCurrentUser();
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $rowResults = [];
        try {
            $db->beginTransaction();
            $existingStmt = $db->prepare('SELECT * FROM marketplace_websites WHERE domain = :domain LIMIT 1');

            foreach ($items as $itemIndex => $item) {
                $rowNumber = max(1, (int)($item['row_number'] ?? ($itemIndex + 2)));
                if (!empty($item['skip_reason'])) {
                    $skipped++;
                    $rowResults[] = ['row' => $rowNumber, 'field' => 'Domain', 'reason' => mb_substr(trim((string)$item['skip_reason']), 0, 255), 'status' => 'skipped'];
                    continue;
                }
                $provided = array_values(array_unique(array_filter((array)($item['provided_fields'] ?? []), static fn($field) => is_string($field))));
                $domain = normalizeMarketplaceDomain($item['domain'] ?? $item['website_url'] ?? '');
                if ($domain === '' || !str_contains($domain, '.')) {
                    $failed++;
                    $rowResults[] = ['row' => $rowNumber, 'field' => 'Website / Domain', 'reason' => 'A valid public domain or URL is required.', 'status' => 'failed'];
                    continue;
                }
                foreach (['da' => 'DA', 'dr' => 'DR', 'traffic' => 'Traffic', 'guest_post_price' => 'Price', 'single_website_price' => 'Link Insertion Price'] as $numberField => $label) {
                    if (in_array($numberField, $provided, true) && trim((string)($item[$numberField] ?? '')) !== '' && marketplaceImportNumber($item[$numberField], in_array($numberField, ['da', 'dr', 'traffic'], true)) === null) {
                        $failed++;
                        $rowResults[] = ['row' => $rowNumber, 'field' => $label, 'reason' => 'Expected a numeric value (examples: 45, 12.5, 25K, or $99).', 'status' => 'failed'];
                        continue 2;
                    }
                }
                $existingStmt->execute([':domain' => $domain]);
                $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                $fieldValues = [];
                $fieldMap = [
                    'website_name' => ['website_name', static fn($v) => mb_substr(trim((string)$v), 0, 150)],
                    'website_url' => ['website_url', static fn($v) => mb_substr(trim((string)$v), 0, 255)],
                    'niche' => ['niche', static fn($v) => mb_substr(trim((string)$v), 0, 80)],
                    'country' => ['country', static fn($v) => mb_substr(trim((string)$v), 0, 80)],
                    'language' => ['language', static fn($v) => mb_substr(trim((string)$v), 0, 50)],
                    'description' => ['description', static fn($v) => trim((string)$v)],
                    'link_type' => ['link_type', static fn($v) => preg_replace('/[^a-z]/', '', strtolower(trim((string)$v))) === 'nofollow' ? 'nofollow' : 'dofollow'],
                    'currency' => ['currency', static fn($v) => marketplaceImportCurrency($v) ?? strtoupper(trim((string)$v))],
                    'da' => ['da', static fn($v) => marketplaceImportNumber($v, true)],
                    'dr' => ['dr', static fn($v) => marketplaceImportNumber($v, true)],
                    'traffic' => ['traffic', static fn($v) => marketplaceImportNumber($v, true)],
                    'guest_post_price' => ['guest_post_price', static fn($v) => marketplaceImportNumber($v)],
                    'single_website_price' => ['single_website_price', static fn($v) => marketplaceImportNumber($v)],
                ];
                foreach ($provided as $field) {
                    if (!isset($fieldMap[$field]) || !array_key_exists($field, $item) || trim((string)$item[$field]) === '') continue;
                    [$column, $normalizer] = $fieldMap[$field];
                    $value = $normalizer($item[$field]);
                    if ($value === null || $value === '') continue;
                    if (in_array($column, ['da', 'dr'], true)) $value = max(0, min(100, (int)$value));
                    if (in_array($column, ['traffic', 'guest_post_price', 'single_website_price'], true)) $value = max(0, $value);
                    $fieldValues[$column] = $value;
                }
                // Price cells often include their currency; preserve it only when
                // a supplied price makes that information meaningful.
                foreach (['guest_post_price', 'single_website_price'] as $priceField) {
                    if (in_array($priceField, $provided, true) && !empty($item[$priceField])) {
                        $currency = marketplaceImportCurrency($item[$priceField]);
                        if ($currency && !isset($fieldValues['currency'])) $fieldValues['currency'] = $currency;
                    }
                }
                if ($existing) {
                    if (!$fieldValues) {
                        $skipped++;
                        $rowResults[] = ['row' => $rowNumber, 'field' => 'Domain', 'reason' => 'Existing domain; no mapped non-empty values to update.', 'status' => 'skipped'];
                        continue;
                    }
                    $sets = []; $params = [':id' => $existing['id']];
                    foreach ($fieldValues as $column => $value) { $sets[] = "`{$column}` = :{$column}"; $params[":{$column}"] = $value; }
                    $db->prepare('UPDATE marketplace_websites SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')->execute($params);
                    $updated++;
                    $rowResults[] = ['row' => $rowNumber, 'status' => 'updated'];
                    continue;
                }
                // `niche` is the only legacy NOT NULL field without a default.
                // Its fallback is used solely when inserting a new record.
                $insertValues = array_merge([
                    'user_id' => (int)$currentUser['id'],
                    'website_name' => ucfirst(explode('.', $domain)[0]),
                    'website_url' => 'https://' . $domain,
                    'domain' => $domain,
                    'niche' => 'General',
                ], $fieldValues);
                $columns = array_keys($insertValues);
                $params = [];
                foreach ($insertValues as $column => $value) $params[':' . $column] = $value;
                $quotedColumns = implode(', ', array_map(static fn($column) => "`{$column}`", $columns));
                $placeholders = implode(', ', array_map(static fn($column) => ':' . $column, $columns));
                $db->prepare("INSERT INTO marketplace_websites ({$quotedColumns}, `status`, `is_featured`, `created_at`) VALUES ({$placeholders}, 'active', 0, NOW())")->execute($params);
                $inserted++;
                $rowResults[] = ['row' => $rowNumber, 'status' => 'imported'];
            }
            $db->commit();
            jsonResponse(['success' => true, 'message' => "Imported {$inserted} and updated {$updated} marketplace website(s).", 'data' => ['total' => count($items), 'imported' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'rows' => $rowResults]]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[MarketMoke Marketplace Batch Import Error] ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'The marketplace import could not be saved. No rows were added.'], 500);
        }
    }

    // Delete selected marketplace listings. Orders and other financial records
    // are intentionally retained; this action only removes marketplace data.
    if ($postAction === 'bulk_delete') {
        if (!hasRole('admin')) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized access. Admin role required.'], 403);
        }
        $ids = $input['ids'] ?? [];
        if (!is_array($ids)) {
            jsonResponse(['success' => false, 'message' => 'Selected website IDs are invalid.'], 400);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (count($ids) === 0) {
            jsonResponse(['success' => false, 'message' => 'Select at least one website to delete.'], 400);
        }
        if (count($ids) > 500) {
            jsonResponse(['success' => false, 'message' => 'A maximum of 500 websites can be deleted at once.'], 400);
        }
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM marketplace_websites WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();
            logMarketplaceActivity($db, 'marketplace_bulk_delete', "Deleted {$deleted} selected marketplace website(s).");
            jsonResponse(['success' => true, 'message' => "Deleted {$deleted} marketplace website(s).", 'data' => ['deleted' => $deleted]]);
        } catch (Throwable $e) {
            error_log('[MarketMoke Marketplace Bulk Delete Error] ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Selected marketplace websites could not be deleted.'], 500);
        }
    }

    // This is intentionally a separate operation with an exact confirmation
    // phrase, because it permanently removes every website listing.
    if ($postAction === 'clear_marketplace') {
        if (!hasRole('admin')) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized access. Admin role required.'], 403);
        }
        if (($input['confirmation'] ?? '') !== 'CLEAR MARKETPLACE') {
            jsonResponse(['success' => false, 'message' => 'Type CLEAR MARKETPLACE to permanently clear all marketplace listings.'], 400);
        }
        try {
            $db->beginTransaction();
            $deleted = (int)$db->query('SELECT COUNT(*) FROM marketplace_websites')->fetchColumn();
            $db->exec('DELETE FROM marketplace_websites');
            logMarketplaceActivity($db, 'marketplace_cleared', "Permanently cleared {$deleted} marketplace website listing(s).");
            $db->commit();
            jsonResponse(['success' => true, 'message' => "Marketplace cleared. {$deleted} website listing(s) were permanently deleted.", 'data' => ['deleted' => $deleted]]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[MarketMoke Marketplace Clear Error] ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Marketplace could not be cleared. No listings were deleted.'], 500);
        }
    }

    // 1. Create or Update Single Website Listing
    if (in_array($postAction, ['save', 'add', 'edit'], true)) {
        $currentUser = getCurrentUser();
        if (!$currentUser) {
            jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $id = (int)($input['id'] ?? 0);
        $websiteName = trim($input['website_name'] ?? '');
        $domain = normalizeMarketplaceDomain($input['domain'] ?? $input['website_url'] ?? '');
        $websiteUrl = trim((string)($input['website_url'] ?? ''));
        if ($websiteUrl === '') $websiteUrl = "https://{$domain}";
        $niche = trim($input['niche'] ?? 'General');
        $da = max(0, min(100, (int)($input['da'] ?? 0)));
        $dr = max(0, min(100, (int)($input['dr'] ?? 0)));
        $traffic = max(0, (int)($input['traffic'] ?? 0));
        $trafficSource = trim($input['traffic_source'] ?? 'Organic Search');
        $country = trim($input['country'] ?? 'United States');
        $language = trim($input['language'] ?? 'English');
        $price = (float)($input['guest_post_price'] ?? $input['price'] ?? 0.00);
        $singleWebsitePrice = (float)($input['single_website_price'] ?? $input['price_link_insertion'] ?? 0.00);
        $linkType = strtolower(trim((string)($input['link_type'] ?? 'dofollow')));
        $linkType = in_array($linkType, ['dofollow', 'nofollow'], true) ? $linkType : 'dofollow';
        $isSponsored = !empty($input['is_sponsored']) ? 1 : 0;
        $turnaroundDays = max(0, min(365, (int)($input['turnaround_days'] ?? 3)));
        $description = trim($input['description'] ?? '');

        // Publishers can submit pending listings; Admins can publish directly
        $status = hasRole('admin') ? ($input['status'] ?? 'active') : 'pending';

        if (empty($domain)) {
            jsonResponse(['success' => false, 'message' => 'Domain is required.'], 400);
        }
        if (!filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
            jsonResponse(['success' => false, 'message' => 'Website URL must be a valid HTTP(S) URL.'], 422);
        }
        if (!preg_match('#^https?://#i', $websiteUrl)) {
            jsonResponse(['success' => false, 'message' => 'Website URL must use HTTP or HTTPS.'], 422);
        }
        if (!in_array($status, ['active', 'paused', 'pending', 'rejected'], true)) {
            jsonResponse(['success' => false, 'message' => 'Invalid marketplace status.'], 422);
        }
        if (empty($websiteName)) {
            $websiteName = ucfirst($domain);
        }

        try {
            if ($id > 0) {
                // A publisher may only alter a listing they own.  Administrators
                // retain the marketplace-wide edit capability.
                if (!hasRole('admin')) {
                    $owner = $db->prepare('SELECT user_id FROM marketplace_websites WHERE id = :id LIMIT 1');
                    $owner->execute([':id' => $id]);
                    if ((int)$owner->fetchColumn() !== (int)$currentUser['id']) {
                        jsonResponse(['success' => false, 'message' => 'You can only update your own website listings.'], 403);
                    }
                }
                // Update
                $stmt = $db->prepare("UPDATE marketplace_websites SET 
                    website_name = :wname, website_url = :wurl, domain = :dom, da = :da, dr = :dr,
                    traffic = :trf, traffic_source = :tsrc, country = :cnt, language = :lng,
                    niche = :niche, guest_post_price = :price, single_website_price = :single_price,
                    link_type = :link_type, is_sponsored = :is_sponsored, turnaround_days = :turnaround_days,
                    description = :descr, status = :st, updated_at = NOW()
                    WHERE id = :id");
                
                $stmt->execute([
                    ':wname' => $websiteName,
                    ':wurl' => $websiteUrl,
                    ':dom' => $domain,
                    ':da' => $da,
                    ':dr' => $dr,
                    ':trf' => $traffic,
                    ':tsrc' => $trafficSource,
                    ':cnt' => $country,
                    ':lng' => $language,
                    ':niche' => $niche,
                    ':price' => $price,
                    ':single_price' => max(0, $singleWebsitePrice),
                    ':link_type' => $linkType,
                    ':is_sponsored' => $isSponsored,
                    ':turnaround_days' => $turnaroundDays,
                    ':descr' => $description,
                    ':st' => $status,
                    ':id' => $id
                ]);

                jsonResponse(['success' => true, 'message' => 'Website listing updated successfully!']);
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO marketplace_websites 
                    (user_id, website_name, website_url, domain, da, dr, traffic, traffic_source, country, language, niche, guest_post_price, single_website_price, link_type, is_sponsored, turnaround_days, description, status, created_at)
                    VALUES (:uid, :wname, :wurl, :dom, :da, :dr, :trf, :tsrc, :cnt, :lng, :niche, :price, :single_price, :link_type, :is_sponsored, :turnaround_days, :descr, :st, NOW())");

                $stmt->execute([
                    ':uid' => $currentUser['id'],
                    ':wname' => $websiteName,
                    ':wurl' => $websiteUrl,
                    ':dom' => $domain,
                    ':da' => $da,
                    ':dr' => $dr,
                    ':trf' => $traffic,
                    ':tsrc' => $trafficSource,
                    ':cnt' => $country,
                    ':lng' => $language,
                    ':niche' => $niche,
                    ':price' => $price,
                    ':single_price' => max(0, $singleWebsitePrice),
                    ':link_type' => $linkType,
                    ':is_sponsored' => $isSponsored,
                    ':turnaround_days' => $turnaroundDays,
                    ':descr' => $description,
                    ':st' => $status
                ]);

                jsonResponse(['success' => true, 'message' => 'Website listing submitted successfully!']);
            }
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    // 2. Admin Quick Status Toggle
    elseif ($postAction === 'update_status') {
        if (!hasRole('admin')) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized access. Admin role required.'], 403);
        }

        $id = (int)($input['id'] ?? 0);
        $newStatus = $input['status'] ?? '';
        if (!$id || !in_array($newStatus, ['active', 'paused', 'pending', 'rejected'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid status parameter.'], 400);
        }

        try {
            $stmt = $db->prepare("UPDATE marketplace_websites SET status = :st WHERE id = :id");
            $stmt->execute([':st' => $newStatus, ':id' => $id]);
            jsonResponse(['success' => true, 'message' => "Website status changed to {$newStatus}."]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    // 3. Delete Listing
    elseif ($postAction === 'delete') {
        if (!hasRole('admin')) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized access. Admin role required.'], 403);
        }

        $id = (int)($input['id'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM marketplace_websites WHERE id = :id");
            $stmt->execute([':id' => $id]);
            jsonResponse(['success' => true, 'message' => 'Website listing deleted successfully!']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['success' => false, 'message' => 'Unknown POST action.'], 400);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
