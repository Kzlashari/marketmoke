<?php
/**
 * MarketMoke - Admin Dashboard Statistics & Approval API
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance()->getConnection();

if ($method === 'GET') {
    try {
        $stmtWebsites = $db->query("SELECT COUNT(*) as total FROM marketplace_websites WHERE status = 'active'");
        $totalWebsites = (int) $stmtWebsites->fetch()['total'];

        $stmtOrders = $db->query("SELECT COUNT(*) as total, SUM(price) as total_volume FROM orders");
        $orderData = $stmtOrders->fetch();
        $totalOrders = (int) $orderData['total'];
        $totalVolume = (float) ($orderData['total_volume'] ?? 0);

        $stmtEscrow = $db->query("SELECT SUM(price) as escrow_locked FROM orders WHERE status = 'in_progress'");
        $escrowLocked = (float) ($stmtEscrow->fetch()['escrow_locked'] ?? 0);

        $stmtUsers = $db->query("SELECT id, name, email, role, balance, status FROM users");
        $users = $stmtUsers->fetchAll();

        jsonResponse([
            'status' => 'success',
            'data' => [
                'total_websites' => $totalWebsites,
                'total_orders' => $totalOrders,
                'total_users' => count($users),
                'total_volume' => $totalVolume,
                'escrow_locked' => $escrowLocked,
                'users' => $users
            ]
        ]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? '';

    if ($action === 'approve_website') {
        $id = (int) ($input['id'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE marketplace_websites SET status = 'active' WHERE id = :id");
            $stmt->execute([':id' => $id]);
            jsonResponse(['status' => 'success', 'message' => 'Website listing approved and published!']);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Unknown action'], 400);
    }
} else {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
