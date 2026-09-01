<?php
include('../db.php');
include('auth.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<div style="padding:12px;color:#6b7280">Method not allowed. Use POST.</div>';
    exit;
}

$course = trim($_POST['course'] ?? '');
$year = trim($_POST['year_level'] ?? '');
$section = trim($_POST['section'] ?? '');
$teacher = trim($_POST['teacher'] ?? '');

if ($course === '') {
    echo '<div style="padding:12px;color:#6b7280">Invalid course.</div>';
    exit;
}

$c = $conn->real_escape_string($course);
$where = "WHERE course = '" . $c . "'";
if ($year !== '') $where .= " AND year_level = '" . $conn->real_escape_string($year) . "'";
if ($section !== '') $where .= " AND section = '" . $conn->real_escape_string($section) . "'";
if ($teacher !== '') $where .= " AND teacher = '" . $conn->real_escape_string($teacher) . "'";

$q = "SELECT id, course, year_level, section, subject, teacher, day, start_time, end_time, room FROM schedules " . $where . " ORDER BY FIELD(day,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC";
$res = $conn->query($q);

if (!$res || $res->num_rows === 0) {
    echo '<div style="padding:12px">No schedules found for ' . htmlspecialchars($course) . '.</div>';
    exit;
}

echo '<table style="width:100%;border-collapse:collapse"><thead><tr style="text-align:left"><th>Course</th><th>Year</th><th>Section</th><th>Subject</th><th>Teacher</th><th>Day</th><th>Time</th><th>Room</th></tr></thead><tbody>';
while ($r = $res->fetch_assoc()) {
    $time = '-';
    if ($r['start_time'] && $r['end_time']) $time = date('h:i A', strtotime($r['start_time'])) . ' - ' . date('h:i A', strtotime($r['end_time']));
    echo '<tr style="border-top:1px solid #eee"><td>' . htmlspecialchars($r['course']) . '</td><td>' . htmlspecialchars($r['year_level'] ?? '-') . '</td><td>' . htmlspecialchars($r['section'] ?? '-') . '</td><td>' . htmlspecialchars($r['subject']) . '</td><td>' . htmlspecialchars($r['teacher'] ?? '-') . '</td><td>' . htmlspecialchars($r['day'] ?? '-') . '</td><td>' . $time . '</td><td>' . htmlspecialchars($r['room'] ?? '-') . '</td></tr>';
}
echo '</tbody></table>';

?>
