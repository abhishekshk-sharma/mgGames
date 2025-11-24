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

include "includes/header.php";
?>
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