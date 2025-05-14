<?php
session_start();
require_once 'db/db.php';

// Prevent browser from caching this page
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Restrict access to only logged-in admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");  
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $userId = $_POST['user_id'];

    // Delete user
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    // Also delete resume info
    $stmt = $conn->prepare("DELETE FROM resume_info WHERE user_id = ?");
    $stmt->execute([$userId]);

    // Set flash message
    $_SESSION['flash_success'] = "User deleted successfully.";

    // Redirect back
    header("Location: admin");
    exit;
}
