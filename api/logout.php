<?php
/**
 * Logout API Endpoint
 * Destroys current user session and clears authentication cookies
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Unset all session variables
$_SESSION = array();

// Delete the session cookie if present
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

// Destroy the session
session_destroy();

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'success' => true,
    'message' => 'Logged out successfully'
]);
exit;
