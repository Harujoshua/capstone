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

echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;background:#f8fafc;padding:12px 16px;border-radius:12px;border:1px solid #e2e8f0;">';
echo '  <div><strong style="color:#0f172a;font-size:15px;"><i class="fa-solid fa-chalkboard-user" style="color:#2563eb;margin-right:6px;"></i>' . htmlspecialchars($fname) . '</strong> <span style="color:#64748b;font-size:13px;margin-left:6px;">(' . ($res ? $res->num_rows : 0) . ' schedules assigned)</span></div>';
echo '</div>';

if(!$res || $res->num_rows === 0){
  echo '<div style="padding:24px;text-align:center;color:#64748b;background:#fff;border-radius:10px;border:1px dashed #cbd5e1;"><i class="fa-solid fa-calendar-xmark" style="font-size:2rem;color:#cbd5e1;display:block;margin-bottom:10px;"></i>No schedules found for ' . htmlspecialchars($fname) . '.</div>';
  exit;
}

echo '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="text-align:left;background:#f1f5f9;color:#475569;"><th style="padding:8px 10px;border-radius:6px 0 0 6px;">Course</th><th style="padding:8px 10px;">Year</th><th style="padding:8px 10px;">Section</th><th style="padding:8px 10px;">Subject</th><th style="padding:8px 10px;">Day</th><th style="padding:8px 10px;">Time</th><th style="padding:8px 10px;border-radius:0 6px 6px 0;">Room</th></tr></thead><tbody>';
while($r = $res->fetch_assoc()){
  $time = '-';
  if($r['start_time'] && $r['end_time']) $time = date('h:i A', strtotime($r['start_time'])) . ' - ' . date('h:i A', strtotime($r['end_time']));
  echo '<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:10px;font-weight:600;color:#1e293b;">' . htmlspecialchars($r['course']) . '</td><td style="padding:10px;color:#475569;">' . htmlspecialchars($r['year_level'] ?? '-') . '</td><td style="padding:10px;color:#475569;">' . htmlspecialchars($r['section'] ?? '-') . '</td><td style="padding:10px;color:#0f172a;font-weight:500;">' . htmlspecialchars($r['subject']) . '</td><td style="padding:10px;"><span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;">' . htmlspecialchars($r['day'] ?? '-') . '</span></td><td style="padding:10px;color:#475569;">' . $time . '</td><td style="padding:10px;color:#475569;">' . htmlspecialchars($r['room'] ?? '-') . '</td></tr>';
}
echo '</tbody></table>';

?>