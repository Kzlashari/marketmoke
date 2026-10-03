<?php
/**
 * MarketMoke - Session & Access Control Helper
 * Included in target structure: includes/auth.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if the user is authenticated and optionally possesses the required role(s)
 * 
 * @param array|string $allowedRoles Optional role or list of allowed roles ('admin', 'publisher', 'user')
 * @return array User session data
 */
function checkAuth($allowedRoles = []) {
    // 1. Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        header("Location: /login.php?msg=auth_required");
        exit();
    }

    // Standardize allowed roles to array
    if (is_string($allowedRoles) && !empty($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    $currentRole = $_SESSION['user_role'] ?? 'user';

    // 2. Check role authorization if roles are specified
    if (!empty($allowedRoles) && !in_array($currentRole, $allowedRoles)) {
        // Redirect to the user's rightful dashboard or display access denied
        switch ($currentRole) {
            case 'admin':
                header("Location: /admin/overview?error=unauthorized_role");
                exit();
            case 'publisher':
                header("Location: /publisher-dashboard.php?error=unauthorized_role");
                exit();
            case 'author':
            case 'editor':
                header("Location: /author-dashboard.php?error=unauthorized_role");
                exit();
            case 'user':
            default:
                header("Location: /user-dashboard.php?error=unauthorized_role");
                exit();
        }
    }

    return [
        'id' => $_SESSION['user_id'],
        'role' => $_SESSION['user_role'] ?? 'user',
        'name' => $_SESSION['user_name'] ?? 'User',
        'email' => $_SESSION['user_email'] ?? ''
    ];
}

/**
 * Check if a user is currently logged in
 */
function isLoggedIn() {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        return true;
    }
    // Also check Bearer token or session cookie if available
    $token = getBearerToken();
    if (!empty($token)) {
        $user = validateSessionToken($token);
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            return true;
        }
    }
    return false;
}

/**
 * Extract Bearer token from HTTP Authorization header or cookie
 */
if (!function_exists('getBearerToken')) {
    function getBearerToken() {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
        if (!empty($_COOKIE['marketmoke_session'])) {
            return trim($_COOKIE['marketmoke_session']);
        }
        return null;
    }
}

/**
 * Validate session token against database if session table exists
 */
function validateSessionToken($token) {
    if (empty($token)) return null;
    try {
        if (class_exists('Database')) {
            $db = Database::getInstance()->getConnection();
            if ($db) {
                $stmt = $db->prepare("SELECT u.id, u.role, u.name, u.email, u.status 
                    FROM user_sessions s 
                    JOIN users u ON s.user_id = u.id 
                    WHERE s.session_token = :token AND s.expires_at > NOW() AND u.status = 'active' 
                    LIMIT 1");
                $stmt->execute([':token' => $token]);
                return $stmt->fetch();
            }
        }
    } catch (Exception $e) {}
    return null;
}

/**
 * Check if current user has a specific role
 */
function hasRole($role) {
    if (isLoggedIn()) {
        $currentRole = $_SESSION['user_role'] ?? 'user';
        return ($currentRole === $role) || ($currentRole === 'admin');
    }
    return false;
}

/**
 * Get current authenticated user session data or null
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'role' => $_SESSION['user_role'],
        'name' => $_SESSION['user_name'],
        'email' => $_SESSION['user_email']
    ];
}
