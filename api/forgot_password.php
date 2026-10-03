<?php
/**
 * MarketMoke - Forgot Password & Reset Password API
 * Actions supported:
 * 1. send_otp        -> Request OTP for registered email
 * 2. verify_otp      -> Verify 6-digit code with cooldown & attempt limiting
 * 3. reset_password  -> Reset password with secure bcrypt hashing & role-based redirect
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/mailer.php';

// Enforce POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'code' => 405, 'message' => 'Method Not Allowed. POST required.'], 405);
}

// Rate Limiting (max 10 actions per 15 minutes per IP)
checkRateLimit('forgot_password', 10, 900);

$rawInput = file_get_contents('php://input');
$data = $GLOBALS['_FP_OVERRIDE_INPUT'] ?? json_decode($rawInput, true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$action = trim($data['action'] ?? ($_GET['action'] ?? 'send_otp'));
$db = Database::getInstance()->getConnection();

// Ensure user_otps table has required columns
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `user_otps` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT DEFAULT NULL,
        `email` VARCHAR(150) NOT NULL,
        `otp_code` VARCHAR(255) NOT NULL,
        `reset_token` VARCHAR(64) DEFAULT NULL,
        `attempts` INT NOT NULL DEFAULT 0,
        `expires_at` DATETIME NOT NULL,
        `is_used` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_user_otps_lookup` (`email`, `is_used`, `expires_at`),
        INDEX `idx_user_otps_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Add attempts and reset_token columns if missing from earlier schema
    $colCheck = $db->query("SHOW COLUMNS FROM `user_otps` LIKE 'attempts'")->fetch();
    if (!$colCheck) {
        $db->exec("ALTER TABLE `user_otps` ADD COLUMN `attempts` INT NOT NULL DEFAULT 0");
    }
    $tokCheck = $db->query("SHOW COLUMNS FROM `user_otps` LIKE 'reset_token'")->fetch();
    if (!$tokCheck) {
        $db->exec("ALTER TABLE `user_otps` ADD COLUMN `reset_token` VARCHAR(64) DEFAULT NULL");
    }
} catch (Exception $e) {
    // Continue
}

// --------------------------------------------------------------------------
// ACTION 1: SEND OTP
// --------------------------------------------------------------------------
if ($action === 'send_otp' || $action === 'request_otp') {
    $email = strtolower(trim($data['email'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 400,
            'message' => 'Please provide a valid email address.'
        ], 400);
    }

    try {
        $stmt = $db->prepare("SELECT id, name, email, status, role FROM users WHERE LOWER(TRIM(email)) = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        // If email is not registered in the database, return error
        if (!$user) {
            jsonResponse([
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Email not registered'
            ], 404);
        }

        // Account status check
        if ($user['status'] !== 'active') {
            jsonResponse([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'This account is currently inactive or pending approval. Please contact customer support.'
            ], 403);
        }

        // Generate cryptographically secure 6-digit random integer
        $rawOtp = random_int(100000, 999999);
        $plainOtp = sprintf("%06d", $rawOtp);
        $hashedOtp = password_hash($plainOtp, PASSWORD_DEFAULT);

        // Invalidate any existing unused OTPs for this email
        $invStmt = $db->prepare("UPDATE user_otps SET is_used = 1 WHERE email = :email AND is_used = 0");
        $invStmt->execute([':email' => $email]);

        // Insert new OTP with 20 minutes validity
        $insStmt = $db->prepare("INSERT INTO user_otps (user_id, email, otp_code, attempts, expires_at, is_used, created_at) 
            VALUES (:user_id, :email, :otp_code, 0, DATE_ADD(NOW(), INTERVAL 20 MINUTE), 0, NOW())");
        $insStmt->execute([
            ':user_id'  => $user['id'],
            ':email'    => $email,
            ':otp_code' => $hashedOtp
        ]);
        $insertedOtpId = (int)$db->lastInsertId();

        // Dispatch OTP via PHPMailer using existing configured SMTP
        $mailSent = false;
        try {
            $mailSent = MarketMokeMailer::sendPasswordResetOtpEmail($user['email'], $user['name'], $plainOtp);
        } catch (Exception $mEx) {
            error_log("[MarketMoke Password Reset Mail Error] " . $mEx->getMessage());
            $mailSent = false;
        }

        if (!$mailSent) {
            error_log("[MarketMoke Password Reset] Mail dispatch failed for: " . $email);
            if ($insertedOtpId > 0) {
                $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $insertedOtpId]);
            }
            jsonResponse([
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Unable to send verification code right now. Please try again later.'
            ], 500);
        }

        // Track session state
        $_SESSION['pw_reset_email'] = $email;
        $_SESSION['pw_reset_sent_time'] = time();

        jsonResponse([
            'status'             => 'success',
            'code'               => 200,
            'message'            => 'If an account exists with this email, a verification code will be sent.',
            'email'              => $email,
            'expires_in_seconds' => 1200,
            'cooldown_seconds'   => 60
        ]);

    } catch (Exception $e) {
        error_log("[MarketMoke ForgotPassword send_otp Error] " . $e->getMessage());
        jsonResponse([
            'status'  => 'error',
            'code'    => 500,
            'message' => 'Unable to send verification code right now. Please try again later.'
        ], 500);
    }
}

// --------------------------------------------------------------------------
// ACTION 2: VERIFY OTP
// --------------------------------------------------------------------------
elseif ($action === 'verify_otp') {
    $email = strtolower(trim($data['email'] ?? ($_SESSION['pw_reset_email'] ?? '')));
    $otp   = trim($data['otp'] ?? ($data['otp_code'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['status' => 'error', 'code' => 400, 'message' => 'Valid email address is required.'], 400);
    }

    if (empty($otp) || strlen($otp) !== 6 || !ctype_digit($otp)) {
        jsonResponse(['status' => 'error', 'code' => 400, 'message' => 'Please enter a valid 6-digit verification code.'], 400);
    }

    try {
        $stmt = $db->prepare("SELECT id, user_id, email, otp_code, attempts, is_used, 
            TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_left 
            FROM user_otps 
            WHERE email = :email AND is_used = 0 
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        if (!$row) {
            jsonResponse([
                'status'  => 'error',
                'code'    => 400,
                'message' => 'No active verification code found. Please request a new code.'
            ], 400);
        }

        // Check expiry
        if ((int)$row['seconds_left'] <= 0) {
            $inv = $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id");
            $inv->execute([':id' => $row['id']]);
            jsonResponse([
                'status'  => 'error',
                'code'    => 400,
                'message' => 'Verification code has expired. Please request a new code.'
            ], 400);
        }

        // Check attempts
        if ((int)$row['attempts'] >= 5) {
            $inv = $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id");
            $inv->execute([':id' => $row['id']]);
            jsonResponse([
                'status'  => 'error',
                'code'    => 429,
                'message' => 'Too many incorrect attempts. Please request a new verification code.'
            ], 429);
        }

        // Verify OTP code (Strict Bcrypt verification, no plaintext fallback)
        $isValid = password_verify($otp, $row['otp_code']);

        if (!$isValid) {
            $newAttempts = (int)$row['attempts'] + 1;
            if ($newAttempts >= 5) {
                $db->prepare("UPDATE user_otps SET attempts = :att, is_used = 1 WHERE id = :id")->execute([
                    ':att' => $newAttempts,
                    ':id'  => $row['id']
                ]);
                jsonResponse([
                    'status'  => 'error',
                    'code'    => 429,
                    'message' => 'Too many incorrect attempts. Please request a new verification code.'
                ], 429);
            } else {
                $db->prepare("UPDATE user_otps SET attempts = :att WHERE id = :id")->execute([
                    ':att' => $newAttempts,
                    ':id'  => $row['id']
                ]);
                $remaining = 5 - $newAttempts;
                jsonResponse([
                    'status'  => 'error',
                    'code'    => 400,
                    'message' => "Invalid verification code. ({$remaining} attempts remaining)"
                ], 400);
            }
        }

        // Code is valid! Mark as used and generate secure reset token
        $resetToken = bin2hex(random_bytes(32));
        $upd = $db->prepare("UPDATE user_otps SET is_used = 1, reset_token = :token WHERE id = :id");
        $upd->execute([':token' => $resetToken, ':id' => $row['id']]);

        $_SESSION['pw_reset_email'] = $email;
        $_SESSION['pw_reset_user_id'] = $row['user_id'];
        $_SESSION['pw_reset_token'] = $resetToken;
        $_SESSION['pw_reset_authorized_at'] = time();

        jsonResponse([
            'status'      => 'success',
            'code'        => 200,
            'message'     => 'Verification code verified successfully. You may now set a new password.',
            'email'       => $email,
            'reset_token' => $resetToken
        ]);

    } catch (Exception $e) {
        error_log("[MarketMoke ForgotPassword verify_otp Error] " . $e->getMessage());
        jsonResponse(['status' => 'error', 'code' => 500, 'message' => 'Error verifying code. Please try again.'], 500);
    }
}

// --------------------------------------------------------------------------
// ACTION 3: RESET PASSWORD
// --------------------------------------------------------------------------
elseif ($action === 'reset_password') {
    $email       = strtolower(trim($data['email'] ?? ($_SESSION['pw_reset_email'] ?? '')));
    $resetToken  = trim($data['reset_token'] ?? ($_SESSION['pw_reset_token'] ?? ''));
    $newPassword = $data['new_password'] ?? ($data['password'] ?? '');
    $confirmPass = $data['confirm_password'] ?? ($data['confirmPassword'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['status' => 'error', 'code' => 400, 'message' => 'Valid email address is required.'], 400);
    }

    if (empty($newPassword) || strlen($newPassword) < 6) {
        jsonResponse(['status' => 'error', 'code' => 400, 'message' => 'Password must be at least 6 characters long.'], 400);
    }

    if ($newPassword !== $confirmPass) {
        jsonResponse(['status' => 'error', 'code' => 400, 'message' => 'Passwords do not match. Please verify both fields.'], 400);
    }

    try {
        // Validate reset token from session OR database within 15 minutes
        $tokenValid = false;
        $userId = null;

        if (!empty($_SESSION['pw_reset_token']) && hash_equals($_SESSION['pw_reset_token'], $resetToken)) {
            $tokenValid = true;
            $userId = $_SESSION['pw_reset_user_id'] ?? null;
        }

        if (!$tokenValid && !empty($resetToken)) {
            $stmt = $db->prepare("SELECT user_id FROM user_otps WHERE email = :email AND reset_token = :token AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) <= 30 LIMIT 1");
            $stmt->execute([':email' => $email, ':token' => $resetToken]);
            $row = $stmt->fetch();
            if ($row) {
                $tokenValid = true;
                $userId = $row['user_id'];
            }
        }

        if (!$tokenValid) {
            jsonResponse([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Your password reset session has expired or is invalid. Please start the process again.'
            ], 403);
        }

        // Fetch user record
        $stmt = $db->prepare("SELECT id, name, email, role, status FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(['status' => 'error', 'code' => 404, 'message' => 'User account not found.'], 404);
        }

        if ($user['status'] !== 'active') {
            jsonResponse(['status' => 'error', 'code' => 403, 'message' => 'Your account is currently inactive or pending approval. Password cannot be reset.'], 403);
        }

        // Securely hash password with BCRYPT
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

        // Update password in database
        $upd = $db->prepare("UPDATE users SET password = :hash WHERE id = :id");
        $upd->execute([':hash' => $passwordHash, ':id' => $user['id']]);

        // Invalidate reset tokens and mark all OTPs used for this user
        $clearTokens = $db->prepare("UPDATE user_otps SET reset_token = NULL, is_used = 1 WHERE email = :email");
        $clearTokens->execute([':email' => $email]);

        // Clear password reset session keys
        unset($_SESSION['pw_reset_email']);
        unset($_SESSION['pw_reset_token']);
        unset($_SESSION['pw_reset_user_id']);
        unset($_SESSION['pw_reset_authorized_at']);

        // Prevent session fixation
        session_regenerate_id(true);

        // Authenticate user session
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];

        // Determine destination dashboard (admin -> /admin/overview, others -> /dashboard)
        $redirectUrl = ($user['role'] === 'admin') ? '/admin/overview' : (rtrim(getAppBasePath(), '/') . '/dashboard');

        jsonResponse([
            'status'       => 'success',
            'code'         => 200,
            'message'      => 'Password updated successfully! Redirecting to your dashboard...',
            'redirect_url' => $redirectUrl,
            'user'         => [
                'id'    => (int)$user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role']
            ]
        ]);

    } catch (Exception $e) {
        error_log("[MarketMoke ForgotPassword reset_password Error] " . $e->getMessage());
        jsonResponse(['status' => 'error', 'code' => 500, 'message' => 'Unable to reset password. Please try again.'], 500);
    }
}

// Unknown action
else {
    jsonResponse(['status' => 'error', 'code' => 400, 'message' => 'Invalid action specified.'], 400);
}
