<?php
require_once 'db/db.php';
session_start();

$userId = $_SESSION['user_id'] ?? null;

if ($userId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Fetch all resume file names for the user
    $stmt = $conn->prepare("SELECT pdf_file FROM resume_history WHERE user_id = ?");
    $stmt->execute([$userId]);
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Delete all files from disk
    foreach ($files as $file) {
        if ($file && file_exists("uploads/resumes/" . $file)) {
            unlink("uploads/resumes/" . $file);
        }
    }

    // Delete all records from database
    $stmt = $conn->prepare("DELETE FROM resume_history WHERE user_id = ?");
    $stmt->execute([$userId]);

    $_SESSION['flash_success'] = "All resume history deleted.";
    header("Location: dashboard.php");
    exit;
}
