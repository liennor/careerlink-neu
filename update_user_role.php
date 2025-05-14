<?php
require_once 'db/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['new_role'])) {
    $userId = $_POST['user_id'];
    $newRole = $_POST['new_role'];

    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->execute([$newRole, $userId]);

    session_start();
    $_SESSION['flash_success'] = "User role updated to '$newRole'.";
    header("Location: admin");
    exit;
}
?>