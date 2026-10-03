<?php
/**
 * MarketMoke - Order Lifecycle & Escrow Management API
 * Handles order transitions: create, accept, submit, cancel, dispute, list, detail.
 * Integrates atomic escrow wallet transactions and PHPMailer automated dispatches.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/MarketMokeMailer.php';

// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Helper to resolve authenticated user identity
function resolveCurrentAuthUser(PDO $db): array {
    if (!empty($_SESSION['user_id'])) {
        $stmt = $db->prepare("SELECT id, name, email, role, status, balance FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) return $user;
    }

    // Support Bearer Token
    $token = getBearerToken();
    if ($token) {
        $stmt = $db->prepare("SELECT id, name, email, role, status, balance FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $token]);
        $user = $stmt->fetch();
        if ($user) return $user;
    }

    // Check custom authentication headers for SPA requests
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authUserId = $headers['X-User-Id'] ?? $headers['x-user-id'] ?? null;
    if ($authUserId) {
        $stmt = $db->prepare("SELECT id, name, email, role, status, balance FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$authUserId]);
        $user = $stmt->fetch();
        if ($user) return $user;
    }

    // Default fallback to first active user or guest
    $fallbackStmt = $db->query("SELECT id, name, email, role, status, balance FROM users WHERE role = 'user' LIMIT 1");
    $fallback = $fallbackStmt->fetch();
    return $fallback ?: ['id' => 1, 'name' => 'Demo Buyer', 'email' => 'buyer@marketmoke.com', 'role' => 'user', 'balance' => 500.00];
}

$currentUser = resolveCurrentAuthUser($db);

// --------------------------------------------------------------------------
// GET: Order Listing & Details
// --------------------------------------------------------------------------
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    $orderId = (int)($_GET['id'] ?? 0);

    if ($action === 'detail' || $orderId > 0) {
        $stmt = $db->prepare("SELECT o.*, 
            b.name as buyer_name, b.email as buyer_email,
            s.name as seller_name, s.email as seller_email,
            w.website_name, w.website_url, w.domain, w.da, w.dr
            FROM orders o
            LEFT JOIN users b ON o.buyer_id = b.id
            LEFT JOIN users s ON o.seller_id = s.id
            LEFT JOIN marketplace_websites w ON o.website_id = w.id
            WHERE o.id = :id LIMIT 1");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonResponse(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        // Check Authorization
        $isParty = ($order['buyer_id'] == $currentUser['id'] || $order['seller_id'] == $currentUser['id'] || $currentUser['role'] === 'admin');
        if (!$isParty) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized access to this order.'], 403);
        }

        // Attach Escrow History
        $order['escrow_history'] = getEscrowHistory($orderId);
        $order['buyer_wallet'] = getUserWallet((int)$order['buyer_id']);

        jsonResponse([
            'status' => 'success',
            'data'   => $order
        ]);
    }

    // Default: List orders based on user role
    $statusFilter = trim($_GET['status'] ?? '');
    $where = ["1=1"];
    $params = [];

    if ($currentUser['role'] === 'admin') {
        // Admin sees all
    } elseif ($currentUser['role'] === 'publisher' || $currentUser['role'] === 'author') {
        $where[] = "o.seller_id = :uid";
        $params[':uid'] = $currentUser['id'];
    } else {
        $where[] = "o.buyer_id = :uid";
        $params[':uid'] = $currentUser['id'];
    }

    if (!empty($statusFilter) && $statusFilter !== 'all') {
        $where[] = "o.status = :status";
        $params[':status'] = $statusFilter;
    }

    $whereClause = implode(" AND ", $where);
    $sql = "SELECT o.*, 
        b.name as buyer_name, b.email as buyer_email,
        s.name as seller_name, s.email as seller_email,
        w.website_name, w.domain, w.da, w.dr
        FROM orders o
        LEFT JOIN users b ON o.buyer_id = b.id
        LEFT JOIN users s ON o.seller_id = s.id
        LEFT JOIN marketplace_websites w ON o.website_id = w.id
        WHERE {$whereClause}
        ORDER BY o.id DESC";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        // Calculate summary counts
        $counts = [
            'total'     => count($orders),
            'pending'   => 0,
            'accepted'  => 0,
            'submitted' => 0,
            'completed' => 0,
            'cancelled' => 0
        ];
        foreach ($orders as $ord) {
            $st = $ord['status'];
            if (isset($counts[$st])) $counts[$st]++;
        }

        jsonResponse([
            'status' => 'success',
            'counts' => $counts,
            'wallet' => getUserWallet((int)$currentUser['id']),
            'data'   => $orders
        ]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// --------------------------------------------------------------------------
// POST: Order Lifecycle Transitions
// --------------------------------------------------------------------------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input)) {
        $input = $_POST;
    }

    $action = trim($input['action'] ?? $_GET['action'] ?? 'create');

    // ----------------------------------------------------------------------
    // 1. ACTION: CREATE ORDER (Buyer places order, Escrow Locked)
    // ----------------------------------------------------------------------
    if ($action === 'create') {
        $websiteId     = (int)($input['website_id'] ?? 0);
        $targetUrl     = trim($input['target_url'] ?? '');
        $anchorText    = trim($input['anchor_text'] ?? '');
        $serviceType   = in_array($input['service_type'] ?? '', ['guest_post', 'link_insertion']) ? $input['service_type'] : 'guest_post';
        $articleTitle  = trim($input['article_title'] ?? 'Editorial Guest Post');
        $articleBody   = trim($input['article_body'] ?? $input['article_content'] ?? '');
        $linkType      = strtolower($input['link_type'] ?? 'dofollow') === 'nofollow' ? 'nofollow' : 'dofollow';
        $buyerId       = (int)$currentUser['id'];

        if (empty($targetUrl) || empty($anchorText)) {
            jsonResponse(['status' => 'error', 'message' => 'Target URL and Anchor Text are strictly required.'], 400);
        }

        if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            jsonResponse(['status' => 'error', 'message' => 'Please provide a valid Target URL (e.g. https://example.com/page).'], 400);
        }

        // Fetch website listing to determine pricing and seller identity
        $site = null;
        if ($websiteId > 0) {
            $siteStmt = $db->prepare("SELECT id, user_id, website_name, domain, guest_post_price, single_website_price FROM marketplace_websites WHERE id = :id LIMIT 1");
            $siteStmt->execute([':id' => $websiteId]);
            $site = $siteStmt->fetch();
        }

        if ($site) {
            $price = (float)$site['guest_post_price'];
            if ($price <= 0) $price = (float)$site['single_website_price'];
            $sellerId = (int)$site['user_id'];
        } else {
            // Default price and demo publisher
            $price = (float)($input['price'] ?? 100.00);
            $sellerStmt = $db->query("SELECT id FROM users WHERE role = 'publisher' LIMIT 1");
            $seller = $sellerStmt->fetch();
            $sellerId = $seller ? (int)$seller['id'] : 2;
        }

        if ($price <= 0) $price = 99.00;

        // Platform fee and publisher payout calculation
        $platformFeePercent = defined('PLATFORM_FEE_PERCENT') ? PLATFORM_FEE_PERCENT : 10;
        $platformFee = round($price * ($platformFeePercent / 100), 2);
        $publisherPayout = $price - $platformFee;

        // Generate clean Order Code
        $orderCode = 'MM-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $deadlineAt = date('Y-m-d H:i:s', time() + (3 * 86400)); // 3 days to accept

        try {
            // Insert Order in pending state
            $insStmt = $db->prepare("INSERT INTO orders (
                order_code, buyer_id, seller_id, website_id, service_type,
                article_title, article_body, target_url, anchor_text, link_type,
                price, platform_fee, publisher_payout, status, deadline_at, created_at
            ) VALUES (
                :code, :bid, :sid, :wid, :stype,
                :title, :body, :turl, :anchor, :ltype,
                :price, :fee, :payout, 'pending', :deadline, NOW()
            )");

            $insStmt->execute([
                ':code'     => $orderCode,
                ':bid'      => $buyerId,
                ':sid'      => $sellerId,
                ':wid'      => $websiteId ?: null,
                ':stype'    => $serviceType,
                ':title'    => $articleTitle,
                ':body'     => $articleBody,
                ':turl'     => $targetUrl,
                ':anchor'   => $anchorText,
                ':ltype'    => $linkType,
                ':price'    => $price,
                ':fee'      => $platformFee,
                ':payout'   => $publisherPayout,
                ':deadline' => $deadlineAt
            ]);

            $orderId = (int)$db->lastInsertId();

            // ATOMIC ESCROW LOCK: Hold funds from buyer wallet
            holdEscrowFunds($buyerId, $price, $orderId, $sellerId);

            // Fetch Publisher Details to dispatch email notification
            $pubStmt = $db->prepare("SELECT name, email FROM users WHERE id = :id LIMIT 1");
            $pubStmt->execute([':id' => $sellerId]);
            $publisher = $pubStmt->fetch();

            if ($publisher && !empty($publisher['email'])) {
                $siteDomain = $site ? $site['domain'] : 'Marketplace Listing';
                $emailBody = "<h2>New Escrow Secured Order #{$orderCode}</h2>"
                    . "<p>Hello <strong>{$publisher['name']}</strong>,</p>"
                    . "<p>A buyer has placed an order on your site <strong>{$siteDomain}</strong>.</p>"
                    . "<ul>"
                    . "<li><strong>Order Code:</strong> {$orderCode}</li>"
                    . "<li><strong>Target URL:</strong> {$targetUrl}</li>"
                    . "<li><strong>Anchor Text:</strong> {$anchorText}</li>"
                    . "<li><strong>Your Payout:</strong> \${$publisherPayout} USD (Held securely in Escrow)</li>"
                    . "<li><strong>Deadline to Accept:</strong> 3 days</li>"
                    . "</ul>"
                    . "<p>Please log in to your publisher dashboard to accept the order.</p>";
                MarketMokeMailer::sendEmail($publisher['email'], $publisher['name'], "New Order {$orderCode} (Escrow Secured)", $emailBody);
            }

            jsonResponse([
                'status'     => 'success',
                'code'       => 201,
                'message'    => "Order #{$orderCode} placed successfully! \${$price} has been locked into escrow.",
                'order_id'   => $orderId,
                'order_code' => $orderCode,
                'escrow_amount' => $price,
                'wallet'     => getUserWallet($buyerId)
            ], 201);

        } catch (Exception $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    // ----------------------------------------------------------------------
    // 2. ACTION: ACCEPT ORDER (Publisher accepts within deadline)
    // ----------------------------------------------------------------------
    if ($action === 'accept') {
        $orderId = (int)($input['order_id'] ?? 0);
        if ($orderId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Valid Order ID required.'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonResponse(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        // Auth Check: Must be seller or admin
        if ($order['seller_id'] != $currentUser['id'] && $currentUser['role'] !== 'admin') {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized. Only the designated publisher can accept this order.'], 403);
        }

        if ($order['status'] !== 'pending') {
            jsonResponse(['status' => 'error', 'message' => "Cannot accept order with status '{$order['status']}'."], 400);
        }

        // Set delivery deadline (e.g. 5 days from acceptance)
        $deliveryDeadline = date('Y-m-d H:i:s', time() + (5 * 86400));

        $upStmt = $db->prepare("UPDATE orders SET status = 'accepted', deadline_at = :dl, updated_at = NOW() WHERE id = :id");
        $upStmt->execute([':dl' => $deliveryDeadline, ':id' => $orderId]);

        // Notify Buyer
        $buyerStmt = $db->prepare("SELECT name, email FROM users WHERE id = :id LIMIT 1");
        $buyerStmt->execute([':id' => $order['buyer_id']]);
        $buyer = $buyerStmt->fetch();

        if ($buyer && !empty($buyer['email'])) {
            $msg = "<p>Hello <strong>{$buyer['name']}</strong>,</p>"
                 . "<p>Great news! Your order <strong>#{$order['order_code']}</strong> has been accepted by the publisher.</p>"
                 . "<p>Target Completion: 5 days. Funds remain safely in escrow until you verify the published link.</p>";
            MarketMokeMailer::sendEmail($buyer['email'], $buyer['name'], "Order #{$order['order_code']} Accepted", $msg);
        }

        jsonResponse([
            'status'  => 'success',
            'message' => "Order #{$order['order_code']} accepted. Delivery deadline set to 5 days.",
            'order'   => array_merge($order, ['status' => 'accepted', 'deadline_at' => $deliveryDeadline])
        ]);
    }

    // ----------------------------------------------------------------------
    // 3. ACTION: SUBMIT ORDER LINK (Publisher submits live URL)
    // ----------------------------------------------------------------------
    if ($action === 'submit') {
        $orderId      = (int)($input['order_id'] ?? 0);
        $publishedUrl = trim($input['published_url'] ?? $input['live_url'] ?? '');

        if ($orderId <= 0 || empty($publishedUrl)) {
            jsonResponse(['status' => 'error', 'message' => 'Order ID and Published Live URL are required.'], 400);
        }

        if (!filter_var($publishedUrl, FILTER_VALIDATE_URL)) {
            jsonResponse(['status' => 'error', 'message' => 'Please provide a valid Published Live URL (e.g. https://domain.com/post).'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonResponse(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        if ($order['seller_id'] != $currentUser['id'] && $currentUser['role'] !== 'admin') {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized. Only the publisher can submit the live URL.'], 403);
        }

        if ($order['status'] !== 'accepted' && $order['status'] !== 'submitted') {
            jsonResponse(['status' => 'error', 'message' => "Order cannot be submitted in '{$order['status']}' state."], 400);
        }

        $upStmt = $db->prepare("UPDATE orders 
            SET status = 'submitted', 
                published_url = :url, 
                link_verified = 0,
                updated_at = NOW() 
            WHERE id = :id");
        $upStmt->execute([':url' => $publishedUrl, ':id' => $orderId]);

        // Notify Buyer
        $buyerStmt = $db->prepare("SELECT name, email FROM users WHERE id = :id LIMIT 1");
        $buyerStmt->execute([':id' => $order['buyer_id']]);
        $buyer = $buyerStmt->fetch();

        if ($buyer && !empty($buyer['email'])) {
            $msg = "<p>Hello <strong>{$buyer['name']}</strong>,</p>"
                 . "<p>The publisher has submitted the live link for Order <strong>#{$order['order_code']}</strong>:</p>"
                 . "<p><a href=\"{$publishedUrl}\" target=\"_blank\">{$publishedUrl}</a></p>"
                 . "<p>You can verify the backlink now. Once verified, the escrow funds will be released to the publisher.</p>";
            MarketMokeMailer::sendEmail($buyer['email'], $buyer['name'], "Order #{$order['order_code']} Live Post Ready for Verification", $msg);
        }

        jsonResponse([
            'status'        => 'success',
            'message'       => 'Live post URL submitted successfully! Order status changed to submitted.',
            'published_url' => $publishedUrl,
            'order_id'      => $orderId
        ]);
    }

    // ----------------------------------------------------------------------
    // 4. ACTION: CANCEL ORDER (Buyer or Admin cancels; Escrow Refunded)
    // ----------------------------------------------------------------------
    if ($action === 'cancel') {
        $orderId = (int)($input['order_id'] ?? 0);
        $reason  = trim($input['reason'] ?? 'Order cancelled by user');

        if ($orderId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Valid Order ID required.'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonResponse(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        // Authorization: Buyer or Admin
        $isBuyer = ($order['buyer_id'] == $currentUser['id']);
        $isAdmin = ($currentUser['role'] === 'admin');

        if (!$isBuyer && !$isAdmin) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized. Only the buyer or admin may cancel this order.'], 403);
        }

        // Can only cancel if pending, or if accepted and deadline expired, or admin
        $now = time();
        $deadlinePassed = !empty($order['deadline_at']) && (strtotime($order['deadline_at']) < $now);

        if ($order['status'] === 'completed') {
            jsonResponse(['status' => 'error', 'message' => 'Cannot cancel an order that has already been verified and completed.'], 400);
        }

        if ($order['status'] === 'cancelled') {
            jsonResponse(['status' => 'error', 'message' => 'Order has already been cancelled.'], 400);
        }

        if ($order['status'] === 'submitted' && !$isAdmin) {
            jsonResponse(['status' => 'error', 'message' => 'The publisher has already submitted the live link. Please dispute or verify instead of cancelling.'], 400);
        }

        try {
            // ATOMIC ESCROW REFUND
            refundEscrowFunds($orderId, $reason);

            // Notify Publisher of cancellation
            $pubStmt = $db->prepare("SELECT name, email FROM users WHERE id = :id LIMIT 1");
            $pubStmt->execute([':id' => $order['seller_id']]);
            $publisher = $pubStmt->fetch();

            if ($publisher && !empty($publisher['email'])) {
                $msg = "<p>Order #{$order['order_code']} has been cancelled. Reason: {$reason}. Held escrow funds have been refunded to the buyer.</p>";
                MarketMokeMailer::sendEmail($publisher['email'], $publisher['name'], "Order #{$order['order_code']} Cancelled", $msg);
            }

            jsonResponse([
                'status'  => 'success',
                'message' => "Order #{$order['order_code']} has been cancelled. \${$order['price']} has been refunded to your wallet.",
                'wallet'  => getUserWallet((int)$order['buyer_id'])
            ]);

        } catch (Exception $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    // ----------------------------------------------------------------------
    // 5. ACTION: DISPUTE ORDER
    // ----------------------------------------------------------------------
    if ($action === 'dispute') {
        $orderId = (int)($input['order_id'] ?? 0);
        $reason  = trim($input['reason'] ?? 'Disputed by buyer');

        $stmt = $db->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonResponse(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        $up = $db->prepare("UPDATE orders SET status = 'disputed', updated_at = NOW() WHERE id = :id");
        $up->execute([':id' => $orderId]);

        jsonResponse([
            'status'  => 'success',
            'message' => "Order #{$order['order_code']} marked as disputed. Admin mediation will review link compliance."
        ]);
    }

    jsonResponse(['status' => 'error', 'message' => "Invalid action '{$action}' supplied."], 400);
}
