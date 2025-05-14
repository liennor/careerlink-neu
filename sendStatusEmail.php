<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Update path if needed

// Admin-only access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");
    exit;
}

function sendStatusEmail($toEmail, $toName, $status) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'neucareerlink@gmail.com';
        $mail->Password   = 'wwny cctj mrpu vviq'; // 🔐 Replace with regenerated one
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        // Sender & recipient
        $mail->setFrom('neucareerlink@gmail.com', 'CareerLink NEU');
        $mail->addAddress($toEmail, $toName);

        // Subject & body content
        $subjectMap = [
            'approved' => 'Your CareerLink NEU Account is Approved!',
            'pending'  => 'Your CareerLink NEU Account is Still Pending',
            'rejected' => 'Your CareerLink NEU Account Was Rejected'
        ];

       $bodyMap = [
            'approved' => "Dear $toName,\n\nWe are pleased to inform you that your CareerLink NEU account has been successfully approved. You may now log in and begin exploring job and internship opportunities exclusively available to NEU students and alumni.\n\nBest regards,\nCareerLink NEU Team",

            'pending' => "Dear $toName,\n\nThank you for registering with CareerLink NEU. Your application is currently under review by our verification team. We will notify you via email once your account has been approved.\n\nSincerely,\nCareerLink NEU Team",

            'rejected' => "Dear $toName,\n\nWe regret to inform you that your registration for CareerLink NEU has been declined. The credentials you provided did not match the records from the Office of Career Placement, Industry and Linkages (OCPIL).\n\nIf you believe this is an error, please contact us for assistance or visit the OCPIL office directly for clarification.\n\nRespectfully,\nCareerLink NEU Team"
        ];

        $mail->Subject = $subjectMap[$status];
        $mail->Body    = $bodyMap[$status];

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log error if needed
        return false;
    }
}
