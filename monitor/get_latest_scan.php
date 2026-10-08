<?php
// Unified lightweight endpoint for the live Gate Monitor display.
// Returns the most recent tapped card UID and the associated student info in a single request.
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../db.php';

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$res = $conn->query("SELECT uid FROM temp_rfid LIMIT 1");
if (!$res || $res->num_rows === 0) {
    echo json_encode(['success' => true, 'uid' => '', 'student' => null]);
    exit;
}

$row = $res->fetch_assoc();
$uid = strtoupper(trim($row['uid'] ?? ''));

if ($uid === '') {
    echo json_encode(['success' => true, 'uid' => '', 'student' => null]);
    exit;
}

$uid_safe = $conn->real_escape_string($uid);
$s_res = $conn->query("SELECT id, name, rfid_uid, course, year_level, section, photo, status FROM students WHERE UPPER(rfid_uid)='$uid_safe' LIMIT 1");

if ($s_res && $s_res->num_rows > 0) {
    $student = $s_res->fetch_assoc();
    echo json_encode([
        'success' => true,
        'uid'     => $uid,
        'student' => [
            'id'         => $student['id'],
            'name'       => $student['name'],
            'rfid_uid'   => $student['rfid_uid'],
            'course'     => $student['course'],
            'year_level' => $student['year_level'],
            'section'    => $student['section'],
            'photo'      => $student['photo'] ?? null,
            'is_blocked' => ($student['status'] === 'BLOCKED')
        ],
        'faculty' => null
    ]);
} else {
    $f_res = $conn->query("SELECT id, name, rfid_uid, department, photo, status FROM faculty WHERE UPPER(rfid_uid)='$uid_safe' LIMIT 1");
    if ($f_res && $f_res->num_rows > 0) {
        $fac = $f_res->fetch_assoc();
        echo json_encode([
            'success' => true,
            'uid'     => $uid,
            'student' => null,
            'faculty' => [
                'id'         => $fac['id'],
                'name'       => $fac['name'],
                'rfid_uid'   => $fac['rfid_uid'],
                'department' => $fac['department'],
                'photo'      => $fac['photo'] ?? null,
                'is_blocked' => (strtoupper($fac['status'] ?? '') === 'BLOCKED')
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'uid'     => $uid,
            'student' => null,
            'faculty' => null,
            'unknown' => true
        ]);
    }
}
