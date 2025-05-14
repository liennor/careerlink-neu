<?php
session_start();
require_once 'db/db.php'; // Only if you will use the database in this file
require_once 'config.php'; // Make sure this is at the top for your API key

// Prevent browser from caching this page
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");


// Restrict access to only logged-in admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login");  
    exit;
}
// Fetch admin details
$adminId = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Fallback in case something goes wrong
if (!$admin) {
    die("Admin data not found.");
}

// Store info in variables (optional)
$adminName = $admin['first_name'] . ' ' . $admin['last_name'];
$adminInitials = strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1));
$adminEmail = $admin['email'];
$adminRole = $admin['role'];

// Fetch only pending users
$stmt = $conn->prepare("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at DESC");
$stmt->execute();
$pendingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count pending users
$pendingCount = count($pendingUsers);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


// Fetch all users excluding rejected and pending ones
$stmt = $conn->prepare("SELECT * FROM users WHERE status NOT IN ('rejected', 'pending') ORDER BY created_at DESC");
$stmt->execute();
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$allUserCount = count($allUsers);

// Fetch all rejected users
$stmtRejected = $conn->prepare("SELECT * FROM users WHERE status = 'rejected' ORDER BY created_at DESC");
$stmtRejected->execute();
$rejectedUsers = $stmtRejected->fetchAll(PDO::FETCH_ASSOC);
$rejectedUserCount = count($rejectedUsers);

// Fetch all resume_info records
$stmtResume = $conn->prepare("SELECT user_id, program FROM resume_info");
$stmtResume->execute();
$resumeInfoData = $stmtResume->fetchAll(PDO::FETCH_ASSOC);

$resumeMap = [];
foreach ($resumeInfoData as $resume) {
    $resumeMap[$resume['user_id']] = $resume; // store the whole row, not just program
}

// Fetch only accepted users
$stmtAccepted = $conn->prepare("SELECT * FROM users WHERE status = 'accepted' ORDER BY created_at DESC");
$stmtAccepted->execute();
$acceptedUsers = $stmtAccepted->fetchAll(PDO::FETCH_ASSOC);
$acceptedUserCount = count($acceptedUsers);

// Count accepted Students
$stmtStudents = $conn->prepare("SELECT COUNT(*) FROM users WHERE status = 'accepted' AND role = 'Student'");
$stmtStudents->execute();
$studentCount = $stmtStudents->fetchColumn();

// Count accepted Alumni
$stmtAlumni = $conn->prepare("SELECT COUNT(*) FROM users WHERE status = 'accepted' AND role = 'Alumni'");
$stmtAlumni->execute();
$alumniCount = $stmtAlumni->fetchColumn();

// Fetch all announcements (latest first)
$stmt = $conn->prepare("SELECT * FROM announcements ORDER BY created_at DESC");
$stmt->execute();
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeAnnouncementCount = 0;
foreach ($announcements as $a) {
    if (!isset($a['status']) || strtolower($a['status']) === 'active') {
        $activeAnnouncementCount++;
    }
}

// Fetch all opportunities
$stmt = $conn->query("SELECT * FROM opportunities ORDER BY created_at DESC");
$opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count active and inactive opportunities
$activeOpportunitiesCount = 0;
$inactiveOpportunitiesCount = 0;
$internshipWithMOACount = 0; 
$totalOpportunitiesCount = count($opportunities);

foreach ($opportunities as $opp) {
    if (isset($opp['status']) && strtolower($opp['status']) === 'active') {
        $activeOpportunitiesCount++;
    } else {
        $inactiveOpportunitiesCount++;
    }
    // Count internships with MOA
    if (
        isset($opp['type'], $opp['moa_status']) &&
        strtolower($opp['type']) === 'internship' &&
        $opp['moa_status']
    ) {
        $internshipWithMOACount++;
    }
}

// Count of users by role
$stmtRoleDist = $conn->query("SELECT role, COUNT(*) as count FROM users WHERE status = 'approved' AND role IN ('Student', 'Alumni') GROUP BY role");
$roleDistribution = $stmtRoleDist->fetchAll(PDO::FETCH_ASSOC);

// Most popular programs
$stmtPrograms = $conn->query("SELECT program, COUNT(*) as count FROM resume_info GROUP BY program ORDER BY count DESC");
$programStats = $stmtPrograms->fetchAll(PDO::FETCH_ASSOC);

// Most popular skills
$stmtSkills = $conn->query("SELECT skills FROM resume_info");
$skillsRaw = $stmtSkills->fetchAll(PDO::FETCH_COLUMN);
$skillCount = [];
foreach ($skillsRaw as $skillLine) {
    $skills = array_map('trim', explode(',', $skillLine));
    foreach ($skills as $skill) {
        if ($skill) $skillCount[$skill] = ($skillCount[$skill] ?? 0) + 1;
    }
}
arsort($skillCount);

$stmtEmployment = $conn->query("SELECT employment_status FROM resume_info");
$employed = 0;
$unemployed = 0;

foreach ($stmtEmployment->fetchAll(PDO::FETCH_COLUMN) as $status) {
    $status = strtolower(trim($status));
    if ($status === 'employed') {
        $employed++;
    } elseif ($status === 'unemployed') {
        $unemployed++;
    }
}

// Graduation year distribution from resume_info.education_status
$stmtGradYear = $conn->query("SELECT education_status FROM resume_info WHERE education_status IS NOT NULL AND education_status != ''");
$educationStatuses = $stmtGradYear->fetchAll(PDO::FETCH_COLUMN);

$gradYearCount = [];
foreach ($educationStatuses as $status) {
    $status = trim($status);

    if (preg_match('/present|ongoing/i', $status)) {
        $year = 'Ongoing';
    } elseif (preg_match('/(\d{4})\D*$/', $status, $matches)) {
        // Get the last 4-digit year in the string (e.g., "09/2020 - 08/2024" => 2024)
        $year = $matches[1];
    } else {
        $year = 'Unknown';
    }

    $gradYearCount[$year] = ($gradYearCount[$year] ?? 0) + 1;
}
ksort($gradYearCount);

$gradYearLabels = array_keys($gradYearCount);
$gradYearData = array_values($gradYearCount);

// Fetch program and experience for users with real jobs
$stmt = $conn->query("SELECT program, experience FROM resume_info WHERE experience IS NOT NULL AND experience != ''");
$userJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rightJob = 0;
$underemployed = 0;

foreach ($userJobs as $user) {
    $program = $user['program'];
    $experience = $user['experience'];

    // Skip if experience is self-taught, freelance, or not a real job
    if (preg_match('/self[- ]?taught|freelance|intern|student|training|course|bootcamp/i', $experience)) {
        continue;
    }

    // Compose the prompt for OpenAI
    $prompt = "A person graduated in \"$program\" and their job experience is \"$experience\". Is this person in a job that matches their degree? Answer only 'Right Job' or 'Underemployed'.";

    // Call OpenAI API
    $response = openai_classify_job($prompt);

    if (stripos($response, 'right job') !== false) {
        $rightJob++;
    } else {
        $underemployed++;
    }
}

$employmentMatchLabels = ['Right Job', 'Underemployed'];
$employmentMatchData = [$rightJob, $underemployed];

// Employment status calculation
$stmt = $conn->query("SELECT program, experience, education_status FROM resume_info WHERE experience IS NOT NULL AND experience != ''");
$userJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$employed = 0;
$unemployed = 0;

foreach ($userJobs as $user) {
    $program = $user['program'];
    $experience = strtolower($user['experience']);
    $education_status = strtolower($user['education_status'] ?? '');

    // Skip if experience is self-taught, freelance, or not a real job
    if (preg_match('/self[- ]?taught|freelance|intern|student|training|course|bootcamp/i', $experience)) {
        // Not a valid job, check if graduated
        if (preg_match('/\d{4}\D*$/', $education_status) && !preg_match('/present|ongoing/i', $education_status)) {
            $unemployed++;
        }
        continue;
    }

    // If graduated and has a valid job
    if (preg_match('/\d{4}\D*$/', $education_status) && !preg_match('/present|ongoing/i', $education_status)) {
        $employed++;
    }
}

$employmentStatusLabels = ['Employed', 'Unemployed'];
$employmentStatusData = [$employed, $unemployed];

// Employment status calculation with AI for ambiguous cases
$stmt = $conn->query("SELECT program, experience, education_status FROM resume_info WHERE experience IS NOT NULL AND experience != ''");
$userJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$employed = 0;
$unemployed = 0;

foreach ($userJobs as $user) {
    $program = $user['program'];
    $experience = strtolower($user['experience']);
    $education_status = strtolower($user['education_status'] ?? '');

    // If experience is clearly not a real job
    if (preg_match('/self[- ]?taught|freelance|intern|student|training|course|bootcamp/i', $experience)) {
        // If graduated, but no valid job, count as unemployed
        if (preg_match('/\d{4}\D*$/', $education_status) && !preg_match('/present|ongoing/i', $education_status)) {
            $unemployed++;
        }
        continue;
    }

    // If experience/job is ongoing (e.g., contains 'present' or 'current'), count as employed
    if (preg_match('/present|current/i', $experience)) {
        $employed++;
        continue;
    }

    // If graduated and has a valid job, count as employed
    if (preg_match('/\d{4}\D*$/', $education_status) && !preg_match('/present|ongoing/i', $education_status)) {
        $employed++;
        continue;
    }

    // Ambiguous case: Use AI to decide
    $prompt = "A person graduated in \"$program\" and their job experience is \"$experience\". Are they employed? Answer only 'Employed' or 'Unemployed'.";
    $response = openai_classify_job($prompt);

    if (stripos($response, 'employed') !== false) {
        $employed++;
    } else {
        $unemployed++;
    }
}

$employmentStatusLabels = ['Employed', 'Unemployed'];
$employmentStatusData = [$employed, $unemployed];

// Employment status calculation using AI and experience dates
$stmt = $conn->query("SELECT program, experience FROM resume_info");
$userRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$employed = 0;
$unemployed = 0;

foreach ($userRows as $user) {
    $program = $user['program'];
    $experience = strtolower($user['experience'] ?? '');

    // If experience contains 'current' or 'present', count as employed
    if (preg_match('/current|present/', $experience)) {
        $employed++;
        continue;
    }

    // Try to find the latest year in the experience string
    if (preg_match_all('/\b(20\d{2})\b/', $experience, $matches)) {
        $years = array_map('intval', $matches[1]);
        $latestYear = max($years);
        $currentYear = (int)date('Y');
        if ($latestYear === $currentYear) {
            $employed++;
            continue;
        }
    }

    // Otherwise, use AI to decide
    $prompt = "A person with the following work experience: \"$experience\". Are they currently employed? Answer only 'Employed' or 'Unemployed'.";
    $response = openai_classify_job($prompt);

    if (stripos($response, 'employed') !== false) {
        $employed++;
    } else {
        $unemployed++;
    }
}

$employmentStatusLabels = ['Employed', 'Unemployed'];
$employmentStatusData = [$employed, $unemployed];

// Helper function to call OpenAI API
function openai_classify_job($prompt) {
    $config = require 'config.php';
    $openaiApiKey = $config['openai_api_key'];
    $url = "https://api.openai.com/v1/chat/completions";
    $data = [
        "model" => "gpt-4-turbo",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ],
        "max_tokens" => 10,
        "temperature" => 0
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $openaiApiKey"
    ]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    if ($result === false) {
        error_log('OpenAI API error: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($result, true);
    return $json['choices'][0]['message']['content'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CareerLink NEU | Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="css/dashboard.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


  <style>
    @media (max-width: 768px) {
      .sidebar {
        position: absolute;
        z-index: 1030;
        width: 250px;
        display: none;
      }
      .sidebar.show {
        display: block;
      }
    }
  </style>
</head>
<body>
  <div class="d-flex">
    <!-- Sidebar -->
    <nav class="sidebar p-3 bg-success text-white flex-column" id="sidebar">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <a href="admin" class="text-decoration-none">
            <h5 class="fw-bold text-white mb-0 mb-0">CareerLink <span class="text-warning">NEU</span></h5>
        </a>
        <button class="btn text-white d-md-none" onclick="toggleSidebar()"><i class="bi bi-x-lg"></i></button>
      </div>

      <a href="#" class="sidebar-link active" data-target="admin-dashboard"><i class="bi bi-grid"></i> Admin Dashboard</a>
      <a href="#" class="sidebar-link" data-target="manage-users"><i class="bi bi-people"></i> Manage Users</a>
      <a href="#" class="sidebar-link" data-target="pending-users"><i class="bi bi-person-plus"></i> Pending Users</a>
      <a href="#" class="sidebar-link" data-target="admin-announcements"><i class="bi bi-bell"></i> Announcements</a>
      <a href="#" class="sidebar-link" data-target="admin-opportunities"><i class="bi bi-briefcase"></i> Opportunities</a>
      <!-- <a href="#" class="sidebar-link" data-target="partner-companies"><i class="bi bi-building"></i> Partner Companies</a> -->
      <a href="#" class="sidebar-link" data-target="university-analytics"><i class="bi bi-bar-chart-line"></i> University Analytics</a>

      <!-- script for sidebar links reloadaing behavior -->
      <script>
        function showAdminSectionById(sectionId) {
            // Hide all admin sections using Bootstrap's d-none
            document.querySelectorAll('.admin-section').forEach(section => {
            section.classList.add('d-none');
            });

            // Show the selected one
            const targetSection = document.getElementById(sectionId);
            if (targetSection) targetSection.classList.remove('d-none');

            // Toggle active sidebar link
            document.querySelectorAll('.sidebar-link').forEach(link => {
            link.classList.remove('active');
            });

            const activeLink = document.querySelector(`.sidebar-link[data-target="${sectionId}"]`);
            if (activeLink) activeLink.classList.add('active');
        }

        // Save and switch admin section on click
        document.querySelectorAll('.sidebar-link').forEach(link => {
            link.addEventListener('click', function (e) {
            e.preventDefault();
            const target = this.getAttribute('data-target');
            sessionStorage.setItem('adminActiveSection', target);
            showAdminSectionById(target);

            // Close sidebar on mobile
            if (window.innerWidth < 768) {
                document.getElementById("sidebar").classList.remove("show");
            }
            });
        });

        // Restore last section on load
        document.addEventListener('DOMContentLoaded', function () {
            const saved = sessionStorage.getItem('adminActiveSection') || 'admin-dashboard';
            showAdminSectionById(saved);
        });
      </script>

      <div class="mt-auto pt-4">
        <a href="#" class="text-white" data-bs-toggle="modal" data-bs-target="#logoutModal">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
        <!-- Logout Modal -->
        <div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
            <div class="modal-body text-center">
                <p class="mb-3 text-dark">Are you sure you want to logout?</p>
                <div class="d-flex justify-content-center gap-2">
                <button class="btn btn-success btn-sm" data-bs-dismiss="modal">Cancel</button>
                <a href="logout" class="btn btn-danger btn-sm">Logout</a>
                </div>
            </div>
            </div>
        </div>
        </div>

      </div>
    </nav>

    <!-- Main Content -->
    <main class="flex-grow-1">
      <div class="container-fluid p-4">

        <!-- dashboard Section -->
        <section id="admin-dashboard" class="admin-section">
            <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
                <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
                </button>
                <div class="text-end">
                <strong><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong><br />
                <small class="text-muted"><?= htmlspecialchars($admin['role']) ?></small>
                </div>
                <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center"
                    style="width: 32px; height: 32px;">
                <?= strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1)) ?>
                </div>
                <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="alert alert-success alert-dismissible fade show mt-2 mx-3" role="alert">
                    <?= $_SESSION['flash_success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
                
                <?php if ($flash): ?>
                    <div class="alert alert-success alert-dismissible fade show mx-3" role="alert">
                        <?= htmlspecialchars($flash) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
            <h4 class="fw-bold text-center">Admin Dashboard</h4>
            <p class="text-center text-muted mb-4">Welcome to the admin control panel. Manage users, announcements, and opportunities.</p>

            <div class="d-flex justify-content-center gap-2 flex-wrap mb-4">
                <div class="d-flex justify-content-center gap-2 flex-wrap mb-4">
                    <button class="btn btn-outline-secondary btn-sm admin-nav-btn" data-target="manage-users">Manage users</button>
                    <button class="btn btn-outline-secondary btn-sm admin-nav-btn" data-target="pending-users">Pending users</button>
                    <button class="btn btn-outline-secondary btn-sm admin-nav-btn" data-target="admin-announcements">Create Announcements</button>
                    <button class="btn btn-outline-secondary btn-sm admin-nav-btn" data-target="admin-opportunities">Create Opportunities</button>
                    <!-- <button class="btn btn-outline-secondary btn-sm admin-nav-btn" data-target="partner-companies">Partner Companies</button> -->
                    <button class="btn btn-outline-secondary btn-sm admin-nav-btn" data-target="university-analytics">Analytics</button>
                    
                </div>

                <!-- Admin dashboard nav buttons -->
                <script>
                    // Admin dashboard nav buttons
                    document.querySelectorAll('.admin-nav-btn').forEach(btn => {
                        btn.addEventListener('click', function () {
                        // Get the target section ID
                        const targetId = this.getAttribute('data-target');

                        // Show the correct section
                        document.querySelectorAll('.admin-section').forEach(section => {
                            section.classList.add('d-none');
                        });
                        document.getElementById(targetId).classList.remove('d-none');

                        // Highlight the corresponding sidebar link
                        document.querySelectorAll('.sidebar-link').forEach(link => {
                            if (link.getAttribute('data-target') === targetId) {
                            link.classList.add('active');
                            } else {
                            link.classList.remove('active');
                            }
                        });
                        });
                    });
                </script>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card shadow-sm text-center">
                        <div class="card-body">
                            <h6 class="fw-bold">Total Users</h6>
                            <h3><?= $allUserCount ?></h3>
                            <p class="text-muted small mb-0">
                                <?php if (empty($allUserCount)): ?>
                                    Waiting for data
                                <?php endif; ?>
                            </p>
                            <i class="bi bi-people fs-4 text-muted"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm text-center">
                        <div class="card-body">
                            <h6 class="fw-bold">Total Announcements</h6>
                            <h3><?= $activeAnnouncementCount ?></h3>
                            <p class="text-muted small mb-0">
                                <?php if (empty($activeAnnouncementCount)): ?>
                                    No announcements yet
                                <?php endif; ?>
                            </p>
                            <i class="bi bi-bell fs-4 text-muted"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm text-center">
                        <div class="card-body">
                            <h6 class="fw-bold">Job Opportunities</h6>
                            <h3><?= $activeOpportunitiesCount ?></h3>
                            <p class="text-muted small mb-0">
                                <?php if (empty($activeOpportunitiesCount)): ?>
                                    No opportunities yet
                                <?php endif; ?>
                            </p>
                            <i class="bi bi-briefcase fs-4 text-muted"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="card shadow-sm text-center">
                        <div class="card-body">
                            <h6 class="fw-bold">Internships with MOA</h6>
                            <h3><?= $internshipWithMOACount ?></h3>
                            <p class="text-muted small mb-0">
                                <?php if (empty($internshipWithMOACount)): ?>
                                    No MOAs yet
                                <?php endif; ?>
                            </p>
                            <i class="bi bi-file-earmark-text fs-4 text-muted"></i>
                        </div>
                    </div>
                </div>
                <!-- <div class="col-md-6">
                    <div class="card shadow-sm text-center">
                        <div class="card-body">
                            <h6 class="fw-bold">Partner Companies</h6>
                            <h3><?= $totalOpportunitiesCount ?></h3>
                            <p class="text-muted small mb-0">
                                <?php if (empty($totalOpportunitiesCount)): ?>
                                    No partners yet
                                <?php endif; ?>
                            </p>
                            <i class="bi bi-building fs-4 text-muted"></i>
                        </div>
                    </div>
                </div> -->
            </div>
        </section>

        <!-- Manage users Section -->
        <section id="manage-users" class="admin-section d-none">
            <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
                <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
                </button>
                <div class="text-end">
                <strong><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong><br />
                <small class="text-muted"><?= htmlspecialchars($admin['role']) ?></small>
                </div>
                <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center"
                    style="width: 32px; height: 32px;">
                <?= strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1)) ?>
                </div>
            </div>

          <h4 class="fw-bold text-center">Manage Users</h4>
            <p class="text-center text-muted mb-4">View and manage user roles and permissions.</p>

            <div class="row g-2 align-items-center mb-3">
                 <?php if ($flash): ?>
            <div class="alert alert-success alert-dismissible fade show mx-3" role="alert">
                <?= htmlspecialchars($flash) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
             <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="alert alert-success alert-dismissible fade show mt-2 mx-3" role="alert">
                    <?= $_SESSION['flash_success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>
            <div class="col-md-4">
                <input type="text" id="searchUsers" class="form-control" placeholder="Search users by name, email, or ID...">
            </div>
            <div class="col-md-3">
                    <select class="form-select" id="departmentSelect">
                        <option selected>All Departments</option>
                        <option>College of Accountancy</option>
                        <option>College of Agriculture</option>
                        <option>College of Arts and Science</option>
                        <option>College of Business Administration</option>
                        <option>College of Communication</option>
                        <option>College of Informatics and Computing Studies</option>
                        <option>College of Criminology</option>
                        <option>College of Education</option>
                        <option>College of Engineering and Architecture</option>
                        <option>College of Medical Technology</option>
                        <option>College of Midwifery</option>
                        <option>College of Music</option>
                        <option>College of Nursing</option>
                        <option>College of Physical Therapy</option>
                        <option>College of Respiratory Therapy</option>
                        <option>School of International Relations</option>
                    </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" id="courseSelect">
                    <option selected>All Courses</option>
                </select>
            </div>
            <script>
                const deptToCourses = {
                    "College of Accountancy": [
                        "Bachelor of Science in Accountancy",
                        "Bachelor of Science in Accounting Information System"
                    ],
                    "College of Agriculture": [
                        "Bachelor of Science in Agriculture"
                    ],
                    "College of Arts and Science": [
                        "Bachelor of Arts in Economics",
                        "Bachelor of Arts in Political Science",
                        "Bachelor of Science in Biology",
                        "Bachelor of Science in Psychology",
                        "Bachelor of Public Administration"
                    ],
                    "College of Business Administration": [
                        "Bachelor of Science in Business Administration Major in Financial Management",
                        "Bachelor of Science in Business Administration Major in Human Resource Development Management",
                        "Bachelor of Science in Business Administration Major in Legal Management",
                        "Bachelor of Science in Business Administration Major in Marketing Management",
                        "Bachelor of Science in Entrepreneurship",
                        "Bachelor of Science in Real Estate Management"
                    ],
                    "College of Communication": [
                        "Bachelor of Arts in Broadcasting",
                        "Bachelor of Arts in Communication",
                        "Bachelor of Arts in Journalism"
                    ],
                    "College of Informatics and Computing Studies": [
                        "Bachelor of Library and Information Science",
                        "Bachelor of Science in Computer Science",
                        "Bachelor of Science in Entertainment and Multimedia Computing with Specialization in Digital Animation Technology",
                        "Bachelor of Science in Entertainment and Multimedia Computing with Specialization in Game Development",
                        "Bachelor of Science in Information Technology",
                        "Bachelor of Science in Information System"
                    ],
                    "College of Criminology": [
                        "Bachelor of Science in Criminology"
                    ],
                    "College of Education": [
                        "Bachelor of Elementary Education",
                        "Bachelor of Elementary Education with Specialization in Preschool Education",
                        "Bachelor of Elementary Education with Specialization in Special Education",
                        "Bachelor of Secondary Education Major in Music, Arts, and Physical Education",
                        "Bachelor of Secondary Education Major in English",
                        "Bachelor of Secondary Education Major in Filipino",
                        "Bachelor of Secondary Education Major in Mathematics",
                        "Bachelor of Secondary Education Major in Science",
                        "Bachelor of Secondary Education Major in Social Studies",
                        "Bachelor of Secondary Education Major in Technology and Livelihood Education"
                    ],
                    "College of Engineering and Architecture": [
                        "Bachelor of Science in Architecture",
                        "Bachelor of Science in Astronomy",
                        "Bachelor of Science in Civil Engineering",
                        "Bachelor of Science in Electrical Engineering",
                        "Bachelor of Science in Electronics Engineering",
                        "Bachelor of Science in Industrial Engineering",
                        "Bachelor of Science in Mechanical Engineering"
                    ],
                    "College of Medical Technology": [
                        "Bachelor of Science in Medical Technology"
                    ],
                    "College of Midwifery": [
                        "Diploma in Midwifery"
                    ],
                    "College of Music": [
                        "Bachelor of Music in Choral Conducting",
                        "Bachelor of Music in Music Education",
                        "Bachelor of Music in Piano",
                        "Bachelor of Music in Voice"
                    ],
                    "College of Nursing": [
                        "Bachelor of Science in Nursing"
                    ],
                    "College of Physical Therapy": [
                        "Bachelor of Science in Physical Therapy"
                    ],
                    "College of Respiratory Therapy": [
                        "Bachelor of Science in Respiratory Therapy"
                    ],
                    "School of International Relations": [
                        "Bachelor of Arts in Foreign Service"
                    ],
                    "All Departments": []
                };
            </script>

            <div class="col-md-2">
                <select class="form-select" id="roleSelect">
                    <option selected>All Roles</option>
                    <option>Student</option>
                    <option>Alumni</option>
                    <option>Admin</option>
                </select>
            </div>

            </div>
            
           
            <div class="table-responsive shadow-sm">
            <table class="table table-bordered table-hover align-middle text-center mb-0">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Student ID</th>
                    <th>TOR Number</th>
                    <th>Program of Study</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody id="userTable">
                    <?php $i = 1; foreach ($allUsers as $user): 
                        $resume = $resumeMap[$user['id']] ?? null;
                    ?>
                    <tr class="user-row" data-role="<?= $user['role'] ?>">
                        <td><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars($user['student_number'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($user['tor_number'] ?? '—') ?></td>
                        <td class="program-cell"><?= htmlspecialchars($resume['program'] ?? '—') ?></td>
                        <td>
                            <select 
                                class="form-select form-select-sm change-role-select"
                                data-user-id="<?= $user['id'] ?>"
                                data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>"
                                data-current-role="<?= $user['role'] ?>"
                            >
                                <option <?= $user['role'] === 'Student' ? 'selected' : '' ?>>Student</option>
                                <option <?= $user['role'] === 'Alumni' ? 'selected' : '' ?>>Alumni</option>
                                <option <?= $user['role'] === 'Admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </td>

                        <td>
                            <a href="#" 
                                class="text-danger me-2 open-delete-user-modal" 
                                data-bs-toggle="modal" 
                                data-bs-target="#deleteUserModal"
                                data-user-id="<?= $user['id'] ?>" 
                                data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>"
                                title="Delete User" 
                                style="text-decoration: none;">
                                    <i class="bi bi-trash"></i>
                            </a>
                            <a href="#" 
                            class="text-primary me-2 open-view-user-modal" 
                            data-bs-toggle="modal" 
                            data-bs-target="#viewUserModal"
                            data-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>"
                            data-email="<?= htmlspecialchars($user['email']) ?>"
                            data-student="<?= htmlspecialchars($user['student_number'] ?? 'N/A') ?>"
                            data-tor="<?= htmlspecialchars($user['tor_number'] ?? 'N/A') ?>"
                            data-role="<?= htmlspecialchars($user['role']) ?>"
                            data-graduation="<?= htmlspecialchars($user['graduation_year'] ?? 'N/A') ?>"
                            data-program="<?= htmlspecialchars($resume['program'] ?? 'N/A') ?>"
                            title="View User Details"
                            style="text-decoration: none;">
                            <i class="bi bi-eye"></i>
                            </a>

  
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="text-center text-muted small mt-2">A list of all registered users.</p>

            <!-- Delete User Modal -->
            <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="delete_user.php">
                <div class="modal-content">
                    <div class="modal-header">
                    <h5 class="modal-title" id="deleteUserModalLabel">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                    Are you sure you want to delete <strong id="deleteUserName">this user</strong>?
                    <input type="hidden" name="user_id" id="deleteUserId">
                    </div>
                    <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </div>
                </form>
            </div>
            </div>

            <!-- View User Modal -->
            <div class="modal fade" id="viewUserModal" tabindex="-1" aria-labelledby="viewUserModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="viewUserModalLabel">User Information</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Name:</strong> <span id="viewName">N/A</span></p>
                    <p><strong>Email:</strong> <span id="viewEmail">N/A</span></p>
                    <p><strong>Student ID:</strong> <span id="viewStudent">N/A</span></p>
                    <p><strong>TOR Number:</strong> <span id="viewTor">N/A</span></p>
                    <p><strong>Program:</strong> <span id="viewProgram">N/A</span></p>
                    <p><strong>Graduation Year:</strong> <span id="viewGraduation">N/A</span></p>
                    <p><strong>Role:</strong> <span id="viewRole">N/A</span></p>
                </div>
                </div>
            </div>
            </div>

            <!-- Confirm Role Change Modal -->
            <div class="modal fade" id="confirmRoleModal" tabindex="-1" aria-labelledby="confirmRoleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="update_user_role.php">
                <div class="modal-content">
                    <div class="modal-header">
                    <h5 class="modal-title" id="confirmRoleModalLabel">Confirm Role Change</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                    Are you sure you want to update <strong id="confirmRoleName">this user</strong> to role <strong id="confirmRoleValue">New Role</strong>?
                    <input type="hidden" name="user_id" id="confirmRoleUserId">
                    <input type="hidden" name="new_role" id="confirmRoleNewValue">
                    </div>
                    <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                    </div>
                </div>
                </form>
            </div>
            </div>



            <!-- Manage users script  -->
            <script>
                //delete users script
                document.querySelectorAll('.open-delete-user-modal').forEach(button => {
                    button.addEventListener('click', () => {
                        const userId = button.dataset.userId;
                        const userName = button.dataset.userName;

                        document.getElementById('deleteUserId').value = userId;
                        document.getElementById('deleteUserName').textContent = userName;
                    });
                });

                //view more script
                document.querySelectorAll('.open-view-user-modal').forEach(button => {
                    button.addEventListener('click', () => {
                        document.getElementById('viewName').textContent = button.dataset.name;
                        document.getElementById('viewEmail').textContent = button.dataset.email;
                        document.getElementById('viewStudent').textContent = button.dataset.student;
                        document.getElementById('viewTor').textContent = button.dataset.tor;
                        document.getElementById('viewProgram').textContent = button.dataset.program;
                        document.getElementById('viewGraduation').textContent = button.dataset.graduation;
                        document.getElementById('viewRole').textContent = button.dataset.role;
                    });
                });

                //trigger modal for changing user role
                document.querySelectorAll('.change-role-select').forEach(select => {
                    select.addEventListener('change', () => {
                        const selectedRole = select.value;
                        const originalRole = select.dataset.currentRole;

                        if (selectedRole === originalRole) return; // no change

                        const userId = select.dataset.userId;
                        const userName = select.dataset.userName;

                        // Set modal values
                        document.getElementById('confirmRoleUserId').value = userId;
                        document.getElementById('confirmRoleNewValue').value = selectedRole;
                        document.getElementById('confirmRoleName').textContent = userName;
                        document.getElementById('confirmRoleValue').textContent = selectedRole;

                        // Show modal
                        const roleModal = new bootstrap.Modal(document.getElementById('confirmRoleModal'));
                        roleModal.show();

                        // Reset the select temporarily to original (UX-friendly; optional)
                        select.value = originalRole;
                    });
                });

                //for search users
                document.getElementById('searchUsers').addEventListener('input', function () {
                    const query = this.value.toLowerCase().trim();
                    const rows = document.querySelectorAll('#userTable .user-row');

                    rows.forEach(row => {
                        const name = row.cells[0].textContent.toLowerCase();
                        const email = row.cells[1].textContent.toLowerCase();
                        const studentId = row.cells[2].textContent.toLowerCase();

                        const match = name.includes(query) || email.includes(query) || studentId.includes(query);

                        row.style.display = match ? '' : 'none';
                    });
                });

                //for auto update courses based on department
                document.getElementById('departmentSelect').addEventListener('change', function () {
                    const department = this.value;
                    const courseSelect = document.getElementById('courseSelect');

                    // Reset courses dropdown
                    courseSelect.innerHTML = '<option selected>All Courses</option>';

                    // Load relevant courses
                    const programs = deptToCourses[department] || [];
                    programs.forEach(program => {
                        const option = document.createElement('option');
                        option.textContent = program;
                        courseSelect.appendChild(option);
                    });
                });
                function filterUsersByDeptCourseAndRole() {
                    const selectedDept = document.getElementById('departmentSelect').value;
                    const selectedCourse = document.getElementById('courseSelect').value;
                    const selectedRole = document.getElementById('roleSelect').value;
                    const rows = document.querySelectorAll('#userTable .user-row');

                    rows.forEach(row => {
                        const programText = row.querySelector('.program-cell').textContent.trim();
                        const userRole = row.dataset.role;

                        const matchesDept = selectedDept === "All Departments" || 
                                            (deptToCourses[selectedDept] && deptToCourses[selectedDept].includes(programText));

                        const matchesCourse = selectedCourse === "All Courses" || programText === selectedCourse;
                        const matchesRole = selectedRole === "All Roles" || userRole === selectedRole;

                        row.style.display = (matchesDept && matchesCourse && matchesRole) ? '' : 'none';
                    });
                }

                // Trigger filtering on all 3 dropdowns
                document.getElementById('departmentSelect').addEventListener('change', filterUsersByDeptCourseAndRole);
                document.getElementById('courseSelect').addEventListener('change', filterUsersByDeptCourseAndRole);
                document.getElementById('roleSelect').addEventListener('change', filterUsersByDeptCourseAndRole);

                    
            </script>


        </section>

        <!-- Pending users Section -->
        <section id="pending-users" class="admin-section d-none">
           <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
                <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
                </button>
                <div class="text-end">
                <strong><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong><br />
                <small class="text-muted"><?= htmlspecialchars($admin['role']) ?></small>
                </div>
                <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center"
                    style="width: 32px; height: 32px;">
                <?= strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1)) ?>
                </div>
            </div>

            <h4 class="fw-bold text-center">Pending Users</h4>
                <p class="text-center text-muted mb-4">Review and approve registration requests from university students.</p>
                
                
                <!-- Filters -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col-md-6">
                        <input type="text" id="pendingSearch" class="form-control" placeholder="Search by name, email, or ID..." />
                    </div>
                    <div class="col-md-3">
                        <select id="pendingRole" class="form-select">
                            <option selected>All Roles</option>
                            <option>Student</option>
                            <option>Alumni</option>                        
                        </select>
                    </div>
                               
                </div>
                
                <!-- Table -->
                <div class="table-responsive shadow-sm">
                    <table class="table table-bordered table-hover align-middle text-center mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><input type="checkbox" id="selectAllPending"></th> <!-- ✅ Added Select All -->
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Student ID</th>
                                <th>Batch</th>
                                <th>TOR Number</th>
                                <th>Registered Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingUserTable">
                            <?php foreach ($pendingUsers as $user): 
                                $resume = $resumeMap[$user['id']] ?? null;
                            ?>
                            <tr class="pending-user-row" data-role="<?= $user['role'] ?>">
                                <td>
                                    <input type="checkbox" class="userCheckbox" value="<?= $user['id'] ?>"> <!-- ✅ Row checkbox -->
                                </td>
                                <td><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= htmlspecialchars($user['role']) ?></td>
                                <td><?= htmlspecialchars($user['student_number'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($user['role'] === 'Alumni' ? ($user['batch'] ?? 'N/A') : '-') ?></td>
                                <td><?= htmlspecialchars($user['tor_number'] ?? 'N/A') ?></td>
                                <td><?= date('m/d/Y', strtotime($user['created_at'])) ?></td>
                                <td>
                                    <div class="d-flex justify-content-center gap-2">
                                        <form method="post" action="approve_user" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>" />
                                            <button class="btn btn-success btn-sm" name="approve">
                                                <i class="bi bi-person-check"></i> Approve
                                            </button>
                                        </form>
                                        <form method="post" action="reject_user" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>" />
                                            <button class="btn btn-danger btn-sm" name="reject">
                                                <i class="bi bi-person-x"></i> Reject
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- No results message -->
                            <tr id="noPendingResults" style="display: none;">
                                <td colspan="9" class="text-center text-muted">No pending users found.</td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- Bulk Action Buttons -->
                    <div id="bulkActions" class="mt-3 text-center d-none">
                        <form id="bulkForm" method="post" action="bulk_user_action.php">
                            <input type="hidden" name="action" value="" id="bulkActionType">
                            <input type="hidden" name="user_ids" id="bulkUserIds">
                            
                            <button type="button" class="btn btn-success me-2" onclick="submitBulkAction('approve')">
                            <i class="bi bi-person-check"></i> Approve Selected
                            </button>
                            
                            <button type="button" class="btn btn-danger" onclick="submitBulkAction('reject')">
                            <i class="bi bi-person-x"></i> Reject Selected
                            </button>
                        </form>
                    </div>
                </div>
            
                <!-- SCRIPT FOR PENDING USERS FILTERING -->
               <script>
                    const selectAll = document.getElementById('selectAllPending');
                    const checkboxes = document.querySelectorAll('.userCheckbox');
                    const bulkActions = document.getElementById('bulkActions');

                    // Toggle all checkboxes
                    selectAll.addEventListener('change', () => {
                        checkboxes.forEach(cb => cb.checked = selectAll.checked);
                        toggleBulkActions();
                    });

                    // Toggle buttons when individual checkboxes are clicked
                    checkboxes.forEach(cb => cb.addEventListener('change', toggleBulkActions));

                    function toggleBulkActions() {
                        const anyChecked = [...checkboxes].some(cb => cb.checked);
                        bulkActions.classList.toggle('d-none', !anyChecked);
                    }

                    function submitBulkAction(action) {
                        const selectedIds = [...checkboxes]
                        .filter(cb => cb.checked)
                        .map(cb => cb.value);

                        if (selectedIds.length === 0) return;

                        document.getElementById('bulkUserIds').value = selectedIds.join(',');
                        document.getElementById('bulkActionType').value = action;
                        document.getElementById('bulkForm').submit();
                    }
               </script>

        </section>

        <!-- Announcements Section -->
        <section id="admin-announcements" class="admin-section d-none">
           <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
                <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
                </button>
                <div class="text-end">
                <strong><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong><br />
                <small class="text-muted"><?= htmlspecialchars($admin['role']) ?></small>
                </div>
                <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center"
                    style="width: 32px; height: 32px;">
                <?= strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1)) ?>
                </div>
            </div>
            <h4 class="fw-bold text-center">Manage Announcements</h4>
            <p class="text-center text-muted mb-4">Create, update, or remove university announcements and partnerships.</p>
            <?php if ($flash): ?>
            <div class="alert alert-success alert-dismissible fade show mx-3" role="alert">
                <?= htmlspecialchars($flash) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>


            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <input type="text" id="announcementSearch" class="form-control flex-grow-1" placeholder="Search announcements..." style="min-width: 250px;">

                <div class="d-flex gap-2">
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
                    <i class="bi bi-plus-lg"></i> Add New
                </button>
                <!-- Create Announcement Modal -->
                <div class="modal fade" id="createAnnouncementModal" tabindex="-1" aria-labelledby="createAnnouncementModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">

                    <form action="create_announcement" method="POST">
                        <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="createAnnouncementModalLabel">Create Announcement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <!-- ✅ Make this scrollable -->
                        <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" placeholder="Enter title" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Enter description" required></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3 d-none">
                            <label class="form-label">Published Date</label>
                            <input type="date" class="form-control" disabled>
                            </div>
                            <div class="col-md-6 mb-3">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" name="expiry_date">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" placeholder="e.g. Room 101, NEU Auditorium, Zoom">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Event Date & Time</label>
                            <input type="datetime-local" class="form-control" name="event_time">
                        </div>
                        </div>

                        <div class="modal-footer">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Create</button>
                        </div>
                    </form>

                    </div>
                </div>
                </div>


                </div>
            </div>

            <div class="table-responsive shadow-sm">
                <table class="table table-bordered table-hover align-middle text-center mb-0">
                <thead class="table-light">
                    <tr>
                    <th>Title <i class="bi bi-arrow-down-up ms-1"></i></th>
                    <th>Description</th>
                    <th>Author <i class="bi bi-arrow-down-up ms-1"></i></th>
                    <th>Location <i class="bi bi-arrow-down-up ms-1"></i></th>
                    <th>Created at <i class="bi bi-arrow-down-up ms-1"></i></th>
                    <th>Event time <i class="bi bi-arrow-down-up ms-1"></i></th>
                    <th>Expirtation Date <i class="bi bi-arrow-down-up ms-1"></i></th>
                    <th>Action</th>
                    </tr>
                </thead>
               <tbody id="announcementTable">
                    <?php if (!empty($announcements)): ?>
                        <?php foreach ($announcements as $announcement): ?>
                        <tr class="announcement-row">
                            <td class="fw-semibold"><?= htmlspecialchars($announcement['title']) ?></td>
                            <td><?= htmlspecialchars($announcement['content']) ?></td>
                            <td><?= htmlspecialchars($announcement['posted_by']) ?></td>
                            <td><?= htmlspecialchars($announcement['location']) ?></td>
                            <td><?= date('M d, Y', strtotime($announcement['created_at'])) ?></td>
                            <td>
                            <?= $announcement['event_time'] ? date('M d, Y • h:i A', strtotime($announcement['event_time'])) : '-' ?>
                            </td>
                            <td>
                            <?= $announcement['expiry_date'] ? date('M d, Y', strtotime($announcement['expiry_date'])) : '-' ?>
                            </td>
                            <td>
                            <a href="#" class="text-dark me-2" style="text-decoration: none;"
                                data-bs-toggle="modal" 
                                data-bs-target="#editAnnouncementModal"
                                data-id="<?= $announcement['id'] ?>"
                                data-title="<?= htmlspecialchars($announcement['title']) ?>"
                                data-description="<?= htmlspecialchars($announcement['content']) ?>"
                                data-location="<?= htmlspecialchars($announcement['location']) ?>"
                                data-event="<?= $announcement['event_time'] ?>"
                                data-expiry="<?= $announcement['expiry_date'] ?>">
                                <i class="bi bi-pencil"></i>

                                <a href="#" class="text-danger" 
                                data-bs-toggle="modal" 
                                data-bs-target="#deleteAnnouncementModal" 
                                data-id="<?= $announcement['id'] ?>" 
                                style="text-decoration: none;">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </a>
                            </td>
                            
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                            <td colspan="8" class="text-center text-muted">No announcements yet.</td>
                            </tr>
                        <?php endif; ?>
                </tbody>
                <!-- script for edit announcecement modal -->
                <script>

                    // FOR EDIT ANNOUNCEMENT MODAL
                    document.addEventListener('DOMContentLoaded', () => {
                    const editButtons = document.querySelectorAll('[data-bs-target="#editAnnouncementModal"]');
                    const modal = document.getElementById('editAnnouncementModal');

                    editButtons.forEach(button => {
                        button.addEventListener('click', () => {
                        modal.querySelector('form input[name="id"]').value = button.dataset.id;
                        modal.querySelector('form input[name="title"]').value = button.dataset.title;
                        modal.querySelector('form textarea[name="description"]').value = button.dataset.description;
                        modal.querySelector('form input[name="location"]').value = button.dataset.location;
                        modal.querySelector('form input[name="event_time"]').value = button.dataset.event;
                        modal.querySelector('form input[name="expiry_date"]').value = button.dataset.expiry;
                        });
                    });
                    });

                    // FOR DELETE ANNOUNCEMENT MODAL
                    document.addEventListener("DOMContentLoaded", function () {
                    const deleteModal = document.getElementById("deleteAnnouncementModal");
                    deleteModal.addEventListener("show.bs.modal", function (event) {
                        const button = event.relatedTarget;
                        const announcementId = button.getAttribute("data-id");
                        document.getElementById("deleteAnnouncementId").value = announcementId;
                    });
                    });

                    // SCRIPT FOR SEARCH ANNOUNCEMENTS
                    document.getElementById('announcementSearch').addEventListener('input', function () {
                        const query = this.value.toLowerCase().trim();
                        const rows = document.querySelectorAll('#announcementTable .announcement-row');
                        const noResult = document.getElementById('noAnnouncementResults');

                        let visibleCount = 0;

                        rows.forEach(row => {
                            const title = row.cells[0].textContent.toLowerCase();
                            const content = row.cells[1].textContent.toLowerCase();
                            const postedBy = row.cells[2].textContent.toLowerCase();
                            const location = row.cells[3].textContent.toLowerCase();

                            const matches = title.includes(query) || content.includes(query) || postedBy.includes(query) || location.includes(query);

                            row.style.display = matches ? '' : 'none';
                            if (matches) visibleCount++;
                        });

                        noResult.style.display = visibleCount === 0 ? '' : 'none';
                    });
                </script>


                </table>
            </div>

           
            <!-- Edit Announcement Modal -->
            <div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-labelledby="editAnnouncementModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                    <form action="update_announcement" method="POST">
                        <div class="modal-header">
                        <h5 class="modal-title">Edit Announcement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <!-- Apply scroll only to modal-body -->
                        <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                        <!-- Hidden ID -->
                        <input type="hidden" name="id" />

                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" required></textarea>
                        </div>

                        <div class="mb-3 d-none">
                            <label class="form-label">Published Date</label>
                            <input type="date" class="form-control" disabled>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Expires</label>
                            <input type="date" class="form-control" name="expiry_date">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Event Date & Time</label>
                            <input type="datetime-local" class="form-control" name="event_time">
                        </div>
                        </div>

                        <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Changes</button>
                        </div>
                    </form>
                    </div>
                </div>
            </div>
            

           
            <!-- Delete Confirmation Modal -->
            <div class="modal fade" id="deleteAnnouncementModal" tabindex="-1" aria-labelledby="deleteAnnouncementModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                <form action="delete_announcement" method="POST">
                    <div class="modal-header">
                    <h5 class="modal-title" id="deleteAnnouncementModalLabel">Delete Announcement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                    Are you sure you want to delete this announcement?
                    <input type="hidden" name="id" id="deleteAnnouncementId">
                    </div>
                    <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
                </div>
            </div>
            </div>

        </section>

        <!-- Opportunities Section -->
        <section id="admin-opportunities" class="admin-section d-none">
           <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
                <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
                </button>
                <div class="text-end">
                <strong><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong><br />
                <small class="text-muted"><?= htmlspecialchars($admin['role']) ?></small>
                </div>
                <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center"
                    style="width: 32px; height: 32px;">
                <?= strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1)) ?>
                </div>
            </div>

            <h4 class="fw-bold text-center">Manage Job Opportunities</h4>
            <p class="text-center text-muted mb-4">Create, update, or remove job and internship opportunities posted by partner companies.</p>
             <div class="row g-2 align-items-center mb-3">
                 <?php if ($flash): ?>
            <div class="alert alert-success alert-dismissible fade show mx-3" role="alert">
                <?= htmlspecialchars($flash) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Top Search Bar -->
            <div class="mb-2">
            <input type="text" id="opportunitySearch" class="form-control" placeholder="Search opportunities...">
            </div>

            <!-- Buttons Underneath -->
            <div class="d-flex gap-2 flex-wrap">
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <ul class="dropdown-menu">
                    <li class="dropdown-header">Filter by Type</li>
                    <li><a class="dropdown-item type-filter" href="#" data-filter="all">All types</li>
                    <li><a class="dropdown-item type-filter" href="#" data-filter="internship">Internship</li>
                    <li><a class="dropdown-item type-filter" href="#" data-filter="job">Job</li>
                    <li><a class="dropdown-item type-filter" href="#" data-filter="moa">With MOA</li>
                </ul>
            </div>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createOpportunityModal">
                <i class="bi bi-plus-lg"></i> Add New
            </button>
            </div>
            <br>

            <div class="table-responsive shadow-sm">
                <table class="table table-bordered table-hover align-middle text-center mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th>
                            <th>Company</th>
                            <th>Skills</th>
                            <th>Type</th>
                            <th>MOA</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="opportunityTable">
                        <?php foreach ($opportunities as $opp): ?>
                             <tr class="opportunity-row"
                                data-type="<?= strtolower($opp['type']) ?>"
                                data-moa="<?= $opp['moa_status'] ? 'yes' : 'no' ?>">
                                <td class="fw-semibold"><?= htmlspecialchars($opp['title']) ?></td>
                                <td><?= htmlspecialchars($opp['company']) ?></td>
                                <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($opp['skills']) ?></span></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($opp['type']) ?></span></td>
                                <td>
                                    <?php if ($opp['moa_status']): ?>
                                        <i class="bi bi-check-circle text-success"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle text-danger"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <!-- View More Icon -->
                                    <a href="#" style="text-decoration: none;"
                                    class="text-secondary me-2 view-details" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#viewOpportunityModal"
                                    data-title="<?= htmlspecialchars($opp['title']) ?>"
                                    data-application_link="<?= htmlspecialchars($opp['application_link']) ?>"
                                    data-deadline="<?= $opp['deadline'] ?>"
                                    data-moa_status="<?= $opp['moa_status'] ?>"
                                    data-moa_expiration="<?= $opp['moa_expiration'] ?>"
                                    data-status="<?= $opp['status'] ?>">
                                    <i class="bi bi-eye" title="View More"></i>
                                    </a>

                                    <!-- Edit Icon -->
                                    <a href="#" style="text-decoration: none;"
                                    class="text-dark me-2 edit-btn" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editOpportunityModal"
                                    data-id="<?= $opp['id'] ?>"
                                    data-title="<?= htmlspecialchars($opp['title'], ENT_QUOTES) ?>"
                                    data-company="<?= htmlspecialchars($opp['company'], ENT_QUOTES) ?>"
                                    data-type="<?= $opp['type'] ?>"
                                    data-description="<?= htmlspecialchars($opp['description'], ENT_QUOTES) ?>"
                                    data-skills="<?= htmlspecialchars($opp['skills'], ENT_QUOTES) ?>"
                                    data-application_link="<?= htmlspecialchars($opp['application_link'], ENT_QUOTES) ?>"
                                    data-deadline="<?= $opp['deadline'] ?>"
                                    data-moa_expiration="<?= $opp['moa_expiration'] ?>"
                                    data-moa_status="<?= $opp['moa_status'] ?>"
                                    data-status="<?= $opp['status'] ?>">
                                    <i class="bi bi-pencil" title="Edit"></i>
                                    </a>

                                    <!-- Delete Icon -->
                                    <a href="#" style="text-decoration: none;"
                                    class="text-danger open-delete-modal" 
                                    data-id="<?= $opp['id'] ?>" 
                                    data-title="<?= htmlspecialchars($opp['title']) ?>" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#deleteOpportunityModal"
                                    title="Delete">
                                    <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="modal fade" id="viewOpportunityModal" tabindex="-1" aria-labelledby="viewOpportunityModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-md">
                        <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title fw-bold" id="viewOpportunityModalLabel">Opportunity Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p><strong>Application Link:</strong> 
                                <a href="#" id="viewAppLink" target="_blank" class="text-decoration-underline">Open Link</a>
                            </p>

                            <p><strong>Deadline:</strong> <span id="viewDeadline">N/A</span></p>
                            <p><strong>MOA Status:</strong> <span id="viewMoaStatus">N/A</span></p>
                            <p><strong>MOA Expiration:</strong> <span id="viewMoaExpire">N/A</span></p>
                            <p><strong>Status:</strong> <span id="viewStatus">N/A</span></p>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- script for edit opportunity modal -->
                <script>
                    // FOR EDIT OPPORTUNITY MODAL
                    document.addEventListener("DOMContentLoaded", () => {
                    document.querySelectorAll(".edit-btn").forEach(button => {
                        button.addEventListener("click", () => {
                        const modal = document.getElementById("editOpportunityModal");
                        modal.querySelector('[name="id"]').value = button.dataset.id;
                        modal.querySelector('[name="title"]').value = button.dataset.title;
                        modal.querySelector('[name="company"]').value = button.dataset.company;
                        modal.querySelector('[name="type"]').value = button.dataset.type;
                        modal.querySelector('[name="description"]').value = button.dataset.description;
                        modal.querySelector('[name="skills"]').value = button.dataset.skills;
                        modal.querySelector('[name="application_link"]').value = button.dataset.application_link;
                        modal.querySelector('[name="deadline"]').value = button.dataset.deadline;
                        modal.querySelector('[name="moa_expiration"]').value = button.dataset.moa_expiration;
                        modal.querySelector('[name="moa_status"]').checked = button.dataset.moa_status === "1";
                        modal.querySelector('[name="status"]').checked = button.dataset.status === "Active";
                        });
                    });
                    });

                    // FOR DELETE OPPORTUNITY MODAL
                    document.addEventListener("DOMContentLoaded", function () {
                        const deleteModal = document.getElementById('deleteOpportunityModal');
                        deleteModal.addEventListener('show.bs.modal', function (event) {
                            const button = event.relatedTarget;
                            const id = button.getAttribute('data-id');
                            document.getElementById('deleteOpportunityId').value = id;
                        });
                    });

                    // VIEW MORE OPPORTUNITY MODAL
                    document.querySelectorAll('.view-details').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const appLink = btn.dataset.application_link?.trim() || '';
                            const deadlineRaw = btn.dataset.deadline;
                            const moaExpireRaw = btn.dataset.moa_expiration;

                            const deadline = deadlineRaw ? new Date(deadlineRaw).toLocaleDateString('en-US') : 'N/A';
                            const moaExpire = moaExpireRaw ? new Date(moaExpireRaw).toLocaleDateString('en-US') : 'N/A';

                            const moaStatus = btn.dataset.moa_status === '1'
                                ? '<i class="bi bi-check-circle text-success"></i> Valid'
                                : '<i class="bi bi-x-circle text-danger"></i> Not Set';

                            const status = btn.dataset.status === 'Active'
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Inactive</span>';

                            // Update Application Link
                            const appLinkEl = document.getElementById('viewAppLink');
                            if (appLink) {
                                appLinkEl.href = appLink;
                                appLinkEl.textContent = 'Open Link';
                                appLinkEl.classList.remove('disabled', 'text-muted');
                                appLinkEl.classList.add('text-decoration-underline', 'link-primary');
                            } else {
                                appLinkEl.removeAttribute('href');
                                appLinkEl.textContent = 'N/A';
                                appLinkEl.classList.add('text-muted', 'disabled');
                                appLinkEl.classList.remove('text-decoration-underline', 'link-primary');
                            }

                            // Set other fields
                            document.getElementById('viewDeadline').textContent = deadline;
                            document.getElementById('viewMoaExpire').textContent = moaExpire;
                            document.getElementById('viewMoaStatus').innerHTML = moaStatus;
                            document.getElementById('viewStatus').innerHTML = status;
                        });
                    });

                    // for search bar opportunities
                    document.getElementById('opportunitySearch').addEventListener('input', function () {
                        const query = this.value.toLowerCase().trim();
                        const rows = document.querySelectorAll('#opportunityTable .opportunity-row');
                        const noResult = document.getElementById('noOpportunityResults');

                        let visibleCount = 0;

                        rows.forEach(row => {
                            const title = row.cells[0].textContent.toLowerCase();
                            const company = row.cells[1].textContent.toLowerCase();
                            const skills = row.cells[2].textContent.toLowerCase();

                            const matches = title.includes(query) || company.includes(query) || skills.includes(query);

                            row.style.display = matches ? '' : 'none';
                            if (matches) visibleCount++;
                        });

                        noResult.style.display = visibleCount === 0 ? '' : 'none';
                    });

                    //FILTERING OPPORTUNITIES
                    document.querySelectorAll('.type-filter').forEach(item => {
                        item.addEventListener('click', function (e) {
                            e.preventDefault();
                            const filter = this.dataset.filter;
                            const rows = document.querySelectorAll('#opportunityTable .opportunity-row');
                            const noResult = document.getElementById('noOpportunityResults');

                            let visibleCount = 0;

                            rows.forEach(row => {
                                const type = row.dataset.type;
                                const moa = row.dataset.moa;

                                let show = false;

                                if (filter === 'all') {
                                    show = true;
                                } else if (filter === 'moa') {
                                    show = moa === 'yes';
                                } else {
                                    show = type === filter;
                                }

                                row.style.display = show ? '' : 'none';
                                if (show) visibleCount++;
                            });

                            noResult.style.display = visibleCount === 0 ? '' : 'none';
                        });
                    });
                    
                </script>
            
            <!-- Create Opportunity Modal -->
            <div class="modal fade" id="createOpportunityModal" tabindex="-1" aria-labelledby="createOpportunityModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable">
                    <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createOpportunityModalLabel">Create Job Opportunity</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <form action="create_opportunities" method="POST">
                        <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                        <p class="text-muted">Fill in the details to create a new job opportunity</p>

                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" placeholder="Enter job title" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Company</label>
                            <input type="text" name="company" class="form-control" placeholder="Enter company name" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Employment Type</label>
                            <select name="type" class="form-select" required>
                            <option value="Job">Job</option>
                            <option value="Internship">Internship</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Enter job description"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Skills required</label>
                            <textarea name="skills" class="form-control" rows="2" placeholder="Enter job requirements"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Application Link (optional)</label>
                            <input type="url" name="application_link" class="form-control" placeholder="https://example.com/apply">
                        </div>


                        <div class="mb-3">
                            <label class="form-label">Application Deadline (optional)</label>
                            <input type="datetime-local" name="deadline" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">MOA Expiration (optional)</label>
                            <input type="date" name="moa_expiration" class="form-control">
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="moa_status" id="moaCheck">
                            <label class="form-check-label" for="moaCheck">Has Memorandum of Agreement</label>
                        </div>
                        </div>

                        <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Create</button>
                        </div>
                    </form>
                    </div>
                </div>
            </div>


            <!-- Delete Opportunity Modal -->
            <div class="modal fade" id="deleteOpportunityModal" tabindex="-1" aria-labelledby="deleteOpportunityModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                <form action="delete_opportunity" method="GET">
                    <input type="hidden" name="id" id="deleteOpportunityId">
                    <div class="modal-header">
                    <h5 class="modal-title" id="deleteOpportunityModalLabel">Delete Opportunity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                    Are you sure you want to delete this opportunity?
                    </div>
                    <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
                </div>
            </div>
            </div>

           <!-- Edit Opportunity Modal -->
            <div class="modal fade" id="editOpportunityModal" tabindex="-1" aria-labelledby="editOpportunityModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Job Opportunity</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <form id="editOpportunityForm" action="update_opportunity" method="POST">
                    <input type="hidden" name="id" id="editOpportunityId">

                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Company</label>
                        <input type="text" name="company" class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Employment Type</label>
                        <select name="type" class="form-select">
                        <option value="Job">Job</option>
                        <option value="Internship">Internship</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Skills Required</label>
                        <textarea name="skills" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Application Link</label>
                        <input type="url" name="application_link" class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Application Deadline</label>
                        <input type="date" name="deadline" class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">MOA Expiration</label>
                        <input type="date" name="moa_expiration" class="form-control">
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="moa_status" id="editMoACheckbox">
                        <label class="form-check-label" for="editMoACheckbox">Has Memorandum of Agreement</label>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="status" id="editActiveCheckbox">
                        <label class="form-check-label" for="editActiveCheckbox">Active</label>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Changes</button>
                    </div>
                     
                    </form>

                    </div>

                   
                </div>
            </div>

            

            


        </section>

        <!-- Partner Companies Section -->
        <section id="partner-companies" class="admin-section d-none">
           <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
                <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
                </button>
                <div class="text-end">
                <strong><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong><br />
                <small class="text-muted"><?= htmlspecialchars($admin['role']) ?></small>
                </div>
                <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center"
                    style="width: 32px; height: 32px;">
                <?= strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1)) ?>
                </div>
            </div>

            <!-- Partner Companies Section -->
            <h4 class="fw-bold text-center">Partner Companies</h4>
            <p class="text-center text-muted mb-4">Manage your list of partner companies and industry affiliations.</p>

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <input type="text" class="form-control flex-grow-1" placeholder="Search companies..." style="min-width: 250px;">
            <div class="d-flex gap-2">
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createCompanyModal">
                <i class="bi bi-plus-lg"></i> Add New
                </button>
            </div>
            </div>

            <div class="table-responsive shadow-sm">
            <table class="table table-bordered table-hover align-middle text-center mb-0">
                <thead class="table-light">
                <tr>
                    <th>Name <i class="bi bi-arrow-down-up ms-1"></i></th>
                    <th>Industry <i class="bi bi-arrow-down-up ms-1"></i></th>
                    <th>Description</th>
                    <th>Website</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td class="fw-semibold">TrendMicro</td>
                    <td><span class="badge bg-light border text-dark">IT</span></td>
                    <td>Company</td>
                    <td>N/A</td>
                    <td>
                    <a href="#" class="text-dark me-2" data-bs-toggle="modal" data-bs-target="#editCompanyModal" style="text-decoration: none;">
                    <i class="bi bi-pencil"></i>
                    </a>
                    <a href="#" class="text-danger"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                </tbody>
            </table>
            </div>
           

            <!-- Create Company Modal -->
            <div class="modal fade" id="createCompanyModal" tabindex="-1" aria-labelledby="createCompanyModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createCompanyModalLabel">Create Company</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Fill in the details to create a new company</p>
                    <form>
                    <div class="mb-3">
                        <label class="form-label">Company Name</label>
                        <input type="text" class="form-control" placeholder="Enter company name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Industry</label>
                        <input type="text" class="form-control" placeholder="Enter industry">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control" rows="3" placeholder="Enter company description"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Website <span class="text-muted">(optional)</span></label>
                        <input type="url" class="form-control" placeholder="https://example.com">
                    </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success">Create</button>
                </div>
                </div>
            </div>
            </div>

            <!-- Edit Company Modal -->
            <div class="modal fade" id="editCompanyModal" tabindex="-1" aria-labelledby="editCompanyModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editCompanyModalLabel">Edit Company</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Update the company details below</p>
                    <form>
                    <div class="mb-3">
                        <label class="form-label">Company Name</label>
                        <input type="text" class="form-control" value="TrendMicro">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Industry</label>
                        <input type="text" class="form-control" value="IT">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control" rows="3">Company</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Website <span class="text-muted">(optional)</span></label>
                        <input type="url" class="form-control" value="https://example.com">
                    </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success">Update</button>
                </div>
                </div>
            </div>
            </div>

        </section>

        <!-- University Analytics Section -->
        <section id="university-analytics" class="admin-section d-none">
         
            <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
                        <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                        <i class="bi bi-list"></i>
                        </button>
                        <div class="text-end">
                        <strong><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></strong><br />
                        <small class="text-muted"><?= htmlspecialchars($admin['role']) ?></small>
                        </div>
                        <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center"
                            style="width: 32px; height: 32px;">
                        <?= strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1)) ?>
                        </div>
            </div> 
            
            <div class="container py-4">
                <h4 class="mb-4">University Analytics Overview</h4>
                <div class="row g-4">
                    
                    <!-- Users by Role -->
                    <div class="col-md-6">
                    <div class="card p-3">
                        <h6 class="mb-2">Users by Role</h6>
                        <div style="height: 300px;">
                        <canvas id="roleChart"></canvas>
                        </div>
                    </div>
                    </div>

                    <!-- Top Programs -->
                    <div class="col-md-6">
                    <div class="card p-3">
                        <h6 class="mb-2">Top Programs</h6>
                        <div style="height: 300px;">
                        <canvas id="programChart"></canvas>
                        </div>
                    </div>
                    </div>

                    <!-- Most Common Skills -->
                    <div class="col-md-6">
                    <div class="card p-3">
                        <h6 class="mb-2">Most Common Skills</h6>
                        <div style="height: 300px;">
                        <canvas id="skillChart"></canvas>
                        </div>
                    </div>
                    </div>

                    <!-- Graduation Year -->
                    <div class="col-md-6">
                      <div class="card p-3">
                        <h6 class="mb-2">Graduation Year</h6>
                        <div style="height: 300px;">
                          <canvas id="gradYearChart"></canvas>
                        </div>
                      </div>
                    </div>
 

                    <div class="col-md-6">
                      <div class="card p-3">
                        <h6 class="mb-2">Employment Status</h6>
                        <div style="height: 300px;">
                          <canvas id="employmentStatusChart"></canvas>
                        </div>
                      </div>
                    </div>

                </div>
            </div>


           
            <!-- SCRPT FOR GRAPH -->
            <script>

                
                const roleLabels = <?= json_encode(array_column($roleDistribution, 'role')) ?>;
                const roleData = <?= json_encode(array_column($roleDistribution, 'count')) ?>;

                const programLabels = <?= json_encode(array_column($programStats, 'program')) ?>;
                const programData = <?= json_encode(array_column($programStats, 'count')) ?>;

                const skillLabels = <?= json_encode(array_keys($skillCount)) ?>;
                const skillData = <?= json_encode(array_values($skillCount)) ?>;

                const gradYearLabels = <?= json_encode($gradYearLabels) ?>;
                const gradYearData = <?= json_encode($gradYearData) ?>;

                const employmentMatchLabels = <?= json_encode($employmentMatchLabels) ?>;
                const employmentMatchData = <?= json_encode($employmentMatchData) ?>;

                const employmentStatusLabels = <?= json_encode($employmentStatusLabels) ?>;
                const employmentStatusData = <?= json_encode($employmentStatusData) ?>;

                const chartRegistry = {};

                const createChart = (id, type, labels, data, label) => {
                    const ctx = document.getElementById(id).getContext('2d');

                    if (chartRegistry[id]) {
                        chartRegistry[id].destroy();
                    }

                    chartRegistry[id] = new Chart(ctx, {
                        type,
                        data: {
                            labels,
                            datasets: [{
                                label,
                                data,
                                backgroundColor: [
                                    'rgba(75, 192, 192, 0.6)',
                                    'rgba(255, 99, 132, 0.6)',
                                    'rgba(255, 206, 86, 0.6)',
                                    'rgba(54, 162, 235, 0.6)',
                                    'rgba(153, 102, 255, 0.6)',
                                    'rgba(255, 159, 64, 0.6)'
                                ],
                                borderColor: 'rgba(0, 0, 0, 0.1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: type === 'bar' || type === 'line' ? {
                                y: { beginAtZero: true }
                            } : {}
                        }
                    });
                };

                createChart("roleChart", "pie", roleLabels, roleData, "User Roles");
                createChart("programChart", "bar", programLabels.slice(0, 10), programData.slice(0, 10), "Top Programs");
                createChart("skillChart", "bar", skillLabels.slice(0, 10), skillData.slice(0, 10), "Top Skills");
                createChart("gradYearChart", "bar", gradYearLabels, gradYearData, "Graduation Year")
                createChart("employmentStatusChart", "doughnut", employmentStatusLabels, employmentStatusData, "Employment Status");
            </script>

        </div>
        </section>
        <!-- Footer -->
            <footer class="text-center text-muted mt-5 py-4 border-top small">
                <div class="container">
                <p class="mb-1">© 2025 CareerLink NEU. All rights reserved.</p>
                <a href="#" class="text-muted text-decoration-none me-2">Terms of Service</a>
                <span class="text-muted">|</span>
                <a href="#" class="text-muted text-decoration-none ms-2">Privacy Policy</a>
                </div>
            </footer>
      </div>
    </main>

    
  </div>

  <script>
    function toggleSidebar() {
      document.getElementById("sidebar").classList.toggle("show");
    }

    // Section navigation
    document.querySelectorAll('.sidebar-link').forEach(link => {
      link.addEventListener('click', function (e) {
        e.preventDefault();

        // Toggle active class
        document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
        this.classList.add('active');

        // Show the correct section
        const targetId = this.getAttribute('data-target');
        document.querySelectorAll('.admin-section').forEach(section => {
          section.classList.add('d-none');
        });
        document.getElementById(targetId).classList.remove('d-none');

        // Close sidebar on mobile
        if (window.innerWidth < 768) {
          document.getElementById("sidebar").classList.remove("show");
        }
      });
    });
  </script>
</body>
</html>
