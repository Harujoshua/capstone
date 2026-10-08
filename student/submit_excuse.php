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

    // Handle File Upload Securely
    if (isset($_FILES['excuse_file']) && $_FILES['excuse_file']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png'
        ];

        // Max file size: 5MB
        $max_size = 5 * 1024 * 1024;
        if ($_FILES['excuse_file']['size'] > $max_size) {
            $_SESSION['error_message'] = "Uploaded file is too large. Maximum size allowed is 5MB.";
            header("Location: dashboard.php");
            exit();
        }

        // Verify MIME type via Fileinfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['excuse_file']['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mime, $allowed_types)) {
            $_SESSION['error_message'] = "Invalid file format. Only PDF, JPG, and PNG files are accepted.";
            header("Location: dashboard.php");
            exit();
        }

        $upload_dir = '../uploads/excuses/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $safe_ext = $allowed_types[$mime];
        $file_name = 'excuse_' . $student_id . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $safe_ext;
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['excuse_file']['tmp_name'], $target_file)) {
            $file_path = $target_file;
        } else {
            $_SESSION['error_message'] = "Failed to upload file. Please try again.";
            header("Location: dashboard.php");
            exit();
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
