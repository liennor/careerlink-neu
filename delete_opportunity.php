<?php
session_start();
require_once 'db/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = $_GET['id'];

    try {
        $stmt = $conn->prepare("DELETE FROM opportunities WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $_SESSION['flash'] = "Opportunity deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['flash'] = "Delete failed: " . $e->getMessage();
    }

    header("Location: admin.php#opportunities");
    exit;
} else {
    $_SESSION['flash'] = "Invalid delete request.";
    header("Location: admin.php#opportunities");
    exit;
}
