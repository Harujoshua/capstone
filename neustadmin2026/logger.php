<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function log_audit($action, $target_type, $target_name, $details = '') {
    include('admin_db.php');
    
    $username = $_SESSION['admin_username'] ?? 'System';
    $role = $_SESSION['admin_role'] ?? 'Unknown';
    
    // Ensure table exists (minimal overhead with IF NOT EXISTS)
    $table_sql = "CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_username VARCHAR(100) NOT NULL,
        admin_role VARCHAR(50) NOT NULL,
        action VARCHAR(100) NOT NULL,
        target_type VARCHAR(50) NOT NULL,
        target_name VARCHAR(255),
        details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $admin_conn->query($table_sql);

    $stmt = $admin_conn->prepare("INSERT INTO audit_logs (admin_username, admin_role, action, target_type, target_name, details) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $username, $role, $action, $target_type, $target_name, $details);
    $stmt->execute();
    $stmt->close();
}
?>
