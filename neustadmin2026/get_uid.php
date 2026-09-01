<?php
include('auth.php');
include('../db.php');

// We only care about the latest scan. 
// Since scan.php clears the table before each insert, we can just grab the first row.
$res = $conn->query("SELECT uid FROM temp_rfid LIMIT 1");

if($res && $res->num_rows > 0){
    $row = $res->fetch_assoc();
    echo trim($row['uid']);
} else {
    echo "";
}
?>