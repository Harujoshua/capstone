<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../session_guard.php';
enforce_session_inactivity('login.php');

if (!isset($_SESSION['student_is_first_login'])) {

    require_once(__DIR__ . '/../db.php');
    $st_id = intval($_SESSION['student_id']);
    $chk = $conn->query("SELECT password_hash, is_first_login FROM students WHERE id = $st_id LIMIT 1");
    if ($chk && $row = $chk->fetch_assoc()) {
        $_SESSION['student_is_first_login'] = (empty($row['password_hash']) || !empty($row['is_first_login']));
    } else {
        $_SESSION['student_is_first_login'] = false;
    }
}
?>
