<?php
$conn = new mysqli("localhost","root","","neust_gatepass_v3");

if($conn->connect_error){
    die("Database Failed");
}