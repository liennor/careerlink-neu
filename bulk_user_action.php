<?php
session_start();
require_once 'db/db.php';
require_once 'sendStatusEmail.php';

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userIds = isset($_POST['user_ids']) ? explode(',', $_POST['user_ids']) : [];
    $action = $_POST['action'] ?? '';

    if (empty($userIds) || !in_array($action, ['approve', 'reject'])) {
        $_SESSION['flash'] = "Invalid bulk action request.";
        header("Location: admin.php#pending-users");
        exit;
    }

    $approvedCount = 0;
    $rejectedCount = 0;

    foreach ($userIds as $userId) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) continue;

        if ($action === 'approve') {
            // Approve user
            $update = $conn->prepare("UPDATE users SET status = 'approved' WHERE id = :id");
            $update->execute([':id' => $userId]);

            sendStatusEmail($user['email'], $user['first_name'] . ' ' . $user['last_name'], 'approved');
            $approvedCount++;
        }

        if ($action === 'reject') {
            // Reject: send email and delete
            sendStatusEmail($user['email'], $user['first_name'] . ' ' . $user['last_name'], 'rejected');

            $delete = $conn->prepare("DELETE FROM users WHERE id = :id");
            $delete->execute([':id' => $userId]);
            $rejectedCount++;
        }
    }

    if ($action === 'approve') {
        $_SESSION['flash'] = "$approvedCount user(s) approved successfully.";
    } elseif ($action === 'reject') {
        $_SESSION['flash'] = "$rejectedCount user(s) rejected and removed.";
    }

    header("Location: admin.php#pending-users");
    exit;
}
?>
