<?php
/**
 * MarketMoke - CSRF Protection Helper
 * Implements cryptographically secure Cross-Site Request Forgery defenses.
 * Generates high-entropy tokens and validates state-changing requests using
 * timing-attack resistant comparisons via hash_equals().
 */

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate a new cryptographically secure CSRF token and store it in session
 * 
 * @return string 64-character hexadecimal CSRF token
 */
function generateCsrfToken(): string {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    return $token;
}

/**
 * Get current active CSRF token, or create one if none exists or has expired
 * 
 * @param int $ttlSeconds Maximum validity in seconds (default: 86400 / 24 hours)
 * @return string Active CSRF token
 */
function getCsrfToken(int $ttlSeconds = 86400): string {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
        return generateCsrfToken();
    }

    // Check token expiration
    if ((time() - (int)$_SESSION['csrf_token_time']) > $ttlSeconds) {
        return generateCsrfToken();
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from request header, POST body, or JSON payload
 * Uses timing-attack resistant hash_equals()
 * 
 * @param string|null $providedToken Optional token string. If null, automatically inspects request
 * @return bool True if valid, false otherwise
 */
function verifyCsrfToken(?string $providedToken = null): bool {
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (empty($sessionToken) || !is_string($sessionToken)) {
        return false;
    }

    if ($providedToken === null) {
        // 1. Inspect HTTP header (X-CSRF-Token or X-CSRF-TOKEN)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headerToken = $headers['X-CSRF-Token'] ?? $headers['X-Csrf-Token'] ?? $headers['x-csrf-token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        if (!empty($headerToken)) {
            $providedToken = trim((string)$headerToken);
        }

        // 2. Inspect standard POST body
        if (empty($providedToken) && !empty($_POST['csrf_token'])) {
            $providedToken = trim((string)$_POST['csrf_token']);
        }

        // 3. Inspect JSON body if content type is application/json
        if (empty($providedToken)) {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded) && !empty($decoded['csrf_token'])) {
                    $providedToken = trim((string)$decoded['csrf_token']);
                }
            }
        }
    }

    if (empty($providedToken) || !is_string($providedToken)) {
        return false;
    }

    return hash_equals($sessionToken, $providedToken);
}

/**
 * Enforce CSRF protection on the current request
 * Automatically halts execution with HTTP 403 if token validation fails.
 * 
 * @return void
 */
function requireCsrf(): void {
    // Exclude safe idempotent read methods
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
        return;
    }

    if (!verifyCsrfToken()) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 403,
            'message' => 'CSRF verification failed: Missing or invalid security token. Please refresh the page and try again.'
        ], 403);
    }
}

/**
 * Regenerate and rotate the active session CSRF token
 * Recommended after sensitive operations (such as login or password reset).
 * 
 * @return string New CSRF token
 */
function regenerateCsrfToken(): string {
    return generateCsrfToken();
}
