<?php
session_start();
require_once 'db/db.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['status'] === 'pending') {
            $error = "Your account is still pending approval. We'll notify you by email once approved.";
        } elseif ($user['status'] === 'rejected') {
            $error = "Your registration was rejected. Please contact the administrator.";
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];

            // Redirect based on role
            if ($user['role'] === 'Admin') {
                header("Location: admin"); // or admin/dashboard.php
            } else {
                header("Location: dashboard");
            }
            exit;
        }
    } else {
        $error = "Invalid email or password.";
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login | CareerLink NEU</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-success">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index">Career<span class="text-warning">Link</span> NEU</a>
    <div class="collapse navbar-collapse justify-content-end">
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" href="index">Home</a></li>
        <li class="nav-item"><a class="nav-link" href="footerSection">Contact</a></li>
        <li class="nav-item"><a class="btn btn-warning" href="register">Sign up</a></li>
      </ul>
    </div>
  </div>
</nav>

    <!-- Login Form -->
    <div class="container d-flex justify-content-center align-items-center" style="min-height: 90vh;">
    <div class="card shadow p-4 w-100" style="max-width: 400px;">
        <h3 class="text-center fw-bold mb-3">Welcome back</h3>
        <p class="text-center text-muted">Enter your credentials to access your account</p>
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>

        <form method="post">
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required placeholder="student@neu.edu.ph">
        </div>

        <div class="mb-4">
            <div class="d-flex justify-content-between">
            <label for="password" class="form-label">Password</label>
            <!-- <a href="#" class="text-decoration-none small">Forgot your password?</a> -->
            </div>
            <input type="password" name="password" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-success w-100">Login</button>
        </form>

        <div class="text-center mt-3">
        Don’t have an account? <a href="register">Sign up</a>
        </div>
    </div>
    </div>

    <footer class="bg-success text-white pt-5 pb-4" id="footerSection">
        <div class="container">
            <div class="row text-center text-md-start">
            <div class="col-md-4">
                <h6 class="fw-bold">About CareerLink NEU</h6>
                <p>Connecting New Era University students and alumni with career opportunities and resources to help them succeed in their professional journeys.</p>
            </div>

            <div class="col-md-4">
                <h6 class="fw-bold">Quick Links</h6>
                <ul class="list-unstyled">
                <li><a href="#" class="text-white text-decoration-none">Home</a></li>
                <li><a href="#" class="text-white text-decoration-none">Login</a></li>
                <li><a href="#" class="text-white text-decoration-none">Sign Up</a></li>
                <li><a href="#" class="text-white text-decoration-none">Terms of Service</a></li>
                <li><a href="#" class="text-white text-decoration-none">Privacy Policy</a></li>
                </ul>
            </div>

            <div class="col-md-4">
                <h6 class="fw-bold">Contact Us</h6>
                <p><strong>Email:</strong> support@careerlinkneu.com</p>
                <p><strong>Phone:</strong> +63 912 345 6789</p>
                <p><strong>Address:</strong> New Era University, No. 9 Central Ave, New Era, Quezon City, 1107 Metro Manila, Philippines</p>
            </div>
            </div>

            <hr class="border-white my-4" />
            <p class="text-center mb-0">&copy; 2025 CareerLink NEU. All rights reserved.</p>
        </div>
    </footer>


</body>
</html>
