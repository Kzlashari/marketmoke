<?php
/**
 * MarketMoke - Admin & User Dashboard Statistics API
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'admin_overview';

if ($method === 'GET') {
    if ($action === 'admin_overview') {
        // Enforce admin permission
        if (!hasRole('admin')) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized access. Admin role required.'], 403);
        }

        try {
            $db = Database::getInstance()->getConnection();

            $totalWebsites = (int)$db->query("SELECT COUNT(*) FROM marketplace_websites")->fetchColumn();
            $activeWebsites = (int)$db->query("SELECT COUNT(*) FROM marketplace_websites WHERE status = 'active'")->fetchColumn();
            $totalBlogPosts = (int)$db->query("SELECT COUNT(*) FROM blog_posts")->fetchColumn();
            $totalPages = (int)$db->query("SELECT COUNT(*) FROM pages")->fetchColumn();
            $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $pendingApprovals = (int)$db->query("SELECT COUNT(*) FROM user_edit_requests WHERE status = 'pending'")->fetchColumn();
            $totalOrders = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
            $orderValue = (float)$db->query("SELECT COALESCE(SUM(price), 0) FROM orders WHERE status NOT IN ('cancelled')")->fetchColumn();

            // Recent websites
            $recentWebsites = $db->query("SELECT id, website_name, domain, da, dr, traffic, guest_post_price, currency, status, created_at FROM marketplace_websites ORDER BY id DESC LIMIT 5")->fetchAll();

            // Recent blog posts
            $recentPosts = $db->query("SELECT id, title, slug, author, category, status, publish_date FROM blog_posts ORDER BY id DESC LIMIT 5")->fetchAll();

            // Recent users
            $recentUsers = $db->query("SELECT id, name, email, role, status, created_at FROM users ORDER BY id DESC LIMIT 5")->fetchAll();

            // Recent activity
            $recentActivity = $db->query("SELECT id, user_name, action, details, ip_address, created_at FROM activity_logs ORDER BY id DESC LIMIT 6")->fetchAll();
            if (count($recentActivity) === 0) {
                // Fall back to actual records when no audit events have been
                // written yet, rather than rendering an empty activity panel.
                $recentActivity = $db->query("
                    SELECT 'System' AS user_name, 'marketplace_listing' AS action,
                           CONCAT('Marketplace listing added: ', website_name) AS details, created_at
                    FROM marketplace_websites
                    UNION ALL
                    SELECT name AS user_name, 'user_registered' AS action,
                           CONCAT('User account created: ', email) AS details, created_at
                    FROM users
                    UNION ALL
                    SELECT author AS user_name, 'blog_post' AS action,
                           CONCAT('Blog post created: ', title) AS details, created_at
                    FROM blog_posts
                    ORDER BY created_at DESC LIMIT 6
                ")->fetchAll();
            }

            jsonResponse([
                'success' => true,
                'message' => 'Admin overview loaded successfully',
                'data' => [
                    'counts' => [
                        'total_websites' => $totalWebsites,
                        'active_websites' => $activeWebsites,
                        'total_blog_posts' => $totalBlogPosts,
                        'total_pages' => $totalPages,
                        'total_users' => $totalUsers,
                        'pending_approvals' => $pendingApprovals,
                        'total_orders' => $totalOrders,
                        'order_value' => $orderValue
                    ],
                    'recent_websites' => $recentWebsites,
                    'recent_posts' => $recentPosts,
                    'recent_users' => $recentUsers,
                    'recent_activity' => $recentActivity
                ]
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to load stats: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'user_overview') {
        $currentUser = getCurrentUser();
        if (!$currentUser) {
            jsonResponse(['success' => false, 'message' => 'Please sign in to access your dashboard.'], 401);
        }

        try {
            $db = Database::getInstance()->getConnection();
            $userId = $currentUser['id'];

            $userStmt = $db->prepare("SELECT id, name, email, role, status, company, phone, bio, balance FROM users WHERE id = :id");
            $userStmt->execute([':id' => $userId]);
            $userData = $userStmt->fetch();

            $totalWebsites = (int)$db->query("SELECT COUNT(*) FROM marketplace_websites WHERE status = 'active'")->fetchColumn();

            $pendingReqStmt = $db->prepare("SELECT COUNT(*) FROM user_edit_requests WHERE user_id = :uid AND status = 'pending'");
            $pendingReqStmt->execute([':uid' => $userId]);
            $pendingRequestsCount = (int)$pendingReqStmt->fetchColumn();

            jsonResponse([
                'success' => true,
                'message' => 'User dashboard data loaded',
                'data' => [
                    'user' => $userData,
                    'active_marketplace_count' => $totalWebsites,
                    'pending_edit_requests' => $pendingRequestsCount
                ]
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
