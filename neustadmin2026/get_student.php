<?php
include('../db.php');
include('auth.php');

// Only accept POST requests for this endpoint
if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'error'=>'invalid_method','message'=>'POST required']);
    exit;
}

// Read id from POST body (form-encoded) or JSON payload
$id = 0;
if(isset($_POST['id'])){
    $id = intval($_POST['id']);
} else {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if(stripos($contentType, 'application/json') !== false){
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if(is_array($data) && isset($data['id'])){ $id = intval($data['id']); }
    }
}

if($id <= 0){
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'error'=>'invalid id']);
    exit;
}

$res = $conn->query("SELECT * FROM students WHERE id=".intval($id)." LIMIT 1");
if(!$res || $res->num_rows === 0){
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'error'=>'not found']);
    exit;
}
$student = $res->fetch_assoc();
// return minimal fields
$out = [
    'id' => $student['id'],
    'name' => $student['name'],
    'rfid_uid' => $student['rfid_uid'],
    'course' => $student['course'],
    'year_level' => $student['year_level'],
    'section' => $student['section'],
    'parent_name' => $student['parent_name'] ?? null,
    'parent_email' => $student['parent_email'] ?? null,
    'parent_email2' => $student['parent_email2'] ?? null,
    'photo' => $student['photo'] ?? null,
    'gender' => $student['gender'] ?? null,
    'municipality' => $student['municipality'] ?? null,
    'barangay' => $student['barangay'] ?? null,
];
header('Content-Type: application/json');
echo json_encode(['success'=>true,'student'=>$out]);
