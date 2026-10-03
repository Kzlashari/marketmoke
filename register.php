<?php
/**
 * MarketMoke - Dynamic User Registration
 * Allows users, publishers, and admins to register dynamically into MySQL with bcrypt hashing
 */

// Keep the SPA registration screen as the single public signup UI.
// The extensionless route is handled by index.php; this legacy file only
// preserves backwards-compatible links without exposing a second interface.
$registerScriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$registerBasePath = ($registerScriptDir === '/' || $registerScriptDir === '.') ? '' : rtrim($registerScriptDir, '/');
$registerQuery = !empty($_GET) ? '?' . http_build_query($_GET) : '';
header('Location: ' . $registerBasePath . '/register' . $registerQuery, true, 302);
exit();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/rate_limiter.php';

// If already authenticated, redirect to appropriate dashboard
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    if (strtolower($_SESSION['user_role'] ?? '') === 'admin') {
        header("Location: /admin/overview");
    } else {
        $base = function_exists('getAppBasePath') ? rtrim(getAppBasePath(), '/') : '';
        header("Location: {$base}/dashboard");
    }
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $company = trim($_POST['company'] ?? '');

    // Validate role: public registration allows ONLY 'user' (Buyer) or 'publisher'
    if ($role === 'admin') {
        $error = "Administrator registration is restricted. Public users cannot register as an administrator.";
    } elseif (!in_array($role, ['user', 'publisher'])) {
        $role = 'user';
    }

    if (empty($name) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif (empty($error)) {
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

            // Check if active user already exists
            $checkStmt = $db->prepare("SELECT id, status FROM users WHERE LOWER(TRIM(email)) = :email LIMIT 1");
            $checkStmt->execute([':email' => $email]);
            $existingUser = $checkStmt->fetch();

            if ($existingUser && $existingUser['status'] === 'active') {
                $error = "An account with this email address already exists. Please log in.";
            } elseif ($existingUser && in_array($existingUser['status'], ['suspended', 'rejected'])) {
                $error = "Registration cannot proceed. Please contact support.";
            } else {
                // Invalidate any existing unused OTPs for this email
                $invStmt = $db->prepare("UPDATE user_otps SET is_used = 1 WHERE email = :email AND is_used = 0");
                $invStmt->execute([':email' => $email]);

                // Generate secure 6-digit OTP
                $rawOtp = random_int(100000, 999999);
                $plainOtp = sprintf("%06d", $rawOtp);
                $hashedOtp = password_hash($plainOtp, PASSWORD_DEFAULT);
                $expiryMinutes = defined('OTP_EXPIRY_MINUTES') ? (int)OTP_EXPIRY_MINUTES : 20;

                // Insert into user_otps
                $insStmt = $db->prepare("INSERT INTO user_otps (user_id, email, otp_code, attempts, expires_at, is_used, created_at) 
                    VALUES (:user_id, :email, :otp_code, 0, DATE_ADD(NOW(), INTERVAL {$expiryMinutes} MINUTE), 0, NOW())");
                $insStmt->execute([
                    ':user_id'  => $existingUser ? (int)$existingUser['id'] : null,
                    ':email'    => $email,
                    ':otp_code' => $hashedOtp
                ]);
                $insertedOtpId = (int)$db->lastInsertId();

                // Dispatch Verification Code via PHPMailer
                $mailSent = false;
                try {
                    $mailSent = MarketMokeMailer::sendSignupOtpEmail($email, $name, $plainOtp);
                } catch (Exception $mailEx) {
                    error_log("[MarketMoke Signup Mail Error] " . $mailEx->getMessage());
                    $mailSent = false;
                }

                if (!$mailSent) {
                    if ($insertedOtpId > 0) {
                        $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $insertedOtpId]);
                    }
                    $error = "Unable to send verification code. Please try again.";
                } else {
                    error_log("[MarketMoke Signup OTP] Dispatched verification code to {$email}: {$plainOtp}");
                    // Store registration payload in session
                    $_SESSION['temp_reg_data'] = [
                        'existing_id'   => $existingUser ? (int)$existingUser['id'] : null,
                        'name'          => $name,
                        'email'         => $email,
                        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                        'role'          => $role,
                        'company'       => $company
                    ];
                    $_SESSION['temp_user_email']   = $email;
                    $_SESSION['temp_user_name']    = $name;
                    $_SESSION['temp_auth_type']    = 'signup';
                    $_SESSION['temp_auth_time']    = time();
                    $_SESSION['temp_auth_expires'] = time() + ($expiryMinutes * 60);

                    header("Location: verify-otp.php");
                    exit();
                }
            }
        } catch (PDOException $e) {
            error_log("[MarketMoke Registration Error] " . $e->getMessage());
            $error = "Database registration error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MarketMoke - Create Account</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; }
    .bg-analytics-pattern {
      background-color: #0b1329;
      background-image: 
        radial-gradient(at 10% 20%, rgba(30, 58, 138, 0.45) 0px, transparent 50%),
        radial-gradient(at 90% 15%, rgba(67, 56, 202, 0.4) 0px, transparent 50%),
        radial-gradient(at 80% 85%, rgba(14, 116, 144, 0.35) 0px, transparent 50%);
    }
  </style>
</head>
<body class="min-h-screen bg-analytics-pattern text-slate-800 flex flex-col justify-between antialiased selection:bg-blue-600 selection:text-white">

  <header class="w-full max-w-7xl mx-auto px-4 py-6 flex items-center justify-between">
    <a href="index.html" class="flex items-center gap-2.5 text-white">
      <div class="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center font-black text-xl shadow-lg">M</div>
      <div class="leading-none">
        <span class="text-xl font-black text-white">Market<span class="text-blue-400">Moke</span></span>
        <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Platform Registration</span>
      </div>
    </a>

    <a href="login.php" class="text-xs font-bold text-slate-300 hover:text-white px-4 py-2 rounded-xl border border-slate-700 bg-slate-800/60 transition-all">
      Sign In
    </a>
  </header>

  <main class="flex-1 flex items-center justify-center px-4 py-8">
    <div class="w-full max-w-[480px] bg-white rounded-[28px] shadow-2xl border border-slate-100 p-8 sm:p-10">
      
      <div class="text-center mb-6">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 mb-2">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
        </div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Create Your MarketMoke Account</h1>
        <p class="text-xs text-slate-500 mt-1">Join the SEO & Guest Post Marketplace platform</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="mb-5 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs font-semibold">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form action="register.php" method="POST" class="space-y-4">
        
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Full Name</label>
          <input 
            type="text" 
            name="name" 
            required 
            placeholder="Sarah Jenkins"
            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
            class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600"
          />
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Email Address</label>
          <input 
            type="email" 
            name="email" 
            required 
            placeholder="sarah@example.com"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600"
          />
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Password</label>
          <input 
            type="password" 
            name="password" 
            required 
            placeholder="Min. 6 characters"
            class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600"
          />
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Select Account Role</label>
          <select 
            name="role" 
            class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600">
            <option value="user" <?= (isset($_POST['role']) && $_POST['role'] === 'user') ? 'selected' : '' ?>>Client / Buyer (Buy Guest Posts & Backlinks)</option>
            <option value="publisher" <?= (isset($_POST['role']) && $_POST['role'] === 'publisher') ? 'selected' : '' ?>>Publisher / Media Owner (Sell Guest Posts)</option>
          </select>
        </div>

        <button 
          type="submit" 
          class="w-full py-3.5 px-4 bg-[#1b3a6b] hover:bg-[#142d54] text-white font-black text-sm rounded-xl shadow-md transition-all mt-2">
          Submit Registration for Approval
        </button>

      </form>

      <div class="mt-6 text-center text-xs font-semibold text-slate-600 pt-4 border-t border-slate-100">
        Already have an account? <a href="login.php" class="text-blue-600 font-extrabold hover:underline">Sign In</a>
      </div>

    </div>
  </main>

  <footer class="w-full py-5 text-center text-xs font-medium text-slate-400">
    <span>&copy; <?= date('Y') ?> MarketMoke SEO Solutions. All rights reserved.</span>
  </footer>

</body>
</html>
