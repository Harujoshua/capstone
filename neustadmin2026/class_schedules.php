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

// Permissions Check
$is_super_admin = (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin');
$can_create_schedule = $is_super_admin || (get_admin_setting($admin_conn, 'subadmin_schedule_create', '1') === '1');
$can_edit_schedule   = $is_super_admin || (get_admin_setting($admin_conn, 'subadmin_schedule_edit', '1') === '1');
$can_delete_schedule = $is_super_admin || (get_admin_setting($admin_conn, 'subadmin_schedule_delete', '0') === '1');

// Backfill teacher_id when teacher name matches a faculty record
$conn->query("UPDATE schedules s JOIN faculty f ON TRIM(s.teacher) = TRIM(f.name) SET s.teacher_id = f.id WHERE (s.teacher_id IS NULL OR s.teacher_id = 0) AND TRIM(s.teacher) != ''");

// Load faculty list for selection (include department)
$faculty_rows = [];
$fres = $conn->query("SELECT id, name, department FROM faculty ORDER BY name ASC");
if ($fres) {
  while ($fr = $fres->fetch_assoc())
    $faculty_rows[] = $fr;
}
// Error and notification holder
$error = '';
$saved_msg = '';
$preset_teacher_id = intval($_GET['add_teacher_id'] ?? 0);
$open_new_modal = false;

if (isset($_GET['saved']) && $_GET['saved'] == '1') {
  $saved_msg = 'Schedule saved successfully!';
}

if ($preset_teacher_id > 0 && $can_create_schedule) {
  $open_new_modal = true;
  $fq = $conn->query("SELECT department FROM faculty WHERE id = $preset_teacher_id LIMIT 1");
  if ($fq && $fq->num_rows) {
    $fdept = $fq->fetch_assoc()['department'] ?? '';
    if (!isset($course) || $course === '') {
      $course = $fdept;
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $act = $_POST['action'];
  if ($act === 'delete' && isset($_POST['id'])) {
    if ($can_delete_schedule) {
      $del = (int) $_POST['id'];
      $res = $conn->query("SELECT subject, course FROM schedules WHERE id=$del")->fetch_assoc();
      $s_name = ($res['subject'] ?? 'Unknown') . ' (' . ($res['course'] ?? '') . ')';
      $conn->query("DELETE FROM schedule_students WHERE schedule_id = $del");
      $conn->query("DELETE FROM schedules WHERE id = $del LIMIT 1");
      log_audit('DELETE', 'Schedule', $s_name, "Schedule deleted.");
    }
    header('Location: class_schedules.php');
    exit;
  } elseif ($act === 'edit' && isset($_POST['id'])) {
    if (!$can_edit_schedule) {
      header('Location: class_schedules.php');
      exit;
    }
    $edit = (int) $_POST['id'];
    $res = $conn->query("SELECT * FROM schedules WHERE id = $edit LIMIT 1");
    if ($res && $res->num_rows) {
      $row = $res->fetch_assoc();
      $editId = $row['id'];
      $course = $row['course'];
      $year_level = $row['year_level'] ?? '';
      $section = $row['section'] ?? '';
      $subject = $row['subject'];
      $teacher = $row['teacher'] ?? '';
      $teacher_id = isset($row['teacher_id']) ? intval($row['teacher_id']) : 0;
      $teacher_manual = ($teacher_id === 0) ? ($row['teacher'] ?? '') : '';
      $day = $row['day'];
      $start = $row['start_time'];
      $end = $row['end_time'];
      $room = $row['room'];
      $assign_all = false;

      $assigned_students = [];
      $ars = $conn->query("SELECT student_id FROM schedule_students WHERE schedule_id = $edit");
      if ($ars) {
        while ($ar = $ars->fetch_assoc())
          $assigned_students[] = (int) $ar['student_id'];
      }

      // prepare students list for checkboxes
      $whereClause = "course = '" . $conn->real_escape_string($course) . "'";
      if ($year_level !== '')
        $whereClause .= " AND year_level = '" . $conn->real_escape_string($year_level) . "'";
      if ($section !== '')
        $whereClause .= " AND section = '" . $conn->real_escape_string($section) . "'";
      $students_for_assign = $conn->query("SELECT id, name FROM students WHERE " . $whereClause . " ORDER BY name ASC");
    }
  }
}

// POST: create or update (only when scheduleForm is submitted)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_schedule') {
  $course = trim($_POST['course'] ?? '');
  $year_level = trim($_POST['year_level'] ?? '');
  $section = trim($_POST['section'] ?? '');
  $subject = trim($_POST['subject'] ?? '');
  $teacher_id_post = intval($_POST['teacher_id'] ?? 0);
  $teacher_manual = trim($_POST['teacher_manual'] ?? '');
  
  $days_selected = [];
  if (isset($_POST['days']) && is_array($_POST['days'])) {
    foreach ($_POST['days'] as $d_item) {
      $d_trimmed = trim($d_item);
      if ($d_trimmed !== '') $days_selected[] = $d_trimmed;
    }
  } elseif (isset($_POST['day']) && trim($_POST['day']) !== '') {
    $days_selected = array_map('trim', explode(',', $_POST['day']));
  }

  $start = trim($_POST['start_time'] ?? '');
  $end = trim($_POST['end_time'] ?? '');
  $room = trim($_POST['room'] ?? '');
  $assign_all = isset($_POST['assign_all']);

  if (isset($_POST['update_id']) && !$can_edit_schedule) {
    $error = 'You do not have permission to edit schedules.';
  } elseif (!isset($_POST['update_id']) && !$can_create_schedule) {
    $error = 'You do not have permission to create new schedules.';
  } elseif ($course === '' || $subject === '') {
    $error = 'Please provide course and subject.';
    if (isset($_POST['update_id'])) $editId = (int)$_POST['update_id'];
  } elseif (empty($days_selected)) {
    $error = 'Please select at least one day for the schedule.';
    if (isset($_POST['update_id'])) $editId = (int)$_POST['update_id'];
  } elseif ($start === '' || $end === '') {
    $error = 'Please provide both start time and end time for the schedule.';
    if (isset($_POST['update_id'])) $editId = (int)$_POST['update_id'];
  } elseif (strtotime($start) >= strtotime($end)) {
    $error = 'Invalid schedule time: End time must be later than start time.';
    if (isset($_POST['update_id'])) $editId = (int)$_POST['update_id'];
  } else {
    $c = $conn->real_escape_string($course);
    $y = $conn->real_escape_string($year_level);
    $sec = $conn->real_escape_string($section);
    $s = $conn->real_escape_string($subject);
    // Resolve teacher name and teacher_id: prefer selected faculty
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
    $day = $days_selected[0];
    $d = $conn->real_escape_string($day);
    $st = "'" . date('H:i:s', strtotime($start)) . "'";
    $en = "'" . date('H:i:s', strtotime($end)) . "'";
    $r = $room === '' ? 'NULL' : "'" . $conn->real_escape_string($room) . "'";
    $teacher_sql = $t === null ? 'NULL' : "'" . $t . "'";

    $year_sql = $y === '' ? 'NULL' : "'" . $y . "'";
    $section_sql = $sec === '' ? 'NULL' : "'" . $sec . "'";

    // Update existing
    if (isset($_POST['update_id']) && $_POST['update_id'] !== '') {
      $update_id = (int) $_POST['update_id'];

      // Conflict Check on update
      $conflict_found = check_schedule_conflict(
        $conn,
        $days_selected[0],
        $start,
        $end,
        $active_school_year,
        $active_semester,
        $teacher_id_post,
        $fname,
        $room,
        $course,
        $year_level,
        $section,
        $update_id
      );

      if (!$conflict_found && count($days_selected) > 1) {
        $extra_days = array_slice($days_selected, 1);
        foreach ($extra_days as $extra_day) {
          $c_extra = check_schedule_conflict(
            $conn,
            $extra_day,
            $start,
            $end,
            $active_school_year,
            $active_semester,
            $teacher_id_post,
            $fname,
            $room,
            $course,
            $year_level,
            $section,
            $update_id
          );
          if ($c_extra) {
            $conflict_found = $c_extra;
            break;
          }
        }
      }

      if ($conflict_found) {
        $error = $conflict_found;
        $editId = $update_id;
      } else {
        $existing = $conn->query("SELECT * FROM schedules WHERE id = $update_id LIMIT 1")->fetch_assoc();
        $update = "UPDATE schedules SET course='$c', year_level=$year_sql, section=$section_sql, subject='$s', teacher=$teacher_sql, teacher_id=" . ($teacher_id_sql_expr === 'NULL' ? 'NULL' : intval($teacher_id_sql_expr)) . ", day='$d', start_time=$st, end_time=$en, room=$r WHERE id = $update_id LIMIT 1";
        if ($conn->query($update)) {
          $changes = [];
          if ($existing) {
            if ($existing['subject'] !== $subject) $changes[] = "subject from '{$existing['subject']}' to '{$subject}'";
            if ($existing['course'] !== $course) $changes[] = "course from '{$existing['course']}' to '{$course}'";
            if (($existing['teacher'] ?? '') !== ($t ?? '')) $changes[] = "teacher to '" . ($t ?? '') . "'";
            if ($existing['day'] !== $day) $changes[] = "day from '{$existing['day']}' to '{$day}'";
            if ($existing['start_time'] !== $start) $changes[] = "start time to '{$start}'";
            if ($existing['end_time'] !== $end) $changes[] = "end time to '{$end}'";
            if ($existing['room'] !== $room) $changes[] = "room to '{$room}'";
          }
          $audit_details = "Schedule updated.";
          if (!empty($changes)) {
              $audit_details = "Changed " . implode(', ', $changes) . ".";
          }
          log_audit('EDIT', 'Schedule', "$s ($c)", $audit_details);
          // handle assignments
          $conn->query("DELETE FROM schedule_students WHERE schedule_id = $update_id");
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
                $conn->query("INSERT IGNORE INTO schedule_students (schedule_id, student_id) VALUES ($update_id, $stud)");
              }
            }
          } else if (isset($_POST['students']) && is_array($_POST['students'])) {
            foreach ($_POST['students'] as $studId) {
              $stud = (int) $studId;
              if ($stud > 0)
                $conn->query("INSERT IGNORE INTO schedule_students (schedule_id, student_id) VALUES ($update_id, $stud)");
            }
          }

          // If user selected multiple days during edit, insert additional rows for remaining days
          if (count($days_selected) > 1) {
            $safe_sy = $conn->real_escape_string($active_school_year);
            $safe_sem = $conn->real_escape_string($active_semester);
            $extra_days = array_slice($days_selected, 1);
            foreach ($extra_days as $extra_day) {
              $extra_d = $conn->real_escape_string($extra_day);
              $extra_insert = "INSERT INTO schedules (course, year_level, section, subject, teacher, teacher_id, day, start_time, end_time, room, school_year, semester) VALUES ('$c', $year_sql, $section_sql, '$s', $teacher_sql, " . ($teacher_id_sql_expr === 'NULL' ? 'NULL' : intval($teacher_id_sql_expr)) . ", '$extra_d', $st, $en, $r, '$safe_sy', '$safe_sem')";
              if ($conn->query($extra_insert)) {
                $extra_sid = $conn->insert_id;
                log_audit('CREATE', 'Schedule', "$s ($c)", "New schedule created for additional day ($extra_day).");
                if ($assign_all) {
                  $whereClause = "course = '" . $conn->real_escape_string($course) . "'";
                  if ($year_level !== '') $whereClause .= " AND year_level = '" . $conn->real_escape_string($year_level) . "'";
                  if ($section !== '') $whereClause .= " AND section = '" . $conn->real_escape_string($section) . "'";
                  $res = $conn->query("SELECT id FROM students WHERE " . $whereClause);
                  if ($res) {
                    while ($row = $res->fetch_assoc()) {
                      $stud = (int) $row['id'];
                      $conn->query("INSERT IGNORE INTO schedule_students (schedule_id, student_id) VALUES ($extra_sid, $stud)");
                    }
                  }
                } else if (isset($_POST['students']) && is_array($_POST['students'])) {
                  foreach ($_POST['students'] as $studId) {
                    $stud = (int) $studId;
                    if ($stud > 0)
                      $conn->query("INSERT IGNORE INTO schedule_students (schedule_id, student_id) VALUES ($extra_sid, $stud)");
                  }
                }
              }
            }
          }

          header('Location: class_schedules.php?saved=1');
          exit;
        } else {
          $error = 'Failed to update schedule: ' . $conn->error;
          $editId = $update_id;
        }
      }
    } else {
      // Create new (Support multiple days if selected via days[])
      $safe_sy = $conn->real_escape_string($active_school_year);
      $safe_sem = $conn->real_escape_string($active_semester);

      $days_to_insert = $days_selected;

      // Conflict Check for new schedules across all selected days
      $conflict_found = null;
      foreach ($days_to_insert as $single_day) {
        $c_msg = check_schedule_conflict(
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
        if ($c_msg) {
          $conflict_found = $c_msg;
          break;
        }
      }

      if ($conflict_found) {
        $error = $conflict_found;
      } else {
        $success_count = 0;
        foreach ($days_to_insert as $single_day) {
          $d_str = $conn->real_escape_string($single_day);
          $insert = "INSERT INTO schedules (course, year_level, section, subject, teacher, teacher_id, day, start_time, end_time, room, school_year, semester) VALUES ('$c', $year_sql, $section_sql, '$s', $teacher_sql, " . ($teacher_id_sql_expr === 'NULL' ? 'NULL' : intval($teacher_id_sql_expr)) . ", '$d_str', $st, $en, $r, '$safe_sy', '$safe_sem')";
          if ($conn->query($insert)) {
            $sid = $conn->insert_id;
            $success_count++;
            log_audit('CREATE', 'Schedule', "$s ($c)", "New schedule created.");
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
            } else if (isset($_POST['students']) && is_array($_POST['students'])) {
              foreach ($_POST['students'] as $studId) {
                $stud = (int) $studId;
                if ($stud > 0)
                  $conn->query("INSERT IGNORE INTO schedule_students (schedule_id, student_id) VALUES ($sid, $stud)");
              }
            }
          }
        }

        if ($success_count > 0) {
          if (isset($_POST['save_and_another']) && $teacher_id_post > 0) {
            header('Location: class_schedules.php?add_teacher_id=' . $teacher_id_post . '&saved=1');
          } else {
            header('Location: class_schedules.php?saved=1');
          }
          exit;
        } else {
          $error = 'Failed to save schedule: ' . $conn->error;
        }
      }
    }
  }
}

// (Delete/Edit handled via POST form submissions)

include('navbar.php');
$request_values = $_POST; // Use POST only for filters; ignore GET
$sel_filter_course = $request_values['filter_course'] ?? '';
$sel_filter_year_level = $request_values['filter_year_level'] ?? '';
$sel_filter_section = $request_values['filter_section'] ?? '';
$sel_filter_teacher = $request_values['filter_teacher'] ?? '';
$sel_filter_day = $request_values['filter_day'] ?? '';
$sel_search = $request_values['search'] ?? '';
$sel_per_page = $request_values['per_page'] ?? '';
$sel_page = $request_values['page'] ?? '';

// Default filters to active academic term on initial load (empty POST)
$is_post_empty = empty($request_values);
$sel_filter_school_year = $is_post_empty ? $active_school_year : ($request_values['filter_school_year'] ?? '');
$sel_filter_semester = $is_post_empty ? $active_semester : ($request_values['filter_semester'] ?? '');
?>
<link rel="stylesheet" href="admin_assets/admin_class_schedules.css">
<script>document.title = "Class Schedules – NEUST Gatepass";</script>
<div class="container">
  <?php if ($saved_msg !== ''): ?>
    <div class="toast-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($saved_msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="toast-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <header class="top">
    <div class="toolbar">
      <a href="class_schedules.php" class="btn ghost"><i class="fa-solid fa-rotate-left"></i> Reset Filters</a>
    </div>
  </header>

  <form method="post" id="filterForm" aria-label="Filters">
    <div>
      <label class="small">Course</label>
      <select name="filter_course">
        <option value="">All courses</option>
        <option value="BSIT" <?= ($sel_filter_course === 'BSIT') ? 'selected' : '' ?>>Bachelor of Science in Information Technology (BSIT)</option>
        <option value="BEED" <?= ($sel_filter_course === 'BEED') ? 'selected' : '' ?>>Bachelor of Elementary Education (BEED)</option>
        <option value="BSBA" <?= ($sel_filter_course === 'BSBA') ? 'selected' : '' ?>>Bachelor of Science in Business Administration (BSBA)</option>
      </select>
    </div>
    <div>
      <label class="small">Year Level</label>
      <select name="filter_year_level">
        <option value="">All years</option>
        <option value="1st Year" <?= ($sel_filter_year_level === '1st Year') ? 'selected' : '' ?>>1st Year</option>
        <option value="2nd Year" <?= ($sel_filter_year_level === '2nd Year') ? 'selected' : '' ?>>2nd Year</option>
        <option value="3rd Year" <?= ($sel_filter_year_level === '3rd Year') ? 'selected' : '' ?>>3rd Year</option>
        <option value="4th Year" <?= ($sel_filter_year_level === '4th Year') ? 'selected' : '' ?>>4th Year</option>
      </select>
    </div>
    <div>
      <label class="small">Section</label>
      <select name="filter_section">
        <option value="">All sections</option>
        <option value="A" <?= ($sel_filter_section === 'A') ? 'selected' : '' ?>>A</option>
        <option value="B" <?= ($sel_filter_section === 'B') ? 'selected' : '' ?>>B</option>
      </select>
    </div>
    <div>
      <label class="small">Teacher</label>
      <select name="filter_teacher">
        <option value="">All teachers</option>
        <?php foreach ($faculty_rows as $fr): ?>
          <option value="<?= intval($fr['id']) ?>" <?= ($sel_filter_teacher == $fr['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($fr['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="small">Day</label>
      <select name="filter_day">
        <option value="">All days</option>
        <option value="Sunday" <?= ($sel_filter_day === 'Sunday') ? 'selected' : '' ?>>Sunday</option>
        <option value="Monday" <?= ($sel_filter_day === 'Monday') ? 'selected' : '' ?>>Monday</option>
        <option value="Tuesday" <?= ($sel_filter_day === 'Tuesday') ? 'selected' : '' ?>>Tuesday</option>
        <option value="Wednesday" <?= ($sel_filter_day === 'Wednesday') ? 'selected' : '' ?>>Wednesday</option>
        <option value="Thursday" <?= ($sel_filter_day === 'Thursday') ? 'selected' : '' ?>>Thursday</option>
        <option value="Friday" <?= ($sel_filter_day === 'Friday') ? 'selected' : '' ?>>Friday</option>
        <option value="Saturday" <?= ($sel_filter_day === 'Saturday') ? 'selected' : '' ?>>Saturday</option>
      </select>
    </div>
    <div>
      <label class="small">School Year</label>
      <input type="text" name="filter_school_year" value="<?= htmlspecialchars($sel_filter_school_year) ?>" placeholder="e.g. 2025-2026" />
    </div>
    <div>
      <label class="small">Semester</label>
      <select name="filter_semester">
        <option value="">All semesters</option>
        <option value="1st Semester" <?= ($sel_filter_semester === '1st Semester') ? 'selected' : '' ?>>1st Semester</option>
        <option value="2nd Semester" <?= ($sel_filter_semester === '2nd Semester') ? 'selected' : '' ?>>2nd Semester</option>
        <option value="Summer" <?= ($sel_filter_semester === 'Summer') ? 'selected' : '' ?>>Summer</option>
      </select>
    </div>
    <div>
      <label class="small">Search</label>
      <input type="text" name="search" placeholder="Subject or teacher" value="<?= htmlspecialchars($sel_search) ?>" />
    </div>
    <div>
      <label class="small">Per page</label>
      <select name="per_page">
        <option value="10" <?= ($sel_per_page == '10') ? 'selected' : '' ?>>10</option>
        <option value="25" <?= ($sel_per_page == '' || $sel_per_page == '25') ? 'selected' : '' ?>>25</option>
      </select>
    </div>
  </form>

  <!-- Modal form (hidden by default) -->
  <div id="scheduleModal" class="modal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="topbar-row" style="justify-content:space-between;margin-bottom:8px">
        <h2 id="modalTitle">
          <?php echo isset($editId) ? 'Edit Schedule' : 'New Schedule'; ?>
        </h2>
        <div><button type="button" class="btn ghost" id="closeModalBtn">Close</button></div>
      </div>
      <form method="post" id="scheduleForm">
        <input type="hidden" name="action" value="save_schedule">
        <?php if (!empty($error)): ?>
          <div style="background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;font-weight:600;">
            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>
        <div id="cs_conflict_alert" style="display:none;background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;font-weight:600;">
          <i class="fa-solid fa-triangle-exclamation"></i> <span id="cs_conflict_text"></span>
        </div>
        <?php if (isset($editId)): ?><input type="hidden" name="update_id" id="cs_update_id"
            value="<?= intval($editId) ?>" /><?php endif; ?>
        <div class="modal-grid">
          <div>
            <div class="field">
              <label>Course</label>
              <select name="course" id="course_select" required>
                <option value="">Select course...</option>
                <option value="BSIT" <?= (isset($course) && $course === 'BSIT') ? 'selected' : '' ?>>Bachelor of Science in Information Technology (BSIT)</option>
                <option value="BEED" <?= (isset($course) && $course === 'BEED') ? 'selected' : '' ?>>Bachelor of Elementary Education (BEED)</option>
                <option value="BSBA" <?= (isset($course) && $course === 'BSBA') ? 'selected' : '' ?>>Bachelor of Science in Business Administration (BSBA)</option>
              </select>
            </div>
            <div class="field">
              <label>Year Level</label>
              <select name="year_level" id="cs_year_level">
                <option value="">All years</option>
                <option value="1st Year" <?= (isset($year_level) && $year_level === '1st Year') ? 'selected' : '' ?>>1st
                  Year</option>
                <option value="2nd Year" <?= (isset($year_level) && $year_level === '2nd Year') ? 'selected' : '' ?>>2nd
                  Year</option>
                <option value="3rd Year" <?= (isset($year_level) && $year_level === '3rd Year') ? 'selected' : '' ?>>3rd
                  Year</option>
                <option value="4th Year" <?= (isset($year_level) && $year_level === '4th Year') ? 'selected' : '' ?>>4th
                  Year</option>
              </select>
            </div>
            <div class="field">
              <label>Section</label>
              <select name="section" id="cs_section">
                <option value="">All sections</option>
                <option value="A" <?= (isset($section) && $section === 'A') ? 'selected' : '' ?>>A</option>
                <option value="B" <?= (isset($section) && $section === 'B') ? 'selected' : '' ?>>B</option>
              </select>
            </div>
            <div class="field">
              <label>Subject</label>
              <input type="text" name="subject" id="cs_subject" required value="<?= htmlspecialchars($subject ?? '') ?>" placeholder="e.g. System Integration and Architecture" />
            </div>
          </div>
          <div>
            <div class="field">
              <label>Teacher</label>
              <select name="teacher_id" id="teacher_select">
                <option value="">Select registered faculty...</option>
                <?php foreach ($faculty_rows as $fr): ?>
                  <?php 
                    $is_sel = ((isset($_POST['teacher_id']) && intval($_POST['teacher_id']) === intval($fr['id'])) || 
                               (isset($teacher_id) && $teacher_id === intval($fr['id'])) || 
                               ($preset_teacher_id === intval($fr['id'])));
                  ?>
                  <option value="<?= intval($fr['id']) ?>" data-dept="<?= htmlspecialchars($fr['department'] ?? '') ?>" <?= $is_sel ? 'selected' : '' ?>>
                    <?= htmlspecialchars($fr['name']) ?>
                  </option>
                <?php endforeach; ?>
                <option value="0" <?= ((isset($_POST['teacher_id']) && intval($_POST['teacher_id']) === 0 && ($_POST['teacher_manual'] ?? '') !== '') || (isset($teacher_id) && $teacher_id === 0 && ($teacher_manual ?? '') !== '')) ? 'selected' : '' ?>>Other (manual)</option>
              </select>
              <input type="text" name="teacher_manual" id="teacher_manual" placeholder="Enter teacher name"
                value="<?= htmlspecialchars($_POST['teacher_manual'] ?? ($teacher_manual ?? '')) ?>" style="display: none;" />
            </div>
            <div class="field">
              <label>Days <span class="muted" style="font-weight:normal;font-size:12px;">(Select one or multiple)</span></label>
              <div class="day-chips">
                <?php
                $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                $selected_day_list = [];
                if (isset($_POST['days']) && is_array($_POST['days'])) {
                  $selected_day_list = $_POST['days'];
                } elseif (isset($day) && $day !== '') {
                  $selected_day_list = array_map('trim', explode(',', $day));
                }
                foreach ($weekdays as $wday):
                  $is_selected = in_array($wday, $selected_day_list, true) || 
                                 (isset($day) && strcasecmp(trim($day), $wday) === 0) ||
                                 (isset($day) && strcasecmp(trim($day), substr($wday, 0, 3)) === 0);
                ?>
                  <label class="day-chip-btn">
                    <input type="checkbox" name="days[]" value="<?= $wday ?>" <?= $is_selected ? 'checked' : '' ?> />
                    <span><?= substr($wday, 0, 3) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="field">
              <label>Room</label>
              <input type="text" name="room" id="cs_room" value="<?= htmlspecialchars($room ?? '') ?>" placeholder="e.g. Lab 102" />
            </div>
            <div class="time-inputs">
              <div class="time-input-col">
                <label>Start Time</label>
                <input type="time" name="start_time" id="cs_start_time" required value="<?= htmlspecialchars($start ?? '') ?>" />
              </div>
              <div class="time-input-col">
                <label>End Time</label>
                <input type="time" name="end_time" id="cs_end_time" required value="<?= htmlspecialchars($end ?? '') ?>" />
              </div>
            </div>

            <div class="field" style="margin-top:12px">
              <label><input type="checkbox" name="assign_all" <?= (isset($assign_all) && $assign_all) ? 'checked' : '' ?> /> Assign all students in selected course</label>
            </div>
          </div>
        </div>
        <?php if (isset($editId)): ?>
          <div class="assigned-students-section">
            <strong>Assign individual students</strong>
            <div class="student-list">
              <?php if (isset($students_for_assign) && $students_for_assign && $students_for_assign->num_rows): ?>
                <?php while ($stu = $students_for_assign->fetch_assoc()): ?>
                  <label><input type="checkbox" name="students[]"
                      value="<?= intval($stu['id']) ?>" <?= in_array((int) $stu['id'], $assigned_students ?? []) ? 'checked' : '' ?> /> <?= htmlspecialchars($stu['name']) ?></label>
                <?php endwhile; ?>
              <?php else: ?>
                <div class="muted">No students found for the selected course/year/section.</div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="form-submit-section" style="gap:10px;display:flex;justify-content:flex-end;margin-top:16px;">
          <?php if (!isset($editId)): ?>
            <button type="submit" name="save_and_another" class="btn ghost-accent"><i class="fa-solid fa-plus-circle"></i> Save & Add Another for Teacher</button>
          <?php endif; ?>
          <button type="submit" class="btn"><i class="fa-solid fa-check"></i> <?php echo isset($editId) ? 'Update Schedule' : 'Create Schedule'; ?></button>
        </div>
      </form>
    </div>
  </div>

  <?php
  // Prepare filters and pagination (use POST only)
  $request = $_POST;
  $filter_course = trim($request['filter_course'] ?? '');
  $filter_year_level = trim($request['filter_year_level'] ?? '');
  $filter_section = trim($request['filter_section'] ?? '');
  $filter_teacher = trim($request['filter_teacher'] ?? '');
  $filter_day = trim($request['filter_day'] ?? '');
  $search = trim($request['search'] ?? '');
  
  $is_post_empty = empty($request);
  $filter_school_year = $is_post_empty ? $active_school_year : trim($request['filter_school_year'] ?? '');
  $filter_semester = $is_post_empty ? $active_semester : trim($request['filter_semester'] ?? '');
  
  $page = max(1, intval($request['page'] ?? 1));
  $per_page = intval($request['per_page'] ?? 25);
  if (!in_array($per_page, [10, 25, 50, 100]))
    $per_page = 25;
  $offset = ($page - 1) * $per_page;

  $where = [];
  if ($filter_course !== '')
    $where[] = "s.course = '" . $conn->real_escape_string($filter_course) . "'";
  if ($filter_year_level !== '')
    $where[] = "s.year_level = '" . $conn->real_escape_string($filter_year_level) . "'";
  if ($filter_section !== '')
    $where[] = "s.section = '" . $conn->real_escape_string($filter_section) . "'";
  if ($filter_teacher !== '')
    $where[] = "s.teacher_id = " . intval($filter_teacher);
  if ($filter_day !== '')
    $where[] = "s.day LIKE '%" . $conn->real_escape_string($filter_day) . "%'";
  if ($filter_school_year !== '')
    $where[] = "s.school_year = '" . $conn->real_escape_string($filter_school_year) . "'";
  if ($filter_semester !== '')
    $where[] = "s.semester = '" . $conn->real_escape_string($filter_semester) . "'";
  if ($search !== '')
    $where[] = "(s.subject LIKE '%" . $conn->real_escape_string($search) . "%' OR s.teacher LIKE '%" . $conn->real_escape_string($search) . "%')";

  $whereSql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
  $totalRes = $conn->query("SELECT COUNT(*) AS total FROM schedules s " . $whereSql);
  $total = ($totalRes && $totalRes->num_rows) ? (int) $totalRes->fetch_assoc()['total'] : 0;
  $last_page = max(1, (int) ceil($total / $per_page));

  $res = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM schedule_students ss WHERE ss.schedule_id = s.id) AS assigned FROM schedules s " . $whereSql . " ORDER BY FIELD(day,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC LIMIT $offset, $per_page");
  ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>

          <th>Course</th>
          <th>Year Level</th>
          <th>Section</th>
          <th>Subject</th>
          <th>School Year</th>
          <th>Semester</th>
          <th>Teacher</th>
          <th>Day</th>
          <th>Time</th>
          <th>Room</th>
          <th>Assigned Students</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php
        if ($res) {
          while ($r = $res->fetch_assoc()):
            ?>
            <tr>

              <td><?= htmlspecialchars($r['course']) ?></td>
              <td><?= htmlspecialchars($r['year_level'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['section'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['subject']) ?></td>
              <td><?= htmlspecialchars($r['school_year'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['semester'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['teacher'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['day']) ?></td>
              <td>
                <?php if ($r['start_time'] && $r['end_time'])
                  echo htmlspecialchars(date('h:i A', strtotime($r['start_time'])) . ' - ' . date('h:i A', strtotime($r['end_time'])));
                else
                  echo '-'; ?>
              </td>
              <td><?= htmlspecialchars($r['room'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['assigned']) ?></td>
              <td class="actions">
                <?php if ($can_edit_schedule): ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="action" value="edit" />
                  <input type="hidden" name="id" value="<?= intval($r['id']) ?>" />
                  <input type="hidden" name="filter_course" value="<?= htmlspecialchars($filter_course) ?>" />
                  <input type="hidden" name="filter_year_level" value="<?= htmlspecialchars($filter_year_level) ?>" />
                  <input type="hidden" name="filter_section" value="<?= htmlspecialchars($filter_section) ?>" />
                  <input type="hidden" name="filter_teacher" value="<?= htmlspecialchars($filter_teacher) ?>" />
                  <input type="hidden" name="filter_day" value="<?= htmlspecialchars($filter_day) ?>" />
                  <input type="hidden" name="filter_school_year" value="<?= htmlspecialchars($filter_school_year) ?>" />
                  <input type="hidden" name="filter_semester" value="<?= htmlspecialchars($filter_semester) ?>" />
                  <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>" />
                  <input type="hidden" name="per_page" value="<?= htmlspecialchars($per_page) ?>" />
                  <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>" />
                  <button type="submit" class="edit-btn" title="Edit Schedule"><i class="fa-solid fa-pen-to-square"></i> <span>Edit</span></button>
                </form>
                <?php endif; ?>
                <?php if ($can_delete_schedule): ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Delete this schedule?')">
                  <input type="hidden" name="action" value="delete" />
                  <input type="hidden" name="id" value="<?= intval($r['id']) ?>" />
                  <input type="hidden" name="filter_course" value="<?= htmlspecialchars($filter_course) ?>" />
                  <input type="hidden" name="filter_year_level" value="<?= htmlspecialchars($filter_year_level) ?>" />
                  <input type="hidden" name="filter_section" value="<?= htmlspecialchars($filter_section) ?>" />
                  <input type="hidden" name="filter_teacher" value="<?= htmlspecialchars($filter_teacher) ?>" />
                  <input type="hidden" name="filter_day" value="<?= htmlspecialchars($filter_day) ?>" />
                  <input type="hidden" name="filter_school_year" value="<?= htmlspecialchars($filter_school_year) ?>" />
                  <input type="hidden" name="filter_semester" value="<?= htmlspecialchars($filter_semester) ?>" />
                  <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>" />
                  <input type="hidden" name="per_page" value="<?= htmlspecialchars($per_page) ?>" />
                  <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>" />
                  <button type="submit" class="delete-btn" title="Delete Schedule"><i class="fa-solid fa-trash-can"></i> <span>Delete</span></button>
                </form>
                <?php endif; ?>
                <?php if (!$can_edit_schedule && !$can_delete_schedule): ?>
                  <span style="color: #94a3b8; font-size: 0.85rem; font-style: italic;">No actions</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php
          endwhile;
        }
        ?>
      </tbody>
    </table>
  </div>

  <div class="pagination-section">
    <div>Showing <?= ($total > 0) ? ($offset + 1) : 0 ?> to <?= min($offset + $per_page, $total) ?> of <?= $total ?>
    </div>
    <div class="pagination-controls pager">
      <?php
      // Render pagination as POST forms to avoid GET links
      for ($p = 1; $p <= $last_page; $p++) {
        if ($p == 1 || $p == $last_page || abs($p - $page) <= 2) {
          if ($p == $page) {
            echo '<strong>' . $p . '</strong> ';
          } else {
            echo '<form method="post" class="inline-form">';
            echo '<input type="hidden" name="page" value="' . intval($p) . '" />';
            echo '<input type="hidden" name="per_page" value="' . htmlspecialchars($per_page) . '" />';
            echo '<input type="hidden" name="filter_course" value="' . htmlspecialchars($filter_course) . '" />';
            echo '<input type="hidden" name="filter_year_level" value="' . htmlspecialchars($filter_year_level) . '" />';
            echo '<input type="hidden" name="filter_section" value="' . htmlspecialchars($filter_section) . '" />';
            echo '<input type="hidden" name="filter_teacher" value="' . htmlspecialchars($filter_teacher) . '" />';
            echo '<input type="hidden" name="filter_day" value="' . htmlspecialchars($filter_day) . '" />';
            echo '<input type="hidden" name="filter_school_year" value="' . htmlspecialchars($filter_school_year) . '" />';
            echo '<input type="hidden" name="filter_semester" value="' . htmlspecialchars($filter_semester) . '" />';
            echo '<input type="hidden" name="search" value="' . htmlspecialchars($search) . '" />';
            echo '<button type="submit" class="pagination-btn">' . $p . '</button>';
            echo '</form> ';
          }
        } elseif ($p == $page - 3 || $p == $page + 3) {
          echo '... ';
        }
      }
      if ($page < $last_page) {
        $next = $page + 1;
        echo '<form method="post" class="inline-form">';
        echo '<input type="hidden" name="page" value="' . intval($next) . '" />';
        echo '<input type="hidden" name="per_page" value="' . htmlspecialchars($per_page) . '" />';
        echo '<input type="hidden" name="filter_course" value="' . htmlspecialchars($filter_course) . '" />';
        echo '<input type="hidden" name="filter_year_level" value="' . htmlspecialchars($filter_year_level) . '" />';
        echo '<input type="hidden" name="filter_section" value="' . htmlspecialchars($filter_section) . '" />';
        echo '<input type="hidden" name="filter_teacher" value="' . htmlspecialchars($filter_teacher) . '" />';
        echo '<input type="hidden" name="filter_day" value="' . htmlspecialchars($filter_day) . '" />';
        echo '<input type="hidden" name="filter_school_year" value="' . htmlspecialchars($filter_school_year) . '" />';
        echo '<input type="hidden" name="filter_semester" value="' . htmlspecialchars($filter_semester) . '" />';
        echo '<input type="hidden" name="search" value="' . htmlspecialchars($search) . '" />';
        echo '<button type="submit" class="pagination-btn">Next</button>';
        echo '</form>';
      }
      ?>
    </div>
  </div>
  <script>
    (function () {
      var modal = document.getElementById('scheduleModal');
      var openBtn = document.getElementById('openNewBtn');
      var closeBtn = document.getElementById('closeModalBtn');
      openBtn && openBtn.addEventListener('click', function () {
        var form = document.getElementById('scheduleForm');
        form && form.reset();
        var uid = form.querySelector('input[name=update_id]');
        if (uid) uid.parentNode.removeChild(uid);
        var dayBoxes = form.querySelectorAll('input[name="days[]"]');
        dayBoxes.forEach(function(b) { b.checked = false; });
        var title = document.getElementById('modalTitle');
        if (title) title.innerHTML = '<i class="fa-solid fa-calendar-plus"></i> New Schedule';
        modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false');
      });
      closeBtn && closeBtn.addEventListener('click', function () { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); });
      modal && modal.addEventListener('click', function (e) { if (e.target === modal) { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); } });
    })();
    document.addEventListener('DOMContentLoaded', function () {
      // Auto-remove success toast popup after 1 second
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
          if (url.searchParams.has('saved')) {
            url.searchParams.delete('saved');
            window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
          }
        } catch (err) {}
      }

      <?php if (isset($editId) || $open_new_modal || (!empty($error) && isset($_POST['action']) && $_POST['action'] === 'save_schedule')): ?>
        var m = document.getElementById('scheduleModal'); if (m) { m.classList.add('open'); m.setAttribute('aria-hidden', 'false'); }
      <?php endif; ?>

      // Validate schedule, times, and conflict checking
      var schedForm = document.getElementById('scheduleForm');
      var csConflictAlert = document.getElementById('cs_conflict_alert');
      var csConflictText = document.getElementById('cs_conflict_text');
      var csStartTimeInput = document.getElementById('cs_start_time');
      var csEndTimeInput = document.getElementById('cs_end_time');
      var csCourseSelect = document.getElementById('course_select');
      var csYearSelect = document.getElementById('cs_year_level');
      var csSectionSelect = document.getElementById('cs_section');
      var csRoomInput = document.getElementById('cs_room');
      var csUpdateIdInput = document.getElementById('cs_update_id');
      var csCheckTimer = null;

      function showCsConflictWarning(msg) {
        if (csConflictAlert && csConflictText) {
          csConflictText.textContent = msg;
          csConflictAlert.style.display = 'block';
        }
      }

      function clearCsConflictWarning() {
        if (csConflictAlert) {
          csConflictAlert.style.display = 'none';
        }
      }

      function runCsConflictCheck() {
        clearTimeout(csCheckTimer);
        csCheckTimer = setTimeout(function () {
          var start = csStartTimeInput ? csStartTimeInput.value : '';
          var end = csEndTimeInput ? csEndTimeInput.value : '';
          if (start && end && start >= end) {
            showCsConflictWarning('Invalid schedule time: End time must be later than start time.');
            return;
          }

          var checkedDays = [];
          if (schedForm) {
            var dayBoxes = schedForm.querySelectorAll('input[name="days[]"]:checked');
            dayBoxes.forEach(function (cb) { checkedDays.push(cb.value); });
          }

          if (!start || !end || checkedDays.length === 0) {
            clearCsConflictWarning();
            return;
          }

          var teacherSelectEl = document.getElementById('teacher_select');
          var teacherManualEl = document.getElementById('teacher_manual');

          var formData = new FormData();
          checkedDays.forEach(function (d) { formData.append('days[]', d); });
          formData.append('start_time', start);
          formData.append('end_time', end);
          formData.append('teacher_id', teacherSelectEl ? teacherSelectEl.value : '');
          formData.append('teacher_manual', teacherManualEl ? teacherManualEl.value : '');
          formData.append('room', csRoomInput ? csRoomInput.value : '');
          formData.append('course', csCourseSelect ? csCourseSelect.value : '');
          formData.append('year_level', csYearSelect ? csYearSelect.value : '');
          formData.append('section', csSectionSelect ? csSectionSelect.value : '');
          if (csUpdateIdInput && csUpdateIdInput.value) {
            formData.append('exclude_id', csUpdateIdInput.value);
          }

          fetch('check_schedule_conflict_ajax.php', {
            method: 'POST',
            body: formData
          })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data && data.conflict) {
              showCsConflictWarning(data.message);
            } else {
              clearCsConflictWarning();
            }
          })
          .catch(function () {});
        }, 300);
      }

      if (schedForm) {
        schedForm.querySelectorAll('input, select').forEach(function (el) {
          el.addEventListener('change', runCsConflictCheck);
          el.addEventListener('input', runCsConflictCheck);
        });

        schedForm.addEventListener('submit', function (e) {
          var checkedDays = schedForm.querySelectorAll('input[name="days[]"]:checked');
          if (checkedDays.length === 0) {
            e.preventDefault();
            alert('Please select at least one day for the schedule.');
            return false;
          }
          var start = csStartTimeInput ? csStartTimeInput.value : '';
          var end = csEndTimeInput ? csEndTimeInput.value : '';
          if (!start || !end) {
            e.preventDefault();
            alert('Please provide both start time and end time.');
            return false;
          }
          if (start >= end) {
            e.preventDefault();
            showCsConflictWarning('Invalid schedule time: End time must be later than start time.');
            alert('Invalid schedule time: End time must be later than start time.');
            return false;
          }
        });
      }

      // Filter faculty based on selected course
      var courseSelect = document.getElementById('course_select');
      var teacherSelect = document.getElementById('teacher_select');
      if (courseSelect && teacherSelect) {
        function filterTeachers() {
          var selectedCourse = courseSelect.value;
          var options = teacherSelect.querySelectorAll('option');
          options.forEach(function(option) {
            var dept = option.getAttribute('data-dept');
            if (selectedCourse === '' || dept === selectedCourse || option.value === '0' || option.selected) {
              option.style.display = '';
            } else {
              option.style.display = 'none';
            }
          });
        }
        courseSelect.addEventListener('change', filterTeachers);
        // Initial filter on load
        filterTeachers();
      }

      // Show/hide manual teacher input
      var teacherManualInput = document.getElementById('teacher_manual');
      if (teacherSelect && teacherManualInput) {
        function toggleManualInput() {
          if (teacherSelect.value === '0') {
            teacherManualInput.style.display = '';
          } else {
            teacherManualInput.style.display = 'none';
          }
        }
        teacherSelect.addEventListener('change', toggleManualInput);
        // Initial check on load
        toggleManualInput();
      }

      // Auto-refresh filters without needing Apply button
      (function () {
        var filterForm = document.getElementById('filterForm');
        if (!filterForm) return;

        var selects = filterForm.querySelectorAll('select');
        var searchInput = filterForm.querySelector('input[name="search"]');
        var syInput = filterForm.querySelector('input[name="filter_school_year"]');
        var debounceTimer = null;

        function submitFormWithFocus(input) {
          if (input) {
            try {
              sessionStorage.setItem('sched_filter_focused_field', input.name);
              sessionStorage.setItem('sched_filter_cursor_pos', input.selectionStart || 0);
            } catch (e) {}
          }
          filterForm.submit();
        }

        // Auto-submit on select changes
        selects.forEach(function (select) {
          select.addEventListener('change', function () {
            filterForm.submit();
          });
        });

        // Auto-submit on typing with debounce (350ms)
        [searchInput, syInput].forEach(function (input) {
          if (!input) return;
          input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
              submitFormWithFocus(input);
            }, 350);
          });

          input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              clearTimeout(debounceTimer);
              submitFormWithFocus(input);
            }
          });
        });

        // Restore focus and cursor position after form submit
        try {
          var focusedField = sessionStorage.getItem('sched_filter_focused_field');
          if (focusedField) {
            sessionStorage.removeItem('sched_filter_focused_field');
            var el = filterForm.querySelector('[name="' + focusedField + '"]');
            if (el) {
              el.focus();
              var pos = parseInt(sessionStorage.getItem('sched_filter_cursor_pos'), 10);
              sessionStorage.removeItem('sched_filter_cursor_pos');
              if (!isNaN(pos) && el.setSelectionRange) {
                el.setSelectionRange(pos, pos);
              }
            }
          }
        } catch (e) {}
      })();
    });
  </script>

</div>