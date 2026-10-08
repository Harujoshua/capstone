<?php
include('../db.php');
include('auth.php');
include_once('admin_db.php');

$is_super_admin = (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin');
$can_edit_student = $is_super_admin || (get_admin_setting($admin_conn, 'subadmin_student_edit', '1') === '1');

// Handle form submission and edit mode
$error = '';
$existing = [];
$edit_id = intval($_REQUEST['id'] ?? 0);
if ($edit_id) {
  if (!$can_edit_student) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
      echo json_encode(['success' => false, 'error' => 'You do not have permission to edit student records.']);
      exit;
    }
    header('Location: students.php');
    exit;
  }
  $res_e = $conn->query("SELECT * FROM students WHERE id=$edit_id LIMIT 1");
  if ($res_e && $res_e->num_rows) {
    $existing = $res_e->fetch_assoc();
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $rfid = trim($_POST['rfid_uid'] ?? '');
  $parent_name = trim($_POST['parent_name'] ?? '');
  $parent_email = trim($_POST['parent_email'] ?? '');
  $parent_email2 = trim($_POST['parent_email2'] ?? '');
  $course = trim($_POST['course'] ?? '');
  $year_level = trim($_POST['year_level'] ?? '');
  $section = trim($_POST['section'] ?? '');
  $gender = trim($_POST['gender'] ?? '');
  $municipality = trim($_POST['municipality'] ?? '');
  $barangay = trim($_POST['barangay'] ?? '');

  if ($name === '' || $rfid === '' || $course === '' || $year_level === '' || $section === '' || $gender === '' || $municipality === '' || $barangay === '') {
    $error = 'Please provide name, RFID UID, select a course, year level, section, gender, and address.';
  } elseif ($parent_email !== '' && !filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please provide a valid parent email address.';
  } elseif ($parent_email2 !== '' && !filter_var($parent_email2, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please provide a valid second parent/contact email address.';
  } else {
    $name_safe = $conn->real_escape_string($name);
    $rfid_safe = $conn->real_escape_string($rfid);
    $parent_name_safe = $parent_name !== '' ? $conn->real_escape_string($parent_name) : null;
    $parent_email_safe = $parent_email !== '' ? $conn->real_escape_string($parent_email) : null;
    $parent_email2_safe = $parent_email2 !== '' ? $conn->real_escape_string($parent_email2) : null;
    $course_safe = $conn->real_escape_string($course);
    $year_level_safe = $conn->real_escape_string($year_level);
    $section_safe = $conn->real_escape_string($section);
    $gender_safe = $conn->real_escape_string($gender);
    $municipality_safe = $conn->real_escape_string($municipality);
    $barangay_safe = $conn->real_escape_string($barangay);
    // Check duplicate RFID — allow same record when editing
    $check_sql = "SELECT id FROM students WHERE rfid_uid='$rfid_safe'";
    if ($edit_id) {
      $check_sql .= " AND id!={$edit_id}";
    }
    $check = $conn->query($check_sql . " LIMIT 1");
    $check_fac = $conn->query("SELECT id FROM faculty WHERE rfid_uid='$rfid_safe' LIMIT 1");
    if ($check && $check->num_rows > 0) {
      $error = 'A student with that RFID UID already exists.';
    } elseif ($check_fac && $check_fac->num_rows > 0) {
      $error = 'That RFID UID is already registered to a faculty member.';
    } else {
      
      $colCheck1 = $conn->query("SHOW COLUMNS FROM students LIKE 'parent_email'");
      if (!$colCheck1 || $colCheck1->num_rows === 0) {
        $conn->query("ALTER TABLE students ADD COLUMN parent_email VARCHAR(255) DEFAULT NULL");
      }
      $colCheck2 = $conn->query("SHOW COLUMNS FROM students LIKE 'parent_email2'");
      if (!$colCheck2 || $colCheck2->num_rows === 0) {
        $conn->query("ALTER TABLE students ADD COLUMN parent_email2 VARCHAR(255) DEFAULT NULL");
      }
      $colCheck3 = $conn->query("SHOW COLUMNS FROM students LIKE 'course'");
      if (!$colCheck3 || $colCheck3->num_rows === 0) {
        $conn->query("ALTER TABLE students ADD COLUMN course VARCHAR(100) DEFAULT NULL");
      }
      $colCheck4 = $conn->query("SHOW COLUMNS FROM students LIKE 'year_level'");
      if (!$colCheck4 || $colCheck4->num_rows === 0) {
        $conn->query("ALTER TABLE students ADD COLUMN year_level VARCHAR(50) DEFAULT NULL");
      }
      $colCheck5 = $conn->query("SHOW COLUMNS FROM students LIKE 'section'");
      if (!$colCheck5 || $colCheck5->num_rows === 0) {
        $conn->query("ALTER TABLE students ADD COLUMN section VARCHAR(20) DEFAULT NULL");
      }
      $colCheckPhoto = $conn->query("SHOW COLUMNS FROM students LIKE 'photo'");
      if (!$colCheckPhoto || $colCheckPhoto->num_rows === 0) {
        $conn->query("ALTER TABLE students ADD COLUMN photo VARCHAR(255) DEFAULT NULL");
      }
      $colCheckGender = $conn->query("SHOW COLUMNS FROM students LIKE 'gender'");
      if (!$colCheckGender || $colCheckGender->num_rows === 0) {
        $conn->query("ALTER TABLE students ADD COLUMN gender VARCHAR(10) DEFAULT NULL");
      }
      $colCheckMuni = $conn->query("SHOW COLUMNS FROM students LIKE 'municipality'");
      if (!$colCheckMuni || $colCheckMuni->num_rows === 0) {
        $conn->query("ALTER TABLE students ADD COLUMN municipality VARCHAR(100) DEFAULT NULL");
      }
      $colCheckBrgy = $conn->query("SHOW COLUMNS FROM students LIKE 'barangay'");
      if (!$colCheckBrgy || $colCheckBrgy->num_rows === 0) {
        $conn->query("ALTER TABLE students ADD COLUMN barangay VARCHAR(100) DEFAULT NULL");
      }

      // handle uploaded photo (optional)
      $photo_filename = null;
      if (isset($_FILES['photo']) && isset($_FILES['photo']['error']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upErr = $_FILES['photo']['error'];
        if ($upErr !== UPLOAD_ERR_OK) {
          switch ($upErr) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
              $serverMax = ini_get('upload_max_filesize');
              $error = 'Uploaded file exceeds server limit (' . $serverMax . ').';
              break;
            case UPLOAD_ERR_PARTIAL:
              $error = 'File was only partially uploaded.';
              break;
            case UPLOAD_ERR_NO_TMP_DIR:
              $error = 'Missing temporary folder on server.';
              break;
            case UPLOAD_ERR_CANT_WRITE:
              $error = 'Failed to write file to disk.';
              break;
            case UPLOAD_ERR_EXTENSION:
              $error = 'File upload stopped by PHP extension.';
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
          } else {
            $maxBytes = 4 * 1024 * 1024; // desired max
            if ($_FILES['photo']['size'] > $maxBytes) {
              $error = 'Image file is too large (max 4MB).';
            } else {
              $uploadDir = __DIR__ . '/../uploads/students';
              if (!is_dir($uploadDir))
                @mkdir($uploadDir, 0777, true);
              $ext = $allowed[$mime];
              $basename = time() . '_' . uniqid() . '.' . $ext;
              $target = $uploadDir . '/' . $basename;
              if (!@move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                $error = 'Failed to move uploaded file. Check uploads folder permissions.';
              } else {
                $photo_filename = $basename;
              }
            }
          }
        }
      }

      // If file upload produced an error, return early for AJAX or show on page
      if ($error !== '') {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => $error]);
          exit;
        }
      } else {

      // Build insert SQL including optional parent emails
      $cols = ["name", "rfid_uid", "status", "course"];
      $vals = ["'$name_safe'", "'$rfid_safe'", "'ACTIVE'", "'$course_safe'"];
      if ($year_level_safe !== '') {
        $cols[] = "year_level";
        $vals[] = "'$year_level_safe'";
      }
      if ($section_safe !== '') {
        $cols[] = "section";
        $vals[] = "'$section_safe'";
      }
      if ($gender_safe !== '') {
        $cols[] = "gender";
        $vals[] = "'$gender_safe'";
      }
      if ($municipality_safe !== '') {
        $cols[] = "municipality";
        $vals[] = "'$municipality_safe'";
      }
      if ($barangay_safe !== '') {
        $cols[] = "barangay";
        $vals[] = "'$barangay_safe'";
      }
      if ($parent_name_safe !== null) {
        $cols[] = "parent_name";
        $vals[] = "'$parent_name_safe'";
      }
      if ($parent_email_safe !== null) {
        $cols[] = "parent_email";
        $vals[] = "'$parent_email_safe'";
      }
      if ($parent_email2_safe !== null) {
        $cols[] = "parent_email2";
        $vals[] = "'$parent_email2_safe'";
      }
      if ($photo_filename !== null) {
        $cols[] = "photo";
        $vals[] = "'" . $conn->real_escape_string($photo_filename) . "'";
      }

      if ($edit_id) {
        // UPDATE existing student
        $updates = [];
        foreach ($cols as $i => $col) {
          $val = $vals[$i];
          $updates[] = "$col = $val";
        }
        $update_sql = "UPDATE students SET " . implode(", ", $updates) . " WHERE id={$edit_id}";
        $conn->query($update_sql);
        
        include_once('logger.php');
        $changes = [];
        if ($existing['name'] !== $name) $changes[] = "name from '{$existing['name']}' to '{$name}'";
        if ($existing['course'] !== $course) $changes[] = "course from '{$existing['course']}' to '{$course}'";
        if ($existing['year_level'] !== $year_level) $changes[] = "year level from '{$existing['year_level']}' to '{$year_level}'";
        if ($existing['section'] !== $section) $changes[] = "section from '{$existing['section']}' to '{$section}'";
        if ($existing['rfid_uid'] !== $rfid) $changes[] = "RFID from '{$existing['rfid_uid']}' to '{$rfid}'";
        
        $audit_details = "Student record updated.";
        if (!empty($changes)) {
            $audit_details = "Changed " . implode(', ', $changes) . ".";
        }
        log_audit('EDIT', 'Student', $name_safe, $audit_details);

        // if a new photo was uploaded, remove the old file
        if (!empty($photo_filename) && !empty($existing['photo'])) {
          $oldPath = __DIR__ . '/../uploads/students/' . $existing['photo'];
          if (file_exists($oldPath))
            @unlink($oldPath);
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
          header('Content-Type: application/json');
          echo json_encode(['success' => true]);
          exit;
        }
        header('Location: students.php');
        exit;
      } else {
        $insert_sql = "INSERT INTO students (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ")";
        $conn->query($insert_sql);
        
        include_once('logger.php');
        log_audit('REGISTER', 'Student', $name_safe, "New student registered with RFID: $rfid_safe");

        // Clear temp_rfid after successful registration
        $conn->query("DELETE FROM temp_rfid");
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
          header('Content-Type: application/json');
          echo json_encode(['success' => true]);
          exit;
        }
        header('Location: students.php');
        exit;
      }
      }
    }
  }
}

include('navbar.php');
?>
<link rel="stylesheet" href="admin_assets/admin_register.css">

<div class="container">
  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form id="regForm" method="POST" action="register.php" enctype="multipart/form-data" novalidate>
    <div class="form-grid">
      <div class="left-col">
        <div class="card">
          <div class="photo-preview-container">
            <?php if (!empty($existing['photo'])): ?>
              <img id="photoPreview" src="../uploads/students/<?= htmlspecialchars($existing['photo']) ?>"
                class="photo-preview" alt="Student photo">
            <?php else: ?>
              <img id="photoPreview"
                src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180'%3E%3Crect width='100%25' height='100%25' fill='%23f8fafc'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='middle' text-anchor='middle' fill='%239aa6b2' font-size='14'%3ENo photo%3C/text%3E%3C/svg%3E"
                class="photo-preview" alt="No photo">
            <?php endif; ?>
          </div>
          <div class="field">
            <label for="photo">Photo <span class="small">(PNG/JPEG)</span></label>
            <input id="photo" name="photo" type="file" accept="image/jpeg,image/png" class="input file-input">
            <div class="file-meta">Tip: you can preview the photo before submitting.</div>
          </div>

          <div class="field">
            <label for="rfid_uid">RFID UID</label>
            <div class="rfid-wrap">
              <input id="rfid_uid" name="rfid_uid" type="text" placeholder="Waiting for card scan..." required
                autocomplete="off" class="input"
                value="<?= htmlspecialchars($_POST['rfid_uid'] ?? $existing['rfid_uid'] ??  '') ?>">
            </div>
            <div id="rfidStatus" class="small-status">
              <div class="rfid-indicator">
                 <span class="rfid-status-text">Ready to scan...</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="right-col">
        <div class="card">
          <div class="field">
            <label for="name">Student name</label>
            <input id="name" name="name" type="text" required autocomplete="off" class="input"
              value="<?= htmlspecialchars($_POST['name'] ?? $existing['name'] ?? '') ?>">
          </div>

          <div class="field">
            <label for="gender">Gender</label>
            <select id="gender" name="gender" required class="input">
              <option value="">Select gender</option>
              <option value="Male" <?= ($_POST['gender'] ?? $existing['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
              <option value="Female" <?= ($_POST['gender'] ?? $existing['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
            </select>
          </div>

          <div class="course-year-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
            <div class="field" style="margin-bottom: 0;">
              <label for="municipality">Municipality</label>
              <select id="municipality" name="municipality" required class="input">
                <option value="Carranglan" <?= ($_POST['municipality'] ?? $existing['municipality'] ?? 'Carranglan') === 'Carranglan' ? 'selected' : '' ?>>Carranglan</option>
              </select>
            </div>
            <div class="field" style="margin-bottom: 0;">
              <label for="barangay">Barangay</label>
              <select id="barangay" name="barangay" required class="input">
                <option value="">Select barangay</option>
                <?php
                  $brgys = ['Bantug', 'Bunga', 'Burgos', 'Capintalan', 'General Luna', 'Joson (formerly Digdig)', 'Minuli', 'Piut', 'Puncan', 'Putlan', 'R. A. Padilla (formerly Baluarte)', 'Salazar', 'San Agustin', 'T. L. Padilla Poblacion (Barangay I)', 'F. C. Otic Poblacion (Barangay II)', 'D. L. Maglanoc Poblacion (Barangay III)', 'G. S. Rosario Poblacion (Barangay IV)'];
                  $selectedBrgy = $_POST['barangay'] ?? $existing['barangay'] ?? '';
                  foreach($brgys as $b) {
                    $sel = ($selectedBrgy === $b) ? 'selected' : '';
                    echo "<option value=\"$b\" $sel>$b</option>";
                  }
                ?>
              </select>
            </div>
          </div>

          <div class="course-year-grid">
            <div class="field">
              <label for="course">Course</label>
              <select id="course" name="course" required class="input">
                <option value="">Select course</option>
                <option value="BSIT" <?= ($_POST['course'] ?? $existing['course'] ?? '') === 'BSIT' ? 'selected' : '' ?>>
                  BSIT</option>
                <option value="BEED" <?= ($_POST['course'] ?? $existing['course'] ?? '') === 'BEED' ? 'selected' : '' ?>>
                  BEED</option>
                <option value="BSBA" <?= ($_POST['course'] ?? $existing['course'] ?? '') === 'BSBA' ? 'selected' : '' ?>>
                  BSBA</option>
              </select>
            </div>

            <div class="field">
              <label for="year_level">Year level</label>
              <select id="year_level" name="year_level" required class="input">
                <option value="">Select year level</option>
                <option value="1st Year" <?= ($_POST['year_level'] ?? $existing['year_level'] ?? '') === '1st Year' ? 'selected' : '' ?>>1st Year</option>
                <option value="2nd Year" <?= ($_POST['year_level'] ?? $existing['year_level'] ?? '') === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                <option value="3rd Year" <?= ($_POST['year_level'] ?? $existing['year_level'] ?? '') === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                <option value="4th Year" <?= ($_POST['year_level'] ?? $existing['year_level'] ?? '') === '4th Year' ? 'selected' : '' ?>>4th Year</option>
              </select>
            </div>
          </div>

          <div class="field">
            <label for="section">Section</label>
            <select id="section" name="section" required class="input">
              <option value="">Select section</option>
              <option value="A" <?= ($_POST['section'] ?? $existing['section'] ?? '') === 'A' ? 'selected' : '' ?>>A
              </option>
              <option value="B" <?= ($_POST['section'] ?? $existing['section'] ?? '') === 'B' ? 'selected' : '' ?>>B
              </option>
            </select>
          </div>

          <div class="field">
            <label for="parent_name">Parent's name <span class="small">(optional)</span></label>
            <input id="parent_name" name="parent_name" type="text" autocomplete="off" class="input"
              value="<?= htmlspecialchars($_POST['parent_name'] ?? $existing['parent_name'] ?? '') ?>">
          </div>

          <div class="email-grid">
            <div class="field">
              <label for="parent_email">Parent's email <span class="small">(optional)</span></label>
              <input id="parent_email" name="parent_email" type="email" placeholder="parent@example.com" class="input"
                value="<?= htmlspecialchars($_POST['parent_email'] ?? $existing['parent_email'] ?? '') ?>">
            </div>
            <div class="field">
              <label for="parent_email2">Second contact email <span class="small">(optional)</span></label>
              <input id="parent_email2" name="parent_email2" type="email" placeholder="parent2@example.com"
                class="input"
                value="<?= htmlspecialchars($_POST['parent_email2'] ?? $existing['parent_email2'] ?? '') ?>">
            </div>
          </div>

          <div class="actions">
            <?php if (!empty($existing)): ?>
              <input type="hidden" name="id" value="<?= htmlspecialchars($existing['id']) ?>">
              <button type="submit" class="btn btn-primary">Update student</button>
            <?php else: ?>
              <button type="submit" class="btn btn-primary">Register student</button>
            <?php endif; ?>
            <a href="students.php" class="btn btn-secondary">Cancel</a>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
  (function () {
    // Image preview and size check
    const photoInput = document.getElementById('photo');
    const preview = document.getElementById('photoPreview');
    if (photoInput) {
      photoInput.addEventListener('change', function () {
        const f = this.files && this.files[0];
        if (!f) return;
        if (!['image/jpeg', 'image/png'].includes(f.type)) {
          alert('Unsupported image format. Only PNG and JPEG images are allowed.');
          this.value = '';
          return;
        }
        const max = 4 * 1024 * 1024; // 4MB
        if (f.size > max) {
          alert('Selected image is too large (max 4MB).');
          this.value = '';
          return;
        }
        const reader = new FileReader();
        reader.onload = function (e) { preview.src = e.target.result; };
        reader.readAsDataURL(f);
      });
    }

    // RFID polling
    const uidInput = document.getElementById('rfid_uid');
    const statusBox = document.getElementById('rfidStatus');
    const statusText = statusBox ? statusBox.querySelector('.rfid-status-text') : null;
    let lastUid = uidInput ? uidInput.value : '';

    async function poll() {
      try {
        const res = await fetch('get_uid.php?t=' + Date.now());
        const uid = (await res.text()).trim();
        
        if (uid && uid !== lastUid) {
          uidInput.value = uid;
          lastUid = uid;
          if (statusText) {
            statusText.textContent = 'Card Scanned: ' + uid;
            statusBox.querySelector('.rfid-indicator').style.background = '#e6f9f0';
            statusBox.querySelector('.rfid-indicator').style.color = '#067a3c';
          }
        }
      } catch (e) { console.error('Polling error:', e); }
      setTimeout(poll, 1000);
    }

    if (uidInput) {
      poll();
      
      // Also update lastUid if user types manually
      uidInput.addEventListener('input', () => {
        lastUid = uidInput.value;
      });
    }
  })();
</script>
</body>

</html>