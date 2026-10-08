<?php
include('../db.php');
include('../auth_lockout.php');
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
        $attempt_state = login_attempt_state('student', $identifier);
        if ($attempt_state['blocked']) {
            $error = login_attempt_message($attempt_state);
        } else {
            $id_safe = $conn->real_escape_string($identifier);
            // Search by email or rfid_uid
            $q = $conn->query("SELECT * FROM students WHERE email='$id_safe' OR rfid_uid='$id_safe' LIMIT 1");

            if ($q && $q->num_rows > 0) {
                $s = $q->fetch_assoc();

                // If student has no password set or is_first_login is true, allow login with RFID.
                $is_first_login = empty($s['password_hash']) || !empty($s['is_first_login']);
                if ($is_first_login) {
                    // If it's the first time and they used RFID, log them in
                    if ($identifier === $s['rfid_uid']) {
                        login_attempt_reset('student', $identifier);
                        $_SESSION['student_id'] = $s['id'];
                        $_SESSION['student_name'] = $s['name'];
                        $_SESSION['student_is_first_login'] = true;
                        header('Location: dashboard.php');
                        exit;
                    } else {
                        $error = 'Please use your RFID UID for first-time login.';
                        login_attempt_failed('student', $identifier);
                    }
                } else {
                    if (password_verify($password, $s['password_hash'])) {
                        login_attempt_reset('student', $identifier);
                        $_SESSION['student_id'] = $s['id'];
                        $_SESSION['student_name'] = $s['name'];
                        $_SESSION['student_is_first_login'] = false;
                        header('Location: dashboard.php');
                        exit;
                    } else {
                        $error = 'Invalid credentials.';
                        login_attempt_failed('student', $identifier);
                    }
                }
            } else {
                $error = 'Student not found.';
                login_attempt_failed('student', $identifier);
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
    <title>Student Login – NEUST Gatepass</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
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
                <label for="identifier">Email or RFID UID</label>
                <input id="identifier" name="identifier" type="text" autocomplete="username" required
                    value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" autofocus
                    placeholder="Enter your email or RFID UID ">

                <label for="password">Password</label>
                <div class="pw">
                    <input id="password" name="password" type="password" autocomplete="current-password">
                    <button type="button" class="toggle" aria-pressed="false" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
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
