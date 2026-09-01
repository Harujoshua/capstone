<?php
// Admin settings page: toggle email sending to parents and manage admin details
include('admin_db.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$adminRole = $_SESSION['admin_role'] ?? 'sub_admin';

if (isset($_GET['cancel_reset'])) {
    unset($_SESSION['admin_reset_otp']);
    unset($_SESSION['admin_reset_time']);
    header('Location: settings.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Update email_to_parents, school year, and semester (super_admin only)
    if ($adminRole === 'super_admin' && isset($_POST['update_general'])) {
        // Toggle email_to_parents
        $val = ($_POST['email_to_parents'] ?? '0') === '1' ? '1' : '0';
        $name = 'email_to_parents';
        $safeName = $admin_conn->real_escape_string($name);
        $safeVal = $admin_conn->real_escape_string($val);
        $res = $admin_conn->query("SELECT id FROM settings WHERE name='$safeName' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $admin_conn->query("UPDATE settings SET value='$safeVal' WHERE name='$safeName' LIMIT 1");
        } else {
            $admin_conn->query("INSERT INTO settings (name, value) VALUES ('$safeName', '$safeVal')");
        }

        // Active School Year
        if (isset($_POST['active_school_year'])) {
            $sy = trim($_POST['active_school_year']);
            $safeSyName = $admin_conn->real_escape_string('active_school_year');
            $safeSyVal = $admin_conn->real_escape_string($sy);
            $resSy = $admin_conn->query("SELECT id FROM settings WHERE name='$safeSyName' LIMIT 1");
            if ($resSy && $resSy->num_rows > 0) {
                $admin_conn->query("UPDATE settings SET value='$safeSyVal' WHERE name='$safeSyName' LIMIT 1");
            } else {
                $admin_conn->query("INSERT INTO settings (name, value) VALUES ('$safeSyName', '$safeSyVal')");
            }
        }

        // Active Semester
        if (isset($_POST['active_semester'])) {
            $sem = trim($_POST['active_semester']);
            $safeSemName = $admin_conn->real_escape_string('active_semester');
            $safeSemVal = $admin_conn->real_escape_string($sem);
            $resSem = $admin_conn->query("SELECT id FROM settings WHERE name='$safeSemName' LIMIT 1");
            if ($resSem && $resSem->num_rows > 0) {
                $admin_conn->query("UPDATE settings SET value='$safeSemVal' WHERE name='$safeSemName' LIMIT 1");
            } else {
                $admin_conn->query("INSERT INTO settings (name, value) VALUES ('$safeSemName', '$safeSemVal')");
            }
        }
    }

    // 2. Update Admin Email
    if (isset($_POST['admin_email'])) {
        $new_email = trim($_POST['admin_email']);
        if (filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $safeEmail = $admin_conn->real_escape_string($new_email);
            // Verify session variable exists, fallback to 'admin' if not
            $adminUsername = $_SESSION['admin_username'] ?? 'admin';
            $safeUsername = $admin_conn->real_escape_string($adminUsername);

            $admin_conn->query("UPDATE admins SET email='$safeEmail' WHERE username='$safeUsername'");
        } elseif ($new_email !== '') {
            $error = 'Invalid email format provided.';
        }
    }

    if (empty($error)) {
        $message = 'Settings saved successfully.';
    }
}

// Password Reset Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Re-fetch email to ensure we send to the latest one
    $adminUsername = $_SESSION['admin_username'] ?? 'admin';
    $safeUsername = $admin_conn->real_escape_string($adminUsername);
    $res = $admin_conn->query("SELECT email FROM admins WHERE username='$safeUsername' LIMIT 1");
    $email_to_send = '';
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $email_to_send = $row['email'];
    }

    if ($_POST['action'] === 'request_password_reset') {
        if (empty($email_to_send)) {
            $error = "No admin email configured. Please set an email first.";
        } else {
            $otp = sprintf("%06d", mt_rand(1, 999999));
            $_SESSION['admin_reset_otp'] = $otp;
            $_SESSION['admin_reset_time'] = time();
            
            require_once '../mailer.php';
            if (send_admin_password_reset_otp($email_to_send, $otp)) {
                $message = "A reset code has been sent to your email: $email_to_send";
            } else {
                $error = "Failed to send reset code. Please check your mailer configuration.";
            }
        }
    } elseif ($_POST['action'] === 'confirm_password_reset') {
        $entered_otp = trim($_POST['otp'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($entered_otp !== (string)($_SESSION['admin_reset_otp'] ?? '')) {
            $error = "Invalid or expired reset code.";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $adminUsername = $_SESSION['admin_username'] ?? 'admin';
            $safeUsername = $admin_conn->real_escape_string($adminUsername);
            
            if ($admin_conn->query("UPDATE admins SET password_hash='$hashed_password' WHERE username='$safeUsername'")) {
                $message = "Password updated successfully.";
                unset($_SESSION['admin_reset_otp']);
                unset($_SESSION['admin_reset_time']);
            } else {
                $error = "Database error: Failed to update password.";
            }
        }
    }
}

// Fetch current settings
$enabled = true;
$q = $admin_conn->query("SELECT value FROM settings WHERE name='email_to_parents' LIMIT 1");
if ($q && $q->num_rows > 0) {
    $r = $q->fetch_assoc();
    $enabled = ($r['value'] === '1' || strtolower((string) $r['value']) === 'true' || $r['value'] === 'on');
}

// Fetch active school year & semester
$active_school_year = '2025-2026';
$q_sy = $admin_conn->query("SELECT value FROM settings WHERE name='active_school_year' LIMIT 1");
if ($q_sy && $q_sy->num_rows > 0) {
    $active_school_year = $q_sy->fetch_assoc()['value'];
}

$active_semester = '1st Semester';
$q_sem = $admin_conn->query("SELECT value FROM settings WHERE name='active_semester' LIMIT 1");
if ($q_sem && $q_sem->num_rows > 0) {
    $active_semester = $q_sem->fetch_assoc()['value'];
}

// Fetch current admin email
$adminUsername = $_SESSION['admin_username'] ?? 'admin';
$safeUsername = $admin_conn->real_escape_string($adminUsername);
$current_email = '';
$res = $admin_conn->query("SELECT email FROM admins WHERE username='$safeUsername' LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $current_email = $row['email'];
}

include('navbar.php');
?>
<link rel="stylesheet" href="admin_assets/admin_settings.css">

<div class="container">
    <!-- Page Header -->

    <?php if ($message): ?>
        <div class="message-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="settings-grid">

        <?php if ($adminRole === 'super_admin'): ?>
        <!-- Card 1: Academic Settings -->
        <form method="post" action="settings.php" class="setting-card accent-teal">
            <input type="hidden" name="update_general" value="1">
            <!-- hidden checkbox value to preserve toggle state -->
            <input type="hidden" name="email_to_parents" value="0">

            <div class="card-header">
                <div class="card-header-text">
                    <h3>Academic Settings</h3>
                    <p>School year & semester configuration</p>
                </div>
            </div>

            <div class="field-row">
                <div class="card-field">
                    <label for="active_school_year">School Year</label>
                    <input type="text" id="active_school_year" name="active_school_year" value="<?= htmlspecialchars($active_school_year) ?>" placeholder="e.g. 2025-2026" required>
                </div>
                <div class="card-field">
                    <label for="active_semester">Semester</label>
                    <select id="active_semester" name="active_semester" required>
                        <option value="1st Semester" <?= ($active_semester === '1st Semester') ? 'selected' : '' ?>>1st Semester</option>
                        <option value="2nd Semester" <?= ($active_semester === '2nd Semester') ? 'selected' : '' ?>>2nd Semester</option>
                        <option value="Summer" <?= ($active_semester === 'Summer') ? 'selected' : '' ?>>Summer</option>
                    </select>
                </div>
            </div>

            <hr class="card-divider">

            <div class="toggle-row">
                <label class="toggle-label" for="email_toggle">
                    Parent Email Notifications
                    <span class="toggle-desc">Send entry/exit emails to parents automatically</span>
                </label>
                <label class="toggle-switch">
                    <input type="checkbox" id="email_toggle" name="email_to_parents" value="1" <?= $enabled ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="card-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
        <?php endif; ?>

        <!-- Security Column: Account Email + Change Password stacked in one grid cell -->
        <div class="security-column <?= ($adminRole !== 'super_admin') ? 'full-width' : '' ?>">

            <!-- Card 2: Account Email -->
            <form method="post" action="settings.php" class="setting-card accent-blue">
                <div class="card-header">
                    <div class="card-header-text">
                        <h3>Account Email</h3>
                        <p>Your admin contact and recovery email</p>
                    </div>
                </div>

                <div class="card-field">
                    <label for="admin_email">Email Address</label>
                    <input type="email" id="admin_email" name="admin_email" value="<?= htmlspecialchars($current_email) ?>" placeholder="admin@example.com" required>
                </div>

                <?php if (!empty($current_email)): ?>
                    <div style="margin-top: 10px;">
                        <span class="status-badge badge-active">Configured</span>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 10px;">
                        <span class="status-badge badge-inactive">Not Set</span>
                    </div>
                <?php endif; ?>

                <div class="card-actions">
                    <button type="submit" class="btn btn-primary">Update Email</button>
                </div>
            </form>

            <!-- Card 3: Change Password -->
            <div class="setting-card accent-violet">
                <div class="card-header">
                    <div class="card-header-text">
                        <h3>Change Password</h3>
                        <p>Secure your account with email-verified password reset</p>
                    </div>
                </div>

                <?php if (!isset($_SESSION['admin_reset_otp'])): ?>
                    <!-- Step 1: Request OTP -->
                    <div class="password-step">
                        <p>To change your password, we'll send a 6-digit verification code to your registered email address.</p>
                        <form method="post" action="settings.php">
                            <input type="hidden" name="action" value="request_password_reset">
                            <button type="submit" class="btn btn-secondary">Send Verification Code</button>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Step 2: Confirm OTP & New Password -->
                    <form method="post" action="settings.php">
                        <input type="hidden" name="action" value="confirm_password_reset">

                        <div class="card-field" style="max-width: 220px; margin: 0 auto 16px;">
                            <label for="otp" style="text-align: center;">Verification Code</label>
                            <input type="text" id="otp" name="otp" placeholder="000000" maxlength="6" required class="otp-input">
                        </div>

                        <div class="field-row" style="max-width: 500px; margin: 0 auto;">
                            <div class="card-field">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" required placeholder="Min 6 characters">
                            </div>
                            <div class="card-field">
                                <label for="confirm_password">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required placeholder="Re-enter password">
                            </div>
                        </div>

                        <div class="card-actions" style="justify-content: center; margin-top: 22px;">
                            <button type="submit" class="btn btn-primary">
                                Update Password
                            </button>
                            <a href="settings.php?cancel_reset=1" class="cancel-link">Cancel</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

        </div><!-- /.security-column -->

    </div><!-- /.settings-grid -->
</div>