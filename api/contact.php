<?php
/**
 * MarketMoke - Contact Form API with PHPMailer & Database Storage
 * Saves inquiries to database and sends notification to admin recipient & auto-reply to sender
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$phone = trim($input['phone'] ?? '');
$subject = trim($input['subject'] ?? 'General Inquiry');
$message = trim($input['message'] ?? '');

if (empty($name) || empty($email) || empty($subject) || empty($message)) {
    jsonResponse(['status' => 'error', 'message' => 'Name, Email, Subject, and Message are required fields.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['status' => 'error', 'message' => 'Please provide a valid email address.'], 400);
}

$db = Database::getInstance()->getConnection();

// Ensure contact_inquiries table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `contact_inquiries` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(150) NOT NULL,
      `email` VARCHAR(150) NOT NULL,
      `phone` VARCHAR(50) DEFAULT NULL,
      `subject` VARCHAR(255) DEFAULT NULL,
      `message` TEXT NOT NULL,
      `status` ENUM('new', 'in_progress', 'resolved') DEFAULT 'new',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stmt = $db->prepare("INSERT INTO `contact_inquiries` (`name`, `email`, `phone`, `subject`, `message`) 
        VALUES (:name, :email, :phone, :subject, :message)");
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':phone' => $phone,
        ':subject' => $subject,
        ':message' => $message
    ]);
    $inquiryId = (int)$db->lastInsertId();
} catch (Exception $e) {
    error_log("[MarketMoke Contact DB Error] " . $e->getMessage());
    $inquiryId = 0;
}

$contactCfg = MarketMokeMailer::getContactConfig();
$adminEmail = $contactCfg['recipient_email'] ?: 'info@marketmoke.com';

// Send notification to Admin via PHPMailer
$mailSent = false;
$mailError = null;
try {
    $mailSent = MarketMokeMailer::sendContactInquiryNotification([
        'id' => $inquiryId,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'subject' => $subject,
        'message' => $message
    ]);
} catch (Exception $e) {
    $mailError = $e->getMessage();
    error_log("[MarketMoke Contact Mail Error] " . $e->getMessage());
}

if (!$mailSent) {
    if ($mailError) {
        error_log("[MarketMoke Contact Mail Failure] " . $mailError);
    }
    // If mail sending failed, log server side and notify user with friendly error
    // Note: If inquiry was saved to DB, keep the inquiry record intact
    jsonResponse([
        'status' => 'error',
        'message' => "We couldn't send your message right now. Please try again later."
    ], 500);
}

// Send auto-reply to user via PHPMailer if enabled
try {
    MarketMokeMailer::sendContactAutoReply($email, $name);
} catch (Exception $e) {
    // Ignore auto-reply error
}

jsonResponse([
    'status' => 'success',
    'message' => 'Your message has been sent successfully. We will get back to you soon.',
    'mail_dispatched' => true,
    'data' => [
        'inquiry_id' => $inquiryId,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'subject' => $subject,
        'date' => date('Y-m-d H:i:s')
    ]
]);
