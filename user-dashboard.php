<?php
/**
 * MarketMoke - Legacy Route redirect / proxy for User Dashboard
 */
require_once __DIR__ . '/config.php';
$basePath = function_exists('getAppBasePath') ? getAppBasePath() : '';
header('Location: ' . rtrim($basePath, '/') . '/dashboard', true, 301);
exit();
