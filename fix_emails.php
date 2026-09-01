<?php
$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function dumpEmails($conn, $table, $column) {
    $res = $conn->query("SELECT id, $column FROM $table WHERE $column IS NOT NULL AND $column != ''");
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            echo "Table $table, ID {$row['id']}, Col $column: " . htmlspecialchars($row[$column]) . "\n";
        }
    }
}

dumpEmails($conn, 'students', 'parent_email');
dumpEmails($conn, 'students', 'parent_email2');
dumpEmails($conn, 'faculty', 'email');

?>
