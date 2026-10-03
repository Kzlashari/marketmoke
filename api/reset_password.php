<?php
/**
 * MarketMoke - Direct Reset Password API Endpoint
 */
$_POST['action'] = 'reset_password';
$rawInput = file_get_contents('php://input');
$decoded = json_decode($rawInput, true);
if (is_array($decoded)) {
    $decoded['action'] = 'reset_password';
    // Repack for forgot_password.php
    $GLOBALS['_FP_OVERRIDE_INPUT'] = $decoded;
}
require_once __DIR__ . '/forgot_password.php';
