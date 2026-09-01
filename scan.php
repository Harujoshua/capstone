<?php
require_once 'mailer.php';
date_default_timezone_set('Asia/Manila');
$conn = new mysqli("localhost","root","","neust_gatepass_v3");

$uid = strtoupper(trim($_POST['uid']));
// Support offline timestamp sync
$timestamp = isset($_POST['ts']) ? intval($_POST['ts']) : time();
$db_now = date('Y-m-d H:i:s', $timestamp);
$today_date = date('Y-m-d', $timestamp);
$current_time_only = date('H:i:s', $timestamp);

function send_response_and_close($message) {
    if (ob_get_level()) ob_end_clean();
    header('Connection: close');
    ignore_user_abort(true);
    ob_start();
    echo $message;
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// ================= SAVE UID TO TEMP (for registration auto-fill) =================
$conn->query("DELETE FROM temp_rfid");
$uid_safe = $conn->real_escape_string($uid);
$conn->query("INSERT INTO temp_rfid (uid) VALUES ('$uid_safe')");

// ================= CHECK STUDENT =================
$q = $conn->query("SELECT * FROM students WHERE UPPER(rfid_uid)='$uid'");

if($q->num_rows == 0){
    echo "NOT_REGISTERED";
    exit;
}

$student = $q->fetch_assoc();

if($student['status'] == "BLOCKED"){
    echo "BLOCKED";
    exit;
}

$name = $student['name'];

// ================= CHECK LAST LOG =================
$check = $conn->query("
    SELECT *, TIMESTAMPDIFF(SECOND, IFNULL(time_out, time_in), '$db_now') AS seconds_since_last 
    FROM logs 
    WHERE rfid_uid='$uid' 
    ORDER BY id DESC 
    LIMIT 1
");

if($check->num_rows > 0){
    $row = $check->fetch_assoc();

    if($row['seconds_since_last'] !== null && $row['seconds_since_last'] < 1){
        send_response_and_close("ALREADY_SCANNED|".$name);
        exit;
    }

    // ================= IF LAST WAS IN TODAY → DO OUT =================
    $last_scan_date = date('Y-m-d', strtotime($row['time_in']));

    if($row['status'] == "IN" && $last_scan_date == $today_date){

        $conn->query("
            UPDATE logs 
            SET status='OUT', time_out='$db_now' 
            WHERE id=".$row['id']."
        ");

        $time = date('F j, Y, g:i A', $timestamp);
        send_response_and_close("OUT_OK|".$name);
        send_parent_notification($student['parent_email'] ?? '', $student['parent_name'] ?? '', $student['name'], 'OUT', $time, $student['parent_email2'] ?? '');
        exit;
    }
}

// ================= OTHERWISE → NEW IN =================
$conn->query("
    INSERT INTO logs(rfid_uid,name,status,time_in) 
    VALUES('$uid','$name','IN','$db_now')
");

$time = date('F j, Y, g:i A', $timestamp);
send_response_and_close("IN_OK|".$name);

// ================= RECORD ATTENDANCE =================
$day_name = date('l', $timestamp);

// 1. Fetch active school term settings from neust_gatepass_v3 database
$active_school_year = '2025-2026';
$active_semester = '1st Semester';
$term_q = $conn->query("SELECT name, value FROM settings WHERE name IN ('active_school_year', 'active_semester')");
if ($term_q) {
    while ($row = $term_q->fetch_assoc()) {
        if ($row['name'] === 'active_school_year') {
            $active_school_year = $row['value'];
        } elseif ($row['name'] === 'active_semester') {
            $active_semester = $row['value'];
        }
    }
}

// 2. Find any class that this student is enrolled in today in the active term that is starting or ongoing
$sched_q = $conn->query("
    SELECT s.* 
    FROM schedules s 
    JOIN schedule_students ss ON s.id = ss.schedule_id 
    WHERE ss.student_id = " . intval($student['id']) . " 
      AND s.day LIKE '%$day_name%'
      AND s.school_year = '" . $conn->real_escape_string($active_school_year) . "'
      AND s.semester = '" . $conn->real_escape_string($active_semester) . "'
      AND (
          ('$current_time_only' BETWEEN s.start_time AND s.end_time)
          OR 
          ('$current_time_only' BETWEEN SUBTIME(s.start_time, '01:00:00') AND s.start_time)
      )
");

if ($sched_q) {
    while ($sched = $sched_q->fetch_assoc()) {
        $schedule_id = intval($sched['id']);
        $start_time = $sched['start_time'];
        $start_ts = strtotime($today_date . ' ' . $start_time);
        $present_cutoff = $start_ts + (15 * 60); 
        
        $status = 'Late';
        if ($timestamp <= $present_cutoff) {
            $status = 'Present';
        }
        
        $conn->query("
            INSERT INTO attendance (schedule_id, student_id, name, attendance_date, status, time_logged) 
            VALUES ($schedule_id, " . intval($student['id']) . ", '" . $conn->real_escape_string($name) . "', '$today_date', '$status', '$db_now')
            ON DUPLICATE KEY UPDATE 
                status = IF(status = 'Pending' OR status = 'Absent', VALUES(status), status),
                time_logged = IF(time_logged IS NULL, VALUES(time_logged), time_logged)
        ");
    }
}

send_parent_notification($student['parent_email'] ?? '', $student['parent_name'] ?? '', $student['name'], 'IN', $time, $student['parent_email2'] ?? '');
?>
