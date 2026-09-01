<?php
include('../db.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? ''); // Can be email or RFID
    $password = $_POST['password'] ?? '';

    if ($identifier === '') {
        $error = 'Please provide your Email or RFID.';
    } else {
        $id_safe = $conn->real_escape_string($identifier);
        // Search by email or rfid_uid
        $q = $conn->query("SELECT * FROM students WHERE email='$id_safe' OR rfid_uid='$id_safe' LIMIT 1");

        if ($q && $q->num_rows > 0) {
            $s = $q->fetch_assoc();

            // If student has a password set, verify it.
            // If not (first time), allow login with just RFID.
            if (empty($s['password_hash'])) {
                // If it's the first time and they used RFID, log them in
                if ($identifier === $s['rfid_uid']) {
                    $_SESSION['student_id'] = $s['id'];
                    $_SESSION['student_name'] = $s['name'];
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Please use your RFID UID for first-time login.';
                }
            } else {
                if (password_verify($password, $s['password_hash'])) {
                    $_SESSION['student_id'] = $s['id'];
                    $_SESSION['student_name'] = $s['name'];
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid credentials.';
                }
            }
        } else {
            $error = 'Student not found.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Student Login – NEUST Gatepass</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="student_assets/login.css">
</head>
<body>
    <div class="auth-card" role="main" aria-labelledby="login-title">
        <div class="brand" aria-hidden="true">
            <div class="brand-inner">
                <img src="../faculty/faculty_assets/neust_logo.png" alt="NEUST Logo" class="brand-logo">
                <h1>NEUST Gatepass</h1>
                <p>Student Portal</p>
            </div>
        </div>
        <div class="form">
            <div class="header">
                <h2 id="login-title">Welcome</h2>
                <p class="lead">Sign in to access your attendance, schedule, and courses.</p>
            </div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php" novalidate>
                <label for="identifier">Email or RFID UID</label>
                <input id="identifier" name="identifier" type="text" autocomplete="username" required
                    value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" autofocus
                    placeholder="Enter your email or scan RFID">

                <label for="password">Password</label>
                <div class="pw">
                    <input id="password" name="password" type="password" autocomplete="current-password">
                    <button type="button" class="toggle" aria-pressed="false" aria-label="Show password">Show</button>
                </div>
                <p class="hint">* First time? Use your RFID UID as identifier and leave password blank.</p>

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
