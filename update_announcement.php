<?php
session_start();
require_once 'db/db.php'; // Adjust path if needed

// Admin-only access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $location = trim($_POST['location']);
    $event_time = $_POST['event_time'] ?? null;
    $expiry_date = $_POST['expiry_date'] ?? null;

    // Validate
    if (empty($id) || empty($title) || empty($description)) {
        $_SESSION['flash'] = "Missing required fields.";
        header("Location: admin.php#announcements");
        exit;
    }

    try {
        $stmt = $conn->prepare("UPDATE announcements SET 
            title = :title,
            content = :description,
            location = :location,
            event_time = :event_time,
            expiry_date = :expiry_date,
            updated_at = NOW()
            WHERE id = :id");

        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':location' => $location,
            ':event_time' => $event_time,
            ':expiry_date' => $expiry_date,
            ':id' => $id
        ]);

        $_SESSION['flash'] = "Announcement updated successfully.";
    } catch (PDOException $e) {
        $_SESSION['flash'] = "Failed to update announcement: " . $e->getMessage();
    }

    header("Location: admin.php#announcements");
    exit;
} else {
    header("Location: admin.php");
    exit;
}
