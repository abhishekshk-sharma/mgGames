<?php
// Start session (if not already started)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Unset all session variables
$_SESSION = array();

session_unset();

// Destroy the session
session_destroy();

// Redirect to login page
header("location: login.php");
exit;
?>