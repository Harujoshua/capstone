<?php
// Admin settings page: toggle email sending to parents and manage admin details
include('admin_db.php');
require_once('auth.php');

$adminRole = $_SESSION['admin_role'] ?? 'sub_admin';

if (isset($_GET['cancel_reset'])) {
    unset($_SESSION['admin_reset_otp']);
    unset($_SESSION['admin_reset_time']);
    header('Location: settings.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Update Academic Settings, Notification Settings, and Session Inactivity Timeout (super_admin only)
    if ($adminRole === 'super_admin') {
        if (isset($_POST['update_session_timeout']) || isset($_POST['update_general'])) {
            if (isset($_POST['session_timeout_minutes'])) {
                $timeout_val = intval($_POST['session_timeout_minutes']);
                if ($timeout_val < 1 || $timeout_val > 1440) {
                    $timeout_val = 15;
                }
                $safeTimeoutName = $admin_conn->real_escape_string('session_timeout_minutes');
                $safeTimeoutVal = $admin_conn->real_escape_string((string)$timeout_val);
                $resTimeout = $admin_conn->query("SELECT id FROM settings WHERE name='$safeTimeoutName' LIMIT 1");
                if ($resTimeout && $resTimeout->num_rows > 0) {
                    $admin_conn->query("UPDATE settings SET value='$safeTimeoutVal' WHERE name='$safeTimeoutName' LIMIT 1");
                } else {
                    $admin_conn->query("INSERT INTO settings (name, value) VALUES ('$safeTimeoutName', '$safeTimeoutVal')");
                }
            }
        }

        if (isset($_POST['update_academic']) || isset($_POST['update_general'])) {
            // Active School Year
            if (isset($_POST['active_school_year'])) {
                $sy = trim($_POST['active_school_year']);
                $safeSyName = $admin_conn->real_escape_string('active_school_year');
                $safeSyVal = $admin_conn->real_escape_string($sy);
                $resSy = $admin_conn->query("SELECT id FROM settings WHERE name='$safeSyName' LIMIT 1");
                if ($resSy && $resSy->num_rows > 0) {
                    $admin_conn->query("UPDATE settings SET value='$safeSyVal' WHERE name='$safeSyName' LIMIT 1");
                } else {
                    $admin_conn->query("INSERT INTO settings (name, value) VALUES ('$safeSyName', '$safeSyVal')");
                }
            }

            // Active Semester
            if (isset($_POST['active_semester'])) {
                $sem = trim($_POST['active_semester']);
                $safeSemName = $admin_conn->real_escape_string('active_semester');
                $safeSemVal = $admin_conn->real_escape_string($sem);
                $resSem = $admin_conn->query("SELECT id FROM settings WHERE name='$safeSemName' LIMIT 1");
                if ($resSem && $resSem->num_rows > 0) {
                    $admin_conn->query("UPDATE settings SET value='$safeSemVal' WHERE name='$safeSemName' LIMIT 1");
                } else {
                    $admin_conn->query("INSERT INTO settings (name, value) VALUES ('$safeSemName', '$safeSemVal')");
                }
            }
        }

        if (isset($_POST['update_notifications']) || isset($_POST['update_general'])) {
            // Toggle email_to_parents
            $val = ($_POST['email_to_parents'] ?? '0') === '1' ? '1' : '0';
            $name = 'email_to_parents';
            $safeName = $admin_conn->real_escape_string($name);
            $safeVal = $admin_conn->real_escape_string($val);
            $res = $admin_conn->query("SELECT id FROM settings WHERE name='$safeName' LIMIT 1");
            if ($res && $res->num_rows > 0) {
                $admin_conn->query("UPDATE settings SET value='$safeVal' WHERE name='$safeName' LIMIT 1");
            } else {
                $admin_conn->query("INSERT INTO settings (name, value) VALUES ('$safeName', '$safeVal')");
            }
        }

        if (isset($_POST['update_schedule_permissions']) || isset($_POST['update_general'])) {
            $sched_create = isset($_POST['subadmin_schedule_create']) ? '1' : '0';
            $sched_edit   = isset($_POST['subadmin_schedule_edit']) ? '1' : '0';
            $sched_delete = isset($_POST['subadmin_schedule_delete']) ? '1' : '0';

            set_admin_setting($admin_conn, 'subadmin_schedule_create', $sched_create);
            set_admin_setting($admin_conn, 'subadmin_schedule_edit', $sched_edit);
            set_admin_setting($admin_conn, 'subadmin_schedule_delete', $sched_delete);


        }

        if (isset($_POST['update_student_permissions']) || isset($_POST['update_general'])) {
            $stud_edit   = isset($_POST['subadmin_student_edit']) ? '1' : '0';
            $stud_block  = isset($_POST['subadmin_student_block']) ? '1' : '0';
            $stud_delete = isset($_POST['subadmin_student_delete']) ? '1' : '0';

            set_admin_setting($admin_conn, 'subadmin_student_edit', $stud_edit);
            set_admin_setting($admin_conn, 'subadmin_student_block', $stud_block);
            set_admin_setting($admin_conn, 'subadmin_student_delete', $stud_delete);


        }

        if (isset($_POST['update_faculty_permissions']) || isset($_POST['update_general'])) {
            $fac_edit           = isset($_POST['subadmin_faculty_edit']) ? '1' : '0';
            $fac_delete         = isset($_POST['subadmin_faculty_delete']) ? '1' : '0';
            $fac_view_schedules = isset($_POST['subadmin_faculty_view_schedules']) ? '1' : '0';
            $fac_add_schedules  = isset($_POST['subadmin_faculty_add_schedules']) ? '1' : '0';
            $fac_add_faculty    = isset($_POST['subadmin_faculty_add']) ? '1' : '0';

            set_admin_setting($admin_conn, 'subadmin_faculty_edit', $fac_edit);
            set_admin_setting($admin_conn, 'subadmin_faculty_delete', $fac_delete);
            set_admin_setting($admin_conn, 'subadmin_faculty_view_schedules', $fac_view_schedules);
            set_admin_setting($admin_conn, 'subadmin_faculty_add_schedules', $fac_add_schedules);
            set_admin_setting($admin_conn, 'subadmin_faculty_add', $fac_add_faculty);


        }
    }

    // 2. Update Admin Email
    if (isset($_POST['admin_email'])) {
        $new_email = trim($_POST['admin_email']);
        if (filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $safeEmail = $admin_conn->real_escape_string($new_email);
            // Verify session variable exists, fallback to 'admin' if not
            $adminUsername = $_SESSION['admin_username'] ?? 'admin';
            $safeUsername = $admin_conn->real_escape_string($adminUsername);

            $admin_conn->query("UPDATE admins SET email='$safeEmail' WHERE username='$safeUsername'");

        } elseif ($new_email !== '') {
            $error = 'Invalid email format provided.';
        }
    }

    if (empty($error)) {
        $message = 'Settings saved successfully.';
    }
}

// Password Reset Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Re-fetch email to ensure we send to the latest one
    $adminUsername = $_SESSION['admin_username'] ?? 'admin';
    $safeUsername = $admin_conn->real_escape_string($adminUsername);
    $res = $admin_conn->query("SELECT email FROM admins WHERE username='$safeUsername' LIMIT 1");
    $email_to_send = '';
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $email_to_send = $row['email'];
    }

    if ($_POST['action'] === 'request_password_reset') {
        if (empty($email_to_send)) {
            $error = "No admin email configured. Please set an email first.";
        } else {
            $otp = sprintf("%06d", mt_rand(1, 999999));
            $_SESSION['admin_reset_otp'] = $otp;
            $_SESSION['admin_reset_time'] = time();
            
            require_once '../mailer.php';
            if (send_admin_password_reset_otp($email_to_send, $otp)) {
                $message = "A reset code has been sent to your email: $email_to_send";
            } else {
                $error = "Failed to send reset code. Please check your mailer configuration.";
            }
        }
    } elseif ($_POST['action'] === 'confirm_password_reset') {
        $entered_otp = trim($_POST['otp'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($entered_otp !== (string)($_SESSION['admin_reset_otp'] ?? '')) {
            $error = "Invalid or expired reset code.";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $adminUsername = $_SESSION['admin_username'] ?? 'admin';
            $safeUsername = $admin_conn->real_escape_string($adminUsername);
            
            if ($admin_conn->query("UPDATE admins SET password_hash='$hashed_password' WHERE username='$safeUsername'")) {
                $message = "Password updated successfully.";

                unset($_SESSION['admin_reset_otp']);
                unset($_SESSION['admin_reset_time']);
            } else {
                $error = "Database error: Failed to update password.";
            }
        }
    }
}

// Fetch current settings
$enabled = true;
$q = $admin_conn->query("SELECT value FROM settings WHERE name='email_to_parents' LIMIT 1");
if ($q && $q->num_rows > 0) {
    $r = $q->fetch_assoc();
    $enabled = ($r['value'] === '1' || strtolower((string) $r['value']) === 'true' || $r['value'] === 'on');
}

// Fetch active school year & semester
$active_school_year = '2025-2026';
$q_sy = $admin_conn->query("SELECT value FROM settings WHERE name='active_school_year' LIMIT 1");
if ($q_sy && $q_sy->num_rows > 0) {
    $active_school_year = $q_sy->fetch_assoc()['value'];
}

$active_semester = '1st Semester';
$q_sem = $admin_conn->query("SELECT value FROM settings WHERE name='active_semester' LIMIT 1");
if ($q_sem && $q_sem->num_rows > 0) {
    $active_semester = $q_sem->fetch_assoc()['value'];
}

// Fetch session timeout
$session_timeout_minutes = 15;
$q_timeout = $admin_conn->query("SELECT value FROM settings WHERE name='session_timeout_minutes' LIMIT 1");
if ($q_timeout && $q_timeout->num_rows > 0) {
    $session_timeout_minutes = intval($q_timeout->fetch_assoc()['value']);
    if ($session_timeout_minutes < 1) $session_timeout_minutes = 15;
}

// Fetch current admin email
$adminUsername = $_SESSION['admin_username'] ?? 'admin';
$safeUsername = $admin_conn->real_escape_string($adminUsername);
$current_email = '';
$res = $admin_conn->query("SELECT email FROM admins WHERE username='$safeUsername' LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $current_email = $row['email'];
}

// Fetch Sub-Admin Action Permissions
$subadmin_schedule_create = (get_admin_setting($admin_conn, 'subadmin_schedule_create', '1') === '1');
$subadmin_schedule_edit   = (get_admin_setting($admin_conn, 'subadmin_schedule_edit', '1') === '1');
$subadmin_schedule_delete = (get_admin_setting($admin_conn, 'subadmin_schedule_delete', '0') === '1');

$subadmin_student_edit    = (get_admin_setting($admin_conn, 'subadmin_student_edit', '1') === '1');
$subadmin_student_block   = (get_admin_setting($admin_conn, 'subadmin_student_block', '1') === '1');
$subadmin_student_delete  = (get_admin_setting($admin_conn, 'subadmin_student_delete', '0') === '1');

$subadmin_faculty_edit           = (get_admin_setting($admin_conn, 'subadmin_faculty_edit', '1') === '1');
$subadmin_faculty_delete         = (get_admin_setting($admin_conn, 'subadmin_faculty_delete', '0') === '1');
$subadmin_faculty_view_schedules = (get_admin_setting($admin_conn, 'subadmin_faculty_view_schedules', '1') === '1');
$subadmin_faculty_add_schedules  = (get_admin_setting($admin_conn, 'subadmin_faculty_add_schedules', '1') === '1');
$subadmin_faculty_add            = (get_admin_setting($admin_conn, 'subadmin_faculty_add', '1') === '1');

include('navbar.php');
?>
<link rel="stylesheet" href="admin_assets/admin_settings.css">

<div class="container">
    <!-- Floating Toast Notifications -->
    <?php if ($message || $error): ?>
        <div class="toast-container">
            <?php if ($message): ?>
                <div class="message-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($adminRole !== 'super_admin'): ?>
    <!-- Notice for Sub-Admins -->
    <div class="subadmin-locked-notice">
        <div>
            <strong>Site Supervisor Access:</strong> Global academic terms, timeout limits, and sub-admin action permissions are managed exclusively by the Super Administrator. You can update your own account security credentials below.
        </div>
    </div>
    <?php endif; ?>

    <!-- Top Search Bar -->
    <div class="settings-search-top">
        <div class="search-settings-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="settingSearch" placeholder="Search settings, terms, or permissions..." onkeyup="filterSettings(this.value)">
        </div>
    </div>

    <!-- Category Segmented Tabs -->
    <div class="tabs-container">
        <button type="button" class="tab-btn active" onclick="switchCategory('all', this)">
            <span class="tab-text">All Settings</span> <span class="tab-badge"><?= ($adminRole === 'super_admin') ? '5' : '1' ?></span>
        </button>
        <?php if ($adminRole === 'super_admin'): ?>
        <button type="button" class="tab-btn" onclick="switchCategory('academic', this)">
            <span class="tab-text">Academic & Term</span>
        </button>
        <button type="button" class="tab-btn" onclick="switchCategory('permissions', this)">
            <span class="tab-text">Role Permissions</span> <span class="tab-badge" style="background:#0d9488;color:#fff;">11</span>
        </button>
        <button type="button" class="tab-btn" onclick="switchCategory('session', this)">
            <span class="tab-text">Session & Security</span>
        </button>
        <button type="button" class="tab-btn" onclick="switchCategory('notifications', this)">
            <span class="tab-text">Email Gateway</span>
        </button>
        <?php endif; ?>
        <button type="button" class="tab-btn" onclick="switchCategory('account', this)">
            <span class="tab-text">Admin Account</span>
        </button>
    </div>

    <?php if ($adminRole === 'super_admin'): ?>
    <!-- Primary Unified Super-Admin Settings Form -->
    <form method="post" action="settings.php" id="settingsGeneralForm">
        <input type="hidden" name="update_general" value="1">
        <input type="hidden" name="email_to_parents" id="hidden_email_to_parents" value="<?= $enabled ? '1' : '0' ?>">
        <input type="hidden" name="session_timeout_minutes" id="hidden_session_timeout" value="<?= htmlspecialchars((string)$session_timeout_minutes) ?>">

        <div class="settings-grid" id="settingsContainer">

            <!-- Card 1: Academic Settings -->
            <div class="setting-card col-6" data-category="academic">
                <div class="card-header-flex">
                    <div class="card-title-group">
                        <div>
                            <h3>Academic Configuration</h3>
                            <p>Active university school year & current semester term</p>
                        </div>
                    </div>
                    <span class="card-badge badge-live">Term Active</span>
                </div>

                <div class="form-grid-2">
                    <div class="input-group">
                        <label class="input-label" for="active_school_year">School Year</label>
                        <input type="text" id="active_school_year" name="active_school_year" class="custom-input" value="<?= htmlspecialchars($active_school_year) ?>" placeholder="e.g. 2025-2026" required oninput="markDirty()">
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="active_semester">Active Semester</label>
                        <select id="active_semester" name="active_semester" class="custom-select" required onchange="markDirty()">
                            <option value="1st Semester" <?= ($active_semester === '1st Semester') ? 'selected' : '' ?>>1st Semester</option>
                            <option value="2nd Semester" <?= ($active_semester === '2nd Semester') ? 'selected' : '' ?>>2nd Semester</option>
                            <option value="Summer" <?= ($active_semester === 'Summer') ? 'selected' : '' ?>>Summer Term</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                    <div style="font-size:0.78rem; color:#166534; display:flex; align-items:center; gap:8px;">
                        <span>Active Term: <strong><?= htmlspecialchars($active_school_year) ?> • <?= htmlspecialchars($active_semester) ?></strong></span>
                    </div>
                    <span style="font-size:0.72rem; color:#15803d; font-weight:700;">ENROLLED IN DATABASE</span>
                </div>

                <div class="card-actions">
                    <button type="submit" name="update_academic" value="1" class="btn btn-primary btn-sm">Save Academic</button>
                </div>
            </div>

            <!-- Card 2: Parent Email Notifications Gateway -->
            <div class="setting-card col-6" data-category="notifications">
                <div class="card-header-flex">
                    <div class="card-title-group">
                        <div>
                            <h3>Email Alerts & Gateway</h3>
                            <p>Automated gatepass tap notifications to registered parents</p>
                        </div>
                    </div>
                    <span class="card-badge badge-live">SMTP Ready</span>
                </div>

                <div class="toggle-item" style="padding: 16px;">
                    <div class="toggle-text">
                        <span class="toggle-title">
                            Parent Email Notifications
                            <span class="perm-state-tag <?= $enabled ? 'allowed' : 'denied' ?>" id="emailTag">
                                <?= $enabled ? 'ACTIVE' : 'PAUSED' ?>
                            </span>
                        </span>
                        <span class="toggle-desc">Automatically dispatch instant timestamped email receipts when student taps RFID in/out</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="emailToggle" <?= $enabled ? 'checked' : '' ?> onchange="toggleEmailService(this)">
                        <span class="slider"></span>
                    </label>
                </div>

                <div style="display:flex; align-items:center; justify-content:space-between; margin-top:12px; font-size:0.8rem; color:var(--text-sub); flex-wrap:wrap; gap:8px;">
                    <span>Service: <strong>PHPMailer (OAuth/SMTP)</strong></span>
                    <button type="submit" name="update_notifications" value="1" class="btn btn-primary btn-sm">Save Notifications</button>
                </div>
            </div>

            <!-- Card 3: Session Inactivity Timeout -->
            <div class="setting-card col-5" data-category="session">
                <div class="card-header-flex">
                    <div class="card-title-group">
                        <div>
                            <h3>Session Inactivity Timeout</h3>
                            <p>Enforce automatic logout during inactivity</p>
                        </div>
                    </div>
                </div>

                <label class="input-label">Select Timeout Threshold</label>
                <div class="timeout-pills">
                    <?php
                    $thresholds = [
                        5 => 'Strict',
                        10 => 'Balanced',
                        15 => 'Standard',
                        20 => 'Extended',
                        30 => 'Relaxed',
                        60 => 'Maximum'
                    ];
                    foreach ($thresholds as $mins => $label):
                        $isActive = ($session_timeout_minutes === $mins);
                    ?>
                    <div class="duration-pill <?= $isActive ? 'active' : '' ?>" onclick="selectDuration(<?= $mins ?>, this)">
                        <strong><?= $mins ?>m</strong>
                        <span><?= $label ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="background: var(--surface-muted); border-radius: 10px; padding: 12px; margin-top: 16px; border: 1px solid var(--border);">
                    <div style="font-size: 0.8rem; color: var(--text-sub); line-height: 1.45;">
                        Users will see a warning modal <strong>60 seconds</strong> before expiration. Applies across Admin, Faculty, and Student portals.
                    </div>
                </div>

                <div class="card-actions">
                    <button type="submit" name="update_session_timeout" value="1" class="btn btn-primary btn-sm">Save Timeout</button>
                </div>
            </div>

            <!-- Card 4: Sub-Admin Action Permissions Matrix -->
            <div class="setting-card col-7" data-category="permissions">
                <div class="card-header-flex">
                    <div class="card-title-group">
                        <div>
                            <h3>Sub-Admin Action Controls</h3>
                            <p>Granular privileges for assistant supervisors and site admins</p>
                        </div>
                    </div>
                </div>

                <!-- Presets -->
                <div class="preset-strip">
                    <span>Quick Permission Presets:</span>
                    <div class="preset-btn-group">
                        <button type="button" class="btn-preset" onclick="applyPreset('standard')">Standard</button>
                        <button type="button" class="btn-preset" onclick="applyPreset('full')">Full Access</button>
                        <button type="button" class="btn-preset" onclick="applyPreset('readonly')">Strict Read-Only</button>
                    </div>
                </div>

                <!-- Class Schedules -->
                <div class="permission-section-header">
                    <span>Class Schedules</span>
                    <span style="font-size:0.7rem; color:var(--text-muted); font-weight:500;">3 Permissions</span>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Create Class Schedules <span class="perm-state-tag <?= $subadmin_schedule_create ? 'allowed' : 'denied' ?>" id="tag_sched_create"><?= $subadmin_schedule_create ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to add new subject class schedules</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_sched_create" name="subadmin_schedule_create" value="1" <?= $subadmin_schedule_create ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_sched_create')">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Edit Schedules (Actions) <span class="perm-state-tag <?= $subadmin_schedule_edit ? 'allowed' : 'denied' ?>" id="tag_sched_edit"><?= $subadmin_schedule_edit ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to edit schedule details</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_sched_edit" name="subadmin_schedule_edit" value="1" <?= $subadmin_schedule_edit ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_sched_edit')">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Delete Schedules (Actions) <span class="perm-state-tag <?= $subadmin_schedule_delete ? 'allowed' : 'denied' ?>" id="tag_sched_delete"><?= $subadmin_schedule_delete ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to delete schedules</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_sched_delete" name="subadmin_schedule_delete" value="1" <?= $subadmin_schedule_delete ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_sched_delete')">
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- Student Controls -->
                <div class="permission-section-header" style="margin-top: 18px;">
                    <span>Student Actions</span>
                    <span style="font-size:0.7rem; color:var(--text-muted); font-weight:500;">3 Permissions</span>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Edit Student (Actions) <span class="perm-state-tag <?= $subadmin_student_edit ? 'allowed' : 'denied' ?>" id="tag_stud_edit"><?= $subadmin_student_edit ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to edit student information</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_stud_edit" name="subadmin_student_edit" value="1" <?= $subadmin_student_edit ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_stud_edit')">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Block Student (Actions) <span class="perm-state-tag <?= $subadmin_student_block ? 'allowed' : 'denied' ?>" id="tag_stud_block"><?= $subadmin_student_block ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to block/unblock students</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_stud_block" name="subadmin_student_block" value="1" <?= $subadmin_student_block ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_stud_block')">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Delete Student (Actions) <span class="perm-state-tag <?= $subadmin_student_delete ? 'allowed' : 'denied' ?>" id="tag_stud_delete"><?= $subadmin_student_delete ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to delete student records</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_stud_delete" name="subadmin_student_delete" value="1" <?= $subadmin_student_delete ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_stud_delete')">
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- Faculty Controls -->
                <div class="permission-section-header" style="margin-top: 18px;">
                    <span>Faculty Actions</span>
                    <span style="font-size:0.7rem; color:var(--text-muted); font-weight:500;">5 Permissions</span>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Add Faculty <span class="perm-state-tag <?= $subadmin_faculty_add ? 'allowed' : 'denied' ?>" id="tag_fac_add"><?= $subadmin_faculty_add ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to add new faculty members</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_fac_add" name="subadmin_faculty_add" value="1" <?= $subadmin_faculty_add ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_fac_add')">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Edit Faculty <span class="perm-state-tag <?= $subadmin_faculty_edit ? 'allowed' : 'denied' ?>" id="tag_fac_edit"><?= $subadmin_faculty_edit ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to edit faculty information</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_fac_edit" name="subadmin_faculty_edit" value="1" <?= $subadmin_faculty_edit ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_fac_edit')">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Delete Faculty <span class="perm-state-tag <?= $subadmin_faculty_delete ? 'allowed' : 'denied' ?>" id="tag_fac_delete"><?= $subadmin_faculty_delete ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to delete faculty records</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_fac_delete" name="subadmin_faculty_delete" value="1" <?= $subadmin_faculty_delete ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_fac_delete')">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">View Faculty Schedules <span class="perm-state-tag <?= $subadmin_faculty_view_schedules ? 'allowed' : 'denied' ?>" id="tag_fac_view"><?= $subadmin_faculty_view_schedules ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to view a faculty member's schedules</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_fac_view" name="subadmin_faculty_view_schedules" value="1" <?= $subadmin_faculty_view_schedules ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_fac_view')">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-text">
                        <span class="toggle-title">Add Faculty Schedules <span class="perm-state-tag <?= $subadmin_faculty_add_schedules ? 'allowed' : 'denied' ?>" id="tag_fac_sched"><?= $subadmin_faculty_add_schedules ? 'ALLOWED' : 'RESTRICTED' ?></span></span>
                        <span class="toggle-desc">Allow sub-admins to add schedules to a faculty member</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="perm_fac_sched" name="subadmin_faculty_add_schedules" value="1" <?= $subadmin_faculty_add_schedules ? 'checked' : '' ?> onchange="updatePermTag(this, 'tag_fac_sched')">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="card-actions">
                    <button type="submit" name="update_schedule_permissions" value="1" class="btn btn-primary btn-sm">Save Permissions</button>
                </div>
            </div>

        </div><!-- /.settings-grid -->
    </form>
    <?php endif; ?>

    <!-- Card 5: Account & Authentication (Email & Password Reset) -->
    <div class="settings-grid" id="accountSecurityContainer">
        <div class="setting-card col-6" data-category="account" style="margin-top: 10px;">
            <div class="card-header-flex">
                <div class="card-title-group">
                    <div>
                        <h3>Account & Authentication</h3>
                        <p>Manage registered email and 2-step verification credentials</p>
                    </div>
                </div>
                <?php if (!empty($current_email)): ?>
                    <span class="status-badge badge-active">Configured</span>
                <?php else: ?>
                    <span class="status-badge badge-inactive">Not Set</span>
                <?php endif; ?>
            </div>

            <!-- Email Update Form -->
            <form method="post" action="settings.php">
                <div class="input-group">
                    <label class="input-label" for="admin_email">
                        <span>Registered Notification Email</span>
                        <span style="font-size:0.75rem; color:var(--text-muted); font-weight:500;">Used for 2FA Password Resets</span>
                    </label>
                    <div style="display:flex; gap:10px;">
                        <input type="email" id="admin_email" name="admin_email" class="custom-input" value="<?= htmlspecialchars($current_email) ?>" placeholder="admin@example.com" required>
                        <button type="submit" class="btn btn-primary btn-sm">Update Email</button>
                    </div>
                </div>
            </form>

            <hr class="card-divider">

            <!-- Password Reset Section -->
            <div style="margin-top: 6px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                    <div>
                        <strong style="font-size: 0.95rem; color: var(--text-main); display: block;">Change Security Password</strong>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Secure verification via 6-digit email OTP</span>
                    </div>
                </div>

                <?php if (!isset($_SESSION['admin_reset_otp'])): ?>
                    <div style="background: var(--surface-muted); border-radius: 12px; padding: 16px; border: 1px solid var(--border);">
                        <p style="margin-bottom: 14px; font-size: 0.86rem; color: var(--text-sub); line-height: 1.5;">
                            To change your password, we will send a 6-digit verification code to your registered email address <strong>(<?= htmlspecialchars($current_email ?: 'No email configured') ?>)</strong>.
                        </p>
                        <form method="post" action="settings.php">
                            <input type="hidden" name="action" value="request_password_reset">
                            <button type="submit" class="btn btn-secondary btn-sm" <?= empty($current_email) ? 'disabled title="Please set an email first"' : '' ?>>
                                Send Verification Code
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Step 2: Confirm OTP & New Password -->
                    <form method="post" action="settings.php" style="background: var(--surface-muted); border-radius: 12px; padding: 18px; border: 1px solid var(--border);">
                        <input type="hidden" name="action" value="confirm_password_reset">

                        <div class="input-group" style="max-width: 220px; margin-bottom: 14px;">
                            <label class="input-label" for="otp">Verification Code</label>
                            <input type="text" id="otp" name="otp" placeholder="000000" maxlength="6" required class="custom-input" style="text-align:center; font-family:'JetBrains Mono', monospace; font-size:1.2rem; letter-spacing:0.3em; font-weight:700;">
                        </div>

                        <div class="form-grid-2" style="margin-bottom: 14px;">
                            <div class="input-group">
                                <label class="input-label" for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="custom-input" required placeholder="Min 6 characters">
                            </div>
                            <div class="input-group">
                                <label class="input-label" for="confirm_password">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="custom-input" required placeholder="Re-enter password">
                            </div>
                        </div>

                        <div style="display:flex; align-items:center; gap:12px; margin-top:14px;">
                            <button type="submit" class="btn btn-primary btn-sm">Update Password</button>
                            <a href="settings.php?cancel_reset=1" class="cancel-link">Cancel</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Floating Sticky Action Bar for Unsaved Changes -->
    <div class="floating-action-bar hidden" id="saveActionBar">
        <div class="floating-info">
            <div class="pulse-indicator"></div>
            <div>
                <h4>Unsaved Configuration Changes</h4>
                <p>You have modified settings that need to be committed to the database.</p>
            </div>
        </div>
        <div style="display:flex; gap:10px;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="discardChanges()">
                Discard
            </button>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveAllChanges()">
                Save All Changes
            </button>
        </div>
    </div>
</div>

<script>
let isDirty = false;

function markDirty() {
    isDirty = true;
    const bar = document.getElementById('saveActionBar');
    if (bar) bar.classList.remove('hidden');
}

function discardChanges() {
    isDirty = false;
    window.location.reload();
}

function saveAllChanges() {
    isDirty = false;
    const form = document.getElementById('settingsGeneralForm');
    if (form) {
        form.submit();
    }
}

function selectDuration(mins, el) {
    document.querySelectorAll('.duration-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    const hiddenInput = document.getElementById('hidden_session_timeout');
    if (hiddenInput) {
        hiddenInput.value = mins;
    }
    markDirty();
}

function toggleEmailService(cb) {
    const tag = document.getElementById('emailTag');
    const hiddenInput = document.getElementById('hidden_email_to_parents');
    if (cb.checked) {
        if (tag) {
            tag.innerText = 'ACTIVE';
            tag.className = 'perm-state-tag allowed';
        }
        if (hiddenInput) hiddenInput.value = '1';
    } else {
        if (tag) {
            tag.innerText = 'PAUSED';
            tag.className = 'perm-state-tag denied';
        }
        if (hiddenInput) hiddenInput.value = '0';
    }
    markDirty();
}

function updatePermTag(cb, tagId) {
    const tag = document.getElementById(tagId);
    if (tag) {
        if (cb.checked) {
            tag.innerText = 'ALLOWED';
            tag.className = 'perm-state-tag allowed';
        } else {
            tag.innerText = 'RESTRICTED';
            tag.className = 'perm-state-tag denied';
        }
    }
    markDirty();
}

function applyPreset(type) {
    markDirty();
    const schedCreate = document.getElementById('perm_sched_create');
    const schedEdit   = document.getElementById('perm_sched_edit');
    const schedDel    = document.getElementById('perm_sched_delete');

    const studEdit    = document.getElementById('perm_stud_edit');
    const studBlock   = document.getElementById('perm_stud_block');
    const studDel     = document.getElementById('perm_stud_delete');

    const facAdd      = document.getElementById('perm_fac_add');
    const facEdit     = document.getElementById('perm_fac_edit');
    const facDel      = document.getElementById('perm_fac_delete');
    const facView     = document.getElementById('perm_fac_view');
    const facSched    = document.getElementById('perm_fac_sched');

    if (type === 'standard') {
        if (schedCreate) schedCreate.checked = true;
        if (schedEdit) schedEdit.checked = true;
        if (schedDel) schedDel.checked = false;

        if (studEdit) studEdit.checked = true;
        if (studBlock) studBlock.checked = true;
        if (studDel) studDel.checked = false;

        if (facAdd) facAdd.checked = true;
        if (facEdit) facEdit.checked = true;
        if (facDel) facDel.checked = false;
        if (facView) facView.checked = true;
        if (facSched) facSched.checked = true;
    } else if (type === 'full') {
        [schedCreate, schedEdit, schedDel, studEdit, studBlock, studDel, facAdd, facEdit, facDel, facView, facSched].forEach(c => {
            if (c) c.checked = true;
        });
    } else if (type === 'readonly') {
        [schedCreate, schedEdit, schedDel, studEdit, studBlock, studDel, facAdd, facEdit, facDel, facSched].forEach(c => {
            if (c) c.checked = false;
        });
        if (facView) facView.checked = true;
    }

    // Sync visual tags
    if (schedCreate) updatePermTag(schedCreate, 'tag_sched_create');
    if (schedEdit) updatePermTag(schedEdit, 'tag_sched_edit');
    if (schedDel) updatePermTag(schedDel, 'tag_sched_delete');

    if (studEdit) updatePermTag(studEdit, 'tag_stud_edit');
    if (studBlock) updatePermTag(studBlock, 'tag_stud_block');
    if (studDel) updatePermTag(studDel, 'tag_stud_delete');

    if (facAdd) updatePermTag(facAdd, 'tag_fac_add');
    if (facEdit) updatePermTag(facEdit, 'tag_fac_edit');
    if (facDel) updatePermTag(facDel, 'tag_fac_delete');
    if (facView) updatePermTag(facView, 'tag_fac_view');
    if (facSched) updatePermTag(facSched, 'tag_fac_sched');
}

function switchCategory(cat, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const cards = document.querySelectorAll('.setting-card');
    cards.forEach(card => {
        if (cat === 'all' || card.getAttribute('data-category') === cat) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

function filterSettings(query) {
    const q = query.toLowerCase();
    const cards = document.querySelectorAll('.setting-card');
    cards.forEach(card => {
        const text = card.innerText.toLowerCase();
        if (text.includes(q)) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    // Auto fadeout toasts
    const toasts = document.querySelectorAll('.message-success, .message-error');
    toasts.forEach(function (toast) {
        setTimeout(function () {
            toast.classList.add('toast-hiding');
            setTimeout(function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 350);
        }, 3200);
    });

    // Detect changes across forms
    const forms = document.querySelectorAll('#settingsGeneralForm input, #settingsGeneralForm select');
    forms.forEach(input => {
        input.addEventListener('change', markDirty);
    });
});
</script>