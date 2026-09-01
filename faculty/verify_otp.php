<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['2fa_pending_faculty'])) {
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
            $_SESSION['faculty_2fa_code'] = $otp;
            
            require_once '../mailer.php';
            if (!empty($_SESSION['2fa_faculty_email'])) {
                send_faculty_otp($_SESSION['2fa_faculty_email'], $otp);
            }
            
            $success_msg = 'A new code has been sent to your email.';
        } else {
            $otp = trim($_POST['otp'] ?? '');
            
            if ($otp === '') {
                $error = 'Please enter the 6-digit code.';
            } elseif ($otp === (string)$_SESSION['faculty_2fa_code']) {
                // OTP verified successfully
                $_SESSION['faculty_logged_in'] = true;
                $_SESSION['faculty_id'] = $_SESSION['2fa_faculty_id'];
                $_SESSION['faculty_name'] = $_SESSION['2fa_faculty_name'];
                $_SESSION['faculty_email'] = $_SESSION['2fa_faculty_email'];
                $_SESSION['faculty_dept'] = $_SESSION['2fa_faculty_dept'];
                
                // Cleanup 2FA session variables
                unset($_SESSION['2fa_pending_faculty']);
                unset($_SESSION['2fa_faculty_id']);
                unset($_SESSION['2fa_faculty_name']);
                unset($_SESSION['2fa_faculty_email']);
                unset($_SESSION['2fa_faculty_dept']);
                unset($_SESSION['faculty_2fa_code']);
                
                header('Location: dashboard.php');
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
    <style>
        :root {
            --bg1: #eef2ff;
            --bg2: #f8fafc;
            --accent: #1a56db;
            --muted: #6b7280;
            --danger-bg: #fee2e2;
            --danger-text: #991b1b;
            --success-bg: #dcfce7;
            --success-text: #166534;
            --info-bg: #e0f2fe;
            --info-text: #075985;
        }

        * {
            box-sizing: border-box
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;
            background: linear-gradient(135deg, var(--bg1), var(--bg2));
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #0f172a;
        }

        .auth-card {
            width: 100%;
            max-width: 980px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(2, 6, 23, 0.08);
            overflow: hidden;
            display: flex;
            gap: 0;
        }

        .brand {
            flex: 1;
            min-width: 280px;
            background: linear-gradient(180deg, #0ea5e9 0%, #6366f1 100%);
            color: #fff;
            padding: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .brand::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }

        .brand-logo {
            width: 100px;
            height: 100px;
            margin-bottom: 20px;
            object-fit: contain;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.15));
            animation: fadeInDown 0.8s ease-out;
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-inner {
            text-align: center;
        }

        .brand h1 {
            margin: 0;
            font-size: 1.6rem;
            letter-spacing: 0.2px
        }

        .brand p {
            margin-top: 8px;
            opacity: 0.95
        }

        .form {
            flex: 1;
            padding: 36px;
        }

        .header {
            margin-bottom: 18px
        }

        h2 {
            margin: 0 0 6px;
            font-size: 1.25rem
        }

        .lead {
            color: var(--muted);
            font-size: 0.95rem;
            margin: 0
        }

        .error, .success, .demo-info {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 14px;
            font-size: 0.95rem;
        }

        .error {
            background: var(--danger-bg);
            color: var(--danger-text);
        }

        .success {
            background: var(--success-bg);
            color: var(--success-text);
        }

        .demo-info {
            background: var(--info-bg);
            color: var(--info-text);
            border: 1px dashed #7dd3fc;
        }

        .form label {
            display: block;
            font-weight: 600;
            margin: 10px 0 6px
        }

        .form input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid #e6e9ee;
            font-size: 1.2rem;
            letter-spacing: 4px;
            text-align: center;
            outline: none;
        }

        .submit {
            margin-top: 18px;
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: none;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
        }

        @media (max-width:780px) {
            .auth-card {
                flex-direction: column
            }

            .brand {
                padding: 28px
            }

            .form {
                padding: 24px
            }
        }
    </style>
</head>

<body>
    <div class="auth-card" role="main" aria-labelledby="login-title">
        <div class="brand" aria-hidden="true">
            <div class="brand-inner">
                <img src="faculty_assets/neust_logo.png" alt="NEUST Logo" class="brand-logo">
                <h1>NEUST Gatepass</h1>
                <p>Faculty Portal</p>
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

            <form method="POST" action="verify_otp.php" style="margin-top: 15px; text-align: center;">
                 <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                 <input type="hidden" name="action" value="resend">
                 <button type="submit" style="background: none; border: none; color: var(--accent); cursor: pointer; font-size: 0.95rem; font-weight: 600;">Resend Code</button>
            </form>
            
            <div style="margin-top: 20px; text-align: center;">
                <a href="login.php" style="color: var(--muted); text-decoration: none; font-size: 0.9rem;">&larr; Back to Login</a>
            </div>
        </div>
    </div>
</body>

</html>
