<?php
session_start();
require_once 'db/db.php';

// Restrict access to only logged-in admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $location = trim($_POST['location']);
    $event_time = $_POST['event_time'] ?? null;
    $expiry_date = $_POST['expiry_date'] ?? null;
    $posted_by = $_SESSION['name'] ?? 'Admin';

    if (empty($title) || empty($description)) {
        $_SESSION['flash'] = " Please fill in all required fields.";
        header("Location: admin.php#announcements");
        exit;
    }

    try {
        $stmt = $conn->prepare("INSERT INTO announcements 
            (title, content, location, event_time, expiry_date, posted_by, created_at, updated_at) 
            VALUES 
            (:title, :content, :location, :event_time, :expiry_date, :posted_by, NOW(), NOW())");

        $stmt->execute([
            ':title' => $title,
            ':content' => $description,
            ':location' => $location,
            ':event_time' => $event_time,
            ':expiry_date' => $expiry_date,
            ':posted_by' => $posted_by
        ]);

        $_SESSION['flash'] = "Announcement posted successfully!";
    } catch (PDOException $e) {
        $_SESSION['flash'] = "Failed to create announcement: " . $e->getMessage();
    }

    header("Location: admin.php#announcements");
    exit;
} else {
    header("Location: admin.php");
    exit;
}
