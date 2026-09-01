<?php
include('../db.php');
include('auth.php');
date_default_timezone_set('Asia/Manila');

$teacher_id = intval($_SESSION['faculty_id'] ?? 0);
$teacher_name = $_SESSION['faculty_name'] ?? $_SESSION['faculty_email'] ?? '';

// Fetch schedules for this faculty
$schedules_sql = "SELECT id, course, year_level, section, subject, start_time, day FROM schedules WHERE (teacher_id = $teacher_id OR LOWER(teacher) = LOWER('" . $conn->real_escape_string($teacher_name) . "')) ORDER BY FIELD(day,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC";
$scheds_res = $conn->query($schedules_sql);
$schedules = [];
if ($scheds_res) {
    while ($r = $scheds_res->fetch_assoc())
        $schedules[] = $r;
}

// Filters
$schedule_id_raw = $_POST['schedule_id'] ?? (!empty($schedules) ? $schedules[0]['id'] : '');
$has_selected_schedule = ($schedule_id_raw !== '');
$selected_schedule = intval($schedule_id_raw);

$start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$end_date = $_POST['end_date'] ?? date('Y-m-d');

$print_subject = "All Subjects";
$print_class = "All Classes";

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

if ($has_selected_schedule) {
    foreach ($schedules as $sched) {
        if (intval($sched['id']) === $selected_schedule) {
            $print_subject = $sched['subject'];
            $print_class = $sched['course'] . ' ' . $sched['year_level'] . ' ' . $sched['section'];
            break;
        }
    }
}

$report_data = [];
$where = "a.attendance_date BETWEEN '" . $conn->real_escape_string($start_date) . "' AND '" . $conn->real_escape_string($end_date) . "'";
if ($has_selected_schedule) {
    $where .= " AND a.schedule_id = " . intval($selected_schedule);
} else {
    $sched_ids = array_column($schedules, 'id');
    if (!empty($sched_ids)) {
        $where .= " AND a.schedule_id IN (" . implode(',', $sched_ids) . ")";
    } else {
        $where .= " AND 1=0"; // No schedules found
    }
}

$sql = "SELECT a.*, s.subject, s.course, s.year_level, s.section, s.start_time, s.day
        FROM attendance a
        JOIN schedules s ON a.schedule_id = s.id
        WHERE $where
        ORDER BY a.attendance_date DESC, s.subject ASC, a.name ASC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $report_data[] = $row;
    }
}

// Handle Export
if (isset($_POST['action']) && $_POST['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_report_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Subject', 'Class', 'Student', 'Status', 'Time In']);
    foreach ($report_data as $row) {
        fputcsv($output, [
            $row['attendance_date'],
            $row['subject'],
            $row['course'] . ' ' . $row['year_level'] . ' ' . $row['section'],
            $row['name'],
            $row['status'],
            $row['time_logged'] ? date('h:i A', strtotime($row['time_logged'])) : '-'
        ]);
    }
    fclose($output);
    exit;
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Faculty - Reports</title>
    <link rel="stylesheet" href="faculty_assets/faculty_reports.css">
    <link rel="stylesheet" href="faculty_assets/faculty_sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="app">

        <?php include('navbar.php'); ?>

        <main class="content">
            <div class="header">
                <div class="title">Attendance Reports</div>
            </div>

            <div class="filters">
                <form method="POST" class="filters-form">
                    <label for="schedule_id">Schedule:</label>
                    <select name="schedule_id" id="schedule_id">
                        <?php foreach ($schedules as $sched): ?>
                            <option value="<?= intval($sched['id']) ?>" <?= ($has_selected_schedule && $selected_schedule === intval($sched['id'])) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sched['subject']) ?> — <?= htmlspecialchars($sched['course'] . ' ' . $sched['year_level'] . ' ' . $sched['section']) ?> (<?= date('h:i A', strtotime($sched['start_time'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="start_date">From:</label>
                    <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>">

                    <label for="end_date">To:</label>
                    <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>">

                    <div style="display:flex; gap:8px;">
                        <button type="submit" name="action" value="view" class="btn">View Report</button>
                        <button type="submit" name="action" value="export_csv" class="btn" style="background:#10b981;">Export Excel</button>
                        <button type="button" onclick="window.print()" class="btn" style="background:#ef4444;">Export PDF</button>
                    </div>
                </form>
            </div>

            <div class="print-header">
                <img src="<?= htmlspecialchars($print_logo) ?>" alt="Department Logo" class="print-logo">
                <div class="print-text-content">
                    <div class="print-title">Student Attendance report</div>
                    <div class="print-subtitle">
                        <div><strong>Department:</strong> <?= htmlspecialchars($print_dept) ?></div>
                        <div><strong>Subject:</strong> <?= htmlspecialchars($print_subject) ?></div>
                        <div><strong>Class:</strong> <?= htmlspecialchars($print_class) ?></div>
                    </div>
                </div>
                <img src="../assets/neust_logo.png" alt="NEUST Logo" class="print-logo">
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Subject</th>
                            <th>Class</th>
                            <th>Student</th>
                            <th>Status</th>
                            <th>Time In</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr><td colspan="6">No attendance records found.</td></tr>
                        <?php else:
                            foreach ($report_data as $row): 
                                $s = strtolower($row['status']);
                                $cls = ($s == 'present') ? 'present' : (($s == 'late') ? 'late' : (($s == 'absent') ? 'absent' : 'pending'));
                        ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['attendance_date']) ?></td>
                                    <td><?= htmlspecialchars($row['subject']) ?></td>
                                    <td><?= htmlspecialchars($row['course'] . ' ' . $row['year_level'] . ' ' . $row['section']) ?></td>
                                    <td><?= htmlspecialchars($row['name']) ?></td>
                                    <td class="<?= $cls ?>"><?= htmlspecialchars($row['status']) ?></td>
                                    <td><?= $row['time_logged'] ? date('h:i A', strtotime($row['time_logged'])) : '-' ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

</body>
</html>