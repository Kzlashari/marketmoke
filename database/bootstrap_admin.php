<?php
/**
 * One-time CLI bootstrap for a new MarketMoke installation.
 *
 * Usage:
 *   php database/bootstrap_admin.php --name="Site Admin" --email="admin@example.com" --password="use-a-unique-password"
 *
 * It never replaces an existing administrator and never contains credentials.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This bootstrap script can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/config.php';

$options = getopt('', ['name:', 'email:', 'password:']);
$name = trim((string)($options['name'] ?? ''));
$email = strtolower(trim((string)($options['email'] ?? '')));
$password = (string)($options['password'] ?? '');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    exit("Usage: php database/bootstrap_admin.php --name=\"Site Admin\" --email=\"admin@example.com\" --password=\"a-unique-password-of-at-least-12-characters\"\n");
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $db = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($adminCount > 0) {
        exit("An administrator already exists. This one-time bootstrap will not create another account.\n");
    }
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status, created_at) VALUES (:name, :email, :password, 'admin', 'active', NOW())");
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    echo "Administrator created. Sign in with the email and password you supplied.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Unable to create the administrator: " . $error->getMessage() . "\n");
    exit(1);
}
