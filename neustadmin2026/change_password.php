<?php
include('admin_db.php');
include('auth.php'); // This checks if admin is logged in, and allows 'change_password.php'

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Please fill in both fields.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $username = $_SESSION['admin_username'];
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $admin_conn->prepare("UPDATE admins SET password_hash = ?, is_first_login = 0 WHERE username = ?");
        $stmt->bind_param("ss", $hashed_password, $username);
        
        if ($stmt->execute()) {
            $_SESSION['admin_is_first_login'] = 0;
            
            $success = "Password changed successfully. Redirecting...";
            header("refresh:2;url=dashboard.php");
        } else {
            $error = "Failed to update password. Please try again.";
        }
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Change Password – NEUST Gatepass</title>
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
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
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
            max-width: 480px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(2, 6, 23, 0.08);
            overflow: hidden;
            padding: 36px;
        }
        .header { margin-bottom: 24px; text-align: center; }
        h2 { margin: 0 0 8px; font-size: 1.4rem; color: #1e293b; }
        .lead { color: var(--muted); font-size: 0.95rem; margin: 0; }
        .error, .success {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.95rem;
            text-align: center;
        }
        .error { background: var(--danger-bg); color: var(--danger-text); }
        .success { background: var(--success-bg); color: var(--success-text); }
        .form label { display: block; font-weight: 600; margin: 12px 0 6px; font-size: 0.95rem; }
        .form input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid #e6e9ee;
            font-size: 0.96rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .form input[type="password"]:focus { border-color: var(--accent); }
        .submit {
            margin-top: 24px;
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: none;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        .submit:hover { background: #1e40af; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="header">
            <h2>Welcome, <?php echo htmlspecialchars($_SESSION['admin_username']); ?>!</h2>
            <p class="lead">For your security, please set a new password for your account.</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php else: ?>
            <form method="POST" class="form">
                <label for="new_password">New Password</label>
                <input id="new_password" name="new_password" type="password" required autofocus>

                <label for="confirm_password">Confirm New Password</label>
                <input id="confirm_password" name="confirm_password" type="password" required>

                <button type="submit" class="submit">Update Password</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
