<?php
include('../db.php');
include('auth.php');

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    echo "<p>Invalid student id.</p>";
    exit;
}

$res = $conn->query("SELECT * FROM students WHERE id=" . intval($id) . " LIMIT 1");
if (!$res || $res->num_rows === 0) {
    echo "<p>Student not found.</p>";
    exit;
}
$student = $res->fetch_assoc();

// detect attendance table and columns (reuse logic from attendance.php)
$attendance_table = null;
$check = $conn->query("SHOW TABLES LIKE 'attendance'");
if ($check && $check->num_rows > 0)
    $attendance_table = 'attendance';
else {
    $check2 = $conn->query("SHOW TABLES LIKE 'attendances'");
    if ($check2 && $check2->num_rows > 0)
        $attendance_table = 'attendances';
}

$att_cols = [];
if ($attendance_table) {
    $colsRes = $conn->query("SHOW COLUMNS FROM " . $attendance_table);
    if ($colsRes) {
        while ($c = $colsRes->fetch_assoc())
            $att_cols[] = $c['Field'];
    }
}

$dateCol = null;
foreach (['created_at', 'date', 'attendance_date', 'date_time', 'att_date'] as $c)
    if (in_array($c, $att_cols)) {
        $dateCol = $c;
        break;
    }
$statusCol = null;
foreach (['status', 'attendance_status', 'state', 'att_status'] as $c)
    if (in_array($c, $att_cols)) {
        $statusCol = $c;
        break;
    }
$schedCol = null;
foreach (['schedule_id', 'schedule', 'class_id', 'class_schedule_id', 'classid'] as $c)
    if (in_array($c, $att_cols)) {
        $schedCol = $c;
        break;
    }
$studentCol = null;
foreach (['student_id', 'student', 'student_uid', 'rfid_uid', 'rfid', 'uid', 'student_rfid'] as $c)
    if (in_array($c, $att_cols)) {
        $studentCol = $c;
        break;
    }

$attendance_rows = [];
if ($attendance_table && $studentCol) {
    if ($studentCol === 'student_id') {
        $aq = "SELECT a.*, s.subject, s.start_time, s.course, s.year_level, s.section FROM $attendance_table a LEFT JOIN schedules s ON a.$schedCol = s.id WHERE a.$studentCol = " . intval($id);
    } else {
        $rf = $conn->real_escape_string($student['rfid_uid'] ?? $student['rfid'] ?? '');
        if ($rf === '')
            $rf = $conn->real_escape_string($student['id']);
        $aq = "SELECT a.*, s.subject, s.start_time, s.course, s.year_level, s.section FROM $attendance_table a LEFT JOIN schedules s ON a.$schedCol = s.id WHERE a.$studentCol = '" . $rf . "'";
    }
    if (isset($aq)) {
        if ($dateCol)
            $aq .= " ORDER BY a.$dateCol DESC";
        else
            $aq .= " ORDER BY a.id DESC";
        $aq .= " LIMIT 1000";
        $ar = $conn->query($aq);
        if ($ar && $ar->num_rows > 0) {
            while ($r = $ar->fetch_assoc())
                $attendance_rows[] = $r;
        }
    }
}

$logs_rows = [];
$log_table = null;
$log_cols = [];
$log_time_in_col = null;
$log_time_out_col = null;
$log_status_col = null;
$log_rfid_col = null;

$checkLogs = $conn->query("SHOW TABLES LIKE 'logs'");
if ($checkLogs && $checkLogs->num_rows > 0) {
    $log_table = 'logs';
    $colsRes = $conn->query("SHOW COLUMNS FROM logs");
    if ($colsRes) {
        while ($c = $colsRes->fetch_assoc())
            $log_cols[] = $c['Field'];
    }
    if (in_array('time_in', $log_cols))
        $log_time_in_col = 'time_in';
    if (in_array('time_out', $log_cols))
        $log_time_out_col = 'time_out';
    if (in_array('status', $log_cols))
        $log_status_col = 'status';
    if (in_array('rfid_uid', $log_cols))
        $log_rfid_col = 'rfid_uid';
    elseif (in_array('rfid', $log_cols))
        $log_rfid_col = 'rfid';
    elseif (in_array('uid', $log_cols))
        $log_rfid_col = 'uid';
}

if ($log_table && $log_rfid_col && $log_time_in_col) {
    $rf = $conn->real_escape_string($student['rfid_uid'] ?? $student['rfid'] ?? '');
    if ($rf === '')
        $rf = $conn->real_escape_string($student['id']);

    $log_sql = "SELECT * FROM logs WHERE $log_rfid_col = '" . $rf . "'";
    if ($log_rfid_col !== 'rfid_uid' && in_array('rfid_uid', $log_cols) && !empty($student['rfid_uid'])) {
        $log_sql .= " OR rfid_uid = '" . $conn->real_escape_string($student['rfid_uid']) . "'";
    }
    if ($log_time_out_col) {
        $log_sql .= " ORDER BY COALESCE($log_time_in_col, $log_time_out_col) DESC";
    } else {
        $log_sql .= " ORDER BY $log_time_in_col DESC";
    }
    $log_sql .= " LIMIT 1000";
    $lr = $conn->query($log_sql);
    if ($lr && $lr->num_rows > 0) {
        while ($r = $lr->fetch_assoc())
            $logs_rows[] = $r;
    }
}

?>
<div class="profile">
    <h2><?= htmlspecialchars($student['name'] ?? '') ?></h2>
    <div><strong>RFID:</strong> <?= htmlspecialchars($student['rfid_uid'] ?? $student['rfid'] ?? '') ?></div>
    <div><strong>Course:</strong> <?= htmlspecialchars($student['course'] ?? '') ?> &nbsp;
        <strong>Year:</strong> <?= htmlspecialchars($student['year_level'] ?? '') ?> &nbsp;
        <strong>Section:</strong> <?= htmlspecialchars($student['section'] ?? '') ?></div>
    <?php if (!empty($student['parent_name']) || !empty($student['parent_email'])): ?>
        <div style="margin-top:6px"><strong>Parent:</strong>
            <?= htmlspecialchars($student['parent_name'] ?? '') ?> —
            <?= htmlspecialchars($student['parent_email'] ?? '') ?>    <?= !empty($student['parent_email2']) ? (' / ' . htmlspecialchars($student['parent_email2'])) : '' ?>
        </div>
    <?php endif; ?>
</div>

<h3>Attendance History</h3>

<?php if (!empty($logs_rows)): ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Time In</th>
                <th>Time Out</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1;
            foreach ($logs_rows as $r): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td>
                        <?php
                        $dateValue = null;
                        if (!empty($r['time_in'])) {
                            $dateValue = date('Y-m-d', strtotime($r['time_in']));
                        } elseif (!empty($r['time_out'])) {
                            $dateValue = date('Y-m-d', strtotime($r['time_out']));
                        }
                        echo htmlspecialchars($dateValue ?? '-');
                        ?>
                    </td>
                    <td><?= (!empty($r['time_in']) && $r['time_in'] !== '-') ? date('h:i A', strtotime($r['time_in'])) : '-' ?></td>
                    <td><?= (!empty($r['time_out']) && $r['time_out'] !== '-') ? date('h:i A', strtotime($r['time_out'])) : '-' ?></td>
                    <td><?= htmlspecialchars($r['status'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php elseif (!$attendance_table && empty($logs_rows)): ?>
    <div class="card">Attendance data not available (no attendance or logs table found).</div>
<?php elseif (empty($attendance_rows)): ?>
    <div class="card">No attendance records found for this student.</div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date / Time</th>
                <th>Status</th>
                <th>Class</th>
                <th>Subject</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1;
            foreach ($attendance_rows as $r): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($r[$dateCol] ?? $r['created_at'] ?? $r['time'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r[$statusCol] ?? $r['status'] ?? '') ?></td>
                    <td><?= htmlspecialchars(($r['course'] ?? '') . ' ' . ($r['year_level'] ?? '') . ' ' . ($r['section'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($r['subject'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
