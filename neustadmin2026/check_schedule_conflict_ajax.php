<?php
include('../db.php');
include('admin_db.php');
include('auth.php');

header('Content-Type: application/json; charset=utf-8');

// Active school year & semester settings
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

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$start_time = trim($input['start_time'] ?? '');
$end_time = trim($input['end_time'] ?? '');
$teacher_id = intval($input['teacher_id'] ?? 0);
$teacher_manual = trim($input['teacher_manual'] ?? '');
$room = trim($input['room'] ?? '');
$course = trim($input['course'] ?? '');
$year_level = trim($input['year_level'] ?? '');
$section = trim($input['section'] ?? '');
$exclude_id = intval($input['exclude_id'] ?? ($input['update_id'] ?? 0));

// Resolve teacher name
$teacher_name = $teacher_manual;
if ($teacher_id > 0) {
    $fq = $conn->query("SELECT name FROM faculty WHERE id = $teacher_id LIMIT 1");
    if ($fq && $fq->num_rows > 0) {
        $teacher_name = $fq->fetch_assoc()['name'];
    }
}

// Collect days
$days = [];
if (isset($input['days']) && is_array($input['days'])) {
    foreach ($input['days'] as $d) {
        $d_t = trim($d);
        if ($d_t !== '') $days[] = $d_t;
    }
} elseif (isset($input['day']) && trim($input['day']) !== '') {
    $raw_day = trim($input['day']);
    if (strpos($raw_day, ',') !== false) {
        $days = array_filter(array_map('trim', explode(',', $raw_day)));
    } else {
        $days = [$raw_day];
    }
}

if (empty($days) || $start_time === '' || $end_time === '') {
    echo json_encode(['conflict' => false, 'message' => '']);
    exit;
}

$st_ts = strtotime($start_time);
$en_ts = strtotime($end_time);
if ($st_ts === false || $en_ts === false || $st_ts >= $en_ts) {
    echo json_encode([
        'conflict' => true,
        'message' => 'Invalid schedule time: End time must be later than start time.'
    ]);
    exit;
}

$conflict_found = false;
$conflict_msg = '';

foreach ($days as $day) {
    $chk = check_schedule_conflict(
        $conn,
        $day,
        $start_time,
        $end_time,
        $active_school_year,
        $active_semester,
        $teacher_id,
        $teacher_name,
        $room,
        $course,
        $year_level,
        $section,
        $exclude_id
    );
    if ($chk) {
        $conflict_found = true;
        $conflict_msg = $chk;
        break;
    }
}

echo json_encode([
    'conflict' => $conflict_found,
    'message' => $conflict_msg
]);
