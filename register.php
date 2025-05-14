<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db/db.php';

$success = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $role = $_POST['role'];
    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $studentNumber = isset($_POST['student_number']) ? trim($_POST['student_number']) : null;
    $torNumber = isset($_POST['tor_number']) ? trim($_POST['tor_number']) : null;
    $batch = isset($_POST['batch']) ? trim($_POST['batch']) : null;

    if ($role === 'Student') {
        if (empty($studentNumber) || !preg_match('/^[0-9]{2}-[0-9]{5}-[0-9]{3}$/', $studentNumber)) {
            $error = "Invalid student number format. Use: 00-00000-000.";
        } else {
            // Check uniqueness
            $checkStudentStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE student_number = :student_number");
            $checkStudentStmt->execute([':student_number' => $studentNumber]);
            if ($checkStudentStmt->fetchColumn() > 0) {
                $error = "Student number already registered.";
            }
        }
        $torNumber = null;

    } elseif ($role === 'Alumni' || $role === 'Other') {
        if (!empty($studentNumber)) {
            if (!preg_match('/^[0-9]{2}-[0-9]{5}-[0-9]{3}$/', $studentNumber)) {
                $error = "Invalid student number format. Use: 00-00000-000.";
            } else {
                $checkStudentStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE student_number = :student_number");
                $checkStudentStmt->execute([':student_number' => $studentNumber]);
                if ($checkStudentStmt->fetchColumn() > 0) {
                    $error = "Student number already registered.";
                }
            }
        }
    } else {
        if (!empty($studentNumber) && !preg_match('/^[A-Z0-9]{2}-[0-9]{5}-[0-9]{3}$/', $studentNumber)) {
            $error = "Invalid student number format. Use: XX-XXXXX-XXX.";
        }
    }

    // ✅ Batch format validation
    if (empty($error) && $role === 'Alumni') {
      if (empty($batch) || !preg_match('/^\d{4}-\d{4}$/', $batch)) {
          $error = "Invalid batch format. Use: YYYY-YYYY.";
      }
    } else {
        $batch = null; 
    }

    // ✅ TOR number duplicate check
    if (empty($error) && !empty($torNumber)) {
        $checkTor = $conn->prepare("SELECT COUNT(*) FROM users WHERE tor_number = :tor_number");
        $checkTor->execute([':tor_number' => $torNumber]);
        if ($checkTor->fetchColumn() > 0) {
            $error = "TOR number already registered.";
        }
    }

    if (empty($error) && strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    }

    if (empty($error)) {
        // Normalize empty strings to NULL before DB insertion
        $torNumber = ($torNumber === '' || $torNumber === null) ? null : $torNumber;
        $studentNumber = ($studentNumber === '' || $studentNumber === null) ? null : $studentNumber;

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $stmt = $conn->prepare("INSERT INTO users (role, first_name, last_name, student_number, batch, tor_number, email, password_hash) 
                        VALUES (:role, :first_name, :last_name, :student_number, :batch, :tor_number, :email, :password_hash)");
            $stmt->execute([
                ':role' => $role,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':student_number' => $studentNumber,
                ':batch' => $batch,
                ':tor_number' => $torNumber,
                ':email' => $email,
                ':password_hash' => $passwordHash
            ]);

            $success = "Registration successful! Please wait for admin approval before accessing your account.";
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'email')) {
                $error = "Email already exists. Please use a different one.";
            } else {
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register | CareerLink NEU</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-success">
    <div class="container">
      <a class="navbar-brand fw-bold" href="index">Career<span class="text-warning">Link</span> NEU</a>
      <div class="collapse navbar-collapse justify-content-end">
        <ul class="navbar-nav">
          <li class="nav-item"><a class="nav-link" href="index">Home</a></li>
          <li class="nav-item"><a class="nav-link" href="#footerSection">Contact</a></li>
          <li class="nav-item"><a class="btn btn-warning" href="#">Sign up</a></li>
        
        </ul>
      </div>
    </div>
  </nav>

  <!-- Registration Form -->
  <div class="container d-flex justify-content-center align-items-center" style="min-height: 100vh;">
    <div class="card shadow p-4 w-100" style="max-width: 500px;">
      <h3 class="text-center fw-bold">Create an account</h3>


      <p class="text-center text-muted">Select your role and enter your details to register</p>
      <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
      <?php endif; ?>
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>

      <!-- Role Toggle -->
      <div class="d-flex mb-3">
        <button type="button" id="studentBtn" class="btn btn-outline-secondary flex-fill me-2 active">Student</button>
        <button type="button" id="alumniBtn" class="btn btn-outline-secondary flex-fill">Alumni</button>
      </div>

      <!-- Form -->
      <form id="registerForm" action="register.php" method="POST">
        <input type="hidden" name="role" id="role" value="Student">

        <div class="row mb-3">
          <div class="col"><input type="text" class="form-control" name="first_name" placeholder="First Name" required></div>
          <div class="col"><input type="text" class="form-control" name="last_name" placeholder="Last Name" required></div>
        </div>

        <div class="mb-3" id="studentField">
          <input type="text" class="form-control" name="student_number" placeholder="Student no.">
          <small class="text-muted">Format: XX-XXXXX-XXX</small>
        </div>


        <div class="mb-3 d-none" id="batchField">
          <input type="text" class="form-control" name="batch" placeholder="Batch Year">
          <small class="text-muted">Format: YYYY-YYYY</small>
        </div>

        <div class="mb-3 d-none" id="torField">
          <input type="text" class="form-control" name="tor_number" placeholder="TOR no.">
          <small class="text-muted">Transcript of Records Control Number</small>
          
        </div>

        <div class="mb-3">
          <input type="email" class="form-control" name="email" placeholder="student@neu.edu.ph" required>
          <small class="text-muted">Must be institutional email</small>
        </div>

        <div class="mb-1 position-relative">
          <input type="password" name="password" id="password" class="form-control pe-5" placeholder="Password" required>
          <i class="bi bi-eye-slash position-absolute" id="togglePassword" style="top: 50%; right: 1rem; transform: translateY(-50%); cursor: pointer; font-size: 1.25rem;"></i>
        </div>
        <small class="text-danger d-none" id="lengthError">Password must be at least 8 characters.</small>

        <hr>
        <div class="mb-3 position-relative">
          <input type="password" id="confirm_password" class="form-control pe-5" placeholder="Confirm Password" required>
          <i class="bi bi-eye-slash position-absolute" id="toggleConfirm" style="top: 50%; right: 1rem; transform: translateY(-50%); cursor: pointer; font-size: 1.25rem;"></i>
        </div>
        <small class="text-danger d-none" id="matchError">Passwords must match.</small>

        <button type="submit" class="btn btn-success w-100 mt-3">Register</button>
      </form>

      <div class="text-center mt-3">
        Already have an account? <a href="login">Login</a>
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

  <script>
    document.addEventListener("DOMContentLoaded", () => {
      const studentBtn = document.getElementById("studentBtn");
      const alumniBtn = document.getElementById("alumniBtn");
      const roleInput = document.getElementById("role");
      const torField = document.getElementById("torField");
      const password = document.getElementById("password");
      const confirmPassword = document.getElementById("confirm_password");
      const togglePassword = document.getElementById("togglePassword");
      const toggleConfirm = document.getElementById("toggleConfirm");
      const lengthError = document.getElementById("lengthError");
      const matchError = document.getElementById("matchError");

      studentBtn.onclick = () => {
        studentBtn.classList.add("active");
        alumniBtn.classList.remove("active");
        roleInput.value = "Student";
        torField.classList.add("d-none");
        batchField.classList.add("d-none"); 
      };

      alumniBtn.onclick = () => {
        alumniBtn.classList.add("active");
        studentBtn.classList.remove("active");
        roleInput.value = "Alumni";
        torField.classList.remove("d-none");
        batchField.classList.remove("d-none"); 
      };

      togglePassword.onclick = () => {
        password.type = password.type === "password" ? "text" : "password";
        togglePassword.classList.toggle("bi-eye");
        togglePassword.classList.toggle("bi-eye-slash");
      };

      toggleConfirm.onclick = () => {
        confirmPassword.type = confirmPassword.type === "password" ? "text" : "password";
        toggleConfirm.classList.toggle("bi-eye");
        toggleConfirm.classList.toggle("bi-eye-slash");
      };

      password.addEventListener("input", () => {
        if (password.value.length < 8) {
          lengthError.classList.remove("d-none");
        } else {
          lengthError.classList.add("d-none");
        }
        validateMatch();
      });

      confirmPassword.addEventListener("input", validateMatch);

      function validateMatch() {
        if (password.value !== confirmPassword.value) {
          matchError.classList.remove("d-none");
        } else {
          matchError.classList.add("d-none");
        }
      }
    });
  </script>
</body>
</html>
