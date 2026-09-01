<?php
include('../db.php');
include('auth.php');

$teacher_id = intval($_SESSION['faculty_id'] ?? 0);
$teacher_name = $_SESSION['faculty_name'] ?? $_SESSION['faculty_email'] ?? '';

// fetch schedules for this faculty
$schedules_sql = "SELECT id, course, year_level, section, subject, start_time, day FROM schedules WHERE (teacher_id = $teacher_id OR LOWER(teacher) = LOWER('" . $conn->real_escape_string($teacher_name) . "')) ORDER BY FIELD(day,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC";
$scheds_res = $conn->query($schedules_sql);
$schedules = [];
if ($scheds_res) {
    while ($r = $scheds_res->fetch_assoc())
        $schedules[] = $r;
}

$schedule_id_raw = $_POST['schedule_id'] ?? 'all';
$has_selected_schedule = ($schedule_id_raw !== 'all' && $schedule_id_raw !== '');
$schedule_id = intval($schedule_id_raw);

$q = trim($_POST['q'] ?? '');
$q_esc = $conn->real_escape_string($q);

$students = [];
// Try to load students for selected schedule
if ($has_selected_schedule) {
    // prefer schedule_students table when available
    $has_ss = false;
    $chk = $conn->query("SHOW TABLES LIKE 'schedule_students'");
    if ($chk && $chk->num_rows > 0)
        $has_ss = true;

    if ($has_ss) {
        $sql = "SELECT s.* FROM schedule_students ss JOIN students s ON ss.student_id = s.id WHERE ss.schedule_id = " . intval($schedule_id);
        if ($q !== '')
            $sql .= " AND (s.name LIKE '%" . $q_esc . "%' OR s.rfid_uid LIKE '%" . $q_esc . "%')";
        $sql .= " ORDER BY s.name ASC";
        $res = $conn->query($sql);
    } else {
        // fallback: use schedule's course/year/section to list students
        $sres = $conn->query("SELECT * FROM schedules WHERE id=" . intval($schedule_id) . " LIMIT 1");
        if ($sres && $sres->num_rows > 0) {
            $sched = $sres->fetch_assoc();
            $course = $conn->real_escape_string($sched['course'] ?? '');
            $year = $conn->real_escape_string($sched['year_level'] ?? '');
            $section = $conn->real_escape_string($sched['section'] ?? '');
            
            $clauses = [];
            if ($course !== '') $clauses[] = "course='" . $course . "'";
            if ($year !== '') $clauses[] = "year_level='" . $year . "'";
            if ($section !== '') $clauses[] = "section='" . $section . "'";
            
            $where_course = count($clauses) > 0 ? implode(' AND ', $clauses) : "1=0"; // if all empty, return none
            
            $sql = "SELECT * FROM students WHERE " . $where_course;
            if ($q !== '')
                $sql .= " AND (name LIKE '%" . $q_esc . "%' OR rfid_uid LIKE '%" . $q_esc . "%')";
            $sql .= " ORDER BY name ASC";
            $res = $conn->query($sql);
        } else {
            $res = null;
        }
    }

    if (isset($res) && $res && $res->num_rows > 0) {
        while ($r = $res->fetch_assoc())
            $students[] = $r;
    }

} else if ($q !== '') {
    // global search across students
    $sql = "SELECT * FROM students WHERE name LIKE '%" . $q_esc . "%' OR rfid_uid LIKE '%" . $q_esc . "%' ORDER BY name ASC LIMIT 500";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        while ($r = $res->fetch_assoc())
            $students[] = $r;
    }

} else {
    // No schedule selected and no search: list students assigned to this teacher across schedules
    $students = [];
    $has_ss = false;
    $chk = $conn->query("SHOW TABLES LIKE 'schedule_students'");
    if ($chk && $chk->num_rows > 0)
        $has_ss = true;

    $safe_teacher = $conn->real_escape_string($teacher_name);
    $conds = [];
    if ($teacher_id > 0)
        $conds[] = "sc.teacher_id = " . intval($teacher_id);
    if ($safe_teacher !== '')
        $conds[] = "LOWER(sc.teacher) = LOWER('" . $safe_teacher . "')";

    if (count($conds) === 0) {
        // nothing to match
    } else if ($has_ss) {
        $where = implode(' OR ', $conds);
        $sql = "SELECT DISTINCT s.* FROM schedule_students ss JOIN schedules sc ON ss.schedule_id = sc.id JOIN students s ON ss.student_id = s.id WHERE (" . $where . ") ORDER BY s.name ASC";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            while ($r = $res->fetch_assoc())
                $students[] = $r;
        }
    } else {
        // fallback: collect schedules taught by teacher then fetch students matching course/year/section
        $where = implode(' OR ', $conds);
        $sq = "SELECT DISTINCT course, year_level, section FROM schedules sc WHERE (" . $where . ")";
        $sr = $conn->query($sq);
        if ($sr && $sr->num_rows > 0) {
            $clauses = [];
            while ($row = $sr->fetch_assoc()) {
                $course = $conn->real_escape_string($row['course'] ?? '');
                $year = $conn->real_escape_string($row['year_level'] ?? '');
                $section = $conn->real_escape_string($row['section'] ?? '');
                if ($course === '' && $year === '' && $section === '')
                    continue;
                $cl = " (course = '" . $course . "'";
                if ($year !== '')
                    $cl .= " AND year_level = '" . $year . "'";
                if ($section !== '')
                    $cl .= " AND section = '" . $section . "'";
                $cl .= " )";
                $clauses[] = $cl;
            }
            if (count($clauses) > 0) {
                $whereClause = implode(' OR ', $clauses);
                $sql = "SELECT DISTINCT * FROM students WHERE " . $whereClause . " ORDER BY name ASC";
                $res = $conn->query($sql);
                if ($res && $res->num_rows > 0) {
                    while ($r = $res->fetch_assoc())
                        $students[] = $r;
                }
            }
        }
    }
}

?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Faculty - Students</title>
    <link rel="stylesheet" href="faculty_assets/faculty_students.css">
    <link rel="stylesheet" href="faculty_assets/faculty_sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>

<body>
    <div class="app">

        <?php include('navbar.php'); ?>

        <main class="content">
            <div class="header">
                <div class="title">Students</div>
            </div>

            <div class="card">
                <form method="post" class="filters-form">
                    <label>Class:
                        <select name="schedule_id">
    <option value="all">— All / choose class —</option>
    <?php foreach ($schedules as $s):
        $time_label = isset($s['start_time']) ? ' (' . date('h:i A', strtotime($s['start_time'])) . ')' : '';
        $label = ($s['subject'] ?? '') . ' — ' . ($s['course'] ?? '') . ' ' . ($s['year_level'] ?? '') . ' ' . ($s['section'] ?? '') . $time_label;
        $sel = ($has_selected_schedule && $schedule_id === intval($s['id'])) ? 'selected' : '';
        ?>
        <option value="<?= intval($s['id']) ?>" <?= $sel ?>><?= htmlspecialchars($label) ?></option>
    <?php endforeach; ?>
</select>
                    </label>

                    <label>Search:
                        <input type="text" name="q" placeholder="name, rfid or id" value="<?= htmlspecialchars($q) ?>">
                    </label>

                    <button type="submit" class="btn">Search</button>
                    <button type="button" onclick="window.print()" class="btn" style="background:#ef4444; color: white; height: 38px;">
                        <i class="fas fa-print"></i> Print List
                    </button>
                </form>
            </div>
            <?php
            $faculty_dept_code = $_SESSION['faculty_dept'] ?? '';
            $print_dept = "College of Information and Communications Technology";
            $print_logo = "../assets/CICT.png";

            if ($faculty_dept_code === 'BSIT') {
                $print_dept = "College of Information and Communications Technology - Bachelor of Science in Information Technology";
                $print_logo = "../assets/CICT.png";
            } elseif ($faculty_dept_code === 'BEED') {
                $print_dept = "College of Education - Bachelor of Elementary Education";
                $print_logo = "../assets/COED.png";
            } elseif ($faculty_dept_code === 'BSBA') {
                $print_dept = "College of Management and Business Technology - Bachelor of Science in Business Administration";
                $print_logo = "../assets/BSBA.png";
            }
            ?>
            <div class="print-header">
                <img src="<?= htmlspecialchars($print_logo) ?>" alt="Department Logo" class="print-logo">
                <div class="print-text-content">
                    <div class="print-title">Students Lists</div>
                    <div class="print-subtitle">
                        <?php
                        $print_label = "All Students";
                        if ($has_selected_schedule) {
                            foreach ($schedules as $s) {
                                if (intval($s['id']) === $schedule_id) {
                                    $print_label = ($s['subject'] ?? '') . ' — ' . ($s['course'] ?? '') . ' ' . ($s['year_level'] ?? '') . ' ' . ($s['section'] ?? '');
                                    break;
                                }
                            }
                        }
                        ?>
                        <div><strong>Department:</strong> <?= htmlspecialchars($print_dept) ?></div>
                        <div><strong>Faculty:</strong> <?= htmlspecialchars($_SESSION['faculty_name'] ?? 'N/A') ?></div>
                        <div><strong>Class:</strong> <?= htmlspecialchars($print_label) ?></div>
                        <div><strong>Date:</strong> <?= date('F d, Y') ?></div>
                    </div>
                </div>
                <img src="../assets/neust_logo.png" alt="NEUST Logo" class="print-logo">
            </div>

            <?php if (count($students) === 0): ?>
                <div class="card">No students found. Select a class or try a search.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>RFID</th>
                                <th>Course</th>
                                <th>Year</th>
                                <th>Section</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1;
                            foreach ($students as $st): ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= htmlspecialchars($st['name'] ?? $st['full_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($st['rfid_uid'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($st['course'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($st['year_level'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($st['section'] ?? '') ?></td>
                                    <td>
                                        <button type="button" class="btn view-student-btn" data-student-id="<?= intval($st['id']) ?>">View Attendance history</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <div id="studentModal" class="modal">
        <div class="modal-content">
            <button class="modal-close" id="modalClose">&times;</button>
            <div id="modalBody" class="modal-body"></div>
        </div>
    </div>


    <script>
        // Modal functionality
        var modal = document.getElementById('studentModal');
        var modalClose = document.getElementById('modalClose');
        var modalBody = document.getElementById('modalBody');
        var viewBtns = document.querySelectorAll('.view-student-btn');

        modalClose.addEventListener('click', function () {
            modal.classList.remove('open');
            modalBody.innerHTML = '';
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.classList.remove('open');
                modalBody.innerHTML = '';
            }
        });

        viewBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var studentId = this.getAttribute('data-student-id');
                modalBody.innerHTML = '<div class="loading"><p>Loading...</p></div>';
                modal.classList.add('open');

                var formData = new FormData();
                formData.append('id', studentId);

                fetch('student.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function (response) { return response.text(); })
                .then(function (html) {
                    modalBody.innerHTML = html;
                })
                .catch(function (error) {
                    modalBody.innerHTML = '<div class="error-message">Error loading student data</div>';
                    console.error('Error:', error);
                });
            });
        });
    </script>
</body>

</html>