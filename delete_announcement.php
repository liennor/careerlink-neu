<?php
session_start();
require_once 'db/db.php'; // Update path if necessary

// Admin-only access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}


// Restrict access to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = $_POST['id'];

    try {
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $_SESSION['flash'] = " Announcement deleted successfully.";
    } catch (PDOException $e) {
        $_SESSION['flash'] = "Failed to delete announcement: " . $e->getMessage();
    }

    header("Location: admin.php#announcements");
    exit;
} else {
    $_SESSION['flash'] = "Invalid request.";
    header("Location: admin.php");
    exit;
}
