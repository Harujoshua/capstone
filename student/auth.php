<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['student_id'])) {
    // Redirect to student login page if not logged in
    // For now, if login.php doesn't exist, we might get a 404, but that's the correct logic.
    header("Location: login.php");
    exit();
}
?>
