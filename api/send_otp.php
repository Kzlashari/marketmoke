<?php
/**
 * MarketMoke - Send OTP Endpoint
 * Accepts email via POST, rate-limits requests (max 3 per 15 min),
 * generates a secure 6-digit random integer, stores its cryptographic hash
 * in `user_otps` with a 10-minute expiry, and dispatches the plain code via PHPMailer.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/MarketMokeMailer.php';

// 1. Enforce POST Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'status'  => 'error',
        'code'    => 405,
        'message' => 'Method Not Allowed. POST request required.'
    ], 405);
}

// The credential-login endpoint deliberately has no artificial retry cap.
// Other direct OTP use continues to use the existing rate limiter.
if (!defined('MARKETMOKE_LOGIN_RATE_LIMIT_DISABLED')) {
    checkRateLimit('send_otp', 3, 900);
}

// 3. Parse JSON Body or Standard Form Data
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$email = sanitizeEmail($data['email'] ?? '');

// 4. Validate Email Address
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse([
        'status'  => 'error',
        'code'    => 400,
        'message' => 'Please provide a valid email address.'
    ], 400);
}

try {
    $db = Database::getInstance()->getConnection();

    // Ensure user_otps table exists
    $db->exec("CREATE TABLE IF NOT EXISTS `user_otps` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT DEFAULT NULL,
        `email` VARCHAR(150) NOT NULL,
        `otp_code` VARCHAR(255) NOT NULL,
        `attempts` INT NOT NULL DEFAULT 0,
        `reset_token` VARCHAR(64) DEFAULT NULL,
        `expires_at` DATETIME NOT NULL,
        `is_used` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_user_otps_lookup` (`email`, `is_used`, `expires_at`),
        INDEX `idx_user_otps_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 5. Look up User (case-insensitive and trimmed)
    $stmt = $db->prepare("SELECT id, name, email, password, status FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // Mandatory password verification
    $password = (string)($data['password'] ?? '');
    if ($password === '') {
        jsonResponse([
            'status'  => 'error',
            'code'    => 400,
            'message' => 'Password is required to authenticate.'
        ], 400);
    }

    $isPasswordValid = false;
    if ($user && !empty($user['password'])) {
        $storedPwd = trim((string)$user['password']);

        // 1. Standard bcrypt verification ($2y$, $2a$, $2b$)
        $bcryptCandidate = $storedPwd;
        if (strpos($bcryptCandidate, '$2b$') === 0) {
            $bcryptCandidate = '$2y$' . substr($bcryptCandidate, 4);
        }
        if (strpos($bcryptCandidate, '$2y$') === 0 || strpos($bcryptCandidate, '$2a$') === 0) {
            if (@password_verify($password, $bcryptCandidate)) {
                $isPasswordValid = true;
            }
        }

        // 2. Direct plaintext match (if stored as plain text in database)
        if (!$isPasswordValid && hash_equals($storedPwd, $password)) {
            $isPasswordValid = true;
        }

        // 3. MD5 match (if stored as MD5 hash in database)
        if (!$isPasswordValid && strlen($storedPwd) === 32 && ctype_xdigit($storedPwd)) {
            if (hash_equals(strtolower($storedPwd), md5($password))) {
                $isPasswordValid = true;
            }
        }

        // 4. SHA-256 match
        if (!$isPasswordValid && strlen($storedPwd) === 64 && ctype_xdigit($storedPwd)) {
            if (hash_equals(strtolower($storedPwd), hash('sha256', $password))) {
                $isPasswordValid = true;
            }
        }

        // Transparently upgrade plaintext/legacy password to secure bcrypt
        if ($isPasswordValid && strpos($storedPwd, '$2') !== 0) {
            try {
                $rehash = password_hash($password, PASSWORD_DEFAULT);
                $rehashStmt = $db->prepare("UPDATE users SET password = :pwd WHERE id = :id");
                $rehashStmt->execute([':pwd' => $rehash, ':id' => $user['id']]);
            } catch (Exception $e) {
                error_log("[MarketMoke Password Rehash Error] " . $e->getMessage());
            }
        }
    }

    if (!$user || !$isPasswordValid) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 401,
            'message' => 'Invalid email or password. Please verify your credentials.'
        ], 401);
    }

    if ($user['status'] === 'pending') {
        jsonResponse([
            'status'  => 'error',
            'code'    => 403,
            'message' => 'Your account is pending administrator approval. Once approved, you will be permitted to log in.'
        ], 403);
    }
    if ($user['status'] === 'rejected') {
        jsonResponse([
            'status'  => 'error',
            'code'    => 403,
            'message' => 'Your registration was reviewed and rejected. Login access is denied.'
        ], 403);
    }
    if ($user['status'] === 'suspended') {
        jsonResponse([
            'status'  => 'error',
            'code'    => 403,
            'message' => 'Your account has been suspended by an administrator. Please contact support.'
        ], 403);
    }
    if ($user['status'] !== 'active') {
        jsonResponse([
            'status'  => 'error',
            'code'    => 403,
            'message' => 'Your account is currently inactive. Please contact customer support.'
        ], 403);
    }

    $userId = (int)$user['id'];
    $userName = $user['name'] ?? 'Valued Member';

    // 6. Invalidate any existing unused OTPs for this email address
    $invStmt = $db->prepare("UPDATE user_otps SET is_used = 1 WHERE email = :email AND is_used = 0");
    $invStmt->execute([':email' => $email]);

    // 7. Generate Cryptographically Secure 6-Digit Integer
    $rawOtpInt = random_int(100000, 999999);
    $plainOtp = sprintf("%06d", $rawOtpInt);

    // 8. Hash the OTP with Bcrypt / Default strong algorithm
    $hashedOtp = password_hash($plainOtp, PASSWORD_DEFAULT);

    // 9. Compute 20-Minute Expiry (MySQL native to prevent timezone discrepancy)
    $expiryMinutes = defined('OTP_EXPIRY_MINUTES') ? (int)OTP_EXPIRY_MINUTES : 20;

    // 10. Persist Hashed OTP into user_otps Table with native MySQL interval
    $insStmt = $db->prepare("INSERT INTO user_otps (user_id, email, otp_code, expires_at, is_used, created_at) 
        VALUES (:user_id, :email, :otp_code, DATE_ADD(NOW(), INTERVAL {$expiryMinutes} MINUTE), 0, NOW())");
    $insStmt->execute([
        ':user_id'    => $userId,
        ':email'      => $email,
        ':otp_code'   => $hashedOtp
    ]);
    $newOtpId = (int)$db->lastInsertId();

    // 11. Send Plain Code via Centralized PHPMailer
    $mailSent = false;
    try {
        $mailSent = MarketMokeMailer::sendOtpEmail($email, $userName, $plainOtp);
    } catch (Exception $e) {
        error_log("[MarketMoke OTP Error] Mail server failed to dispatch OTP email to {$email}: " . $e->getMessage());
        $mailSent = false;
    }

    $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])
        || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false
        || strpos($_SERVER['SERVER_NAME'] ?? '', 'localhost') !== false;

    if (!$mailSent) {
        if ($isLocalhost) {
            // Local development fallback: Log OTP and present to screen when SMTP is unconfigured on localhost
            error_log("[MarketMoke Local Auth] Verification code for {$email}: {$plainOtp}");

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['pending_otp_email'] = $email;
            $_SESSION['pending_otp_expires'] = time() + ($expiryMinutes * 60);

            jsonResponse([
                'status'             => 'success',
                'code'               => 200,
                'message'            => 'Verification code: ' . $plainOtp . ' (Local Dev Mode - SMTP not configured)',
                'dev_otp'            => $plainOtp,
                'expires_in_seconds' => $expiryMinutes * 60
            ]);
        } else {
            // Invalidate newly generated OTP and fail safely
            if ($newOtpId > 0) {
                $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $newOtpId]);
            }
            jsonResponse([
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Unable to send verification code email. Please check server SMTP configuration.'
            ], 500);
        }
    }

    // 12. Set Temporary Session for Multi-Step verification tracking
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['pending_otp_email'] = $email;
    $_SESSION['pending_otp_expires'] = time() + ($expiryMinutes * 60);

    // 13. Return Secure JSON Response (NEVER leak the plain OTP)
    jsonResponse([
        'status'             => 'success',
        'code'               => 200,
        'message'            => 'A 6-digit verification code has been dispatched to your email address.',
        'expires_in_seconds' => $expiryMinutes * 60
    ]);

} catch (Exception $e) {
    error_log("[MarketMoke send_otp Error] " . $e->getMessage());
    jsonResponse([
        'status'  => 'error',
        'code'    => 500,
        'message' => 'Unable to generate verification code. Please try again later.'
    ], 500);
}
