<?php
$admin_db = 'neust_gatepass_v3';
$admin_conn = new mysqli('localhost', 'root', '', $admin_db);

if ($admin_conn->connect_error) {
    die("Unable to connect to admin database '{$admin_db}': " . $admin_conn->connect_error );
}

// Ensure the is_first_login column exists
try {
    $admin_conn->query("ALTER TABLE admins ADD COLUMN is_first_login TINYINT(1) DEFAULT 1");
} catch (mysqli_sql_exception $e) {
    // Column already exists, ignore the exception
}
?>
