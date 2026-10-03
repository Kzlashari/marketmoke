<?php
/**
 * MarketMoke - Production-Ready OTP Login
 * Step 1: Validate credentials against MySQL via PDO, generate 6-digit OTP, send via PHPMailer
 */

// Keep the SPA login as the single public authentication UI.
// The extensionless route is handled by index.php; this legacy file only
// preserves backwards-compatible links without exposing a second interface.
$loginScriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$loginBasePath = ($loginScriptDir === '/' || $loginScriptDir === '.') ? '' : rtrim($loginScriptDir, '/');
$loginQuery = !empty($_GET) ? '?' . http_build_query($_GET) : '';
header('Location: ' . $loginBasePath . '/login' . $loginQuery, true, 302);
exit();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/rate_limiter.php';

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// If already authenticated, redirect to role-specific dashboard
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    if (strtolower($_SESSION['user_role'] ?? '') === 'admin') {
        header("Location: /admin/overview");
    } else {
        header("Location: " . rtrim(getAppBasePath(), '/') . "/dashboard");
    }
    exit();
}

$error = '';
$success = '';

// Handle messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'pending_approval') {
        $success = "Registration submitted successfully! Your account is currently pending administrator approval. Once approved, you will be able to log in.";
    } elseif ($_GET['msg'] === 'registered') {
        $success = "Registration received! Your account is pending administrator approval.";
    } elseif ($_GET['msg'] === 'logged_out') {
        $success = "You have been safely logged out.";
    } elseif ($_GET['msg'] === 'auth_required') {
        $error = "Please sign in to access your dashboard.";
    } elseif ($_GET['msg'] === 'account_inactive') {
        $error = "Your account is currently inactive. Please contact support.";
    } elseif ($_GET['msg'] === 'session_expired') {
        $error = "Your verification session has expired. Please sign in again.";
    }
}

// Process Login Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    // Validate CSRF if present in session
    if (!empty($_SESSION['csrf_token']) && !empty($csrf) && !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = "Invalid session submission. Please refresh and try again.";
    } elseif (empty($email) || empty($password)) {
        $error = "Please provide both email address and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Enforce persistent IP-based rate limiting (5 attempts per 15 minutes)
        $rateLimitKey = 'login_' . md5(strtolower($email));
        checkRateLimit($rateLimitKey, 5, 900);

        try {
            $db = Database::getInstance()->getConnection();

            // Ensure user_otps table exists
            $db->exec("CREATE TABLE IF NOT EXISTS `user_otps` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT DEFAULT NULL,
                `email` VARCHAR(150) NOT NULL,
                `otp_code` VARCHAR(255) NOT NULL,
                `attempts` INT NOT NULL DEFAULT 0,
                `expires_at` DATETIME NOT NULL,
                `is_used` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_user_otps_lookup` (`email`, `is_used`, `expires_at`),
                INDEX `idx_user_otps_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Look up user by email with PDO prepared statement
            $stmt = $db->prepare("SELECT id, name, email, password, role, status FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1");
            $stmt->execute([':email' => strtolower($email)]);
            $user = $stmt->fetch();

            // Resilient password verification (bcrypt, plaintext, MD5, SHA-256)
            $isPasswordValid = false;
            if ($user && !empty($user['password'])) {
                $storedPwd = trim((string)$user['password']);

                // 1. Bcrypt format ($2y$, $2a$, $2b$)
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
                        error_log("[MarketMoke Rehash Error] " . $e->getMessage());
                    }
                }
            }

            if (!$user || !$isPasswordValid) {
                $error = "Invalid email address or password.";
            } else {
                // Password is valid - check account status
                if ($user['status'] === 'pending') {
                    $error = "Your account is pending administrator approval. Once approved by an administrator, you will be permitted to log in.";
                } elseif ($user['status'] === 'rejected') {
                    $error = "Your account registration has been reviewed and rejected. Login access is not permitted.";
                } elseif ($user['status'] === 'suspended') {
                    $error = "Your account has been suspended by an administrator. Please contact support.";
                } elseif ($user['status'] !== 'active') {
                    $error = "Your account is currently inactive. Please contact system support.";
                } else {
                    // Valid credentials & active status: reset rate limiter counter
                    clearRateLimit($rateLimitKey);

                    // Standardize OTP: generate cryptographically secure 6-digit OTP
                    $otp = sprintf("%06d", random_int(100000, 999999));
                    $hashedOtp = password_hash($otp, PASSWORD_DEFAULT);
                    $expiryMinutes = defined('OTP_EXPIRY_MINUTES') ? (int)OTP_EXPIRY_MINUTES : 20;

                    // Invalidate any existing unused OTPs for this email in user_otps
                    $invalidateStmt = $db->prepare("UPDATE user_otps SET is_used = 1 WHERE email = :email AND is_used = 0");
                    $invalidateStmt->execute([':email' => $user['email']]);

                    // Also invalidate legacy otp_verifications table if present
                    try {
                        $db->prepare("UPDATE otp_verifications SET is_used = 1 WHERE user_id = :uid AND is_used = 0")->execute([':uid' => $user['id']]);
                    } catch (Exception $ignore) {}

                    // Insert fresh hashed OTP into user_otps table
                    $insertStmt = $db->prepare("INSERT INTO user_otps (user_id, email, otp_code, attempts, expires_at, is_used, created_at) 
                        VALUES (:user_id, :email, :otp_code, 0, DATE_ADD(NOW(), INTERVAL {$expiryMinutes} MINUTE), 0, NOW())");
                    $insertStmt->execute([
                        ':user_id'  => $user['id'],
                        ':email'    => $user['email'],
                        ':otp_code' => $hashedOtp
                    ]);
                    $insertedOtpId = (int)$db->lastInsertId();

                    // CRITICAL EXIT-POINT: Send OTP via PHPMailer
                    $mailSent = false;
                    try {
                        $mailSent = MarketMokeMailer::sendOtpEmail($user['email'], $user['name'], $otp);
                    } catch (Exception $mailEx) {
                        error_log("[MarketMoke Login Mail Failure] " . $mailEx->getMessage());
                        $mailSent = false;
                    }

                    if (!$mailSent) {
                        // CRITICAL: On mail failure, MUST NOT redirect to verify-otp.php!
                        // Invalidate/remove the generated OTP immediately
                        if ($insertedOtpId > 0) {
                            $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $insertedOtpId]);
                        }
                        $error = "Unable to send verification code. Please try again.";
                    } else {
                        error_log("[MarketMoke Login OTP] Dispatched verification code to {$user['email']}: {$otp}");
                        // Mail dispatched successfully! Set temporary auth session
                        $_SESSION['temp_user_id']      = (int)$user['id'];
                        $_SESSION['temp_user_email']   = $user['email'];
                        $_SESSION['temp_user_name']    = $user['name'];
                        $_SESSION['temp_auth_type']    = 'login';
                        $_SESSION['temp_auth_time']    = time();
                        $_SESSION['temp_auth_expires'] = time() + ($expiryMinutes * 60);

                        header("Location: verify-otp.php");
                        exit();
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("[MarketMoke Login Database Exception] " . $e->getMessage());
            $error = "A temporary system error occurred. Please try again shortly.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MarketMoke - Access Your SEO Dashboard</title>
  <meta name="description" content="Sign in to unlock insights and optimization tools on MarketMoke SEO & Guest Post Marketplace platform.">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  
  <style>
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .bg-analytics-pattern {
      background-color: #0b1329;
      background-image: 
        radial-gradient(at 10% 20%, rgba(30, 58, 138, 0.45) 0px, transparent 50%),
        radial-gradient(at 90% 15%, rgba(67, 56, 202, 0.4) 0px, transparent 50%),
        radial-gradient(at 80% 85%, rgba(14, 116, 144, 0.35) 0px, transparent 50%),
        radial-gradient(at 20% 80%, rgba(30, 27, 75, 0.5) 0px, transparent 50%);
    }
    .watermark-cloud {
      position: absolute;
      pointer-events: none;
      user-select: none;
      opacity: 0.18;
      filter: blur(0.5px);
    }
  </style>
</head>
<body class="min-h-screen bg-analytics-pattern text-slate-800 flex flex-col justify-between relative overflow-x-hidden antialiased selection:bg-blue-600 selection:text-white">

  <!-- Background Decorative SEO Analytics Graphics Matching Provided Design -->
  <div class="fixed inset-0 overflow-hidden pointer-events-none z-0" aria-hidden="true">
    <!-- Left SEO Chart Mock -->
    <div class="absolute -left-12 top-20 w-80 md:w-96 p-5 bg-slate-900/60 backdrop-blur-md rounded-3xl border border-slate-700/50 shadow-2xl opacity-60 hidden lg:block">
      <div class="flex items-center justify-between mb-4">
        <span class="text-xs font-bold text-slate-300">Organic Traffic Growth</span>
        <span class="text-xs font-black text-emerald-400">+142%</span>
      </div>
      <div class="h-32 flex items-end gap-2 px-2">
        <div class="w-1/6 bg-blue-500/40 rounded-t h-1/3"></div>
        <div class="w-1/6 bg-blue-500/60 rounded-t h-1/2"></div>
        <div class="w-1/6 bg-indigo-500/60 rounded-t h-2/3"></div>
        <div class="w-1/6 bg-cyan-400/70 rounded-t h-3/4"></div>
        <div class="w-1/6 bg-emerald-400/80 rounded-t h-5/6"></div>
        <div class="w-1/6 bg-emerald-500 rounded-t h-full shadow-lg"></div>
      </div>
    </div>

    <!-- Right SEO Keyword Cloud Mock -->
    <div class="watermark-cloud right-6 top-16 max-w-sm hidden lg:block text-slate-200 text-right font-black leading-tight">
      <p class="text-4xl text-slate-400/40">Organic Ranking</p>
      <p class="text-2xl text-blue-400/50 mt-1">SEO Keywords Backlinks</p>
      <p class="text-5xl text-slate-300/30">Analytics</p>
      <p class="text-xl text-indigo-300/40 mt-1">DA / DR Traffic Authority</p>
      <p class="text-3xl text-cyan-400/40">Guest Posting</p>
    </div>

    <!-- Bottom Network Nodes Graphic Mock -->
    <div class="absolute right-10 bottom-12 opacity-30 hidden xl:block">
      <svg width="260" height="140" viewBox="0 0 260 140" fill="none" stroke="currentColor" class="text-blue-400">
        <circle cx="40" cy="70" r="14" fill="#1e3a8a" stroke="#60a5fa" stroke-width="2"/>
        <circle cx="130" cy="30" r="18" fill="#1e293b" stroke="#38bdf8" stroke-width="2"/>
        <circle cx="140" cy="110" r="12" fill="#1e293b" stroke="#818cf8" stroke-width="2"/>
        <circle cx="220" cy="70" r="16" fill="#1e3a8a" stroke="#34d399" stroke-width="2"/>
        <line x1="54" y1="70" x2="112" y2="35" stroke="#475569" stroke-width="1.5" stroke-dasharray="3 3"/>
        <line x1="52" y1="74" x2="128" y2="106" stroke="#475569" stroke-width="1.5" stroke-dasharray="3 3"/>
        <line x1="148" y1="35" x2="204" y2="65" stroke="#475569" stroke-width="1.5" stroke-dasharray="3 3"/>
        <line x1="152" y1="106" x2="206" y2="75" stroke="#475569" stroke-width="1.5" stroke-dasharray="3 3"/>
      </svg>
    </div>
  </div>

  <!-- Top Simple Navigation Bar -->
  <header class="relative z-10 w-full max-w-7xl mx-auto px-4 py-6 flex items-center justify-between">
    <a href="index.html" class="flex items-center gap-2.5 text-white group">
      <div class="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center font-black text-xl shadow-lg group-hover:bg-blue-500 transition-colors">
        M
      </div>
      <div class="leading-none">
        <span class="text-xl font-black tracking-tight text-white">Market<span class="text-blue-400">Moke</span></span>
        <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">SEO Platform</span>
      </div>
    </a>

    <div class="flex items-center gap-3">
      <a href="index.html" class="text-xs font-bold text-slate-300 hover:text-white px-3 py-1.5 rounded-lg transition-colors">
        Marketplace
      </a>
      <a href="register.php" class="text-xs font-bold bg-white/10 hover:bg-white/20 text-white border border-white/20 px-4 py-2 rounded-xl transition-all">
        Create Account
      </a>
    </div>
  </header>

  <!-- Main Login Card (Matching Attached UI Design Exactly) -->
  <main class="relative z-10 flex-1 flex items-center justify-center px-4 py-8">
    <div class="w-full max-w-[440px] bg-white rounded-[28px] shadow-[0_25px_60px_-15px_rgba(0,0,0,0.4)] border border-slate-100 p-8 sm:p-10 transition-all">
      
      <!-- Brand Logo in Card -->
      <div class="text-center space-y-1 mb-6">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-tr from-slate-900 to-blue-900 text-white shadow-md mx-auto mb-2">
          <!-- Stylized M with chart/bag silhouette -->
          <svg class="w-8 h-8 text-blue-400" viewBox="0 0 24 24" fill="currentColor">
            <path d="M4 4h4l4 7 4-7h4v16h-3.5v-9.5L13.2 17h-2.4L7.5 10.5V20H4V4z"/>
          </svg>
        </div>
        <h2 class="text-xl font-black text-slate-900 tracking-tight uppercase">MarketMoke</h2>
        <p class="text-[11px] font-bold text-slate-400 tracking-wider uppercase">SEO Performance Platform</p>
      </div>

      <!-- Card Heading & Subtitle -->
      <div class="text-center mb-6">
        <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Access Your SEO Dashboard</h1>
        <p class="text-xs text-slate-500 mt-1">Sign in to unlock insights and optimization tools.</p>
      </div>

      <!-- Alerts -->
      <?php if (!empty($error)): ?>
        <div class="mb-5 p-3.5 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs font-semibold flex items-center gap-2">
          <svg class="w-4 h-4 text-red-500 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="mb-5 p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs font-semibold flex items-center gap-2">
          <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
          <span><?= htmlspecialchars($success) ?></span>
        </div>
      <?php endif; ?>

      <!-- Login Form -->
      <form action="login.php" method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        
        <!-- Email Field -->
        <div>
          <label for="email" class="block text-xs font-bold text-slate-700 mb-1">Email Address</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </span>
            <input 
              type="email" 
              id="email" 
              name="email" 
              required 
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              placeholder="jane.doe@example.com"
              class="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent transition-all shadow-xs"
              autocomplete="email"
              autofocus
            />
          </div>
        </div>

        <!-- Password Field -->
        <div>
          <label for="password" class="block text-xs font-bold text-slate-700 mb-1">Password</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </span>
            <input 
              type="password" 
              id="password" 
              name="password" 
              required 
              placeholder="••••••••"
              class="w-full pl-10 pr-10 py-3 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent transition-all shadow-xs"
              autocomplete="current-password"
            />
            <button 
              type="button" 
              onclick="togglePasswordVisibility()" 
              class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none"
              aria-label="Toggle password visibility">
              <svg id="eyeIcon" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </button>
          </div>
        </div>

        <!-- Forgot Password Link -->
        <div class="text-right">
          <a href="#forgot-password" onclick="openForgotPasswordModal(event)" class="text-xs font-bold text-slate-600 hover:text-blue-600 hover:underline">
            Forgot Password?
          </a>
        </div>

        <!-- Primary Sign In Button -->
        <button 
          type="submit" 
          id="signInBtn"
          class="w-full py-3.5 px-4 bg-[#1b3a6b] hover:bg-[#142d54] active:bg-[#0f213f] text-white font-black text-sm rounded-xl shadow-md hover:shadow-lg transition-all flex items-center justify-center gap-2">
          <span>Sign In</span>
        </button>

      </form>

      <!-- Social Login Options -->
      <div class="mt-6">
        <div class="relative flex py-2 items-center">
          <div class="flex-grow border-t border-slate-200"></div>
          <span class="flex-shrink mx-3 text-[11px] font-bold text-slate-400 uppercase tracking-wider">Or sign in with:</span>
          <div class="flex-grow border-t border-slate-200"></div>
        </div>

        <div class="grid grid-cols-3 gap-2.5 mt-3">
          <!-- Google -->
          <button 
            type="button" 
            onclick="alert('Google SSO ready for integration. Please proceed with Email + OTP login.')"
            class="flex flex-col items-center justify-center p-2.5 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-xl transition-all group">
            <svg class="w-5 h-5 mb-1" viewBox="0 0 24 24">
              <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.8-2.4 3.66v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.15z"/>
              <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.24v3.15C3.26 21.36 7.33 24 12 24z"/>
              <path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.24C.45 8.15 0 9.92 0 12s.45 3.85 1.24 5.42l4.04-3.15z"/>
              <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.24 6.58l4.04 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/>
            </svg>
            <span class="text-[10px] font-bold text-slate-700 leading-tight text-center">Continue with Google</span>
          </button>

          <!-- Facebook -->
          <button 
            type="button" 
            onclick="alert('Facebook SSO ready for integration. Please proceed with Email + OTP login.')"
            class="flex flex-col items-center justify-center p-2.5 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-xl transition-all group">
            <svg class="w-5 h-5 mb-1 text-[#1877F2] fill-current" viewBox="0 0 24 24">
              <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
            <span class="text-[10px] font-bold text-slate-700 leading-tight text-center">Continue with Facebook</span>
          </button>

          <!-- Apple -->
          <button 
            type="button" 
            onclick="alert('Apple SSO ready for integration. Please proceed with Email + OTP login.')"
            class="flex flex-col items-center justify-center p-2.5 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-xl transition-all group">
            <svg class="w-5 h-5 mb-1 text-slate-900 fill-current" viewBox="0 0 24 24">
              <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M15.97 6.38c.62-.75 1.04-1.8 1.01-2.85-.92.04-2.03.62-2.69 1.38-.58.67-1.09 1.74-.95 2.78 1.03.08 2.01-.56 2.63-1.31z"/>
            </svg>
            <span class="text-[10px] font-bold text-slate-700 leading-tight text-center">Continue with Apple</span>
          </button>
        </div>
      </div>

      <!-- Sign Up Footer Link -->
      <div class="mt-6 text-center text-xs font-semibold text-slate-600 pt-4 border-t border-slate-100">
        Don't have an account? 
        <a href="register.php" class="text-blue-600 font-extrabold hover:underline">
          Sign Up
        </a>
      </div>

    </div>
  </main>

  <!-- Page Footer Matching Design -->
  <footer class="relative z-10 w-full py-5 text-center text-xs font-medium text-slate-400">
    <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2">
      <span>&copy; <?= date('Y') ?> MarketMoke SEO Solutions. All rights reserved.</span>
      <span class="hidden sm:inline">|</span>
      <div class="flex items-center gap-2">
        <a href="terms.html" class="hover:text-slate-200 transition-colors">Terms of Service</a>
        <span>|</span>
        <a href="privacy.html" class="hover:text-slate-200 transition-colors">Privacy Policy</a>
      </div>
    </div>
  </footer>

  <!-- Forgot Password Popup Modal -->
  <div id="forgotPasswordModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-950/70 backdrop-blur-xs transition-opacity duration-200">
    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden transform transition-all">
      <!-- Modal Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/70">
        <div>
          <h2 id="fpModalTitle" class="text-base font-extrabold text-slate-900">Forgot Password</h2>
          <p id="fpModalSubtitle" class="text-xs text-slate-500 font-medium">Enter your registered email to receive a verification code.</p>
        </div>
        <button type="button" onclick="closeForgotPasswordModal()" class="w-8 h-8 rounded-full text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 flex items-center justify-center text-lg font-bold transition-all" aria-label="Close">
          &times;
        </button>
      </div>

      <!-- Modal Body -->
      <div class="p-6">
        <!-- Alert Notification Box -->
        <div id="fpAlertBox" class="hidden mb-4 p-3 rounded-xl text-xs font-semibold flex items-center gap-2"></div>

        <!-- STEP 1: Enter Email & Send OTP -->
        <form id="fpStep1Form" onsubmit="handleFpSendOtp(event)" class="space-y-4">
          <div>
            <label for="fpEmail" class="block text-xs font-bold text-slate-700 mb-1">Email Address</label>
            <div class="relative">
              <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
              </span>
              <input 
                type="email" 
                id="fpEmail" 
                name="email" 
                required 
                placeholder="name@example.com" 
                class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent transition-all"
              />
            </div>
          </div>
          <button 
            type="submit" 
            id="fpSendOtpBtn" 
            class="w-full py-3.5 px-4 bg-[#1b3a6b] hover:bg-[#142d54] active:bg-[#0f213f] text-white font-black text-xs rounded-xl shadow-md transition-all flex items-center justify-center gap-2">
            <span>Send OTP</span>
          </button>
        </form>

        <!-- STEP 2: Verify OTP Code -->
        <form id="fpStep2Form" onsubmit="handleFpVerifyOtp(event)" class="hidden space-y-4">
          <div class="bg-blue-50/80 border border-blue-100 rounded-xl p-3 text-center">
            <span class="text-[11px] font-semibold text-slate-600">Verification code sent to:</span>
            <div id="fpTargetEmailDisplay" class="font-extrabold text-blue-900 text-xs truncate mt-0.5"></div>
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label for="fpOtpInput" class="text-xs font-bold text-slate-700">6-Digit Verification Code</label>
              <span id="fpTimerDisplay" class="text-[11px] font-extrabold text-amber-600">Expires: 20:00</span>
            </div>
            <input 
              type="text" 
              id="fpOtpInput" 
              name="otp" 
              maxlength="6" 
              pattern="[0-9]{6}" 
              inputmode="numeric" 
              required 
              placeholder="123456" 
              class="w-full py-3 px-4 text-center tracking-[0.4em] font-mono text-lg font-black bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent transition-all"
            />
          </div>

          <div class="flex items-center justify-between text-xs pt-1">
            <button type="button" onclick="goToFpStep(1)" class="font-bold text-slate-500 hover:text-slate-800 flex items-center gap-1">
              <span>&larr;</span> <span>Back</span>
            </button>
            <button type="button" id="fpResendBtn" onclick="handleFpResendOtp()" class="font-bold text-blue-600 hover:text-blue-800 disabled:text-slate-400 disabled:cursor-not-allowed">
              Resend OTP
            </button>
          </div>

          <button 
            type="submit" 
            id="fpVerifyOtpBtn" 
            class="w-full py-3.5 px-4 bg-[#1b3a6b] hover:bg-[#142d54] active:bg-[#0f213f] text-white font-black text-xs rounded-xl shadow-md transition-all flex items-center justify-center gap-2">
            <span>Verify OTP</span>
          </button>
        </form>

        <!-- STEP 3: Create New Password -->
        <form id="fpStep3Form" onsubmit="handleFpResetPassword(event)" class="hidden space-y-4">
          <div>
            <label for="fpNewPassword" class="block text-xs font-bold text-slate-700 mb-1">New Password</label>
            <div class="relative">
              <input 
                type="password" 
                id="fpNewPassword" 
                name="new_password" 
                minlength="6" 
                required 
                placeholder="At least 6 characters" 
                class="w-full pl-3.5 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent transition-all"
              />
              <button 
                type="button" 
                onclick="toggleFpPwdVisibility('fpNewPassword', 'fpEye1')" 
                class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600"
                aria-label="Toggle password visibility">
                <svg id="fpEye1" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </div>
          </div>

          <div>
            <label for="fpConfirmPassword" class="block text-xs font-bold text-slate-700 mb-1">Confirm New Password</label>
            <div class="relative">
              <input 
                type="password" 
                id="fpConfirmPassword" 
                name="confirm_password" 
                minlength="6" 
                required 
                placeholder="Re-enter password" 
                class="w-full pl-3.5 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent transition-all"
              />
              <button 
                type="button" 
                onclick="toggleFpPwdVisibility('fpConfirmPassword', 'fpEye2')" 
                class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600"
                aria-label="Toggle password visibility">
                <svg id="fpEye2" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </div>
          </div>

          <button 
            type="submit" 
            id="fpResetBtn" 
            class="w-full py-3.5 px-4 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black text-xs rounded-xl shadow-md transition-all flex items-center justify-center gap-2">
            <span>Save New Password &amp; Sign In</span>
          </button>
        </form>
      </div>
    </div>
  </div>

  <script>
    function togglePasswordVisibility() {
      const pwd = document.getElementById('password');
      pwd.type = pwd.type === 'password' ? 'text' : 'password';
    }

    function toggleFpPwdVisibility(inputId, iconId) {
      const el = document.getElementById(inputId);
      if (el) el.type = el.type === 'password' ? 'text' : 'password';
    }

    // Dynamic API Endpoint Helper for Subdirectory / XAMPP / Root Compatibility
    function getApiEndpoint(endpoint) {
      let base = '<?= function_exists("getAppBasePath") ? addslashes(getAppBasePath()) : "" ?>';
      if (!base) {
        const m = window.location.pathname.match(/^(.*?)\/(login|verify-otp|dashboard|admin|user|publisher|author)/i);
        if (m && m[1]) base = m[1];
      }
      return (base ? base.replace(/\/$/, '') : '') + (endpoint.startsWith('/') ? endpoint : '/' + endpoint);
    }

    // Forgot Password State
    const fpState = {
      email: '',
      resetToken: '',
      expiryTime: 0,
      timerInterval: null,
      cooldownInterval: null,
      cooldownLeft: 0
    };

    function resetFpState() {
      if (fpState.timerInterval) clearInterval(fpState.timerInterval);
      if (fpState.cooldownInterval) clearInterval(fpState.cooldownInterval);
      fpState.email = '';
      fpState.resetToken = '';
      fpState.expiryTime = 0;
      fpState.cooldownLeft = 0;
      clearFpAlert();
      const elEmail = document.getElementById('fpEmail');
      const elOtp = document.getElementById('fpOtpInput');
      const elNewPass = document.getElementById('fpNewPassword');
      const elConfPass = document.getElementById('fpConfirmPassword');
      if (elEmail) elEmail.value = '';
      if (elOtp) elOtp.value = '';
      if (elNewPass) elNewPass.value = '';
      if (elConfPass) elConfPass.value = '';
    }

    // Safe DOM rendering for alerts (prevents XSS)
    function showFpAlert(msg, type = 'error') {
      const box = document.getElementById('fpAlertBox');
      if (!box) return;
      box.classList.remove('hidden', 'bg-red-50', 'border-red-200', 'text-red-700', 'bg-emerald-50', 'border-emerald-200', 'text-emerald-800', 'bg-blue-50', 'border-blue-200', 'text-blue-800');
      box.innerHTML = '';

      const iconSpan = document.createElement('span');
      iconSpan.textContent = type === 'error' ? '⚠️' : (type === 'success' ? '✓' : 'ℹ️');

      const textSpan = document.createElement('span');
      textSpan.textContent = String(msg || '');

      if (type === 'error') {
        box.classList.add('bg-red-50', 'border', 'border-red-200', 'text-red-700');
      } else if (type === 'success') {
        box.classList.add('bg-emerald-50', 'border', 'border-emerald-200', 'text-emerald-800');
      } else {
        box.classList.add('bg-blue-50', 'border', 'border-blue-200', 'text-blue-800');
      }
      box.appendChild(iconSpan);
      box.appendChild(textSpan);
    }

    function clearFpAlert() {
      const box = document.getElementById('fpAlertBox');
      if (box) {
        box.classList.add('hidden');
        box.innerHTML = '';
      }
    }

    function openForgotPasswordModal(e) {
      if (e && e.preventDefault) e.preventDefault();
      resetFpState();
      const modal = document.getElementById('forgotPasswordModal');
      if (!modal) return;
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      const emailField = document.getElementById('email');
      const fpEmail = document.getElementById('fpEmail');
      if (emailField && emailField.value.trim() && fpEmail) {
        fpEmail.value = emailField.value.trim();
      }
      goToFpStep(1);
    }

    function closeForgotPasswordModal() {
      const modal = document.getElementById('forgotPasswordModal');
      if (!modal) return;
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      resetFpState();
    }

    function goToFpStep(step) {
      clearFpAlert();
      const title = document.getElementById('fpModalTitle');
      const subtitle = document.getElementById('fpModalSubtitle');
      const step1 = document.getElementById('fpStep1Form');
      const step2 = document.getElementById('fpStep2Form');
      const step3 = document.getElementById('fpStep3Form');

      step1.classList.add('hidden');
      step2.classList.add('hidden');
      step3.classList.add('hidden');

      if (step === 1) {
        title.textContent = 'Forgot Password';
        subtitle.textContent = "Enter your registered email address and we'll send you a verification code.";
        step1.classList.remove('hidden');
        setTimeout(() => document.getElementById('fpEmail')?.focus(), 50);
      } else if (step === 2) {
        title.textContent = 'Verify OTP';
        subtitle.textContent = 'Enter the 6-digit verification code sent to your email.';
        step2.classList.remove('hidden');
        const emailDisp = document.getElementById('fpTargetEmailDisplay');
        if (emailDisp) emailDisp.textContent = fpState.email;
        startFpTimer();
        startFpCooldown(60);
        setTimeout(() => document.getElementById('fpOtpInput')?.focus(), 50);
      } else if (step === 3) {
        title.textContent = 'Create New Password';
        subtitle.textContent = 'Choose a strong new password for your account.';
        step3.classList.remove('hidden');
        setTimeout(() => document.getElementById('fpNewPassword')?.focus(), 50);
      }
    }

    function startFpTimer() {
      if (fpState.timerInterval) clearInterval(fpState.timerInterval);
      fpState.expiryTime = Date.now() + 20 * 60 * 1000;
      const display = document.getElementById('fpTimerDisplay');
      
      const update = () => {
        const remaining = Math.max(0, Math.floor((fpState.expiryTime - Date.now()) / 1000));
        const mins = Math.floor(remaining / 60);
        const secs = remaining % 60;
        if (display) {
          display.textContent = `Expires: ${mins}:${secs < 10 ? '0' : ''}${secs}`;
          if (remaining <= 60) {
            display.className = 'text-[11px] font-extrabold text-red-600 animate-pulse';
          } else {
            display.className = 'text-[11px] font-extrabold text-amber-600';
          }
        }
        if (remaining <= 0) {
          clearInterval(fpState.timerInterval);
          showFpAlert('Verification code has expired. Please request a new code.');
        }
      };
      update();
      fpState.timerInterval = setInterval(update, 1000);
    }

    function startFpCooldown(seconds = 60) {
      if (fpState.cooldownInterval) clearInterval(fpState.cooldownInterval);
      fpState.cooldownLeft = seconds;
      const btn = document.getElementById('fpResendBtn');
      if (!btn) return;
      btn.disabled = true;

      const update = () => {
        if (fpState.cooldownLeft > 0) {
          btn.textContent = `Resend in ${fpState.cooldownLeft}s`;
          fpState.cooldownLeft--;
        } else {
          btn.disabled = false;
          btn.textContent = 'Resend OTP';
          clearInterval(fpState.cooldownInterval);
        }
      };
      update();
      fpState.cooldownInterval = setInterval(update, 1000);
    }

    async function handleFpSendOtp(e) {
      e.preventDefault();
      clearFpAlert();
      const email = document.getElementById('fpEmail').value.trim();
      if (!email) return;

      const btn = document.getElementById('fpSendOtpBtn');
      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = `<span>Sending Code...</span>`;

      try {
        const res = await fetch(getApiEndpoint('/api/forgot_password.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'send_otp', email })
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data.status === 'success') {
          fpState.email = email;
          goToFpStep(2);
          showFpAlert(data.message || 'Verification code sent to your email.', 'success');
        } else {
          showFpAlert(data.message || 'Unable to process request. Please try again.');
        }
      } catch (err) {
        showFpAlert('Network error. Please verify your connection.');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
      }
    }

    async function handleFpResendOtp() {
      if (fpState.cooldownLeft > 0 || !fpState.email) return;
      clearFpAlert();
      const btn = document.getElementById('fpResendBtn');
      btn.disabled = true;
      btn.textContent = 'Sending...';

      try {
        const res = await fetch(getApiEndpoint('/api/forgot_password.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'send_otp', email: fpState.email })
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.status === 'success') {
          showFpAlert('New verification code sent successfully.', 'success');
          startFpTimer();
          startFpCooldown(60);
        } else {
          showFpAlert(data.message || 'Could not resend code. Please try again.');
          btn.disabled = false;
          btn.textContent = 'Resend OTP';
        }
      } catch (err) {
        showFpAlert('Connection error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Resend OTP';
      }
    }

    async function handleFpVerifyOtp(e) {
      e.preventDefault();
      clearFpAlert();
      const otp = document.getElementById('fpOtpInput').value.trim();
      if (!otp || otp.length !== 6) {
        showFpAlert('Please enter the full 6-digit code.');
        return;
      }

      const btn = document.getElementById('fpVerifyOtpBtn');
      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = `<span>Verifying...</span>`;

      try {
        const res = await fetch(getApiEndpoint('/api/forgot_password.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'verify_otp', email: fpState.email, otp })
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data.status === 'success') {
          fpState.resetToken = data.reset_token;
          if (fpState.timerInterval) clearInterval(fpState.timerInterval);
          goToFpStep(3);
          showFpAlert('Code verified! Please create your new password.', 'success');
        } else {
          showFpAlert(data.message || 'Invalid or expired verification code.');
        }
      } catch (err) {
        showFpAlert('Connection error while verifying code.');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
      }
    }

    async function handleFpResetPassword(e) {
      e.preventDefault();
      clearFpAlert();
      const newPassword = document.getElementById('fpNewPassword').value;
      const confirmPassword = document.getElementById('fpConfirmPassword').value;

      if (newPassword.length < 6) {
        showFpAlert('Password must be at least 6 characters long.');
        return;
      }

      if (newPassword !== confirmPassword) {
        showFpAlert('Passwords do not match.');
        return;
      }

      const btn = document.getElementById('fpResetBtn');
      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = `<span>Updating Password...</span>`;

      try {
        const res = await fetch(getApiEndpoint('/api/forgot_password.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'reset_password',
            email: fpState.email,
            reset_token: fpState.resetToken,
            new_password: newPassword,
            confirm_password: confirmPassword
          })
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data.status === 'success') {
          showFpAlert('Password reset successfully! Redirecting to your dashboard...', 'success');
          btn.disabled = true;
          setTimeout(() => {
            window.location.href = data.redirect_url || 'dashboard';
          }, 1500);
        } else {
          showFpAlert(data.message || 'Unable to update password. Please start over.');
          btn.disabled = false;
          btn.innerHTML = originalText;
        }
      } catch (err) {
        showFpAlert('Connection error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = originalText;
      }
    }
  </script>
</body>
</html>
