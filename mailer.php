<?php

// NEUST Gatepass System - Email Notification Module
// This module handles all email communications for the system including:
// - Parent/Guardian notifications for student entry/exit events
// - Two-factor authentication (2FA) codes for admin and faculty

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

// Parent/Guardian Notification Functions

/**
 * Sends email notification to parents when students enter or exit campus.
 * Respects admin settings to allow disabling parent email notifications.
 *
 * @param string $toEmail Parent's email address
 * @param string $parentName Parent's name for personalization
 * @param string $studentName Student's name involved in the event
 * @param string $status "ENTRY" or "EXIT" to indicate action type
 * @param string $time Timestamp of when the entry/exit occurred
 * @return bool True if email sent successfully, False otherwise
 */
function send_parent_notification($toEmail, $parentName, $studentName, $status, $time, $toEmail2 = '')
{
    // Silently skip if no email address is configured
    if (empty($toEmail) && empty($toEmail2)) {
        return false;
    }

    // Helper function to check if parent emails are enabled in system settings
    if (!function_exists('is_parent_email_enabled')) {
        function is_parent_email_enabled()
        {
            $enabled = true;
            
            // Query admin database to retrieve the email notification setting
            $admin_conn = @new mysqli('localhost', 'root', '', 'neust_gatepass_v3');
            if ($admin_conn && !$admin_conn->connect_error) {
                $res = $admin_conn->query("SHOW TABLES LIKE 'settings'");
                if ($res && $res->num_rows > 0) {
                    // Fetch the email_to_parents setting from the database
                    $q = $admin_conn->query("SELECT value FROM settings WHERE name='email_to_parents' LIMIT 1");
                    if ($q && $q->num_rows > 0) {
                        $row = $q->fetch_assoc();
                        $v = trim((string) ($row['value'] ?? ''));
                        // Allow multiple formats for the setting value
                        $enabled = ($v === '1' || strtolower($v) === 'true' || $v === 'on');
                    }
                }
                $admin_conn->close();
            }
            return $enabled;
        }
    }

    try {
        // Check if admin has disabled parent email notifications
        if (!is_parent_email_enabled()) {
            return false;
        }
    } catch (Throwable $e) {
        // If setting check fails, proceed with sending (conservative default)
    }


    $mail = new PHPMailer(true);

    try {
        // Configure SMTP Server Settings
        // Using Gmail SMTP service for reliable email delivery
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'carranglanoffcampusneust@gmail.com';
        $mail->Password = 'gaxw jmjg uvzp gwrc';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        // Set Email Recipients and Sender
        $mail->setFrom('no-reply@neustgatepass.edu.ph', 'NEUST Gatepass System');
        if (!empty($toEmail)) {
            $mail->addAddress($toEmail, empty($parentName) ? 'Parent/Guardian' : $parentName);
        }
        if (!empty($toEmail2)) {
            $mail->addAddress($toEmail2, empty($parentName) ? 'Parent/Guardian' : $parentName);
        }

        // Compose Email Content
        $mail->isHTML(true);
        $mail->Subject = 'NEUST_CARRANGLAN_OFF-CAMPUS';

        // Personalize greeting based on available parent information
        $greeting = empty($parentName) ? "Dear Parent/Guardian of $studentName," : "Dear $parentName,";
        
        // Determine the action text based on entry/exit status
        if (strtoupper($status) === 'IN') {
            $action = "entered";
        } else {
            $action = "exited";
        }

        // Generate HTML email body with styled notification message
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #1a56db; color: #fff; padding: 20px; text-align: center;'>
                    <h2 style='margin: 0;'>Campus $action Notification</h2>
                </div>
                <div style='padding: 30px;'>
                    <p style='font-size: 16px; margin-bottom: 20px;'>$greeting</p>
                    <p style='font-size: 16px; line-height: 1.5;'>
                        This is to notify you that your child, <strong>$studentName</strong>, has $action the campus.
                    </p>
                    <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                        <tr>
                            <td style='padding: 10px; border-bottom: 1px solid #eee; width: 40%; font-weight: bold;'>Status:</td>
                            <td style='padding: 10px; border-bottom: 1px solid #eee; color: " . ($action === 'entered' ? '#15803d' : '#b91c1c') . "; font-weight: bold;'>" . strtoupper($action) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;'>Date & Time:</td>
                            <td style='padding: 10px; border-bottom: 1px solid #eee;'>$time</td>
                        </tr>
                    </table>
                    <p style='font-size: 14px; color: #666; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px;'>
                        This is an automated message from the NEUST Gatepass System. Please do not reply to this email.
                    </p>
                </div>
            </div>
        ";

        // Provide plain text alternative for email clients that don't support HTML
        $mail->AltBody = "$greeting\n\nThis is to notify you that your child, $studentName, has  $action the campus on $time.\n\nStatus: " . strtoupper($action) . "\n\nThis is an automated message. Please do not reply.";

        // Send the email
        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error for debugging purposes
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Two-Factor Authentication (2FA) Email Functions

/**
 * Sends a 6-digit OTP code to admin for two-factor authentication during login.
 * Used to verify admin identity and enhance system security.
 *
 * @param string $toEmail Admin's email address where OTP will be sent
 * @param string $otp The 6-digit one-time password to send
 * @return bool True if email sent successfully, False otherwise
 */
function send_admin_otp($toEmail, $otp)
{
    // Skip sending if no email is configured
    if (empty($toEmail)) {
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // Configure SMTP Server Settings
        // Using Gmail SMTP service for reliable email delivery
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'carranglanoffcampusneust@gmail.com';
        $mail->Password = 'gaxw jmjg uvzp gwrc';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        // Set Email Recipients and Sender
        $mail->setFrom('no-reply@neustgatepass.edu.ph', 'NEUST Gatepass System');
        $mail->addAddress($toEmail, 'Administrator');

        // Compose Email Content
        $mail->isHTML(true);
        $mail->Subject = 'Admin Login - 2FA Code';

        // Generate HTML email body with secure OTP display
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #1a56db; color: #fff; padding: 20px; text-align: center;'>
                    <h2 style='margin: 0;'>Your Authentication Code</h2>
                </div>
                <div style='padding: 30px; text-align: center;'>
                    <p style='font-size: 16px; margin-bottom: 20px;'>Use the following 6-digit code to complete your login:</p>
                    <div style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #1a56db; margin: 20px 0;'>
                        $otp
                    </div>
                    <p style='font-size: 14px; color: #666; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px;'>
                        This code will expire shortly. Do not share this code with anyone.
                    </p>
                </div>
            </div>
        ";

        // Provide plain text alternative for security and accessibility
        $mail->AltBody = "Your authentication code is: $otp\n\nDo not share this code with anyone.";

        // Send the email
        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error for debugging purposes
        error_log("OTP Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Sends a 6-digit OTP code to faculty for two-factor authentication during login.
 * Used to verify faculty identity and enhance system security.
 *
 * @param string $toEmail Faculty's email address where OTP will be sent
 * @param string $otp The 6-digit one-time password to send
 * @return bool True if email sent successfully, False otherwise
 */
function send_faculty_otp($toEmail, $otp)
{
    // Skip sending if no email is configured
    if (empty($toEmail)) {
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // Configure SMTP Server Settings
        // Using Gmail SMTP service for reliable email delivery
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'carranglanoffcampusneust@gmail.com';
        $mail->Password = 'gaxw jmjg uvzp gwrc';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        // Set Email Recipients and Sender
        $mail->setFrom('no-reply@neustgatepass.edu.ph', 'NEUST Gatepass System');
        $mail->addAddress($toEmail, 'Faculty');

        // Compose Email Content
        $mail->isHTML(true);
        $mail->Subject = 'Faculty Login - 2FA Code';

        // Generate HTML email body with secure OTP display
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #1a56db; color: #fff; padding: 20px; text-align: center;'>
                    <h2 style='margin: 0;'>Your Authentication Code</h2>
                </div>
                <div style='padding: 30px; text-align: center;'>
                    <p style='font-size: 16px; margin-bottom: 20px;'>Use the following 6-digit code to complete your login:</p>
                    <div style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #1a56db; margin: 20px 0;'>
                        $otp
                    </div>
                    <p style='font-size: 14px; color: #666; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px;'>
                        This code will expire shortly. Do not share this code with anyone.
                    </p>
                </div>
            </div>
        ";

        // Provide plain text alternative for security and accessibility
        $mail->AltBody = "Your authentication code is: $otp\n\nDo not share this code with anyone.";

        // Send the email
        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error for debugging purposes
        error_log("OTP Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Sends a 6-digit OTP code to admin for password reset.
 */
function send_admin_password_reset_otp($toEmail, $otp)
{
    if (empty($toEmail)) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'carranglanoffcampusneust@gmail.com';
        $mail->Password = 'gaxw jmjg uvzp gwrc';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        $mail->setFrom('no-reply@neustgatepass.edu.ph', 'NEUST Gatepass System');
        $mail->addAddress($toEmail, 'Administrator');

        $mail->isHTML(true);
        $mail->Subject = 'Security Alert - Admin Password Reset Code';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #b91c1c; color: #fff; padding: 20px; text-align: center;'>
                    <h2 style='margin: 0;'>Password Reset Request</h2>
                </div>
                <div style='padding: 30px; text-align: center;'>
                    <p style='font-size: 16px; margin-bottom: 20px;'>A request was made to change the administrator password. Use the following 6-digit code to verify your identity:</p>
                    <div style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #b91c1c; margin: 20px 0;'>
                        $otp
                    </div>
                    <p style='font-size: 14px; color: #666; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px;'>
                        If you did not request this change, please ignore this email and ensure your account is secure.
                    </p>
                </div>
            </div>
        ";
        $mail->AltBody = "Your password reset code is: $otp";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Password Reset OTP could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Sends an email with a setup link for a new faculty account to set their password.
 */
function send_faculty_setup_email($toEmail, $facultyName, $token)
{
    if (empty($toEmail)) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'carranglanoffcampusneust@gmail.com';
        $mail->Password = 'gaxw jmjg uvzp gwrc';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        $mail->setFrom('no-reply@neustgatepass.edu.ph', 'NEUST Gatepass System');
        $mail->addAddress($toEmail, $facultyName);

        $mail->isHTML(true);
        $mail->Subject = 'Faculty Account Setup - NEUST Gatepass System';
        
        // Define the base URL dynamically or statically. 
        // For development, assuming localhost path. Adjust to production domain if needed.
        $setupLink = "http://" . $_SERVER['HTTP_HOST'] . "/neust_gatepass/faculty/set_password.php?token=" . $token;

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #1a56db; color: #fff; padding: 20px; text-align: center;'>
                    <h2 style='margin: 0;'>Welcome to NEUST Gatepass System</h2>
                </div>
                <div style='padding: 30px;'>
                    <p style='font-size: 16px; margin-bottom: 20px;'>Dear $facultyName,</p>
                    <p style='font-size: 16px; line-height: 1.5;'>
                        An administrator has created a faculty account for you. To complete your account setup, please set your password by clicking the button below:
                    </p>
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='$setupLink' style='background-color: #1a56db; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;'>Set My Password</a>
                    </div>
                    <p style='font-size: 14px; color: #666;'>
                        If the button above does not work, you can copy and paste the following link into your browser:<br>
                        <a href='$setupLink'>$setupLink</a>
                    </p>
                    <p style='font-size: 14px; color: #666; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px;'>
                        This link will expire in 24 hours. If you did not request this account, please contact the administrator.
                    </p>
                </div>
            </div>
        ";
        
        $mail->AltBody = "Dear $facultyName,\n\nAn administrator has created a faculty account for you. To set your password, please visit the following link:\n\n$setupLink\n\nThis link will expire in 24 hours.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Faculty setup email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
