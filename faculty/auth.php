<?php
// Faculty session enforcement
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(!isset($_SESSION['faculty_logged_in']) || $_SESSION['faculty_logged_in'] !== true){
    header('Location: login.php');
    exit;
}
