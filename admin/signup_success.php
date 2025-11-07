<?php
// signup_success.php
require_once '../config.php';

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if registration was successful
if (!isset($_SESSION['registration_success'])) {
    header("Location: signup.php");
    exit;
}

// Clear the session variable
unset($_SESSION['registration_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful - RB Games</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
        }
    </style>
</head>
<body>
    <script>
        Swal.fire({
            title: 'Success!',
            text: 'We have received your request successfully! Please wait till we verify your details. We will soon reach out to you via phone or email you provided.',
            icon: 'success',
            confirmButtonText: 'OK',
            confirmButtonColor: '#ff3c7e'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'login.php';
            }
        });
    </script>
</body>
</html>