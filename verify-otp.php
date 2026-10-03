<?php
/**
 * MarketMoke - OTP Verification Handler
 * Step 2 & Step 3: Validate 6-digit OTP from MySQL, mark as used, establish session, and role route
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/rate_limiter.php';

// Check if temp user session is present
if (!isset($_SESSION['temp_user_id']) || empty($_SESSION['temp_user_id'])) {
    header("Location: login.php?msg=auth_required");
    exit();
}

$tempUserId    = (int)$_SESSION['temp_user_id'];
$tempUserEmail = $_SESSION['temp_user_email'] ?? '';
$tempUserName  = $_SESSION['temp_user_name'] ?? 'User';

$error = '';
$success = '';

// Handle Resend OTP Request
if (isset($_GET['action']) && $_GET['action'] === 'resend') {
    // Enforce 60-second resend cooldown
    $lastResend = (int)($_SESSION['last_otp_resend_time'] ?? 0);
    $cooldownRemaining = 60 - (time() - $lastResend);

    if ($cooldownRemaining > 0) {
        $error = "Please wait {$cooldownRemaining} seconds before requesting a new verification code.";
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Fetch user from database
            $uStmt = $db->prepare("SELECT id, name, email, status FROM users WHERE id = :id LIMIT 1");
            $uStmt->execute([':id' => $tempUserId]);
            $userObj = $uStmt->fetch();

            if ($userObj && $userObj['status'] === 'active') {
                // Invalidate old unused OTPs
                $invStmt = $db->prepare("UPDATE user_otps SET is_used = 1 WHERE email = :email AND is_used = 0");
                $invStmt->execute([':email' => $userObj['email']]);

                // Generate new 6-digit OTP
                $newOtp = sprintf("%06d", random_int(100000, 999999));
                $hashedOtp = password_hash($newOtp, PASSWORD_DEFAULT);
                $expiryMinutes = defined('OTP_EXPIRY_MINUTES') ? (int)OTP_EXPIRY_MINUTES : 20;

                $insStmt = $db->prepare("INSERT INTO user_otps (user_id, email, otp_code, attempts, expires_at, is_used, created_at) 
                    VALUES (:user_id, :email, :otp_code, 0, DATE_ADD(NOW(), INTERVAL {$expiryMinutes} MINUTE), 0, NOW())");
                $insStmt->execute([
                    ':user_id'  => $tempUserId,
                    ':email'    => $userObj['email'],
                    ':otp_code' => $hashedOtp
                ]);
                $insertedId = (int)$db->lastInsertId();

                // Dispatch via MarketMokeMailer
                $mailSent = false;
                try {
                    $mailSent = MarketMokeMailer::sendOtpEmail($userObj['email'], $userObj['name'], $newOtp);
                } catch (Exception $e) {
                    error_log("[MarketMoke OTP Resend Mailer Error] " . $e->getMessage());
                    $mailSent = false;
                }

                if (!$mailSent) {
                    // Fail safely: invalidate newly generated OTP
                    if ($insertedId > 0) {
                        $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $insertedId]);
                    }
                    $error = "Unable to send verification code. Please try again.";
                } else {
                    $_SESSION['last_otp_resend_time'] = time();
                    $success = "A fresh 6-digit verification code has been dispatched to your email.";
                }
            } else {
                $error = "Account is inactive. Please contact customer support.";
            }
        } catch (Exception $e) {
            error_log("[MarketMoke OTP Resend DB Error] " . $e->getMessage());
            $error = "Failed to resend code due to a system error. Please try again.";
        }
    }
}

// Handle OTP Verification Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedOtp = '';
    if (isset($_POST['otp_code'])) {
        $submittedOtp = trim($_POST['otp_code']);
    } elseif (isset($_POST['otp_digit']) && is_array($_POST['otp_digit'])) {
        $submittedOtp = implode('', $_POST['otp_digit']);
    }

    // Clean input
    $submittedOtp = preg_replace('/[^0-9]/', '', $submittedOtp);

    if (strlen($submittedOtp) !== 6) {
        $error = "Please enter the complete 6-digit OTP verification code.";
    } else {
        try {
            $db = Database::getInstance()->getConnection();

            // Fetch latest active unused OTP for this user
            $stmt = $db->prepare("
                SELECT id, user_id, email, otp_code, attempts, expires_at, is_used,
                       TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_left
                FROM user_otps
                WHERE (user_id = :user_id OR email = :email)
                  AND is_used = 0
                ORDER BY id DESC
                LIMIT 1
            ");

            $stmt->execute([
                ':user_id' => $tempUserId,
                ':email'   => $tempUserEmail
            ]);

            $otpRecord = $stmt->fetch();

            if (!$otpRecord) {
                $error = "No active verification code found. Please request a new code.";
            } elseif ((int)$otpRecord['seconds_left'] <= 0) {
                // Expired: invalidate
                $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $otpRecord['id']]);
                $error = "Verification code has expired. Please request a new code.";
            } elseif ((int)$otpRecord['attempts'] >= 5) {
                // Brute force lockout: invalidate
                $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $otpRecord['id']]);
                $error = "Too many incorrect attempts. Please request a new verification code.";
            } else {
                // SECURE BCRYPT VERIFICATION - NO PLAINTEXT FALLBACK
                $isValid = password_verify($submittedOtp, $otpRecord['otp_code']);

                if (!$isValid) {
                    $newAttempts = (int)$otpRecord['attempts'] + 1;
                    $db->prepare("UPDATE user_otps SET attempts = :att WHERE id = :id")->execute([
                        ':att' => $newAttempts,
                        ':id'  => $otpRecord['id']
                    ]);
                    $remaining = max(0, 5 - $newAttempts);
                    if ($remaining > 0) {
                        $error = "Invalid verification code. ({$remaining} attempt(s) remaining)";
                    } else {
                        $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $otpRecord['id']]);
                        $error = "Too many incorrect attempts. Please request a new verification code.";
                    }
                } else {
                    // Mark OTP as used immediately (Single-use enforcement)
                    $db->prepare("UPDATE user_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $otpRecord['id']]);
                    $db->prepare("UPDATE user_otps SET is_used = 1 WHERE email = :email AND is_used = 0")->execute([':email' => $otpRecord['email']]);

                    // Fetch user details for session and role routing
                    $uStmt = $db->prepare("SELECT id, name, email, role, status FROM users WHERE id = :id LIMIT 1");
                    $uStmt->execute([':id' => $tempUserId]);
                    $user = $uStmt->fetch();

                    if (!$user || $user['status'] !== 'active') {
                        $error = "Account is inactive or pending approval. Contact support.";
                    } else {
                        // Destroy temporary session variables
                        unset($_SESSION['temp_user_id']);
                        unset($_SESSION['temp_user_email']);
                        unset($_SESSION['temp_user_name']);
                        unset($_SESSION['temp_auth_time']);
                        unset($_SESSION['temp_auth_expires']);

                        // Prevent session fixation
                        session_regenerate_id(true);

                        // Establish full authenticated user session
                        $_SESSION['user_id']          = (int)$user['id'];
                        $_SESSION['user_role']        = $user['role'];
                        $_SESSION['user_name']        = $user['name'];
                        $_SESSION['user_email']       = $user['email'];
                        $_SESSION['authenticated_at'] = time();

                        // Dynamic role-based redirection
                        $role = strtolower($user['role'] ?? 'user');
                        switch ($role) {
                            case 'admin':
                                header("Location: /admin/overview");
                                break;
                            case 'publisher':
                                header("Location: publisher-dashboard.php");
                                break;
                            case 'author':
                            case 'editor':
                                header("Location: author-dashboard.php");
                                break;
                            case 'user':
                            default:
                                header("Location: user-dashboard.php");
                                break;
                        }
                        exit();
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[MarketMoke OTP Verification Error] " . $e->getMessage());
            $error = "Verification failed due to a system error. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MarketMoke - Verify OTP Code</title>
  <meta name="description" content="Enter your 6-digit one-time password to complete authentication.">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@600;700;800&display=swap" rel="stylesheet">
  
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  
  <style>
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .font-mono {
      font-family: 'JetBrains Mono', monospace;
    }
    .bg-analytics-pattern {
      background-color: #0b1329;
      background-image: 
        radial-gradient(at 10% 20%, rgba(30, 58, 138, 0.45) 0px, transparent 50%),
        radial-gradient(at 90% 15%, rgba(67, 56, 202, 0.4) 0px, transparent 50%),
        radial-gradient(at 80% 85%, rgba(14, 116, 144, 0.35) 0px, transparent 50%),
        radial-gradient(at 20% 80%, rgba(30, 27, 75, 0.5) 0px, transparent 50%);
    }
  </style>
</head>
<body class="min-h-screen bg-analytics-pattern text-slate-800 flex flex-col justify-between relative overflow-x-hidden antialiased selection:bg-blue-600 selection:text-white">

  <!-- Header -->
  <header class="relative z-10 w-full max-w-7xl mx-auto px-4 py-6 flex items-center justify-between">
    <a href="index.html" class="flex items-center gap-2.5 text-white group">
      <div class="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center font-black text-xl shadow-lg">
        M
      </div>
      <div class="leading-none">
        <span class="text-xl font-black tracking-tight text-white">Market<span class="text-blue-400">Moke</span></span>
        <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Two-Factor Security</span>
      </div>
    </a>

    <div>
      <a href="logout.php" class="text-xs font-bold text-slate-300 hover:text-white px-3 py-1.5 rounded-lg border border-slate-700 bg-slate-800/60 transition-all">
        Cancel / Log Out
      </a>
    </div>
  </header>

  <!-- Verification Card -->
  <main class="relative z-10 flex-1 flex items-center justify-center px-4 py-8">
    <div class="w-full max-w-[460px] bg-white rounded-[28px] shadow-[0_25px_60px_-15px_rgba(0,0,0,0.4)] border border-slate-100 p-8 sm:p-10 transition-all">
      
      <!-- Icon & Title -->
      <div class="text-center space-y-2 mb-6">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 border border-blue-100 mx-auto shadow-xs">
          <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
        </div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Enter Verification Code</h1>
        <p class="text-xs text-slate-500 max-w-xs mx-auto">
          We sent a 6-digit one-time code to <br>
          <strong class="text-slate-800 font-bold"><?= htmlspecialchars($tempUserEmail) ?></strong>
        </p>
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

      <!-- Verification Form -->
      <form action="verify-otp.php" method="POST" id="otpForm" class="space-y-6">
        
        <!-- 6 Digit OTP Input Boxes -->
        <div>
          <label class="block text-center text-xs font-bold text-slate-700 mb-3 uppercase tracking-wider">
            6-Digit Security Token
          </label>
          <div class="flex items-center justify-between gap-2 sm:gap-2.5">
            <?php for ($i = 0; $i < 6; $i++): ?>
              <input 
                type="text" 
                name="otp_digit[]" 
                maxlength="1" 
                pattern="[0-9]" 
                inputmode="numeric" 
                autocomplete="one-time-code"
                required
                class="otp-box w-12 h-14 text-center font-mono font-black text-2xl bg-slate-50 border border-slate-300 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent text-slate-900 transition-all shadow-xs"
                id="digit-<?= $i ?>"
                <?= $i === 0 ? 'autofocus' : '' ?>
              />
            <?php endfor; ?>
          </div>
          <input type="hidden" name="otp_code" id="fullOtpCode" />
        </div>

        <!-- 10 Minute Validity Notice & Countdown -->
        <div class="flex items-center justify-between text-xs text-slate-500 px-1 font-medium">
          <span class="flex items-center gap-1.5 text-slate-600">
            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Expires in: <strong id="timerDisplay" class="text-slate-800 font-mono">10:00</strong>
          </span>
          <a href="verify-otp.php?action=resend" class="text-blue-600 hover:text-blue-800 font-bold hover:underline">
            Resend OTP
          </a>
        </div>

        <!-- Verify Button -->
        <button 
          type="submit" 
          id="verifyBtn"
          class="w-full py-3.5 px-4 bg-[#1b3a6b] hover:bg-[#142d54] active:bg-[#0f213f] text-white font-black text-sm rounded-xl shadow-md hover:shadow-lg transition-all flex items-center justify-center gap-2">
          <span>Verify & Continue</span>
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        </button>

      </form>

      <!-- Back / Log Out -->
      <div class="mt-6 text-center text-xs font-semibold text-slate-500 pt-4 border-t border-slate-100">
        Wrong email? <a href="logout.php" class="text-blue-600 font-bold hover:underline">Return to Login</a>
      </div>

    </div>
  </main>

  <!-- Footer -->
  <footer class="relative z-10 w-full py-5 text-center text-xs font-medium text-slate-400">
    <span>&copy; <?= date('Y') ?> MarketMoke SEO Solutions. All rights reserved.</span>
  </footer>

  <script>
    // Handle Auto-Focus and Auto-Advance across 6 OTP inputs
    const inputs = document.querySelectorAll('.otp-box');
    const fullOtpInput = document.getElementById('fullOtpCode');
    const form = document.getElementById('otpForm');

    inputs.forEach((input, index) => {
      input.addEventListener('input', (e) => {
        const val = e.target.value.replace(/[^0-9]/g, '');
        e.target.value = val;
        
        if (val && index < inputs.length - 1) {
          inputs[index + 1].focus();
        }
        updateFullCode();
      });

      input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !e.target.value && index > 0) {
          inputs[index - 1].focus();
        }
      });

      input.addEventListener('paste', (e) => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').trim().replace(/[^0-9]/g, '');
        if (pasted.length === 6) {
          pasted.split('').forEach((char, i) => {
            if (inputs[i]) inputs[i].value = char;
          });
          inputs[5].focus();
          updateFullCode();
        }
      });
    });

    function updateFullCode() {
      let code = '';
      inputs.forEach(inp => code += inp.value);
      fullOtpInput.value = code;
      if (code.length === 6) {
        // Optional auto submit when 6th digit entered
      }
    }

    form.addEventListener('submit', () => {
      updateFullCode();
    });

    // 10 Minute Countdown Timer
    let duration = 600;
    const timerDisplay = document.getElementById('timerDisplay');
    const countdown = setInterval(() => {
      duration--;
      if (duration <= 0) {
        clearInterval(countdown);
        timerDisplay.textContent = '00:00 (Expired)';
        timerDisplay.classList.add('text-red-600');
      } else {
        const mins = String(Math.floor(duration / 60)).padStart(2, '0');
        const secs = String(duration % 60).padStart(2, '0');
        timerDisplay.textContent = `${mins}:${secs}`;
      }
    }, 1000);
  </script>
</body>
</html>
