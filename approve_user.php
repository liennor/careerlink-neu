<?php
session_start();
require_once 'db/db.php';
require_once 'sendStatusEmail.php'; // Make sure this is the correct path

// Admin-only access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

$_SESSION['flash'] = "User {$user['first_name']} {$user['last_name']} has been approved.";

// Restrict access to only logged-in admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");  
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $userId = $_POST['user_id'];

    // Fetch user details
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Update status to approved
        $update = $conn->prepare("UPDATE users SET status = 'approved' WHERE id = :id");
        $update->execute([':id' => $userId]);

        // Send email
        sendStatusEmail($user['email'], $user['first_name'] . ' ' . $user['last_name'], 'approved');
    }
}

header("Location: admin.php#pending-users");
exit;
