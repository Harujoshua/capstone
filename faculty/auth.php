<?php
// Faculty session enforcement
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(!isset($_SESSION['faculty_logged_in']) || $_SESSION['faculty_logged_in'] !== true){
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../session_guard.php';
enforce_session_inactivity('login.php');

// First-login password change is disabled for faculty accounts.
// Accounts are created with a temporary password and can log in directly.
$_SESSION['faculty_is_first_login'] = false;

