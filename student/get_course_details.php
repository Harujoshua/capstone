<?php
session_start();
include('auth.php');
include('../db.php');

if (isset($_GET['schedule_id'])) {
    $schedule_id = (int)$_GET['schedule_id'];
    $student_id = $_SESSION['student_id'];
    
    $query = "SELECT attendance_date, status, time_logged 
              FROM attendance 
              WHERE schedule_id = $schedule_id AND student_id = $student_id 
              ORDER BY attendance_date DESC";
              
    $result = $conn->query($query);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    echo json_encode($data);
}
?>
