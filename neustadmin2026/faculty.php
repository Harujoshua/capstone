<?php
include('../db.php');
include('admin_db.php');
include('auth.php');
include_once('logger.php');

// Fetch current active school year & semester settings
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

// Faculty Permissions Check
$is_super_admin = (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin');
$can_add_faculty          = $is_super_admin || (get_admin_setting($admin_conn, 'subadmin_faculty_add', '1') === '1');
$can_edit_faculty         = $is_super_admin || (get_admin_setting($admin_conn, 'subadmin_faculty_edit', '1') === '1');
$can_delete_faculty       = $is_super_admin || (get_admin_setting($admin_conn, 'subadmin_faculty_delete', '0') === '1');
$can_view_fac_schedules   = $is_super_admin || (get_admin_setting($admin_conn, 'subadmin_faculty_view_schedules', '1') === '1');
$can_add_fac_schedules    = $is_super_admin || (get_admin_setting($admin_conn, 'subadmin_faculty_add_schedules', '1') === '1');

// Load faculty list for selection
$faculty_rows = [];
$fres_all = $conn->query("SELECT id, name, department FROM faculty ORDER BY name ASC");
if ($fres_all) {
  while ($fr = $fres_all->fetch_assoc())
    $faculty_rows[] = $fr;
}

$error = '';
$saved_schedule_msg = '';
$open_modal = false;
$open_add_sched_modal = false;
$edit = null;

function ensure_faculty_columns($conn) {
  $colCheckPhoto = $conn->query("SHOW COLUMNS FROM faculty LIKE 'photo'");
  if (!$colCheckPhoto || $colCheckPhoto->num_rows === 0) {
    $conn->query("ALTER TABLE faculty ADD COLUMN photo VARCHAR(255) DEFAULT NULL");
  }
  $colCheckRfid = $conn->query("SHOW COLUMNS FROM faculty LIKE 'rfid_uid'");
  if (!$colCheckRfid || $colCheckRfid->num_rows === 0) {
    $conn->query("ALTER TABLE faculty ADD COLUMN rfid_uid VARCHAR(100) DEFAULT NULL UNIQUE");
  }
}

function generate_faculty_temporary_password() {
  return 'NeustCarr' . strtoupper(bin2hex(random_bytes(4)));
}

if (isset($_GET['saved_schedule']) && $_GET['saved_schedule'] === '1') {
  $saved_schedule_msg = 'Schedule saved successfully!';
}

$preset_teacher_id = intval($_GET['add_schedule_teacher_id'] ?? 0);
if ($preset_teacher_id > 0) {
  if ($can_add_fac_schedules) {
    $open_add_sched_modal = true;
  } else {
    $preset_teacher_id = 0;
  }
}

// Handle Add Schedule POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_schedule') {
  if (!$can_add_fac_schedules) {
    header('Location: faculty.php');
    exit;
  }
  $course = trim($_POST['course'] ?? '');
  $year_level = trim($_POST['year_level'] ?? '');
  $section = trim($_POST['section'] ?? '');
  $subject = trim($_POST['subject'] ?? '');
  $teacher_id_post = intval($_POST['teacher_id'] ?? 0);
  $teacher_manual = trim($_POST['teacher_manual'] ?? '');
  $day = trim($_POST['day'] ?? '');
  $start = trim($_POST['start_time'] ?? '');
  $end = trim($_POST['end_time'] ?? '');
  $room = trim($_POST['room'] ?? '');
  $assign_all = isset($_POST['assign_all']);

  $days_to_insert = [];
  if (isset($_POST['days']) && is_array($_POST['days']) && !empty($_POST['days'])) {
    foreach ($_POST['days'] as $d_item) {
      $d_trimmed = trim($d_item);
      if ($d_trimmed !== '') $days_to_insert[] = $d_trimmed;
    }
  }
  if (empty($days_to_insert) && $day !== '') {
    $days_to_insert[] = $day;
  }

  if ($course === '' || $subject === '') {
    $error = 'Please provide course and subject.';
    $open_add_sched_modal = true;
    $preset_teacher_id = $teacher_id_post;
  } elseif (empty($days_to_insert)) {
    $error = 'Please select at least one day for the schedule.';
    $open_add_sched_modal = true;
    $preset_teacher_id = $teacher_id_post;
  } elseif ($start === '' || $end === '') {
    $error = 'Please provide both start time and end time for the schedule.';
    $open_add_sched_modal = true;
    $preset_teacher_id = $teacher_id_post;
  } elseif (strtotime($start) >= strtotime($end)) {
    $error = 'Invalid schedule time: End time must be later than start time.';
    $open_add_sched_modal = true;
    $preset_teacher_id = $teacher_id_post;
  } else {
    $c = $conn->real_escape_string($course);
    $y = $conn->real_escape_string($year_level);
    $sec = $conn->real_escape_string($section);
    $s = $conn->real_escape_string($subject);

    if ($teacher_id_post > 0) {
      $fres = $conn->query("SELECT name FROM faculty WHERE id = " . intval($teacher_id_post) . " LIMIT 1");
      $fname = ($fres && $fres->num_rows) ? $fres->fetch_assoc()['name'] : '';
      $t = $fname !== '' ? $conn->real_escape_string($fname) : null;
      $teacher_id_sql_expr = intval($teacher_id_post);
    } else {
      $fname = $teacher_manual;
      $t = $teacher_manual === '' ? null : $conn->real_escape_string($teacher_manual);
      $teacher_id_sql_expr = 'NULL';
    }

    $safe_sy = $conn->real_escape_string($active_school_year);
    $safe_sem = $conn->real_escape_string($active_semester);

    // Conflict Check against existing schedules
    $conflict_found = null;
    foreach ($days_to_insert as $single_day) {
      $conflict_msg = check_schedule_conflict(
        $conn,
        $single_day,
        $start,
        $end,
        $active_school_year,
        $active_semester,
        $teacher_id_post,
        $fname,
        $room,
        $course,
        $year_level,
        $section
      );
      if ($conflict_msg) {
        $conflict_found = $conflict_msg;
        break;
      }
    }

    if ($conflict_found) {
      $error = $conflict_found;
      $open_add_sched_modal = true;
      $preset_teacher_id = $teacher_id_post;
    } else {
      $st = "'" . date('H:i:s', strtotime($start)) . "'";
      $en = "'" . date('H:i:s', strtotime($end)) . "'";
      $r = $room === '' ? 'NULL' : "'" . $conn->real_escape_string($room) . "'";
      $teacher_sql = $t === null ? 'NULL' : "'" . $t . "'";
      $year_sql = $y === '' ? 'NULL' : "'" . $y . "'";
      $section_sql = $sec === '' ? 'NULL' : "'" . $sec . "'";

      $success_count = 0;
      foreach ($days_to_insert as $single_day) {
        $d_str = $conn->real_escape_string($single_day);
        $insert = "INSERT INTO schedules (course, year_level, section, subject, teacher, teacher_id, day, start_time, end_time, room, school_year, semester) VALUES ('$c', $year_sql, $section_sql, '$s', $teacher_sql, " . ($teacher_id_sql_expr === 'NULL' ? 'NULL' : intval($teacher_id_sql_expr)) . ", '$d_str', $st, $en, $r, '$safe_sy', '$safe_sem')";
        if ($conn->query($insert)) {
          $sid = $conn->insert_id;
          $success_count++;
          log_audit('CREATE', 'Schedule', "$s ($c)", "New schedule created for faculty.");
          if ($assign_all) {
            $whereClause = "course = '" . $conn->real_escape_string($course) . "'";
            if ($year_level !== '') {
              $whereClause .= " AND year_level = '" . $conn->real_escape_string($year_level) . "'";
            }
            if ($section !== '') {
              $whereClause .= " AND section = '" . $conn->real_escape_string($section) . "'";
            }
            $res = $conn->query("SELECT id FROM students WHERE " . $whereClause);
            if ($res) {
              while ($row = $res->fetch_assoc()) {
                $stud = (int) $row['id'];
                $conn->query("INSERT IGNORE INTO schedule_students (schedule_id, student_id) VALUES ($sid, $stud)");
              }
            }
          }
        }
      }

      if ($success_count > 0) {
        if (isset($_POST['save_and_another']) && $teacher_id_post > 0) {
          header('Location: faculty.php?add_schedule_teacher_id=' . $teacher_id_post . '&saved_schedule=1');
        } else {
          header('Location: faculty.php?saved_schedule=1');
        }
        exit;
      } else {
        $error = 'Failed to save schedule: ' . $conn->error;
        $open_add_sched_modal = true;
        $preset_teacher_id = $teacher_id_post;
      }
    }
  }
}

// Handle POST-only actions first (delete, load edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'add_schedule') {
  $action = $_POST['action'];
  // Delete via POST only
  if ($action === 'delete' && isset($_POST['id'])) {
    if ($can_delete_faculty) {
      $del = (int) $_POST['id'];
      $res = $conn->query("SELECT name FROM faculty WHERE id=$del")->fetch_assoc();
      $name = $res['name'] ?? 'Unknown';
      $conn->query("DELETE FROM faculty WHERE id = $del LIMIT 1");
      log_audit('DELETE', 'Faculty', $name, "Faculty member deleted.");
    }
    header('Location: faculty.php');
    exit;
  }

  // Load existing faculty for editing
  if ($action === 'edit' && isset($_POST['id']) && empty($_POST['name']) && empty($_POST['email'])) {
    if ($can_edit_faculty) {
      $eid = (int) $_POST['id'];
      $er  = $conn->query("SELECT * FROM faculty WHERE id = $eid LIMIT 1");
      if ($er && $er->num_rows)
        $edit = $er->fetch_assoc();
    } else {
      header('Location: faculty.php');
      exit;
    }
  }
}

// Handle POST create/update faculty
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !(isset($_POST['action']) && ($_POST['action'] === 'edit' || $_POST['action'] === 'add_schedule'))) {
  // Block create if not permitted (update/edit is still allowed via action=edit form)
  $is_new_faculty = (intval($_POST['id'] ?? 0) === 0);
  if ($is_new_faculty && !$can_add_faculty) {
    header('Location: faculty.php');
    exit;
  }
  ensure_faculty_columns($conn);

  $id         = intval($_POST['id'] ?? 0);
  $name       = trim($_POST['name'] ?? '');
  $email      = trim($_POST['email'] ?? '');
  $department = trim($_POST['department'] ?? '');
  $rfid_uid   = trim($_POST['rfid_uid'] ?? '');
  $photo_filename = null;

  if (isset($_FILES['photo']) && isset($_FILES['photo']['error']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upErr = $_FILES['photo']['error'];
    if ($upErr !== UPLOAD_ERR_OK) {
      switch ($upErr) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
          $error = 'Uploaded photo exceeds the server size limit.';
          break;
        case UPLOAD_ERR_PARTIAL:
          $error = 'Photo was only partially uploaded.';
          break;
        case UPLOAD_ERR_NO_TMP_DIR:
          $error = 'Temporary upload folder is missing on the server.';
          break;
        case UPLOAD_ERR_CANT_WRITE:
          $error = 'Failed to write the uploaded photo to disk.';
          break;
        case UPLOAD_ERR_EXTENSION:
          $error = 'File upload was stopped by a PHP extension.';
          break;
        default:
          $error = 'Failed to upload photo file (code ' . intval($upErr) . ').';
      }
    } else {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
      finfo_close($finfo);
      $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

      if (!isset($allowed[$mime])) {
        $error = 'Unsupported image type. Only PNG and JPEG images are allowed.';
      } elseif ($_FILES['photo']['size'] > 4 * 1024 * 1024) {
        $error = 'Image file is too large (max 4MB).';
      } else {
        $uploadDir = __DIR__ . '/../uploads/faculty';
        if (!is_dir($uploadDir)) {
          @mkdir($uploadDir, 0777, true);
        }
        $ext = $allowed[$mime];
        $basename = time() . '_' . uniqid() . '.' . $ext;
        $target = $uploadDir . '/' . $basename;
        if (!@move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
          $error = 'Failed to move uploaded photo. Check the uploads folder permissions.';
        } else {
          $photo_filename = $basename;
        }
      }
    }
  }

  if ($name === '' || $email === '' || $rfid_uid === '') {
    $error = 'Please provide name, email, and RFID card UID.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please provide a valid email address.';
  } elseif ($error !== '') {
    // keep the upload validation error visible and reopen the modal if needed
  } else {
    $name_safe  = $conn->real_escape_string($name);
    $email_safe = $conn->real_escape_string($email);
    $rfid_safe  = $conn->real_escape_string($rfid_uid);
    $dept_safe  = $department !== '' ? "'" . $conn->real_escape_string($department) . "'" : 'NULL';

    $check_sql = "SELECT id FROM faculty WHERE email='{$email_safe}'";
    if ($id) $check_sql .= " AND id!={$id}";
    $check = $conn->query($check_sql . " LIMIT 1");

    $rfid_check_sql = "SELECT id FROM faculty WHERE rfid_uid='{$rfid_safe}'";
    if ($id) $rfid_check_sql .= " AND id!={$id}";
    $rfid_check = $conn->query($rfid_check_sql . " LIMIT 1");

    $stud_rfid_check = $conn->query("SELECT id FROM students WHERE rfid_uid='{$rfid_safe}' LIMIT 1");

    if ($check && $check->num_rows > 0) {
      $error = 'A faculty member with that email already exists.';
    } elseif ($rfid_check && $rfid_check->num_rows > 0) {
      $error = 'A faculty member with that RFID UID already exists.';
    } elseif ($stud_rfid_check && $stud_rfid_check->num_rows > 0) {
      $error = 'That RFID UID is already assigned to a student.';
    } else {
      if ($id) {
        $existing = $conn->query("SELECT * FROM faculty WHERE id={$id} LIMIT 1")->fetch_assoc();
        $updates = [
          "name='{$name_safe}'",
          "email='{$email_safe}'",
          "department={$dept_safe}",
          "rfid_uid='{$rfid_safe}'"
        ];
        if ($photo_filename !== null) {
          $updates[] = "photo='" . $conn->real_escape_string($photo_filename) . "'";
        }
        $sql = "UPDATE faculty SET " . implode(', ', $updates) . " WHERE id={$id} LIMIT 1";
        $conn->query($sql);

        $changes = [];
        if ($existing && $existing['name']       !== $name)       $changes[] = "name from '{$existing['name']}' to '{$name}'";
        if ($existing && $existing['email']      !== $email)      $changes[] = "email from '{$existing['email']}' to '{$email}'";
        if ($existing && $existing['department'] !== $department) $changes[] = "department from '{$existing['department']}' to '{$department}'";
        if ($existing && ($existing['rfid_uid'] ?? '') !== $rfid_uid) $changes[] = "RFID UID from '" . ($existing['rfid_uid'] ?? 'None') . "' to '{$rfid_uid}'";

        $audit_details = "Faculty member details updated.";
        if (!empty($changes)) $audit_details = "Changed " . implode(', ', $changes) . ".";
        log_audit('EDIT', 'Faculty', $name_safe, $audit_details);

        // Clear temp_rfid after successful update
        $conn->query("DELETE FROM temp_rfid");

        if (!empty($photo_filename) && !empty($existing['photo'])) {
          $oldPath = __DIR__ . '/../uploads/faculty/' . $existing['photo'];
          if (file_exists($oldPath)) {
            @unlink($oldPath);
          }
        }
      } else {
        $token  = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + (24 * 60 * 60));
        $temporary_password = generate_faculty_temporary_password();
        $hashed_password = password_hash($temporary_password, PASSWORD_DEFAULT);

        $cols = ['name', 'email', 'department', 'rfid_uid', 'password_hash', 'reset_token', 'token_expiry', 'is_first_login'];
        $vals = ["'{$name_safe}'", "'{$email_safe}'", $dept_safe, "'{$rfid_safe}'", "'{$hashed_password}'", "'{$token}'", "'{$expiry}'", '0'];
        if ($photo_filename !== null) {
          $cols[] = 'photo';
          $vals[] = "'" . $conn->real_escape_string($photo_filename) . "'";
        }

        $sql = "INSERT INTO faculty (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        if ($conn->query($sql)) {
          // Clear temp_rfid after successful registration
          $conn->query("DELETE FROM temp_rfid");
          require_once('../mailer.php');
          send_faculty_setup_email($email, $name, $token, $temporary_password);
          log_audit('CREATE', 'Faculty', $name_safe, "New faculty member registered with RFID: $rfid_safe.");
        } else {
          $error = 'Error creating faculty member: ' . $conn->error;
        }
      }
      if ($error === '') {
        header('Location: faculty.php');
        exit;
      }
    }
  }
}

// Auto-open create modal if server flagged an error on create
$open_modal = ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action']) && intval($_POST['id'] ?? 0) === 0 && $error !== '' && !$open_add_sched_modal);

// Total faculty count for badge
$faculty_count_q = $conn->query("SELECT COUNT(*) AS c FROM faculty");
$faculty_count   = intval($faculty_count_q ? $faculty_count_q->fetch_assoc()['c'] : 0);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Faculty Management — Admin</title>
  <link rel="stylesheet" href="admin_assets/admin_dashboard.css">
  <link rel="stylesheet" href="admin_assets/admin_faculty.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<div class="app">
  <?php include('navbar.php'); ?>

  <main class="content">

    <?php if ($saved_schedule_msg !== ''): ?>
      <div class="toast-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($saved_schedule_msg) ?></div>
    <?php endif; ?>

    <!-- Error (outside modal) -->
    <?php if ($error && !$open_modal && !$open_add_sched_modal): ?>
      <div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($edit): ?>
    <div id="editModal" class="modal open">
      <div class="modal-content">
        <div class="modal-header">
          <h3><i class="fa-solid fa-pen-to-square"></i> Edit Faculty</h3>
          <button type="button" class="modal-close-btn" data-close-edit aria-label="Close">&times;</button>
        </div>
        <p class="modal-subtitle">Update the selected faculty member.</p>

        <form method="POST" action="faculty.php" enctype="multipart/form-data">
          <input type="hidden" name="id" value="<?= intval($edit['id']) ?>">
          <div class="modal-form-group">
            <label for="edit_name">Full Name</label>
            <input id="edit_name" type="text" name="name" required value="<?= htmlspecialchars($edit['name']) ?>" placeholder="e.g. Juan Dela Cruz">
          </div>
          <div class="modal-form-group">
            <label for="edit_email">Email Address</label>
            <input id="edit_email" type="email" name="email" required value="<?= htmlspecialchars($edit['email']) ?>" placeholder="faculty@neust.edu.ph">
          </div>
          <div class="modal-form-group">
            <label for="edit_dept">Program / Department</label>
            <select id="edit_dept" name="department">
              <option value="">Select program</option>
              <option value="BSIT" <?= (isset($edit['department']) && $edit['department'] === 'BSIT') ? 'selected' : '' ?>>Bachelor of Science in Information Technology (BSIT)</option>
              <option value="BEED" <?= (isset($edit['department']) && $edit['department'] === 'BEED') ? 'selected' : '' ?>>Bachelor of Elementary Education (BEED)</option>
              <option value="BSBA" <?= (isset($edit['department']) && $edit['department'] === 'BSBA') ? 'selected' : '' ?>>Bachelor of Science in Business Administration (BSBA)</option>
            </select>
          </div>
          <div class="modal-form-group">
            <label for="edit_rfid">RFID Card UID</label>
            <div class="rfid-input-wrap">
              <input id="edit_rfid" type="text" name="rfid_uid" required placeholder="Tap RFID card or enter UID..." autocomplete="off" value="<?= htmlspecialchars($edit['rfid_uid'] ?? '') ?>">
            </div>
            <div id="edit_rfid_status" class="rfid-status-box">
              <span class="rfid-status-indicator <?= !empty($edit['rfid_uid']) ? 'scanned' : '' ?>">
                <i class="fa-solid fa-id-card"></i>
                <span class="rfid-status-text"><?= !empty($edit['rfid_uid']) ? 'Card: ' . htmlspecialchars($edit['rfid_uid']) : 'Ready to scan card...' ?></span>
              </span>
            </div>
          </div>
          <div class="modal-form-group">
            <label for="edit_photo">Faculty Photo</label>
            <div class="faculty-photo-preview-box">
              <?php if (!empty($edit['photo'])): ?>
                <img id="edit_photo_preview" src="../uploads/faculty/<?= htmlspecialchars($edit['photo']) ?>" alt="<?= htmlspecialchars($edit['name']) ?>" class="faculty-photo-preview">
              <?php else: ?>
                <img id="edit_photo_preview" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180'%3E%3Crect width='100%25' height='100%25' fill='%23f8fafc'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='middle' text-anchor='middle' fill='%239aa6b2' font-size='14'%3ENo photo%3C/text%3E%3C/svg%3E" alt="No photo" class="faculty-photo-preview">
              <?php endif; ?>
            </div>
            <input id="edit_photo" type="file" name="photo" accept="image/jpeg,image/png" class="file-input">
            <small class="file-hint">PNG or JPEG only. Max size: 4MB.</small>
          </div>
          <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-close-edit>Cancel</button>
            <button type="submit" class="btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php
    // Group faculty by department
    $dept_faculty = [
      'BSIT' => [],
      'BEED' => [],
      'BSBA' => [],
      'Unassigned' => []
    ];

    $res = $conn->query("SELECT * FROM faculty ORDER BY name ASC");
    if ($res && $res->num_rows) {
      while ($r = $res->fetch_assoc()) {
        $d = trim($r['department'] ?? '');
        if ($d !== '' && isset($dept_faculty[$d])) {
          $dept_faculty[$d][] = $r;
        } elseif ($d !== '') {
          $dept_faculty[$d][] = $r;
        } else {
          $dept_faculty['Unassigned'][] = $r;
        }
      }
    }
    ?>

    <!-- Department Filter Bar -->
    <div class="dept-filter-bar">
      <div class="dept-tabs">
        <button type="button" class="dept-tab active" data-dept="all" onclick="filterDepartment('all', this)">
          <i class="fa-solid fa-layer-group"></i> All Departments <span class="tab-badge"><?= $faculty_count ?></span>
        </button>
        <?php foreach ($dept_faculty as $dept_name => $members): ?>
          <?php if (!empty($members) || in_array($dept_name, ['BSIT', 'BEED', 'BSBA'])): ?>
            <?php
              $dept_logo_path = '';
              if ($dept_name === 'BSIT') {
                $dept_logo_path = '../assets/CICT.png';
              } elseif ($dept_name === 'BEED') {
                $dept_logo_path = '../assets/COED.png';
              } elseif ($dept_name === 'BSBA') {
                $dept_logo_path = '../assets/BSBA.png';
              }
            ?>
            <button type="button" class="dept-tab" data-dept="<?= htmlspecialchars($dept_name) ?>" onclick="filterDepartment('<?= htmlspecialchars($dept_name) ?>', this)">
              <?php if ($dept_logo_path !== ''): ?>
                <img src="<?= htmlspecialchars($dept_logo_path) ?>" alt="<?= htmlspecialchars($dept_name) ?> logo" class="dept-tab-logo-img">
              <?php endif; ?>
              <?= htmlspecialchars($dept_name) ?> <span class="tab-badge"><?= count($members) ?></span>
            </button>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Department Grid Container -->
    <div class="dept-grid-container">
      <?php
      $has_any = false;
      foreach ($dept_faculty as $dept_name => $members):
        if (!empty($members)) {
          $has_any = true;
        }
        if (empty($members) && !in_array($dept_name, ['BSIT', 'BEED', 'BSBA'], true)) {
          continue;
        }
      ?>
        <div class="dept-card-panel" data-dept-panel="<?= htmlspecialchars($dept_name) ?>">
          <div class="dept-header-banner dept-banner-<?= strtolower($dept_name) ?>">
            <div class="dept-title-group">
              <?php
                $header_logo = '';
                if ($dept_name === 'BSIT') {
                  $header_logo = '../assets/CICT.png';
                } elseif ($dept_name === 'BEED') {
                  $header_logo = '../assets/COED.png';
                } elseif ($dept_name === 'BSBA') {
                  $header_logo = '../assets/BSBA.png';
                }
              ?>
              <?php if ($header_logo !== ''): ?>
                <img src="<?= htmlspecialchars($header_logo) ?>" alt="<?= htmlspecialchars($dept_name) ?> logo" class="dept-logo-img">
              <?php endif; ?>
              <h3><?= htmlspecialchars($dept_name) ?> Department</h3>
            </div>
            <div class="dept-header-actions">
              <span class="dept-member-count"><i class="fa-solid fa-chalkboard-user"></i> <?= count($members) ?> member<?= count($members) !== 1 ? 's' : '' ?></span>
              <?php if ($can_add_faculty && !empty($members) && in_array($dept_name, ['BSIT', 'BEED', 'BSBA'], true)): ?>
                <button type="button" class="btn-primary openCreateBtn" data-dept="<?= htmlspecialchars($dept_name) ?>" title="Add Faculty to <?= htmlspecialchars($dept_name) ?>">
                  <i class="fa-solid fa-plus"></i>
                </button>
              <?php endif; ?>
            </div>
          </div>

          <?php if (empty($members)): ?>
            <div class="dept-empty-state">
              <i class="fa-solid fa-user-slash"></i>
              <p>No faculty yet.</p>
              <?php if ($can_add_faculty): ?>
              <button type="button" class="btn-primary openCreateBtn" data-dept="<?= htmlspecialchars($dept_name) ?>" title="Add Faculty to <?= htmlspecialchars($dept_name) ?>">
                <i class="fa-solid fa-plus"></i>
              </button>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="dept-faculty-grid">
              <?php foreach ($members as $r):
                $initial = strtoupper(substr($r['name'], 0, 1));
              ?>
                <div class="faculty-card">
                  <div class="faculty-card-top">
                    <div class="faculty-avatar">
                      <?php if (!empty($r['photo'])): ?>
                        <img src="../uploads/faculty/<?= htmlspecialchars($r['photo']) ?>" alt="<?= htmlspecialchars($r['name']) ?>" class="faculty-avatar-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <span class="faculty-avatar-text" style="display:none;"><?= htmlspecialchars($initial) ?></span>
                      <?php else: ?>
                        <span class="faculty-avatar-text"><?= htmlspecialchars($initial) ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="faculty-info">
                      <div class="faculty-name"><?= htmlspecialchars($r['name']) ?></div>
                      <div class="faculty-email"><i class="fa-solid fa-envelope" style="margin-right:4px;"></i><?= htmlspecialchars($r['email']) ?></div>
                      <div class="faculty-rfid">
                        <i class="fa-solid fa-id-card"></i>
                        <?= !empty($r['rfid_uid']) ? htmlspecialchars($r['rfid_uid']) : '<span style="color:#94a3b8;font-style:italic;">No RFID Card</span>' ?>
                      </div>
                    </div>
                  </div>
                  <div class="faculty-card-actions">
                    <div class="actions-left">
                      <!-- Edit -->
                      <?php if ($can_edit_faculty): ?>
                      <form method="POST" action="faculty.php" class="table-actions">
                        <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                        <input type="hidden" name="action" value="edit">
                        <button type="submit" class="link-like" title="Edit Faculty"><i class="fa-solid fa-pen"></i></button>
                      </form>
                      <?php endif; ?>
                      <!-- Delete -->
                      <?php if ($can_delete_faculty): ?>
                      <form method="POST" action="faculty.php" class="table-actions" onsubmit="return confirm('Delete this faculty member?')">
                        <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="link-like delete" title="Delete Faculty"><i class="fa-solid fa-trash"></i></button>
                      </form>
                      <?php endif; ?>
                    </div>
                    <div class="actions-right">
                      <!-- View Schedules -->
                      <?php if ($can_view_fac_schedules): ?>
                      <button
                        class="btn-action"
                        data-fid="<?= intval($r['id']) ?>"
                        data-fname="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"
                        onclick="openSchedulesModal(this)"
                        title="View Schedules">
                        <i class="fa-solid fa-calendar-days"></i>
                      </button>
                      <?php endif; ?>
                      <!-- Add Schedule -->
                      <?php if ($can_add_fac_schedules): ?>
                      <button
                        type="button"
                        class="btn-action success"
                        data-fid="<?= intval($r['id']) ?>"
                        data-fname="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"
                        data-fdept="<?= htmlspecialchars($r['department'] ?? '', ENT_QUOTES) ?>"
                        onclick="openAddScheduleModal(this)"
                        title="Add Schedule for <?= htmlspecialchars($r['name']) ?>">
                        <i class="fa-solid fa-plus"></i>
                      </button>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if (!$has_any): ?>
        <div class="faculty-panel" style="padding: 32px; text-align: center; color: #64748b;">
          <i class="fa-solid fa-users-slash" style="font-size:2.2rem;color:#cbd5e1;display:block;margin-bottom:10px;"></i>
          No faculty members found.
        </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- ── Create Faculty Modal ── -->
<?php if ($can_add_faculty): ?>
<div id="createModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i></i> Add Faculty</h3>
      <button id="closeModal" class="modal-close-btn" aria-label="Close">&times;</button>
    </div>
    <p class="modal-subtitle">Fill in the details below to register a new faculty member.</p>

    <?php if ($error && $open_modal): ?>
      <div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="faculty.php" enctype="multipart/form-data">
      <div class="modal-form-group">
        <label for="cf_name">Full Name</label>
        <input id="cf_name" type="text" name="name" required placeholder="e.g. Maria Santos"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      </div>
      <div class="modal-form-group">
        <label for="cf_email">Email Address</label>
        <input id="cf_email" type="email" name="email" required placeholder="faculty@neust.edu.ph"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="modal-form-group">
        <label for="cf_dept">Program / Department</label>
        <select id="cf_dept" name="department">
          <option value="">Select program</option>
          <option value="BSIT" <?= (($_POST['department'] ?? '') === 'BSIT') ? 'selected' : '' ?>>Bachelor of Science in Information Technology (BSIT)</option>
          <option value="BEED" <?= (($_POST['department'] ?? '') === 'BEED') ? 'selected' : '' ?>>Bachelor of Elementary Education (BEED)</option>
          <option value="BSBA" <?= (($_POST['department'] ?? '') === 'BSBA') ? 'selected' : '' ?>>Bachelor of Science in Business Administration (BSBA)</option>
        </select>
      </div>
      <div class="modal-form-group">
        <label for="cf_rfid">RFID Card UID</label>
        <div class="rfid-input-wrap">
          <input id="cf_rfid" type="text" name="rfid_uid" required placeholder="Tap RFID card or enter UID..." autocomplete="off"
                 value="<?= htmlspecialchars($_POST['rfid_uid'] ?? '') ?>">
        </div>
        <div id="cf_rfid_status" class="rfid-status-box">
          <span class="rfid-status-indicator">
            <i class="fa-solid fa-wifi"></i>
            <span class="rfid-status-text">Ready to scan card...</span>
          </span>
        </div>
      </div>
      <div class="modal-form-group">
        <label for="cf_photo">Faculty Photo</label>
        <div class="faculty-photo-preview-box">
          <img id="cf_photo_preview" class="faculty-photo-preview"
               src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180'%3E%3Crect width='100%25' height='100%25' fill='%23f8fafc'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='middle' text-anchor='middle' fill='%239aa6b2' font-size='14'%3ENo photo%3C/text%3E%3C/svg%3E"
               alt="Faculty photo preview">
        </div>
        <input id="cf_photo" type="file" name="photo" accept="image/jpeg,image/png" class="file-input">
        <small class="file-hint">PNG or JPEG only. Max size: 4MB.</small>
      </div>
      <div class="modal-buttons">
        <button type="button" id="cancelModal" class="btn-cancel">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa-solid fa-plus"></i></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<!-- ── Schedules Modal ── -->
<?php if ($can_view_fac_schedules): ?>
<div id="schedulesModal" class="modal">
  <div class="modal-content wide">
    <div class="modal-header">
      <h3 id="schedulesTitle"><i class="fa-solid fa-calendar-days"></i> Schedules</h3>
      <button id="closeSchedules" class="modal-close-btn" aria-label="Close">&times;</button>
    </div>
    <div id="schedulesContent" style="margin-top:12px;">Loading…</div>
  </div>
</div>
<?php endif; ?>

<!-- ── Add Schedule Modal ── -->
<?php if ($can_add_fac_schedules): ?>
<div id="addScheduleModal" class="modal">
  <div class="modal-content wide">
    <div class="modal-header">
      <h3 id="addScheduleTitle"><i class="fa-solid fa-calendar-plus"></i> New Schedule</h3>
      <button id="closeAddSchedule" class="modal-close-btn" aria-label="Close">&times;</button>
    </div>
    <p class="modal-subtitle">Assign a new class schedule to a faculty member.</p>

    <?php if ($error && $open_add_sched_modal): ?>
      <div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div id="as_conflict_alert" class="alert-error" style="display:none;margin-bottom:14px;">
      <i class="fa-solid fa-triangle-exclamation"></i> <span id="as_conflict_text"></span>
    </div>

    <?php
    $f_post_course = ($open_add_sched_modal && isset($_POST['course'])) ? $_POST['course'] : '';
    $f_post_year = ($open_add_sched_modal && isset($_POST['year_level'])) ? $_POST['year_level'] : '';
    $f_post_section = ($open_add_sched_modal && isset($_POST['section'])) ? $_POST['section'] : '';
    $f_post_subject = ($open_add_sched_modal && isset($_POST['subject'])) ? $_POST['subject'] : '';
    $f_post_room = ($open_add_sched_modal && isset($_POST['room'])) ? $_POST['room'] : '';
    $f_post_start = ($open_add_sched_modal && isset($_POST['start_time'])) ? $_POST['start_time'] : '';
    $f_post_end = ($open_add_sched_modal && isset($_POST['end_time'])) ? $_POST['end_time'] : '';
    $f_post_days = ($open_add_sched_modal && isset($_POST['days']) && is_array($_POST['days'])) ? $_POST['days'] : [];
    $f_post_assign = ($open_add_sched_modal && isset($_POST['assign_all']));
    ?>

    <form method="POST" action="faculty.php" id="addScheduleForm">
      <input type="hidden" name="action" value="add_schedule">
      <div class="modal-grid">
        <div>
          <div class="modal-form-group">
            <label for="as_course_select">Course</label>
            <select name="course" id="as_course_select" required>
              <option value="">Select course...</option>
              <option value="BSIT" <?= ($f_post_course === 'BSIT') ? 'selected' : '' ?>>Bachelor of Science in Information Technology (BSIT)</option>
              <option value="BEED" <?= ($f_post_course === 'BEED') ? 'selected' : '' ?>>Bachelor of Elementary Education (BEED)</option>
              <option value="BSBA" <?= ($f_post_course === 'BSBA') ? 'selected' : '' ?>>Bachelor of Science in Business Administration (BSBA)</option>
            </select>
          </div>
          <div class="modal-form-group">
            <label for="as_year_level">Year Level</label>
            <select name="year_level" id="as_year_level">
              <option value="">All years</option>
              <option value="1st Year" <?= ($f_post_year === '1st Year') ? 'selected' : '' ?>>1st Year</option>
              <option value="2nd Year" <?= ($f_post_year === '2nd Year') ? 'selected' : '' ?>>2nd Year</option>
              <option value="3rd Year" <?= ($f_post_year === '3rd Year') ? 'selected' : '' ?>>3rd Year</option>
              <option value="4th Year" <?= ($f_post_year === '4th Year') ? 'selected' : '' ?>>4th Year</option>
            </select>
          </div>
          <div class="modal-form-group">
            <label for="as_section">Section</label>
            <select name="section" id="as_section">
              <option value="">All sections</option>
              <option value="A" <?= ($f_post_section === 'A') ? 'selected' : '' ?>>A</option>
              <option value="B" <?= ($f_post_section === 'B') ? 'selected' : '' ?>>B</option>
            </select>
          </div>
          <div class="modal-form-group">
            <label for="as_subject">Subject</label>
            <input type="text" name="subject" id="as_subject" required value="<?= htmlspecialchars($f_post_subject) ?>" placeholder="e.g. System Integration and Architecture" />
          </div>
        </div>
        <div>
          <div class="modal-form-group">
            <label for="as_teacher_select">Teacher</label>
            <select name="teacher_id" id="as_teacher_select">
              <option value="">Select registered faculty...</option>
              <?php foreach ($faculty_rows as $fr): ?>
                <option value="<?= intval($fr['id']) ?>" data-dept="<?= htmlspecialchars($fr['department'] ?? '') ?>" <?= ($open_add_sched_modal && isset($_POST['teacher_id']) && intval($_POST['teacher_id']) === intval($fr['id'])) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($fr['name']) ?>
                </option>
              <?php endforeach; ?>
              <option value="0" <?= ($open_add_sched_modal && isset($_POST['teacher_id']) && $_POST['teacher_id'] === '0') ? 'selected' : '' ?>>Other (manual)</option>
            </select>
            <input type="text" name="teacher_manual" id="as_teacher_manual" value="<?= htmlspecialchars($_POST['teacher_manual'] ?? '') ?>" placeholder="Enter teacher name" style="<?= ($open_add_sched_modal && isset($_POST['teacher_id']) && $_POST['teacher_id'] === '0') ? '' : 'display: none;' ?> margin-top: 6px;" />
          </div>
          <div class="modal-form-group">
            <label>Days <span style="font-weight:normal;font-size:11px;color:var(--muted);">(Select one or multiple)</span></label>
            <div class="day-chips">
              <?php
              $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
              foreach ($weekdays as $wday):
                $is_wday_chk = in_array($wday, $f_post_days, true);
              ?>
                <label class="day-chip-btn">
                  <input type="checkbox" name="days[]" value="<?= $wday ?>" <?= $is_wday_chk ? 'checked' : '' ?> />
                  <span><?= substr($wday, 0, 3) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="modal-form-group">
            <label for="as_room">Room</label>
            <input type="text" name="room" id="as_room" value="<?= htmlspecialchars($f_post_room) ?>" placeholder="e.g. Lab 102" />
          </div>
          <div class="time-inputs">
            <div class="time-input-col modal-form-group">
              <label for="as_start_time">Start Time</label>
              <input type="time" name="start_time" id="as_start_time" required value="<?= htmlspecialchars($f_post_start) ?>" />
            </div>
            <div class="time-input-col modal-form-group">
              <label for="as_end_time">End Time</label>
              <input type="time" name="end_time" id="as_end_time" required value="<?= htmlspecialchars($f_post_end) ?>" />
            </div>
          </div>

          <div style="margin-top:8px">
            <label style="font-size:13px;cursor:pointer;"><input type="checkbox" name="assign_all" <?= $f_post_assign ? 'checked' : '' ?> /> Assign all students in selected course</label>
          </div>
        </div>
      </div>
      <div class="modal-buttons" style="margin-top:20px;">
        <button type="button" id="cancelAddSchedule" class="btn-cancel">Cancel</button>
        <button type="submit" name="save_and_another" class="btn-ghost-accent"><i class="fa-solid fa-plus-circle"></i> Save &amp; Add Another</button>
        <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Create Schedule</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
  /* ── Department Filter JS ── */
  function filterDepartment(dept, btn) {
    var tabs = document.querySelectorAll('.dept-tab');
    tabs.forEach(function(t) { t.classList.remove('active'); });
    if (btn) btn.classList.add('active');

    var panels = document.querySelectorAll('[data-dept-panel]');
    panels.forEach(function(panel) {
      var pDept = panel.getAttribute('data-dept-panel');
      if (dept === 'all' || pDept === dept) {
        panel.style.display = '';
      } else {
        panel.style.display = 'none';
      }
    });
  }

  /* ── Create Modal Toggle ── */
  (function () {
    var modal      = document.getElementById('createModal');
    var closeBtn   = document.getElementById('closeModal');
    var cancelBtn  = document.getElementById('cancelModal');
    var deptSelect = document.getElementById('cf_dept');
    var openBtns   = document.querySelectorAll('.openCreateBtn');
    var photoInput = document.getElementById('cf_photo');
    var photoPreview = document.getElementById('cf_photo_preview');
    var editModal = document.getElementById('editModal');
    var editPhotoInput = document.getElementById('edit_photo');
    var editPhotoPreview = document.getElementById('edit_photo_preview');

    function attachPhotoPreview(input, preview) {
      if (!input || !preview) return;
      input.addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;

        if (!['image/jpeg', 'image/png'].includes(file.type)) {
          alert('Unsupported image format. Only PNG and JPEG images are allowed.');
          this.value = '';
          return;
        }

        var maxSize = 4 * 1024 * 1024;
        if (file.size > maxSize) {
          alert('Selected image is too large (max 4MB).');
          this.value = '';
          return;
        }

        var reader = new FileReader();
        reader.onload = function (event) {
          preview.src = event.target.result;
        };
        reader.readAsDataURL(file);
      });
    }

    attachPhotoPreview(photoInput, photoPreview);
    attachPhotoPreview(editPhotoInput, editPhotoPreview);

    function show(dept) {
      if (deptSelect && dept) {
        deptSelect.value = dept;
      }
      if (modal) {
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
      }
    }

    function hide() {
      if (modal) {
        modal.classList.remove('open');
      }
      document.body.style.overflow = '';
    }

    openBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        show(btn.getAttribute('data-dept') || '');
      });
    });

    if (closeBtn)  closeBtn.addEventListener('click', hide);
    if (cancelBtn) cancelBtn.addEventListener('click', hide);

    if (modal) {
      modal.addEventListener('click', function (e) { if (e.target === modal) hide(); });
    }

    if (editModal) {
      editModal.addEventListener('click', function (e) { if (e.target === editModal) { editModal.classList.remove('open'); document.body.style.overflow = ''; } });
      document.querySelectorAll('[data-close-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          editModal.classList.remove('open');
          document.body.style.overflow = '';
        });
      });
    }

    // Auto-open if server flagged an error on create
    if (<?= $open_modal ? 'true' : 'false' ?>) show('<?= htmlspecialchars($_POST['department'] ?? '', ENT_QUOTES) ?>');
  })();

  /* ── Schedules Modal ── */
  function openSchedulesModal(btn) {
    var fid     = btn.getAttribute('data-fid');
    var fname   = btn.getAttribute('data-fname');
    var modal   = document.getElementById('schedulesModal');
    var title   = document.getElementById('schedulesTitle');
    var content = document.getElementById('schedulesContent');

    title.innerHTML = '<i class="fa-solid fa-calendar-days"></i> Schedules for ' + fname;
    content.innerHTML = 'Loading…';
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch('get_teacher_schedules.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(fid)
    })
      .then(function (r) { return r.text(); })
      .then(function (html) { content.innerHTML = html; })
      .catch(function () { content.innerHTML = '<div class="modal-error"><i class="fa-solid fa-triangle-exclamation"></i> Failed to load schedules.</div>'; });
  }

  var _closeSchedulesBtn = document.getElementById('closeSchedules');
  var _schedulesModal = document.getElementById('schedulesModal');
  if (_closeSchedulesBtn) {
    _closeSchedulesBtn.addEventListener('click', function () {
      if (_schedulesModal) { _schedulesModal.classList.remove('open'); }
      document.body.style.overflow = '';
    });
  }
  if (_schedulesModal) {
    _schedulesModal.addEventListener('click', function (e) {
      if (e.target === this) { this.classList.remove('open'); document.body.style.overflow = ''; }
    });
  }



  function openAddScheduleModal(btn) {
    var fid = btn.getAttribute('data-fid');
    var fname = btn.getAttribute('data-fname');
    var fdept = btn.getAttribute('data-fdept');
    openAddScheduleModalFromParams(fid, fname, fdept);
  }

  function openAddScheduleModalFromParams(fid, fname, fdept) {
    var modal = document.getElementById('addScheduleModal');
    var title = document.getElementById('addScheduleTitle');
    var teacherSelect = document.getElementById('as_teacher_select');
    var courseSelect = document.getElementById('as_course_select');

    if (title && fname) {
      title.innerHTML = '<i class="fa-solid fa-calendar-plus"></i> New Schedule for ' + fname;
    }
    if (teacherSelect && fid) {
      teacherSelect.value = fid;
    }
    if (courseSelect && fdept) {
      courseSelect.value = fdept;
    }
    if (modal) {
      modal.classList.add('open');
      document.body.style.overflow = 'hidden';
    }
  }

  window.parentOpenAddSchedule = function(fid, fname) {
    var schedModal = document.getElementById('schedulesModal');
    if (schedModal) schedModal.classList.remove('open');
    openAddScheduleModalFromParams(fid, fname, '');
  };

  (function () {
    var modal = document.getElementById('addScheduleModal');
    var closeBtn = document.getElementById('closeAddSchedule');
    var cancelBtn = document.getElementById('cancelAddSchedule');
    var teacherSelect = document.getElementById('as_teacher_select');
    var teacherManual = document.getElementById('as_teacher_manual');

    function hide() {
      if (modal) {
        modal.classList.remove('open');
        document.body.style.overflow = '';
      }
    }

    if (closeBtn) closeBtn.addEventListener('click', hide);
    if (cancelBtn) cancelBtn.addEventListener('click', hide);
    if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) hide(); });

    if (teacherSelect && teacherManual) {
      teacherSelect.addEventListener('change', function () {
        teacherManual.style.display = (teacherSelect.value === '0') ? '' : 'none';
      });
    }

    /* ── Schedule Conflict Real-Time & Submit Validation ── */
    var schedForm = document.getElementById('addScheduleForm');
    var conflictAlert = document.getElementById('as_conflict_alert');
    var conflictText = document.getElementById('as_conflict_text');
    var startTimeInput = document.getElementById('as_start_time');
    var endTimeInput = document.getElementById('as_end_time');
    var courseSelectInput = document.getElementById('as_course_select');
    var yearSelectInput = document.getElementById('as_year_level');
    var sectionSelectInput = document.getElementById('as_section');
    var roomInput = document.getElementById('as_room');
    var checkTimer = null;

    function showConflictWarning(msg) {
      if (conflictAlert && conflictText) {
        conflictText.textContent = msg;
        conflictAlert.style.display = 'block';
      }
    }

    function clearConflictWarning() {
      if (conflictAlert) {
        conflictAlert.style.display = 'none';
      }
    }

    function runConflictCheck() {
      clearTimeout(checkTimer);
      checkTimer = setTimeout(function () {
        var start = startTimeInput ? startTimeInput.value : '';
        var end = endTimeInput ? endTimeInput.value : '';
        if (start && end && start >= end) {
          showConflictWarning('Invalid schedule time: End time must be later than start time.');
          return;
        }

        var checkedDays = [];
        if (schedForm) {
          var dayBoxes = schedForm.querySelectorAll('input[name="days[]"]:checked');
          dayBoxes.forEach(function (cb) { checkedDays.push(cb.value); });
        }

        if (!start || !end || checkedDays.length === 0) {
          clearConflictWarning();
          return;
        }

        var formData = new FormData();
        checkedDays.forEach(function (d) { formData.append('days[]', d); });
        formData.append('start_time', start);
        formData.append('end_time', end);
        formData.append('teacher_id', teacherSelect ? teacherSelect.value : '');
        formData.append('teacher_manual', teacherManual ? teacherManual.value : '');
        formData.append('room', roomInput ? roomInput.value : '');
        formData.append('course', courseSelectInput ? courseSelectInput.value : '');
        formData.append('year_level', yearSelectInput ? yearSelectInput.value : '');
        formData.append('section', sectionSelectInput ? sectionSelectInput.value : '');

        fetch('check_schedule_conflict_ajax.php', {
          method: 'POST',
          body: formData
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data && data.conflict) {
            showConflictWarning(data.message);
          } else {
            clearConflictWarning();
          }
        })
        .catch(function () {});
      }, 300);
    }

    if (schedForm) {
      schedForm.querySelectorAll('input, select').forEach(function (el) {
        el.addEventListener('change', runConflictCheck);
        el.addEventListener('input', runConflictCheck);
      });

      schedForm.addEventListener('submit', function (e) {
        var checkedDays = schedForm.querySelectorAll('input[name="days[]"]:checked');
        if (checkedDays.length === 0) {
          e.preventDefault();
          alert('Please select at least one day for the schedule.');
          return false;
        }
        var start = startTimeInput ? startTimeInput.value : '';
        var end = endTimeInput ? endTimeInput.value : '';
        if (!start || !end) {
          e.preventDefault();
          alert('Please provide both start time and end time.');
          return false;
        }
        if (start >= end) {
          e.preventDefault();
          showConflictWarning('Invalid schedule time: End time must be later than start time.');
          alert('Invalid schedule time: End time must be later than start time.');
          return false;
        }
      });
    }

    <?php if ($open_add_sched_modal): ?>
      openAddScheduleModalFromParams(<?= $preset_teacher_id ?>, '', '');
    <?php endif; ?>
  })();

  // Auto-remove success toast popup after 1 second
  document.addEventListener('DOMContentLoaded', function () {
    var toast = document.querySelector('.toast-success');
    if (toast) {
      setTimeout(function () {
        toast.style.transition = 'opacity 0.35s cubic-bezier(0.16, 1, 0.3, 1), transform 0.35s cubic-bezier(0.16, 1, 0.3, 1)';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-16px) scale(0.96)';
        setTimeout(function () {
          if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 350);
      }, 1000);

      try {
        var url = new URL(window.location.href);
        if (url.searchParams.has('saved_schedule') || url.searchParams.has('saved')) {
          url.searchParams.delete('saved_schedule');
          url.searchParams.delete('saved');
          window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
        }
      } catch (err) {}
    }
  });

  /* ── RFID Polling for Create & Edit Faculty Modals ── */
  (function () {
    var cfRfid = document.getElementById('cf_rfid');
    var cfStatus = document.getElementById('cf_rfid_status');
    var editRfid = document.getElementById('edit_rfid');
    var editStatus = document.getElementById('edit_rfid_status');
    var createModal = document.getElementById('createModal');
    var editModal = document.getElementById('editModal');

    var lastUid = '';
    if (cfRfid && cfRfid.value) lastUid = cfRfid.value.trim();

    async function pollRfid() {
      try {
        var isCreateOpen = createModal && createModal.classList.contains('open');
        var isEditOpen = editModal && editModal.classList.contains('open');

        if (isCreateOpen || isEditOpen) {
          var res = await fetch('get_uid.php?t=' + Date.now());
          var uid = (await res.text()).trim();

          if (uid && uid !== lastUid) {
            lastUid = uid;
            if (isCreateOpen && cfRfid) {
              cfRfid.value = uid;
              if (cfStatus) {
                var ind = cfStatus.querySelector('.rfid-status-indicator');
                var txt = cfStatus.querySelector('.rfid-status-text');
                if (ind) ind.classList.add('scanned');
                if (txt) txt.textContent = 'Card Scanned: ' + uid;
              }
            } else if (isEditOpen && editRfid) {
              editRfid.value = uid;
              if (editStatus) {
                var ind = editStatus.querySelector('.rfid-status-indicator');
                var txt = editStatus.querySelector('.rfid-status-text');
                if (ind) ind.classList.add('scanned');
                if (txt) txt.textContent = 'Card Scanned: ' + uid;
              }
            }
          }
        }
      } catch (err) {
        // Silently catch polling issues
      }
      setTimeout(pollRfid, 1000);
    }

    if (cfRfid) {
      cfRfid.addEventListener('input', function () {
        lastUid = this.value.trim();
      });
    }
    if (editRfid) {
      editRfid.addEventListener('input', function () {
        lastUid = this.value.trim();
      });
    }

    pollRfid();
  })();
</script>
</body>
</html>