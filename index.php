<?php
/**
 * MarketMoke - Guest Post Marketplace Entry Point
 * Handles API Routing or Serves Frontend HTML with Dynamic SEO for cPanel, XAMPP & Apache/Nginx
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/seo.php';

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// API Clean URL Routing
if (strpos($requestUri, '/api/login') !== false) {
    require_once __DIR__ . '/api/login.php';
    exit();
} elseif (strpos($requestUri, '/api/websites') !== false) {
    require_once __DIR__ . '/api/websites.php';
    exit();
} elseif (strpos($requestUri, '/api/marketplace') !== false) {
    require_once __DIR__ . '/api/marketplace.php';
    exit();
} elseif (strpos($requestUri, '/api/blogs') !== false) {
    require_once __DIR__ . '/api/blogs.php';
    exit();
} elseif (strpos($requestUri, '/api/orders') !== false) {
    require_once __DIR__ . '/api/orders.php';
    exit();
} elseif (strpos($requestUri, '/api/verify-link') !== false || strpos($requestUri, '/api/verify_link') !== false) {
    require_once __DIR__ . '/api/verify_link.php';
    exit();
} elseif (strpos($requestUri, '/api/admin/invoices') !== false || strpos($requestUri, '/api/payments') !== false || strpos($requestUri, '/api/invoices') !== false) {
    require_once __DIR__ . '/api/invoices.php';
    exit();
} elseif (strpos($requestUri, '/api/admin/gateways') !== false || strpos($requestUri, '/api/gateways') !== false) {
    require_once __DIR__ . '/api/gateways.php';
    exit();
} elseif (strpos($requestUri, '/api/admin') !== false) {
    require_once __DIR__ . '/api/admin.php';
    exit();
} elseif (strpos($requestUri, '/api/settings') !== false) {
    require_once __DIR__ . '/api/settings.php';
    exit();
} elseif (strpos($requestUri, '/api/pages') !== false) {
    require_once __DIR__ . '/api/pages.php';
    exit();
} elseif (strpos($requestUri, '/api/users') !== false) {
    require_once __DIR__ . '/api/users.php';
    exit();
} elseif (strpos($requestUri, '/api/dashboard') !== false) {
    require_once __DIR__ . '/api/dashboard.php';
    exit();
} elseif (strpos($requestUri, '/api/ai_automation') !== false) {
    require_once __DIR__ . '/api/ai_automation.php';
    exit();
} elseif (strpos($requestUri, '/api/send_otp') !== false) {
    require_once __DIR__ . '/api/send_otp.php';
    exit();
} elseif (strpos($requestUri, '/api/verify_otp') !== false) {
    require_once __DIR__ . '/api/verify_otp.php';
    exit();
} elseif (strpos($requestUri, '/api/contact') !== false) {
    require_once __DIR__ . '/api/contact.php';
    exit();
} elseif (strpos($requestUri, '/api/forgot_password') !== false) {
    require_once __DIR__ . '/api/forgot_password.php';
    exit();
} elseif (strpos($requestUri, '/api/reset_password') !== false) {
    require_once __DIR__ . '/api/reset_password.php';
    exit();
}

// Clean URL Routing for Admin Dashboard (/admin/overview, /admin/blog, /admin/users, etc.)
if (preg_match('~(?:^|/)admin(?:/.*)?$~i', $requestUri) || strpos($requestUri, 'admin-dashboard.php') !== false) {
    // Naked /admin, /admin/, or legacy admin-dashboard.php must redirect directly to /admin/overview
    if (preg_match('~(?:^|/)admin/?$~i', $requestUri) || strpos($requestUri, 'admin-dashboard.php') !== false) {
        $base = function_exists('getAppBasePath') ? rtrim(getAppBasePath(), '/') : '';
        header('Location: ' . $base . '/admin/overview', true, 301);
        exit();
    }
    require_once __DIR__ . '/admin/overview.php';
    exit();
}

// Clean URL Routing for User Dashboard (/user, /user/dashboard, etc.)
if (preg_match('~(?:^|/)user(?:/.*)?$~i', $requestUri) || strpos($requestUri, 'user-dashboard.php') !== false) {
    require_once __DIR__ . '/user/dashboard.php';
    exit();
}

// Dynamic SEO Setup for Frontend
$basePath = function_exists('getAppBasePath') ? getAppBasePath() : '';
$seoRequestPath = $requestUri;
if ($basePath !== '' && str_starts_with($seoRequestPath, $basePath)) {
    $seoRequestPath = substr($seoRequestPath, strlen($basePath)) ?: '/';
}
$seoConfig = seoConfig();
$siteName = $seoConfig['schema']['organization_name'] ?: 'MarketMoke';
$pageTitle = $seoConfig['seo']['default_meta_title'] ?: 'MarketMoke - Guest Post Marketplace';
$metaDescription = $seoConfig['seo']['default_meta_description'] ?: 'Buy and sell guest post spots and link insertions connecting bloggers, website owners, and advertisers, featuring an AI Guest Post & Content Automation Agent.';
$canonicalUrl = seoCanonical($seoRequestPath);
$ogImage = $seoConfig['seo']['og_image'] ?: ($basePath . '/assets/images/og-default.png');
$ogType = 'website';
$pageType = 'home';
$schemaEntity = ['title' => $pageTitle, 'description' => $metaDescription];

// Check for Blog Post URL: /blog/{slug_or_id} or /subfolder/blog/{slug_or_id}
if (preg_match('~(?:^|/)blog/([^/?#]+)~i', $requestUri, $matches)) {
    $blogSlug = trim($matches[1]);
    
    // Attempt to query database if available
    try {
        if (file_exists(__DIR__ . '/includes/db.php')) {
            require_once __DIR__ . '/includes/db.php';
            $db = Database::getInstance()->getConnection();
            if ($db) {
                $stmt = $db->prepare("SELECT * FROM blog_posts WHERE (slug = :slug OR id = :id) AND status = 'published' LIMIT 1");
                $stmt->execute([':slug' => $blogSlug, ':id' => is_numeric($blogSlug) ? (int)$blogSlug : 0]);
                $post = $stmt->fetch();
                if ($post) {
                    $rawTitle = $post['meta_title'] ?: $post['title'];
                    $pageTitle = (strpos($rawTitle, $siteName) !== false) ? $rawTitle : ($rawTitle . ' | ' . $siteName);
                    $metaDescription = $post['meta_description'] ?: ($post['excerpt'] ?: substr(strip_tags($post['content']), 0, 160));
                    $canonicalUrl = seoCanonical('/blog/' . rawurlencode($post['slug']), (string)($post['canonical_url'] ?? ''));
                    if (!empty($post['featured_image'])) {
                        $ogImage = $post['featured_image'];
                    }
                    $ogType = 'article';
                    $pageType = 'article';
                    $schemaEntity = ['title' => $post['title'], 'description' => $metaDescription, 'author' => $post['author'] ?? '', 'image' => $post['featured_image'] ?? ''];
                } else {
                    $pageTitle = 'Article Not Found | MarketMoke';
                }
            }
        }
    } catch (Exception $e) {
        // Fallback gracefully without breaking page render
    }
} elseif (preg_match('~(?:^|/)pages/([^/?#]+)~i', $requestUri, $matches) || (preg_match('~^/([a-zA-Z0-9_-]+)(?:\.php)?$~i', $requestUri, $matches) && !in_array(strtolower($matches[1]), ['marketplace', 'services', 'blog', 'admin', 'login', 'register', 'contact', 'about', 'invoices', 'gateways', 'settings', 'my-orders', 'browse-websites', 'sell-post', 'client-dashboard', 'publisher-dashboard', 'admin-login', 'verify-otp']))) {
    $pageSlug = trim($matches[1]);
    try {
        if (file_exists(__DIR__ . '/includes/db.php')) {
            require_once __DIR__ . '/includes/db.php';
            $db = Database::getInstance()->getConnection();
            if ($db) {
                $stmt = $db->prepare("SELECT * FROM pages WHERE (slug = :slug OR id = :id) AND status = 'published' LIMIT 1");
                $stmt->execute([':slug' => $pageSlug, ':id' => is_numeric($pageSlug) ? (int)$pageSlug : 0]);
                $pageItem = $stmt->fetch();
                if ($pageItem) {
                    $pageTitle = htmlspecialchars($pageItem['title']) . ' | MarketMoke';
                    $metaDescription = !empty($pageItem['meta_description']) ? $pageItem['meta_description'] : 'MarketMoke verified content page.';
                    $canonicalUrl = seoCanonical('/' . rawurlencode($pageItem['slug']), (string)($pageItem['canonical_url'] ?? ''));
                    $pageType = 'page';
                    $schemaEntity = ['title' => $pageItem['title'], 'description' => $metaDescription];
                }
            }
        }
    } catch (Exception $e) {}
} elseif (preg_match('~(?:^|/)contact(?:\.php)?$~i', $requestUri)) {
    $pageTitle = 'Contact Us - Get in Touch | MarketMoke';
    $metaDescription = 'Contact MarketMoke. Office 871, 85 Dunstall Hill, Wolverhampton, United Kingdom, WV6 0SR. Email: info@marketmoke.com. WhatsApp: +44 7737 133124.';
}
$schemas = seoBuildSchemas($pageType, $canonicalUrl, $schemaEntity);
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>" />
  <meta name="robots" content="<?= htmlspecialchars($seoConfig['seo']['robots_meta']) ?>" />
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>" />

  <!-- Open Graph / Social Sharing -->
  <meta property="og:type" content="<?= htmlspecialchars($ogType) ?>" />
  <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>" />
  <meta property="og:description" content="<?= htmlspecialchars($metaDescription) ?>" />
  <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>" />
  <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>" />
  
  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>" />
  <meta name="twitter:description" content="<?= htmlspecialchars($metaDescription) ?>" />
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>" />

  <?php foreach ($schemas as $index => $schema): ?>
  <script type="application/ld+json" id="seo-schema-<?= (int)$index ?>"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
  <?php endforeach; ?>
  <?php if (!empty($seoConfig['seo']['google_search_console_code'])): ?><meta name="google-site-verification" content="<?= htmlspecialchars($seoConfig['seo']['google_search_console_code']) ?>" /><?php endif; ?>
  <?php if (!empty($seoConfig['seo']['bing_webmaster_code'])): ?><meta name="msvalidate.01" content="<?= htmlspecialchars($seoConfig['seo']['bing_webmaster_code']) ?>" /><?php endif; ?>
  <?php if (!empty($seoConfig['seo']['google_analytics_id'])): ?><script async src="https://www.googletagmanager.com/gtag/js?id=<?= rawurlencode($seoConfig['seo']['google_analytics_id']) ?>"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config',<?= json_encode($seoConfig['seo']['google_analytics_id']) ?>);</script><?php endif; ?>
  <?= $seoConfig['custom_code']['custom_head_code'] ?>
  
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  
  <!-- Subfolder-aware Fetch Interceptor & Configuration -->
  <script>
    window.APP_BASE_PATH = <?= json_encode($basePath) ?>;
    (function() {
      if (window.APP_BASE_PATH) {
        var origFetch = window.fetch;
        window.fetch = function(url, init) {
          if (typeof url === 'string') {
            if (url.startsWith('/api/')) {
              url = window.APP_BASE_PATH + url;
            } else if (url.startsWith('/assets/')) {
              url = window.APP_BASE_PATH + url;
            } else if (url.startsWith('/uploads/')) {
              url = window.APP_BASE_PATH + url;
            }
          }
          return origFetch.call(this, url, init);
        };
      }
    })();
  </script>

  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>/assets/css/style.css" />
</head>
<body class="h-full font-sans text-slate-800 bg-slate-50 antialiased selection:bg-indigo-500 selection:text-white">
  <?= $seoConfig['custom_code']['custom_body_code'] ?>
  <div id="app" class="min-h-screen flex flex-col"></div>

  <!-- SheetJS for multi-sheet Excel (.xlsx/.xls) and CSV parsing -->
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

  <!-- Vanilla JS Application Script -->
  <script src="<?= htmlspecialchars($basePath) ?>/assets/js/app.js"></script>
</body>
</html>
