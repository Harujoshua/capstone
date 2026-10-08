<?php
require_once 'mailer.php';
date_default_timezone_set('Asia/Manila');
$conn = new mysqli("localhost","root","","neust_gatepass_v3");

$uid = strtoupper(trim($_POST['uid'] ?? ''));
if ($uid === '') {
    echo "INVALID_UID";
    exit;
}

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

// ================= CHECK FACULTY FIRST =================
$fac_q = $conn->query("SELECT * FROM faculty WHERE UPPER(rfid_uid)='$uid_safe' LIMIT 1");
if ($fac_q && $fac_q->num_rows > 0) {
    $fac = $fac_q->fetch_assoc();
    $name = $fac['name'];
    $name_safe = $conn->real_escape_string($name);
    $photo = trim($fac['photo'] ?? '');

    if (strtoupper($fac['status'] ?? '') === 'BLOCKED') {
        echo "BLOCKED|" . $name . "|" . $photo;
        exit;
    }

    // Cooldown check (prevent accidental double tap within 1 sec)
    $check_recent_fac = $conn->query("
        SELECT *, TIMESTAMPDIFF(SECOND, IFNULL(time_out, time_in), '$db_now') AS seconds_since_last 
        FROM logs 
        WHERE rfid_uid='$uid_safe' AND student_id IS NULL 
        ORDER BY id DESC 
        LIMIT 1
    ");

    if ($check_recent_fac && $check_recent_fac->num_rows > 0) {
        $recent_fac = $check_recent_fac->fetch_assoc();
        if ($recent_fac['seconds_since_last'] !== null && $recent_fac['seconds_since_last'] < 1) {
            send_response_and_close("ALREADY_SCANNED|" . $name . "|" . $photo);
            exit;
        }
    }

    // Check today's gate log for this faculty
    $check_fac_today = $conn->query("
        SELECT * 
        FROM logs 
        WHERE rfid_uid='$uid_safe' 
          AND student_id IS NULL
          AND DATE(time_in)='$today_date' 
        ORDER BY id ASC 
        LIMIT 1
    ");

    if ($check_fac_today && $check_fac_today->num_rows > 0) {
        $fac_today_row = $check_fac_today->fetch_assoc();
        $fac_log_id = intval($fac_today_row['id']);

        if ($fac_today_row['status'] === 'IN') {
            // Currently IN -> Record OUT
            $conn->query("
                UPDATE logs 
                SET status='OUT', time_out='$db_now' 
                WHERE id=$fac_log_id
            ");
            send_response_and_close("OUT_OK|" . $name . "|" . $photo);
            exit;
        } else {
            // Currently OUT -> Record re-entry IN
            $conn->query("
                UPDATE logs 
                SET status='IN', time_out=NULL 
                WHERE id=$fac_log_id
            ");
            send_response_and_close("IN_OK|" . $name . "|" . $photo);
            exit;
        }
    } else {
        // First IN of the day
        $conn->query("
            INSERT INTO logs(student_id, rfid_uid, name, status, time_in) 
            VALUES(NULL, '$uid_safe', '$name_safe', 'IN', '$db_now')
        ");
        send_response_and_close("IN_OK|" . $name . "|" . $photo);
        exit;
    }
}

// ================= CHECK STUDENT =================
$q = $conn->query("SELECT * FROM students WHERE UPPER(rfid_uid)='$uid_safe'");

if($q->num_rows == 0){
    echo "NOT_REGISTERED||";
    exit;
}

$student = $q->fetch_assoc();
$name = $student['name'];
$name_safe = $conn->real_escape_string($name);
$photo = trim($student['photo'] ?? '');

if($student['status'] == "BLOCKED"){
    echo "BLOCKED|" . $name . "|" . $photo;
    exit;
}

// ================= SECURITY CHECK: REQUIRE PICTURE =================
$photo_file_exists = false;
if(!empty($photo) && file_exists(__DIR__ . '/uploads/students/' . $photo)){
    $photo_file_exists = true;
}

if(!$photo_file_exists){
    echo "NO_PHOTO|" . $name . "|";
    exit;
}

// ================= CHECK LAST LOG (DUPLICATE / COOLDOWN CHECK) =================
$check_recent = $conn->query("
    SELECT *, TIMESTAMPDIFF(SECOND, IFNULL(time_out, time_in), '$db_now') AS seconds_since_last 
    FROM logs 
    WHERE rfid_uid='$uid_safe' 
    ORDER BY id DESC 
    LIMIT 1
");

if($check_recent && $check_recent->num_rows > 0){
    $recent_row = $check_recent->fetch_assoc();

    if($recent_row['seconds_since_last'] !== null && $recent_row['seconds_since_last'] < 1){
        $photo = $student['photo'] ?? '';
        send_response_and_close("ALREADY_SCANNED|".$name."|".$photo);
        exit;
    }
}
$check_today = $conn->query("
    SELECT * 
    FROM logs 
    WHERE rfid_uid='$uid_safe' 
      AND DATE(time_in)='$today_date' 
    ORDER BY id ASC 
    LIMIT 1
");

if($check_today && $check_today->num_rows > 0){
    $today_row = $check_today->fetch_assoc();
    $today_id = intval($today_row['id']);

    if($today_row['status'] == "IN"){
        // Currently IN -> Record OUT (updates time_out as Last Out, keeps original First In)
        $conn->query("
            UPDATE logs 
            SET status='OUT', time_out='$db_now' 
            WHERE id=$today_id
        ");

        $time = date('F j, Y, g:i A', $timestamp);
        $photo = $student['photo'] ?? '';
        send_response_and_close("OUT_OK|".$name."|".$photo);
        send_parent_notification($student['parent_email'] ?? '', $student['parent_name'] ?? '', $student['name'], 'OUT', $time, $student['parent_email2'] ?? '');
        exit;
    } else {
        // Currently OUT -> Record re-entry IN (keep original First In, reset time_out, set status='IN')
        $conn->query("
            UPDATE logs 
            SET status='IN', time_out=NULL 
            WHERE id=$today_id
        ");

        $time = date('F j, Y, g:i A', $timestamp);
        $photo = $student['photo'] ?? '';
        send_response_and_close("IN_OK|".$name."|".$photo);
    }
} else {
    // No record today -> Student's FIRST IN of the day
    $student_id_val = intval($student['id']);
    $conn->query("
        INSERT INTO logs(student_id,rfid_uid,name,status,time_in) 
        VALUES($student_id_val,'$uid_safe','$name_safe','IN','$db_now')
    ");

    $time = date('F j, Y, g:i A', $timestamp);
    $photo = $student['photo'] ?? '';
    send_response_and_close("IN_OK|".$name."|".$photo);
}

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

        // Use faculty-set late grace period from the active class session.
        // Fall back to 15 minutes if no session has been started yet.
        $sess_res = $conn->query("SELECT late_grace_period, absent_grace_period, actual_start_time FROM class_sessions WHERE schedule_id = $schedule_id AND session_date = '$today_date' LIMIT 1");
        $sess = ($sess_res && $sess_res->num_rows > 0) ? $sess_res->fetch_assoc() : null;

        if ($sess) {
            $actual_start_ts  = strtotime($sess['actual_start_time']);
            $present_cutoff   = $actual_start_ts + (intval($sess['late_grace_period']) * 60);
            $absent_threshold = $actual_start_ts + (intval($sess['absent_grace_period']) * 60);
        } else {
            $present_cutoff   = $start_ts + (15 * 60); // 15-minute default
            $absent_threshold = strtotime($today_date . ' ' . $sched['end_time']);
        }

        // Determine status based on when the student scanned in
        if ($timestamp <= $present_cutoff) {
            $status = 'Present';
        } elseif ($timestamp <= $absent_threshold) {
            $status = 'Late';
        } else {
            // Check if student has an approved excuse for this class today
            $ex_chk = $conn->query("SELECT status FROM excuse_letters WHERE student_id = " . intval($student['id']) . " AND schedule_id = $schedule_id AND date_absent = '$today_date' AND status = 'Approved' LIMIT 1");
            $status = ($ex_chk && $ex_chk->num_rows > 0) ? 'Excused' : 'Absent';
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
