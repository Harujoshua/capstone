<?php
session_start();
include('../db.php');

$error = '';
$success = '';

// Check if token is present
if (!isset($_GET['token']) || empty($_GET['token'])) {
    die("Invalid or missing setup token.");
}

$token = $_GET['token'];

// Validate token
$stmt = $conn->prepare("SELECT id, name, email FROM faculty WHERE reset_token = ? AND token_expiry > NOW() LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("This setup link is invalid or has expired. Please contact the administrator.");
}

$faculty = $result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Update password and clear token
        $update_stmt = $conn->prepare("UPDATE faculty SET password_hash = ?, is_first_login = 0, reset_token = NULL, token_expiry = NULL WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_password, $faculty['id']);
        
        if ($update_stmt->execute()) {
            $success = "Your password has been set successfully! You can now login.";
        } else {
            $error = "Failed to set password. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set Password - NEUST Gatepass</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .setup-container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; }
        h2 { color: #1a56db; margin-top: 0; }
        p { color: #666; margin-bottom: 20px; }
        input[type="password"] { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        .btn { background-color: #1a56db; color: white; padding: 12px; border: none; border-radius: 5px; width: 100%; cursor: pointer; font-size: 16px; font-weight: 500; font-family: 'Inter', sans-serif; transition: background 0.3s; }
        .btn:hover { background-color: #1e40af; }
        .error { color: #dc2626; background: #fee2e2; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: left; font-size: 14px; }
        .success { color: #16a34a; background: #dcfce7; padding: 15px; border-radius: 5px; margin-bottom: 15px; font-size: 15px; }
        .login-link { display: inline-block; margin-top: 20px; color: #1a56db; text-decoration: none; font-weight: 500; }
        .login-link:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="setup-container">
    <h2>Welcome, <?= htmlspecialchars($faculty['name']) ?>!</h2>
    
    <?php if ($success): ?>
        <div class="success"><?= $success ?></div>
        <a href="login.php" class="login-link">Go to Login Page</a>
    <?php else: ?>
        <p>Please set your password to activate your account.</p>
        
        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="password" name="password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <button type="submit" class="btn">Set Password</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>
