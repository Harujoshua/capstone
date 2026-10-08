<?php
include('../db.php');
include('../auth_lockout.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identifier === '') {
        $error = 'Please provide your email address or RFID UID.';
    } else {
        $attempt_state = login_attempt_state('faculty', $identifier);
        if ($attempt_state['blocked']) {
            $error = login_attempt_message($attempt_state);
        } else {
            $id_safe = $conn->real_escape_string($identifier);
            $q = $conn->query("SELECT * FROM faculty WHERE email='$id_safe' OR UPPER(rfid_uid)=UPPER('$id_safe') LIMIT 1");
            if ($q && $q->num_rows > 0) {
                $f = $q->fetch_assoc();
                $has_password = !empty($f['password_hash']);
                $is_first_login = !empty($f['is_first_login']);

                if (!$has_password) {
                    login_attempt_reset('faculty', $identifier);
                    $_SESSION['2fa_pending_faculty'] = true;
                    $_SESSION['2fa_faculty_id'] = $f['id'];
                    $_SESSION['2fa_faculty_name'] = $f['name'];
                    $_SESSION['2fa_faculty_email'] = $f['email'];
                    $_SESSION['2fa_faculty_dept'] = $f['department'];
                    $_SESSION['2fa_faculty_is_first_login'] = true;

                    $otp = sprintf("%06d", mt_rand(1, 999999));
                    $_SESSION['faculty_2fa_code'] = $otp;

                    require_once '../mailer.php';
                    send_faculty_otp($f['email'], $otp);

                    header('Location: verify_otp.php');
                    exit;
                } else {
                    if ($password === '') {
                        $error = 'Please provide your password.';
                        login_attempt_failed('faculty', $identifier);
                    } elseif (password_verify($password, $f['password_hash'])) {
                        login_attempt_reset('faculty', $identifier);
                        $_SESSION['2fa_pending_faculty'] = true;
                        $_SESSION['2fa_faculty_id'] = $f['id'];
                        $_SESSION['2fa_faculty_name'] = $f['name'];
                        $_SESSION['2fa_faculty_email'] = $f['email'];
                        $_SESSION['2fa_faculty_dept'] = $f['department'];
                        $_SESSION['2fa_faculty_is_first_login'] = $is_first_login;

                        $otp = sprintf("%06d", mt_rand(1, 999999));
                        $_SESSION['faculty_2fa_code'] = $otp;

                        require_once '../mailer.php';
                        send_faculty_otp($f['email'], $otp);

                        header('Location: verify_otp.php');
                        exit;
                    } else {
                        $error = 'Invalid email or password.';
                        login_attempt_failed('faculty', $identifier);
                    }
                }
            } else {
                $error = 'Invalid email or password.';
                login_attempt_failed('faculty', $identifier);
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
    <title>Faculty Login – NEUST Gatepass</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        :root {
            --bg1: #eef2ff;
            --bg2: #f8fafc;
            --accent: #1a56db;
            --muted: #6b7280;
            --danger-bg: #fee2e2;
            --danger-text: #991b1b;
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

        .error {
            background: var(--danger-bg);
            color: var(--danger-text);
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 14px
        }

        .form label {
            display: block;
            font-weight: 600;
            margin: 10px 0 6px
        }

        .form input[type="text"],
        .form input[type="email"],
        .form input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid #e6e9ee;
            font-size: 0.96rem;
            outline: none;
        }

        .form .pw {
            display: flex;
            gap: 8px;
        }

        .pw .toggle {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #e6e9ee;
            background: #fff;
            color: var(--muted);
            cursor: pointer;
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

        .forgot {
            margin-top: 10px;
            font-size: 0.9rem;
            color: var(--muted)
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
                <h2 id="login-title">Welcome</h2>
                <p class="lead">Sign in to access your classes, attendance, and reports.</p>
            </div>

            <?php if (!empty($_GET['expired'])): ?>
                <div class="notice-expired" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;display:flex;align-items:center;gap:10px;">
                    <i class="fa-solid fa-clock-rotate-left" style="font-size:16px;color:#d97706;"></i>
                    <span>Your session has expired due to inactivity. Please sign in again.</span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>


            <form method="POST" action="login.php" novalidate>
                <label for="email">Email</label>
                <input id="email" name="email" type="text" autocomplete="username" required
                    placeholder="Enter email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autofocus>

                <label for="password">Password</label>
                <div class="pw">
                    <input id="password" name="password" type="password" autocomplete="current-password">
                    <button type="button" class="toggle" aria-pressed="false" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                </div>

                <button type="submit" class="submit">Sign in</button>
            </form>
    </div>

    <script>
        (function () {
            var pw = document.getElementById('password');
            var btn = document.querySelector('.toggle');
            if (!pw || !btn) return;
            btn.addEventListener('click', function () {
                var show = pw.getAttribute('type') === 'password';
                pw.setAttribute('type', show ? 'text' : 'password');
                btn.querySelector('i').className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                btn.setAttribute('aria-pressed', show);
            });
        })();
    </script>
</body>

</html>