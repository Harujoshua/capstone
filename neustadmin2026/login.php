<?php
include('../db.php');
include('admin_db.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please provide username and password.';
        } else {
            // Admin table and default-account provisioning removed from login flow.

            // Use prepared statement to prevent SQL injection
            if ($stmt = $admin_conn->prepare('SELECT username, password_hash, email, role, is_first_login FROM admins WHERE username = ? LIMIT 1')) {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 1) {
                    $stmt->bind_result($db_username, $db_password_hash, $db_email, $db_role, $db_is_first_login);
                    $stmt->fetch();
                    if (is_string($db_password_hash) && password_verify($password, $db_password_hash)) {
                        $_SESSION['2fa_pending_admin'] = $db_username;
                        $_SESSION['2fa_admin_email'] = $db_email;
                        $_SESSION['2fa_pending_role'] = $db_role;
                        $_SESSION['2fa_pending_is_first_login'] = $db_is_first_login;
                        $otp = sprintf("%06d", mt_rand(1, 999999));
                        $_SESSION['admin_2fa_code'] = $otp;
                        
                        require_once '../mailer.php';
                        if (!empty($db_email)) {
                            send_admin_otp($db_email, $otp);
                        }
                        
                        $stmt->close();
                        header('Location: verify_otp.php');
                        exit;
                    } else {
                        $error = 'Invalid username or password.';
                    }
                } else {
                    $error = 'Invalid username or password.';
                }
                $stmt->close();
            } else {
                $error = 'Authentication temporarily unavailable.';
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
    <title>Admin Login – NEUST Gatepass</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
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
                <img src="admin_assets/neust_logo.png" alt="NEUST Logo" class="brand-logo">
                <h1>NEUST Gatepass</h1>
                <p>Administrator Portal</p>
            </div>
        </div>
        <div class="form">
            <div class="header">
                <h2 id="login-title">Welcome back Admin</h2>
                <p class="lead">Sign in to manage schedules, students, and reports.</p>
            </div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username" required
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autofocus>

                <label for="password">Password</label>
                <div class="pw">
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <button type="button" class="toggle" aria-pressed="false" aria-label="Show password">Show</button>
                </div>

                <button type="submit" class="submit">Sign in</button>
            </form>
        </div>
    </div>

    <script>
        (function () {
            var pw = document.getElementById('password');
            var btn = document.querySelector('.toggle');
            if (!pw || !btn) return;
            btn.addEventListener('click', function (e) {
                var type = pw.getAttribute('type') === 'password' ? 'text' : 'password';
                pw.setAttribute('type', type);
                btn.textContent = type === 'password' ? 'Show' : 'Hide';
                btn.setAttribute('aria-pressed', type !== 'password');
            });
        })();
    </script>
</body>

</html>