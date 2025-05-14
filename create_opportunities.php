<?php
session_start();
require_once 'db/db.php';

// Restrict access to logged-in Admins only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Auto-delete expired opportunities BEFORE inserting new one
    try {
        $conn->prepare("DELETE FROM opportunities WHERE deadline IS NOT NULL AND deadline < CURDATE()")->execute();
    } catch (PDOException $e) {
        $_SESSION['flash'] = "Failed to clean up expired opportunities: " . $e->getMessage();
        header("Location: admin.php#opportunities");
        exit;
    }

    // Get form data
    $title = trim($_POST['title']);
    $company = trim($_POST['company']);
    $type = trim($_POST['type']);
    $skills = trim($_POST['skills']);
    $description = trim($_POST['description']);
    $deadline = !empty($_POST['deadline']) ? date('Y-m-d', strtotime($_POST['deadline'])) : null;
    $moa_status = isset($_POST['moa_status']) ? 1 : 0;
    $moa_expiration = !empty($_POST['moa_expiration']) ? date('Y-m-d', strtotime($_POST['moa_expiration'])) : null;
    $application_link = !empty($_POST['application_link']) ? trim($_POST['application_link']) : null;

    
    // Status based ONLY on moa_status
    $status = $moa_status ? 'Active' : 'Inactive';

    if (empty($title) || empty($company) || empty($type)) {
        $_SESSION['flash'] = "Please fill in all required fields.";
        header("Location: admin.php#opportunities");
        exit;
    }

    try {
        $stmt = $conn->prepare("INSERT INTO opportunities 
(title, company, skills, description, application_link, type, deadline, moa_status, moa_expiration, status, created_at) 
VALUES 
(:title, :company, :skills, :description, :application_link, :type, :deadline, :moa_status, :moa_expiration, :status, NOW())");


        $stmt->execute([
        ':title' => $title,
        ':company' => $company,
        ':skills' => $skills,
        ':description' => $description,
        ':application_link' => $application_link,
        ':type' => $type,
        ':deadline' => $deadline,
        ':moa_status' => $moa_status,
        ':moa_expiration' => $moa_expiration,
        ':status' => $status
            ]);



        $_SESSION['flash'] = "Opportunity created successfully!";
    } catch (PDOException $e) {
        $_SESSION['flash'] = "Failed to create opportunity: " . $e->getMessage();
    }

    header("Location: admin#opportunities");
    exit;
} else {
    header("Location: admin");
    exit;
}
