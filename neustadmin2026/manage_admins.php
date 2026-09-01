<?php
include('admin_db.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security: Only Super Admins can access this page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || ($_SESSION['admin_role'] ?? 'sub_admin') !== 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

// Handle Account Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    if (empty($username) || empty($email) || empty($password)) {
        $error = "All fields are required.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $admin_conn->prepare("INSERT INTO admins (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $email, $hashed_password, $role);
        
        if ($stmt->execute()) {
            include_once('logger.php');
            log_audit('CREATE', 'Admin', $username, "New admin account created with role: $role");
            $message = "New admin account created successfully!";
        } else {
            $error = "Error: Username might already exist.";
        }
        $stmt->close();
    }
}

// Handle Account Deletion
if (isset($_GET['delete'])) {
    $admin_id = $_GET['delete'];
    $current_user = $_SESSION['admin_username'];
    
    // Prevent self-deletion
    $check = $admin_conn->prepare("SELECT username FROM admins WHERE username = ?");
    $check->bind_param("s", $current_user);
    $check->execute();
    $res = $check->get_result()->fetch_assoc();
    
    if ($res && $current_user !== $admin_id) {
        $stmt = $admin_conn->prepare("DELETE FROM admins WHERE username = ?");
        $stmt->bind_param("s", $admin_id);
        if ($stmt->execute()) {
            include_once('logger.php');
            log_audit('DELETE', 'Admin', $admin_id, "Admin account removed.");
            $message = "Admin account removed.";
        }
        $stmt->close();
    } else {
        $error = "You cannot delete your own account.";
    }
}

// Fetch all admins
$admins = $admin_conn->query("SELECT username, email, role FROM admins ORDER BY role DESC");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management - Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="admin_assets/admin_sidebar.css">
    <link rel="stylesheet" href="admin_assets/admin_settings.css">
    <style>
        .grid-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
        @media (max-width: 900px) { .grid-2col { grid-template-columns: 1fr; } }
        .table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #e2e8f0; margin-top: 16px; }
        .admin-list-table { width: 100%; border-collapse: collapse; background: #fff; font-size: 0.9rem; }
        .admin-list-table th, .admin-list-table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .admin-list-table th { background: #f8fafc; font-weight: 600; color: #475569; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        .role-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .role-super { background: #dcfce7; color: #166534; }
        .role-sub { background: #f1f5f9; color: #475569; }
        .btn-delete { color: #dc2626; text-decoration: none; font-size: 0.85rem; font-weight: 600; }
        .btn-delete:hover { text-decoration: underline; }
        @media (max-width: 640px) {
            .admin-list-table th, .admin-list-table td { padding: 10px 12px; font-size: 0.85rem; }
        }
    </style>
</head>
<body>
    <div class="app">
        <?php include('navbar.php'); ?>

        <main class="content" style="margin-top: 30px;">


            <?php if ($message): ?><div class="message-success" style="padding: 12px 16px; background: #dcfce7; color: #166534; border-radius: 8px; margin-bottom: 16px; font-weight: 600;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="message-error" style="padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 16px; font-weight: 600;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="grid-2col">

                <div class="settings-section" style="background: #fff; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0;">
                    <h3 style="margin-top: 0; margin-bottom: 16px; color: #0f172a;">Add New Supervisor</h3>
                    <form method="post" style="display: grid; gap: 16px; width: 100%;">
                        <input type="hidden" name="add_admin" value="1">
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 6px;">
                            <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Username</label>
                            <input type="text" name="username" required style="padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none;">
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 6px;">
                            <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Email</label>
                            <input type="email" name="email" required style="padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none;">
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 6px;">
                            <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Password</label>
                            <input type="password" name="password" required style="padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none;">
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 6px;">
                            <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Role</label>
                            <select name="role" style="padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none; background: #fff;">
                                <option value="sub_admin">Supervisor (Restricted)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn" style="padding: 12px; background: #6366f1; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 8px;">Create Account</button>
                    </form>
                </div>

                <div class="settings-section" style="background: #fff; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0;">
                    <h3 style="margin-top: 0; margin-bottom: 8px; color: #0f172a;">Existing Accounts</h3>
                    <div class="table-wrap">
                        <table class="admin-list-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $admins->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['username']) ?></strong></td>
                                    <td><?= htmlspecialchars($row['email']) ?></td>
                                    <td>
                                        <span class="role-badge <?= $row['role'] === 'super_admin' ? 'role-super' : 'role-sub' ?>">
                                            <?= $row['role'] === 'super_admin' ? 'Super Admin' : 'Supervisor' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($row['username'] !== $_SESSION['admin_username']): ?>
                                            <a href="?delete=<?= urlencode($row['username']) ?>" class="btn-delete" onclick="return confirm('Are you sure?')"><i class="fa-solid fa-trash-can"></i> Remove</a>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 0.8rem;">(You)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </main>
    </div>
</body>
</html>
