<?php
require_once 'db/db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['history_id'])) {
    $historyId = $_POST['history_id'];

    // Fetch the file name before deleting
    $stmt = $conn->prepare("SELECT pdf_file FROM resume_history WHERE id = ?");
    $stmt->execute([$historyId]);
    $file = $stmt->fetchColumn();

    // Delete the file from server
    if ($file && file_exists("uploads/resumes/" . $file)) {
        unlink("uploads/resumes/" . $file);
    }

    // Delete the database record
    $stmt = $conn->prepare("DELETE FROM resume_history WHERE id = ?");
    $stmt->execute([$historyId]);

    $_SESSION['flash_success'] = "Resume file deleted successfully.";
    header("Location: dashboard.php");
    exit;
}
