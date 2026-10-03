<?php
/**
 * MarketMoke - Logout Handler
 * Terminates user session and redirects to login.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session variables
$_SESSION = [];

// Destroy session cookie if present
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Clear cookies
setcookie('marketmoke_session', '', time() - 42000, '/');
setcookie('marketmoke_temp_auth', '', time() - 42000, '/');

// Destroy session
session_destroy();

// Redirect to login with logout status
header("Location: login.php?msg=logged_out");
exit();
