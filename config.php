<?php
// Database configuration
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); 
define('DB_PASSWORD', ''); 
define('DB_NAME', 'mg games');

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
date_default_timezone_set('Asia/Kolkata');
// Check connection
if($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8
$conn->set_charset("utf8");

// Function to safely escape strings
function sanitize_input($conn, $data) {
    return mysqli_real_escape_string($conn, trim($data));
}
?>