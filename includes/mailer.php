<?php
/**
 * MarketMoke - PHPMailer Integration Wrapper
 * Official PHPMailer 6.x implementation for OTP authentication and Contact inquiries
 * https://github.com/PHPMailer/PHPMailer
 */

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class MarketMokeMailer {

    /**
     * Retrieve SMTP configuration from database or fallback defaults
     */
    public static function getSmtpConfig() {
        $defaultConfig = [
            'host' => defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com',
            'port' => defined('SMTP_PORT') ? (int)SMTP_PORT : 587,
            'encryption' => defined('SMTP_ENCRYPTION') ? strtolower(SMTP_ENCRYPTION) : 'tls', // 'tls', 'ssl', or 'none'
            'username' => defined('SMTP_USER') ? SMTP_USER : 'marketmoke@gmail.com',
            'password' => defined('SMTP_PASS') ? SMTP_PASS : '', // Google App Password or SMTP password
            'from_email' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'marketmoke@gmail.com',
            'from_name' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'MarketMoke Marketplace',
            'otp_subject' => 'Your MarketMoke One-Time Login Code (OTP)'
        ];

        try {
            $db = Database::getInstance()->getConnection();
            // Check primary site_settings table first
            $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'smtp_config' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row && !empty($row['setting_value'])) {
                $saved = is_array($row['setting_value']) ? $row['setting_value'] : json_decode($row['setting_value'], true);
                if (is_array($saved)) {
                    return array_merge($defaultConfig, array_filter($saved, function($val) { return $val !== '' && $val !== null; }));
                }
            }

            // Fallback to system_settings if present
            $stmt2 = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_config' LIMIT 1");
            $stmt2->execute();
            $row2 = $stmt2->fetch();
            if ($row2 && !empty($row2['setting_value'])) {
                $saved2 = json_decode($row2['setting_value'], true);
                if (is_array($saved2)) {
                    return array_merge($defaultConfig, array_filter($saved2, function($val) { return $val !== '' && $val !== null; }));
                }
            }
        } catch (\Throwable $e) {
            // Fall back to environment configuration when optional settings
            // tables have not been created or cannot be queried.
        }

        return $defaultConfig;
    }

    /**
     * Retrieve Contact Form configuration from database or fallback defaults
     */
    public static function getContactConfig() {
        $defaultConfig = [
            'recipient_email' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'info@marketmoke.com',
            'support_phone' => '+44 7737 133124',
            'support_address' => 'Office 871, 85 Dunstall Hill, Wolverhampton, United Kingdom, WV6 0SR',
            'auto_reply_enabled' => true,
            'subject_prefix' => '[MarketMoke Contact Inquiry]'
        ];

        try {
            $db = Database::getInstance()->getConnection();
            // Check site_settings table
            $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'contact_config' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row && !empty($row['setting_value'])) {
                $saved = is_array($row['setting_value']) ? $row['setting_value'] : json_decode($row['setting_value'], true);
                if (is_array($saved)) {
                    return array_merge($defaultConfig, array_filter($saved, function($val) { return $val !== '' && $val !== null; }));
                }
            }

            // Also check general_settings for contact email
            $stmtGen = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'general_settings' LIMIT 1");
            $stmtGen->execute();
            $rowGen = $stmtGen->fetch();
            if ($rowGen && !empty($rowGen['setting_value'])) {
                $gen = is_array($rowGen['setting_value']) ? $rowGen['setting_value'] : json_decode($rowGen['setting_value'], true);
                if (is_array($gen) && !empty($gen['contactEmail'])) {
                    $defaultConfig['recipient_email'] = $gen['contactEmail'];
                }
            }
        } catch (\Throwable $e) {
            // Contact settings are optional; keep the mailer usable when the
            // settings tables are unavailable.
        }

        return $defaultConfig;
    }

    /**
     * Create configured PHPMailer instance
     */
    public static function createMailer($customConfig = null) {
        $cfg = $customConfig ?: self::getSmtpConfig();
        $mail = new PHPMailer(true);

        try {
            $mail->Timeout = 5;
            if (empty($cfg['password']) && !empty($cfg['username'])) {
                throw new Exception("SMTP password is not configured.");
            }
            $mail->isSMTP();
            $mail->Host = $cfg['host'] ?: 'smtp.gmail.com';
            $mail->SMTPAuth = !empty($cfg['username']);
            $mail->Username = $cfg['username'];
            $mail->Password = $cfg['password'];

            $enc = strtolower($cfg['encryption'] ?? 'tls');
            if ($enc === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->Port = (int)($cfg['port'] ?: 587);
            $mail->CharSet = 'UTF-8';

            $fromEmail = !empty($cfg['from_email']) ? $cfg['from_email'] : $cfg['username'];
            $fromName = !empty($cfg['from_name']) ? $cfg['from_name'] : 'MarketMoke Marketplace';
            $mail->setFrom($fromEmail, $fromName);
            $mail->isHTML(true);

            return $mail;
        } catch (Exception $e) {
            throw new Exception("PHPMailer Initialization Error: " . $e->getMessage());
        }
    }

    /**
     * Send 6-digit OTP email to logging in user
     */
    public static function sendOtpEmail($toEmail, $toName, $otpCode) {
        $cfg = self::getSmtpConfig();

        $mail = self::createMailer($cfg);
        $mail->addAddress($toEmail, $toName ?: 'MarketMoke User');
        $mail->Subject = $cfg['otp_subject'] ?: 'Your MarketMoke One-Time Login Code (OTP)';

        $htmlBody = '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; max-width: 540px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
            <div style="background-color: #0f172a; padding: 28px 32px; text-align: center;">
                <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">Market<span style="color: #3b82f6;">Moke</span></h1>
                <p style="color: #94a3b8; font-size: 12px; margin: 6px 0 0; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Secure Identity Authentication</p>
            </div>
            <div style="padding: 32px; color: #334155;">
                <p style="font-size: 15px; line-height: 1.6; margin: 0 0 16px;">Hello <strong>' . htmlspecialchars($toName) . '</strong>,</p>
                <p style="font-size: 14px; line-height: 1.6; margin: 0 0 24px; color: #475569;">
                    A login attempt was initiated for your MarketMoke account. Please use the following One-Time Password (OTP) to complete your authentication:
                </p>
                <div style="background-color: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px; text-align: center; margin: 0 0 24px;">
                    <span style="font-size: 34px; font-weight: 900; letter-spacing: 8px; color: #1e293b; font-family: monospace;">' . htmlspecialchars($otpCode) . '</span>
                    <p style="font-size: 11px; color: #64748b; margin: 8px 0 0; font-weight: 600;">⏰ This verification code will expire in 10 minutes.</p>
                </div>
                <p style="font-size: 13px; line-height: 1.5; color: #64748b; margin: 0 0 16px;">
                    <strong>Security Notice:</strong> Never share this code with anyone. MarketMoke representatives will never ask for your OTP. If you did not request this login, please secure your password immediately.
                </p>
            </div>
            <div style="background-color: #f1f5f9; padding: 16px 32px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b;">
                © ' . date('Y') . ' MarketMoke Guest Post Marketplace LLC. All rights reserved.
            </div>
        </div>';

        $mail->Body = $htmlBody;
        $mail->AltBody = "Hello " . $toName . ",\n\nYour MarketMoke login OTP is: " . $otpCode . "\nThis code will expire in 10 minutes.\nNever share this code with anyone.";

        return $mail->send();
    }

    /**
     * Send contact form inquiry notification to admin recipient
     */
    public static function sendContactInquiryNotification($data) {
        $contactCfg = self::getContactConfig();
        $recipient = $contactCfg['recipient_email'] ?: 'marketmoke@gmail.com';

        $mail = self::createMailer();
        $mail->addAddress($recipient, 'MarketMoke Admin Support');
        if (!empty($data['email'])) {
            $mail->addReplyTo($data['email'], $data['name'] ?? 'Visitor');
        }
        $prefix = $contactCfg['subject_prefix'] ?: '[MarketMoke Contact Inquiry]';
        $mail->Subject = $prefix . ' ' . ($data['subject'] ?: 'New message from ' . $data['name']);

        $mail->Body = '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px;">
            <h2 style="color: #0f172a; margin-top: 0;">📬 New Contact Form Inquiry Received</h2>
            <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 16px 0;">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <tr><td style="padding: 8px 0; color: #64748b; width: 140px;"><strong>Sender Name:</strong></td><td style="color: #0f172a;">' . htmlspecialchars($data['name']) . '</td></tr>
                <tr><td style="padding: 8px 0; color: #64748b;"><strong>Sender Email:</strong></td><td><a href="mailto:' . htmlspecialchars($data['email']) . '" style="color: #2563eb;">' . htmlspecialchars($data['email']) . '</a></td></tr>
                <tr><td style="padding: 8px 0; color: #64748b;"><strong>Phone/WhatsApp:</strong></td><td style="color: #0f172a;">' . htmlspecialchars($data['phone'] ?? 'N/A') . '</td></tr>
                <tr><td style="padding: 8px 0; color: #64748b;"><strong>Subject:</strong></td><td style="color: #0f172a;">' . htmlspecialchars($data['subject'] ?? 'General Inquiry') . '</td></tr>
                <tr><td style="padding: 8px 0; color: #64748b; vertical-align: top;"><strong>Message:</strong></td><td style="color: #0f172a; line-height: 1.5; white-space: pre-wrap;">' . nl2br(htmlspecialchars($data['message'])) . '</td></tr>
            </table>
            <div style="margin-top: 20px; padding: 12px; background-color: #f8fafc; border-radius: 8px; font-size: 12px; color: #64748b;">
                Received on: ' . date('Y-m-d H:i:s T') . ' | IP: ' . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') . '
            </div>
        </div>';

        $mail->AltBody = "New Contact Form Inquiry Received\n\n" .
            "Sender Name: " . ($data['name'] ?? '') . "\n" .
            "Sender Email: " . ($data['email'] ?? '') . "\n" .
            "Phone: " . ($data['phone'] ?? 'N/A') . "\n" .
            "Subject: " . ($data['subject'] ?? 'General Inquiry') . "\n\n" .
            "Message:\n" . ($data['message'] ?? '') . "\n\n" .
            "Received on: " . date('Y-m-d H:i:s T') . "\n";

        return $mail->send();
    }

    /**
     * Send Password Reset OTP Email
     */
    public static function sendPasswordResetOtpEmail(string $toEmail, string $toName, string $otpCode): bool {
        try {
            $mail = self::createMailer();
            $mail->addAddress($toEmail, $toName ?: 'MarketMoke User');
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Verification Code - MarketMoke';

            $spacedOtp = implode('&nbsp;&nbsp;', str_split($otpCode));

            $mail->Body = '
            <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 540px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
                <div style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); padding: 30px; text-align: center;">
                    <h1 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;">Market<span style="color: #38bdf8;">Moke</span></h1>
                    <p style="color: #94a3b8; font-size: 11px; margin: 6px 0 0; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700;">Account Security Service</p>
                </div>
                <div style="padding: 32px 28px; color: #334155;">
                    <p style="font-size: 15px; margin: 0 0 16px; color: #0f172a;">Hello <strong>' . htmlspecialchars($toName) . '</strong>,</p>
                    <p style="font-size: 14px; line-height: 1.6; margin: 0 0 24px; color: #475569;">
                        We received a request to reset your password. Use the verification code below to confirm your request:
                    </p>
                    <div style="background: #f0f9ff; border: 2px dashed #0284c7; border-radius: 12px; padding: 22px; text-align: center; margin: 0 0 24px;">
                        <span style="font-size: 11px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 1.5px; display: block; margin-bottom: 8px;">Your Verification Code</span>
                        <span style="font-size: 36px; font-weight: 900; letter-spacing: 8px; color: #0c4a6e; font-family: monospace; display: inline-block;">' . $spacedOtp . '</span>
                    </div>
                    <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 10px; padding: 14px; margin: 0 0 20px; font-size: 12px; line-height: 1.5; color: #92400e;">
                        ⏳ <strong>Code Expiry:</strong> This code expires after <strong>20 minutes</strong> and can only be used once.<br/>
                        🔒 <strong>Security Notice:</strong> If you did not request this, you can safely ignore this email. Your current password remains secure.
                    </div>
                    <p style="font-size: 12px; color: #64748b; line-height: 1.5; margin: 0;">
                        Never share this verification code with anyone. MarketMoke staff will never ask for your code.
                    </p>
                </div>
                <div style="background: #f8fafc; padding: 16px 28px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8;">
                    &copy; ' . date('Y') . ' MarketMoke Marketplace LLC. All rights reserved.
                </div>
            </div>';

            $mail->AltBody = "Hello " . $toName . ",\n\n" .
                "We received a request to reset your password.\n" .
                "Your verification code is: " . $otpCode . "\n\n" .
                "This code expires after 20 minutes.\n" .
                "If you did not request this, you can ignore this email.\n\n" .
                "Best regards,\nMarketMoke Team";

            return $mail->send();
        } catch (Exception $e) {
            error_log("[MarketMoke Password Reset Mail Error] " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send auto-reply to visitor who submitted contact form
     */
    public static function sendContactAutoReply($toEmail, $toName) {
        $contactCfg = self::getContactConfig();
        if (empty($contactCfg['auto_reply_enabled'])) {
            return true; // Auto-reply disabled in settings
        }

        $mail = self::createMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = 'We have received your MarketMoke inquiry';

        $mail->Body = '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 540px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; color: #334155;">
            <h2 style="color: #0f172a; margin-top: 0;">Thank You for Contacting MarketMoke</h2>
            <p>Hi ' . htmlspecialchars($toName) . ',</p>
            <p>Thank you for reaching out to us. We have received your inquiry and our 24/7 client support desk is reviewing your message.</p>
            <p>A support representative will get back to you within 2-4 hours.</p>
            <div style="margin: 20px 0; padding: 16px; background-color: #f8fafc; border-radius: 8px; font-size: 13px;">
                <strong>Need immediate assistance?</strong><br>
                WhatsApp Direct: <a href="https://wa.me/' . preg_replace('/[^0-9]/', '', $contactCfg['support_phone']) . '" style="color: #10b981; font-weight: bold;">' . htmlspecialchars($contactCfg['support_phone']) . '</a><br>
                Official Support: <a href="mailto:' . htmlspecialchars($contactCfg['recipient_email']) . '" style="color: #2563eb;">' . htmlspecialchars($contactCfg['recipient_email']) . '</a>
            </div>
            <p style="font-size: 12px; color: #64748b;">Best regards,<br>The MarketMoke Support Team</p>
        </div>';

        return $mail->send();
    }
}

/**
 * Global wrapper function for sending OTP emails via PHPMailer
 * @param string $email Recipient email address
 * @param string $name Recipient user name
 * @param string $otp 6-digit OTP code
 * @return bool True if sent successfully, false otherwise
 */
function sendOTPMail($email, $name, $otp) {
    return MarketMokeMailer::sendOtpEmail($email, $name, $otp);
}

