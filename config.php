<?php
/**
 * MarketMoke - Production Environment & System Configuration
 * Security Protected: Direct web access is strictly forbidden.
 */

// 1. Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1.1 Direct Web Access Guard
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status'  => 'error',
        'code'    => 403,
        'message' => 'Direct execution of server configuration is strictly forbidden.'
    ]);
    exit();
}

// 1.1 Load environment variables from .env file if present
$envFilePath = __DIR__ . '/.env';
if (file_exists($envFilePath)) {
    $envLines = @file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($envKey, $envVal) = explode('=', $line, 2);
            $envKey = trim($envKey);
            $envVal = trim($envVal, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($envKey, $_ENV)) {
                $_ENV[$envKey] = $envVal;
                putenv("{$envKey}={$envVal}");
            }
        }
    }
}

// 2. Centralized Database Credentials & Connection Configuration (Default Local XAMPP Environment)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'marketplace_db-353033393571');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// 3. Application identity
define('APP_NAME', 'MarketMoke');

// 4. Centralized SMTP Server Configuration (Production Email Dispatch)
// Environment variables are the safe fallback; administrators may override
// these values through the protected site_settings record.
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 465));
define('SMTP_USER', getenv('SMTP_USER') ?: 'marketmoke@gmail.com');
// Credentials must be supplied by the ignored .env file or server environment.
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'tqzg iheu pgmc zqgq');
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'ssl'); // 'ssl', 'tls', or 'none'
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'marketmoke@gmail.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'MarketMoke Marketplace');
define('SMTP_DEBUG', false); // Set to true only in local debug environments

// 5. Application Security & Platform Settings

/**
 * Detect application base path (supports both domain root and subdirectories like /marketmoke)
 */
if (!function_exists('getAppBasePath')) {
    function getAppBasePath(): string {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptDir = preg_replace('~/(admin|user|api|includes)$~i', '', $scriptDir);
        return ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') ? '' : $scriptDir;
    }
}

if (!defined('APP_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = getAppBasePath();
    define('APP_URL', getenv('APP_URL') ?: ($protocol . '://' . $host . $basePath));
}
define('JWT_SECRET', getenv('JWT_SECRET') ?: '');
define('MASTER_ENCRYPTION_KEY', getenv('MASTER_ENCRYPTION_KEY') ?: '');
define('OTP_EXPIRY_MINUTES', 20);
define('PLATFORM_FEE_PERCENT', 10);

// 5. Global API & CORS Headers (Safe for AJAX from SPA)
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Send Standardized JSON Response and Terminate
 */
if (!function_exists('jsonResponse')) {
    function jsonResponse($data, int $statusCode = 200): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        // Keep API failures machine-readable as well as human-readable.  Older
        // callers use `message`; AJAX callers can consistently use `error`.
        if (is_array($data) && ($data['success'] ?? null) === false && empty($data['error'])) {
            $data['error'] = $data['message'] ?? 'Request failed.';
        }
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }
}

/**
 * Extract Bearer Token from HTTP Headers
 */
if (!function_exists('getBearerToken')) {
    function getBearerToken(): ?string {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }
        if (!empty($_COOKIE['marketmoke_session'])) {
            return trim($_COOKIE['marketmoke_session']);
        }
        return null;
    }
}

/**
 * Extract Client IP Safely Supporting Reverse Proxies
 */
if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',   // Standard proxy
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'             // Direct client
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ipList = explode(',', $_SERVER[$header]);
                $ip = trim($ipList[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }
}
