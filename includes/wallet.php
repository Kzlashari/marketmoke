<?php
/**
 * MarketMoke - Escrow Wallet & Financial Transaction Engine
 * Atomic, thread-safe database operations utilizing MySQL PDO transactions,
 * pessimistic row locking (SELECT ... FOR UPDATE), and strict audit logging.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

/**
 * Ensure a user wallet record exists in user_wallets.
 * Initializes from users.balance if previously present.
 */
function ensureWalletExists(PDO $db, int $userId): array {
    // Check if wallet exists
    $stmt = $db->prepare("SELECT user_id, balance, escrow_balance, updated_at FROM user_wallets WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $wallet = $stmt->fetch();

    if ($wallet) {
        return $wallet;
    }

    // Auto-create wallet and sync existing user balance
    $userStmt = $db->prepare("SELECT balance FROM users WHERE id = :uid LIMIT 1");
    $userStmt->execute([':uid' => $userId]);
    $user = $userStmt->fetch();
    $initialBalance = $user ? (float)$user['balance'] : 0.00;

    $insStmt = $db->prepare("INSERT INTO user_wallets (user_id, balance, escrow_balance, updated_at) 
        VALUES (:uid, :bal, 0.00, NOW()) 
        ON DUPLICATE KEY UPDATE balance = balance");
    $insStmt->execute([':uid' => $userId, ':bal' => $initialBalance]);

    return [
        'user_id' => $userId,
        'balance' => $initialBalance,
        'escrow_balance' => 0.00,
        'updated_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Retrieve current wallet balances for a user.
 */
function getUserWallet(int $userId): array {
    $db = Database::getInstance()->getConnection();
    ensureWalletExists($db, $userId);

    $stmt = $db->prepare("SELECT user_id, balance, escrow_balance, updated_at FROM user_wallets WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $wallet = $stmt->fetch();

    return [
        'user_id'           => $userId,
        'balance'           => (float)($wallet['balance'] ?? 0.00),
        'escrow_balance'    => (float)($wallet['escrow_balance'] ?? 0.00),
        'available_balance' => (float)($wallet['balance'] ?? 0.00),
        'total_equity'      => (float)($wallet['balance'] ?? 0.00) + (float)($wallet['escrow_balance'] ?? 0.00),
        'updated_at'        => $wallet['updated_at'] ?? date('Y-m-d H:i:s')
    ];
}

/**
 * Atomic Escrow Hold: Locks buyer funds into escrow upon order placement.
 * 
 * @param int $buyerId The buyer user ID placing the order
 * @param float $amount Total order price to lock into escrow
 * @param int $orderId The created order ID
 * @param int $sellerId The publisher/seller receiving the order
 * @return bool True on success
 * @throws Exception On insufficient balance or database failure
 */
function holdEscrowFunds(int $buyerId, float $amount, int $orderId, int $sellerId): bool {
    if ($amount <= 0) {
        throw new InvalidArgumentException("Escrow hold amount must be greater than zero.");
    }

    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    try {
        // 1. Ensure wallets exist for both parties
        ensureWalletExists($db, $buyerId);
        ensureWalletExists($db, $sellerId);

        // 2. Pessimistic Row Lock on Buyer's Wallet to prevent race conditions / double-spending
        $lockStmt = $db->prepare("SELECT balance, escrow_balance FROM user_wallets WHERE user_id = :uid FOR UPDATE");
        $lockStmt->execute([':uid' => $buyerId]);
        $buyerWallet = $lockStmt->fetch();

        $currentBalance = (float)$buyerWallet['balance'];
        if ($currentBalance < $amount) {
            $formattedReq = number_format($amount, 2);
            $formattedBal = number_format($currentBalance, 2);
            throw new Exception("Insufficient wallet funds. Required: \${$formattedReq}, Available: \${$formattedBal}. Please deposit funds.");
        }

        // 3. Deduct from buyer balance, credit to buyer escrow_balance
        $upWallet = $db->prepare("UPDATE user_wallets 
            SET balance = balance - :amt, 
                escrow_balance = escrow_balance + :amt, 
                updated_at = NOW() 
            WHERE user_id = :uid");
        $upWallet->execute([':amt' => $amount, ':uid' => $buyerId]);

        // 4. Keep users table synchronized
        $upUser = $db->prepare("UPDATE users SET balance = balance - :amt WHERE id = :uid");
        $upUser->execute([':amt' => $amount, ':uid' => $buyerId]);

        // 5. Record Escrow Audit Transaction
        $insTx = $db->prepare("INSERT INTO escrow_transactions (order_id, buyer_id, seller_id, amount, type, status, notes, created_at) 
            VALUES (:oid, :bid, :sid, :amt, 'hold', 'held', :notes, NOW())");
        $insTx->execute([
            ':oid'   => $orderId,
            ':bid'   => $buyerId,
            ':sid'   => $sellerId,
            ':amt'   => $amount,
            ':notes' => "Funds held in escrow for Order #{$orderId}"
        ]);

        $db->commit();
        return true;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("[MarketMoke Escrow Hold Error] Order #{$orderId}: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Atomic Escrow Release: Releases locked escrow funds to the publisher's wallet
 * upon successful guest post link verification or manual buyer approval.
 * 
 * @param int $orderId The order ID to release funds for
 * @return array Release details
 * @throws Exception If no active hold exists or database error occurs
 */
function releaseEscrowFunds(int $orderId): array {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    try {
        // 1. Lock Order record
        $orderStmt = $db->prepare("SELECT id, order_code, buyer_id, seller_id, price, platform_fee, publisher_payout, status FROM orders WHERE id = :oid FOR UPDATE");
        $orderStmt->execute([':oid' => $orderId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            throw new Exception("Order #{$orderId} does not exist.");
        }

        if ($order['status'] === 'completed') {
            throw new Exception("Escrow for Order #{$orderId} has already been released and marked completed.");
        }

        if ($order['status'] === 'cancelled') {
            throw new Exception("Cannot release escrow for a cancelled order.");
        }

        // 2. Lock active 'hold' escrow record
        $txStmt = $db->prepare("SELECT id, amount, status FROM escrow_transactions 
            WHERE order_id = :oid AND type = 'hold' AND status = 'held' 
            ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $txStmt->execute([':oid' => $orderId]);
        $holdTx = $txStmt->fetch();

        if (!$holdTx) {
            throw new Exception("No active held escrow transaction found for Order #{$orderId}.");
        }

        $totalAmount = (float)$holdTx['amount'];
        $buyerId = (int)$order['buyer_id'];
        $sellerId = (int)$order['seller_id'];

        // Determine payout and platform fee
        $payout = (float)$order['publisher_payout'];
        if ($payout <= 0) {
            $platformFeePercent = defined('PLATFORM_FEE_PERCENT') ? PLATFORM_FEE_PERCENT : 10;
            $fee = round($totalAmount * ($platformFeePercent / 100), 2);
            $payout = $totalAmount - $fee;
        } else {
            $fee = $totalAmount - $payout;
        }

        ensureWalletExists($db, $buyerId);
        ensureWalletExists($db, $sellerId);

        // 3. Lock buyer wallet and deduct escrow_balance
        $buyerLock = $db->prepare("SELECT escrow_balance FROM user_wallets WHERE user_id = :uid FOR UPDATE");
        $buyerLock->execute([':uid' => $buyerId]);
        $buyerWallet = $buyerLock->fetch();

        $curEscrow = (float)($buyerWallet['escrow_balance'] ?? 0.00);
        $deductEscrow = min($totalAmount, $curEscrow);

        $upBuyer = $db->prepare("UPDATE user_wallets 
            SET escrow_balance = GREATEST(0.00, escrow_balance - :amt), 
                updated_at = NOW() 
            WHERE user_id = :uid");
        $upBuyer->execute([':amt' => $deductEscrow, ':uid' => $buyerId]);

        // 4. Lock publisher wallet and credit publisher payout
        $sellerLock = $db->prepare("SELECT balance FROM user_wallets WHERE user_id = :uid FOR UPDATE");
        $sellerLock->execute([':uid' => $sellerId]);

        $upSeller = $db->prepare("UPDATE user_wallets 
            SET balance = balance + :payout, 
                updated_at = NOW() 
            WHERE user_id = :uid");
        $upSeller->execute([':payout' => $payout, ':uid' => $sellerId]);

        // Keep publisher's users table balance in sync
        $upSellerUser = $db->prepare("UPDATE users SET balance = balance + :payout WHERE id = :uid");
        $upSellerUser->execute([':payout' => $payout, ':uid' => $sellerId]);

        // 5. Update hold transaction status to 'released'
        $upTx = $db->prepare("UPDATE escrow_transactions SET status = 'released' WHERE id = :id");
        $upTx->execute([':id' => $holdTx['id']]);

        // 6. Insert audit release transaction
        $insRelease = $db->prepare("INSERT INTO escrow_transactions (order_id, buyer_id, seller_id, amount, type, status, notes, created_at) 
            VALUES (:oid, :bid, :sid, :amt, 'release', 'released', :notes, NOW())");
        $insRelease->execute([
            ':oid'   => $orderId,
            ':bid'   => $buyerId,
            ':sid'   => $sellerId,
            ':amt'   => $payout,
            ':notes' => "Escrow released to publisher after backlink verification. Platform fee: \${$fee}"
        ]);

        // 7. Update order status to 'completed'
        $upOrder = $db->prepare("UPDATE orders 
            SET status = 'completed', 
                completed_at = NOW(), 
                updated_at = NOW() 
            WHERE id = :oid");
        $upOrder->execute([':oid' => $orderId]);

        $db->commit();

        return [
            'order_id'        => $orderId,
            'buyer_id'        => $buyerId,
            'seller_id'       => $sellerId,
            'total_amount'    => $totalAmount,
            'publisher_payout'=> $payout,
            'platform_fee'    => $fee,
            'status'          => 'completed'
        ];

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("[MarketMoke Escrow Release Error] Order #{$orderId}: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Atomic Escrow Refund: Refunds held escrow funds back to the buyer's balance
 * if an order is cancelled or publisher fails to fulfill requirements.
 * 
 * @param int $orderId The order ID to refund
 * @param string $reason Cancellation / refund reason
 * @return bool True on success
 * @throws Exception If no active hold exists or database error occurs
 */
function refundEscrowFunds(int $orderId, string $reason = 'Order cancelled'): bool {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    try {
        // 1. Lock Order
        $orderStmt = $db->prepare("SELECT id, buyer_id, seller_id, status FROM orders WHERE id = :oid FOR UPDATE");
        $orderStmt->execute([':oid' => $orderId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            throw new Exception("Order #{$orderId} does not exist.");
        }

        if ($order['status'] === 'completed') {
            throw new Exception("Cannot refund order #{$orderId} because escrow has already been released to publisher.");
        }

        if ($order['status'] === 'cancelled') {
            throw new Exception("Order #{$orderId} has already been cancelled and refunded.");
        }

        // 2. Lock active 'hold' escrow record
        $txStmt = $db->prepare("SELECT id, amount, status FROM escrow_transactions 
            WHERE order_id = :oid AND type = 'hold' AND status = 'held' 
            ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $txStmt->execute([':oid' => $orderId]);
        $holdTx = $txStmt->fetch();

        if (!$holdTx) {
            throw new Exception("No active held escrow transaction found for Order #{$orderId}.");
        }

        $amount = (float)$holdTx['amount'];
        $buyerId = (int)$order['buyer_id'];
        $sellerId = (int)$order['seller_id'];

        ensureWalletExists($db, $buyerId);

        // 3. Lock buyer wallet: deduct escrow_balance and credit balance
        $buyerLock = $db->prepare("SELECT escrow_balance FROM user_wallets WHERE user_id = :uid FOR UPDATE");
        $buyerLock->execute([':uid' => $buyerId]);

        $upBuyer = $db->prepare("UPDATE user_wallets 
            SET balance = balance + :amt, 
                escrow_balance = GREATEST(0.00, escrow_balance - :amt), 
                updated_at = NOW() 
            WHERE user_id = :uid");
        $upBuyer->execute([':amt' => $amount, ':uid' => $buyerId]);

        // Keep users table balance in sync
        $upUser = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
        $upUser->execute([':amt' => $amount, ':uid' => $buyerId]);

        // 4. Update hold transaction status to 'refunded'
        $upTx = $db->prepare("UPDATE escrow_transactions SET status = 'refunded' WHERE id = :id");
        $upTx->execute([':id' => $holdTx['id']]);

        // 5. Insert refund transaction record
        $insRefund = $db->prepare("INSERT INTO escrow_transactions (order_id, buyer_id, seller_id, amount, type, status, notes, created_at) 
            VALUES (:oid, :bid, :sid, :amt, 'refund', 'refunded', :notes, NOW())");
        $insRefund->execute([
            ':oid'   => $orderId,
            ':bid'   => $buyerId,
            ':sid'   => $sellerId,
            ':amt'   => $amount,
            ':notes' => "Refunded to buyer balance: {$reason}"
        ]);

        // 6. Update order status to 'cancelled'
        $upOrder = $db->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = :oid");
        $upOrder->execute([':oid' => $orderId]);

        $db->commit();
        return true;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("[MarketMoke Escrow Refund Error] Order #{$orderId}: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Deposit funds into user wallet (for testing or gateway webhook credits)
 */
function depositWalletFunds(int $userId, float $amount, string $reference = 'Deposit'): array {
    if ($amount <= 0) {
        throw new InvalidArgumentException("Deposit amount must be greater than zero.");
    }

    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    try {
        ensureWalletExists($db, $userId);

        $up = $db->prepare("UPDATE user_wallets SET balance = balance + :amt, updated_at = NOW() WHERE user_id = :uid");
        $up->execute([':amt' => $amount, ':uid' => $userId]);

        $upUser = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
        $upUser->execute([':amt' => $amount, ':uid' => $userId]);

        $db->commit();
        return getUserWallet($userId);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Fetch full escrow audit history for a specific order
 */
function getEscrowHistory(int $orderId): array {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT t.*, b.name as buyer_name, s.name as seller_name 
        FROM escrow_transactions t
        LEFT JOIN users b ON t.buyer_id = b.id
        LEFT JOIN users s ON t.seller_id = s.id
        WHERE t.order_id = :oid 
        ORDER BY t.id ASC");
    $stmt->execute([':oid' => $orderId]);
    return $stmt->fetchAll();
}
