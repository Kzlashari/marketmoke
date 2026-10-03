<?php
/**
 * MarketMoke - Unified Dashboard Dispatcher
 * Serves a single, unified URL (/dashboard) for all authenticated roles.
 * Dispatches to role-specific dashboards based on active session.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_check.php';

$basePath = function_exists('getAppBasePath') ? rtrim(getAppBasePath(), '/') : '';

// 1. Guest access protection: redirect to login if not authenticated
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ' . ($basePath ? $basePath : '') . '/login.php?msg=auth_required');
    exit();
}

// 2. Resolve authenticated user's role
$role = strtolower($_SESSION['user_role'] ?? 'user');

// 3. Delegate to the corresponding role dashboard under the unified /dashboard URL
switch ($role) {
    case 'admin':
        header('Location: ' . ($basePath ? $basePath : '') . '/admin/overview');
        exit();

    case 'author':
    case 'editor':
        require_once __DIR__ . '/author-dashboard.php';
        break;

    case 'publisher':
        require_once __DIR__ . '/publisher-dashboard.php';
        break;

    case 'user':
    default:
        require_once __DIR__ . '/user/dashboard.php';
        break;
}
exit();
