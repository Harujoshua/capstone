<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true){
    header('Location: login.php');
    exit;
}

$current_file = basename($_SERVER['PHP_SELF']);
if (!empty($_SESSION['admin_is_first_login']) && $current_file !== 'change_password.php' && $current_file !== 'logout.php') {
    header('Location: change_password.php');
    exit;
}

?>
