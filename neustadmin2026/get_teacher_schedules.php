<?php
include('../db.php');
include('auth.php');

// This endpoint accepts POST requests only
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  http_response_code(405);
  echo '<div style="padding:12px;color:#6b7280">Method not allowed. Use POST.</div>';
  exit;
}

$id = intval($_POST['id'] ?? 0);
if($id <= 0){
  echo '<div style="padding:12px;color:#6b7280">Invalid faculty id.</div>';
  exit;
}

// Fetch faculty name
$fres = $conn->query("SELECT name FROM faculty WHERE id = " . $id . " LIMIT 1");
if(!$fres || $fres->num_rows === 0){
  echo '<div style="padding:12px;color:#6b7280">Faculty not found.</div>';
  exit;
}
$frow = $fres->fetch_assoc();
$fname = $frow['name'];

// Fetch schedules linked by teacher_id or by matching teacher name if teacher_id is null
$stmt = $conn->prepare("SELECT id, course, year_level, section, subject, day, start_time, end_time, room FROM schedules WHERE teacher_id = ? OR (teacher_id IS NULL AND TRIM(teacher) = ?) ORDER BY FIELD(day,'Mon','Tue','Wed','Thu','Fri','Sat','Sun'), start_time ASC");
$stmt->bind_param('is', $id, $fname);
$stmt->execute();
$res = $stmt->get_result();

if(!$res || $res->num_rows === 0){
  echo '<div style="padding:12px">No schedules found for ' . htmlspecialchars($fname) . '.</div>';
  exit;
}

echo '<table style="width:100%;border-collapse:collapse"><thead><tr style="text-align:left"><th>Course</th><th>Year</th><th>Section</th><th>Subject</th><th>Day</th><th>Time</th><th>Room</th></tr></thead><tbody>';
while($r = $res->fetch_assoc()){
  $time = '-';
  if($r['start_time'] && $r['end_time']) $time = date('h:i A', strtotime($r['start_time'])) . ' - ' . date('h:i A', strtotime($r['end_time']));
  echo '<tr style="border-top:1px solid #eee"><td>' . htmlspecialchars($r['course']) . '</td><td>' . htmlspecialchars($r['year_level'] ?? '-') . '</td><td>' . htmlspecialchars($r['section'] ?? '-') . '</td><td>' . htmlspecialchars($r['subject']) . '</td><td>' . htmlspecialchars($r['day'] ?? '-') . '</td><td>' . $time . '</td><td>' . htmlspecialchars($r['room'] ?? '-') . '</td></tr>';
}
echo '</tbody></table>';

?>