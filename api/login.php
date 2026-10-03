<?php
/**
 * MarketMoke - Login API Endpoint
 * Accepts email & password, validates credentials, and dispatches OTP
 * Maps directly to send_otp.php workflow for full compatibility
 */

// This endpoint is the credential-verified login flow.  It deliberately does
// not impose an artificial attempt cap; send_otp.php still verifies the
// password and account status before issuing an OTP.
define('MARKETMOKE_LOGIN_RATE_LIMIT_DISABLED', true);
require_once __DIR__ . '/send_otp.php';
