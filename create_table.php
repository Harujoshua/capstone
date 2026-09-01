<?php
$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");
if ($conn->connect_error) die("Connection failed");

$sql = "CREATE TABLE IF NOT EXISTS excuse_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    schedule_id INT,
    date_absent DATE,
    reason TEXT,
    file_path VARCHAR(255),
    status VARCHAR(50) DEFAULT 'Pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Table created successfully";
} else {
    echo "Error: " . $conn->error;
}
$conn->close();
?>
