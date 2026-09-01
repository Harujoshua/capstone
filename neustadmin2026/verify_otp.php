<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['2fa_pending_admin'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? 'verify';
        
        if ($action === 'resend') {
            $otp = sprintf("%06d", mt_rand(1, 999999));
            $_SESSION['admin_2fa_code'] = $otp;
            
            require_once '../mailer.php';
            if (!empty($_SESSION['2fa_admin_email'])) {
                send_admin_otp($_SESSION['2fa_admin_email'], $otp);
            }
            
            $success_msg = 'A new code has been sent to your email.';
        } else {
            $otp = trim($_POST['otp'] ?? '');
            
            if ($otp === '') {
                $error = 'Please enter the 6-digit code.';
            } elseif ($otp === (string)$_SESSION['admin_2fa_code']) {
                // OTP verified successfully
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $_SESSION['2fa_pending_admin'];
                $_SESSION['admin_role'] = $_SESSION['2fa_pending_role'] ?? 'sub_admin';
                $_SESSION['admin_is_first_login'] = $_SESSION['2fa_pending_is_first_login'] ?? 0;
                
                // Cleanup 2FA session variables
                unset($_SESSION['2fa_pending_admin']);
                unset($_SESSION['2fa_pending_role']);
                unset($_SESSION['2fa_pending_is_first_login']);
                unset($_SESSION['admin_2fa_code']);
                unset($_SESSION['2fa_admin_email']);
                
                if ($_SESSION['admin_is_first_login']) {
                    header('Location: change_password.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit;
            } else {
                $error = 'Invalid code. Please try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Verify 2FA – NEUST Gatepass</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin_assets/admin_verify_otp.css">
</head>

<body>
    <div class="auth-card" role="main" aria-labelledby="login-title">
        <div class="brand" aria-hidden="true">
            <div class="brand-inner">
                <img src="admin_assets/neust_logo.png" alt="NEUST Logo" class="brand-logo">
                <h1>NEUST Gatepass</h1>
                <p>Administrator Portal</p>
            </div>
        </div>
        <div class="form">
            <div class="header">
                <h2 id="login-title">Two-Factor Authentication</h2>
                <p class="lead">Enter the 6-digit code to continue.</p>
            </div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success_msg): ?>
                <div class="success"><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>



            <form method="POST" action="verify_otp.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="verify">
                
                <label for="otp">Authentication Code</label>
                <input id="otp" name="otp" type="text" autocomplete="one-time-code" required autofocus pattern="[0-9]{6}" maxlength="6" placeholder="000000">

                <button type="submit" class="submit">Verify Code</button>
            </form>

            <form method="POST" action="verify_otp.php" class="resend-form">
                 <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                 <input type="hidden" name="action" value="resend">
                 <button type="submit" class="resend-btn">Resend Code</button>
            </form>
            
            <div class="back-link-section">
                <a href="login.php" class="back-link">&larr; Back to Login</a>
            </div>
        </div>
    </div>
</body>

</html>
