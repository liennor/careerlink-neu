<?php
session_start();
require_once 'db/db.php';

// Restrict to Admins only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $id = $_POST['id'];
    $title = trim($_POST['title']);
    $company = trim($_POST['company']);
    $type = $_POST['type'];
    $description = trim($_POST['description']);
    $skills = trim($_POST['skills']);
    $application_link = !empty($_POST['application_link']) ? trim($_POST['application_link']) : null;
    $deadline = !empty($_POST['deadline']) ? date('Y-m-d', strtotime($_POST['deadline'])) : null;
    $moa_expiration = !empty($_POST['moa_expiration']) ? date('Y-m-d', strtotime($_POST['moa_expiration'])) : null;
    $moa_status = isset($_POST['moa_status']) ? 1 : 0;
    $status = isset($_POST['status']) ? 'Active' : 'Inactive';

    if (empty($title) || empty($company) || empty($type)) {
        $_SESSION['flash'] = "Please fill in all required fields.";
        header("Location: admin.php#opportunities");
        exit;
    }

    try {
        $stmt = $conn->prepare("UPDATE opportunities SET 
            title = :title,
            company = :company,
            type = :type,
            description = :description,
            skills = :skills,
            application_link = :application_link,
            deadline = :deadline,
            moa_expiration = :moa_expiration,
            moa_status = :moa_status,
            status = :status,
            updated_at = NOW()
        WHERE id = :id");

        $stmt->execute([
            ':title' => $title,
            ':company' => $company,
            ':type' => $type,
            ':description' => $description,
            ':skills' => $skills,
            ':application_link' => $application_link,
            ':deadline' => $deadline,
            ':moa_expiration' => $moa_expiration,
            ':moa_status' => $moa_status,
            ':status' => $status,
            ':id' => $id
        ]);

        $_SESSION['flash'] = "Opportunity updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['flash'] = "Failed to update opportunity: " . $e->getMessage();
    }

    header("Location: admin.php#opportunities");
    exit;
} else {
    header("Location: admin.php");
    exit;
}
