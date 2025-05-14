<?php
session_start();
session_unset();      // Clear all session variables
session_destroy();    // Destroy the session

// Prevent browser caching of protected pages
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");

// Redirect to login or home
header("Location: login");
exit;
?>
