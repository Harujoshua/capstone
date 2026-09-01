<?php
include('auth.php');
include('../db.php');

$student_id = $_SESSION['student_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $email = $conn->real_escape_string($_POST['email']);
        $conn->query("UPDATE students SET email = '$email' WHERE id = $student_id");
        $success = "Profile updated successfully.";
    } elseif (isset($_POST['change_password'])) {
        $new_pw = $_POST['new_password'];
        $confirm_pw = $_POST['confirm_password'];
        
        if ($new_pw === $confirm_pw && !empty($new_pw)) {
            $hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $conn->query("UPDATE students SET password_hash = '$hash' WHERE id = $student_id");
            $success = "Password changed successfully.";
        } else {
            $error = "Passwords do not match or are empty.";
        }
    }
}

$st_res = $conn->query("SELECT * FROM students WHERE id = $student_id");
$student = $st_res->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Student Portal</title>
    <link rel="stylesheet" href="student_assets/dashboard.css">
    <link rel="stylesheet" href="student_assets/profile.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="dashboard-container">
        <div class="header-section">
            <div class="welcome-text">
                <h1>Account Settings</h1>
                <p>Manage your personal information and security preferences.</p>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-error"><?= $error ?></div>
        <?php endif; ?>

        <div class="profile-grid">
            <div class="profile-card">
                <div class="profile-avatar">
                    <?php if ($student['photo']): ?>
                        <img src="../uploads/students/<?= htmlspecialchars($student['photo']) ?>" alt="Profile">
                    <?php else: ?>
                        <?= strtoupper(substr($student['name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <h2 class="profile-name"><?= htmlspecialchars($student['name']) ?></h2>
                <p class="profile-course"><?= htmlspecialchars($student['course']) ?> - <?= htmlspecialchars($student['year_level']) ?></p>
                <div class="profile-info-list">
                    <div class="info-row"><i class="fa-solid fa-id-card"></i> RFID: <?= htmlspecialchars($student['rfid_uid']) ?></div>
                    <div class="info-row"><i class="fa-solid fa-users"></i> Section: <?= htmlspecialchars($student['section']) ?></div>
                </div>
            </div>

            <div class="profile-forms">
                <div class="card">
                    <h3 class="card-title">Personal Information</h3>
                    <form method="POST">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" class="form-control form-control-readonly" value="<?= htmlspecialchars($student['name']) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($student['email'] ?? '') ?>" placeholder="Enter your email">
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                    </form>
                </div>

                <div class="card">
                    <h3 class="card-title">Security</h3>
                    <p class="security-hint">Update your password to keep your account secure.</p>
                    <form method="POST">
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" class="form-control" placeholder="••••••••">
                        </div>
                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="••••••••">
                        </div>
                        <button type="submit" name="change_password" class="btn btn-outline">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
