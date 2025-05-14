<?php
session_start();
require_once 'db/db.php';
require_once 'sendStatusEmail.php'; // Make sure this is the correct path

// Restrict access to only logged-in admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $userId = $_POST['user_id'];

    // Fetch user details before deletion
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Send rejection email before deleting
        sendStatusEmail($user['email'], $user['first_name'] . ' ' . $user['last_name'], 'rejected');

        // Delete user from database
        $delete = $conn->prepare("DELETE FROM users WHERE id = :id");
        $delete->execute([':id' => $userId]);

        $_SESSION['flash'] = "User {$user['first_name']} {$user['last_name']} has been rejected and removed.";
    }
}

header("Location: admin.php#pending-users");
exit;
