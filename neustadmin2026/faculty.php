<?php
include('../db.php');
include('auth.php');
include_once('logger.php');

$error = '';
$open_modal = false;
$edit = null;

// Handle POST-only actions first (delete, load edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];
  // Delete via POST only
  if ($action === 'delete' && isset($_POST['id'])) {
    if (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin') {
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
    $eid = (int) $_POST['id'];
    $er  = $conn->query("SELECT * FROM faculty WHERE id = $eid LIMIT 1");
    if ($er && $er->num_rows)
      $edit = $er->fetch_assoc();
  }
}

// Handle POST create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !(isset($_POST['action']) && $_POST['action'] === 'edit')) {
  $id         = intval($_POST['id'] ?? 0);
  $name       = trim($_POST['name'] ?? '');
  $email      = trim($_POST['email'] ?? '');
  $department = trim($_POST['department'] ?? '');

  if ($name === '' || $email === '') {
    $error = 'Please provide name and email.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please provide a valid email address.';
  } else {
    $name_safe  = $conn->real_escape_string($name);
    $email_safe = $conn->real_escape_string($email);
    $dept_safe  = $department !== '' ? "'" . $conn->real_escape_string($department) . "'" : 'NULL';

    $check_sql = "SELECT id FROM faculty WHERE email='{$email_safe}'";
    if ($id) $check_sql .= " AND id!={$id}";
    $check = $conn->query($check_sql . " LIMIT 1");
    if ($check && $check->num_rows > 0) {
      $error = 'A faculty with that email already exists.';
    } else {
      if ($id) {
        $existing = $conn->query("SELECT * FROM faculty WHERE id={$id} LIMIT 1")->fetch_assoc();
        $sql = "UPDATE faculty SET name='{$name_safe}', email='{$email_safe}', department={$dept_safe} WHERE id={$id} LIMIT 1";
        $conn->query($sql);

        $changes = [];
        if ($existing && $existing['name']       !== $name)       $changes[] = "name from '{$existing['name']}' to '{$name}'";
        if ($existing && $existing['email']      !== $email)      $changes[] = "email from '{$existing['email']}' to '{$email}'";
        if ($existing && $existing['department'] !== $department) $changes[] = "department from '{$existing['department']}' to '{$department}'";

        $audit_details = "Faculty member details updated.";
        if (!empty($changes)) $audit_details = "Changed " . implode(', ', $changes) . ".";
        log_audit('EDIT', 'Faculty', $name_safe, $audit_details);
      } else {
        $token  = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + (24 * 60 * 60));
        $sql    = "INSERT INTO faculty (name, email, department, password_hash, reset_token, token_expiry) VALUES ('{$name_safe}','{$email_safe}',{$dept_safe}, NULL, '{$token}', '{$expiry}')";
        if ($conn->query($sql)) {
          require_once('../mailer.php');
          send_faculty_setup_email($email, $name, $token);
          log_audit('CREATE', 'Faculty', $name_safe, "New faculty member created.");
        } else {
          $error = 'Error creating faculty member.';
        }
      }
      header('Location: faculty.php');
      exit;
    }
  }
}

// Auto-open create modal if server flagged an error on create
$open_modal = ($_SERVER['REQUEST_METHOD'] === 'POST' && intval($_POST['id'] ?? 0) === 0 && $error !== '');

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

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-left">
        <h1>Faculty Management</h1>
        <p>Manage registered faculty members and their assigned programs.</p>
      </div>
      <button id="openCreateBtn" class="btn-primary">
        <i class="fa-solid fa-plus"></i> Add Faculty
      </button>
    </div>

    <!-- Error (outside modal) -->
    <?php if ($error && !$open_modal): ?>
      <div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Edit Form (inline panel) -->
    <?php if ($edit): ?>
    <div class="edit-panel">
      <h3><i class="fa-solid fa-pen-to-square"></i> Edit Faculty Member</h3>
      <form method="POST" action="faculty.php">
        <input type="hidden" name="id" value="<?= intval($edit['id']) ?>">
        <div class="edit-form-row">
          <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($edit['name']) ?>" placeholder="e.g. Juan Dela Cruz">
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($edit['email']) ?>" placeholder="faculty@neust.edu.ph">
          </div>
          <div class="form-group">
            <label>Program / Department</label>
            <select name="department">
              <option value="">Select program</option>
              <option value="BSIT" <?= (isset($edit['department']) && $edit['department'] === 'BSIT') ? 'selected' : '' ?>>BSIT</option>
              <option value="BEED" <?= (isset($edit['department']) && $edit['department'] === 'BEED') ? 'selected' : '' ?>>BEED</option>
              <option value="BSBA" <?= (isset($edit['department']) && $edit['department'] === 'BSBA') ? 'selected' : '' ?>>BSBA</option>
            </select>
          </div>
          <div class="edit-form-actions">
            <button class="btn-save" type="submit"><i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i>Save</button>
            <a class="btn-cancel" href="faculty.php">Cancel</a>
          </div>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- Faculty Table Panel -->
    <div class="faculty-panel">
      <div class="faculty-panel-header">
        <h2><i class="fa-solid fa-users"></i> Registered Faculty</h2>
        <span class="faculty-count-badge">
          <i class="fa-solid fa-chalkboard-user"></i>
          <?= number_format($faculty_count) ?> member<?= $faculty_count !== 1 ? 's' : '' ?>
        </span>
      </div>

      <div class="table-wrap">
        <table class="faculty-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Department</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $res = $conn->query("SELECT * FROM faculty ORDER BY name ASC");
            if ($res && $res->num_rows) {
              while ($r = $res->fetch_assoc()):
                $initial = strtoupper(substr($r['name'], 0, 1));
            ?>
            <tr>
              <td>
                <div class="faculty-name-cell">
                  <div class="faculty-avatar"><?= $initial ?></div>
                  <span class="faculty-name-text"><?= htmlspecialchars($r['name']) ?></span>
                </div>
              </td>
              <td><?= htmlspecialchars($r['email']) ?></td>
              <td>
                <?php if (!empty($r['department'])): ?>
                  <span class="dept-badge"><?= htmlspecialchars($r['department']) ?></span>
                <?php else: ?>
                  <span style="color:#94a3b8;font-size:0.85rem;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="table-actions-cell">
                  <!-- Edit -->
                  <form method="POST" action="faculty.php" class="table-actions">
                    <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <button type="submit" class="link-like"><i class="fa-solid fa-pen"></i> Edit</button>
                  </form>
                  <!-- Delete (super admin only) -->
                  <?php if (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin'): ?>
                  <form method="POST" action="faculty.php" class="table-actions" onsubmit="return confirm('Delete this faculty member?')">
                    <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="link-like delete"><i class="fa-solid fa-trash"></i> Delete</button>
                  </form>
                  <?php endif; ?>
                  <!-- View Schedules -->
                  <button
                    class="btn-action"
                    data-fid="<?= intval($r['id']) ?>"
                    data-fname="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"
                    onclick="openSchedulesModal(this)">
                    <i class="fa-solid fa-calendar-days"></i> Schedules
                  </button>
                </div>
              </td>
            </tr>
            <?php
              endwhile;
            } else {
              echo '<tr class="empty-state-row"><td colspan="4"><i class="fa-solid fa-users-slash" style="font-size:2rem;color:#cbd5e1;display:block;margin-bottom:8px;"></i>No faculty members found.</td></tr>';
            }
            ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<!-- ── Create Faculty Modal ── -->
<div id="createModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user-plus"></i> Add Faculty</h3>
      <button id="closeModal" class="modal-close-btn" aria-label="Close">&times;</button>
    </div>
    <p class="modal-subtitle">Fill in the details below to register a new faculty member.</p>

    <?php if ($error && $open_modal): ?>
      <div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="faculty.php">
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
      <div class="modal-buttons">
        <button type="button" id="cancelModal" class="btn-cancel">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa-solid fa-plus"></i> Create Faculty</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Schedules Modal ── -->
<div id="schedulesModal" class="modal">
  <div class="modal-content wide">
    <div class="modal-header">
      <h3 id="schedulesTitle"><i class="fa-solid fa-calendar-days"></i> Schedules</h3>
      <button id="closeSchedules" class="modal-close-btn" aria-label="Close">&times;</button>
    </div>
    <div id="schedulesContent" style="margin-top:12px;">Loading…</div>
  </div>
</div>

<script>
  /* ── Create Modal Toggle ── */
  (function () {
    var openBtn    = document.getElementById('openCreateBtn');
    var modal      = document.getElementById('createModal');
    var closeBtn   = document.getElementById('closeModal');
    var cancelBtn  = document.getElementById('cancelModal');

    function show() { modal.classList.add('open'); document.body.style.overflow = 'hidden'; }
    function hide() { modal.classList.remove('open'); document.body.style.overflow = ''; }

    if (openBtn)   openBtn.addEventListener('click', show);
    if (closeBtn)  closeBtn.addEventListener('click', hide);
    if (cancelBtn) cancelBtn.addEventListener('click', hide);

    // Close on backdrop click
    modal.addEventListener('click', function (e) { if (e.target === modal) hide(); });

    // Auto-open if server flagged an error on create
    if (<?= $open_modal ? 'true' : 'false' ?>) show();
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

  document.getElementById('closeSchedules').addEventListener('click', function () {
    document.getElementById('schedulesModal').classList.remove('open');
    document.body.style.overflow = '';
  });
  document.getElementById('schedulesModal').addEventListener('click', function (e) {
    if (e.target === this) { this.classList.remove('open'); document.body.style.overflow = ''; }
  });
</script>
</body>
</html>