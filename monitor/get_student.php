<?php
// Returns student info by RFID UID (used by monitor UI)
include('../db.php');

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'invalid_method', 'message' => 'Use POST']);
    exit;
}

$uid = strtoupper(trim($_POST['uid'] ?? ''));
if ($uid === '') {
    echo json_encode(['success' => false, 'error' => 'missing uid']);
    exit;
}

$uid_safe = $conn->real_escape_string($uid);
$res = $conn->query("SELECT * FROM students WHERE UPPER(rfid_uid)='$uid_safe' AND (status IS NULL OR status != 'BLOCKED') LIMIT 1");
if(!$res || $res->num_rows === 0){
    echo json_encode(['success'=>false,'error'=>'not found']);
    exit;
}

$student = $res->fetch_assoc();
$out = [
    'id' => $student['id'],
    'name' => $student['name'],
    'rfid_uid' => $student['rfid_uid'],
    'course' => $student['course'],
    'year_level' => $student['year_level'],
    'section' => $student['section'],
    'photo' => $student['photo'] ?? null,
];

echo json_encode(['success'=>true,'student'=>$out]);
