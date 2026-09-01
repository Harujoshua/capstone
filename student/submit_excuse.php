<?php
session_start();
include('auth.php');
include('../db.php');

// Ensure the table exists
$conn->query("CREATE TABLE IF NOT EXISTS excuse_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    schedule_id INT,
    date_absent DATE,
    reason TEXT,
    file_path VARCHAR(255),
    status VARCHAR(50) DEFAULT 'Pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_SESSION['student_id'];
    $schedule_id = (int)$_POST['schedule_id'];
    $date_absent = $conn->real_escape_string($_POST['date_absent']);
    $reason = $conn->real_escape_string($_POST['reason']);
    $file_path = '';

    // Handle File Upload
    if (isset($_FILES['excuse_file']) && $_FILES['excuse_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/excuses/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_extension = pathinfo($_FILES['excuse_file']['name'], PATHINFO_EXTENSION);
        $file_name = 'excuse_' . $student_id . '_' . time() . '.' . $file_extension;
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['excuse_file']['tmp_name'], $target_file)) {
            $file_path = $target_file;
        }
    }

    $stmt = $conn->prepare("INSERT INTO excuse_letters (student_id, schedule_id, date_absent, reason, file_path) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $student_id, $schedule_id, $date_absent, $reason, $file_path);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Excuse letter submitted successfully.";
    } else {
        $_SESSION['error_message'] = "Failed to submit excuse letter.";
    }
    header("Location: dashboard.php");
    exit();
}
?>
