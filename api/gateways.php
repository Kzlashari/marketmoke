<?php
/**
 * MarketMoke - Payment Gateways Security & Cryptography API
 * Handles encrypted storage of PayPal credentials and Crypto wallet addresses.
 * 
 * Security Controls:
 * - Credentials encrypted at rest via AES-256-GCM (includes/crypto.php)
 * - Plaintext secrets are NEVER exposed in GET responses (strict DOM masking)
 * - State modifications require CSRF token validation and admin authorization
 * - Masked form submissions retain existing encrypted secrets without data loss
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/csrf.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance()->getConnection();

// Default baseline structure
$defaultGateways = [
    'paypal' => [
        'enabled'        => true,
        'merchant_email' => 'paypal-merchant@marketmoke.com',
        'client_id'      => 'AZD92k18x_MarketMoke_PayPal_ClientID_2026',
        'secret_key'     => 'EKp9831_Secret_Key_Verified',
        'mode'           => 'sandbox',
        'webhook_url'    => 'https://marketmoke.com/api/webhooks/paypal',
        'auto_escrow'    => true
    ],
    'crypto' => [
        'enabled'          => true,
        'usdt_trc20'       => 'TX9aK8mN2qL5vP8zR3wY7uX1sE4tG6yQe23',
        'usdt_erc20'       => '0x71C83921b7f08518bA4982c7a3F51a2d59C34982',
        'btc_address'      => 'bc1q9x2m48k5v9p2z7r3w1u8s4e0t2g6y8x85z',
        'eth_address'      => '0x3a2918B7f08518bA4982c7a3F51a2d59C3a2821c',
        'auto_hash_verify' => true
    ]
];

/**
 * Load raw encrypted gateway settings from database
 */
function loadEncryptedGateways(PDO $db, array $defaults): array {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'gateway_credentials' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['setting_value'])) {
            $saved = json_decode($row['setting_value'], true);
            if (is_array($saved)) {
                return array_replace_recursive($defaults, $saved);
            }
        }
    } catch (PDOException $e) {
        error_log("Database error reading gateway settings: " . $e->getMessage());
    }

    // If not yet persisted, encrypt defaults and return
    $encrypted = $defaults;
    $encrypted['paypal']['merchant_email'] = encryptSecret($defaults['paypal']['merchant_email']);
    $encrypted['paypal']['client_id']      = encryptSecret($defaults['paypal']['client_id']);
    $encrypted['paypal']['secret_key']     = encryptSecret($defaults['paypal']['secret_key']);
    $encrypted['crypto']['usdt_trc20']     = encryptSecret($defaults['crypto']['usdt_trc20']);
    $encrypted['crypto']['usdt_erc20']     = encryptSecret($defaults['crypto']['usdt_erc20']);
    $encrypted['crypto']['btc_address']    = encryptSecret($defaults['crypto']['btc_address']);
    $encrypted['crypto']['eth_address']    = encryptSecret($defaults['crypto']['eth_address']);
    return $encrypted;
}

/**
 * Save encrypted gateway settings to database
 */
function saveEncryptedGateways(PDO $db, array $encryptedData): bool {
    try {
        $json = json_encode($encryptedData, JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare("
            INSERT INTO site_settings (setting_group, setting_key, setting_value)
            VALUES ('gateways', 'gateway_credentials', :val)
            ON DUPLICATE KEY UPDATE setting_value = :val2, setting_group = 'gateways'
        ");
        return $stmt->execute([
            ':val'  => $json,
            ':val2' => $json
        ]);
    } catch (PDOException $e) {
        error_log("Database error saving gateway settings: " . $e->getMessage());
        return false;
    }
}

// --------------------------------------------------------------------------
// GET: Retrieve Gateway Settings (Strictly Masked for Client DOM)
// --------------------------------------------------------------------------
if ($method === 'GET') {
    $encryptedSettings = loadEncryptedGateways($db, $defaultGateways);
    $maskedOutput = $encryptedSettings;

    // Decrypt and apply strict DOM masking to sensitive keys
    $decryptAndMask = function($cipher, int $start = 4, int $end = 4) {
        if (empty($cipher)) return '';
        $plain = decryptSecret($cipher);
        if ($plain === null) {
            // Not encrypted or already plain
            $plain = (string)$cipher;
        }
        return maskSecret($plain, $start, $end);
    };

    // Mask PayPal credentials
    $maskedOutput['paypal']['merchant_email'] = $decryptAndMask($encryptedSettings['paypal']['merchant_email'] ?? '', 2, 4);
    $maskedOutput['paypal']['client_id']      = $decryptAndMask($encryptedSettings['paypal']['client_id'] ?? '', 4, 4);
    $maskedOutput['paypal']['secret_key']     = $decryptAndMask($encryptedSettings['paypal']['secret_key'] ?? '', 4, 4);

    // Mask Crypto wallet addresses
    $maskedOutput['crypto']['usdt_trc20']  = $decryptAndMask($encryptedSettings['crypto']['usdt_trc20'] ?? '', 4, 4);
    $maskedOutput['crypto']['usdt_erc20']  = $decryptAndMask($encryptedSettings['crypto']['usdt_erc20'] ?? '', 4, 4);
    $maskedOutput['crypto']['btc_address'] = $decryptAndMask($encryptedSettings['crypto']['btc_address'] ?? '', 4, 4);
    $maskedOutput['crypto']['eth_address'] = $decryptAndMask($encryptedSettings['crypto']['eth_address'] ?? '', 4, 4);

    // Add security metadata
    jsonResponse([
        'status'   => 'success',
        'security' => [
            'encryption'      => 'AES-256-GCM',
            'dom_masking'     => 'enabled',
            'csrf_protection' => 'enforced'
        ],
        'data'     => $maskedOutput
    ]);
}

// --------------------------------------------------------------------------
// POST: Update Gateway Settings with AES-256-GCM Encryption
// --------------------------------------------------------------------------
elseif ($method === 'POST') {
    // 1. Enforce Admin Authorization
    if (!hasRole('admin')) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 403,
            'message' => 'Unauthorized. Administrative privileges required to manage payment gateways.'
        ], 403);
    }

    // 2. Enforce CSRF Token Verification
    if (!verifyCsrfToken()) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 403,
            'message' => 'CSRF verification failed. Missing or invalid security token.'
        ], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $existing = loadEncryptedGateways($db, $defaultGateways);
    $updated = $existing;

    // Helper: update encrypted field if not masked
    $updateEncryptedField = function($incomingVal, $existingCipher) {
        if (!isset($incomingVal) || $incomingVal === '') {
            return $existingCipher;
        }
        $val = trim((string)$incomingVal);
        // If the submitted value is the masked placeholder, keep existing encrypted value
        if (isMaskedValue($val)) {
            return $existingCipher;
        }
        // New secret provided: Encrypt with AES-256-GCM
        return encryptSecret($val);
    };

    // Update PayPal settings
    if (isset($input['paypal']) && is_array($input['paypal'])) {
        $pp = $input['paypal'];
        $updated['paypal']['enabled']        = (bool)($pp['enabled'] ?? $existing['paypal']['enabled']);
        $updated['paypal']['mode']           = in_array($pp['mode'] ?? '', ['sandbox', 'live'], true) ? $pp['mode'] : 'sandbox';
        $updated['paypal']['webhook_url']    = trim($pp['webhook_url'] ?? $existing['paypal']['webhook_url']);
        $updated['paypal']['auto_escrow']    = (bool)($pp['auto_escrow'] ?? $existing['paypal']['auto_escrow']);
        $updated['paypal']['merchant_email'] = $updateEncryptedField($pp['merchant_email'] ?? '', $existing['paypal']['merchant_email']);
        $updated['paypal']['client_id']      = $updateEncryptedField($pp['client_id'] ?? '', $existing['paypal']['client_id']);
        $updated['paypal']['secret_key']     = $updateEncryptedField($pp['secret_key'] ?? '', $existing['paypal']['secret_key']);
    }

    // Update Crypto settings
    if (isset($input['crypto']) && is_array($input['crypto'])) {
        $cr = $input['crypto'];
        $updated['crypto']['enabled']          = (bool)($cr['enabled'] ?? $existing['crypto']['enabled']);
        $updated['crypto']['auto_hash_verify'] = (bool)($cr['auto_hash_verify'] ?? $existing['crypto']['auto_hash_verify']);
        $updated['crypto']['usdt_trc20']       = $updateEncryptedField($cr['usdt_trc20'] ?? '', $existing['crypto']['usdt_trc20']);
        $updated['crypto']['usdt_erc20']       = $updateEncryptedField($cr['usdt_erc20'] ?? '', $existing['crypto']['usdt_erc20']);
        $updated['crypto']['btc_address']      = $updateEncryptedField($cr['btc_address'] ?? '', $existing['crypto']['btc_address']);
        $updated['crypto']['eth_address']      = $updateEncryptedField($cr['eth_address'] ?? '', $existing['crypto']['eth_address']);
    }

    // Persist encrypted payload
    $saved = saveEncryptedGateways($db, $updated);
    if (!$saved) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 500,
            'message' => 'Failed to persist encrypted gateway credentials to database.'
        ], 500);
    }

    // Prepare masked response for UI confirmation
    $maskedConfirmed = $updated;
    $maskedConfirmed['paypal']['merchant_email'] = maskSecret(decryptSecret($updated['paypal']['merchant_email']) ?: '', 2, 4);
    $maskedConfirmed['paypal']['client_id']      = maskSecret(decryptSecret($updated['paypal']['client_id']) ?: '', 4, 4);
    $maskedConfirmed['paypal']['secret_key']     = maskSecret(decryptSecret($updated['paypal']['secret_key']) ?: '', 4, 4);
    $maskedConfirmed['crypto']['usdt_trc20']     = maskSecret(decryptSecret($updated['crypto']['usdt_trc20']) ?: '', 4, 4);
    $maskedConfirmed['crypto']['usdt_erc20']     = maskSecret(decryptSecret($updated['crypto']['usdt_erc20']) ?: '', 4, 4);
    $maskedConfirmed['crypto']['btc_address']    = maskSecret(decryptSecret($updated['crypto']['btc_address']) ?: '', 4, 4);
    $maskedConfirmed['crypto']['eth_address']    = maskSecret(decryptSecret($updated['crypto']['eth_address']) ?: '', 4, 4);

    jsonResponse([
        'status'  => 'success',
        'message' => 'Payment Gateway credentials encrypted via AES-256-GCM and saved successfully!',
        'data'    => $maskedConfirmed
    ]);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}
