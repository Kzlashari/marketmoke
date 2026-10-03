<?php
/**
 * MarketMoke - Rate Limiter & Brute-Force Defense Middleware
 * Restricts request frequency per IP address using MySQL or Session fallback.
 * Implements HTTP 429 Too Many Requests response with Retry-After header.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

/**
 * Check and enforce rate limiting on a specific endpoint
 *
 * @param string $endpointKey Unique identifier for the rate limited action (e.g., 'send_otp')
 * @param int $maxRequests Maximum allowed attempts within window (Default: 3)
 * @param int $decaySeconds Window length in seconds (Default: 900 = 15 minutes)
 */
function checkRateLimit(string $endpointKey = 'send_otp', int $maxRequests = 3, int $decaySeconds = 900): void {
    $ip = getClientIp();
    $now = time();

    // 1. Try Persistent Database Rate Limiting (MySQL)
    try {
        $db = Database::getInstance()->getConnection();

        // Ensure rate_limits table exists
        $db->exec("CREATE TABLE IF NOT EXISTS `rate_limits` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `ip_address` VARCHAR(45) NOT NULL,
            `endpoint` VARCHAR(60) NOT NULL,
            `request_count` INT NOT NULL DEFAULT 1,
            `last_request` DATETIME NOT NULL,
            `reset_time` DATETIME NOT NULL,
            UNIQUE KEY `uk_ip_endpoint` (`ip_address`, `endpoint`),
            INDEX `idx_rate_limits_reset` (`reset_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Look up existing active window for this IP & endpoint
        $stmt = $db->prepare("SELECT id, request_count, reset_time FROM rate_limits WHERE ip_address = :ip AND endpoint = :endpoint LIMIT 1");
        $stmt->execute([':ip' => $ip, ':endpoint' => $endpointKey]);
        $record = $stmt->fetch();

        if ($record) {
            $resetTimestamp = strtotime($record['reset_time']);

            if ($resetTimestamp > $now) {
                // Window is still active
                if ((int)$record['request_count'] >= $maxRequests) {
                    $retryAfter = max(1, $resetTimestamp - $now);
                    respondRateLimitExceeded($retryAfter, $maxRequests, $decaySeconds);
                }

                // Increment request counter
                $upStmt = $db->prepare("UPDATE rate_limits SET request_count = request_count + 1, last_request = NOW() WHERE id = :id");
                $upStmt->execute([':id' => $record['id']]);
                return;
            } else {
                // Window expired: Reset counter and new window
                $newResetTime = date('Y-m-d H:i:s', $now + $decaySeconds);
                $upStmt = $db->prepare("UPDATE rate_limits SET request_count = 1, last_request = NOW(), reset_time = :reset WHERE id = :id");
                $upStmt->execute([':reset' => $newResetTime, ':id' => $record['id']]);
                return;
            }
        } else {
            // First request in this window: Insert new tracking row
            $newResetTime = date('Y-m-d H:i:s', $now + $decaySeconds);
            $insStmt = $db->prepare("INSERT INTO rate_limits (ip_address, endpoint, request_count, last_request, reset_time) 
                VALUES (:ip, :endpoint, 1, NOW(), :reset)
                ON DUPLICATE KEY UPDATE request_count = request_count + 1, last_request = NOW()");
            $insStmt->execute([
                ':ip'       => $ip,
                ':endpoint' => $endpointKey,
                ':reset'    => $newResetTime
            ]);
            return;
        }
    } catch (Exception $e) {
        error_log("[MarketMoke RateLimiter] DB error, using PHP session fallback: " . $e->getMessage());
    }

    // 2. Fallback: PHP Session-based Rate Limiter
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }

    $rateKey = "{$ip}_{$endpointKey}";
    $sessionData = $_SESSION['rate_limits'][$rateKey] ?? null;

    if ($sessionData && $sessionData['reset_at'] > $now) {
        if ($sessionData['count'] >= $maxRequests) {
            $retryAfter = max(1, $sessionData['reset_at'] - $now);
            respondRateLimitExceeded($retryAfter, $maxRequests, $decaySeconds);
        }
        $_SESSION['rate_limits'][$rateKey]['count']++;
    } else {
        $_SESSION['rate_limits'][$rateKey] = [
            'count'    => 1,
            'reset_at' => $now + $decaySeconds
        ];
    }
}

/**
 * Send HTTP 429 Too Many Requests response
 */
function respondRateLimitExceeded(int $retryAfterSeconds, int $maxRequests, int $decaySeconds): void {
    http_response_code(429);
    header('Retry-After: ' . $retryAfterSeconds);
    header('Content-Type: application/json; charset=UTF-8');

    $minutes = ceil($decaySeconds / 60);

    echo json_encode([
        'status'              => 'error',
        'code'                => 429,
        'message'             => "Too many requests. For your security, requests are limited to {$maxRequests} attempts per {$minutes} minutes.",
        'retry_after_seconds' => $retryAfterSeconds
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Optional: Clear rate limit for an IP after successful verification
 */
function clearRateLimit(string $endpointKey = 'send_otp'): void {
    $ip = getClientIp();
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM rate_limits WHERE ip_address = :ip AND endpoint = :endpoint");
        $stmt->execute([':ip' => $ip, ':endpoint' => $endpointKey]);
    } catch (Exception $e) {
        // Silently ignore cleanup error
    }

    if (session_status() !== PHP_SESSION_NONE && isset($_SESSION['rate_limits']["{$ip}_{$endpointKey}"])) {
        unset($_SESSION['rate_limits']["{$ip}_{$endpointKey}"]);
    }
}
