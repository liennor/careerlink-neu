<?php

session_start();
require_once 'db/db.php'; // Only if you will use the database in this file

// Prevent browser from caching this page
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");


// Restrict access to logged-in users only
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

$config = require __DIR__ . '/config.php';
$apiKey = $config['openai_api_key'];

$userId = $_SESSION['user_id'];

// Fetch user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userInfo) {
    die("User not found.");
}

// Extract user info
$firstName = $userInfo['first_name'];
$lastName = $userInfo['last_name'];
$role = $userInfo['role'];
$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
$displayName = ucfirst($firstName) . ' ' . ucfirst($lastName);

// Optional: store session data in variables
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$userName = $_SESSION['name'];

// Fetch Announcements and Opportunities counts
try {
    $stmtAnnouncements = $conn->query("SELECT COUNT(*) FROM announcements");
    $totalAnnouncements = $stmtAnnouncements->fetchColumn();

   $stmtOpportunities = $conn->query("SELECT COUNT(*) FROM opportunities WHERE status = 'active'");
  $totalOpportunities = $stmtOpportunities->fetchColumn();

} catch (PDOException $e) {
    $totalAnnouncements = 0;
    $totalOpportunities = 0;
    // Optional: Log or display error
}

//fectch announcements data
try {
    $stmt = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $announcements = [];
    $_SESSION['flash'] = "Error fetching announcements: " . $e->getMessage();
}

// Fetch Opportunities data
try {
    $stmt = $conn->prepare("SELECT * FROM opportunities WHERE status = 'Active' ORDER BY created_at DESC");
    $stmt->execute();
    $opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $opportunities = [];
    $_SESSION['flash'] = "Error fetching opportunities: " . $e->getMessage();
}


$userId = $_SESSION['user_id'];

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = null;
    $_SESSION['flash'] = "Failed to load profile: " . $e->getMessage();
}



// Get current resume info
$currentStmt = $conn->prepare("SELECT * FROM resume_info WHERE user_id = :user_id ORDER BY parsed_at DESC LIMIT 1");
$currentStmt->execute([':user_id' => $userId]);
$currentResume = $currentStmt->fetch(PDO::FETCH_ASSOC);

// Get resume upload history
$historyStmt = $conn->prepare("SELECT * FROM resume_history WHERE user_id = :user_id ORDER BY uploaded_at DESC");
$historyStmt->execute([':user_id' => $userId]);
$resumeHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

function isJobRecommendedForCourse($jobTitle, $jobDesc, $userCourse, $apiKey) {
    $prompt = "Is the following job suitable for someone with a course in \"$userCourse\"? Job title: \"$jobTitle\". Description: \"$jobDesc\". Answer only Yes or No.";
    $data = [
        "model" => "gpt-3.5-turbo",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ],
        "max_tokens" => 3,
        "temperature" => 0
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    if ($result === false) {
        error_log('OpenAI API error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $response = json_decode($result, true);
    if (!isset($response['choices'][0]['message']['content'])) {
        error_log('OpenAI API bad response: ' . $result);
        return false;
    }
    $answer = strtolower($response['choices'][0]['message']['content']);

    return strpos($answer, 'yes') !== false;
}

$userSkillsArr = array_map('trim', explode(',', $currentResume['skills'] ?? ''));
$userCourse = $currentResume['program'] ?? '';
$isStudent = (strtolower($role) === 'student'); // Adjust if your role naming differs

// Prepare an array with recommendation and type info

// Filter based on role before recommendation logic
$opportunities = array_filter($opportunities, function($opp) use ($role) {
    $type = strtolower($opp['type']);
    if (strtolower($role) === 'student' && $type === 'job') {
        return false; // Hide job for students
    }
    if (strtolower($role) === 'alumni' && $type === 'internship') {
        return false; // Hide internship for alumni
    }
    return true; // Show otherwise
});


$sortedOpportunities = [];
foreach ($opportunities as $opp) {
    $isRecommendedCourse = isJobRecommendedForCourse($opp['title'], $opp['description'], $userCourse, $apiKey);
    $oppSkillsArr = array_map('trim', explode(',', $opp['skills']));
    $isRecommendedSkills = false;
    foreach ($userSkillsArr as $skill) {
        if ($skill && in_array($skill, $oppSkillsArr)) {
            $isRecommendedSkills = true;
            break;
        }
    }
    $showRecommended = $isRecommendedCourse && $isRecommendedSkills;
    $isInternship = (strtolower($opp['type']) === 'internship');

    $sortedOpportunities[] = [
        'data' => $opp,
        'isRecommended' => $showRecommended,
        'isInternship' => $isInternship,
        'isRecommendedCourse' => $isRecommendedCourse,
        'isRecommendedSkills' => $isRecommendedSkills
    ];
}

// Sort: recommended first, then internships (if student), then others
usort($sortedOpportunities, function($a, $b) use ($isStudent) {
    // Recommended always first
    if ($a['isRecommended'] !== $b['isRecommended']) {
        return $a['isRecommended'] ? -1 : 1;
    }
    // If student, internships before jobs
    if ($isStudent && $a['isInternship'] !== $b['isInternship']) {
        return $a['isInternship'] ? -1 : 1;
    }
    // Otherwise, keep original order
    return 0;
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />

  <title>CareerLink NEU | Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="css/dashboard.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>


</head>
<body>

  <div class="d-flex">
    <!-- Sidebar -->
    <nav class="sidebar p-3" id="sidebar">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold text-white mb-0">CareerLink <span class="text-warning">NEU</span></h5>
        <button class="btn text-white d-md-none" onclick="toggleSidebar()"><i class="bi bi-x-lg"></i></button>
      </div>
      <a href="#" class="nav-link active" data-target="dashboardSection"><i class="bi bi-house-door"></i>Dashboard</a>
      <a href="#" class="nav-link" data-target="announcementsSection"><i class="bi bi-bell"></i>Announcements</a>
      <a href="#" class="nav-link" data-target="jobsSection"><i class="bi bi-briefcase"></i>Job Opportunities</a>
      <a href="#" class="nav-link" data-target="resumeSection"><i class="bi bi-upload"></i>Upload Resume</a>
      <a href="#" class="nav-link" data-target="profileSection"><i class="bi bi-person"></i>Profile</a>
      <div class="mt-auto pt-4">
        <a href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
          <i class="bi bi-box-arrow-right"></i> Logout
        </a>

       <!-- Logout Modal -->
      <div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
          <div class="modal-content">
            <div class="modal-body text-center">
              <p class="mb-3 text-dark">Are you sure you want to logout?</p>
              <div class="d-flex justify-content-center gap-2">
                <button class="btn btn-success btn-sm" data-bs-dismiss="modal">Cancel</button>
                <a href="login" class="btn btn-danger btn-sm">Logout</a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- script for reload the current section -->
      <script>
          // // Show the previously selected section on load
          window.addEventListener('DOMContentLoaded', function () {
            const savedSection = sessionStorage.getItem('activeSection') || 'dashboardSection';
            showSection(savedSection);
          });

          // Save clicked section and show it
          document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function (e) {
              e.preventDefault();
              const target = this.getAttribute('data-target');
              sessionStorage.setItem('activeSection', target);
              showSection(target);
            });
          });

          function showSection(sectionId) {
            // Hide all
            document.querySelectorAll('section.page-section').forEach(sec => sec.style.display = 'none');
            // Show selected
            document.getElementById(sectionId).style.display = 'block';
          }
      </script>

    </nav>

    <!-- Main Content -->
    <main class="flex-grow-1">
      <div class="container-fluid">
        

        <!-- Dashboard Section -->
        <section id="dashboardSection" class="page-section active">
          <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
            <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
              <i class="bi bi-list"></i>
            </button>
            <div class="text-end">
              <strong><?= htmlspecialchars($displayName) ?></strong><br />
              <small class="text-muted"><?= htmlspecialchars($role) ?></small>
            </div>
            <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
              <?= htmlspecialchars($initials) ?>
            </div>
          </div>

          <div class="row g-3 px-3">
            <div class="col-md-6">
              <div class="card info-card shadow-sm">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <h6 class="fw-bold">Total Announcements</h6>
                      <h5><?= $totalAnnouncements ?></h5>
                      <!-- <small class="text-muted">+3 in the last month</small> -->
                    </div>
                    <i class="bi bi-bell-fill text-primary"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card info-card shadow-sm">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <h6 class="fw-bold">Total Opportunities</h6>
                      <h5><?= $totalOpportunities ?></h5>
                      <!-- <small class="text-muted"></small> -->
                    </div>
                    <i class="bi bi-bag-check-fill text-success"></i>
                  </div>
                </div>
              </div>
            </div>
            
          </div>

          <div class="row g-3 mt-4 px-3">
            <div class="col-lg-6">
              <h6 class="fw-bold">Recent Announcements</h6>
              <?php
                $recentAnnouncements = array_slice($announcements, 0, 2);
                if (empty($recentAnnouncements)):
              ?>
                <div class="card shadow-sm mb-3">
                  <div class="card-body">
                    <p class="text-muted mb-0">No announcements available at the moment.</p>
                  </div>
                </div>
              <?php else: ?>
                <?php foreach ($recentAnnouncements as $announcement): ?>
                  <div class="card shadow-sm mb-3">
                    <div class="card-body">
                      <?php if (!empty($announcement['type'])): ?>
                        <small class="badge bg-success"><?= htmlspecialchars($announcement['type']) ?></small>
                      <?php endif; ?>
                      <h6 class="fw-bold mt-2 mb-0"><?= htmlspecialchars($announcement['title']) ?></h6>
                      <p class="text-muted small"><?= htmlspecialchars($announcement['content']) ?></p>
                      <small class="text-muted">
                        Posted: <?= date('n/j/Y', strtotime($announcement['created_at'])) ?>
                        <?php if (!empty($announcement['expiry_date'])): ?>
                          | Expires: <?= date('n/j/Y', strtotime($announcement['expiry_date'])) ?>
                        <?php endif; ?>
                      </small>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div class="col-lg-6">
              <h6 class="fw-bold">Recommended Opportunities</h6>
              <?php
                $recommended = array_filter($sortedOpportunities, fn($item) => $item['isRecommended']);
                $recommended = array_slice($recommended, 0, 2);
                if (empty($recommended)):
              ?>
                <div class="card shadow-sm mb-3">
                  <div class="card-body">
                    <p class="text-muted mb-0">No recommended opportunities at the moment.</p>
                  </div>
                </div>
              <?php else: ?>
                <?php foreach ($recommended as $item): 
                  $opp = $item['data'];
                ?>
                  <div class="card shadow-sm mb-3">
                    <div class="card-body">
                      <h6 class="fw-bold"><?= htmlspecialchars($opp['title']) ?></h6>
                      <small class="text-muted"><?= htmlspecialchars($opp['company']) ?><?= !empty($opp['location']) ? ', ' . htmlspecialchars($opp['location']) : '' ?></small>
                      <p class="mt-2 mb-1"><?= htmlspecialchars($opp['description']) ?></p>
                      <?php if (!empty($opp['skills'])): ?>
                        <?php foreach (explode(',', $opp['skills']) as $skill): ?>
                          <span class="badge text-bg-light border"><?= htmlspecialchars(trim($skill)) ?></span>
                        <?php endforeach; ?>
                      <?php endif; ?>
                      <div class="d-flex justify-content-between align-items-center mt-3">
                        <small class="text-muted">Posted: <?= date('n/j/Y', strtotime($opp['created_at'])) ?></small>
                        <?php if (!empty($opp['application_link'])): ?>
                          <a href="<?= htmlspecialchars($opp['application_link']) ?>" target="_blank" class="btn btn-sm btn-outline-success">View Details</a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <!-- Announcements Section -->
        <section id="announcementsSection" class="page-section">

          <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
            <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
              <i class="bi bi-list"></i>
            </button>
           <div class="text-end">
              <strong><?= htmlspecialchars($displayName) ?></strong><br />
              <small class="text-muted"><?= htmlspecialchars($role) ?></small>
            </div>
            <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
              <?= htmlspecialchars($initials) ?>
            </div>
          </div>

          <h4 class="fw-bold text-center">Announcements</h4>
          <p class="text-center text-muted">Stay updated with the latest university announcements and opportunities.</p>

          <div class="d-flex justify-content-center gap-2 mb-3 flex-wrap">
            <input type="text" id="announcementSearchInput" class="form-control w-auto" placeholder="Search announcements..." style="min-width: 250px;" oninput="filterAnnouncements()">
            <!-- Funnel button removed -->
          </div>

          <h5 class="fw-bold mt-4 mb-3">Latest Announcements</h5>
          <div id="announcementList" class="row row-cols-1 row-cols-md-2 g-3">
              <?php if (empty($announcements)): ?>
                  <p class="text-muted">No announcements available at the moment.</p>
              <?php else: ?>
                  <?php foreach ($announcements as $announcement): ?>
                      <div class="col announcement-card">
                          <div class="card shadow-sm h-100">
                              <div class="card-body">
                                  <h6 class="card-title fw-bold mb-1"><?= htmlspecialchars($announcement['title']) ?></h6>
                                  <p class="small text-muted mb-2">
                                      <?= date('F j, Y g:i A', strtotime($announcement['created_at'])) ?> 
                                      • Posted by <?= htmlspecialchars($announcement['posted_by']) ?>
                                  </p>
                                  <?php if (!empty($announcement['location'])): ?>
                                      <p class="mb-1"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($announcement['location']) ?></p>
                                  <?php endif; ?>
                                  <?php if (!empty($announcement['event_time'])): ?>
                                      <p class="mb-1"><i class="bi bi-calendar-event"></i> <?= date('F j, Y g:i A', strtotime($announcement['event_time'])) ?></p>
                                  <?php endif; ?>
                                  <p><?= nl2br(htmlspecialchars($announcement['content'])) ?></p>
                              </div>
                              <?php if (!empty($announcement['expiry_date'])): ?>
                                  <div class="card-footer text-end small text-muted">
                                      Expires on <?= date('F j, Y', strtotime($announcement['expiry_date'])) ?>
                                  </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  <?php endforeach; ?>
              <?php endif; ?>
          </div>

        </section>

        <!-- Job Opportunities -->
        <section id="jobsSection" class="page-section">

          <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
            <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
              <i class="bi bi-list"></i>
            </button>
            <div class="text-end">
              <strong><?= htmlspecialchars($displayName) ?></strong><br />
              <small class="text-muted"><?= htmlspecialchars($role) ?></small>
            </div>
            <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
              <?= htmlspecialchars($initials) ?>
            </div>
          </div>
          <h4 class="fw-bold text-center">Job Opportunities</h4>
          <p class="text-center text-muted">Explore the latest job and internship opportunities from our partner companies.</p>

          <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
            <button id="allOpportunitiesBtn" class="btn btn-success">All Opportunities</button>
            <button id="recommendedSkillsBtn" class="btn btn-outline-secondary"><i class="bi bi-stars me-1"></i>Recommended for Your Skills</button>
            <button id="recommendedCourseBtn" class="btn btn-outline-secondary"><i class="bi bi-book me-1"></i>Recommended for Your Program of Study</button>
          </div>

          <div class="row mb-4">
            <div class="col-md-3 mb-3">
              <input type="text" id="opportunitySearchInput" class="form-control" placeholder="Search opportunities...">
            </div>
            <div class="col-md-3 mb-3">
              <select id="opportunityTypeSelect" class="form-select">
                <option value="">All Types</option>
                <option value="Internship">Internship</option>
                <option value="Job">Job</option>
              </select>
            </div>
            <div class="col-md-3 mb-3">
              <input type="text" id="opportunitySkillsInput" class="form-control" placeholder="Search skills...">
              <div class="small text-muted mt-1">No skills selected</div>
            </div>
          </div>

          <h5 class="fw-bold mt-4 mb-3">Active Job & Internship Opportunities</h5>

          <?php
          $userSkillsArr = array_map('trim', explode(',', $currentResume['skills'] ?? ''));
          $userCourse = $currentResume['program'] ?? '';
          ?>
          <?php if (empty($opportunities)): ?>
            <p class="text-muted">No active opportunities available at the moment.</p>
          <?php else: ?>
            <?php foreach ($sortedOpportunities as $item): ?>
              <?php
                $opp = $item['data'];
                $isRecommendedCourse = $item['isRecommendedCourse'];
                $isRecommendedSkills = $item['isRecommendedSkills'];
                $showRecommended = $item['isRecommended'];
              ?>
              <div class="card shadow-sm mb-3 opportunity-card
                  <?= $showRecommended ? 'recommended-highlight' : '' ?>"
                  data-title="<?= htmlspecialchars(strtolower($opp['title'] . ' ' . $opp['company'] . ' ' . ($opp['location'] ?? ''))) ?>"
                  data-type="<?= htmlspecialchars(strtolower($opp['type'] === 'Full-time' ? 'Job' : $opp['type'])) ?>"
                  data-skills="<?= htmlspecialchars(strtolower($opp['skills'])) ?>"
                  data-recommended-course="<?= $isRecommendedCourse ? 'yes' : 'no' ?>"
                  data-recommended-skills="<?= $isRecommendedSkills ? 'yes' : 'no' ?>">
                <div class="card-body position-relative">
                  <?php if ($showRecommended): ?>
                    <div class="recommended-badge position-absolute top-0 end-0 m-2">
                      <span class="badge bg-success text-white px-3 py-2 d-flex align-items-center" style="font-size:1rem;">
                        <i class="bi bi-check-circle-fill me-1" style="font-size:1.2rem;"></i> RECOMMENDED
                      </span>
                    </div>
                  <?php endif; ?>
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <h5 class="fw-bold mb-1"><?= htmlspecialchars($opp['title']) ?></h5>
                      <small class="text-muted">
                        <i class="bi bi-building"></i> <?= htmlspecialchars($opp['company']) ?>
                        <?= !empty($opp['location']) ? ' • ' . htmlspecialchars($opp['location']) : '' ?>
                      </small>
                    </div>
                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($opp['type']) ?></span>
                  </div>

                  <p class="mt-2 mb-1"><?= nl2br(htmlspecialchars($opp['description'])) ?></p>

                  <div class="mb-2">
                    <small class="text-muted">Required Skills:</small><br>
                    <?php foreach (explode(',', $opp['skills']) as $skill): ?>
                      <span class="badge bg-light text-dark border"><?= htmlspecialchars(trim($skill)) ?></span>
                    <?php endforeach; ?>
                  </div>

                  <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Posted: <?= date('F j, Y', strtotime($opp['created_at'])) ?></small>
                    <?php if (!empty($opp['application_link'])): ?>
                      <a href="<?= htmlspecialchars($opp['application_link']) ?>" target="_blank" class="btn btn-success btn-sm">View Details</a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>
          
        <!-- Upload Resume Section -->
        <section id="resumeSection" class="page-section">
            <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
              <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
              </button>
              <div class="text-end">
                <strong><?= htmlspecialchars($displayName) ?></strong><br />
                <small class="text-muted"><?= htmlspecialchars($role) ?></small>
              </div>
              <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                <?= htmlspecialchars($initials) ?>
              </div>
            </div>

            <!-- Resume Section -->
            <div class="container mt-4 p-3 rounded">
              <!-- Tabs -->
              <ul class="nav nav-tabs mb-4" id="resumeTabs">
                <li class="nav-item">
                  <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#currentResume">Current Resume</button>
                </li>
                <li class="nav-item">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#resumeHistory">Resume History</button>
                </li>
              </ul>

              <!-- Tab Content -->
              <div class="tab-content">
                <!-- Current Resume -->
                <div class="tab-pane fade show active" id="currentResume">
                  <div class="row g-4">
                    <!-- Left Column: File Info -->
                    <div class="col-md-6">
                      <div class="card border-0 shadow-sm p-3">
                        <h5 class="fw-bold">Current Resume</h5>
                        <p><strong>File Name:</strong> <?= htmlspecialchars($currentResume['pdf_file'] ?? 'No file uploaded') ?></p>
                        <p><strong>Upload Date:</strong> <?= isset($currentResume['parsed_at']) ? date('F j, Y', strtotime($currentResume['parsed_at'])) : '—' ?></p>

                        <!-- Upload Interaction -->
                        <div class="d-flex flex-column flex-sm-row align-items-start gap-2 mt-2">
                          <button class="btn btn-success" onclick="document.getElementById('resumeUploadInput').click();">
                            <i class="bi bi-upload"></i> Upload
                          </button>

                          <button id="scanNowBtn" class="btn btn-primary" style="display: none;" onclick="scanResume()">
                            <i class="bi bi-search"></i> Scan Now
                          </button>
                        </div>

                        <!-- File name preview -->
                        <p id="selectedResumeName" class="text-muted small mt-2 mb-0">No file selected</p>

                        <!-- Hidden file inputs -->
                        <input type="file" id="resumeUploadInput" accept=".pdf,.png,.jpg,.jpeg" style="display: none;" onchange="previewSelectedResume(this)">
                        <input type="file" id="resumeReplaceInput" accept=".pdf,.png,.jpg,.jpeg" style="display: none;" onchange="handleResumeReplace(this.files[0])">
                      </div>
                      <!-- Add this container where you want the message to appear -->
                    <div id="uploadStatusMessage" class="text-center mt-2 fw-bold text-danger"></div>
                    
                    <!-- LOADING MESSAGE  -->
                     <div id="uploadLoading" class="text-center mt-2" style="display: none;">
                        <div class="spinner-border text-primary" role="status" style="width: 2rem; height: 2rem;">
                          <span class="visually-hidden">Analyzing...</span>
                        </div>
                       <p class="mt-2 mb-0 text-muted small px-3 py-2 rounded bg-white border shadow-sm">
                          Scanning and analyzing your resume. This may take a few moments. Thank you for your patience.
                       </p>

                      </div>                   
                    </div>  


                    <!-- for resume script -->

                    <script>
                        let selectedResumeFile = null;

                        function previewSelectedResume(input) {
                            if (input.files && input.files[0]) {
                                selectedResumeFile = input.files[0];
                                document.getElementById('selectedResumeName').textContent = "Selected File: " + selectedResumeFile.name;
                                document.getElementById('scanNowBtn').style.display = "inline-block";
                                document.getElementById('uploadStatusMessage').textContent = ""; // Clear old messages
                            }
                        }

                        function scanResume() {
                        const messageContainer = document.getElementById('uploadStatusMessage');
                        const loadingSpinner = document.getElementById('uploadLoading');

                        if (!selectedResumeFile) {
                            messageContainer.textContent = "No file selected.";
                            messageContainer.classList.add("text-danger");
                            return;
                        }

                        const formData = new FormData();
                        formData.append("resume_file", selectedResumeFile);

                        messageContainer.textContent = "";
                        loadingSpinner.style.display = "block"; // ✅ Show spinner

                        fetch("upload_resume.php", {
                            method: "POST",
                            body: formData
                        })
                        .then(res => res.text())
                        .then(data => {
                            loadingSpinner.style.display = "none"; // ✅ Hide spinner
                            messageContainer.textContent = data;
                            messageContainer.classList.remove("text-danger");
                            messageContainer.classList.add("text-success");
                            setTimeout(() => location.reload(), 3000);
                        })
                        .catch(err => {
                            loadingSpinner.style.display = "none"; // ✅ Hide spinner
                            console.error(err);
                            messageContainer.textContent = "Upload failed.";
                            messageContainer.classList.add("text-danger");
                        });
                      }

                    </script>


                    <!-- Right Column: Extracted Info -->
                    <div class="col-md-6">
                      <div class="card border-0 shadow-sm p-3">
                        <h5 class="fw-bold">Extracted Information</h5>
                      <p><strong>Experience:</strong> <?= htmlspecialchars($currentResume['experience'] ?? '—') ?></p>
                      <p><strong>Phone number:</strong> <?= htmlspecialchars($currentResume['phone'] ?? '—') ?></p>
                      <p><strong>Home Address:</strong><?= htmlspecialchars($currentResume['home_address'] ?? '—') ?></p>
                      <!-- <p><strong>Program of Study:</strong> <?= htmlspecialchars($currentResume['program'] ?? '—') ?></p> -->
                      <p><strong>Skills:</strong> <?= htmlspecialchars($currentResume['skills'] ?? '—') ?></p>
                      <p><strong>Education:</strong> <?= htmlspecialchars($currentResume['education'] ?? '—') ?></p>                     
                      </div>
                    </div>
                  </div>
                </div>

               
                <!-- Resume History -->
                <div class="tab-pane fade" id="resumeHistory">
                  <div class="pt-3">
                    <h5 class="fw-bold">Resume Upload History</h5>
                    <?php if (isset($_SESSION['flash_success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                          <?= $_SESSION['flash_success'] ?>
                          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['flash_success']); ?>
                    <?php endif; ?>
                     <button type="button" class="btn btn-outline-danger btn-sm mb-3" data-bs-toggle="modal" data-bs-target="#deleteAllResumeModal">
                        <i class="bi bi-trash-fill"></i> Delete All History
                      </button>
                    
                    <div class="row g-4">
                     
                      <?php foreach ($resumeHistory as $index => $history): ?>
                        <div class="col-12 col-md-6 col-lg-5">
                          <div class="card shadow-sm p-3 h-100">
                            <h6 class="fw-bold"><?= htmlspecialchars($history['original_filename']) ?></h6>
                            <p class="text-muted small mb-2">Uploaded: <?= date('F j, Y', strtotime($history['uploaded_at'])) ?></p>

                            <div class="d-flex flex-column flex-sm-row gap-2">
                              <a href="uploads/resumes/<?= htmlspecialchars($history['pdf_file']) ?>" target="_blank" class="btn btn-outline-primary btn-sm w-100">
                                <i class="bi bi-eye"></i> View File
                              </a>

                              <button type="button" class="btn btn-secondary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#resumeModal<?= $index ?>">
                                <i class="bi bi-info-circle"></i> View More
                              </button>
                              <button type="button"
                                    class="btn btn-danger btn-sm w-100 open-delete-resume-modal"
                                    data-bs-toggle="modal"
                                    data-bs-target="#deleteResumeModal"
                                    data-id="<?= $history['id'] ?>">
                              <i class="bi bi-trash"></i> Delete
                            </button>
                            
                            </div>
                          </div>
                        </div>

                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>

                <!-- FOR RESUME DELETION SCRIPT -->
                <script>
                  document.querySelectorAll('.open-delete-resume-modal').forEach(button => {
                    button.addEventListener('click', () => {
                      const id = button.dataset.id;
                      document.getElementById('deleteResumeId').value = id;
                    });
                  });
                </script>
                 <!-- Delete Resume Modal -->
                <div class="modal fade" id="deleteResumeModal" tabindex="-1" aria-labelledby="deleteResumeModalLabel" aria-hidden="true">
                  <div class="modal-dialog">
                    <form method="POST" action="delete_resume_history.php">
                      <div class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title">Confirm Delete</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                          Are you sure you want to delete this resume file?
                          <input type="hidden" name="history_id" id="deleteResumeId">
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" class="btn btn-danger">Delete</button>
                        </div>
                      </div>
                    </form>
                  </div>
                </div>

                <!-- Delete All Resume Modal -->
                <div class="modal fade" id="deleteAllResumeModal" tabindex="-1" aria-labelledby="deleteAllResumeModalLabel" aria-hidden="true">
                  <div class="modal-dialog">
                    <form method="POST" action="delete_all_resume_history.php">
                      <div class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title">Delete All Resume History</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                          Are you sure you want to delete <strong>all resume history</strong>? This action cannot be undone.
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" class="btn btn-danger">Delete All</button>
                        </div>
                      </div>
                    </form>
                  </div>
                </div>




               <!-- Resume view more modal -->
               <?php foreach ($resumeHistory as $index => $history): ?>
                  <div class="modal fade" id="resumeModal<?= $index ?>" tabindex="-1" aria-labelledby="resumeModalLabel<?= $index ?>" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
                      <div class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title" id="resumeModalLabel<?= $index ?>">Resume Details: <?= htmlspecialchars($history['original_filename']) ?></h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                          <p><strong>Phone:</strong> <?= htmlspecialchars($history['phone'] ?? '—') ?></p>
                          <p><strong>Program:</strong> <?= htmlspecialchars($history['program'] ?? '—') ?></p>
                          <p><strong>Skills:</strong> <?= htmlspecialchars($history['skills'] ?? '—') ?></p>

                          <p><strong>Experience:</strong><br>
                          <?php
                          $expItems = json_decode($history['experience'], true);
                          if (is_array($expItems)) {
                              echo '<ul>';
                              foreach ($expItems as $item) {
                                  echo '<li>' . htmlspecialchars(is_array($item) ? implode(', ', $item) : $item) . '</li>';
                              }
                              echo '</ul>';
                          } elseif (!empty($history['experience'])) {
                              echo '<p>' . htmlspecialchars($history['experience']) . '</p>';
                          } else {
                              echo '—';
                          }
                          ?>
                          </p>

                          <p><strong>Education:</strong><br>
                          <?php
                          $eduItems = json_decode($history['education'], true);
                          if (is_array($eduItems)) {
                              echo '<ul>';
                              foreach ($eduItems as $edu) {
                                  echo '<li>' . htmlspecialchars(is_array($edu) ? implode(', ', $edu) : $edu) . '</li>';
                              }
                              echo '</ul>';
                          } elseif (!empty($history['education'])) {
                              echo '<p>' . htmlspecialchars($history['education']) . '</p>';
                          } else {
                              echo '—';
                          }
                          ?>
                          </p>

                          <!-- <p><strong>Raw JSON Output:</strong></p>
                          <pre class="bg-light p-2 border rounded small text-wrap text-break"><?= htmlspecialchars($history['parsed_json']) ?></pre> -->
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>



              </div>
            </div>
        </section>

        <!-- Profile Section -->
        <section id="profileSection" class="page-section">
            <div class="d-flex justify-content-between align-items-center dashboard-header px-3">
              <button class="btn btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
              </button>
              <div class="text-end">
                <strong><?= htmlspecialchars($displayName) ?></strong><br />
                <small class="text-muted"><?= htmlspecialchars($role) ?></small>
              </div>
              <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                <?= htmlspecialchars($initials) ?>
              </div>
            </div>

            <!-- Cover and Avatar -->
            <div class="card shadow-sm mb-4">
              <div class="bg-success" style="height: 120px;"></div>
              <div class="card-body position-relative">
                <div class="position-absolute top-0 translate-middle-y" style="left: 1.5rem;">
                  <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width: 70px; height: 70px; font-size: 1.25rem; border: 3px solid white;">
                    <?= htmlspecialchars($initials) ?>
                  </div>
                  <!-- <button class="btn btn-sm btn-light position-absolute bottom-0 end-0 rounded-circle" style="transform: translate(25%, 25%);">
                    <i class="bi bi-camera"></i>
                  </button> -->
                </div>
                <!-- <div class="text-end">
                  <button id="editToggle" class="btn btn-outline-dark btn-sm"><i class="bi bi-pencil"></i> Edit Profile</button>
                  <button id="cancelEdit" class="btn btn-outline-secondary btn-sm d-none">Cancel</button>
                </div> -->
                <div class="ps-5 mt-3">
                  <h5 class="fw-bold mb-0"><?= htmlspecialchars($displayName) ?></h5>
                  <small class="text-muted">Bachelor of Science in Computer Science</small>
                </div>
              </div>
            </div>

            <!-- Tabs -->
            <div class="text-center mb-3">
              <div class="btn-group">
                <button class="btn btn-outline-secondary btn-sm active" onclick="showTab('personal')">Personal Info</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="showTab('academic')">Academic</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="showTab('skills')">Skills</button>
              </div>
            </div>

            <!-- Content Panels -->
            <div id="tab-personal" class="profile-tab">
              <div class="card shadow-sm">
                <div class="card-body">
                  <h6 class="fw-bold text-center">Personal Information</h6>
                  <p class="text-center text-muted small">Your contact and personal details.</p>
                  <div class="row mb-3">
                    <div class="col-md-6">
                      <p class="mb-1"><i class="bi bi-person"></i> <strong>Full Name</strong></p>
                      <p><?= htmlspecialchars($displayName) ?></p>
                    </div>
                    <div class="col-md-6">
                      <p class="mb-1"><i class="bi bi-envelope"></i> <strong>Email</strong></p>
                      <p><?= htmlspecialchars($user['email']) ?></p>
                    </div>
                  </div>
                  <div class="row mb-3">
                    <div class="col-md-6">
                      <p class="mb-1"><i class="bi bi-telephone"></i> <strong>Student number</strong></p>
                      <p><?= $user['student_number'] ? htmlspecialchars($user['student_number']) : '<span class="text-muted">Not provided</span>' ?></p>
                    </div>

                    <div class="col-md-6">
                      <p class="mb-1"><i class="bi bi-geo-alt"></i> <strong>TOR number</strong></p>
                      <p><?= $user['tor_number'] ? htmlspecialchars($user['tor_number']) : '<span class="text-muted">Not provided</span>' ?></p>
                    </div>

                  </div>
                </div>
              </div>
            </div>

            <div id="tab-academic" class="profile-tab d-none">
              <div class="card shadow-sm">
                <div class="card-body">
                  <h6 class="fw-bold text-center">Academic Information</h6>
                  <p class="text-center text-muted small">Your educational background and academic details.</p>
                  <!-- <div class="mb-2">
                    <p class="mb-1"><i class="bi bi-mortarboard"></i> <strong>Program of Study</strong></p>
                    <p><?= htmlspecialchars($currentResume['program'] ?? '—') ?></p>
                  </div> -->
                  <div>
                    <p class="mb-1"><i class="bi bi-bar-chart"></i> <strong>Educational Background</strong></p>
                    <p><?= htmlspecialchars($currentResume['education'] ?? '—') ?></p>
                  </div>
                </div>
              </div>
            </div>


            <div id="tab-skills" class="profile-tab d-none">
              <div class="card shadow-sm">
                <div class="card-body">
                  <h6 class="fw-bold text-center"></h6>
                  <p class="text-center text-muted small">Your technical and professional skills.</p>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                  <?php
                    $skills = isset($currentResume['skills']) ? explode(',', $currentResume['skills']) : [];
                    if (!empty($skills) && trim($currentResume['skills']) !== ''):
                      foreach ($skills as $skill):
                  ?>
                    <span class="badge text-bg-light"><?= htmlspecialchars(trim($skill)) ?></span>
                  <?php
                      endforeach;
                    else:
                  ?>
                    <span class="badge text-bg-light">—</span>
                  <?php endif; ?>
                </div>
                </div>
              </div>
            </div>
            
            <!-- Editable Personal Info (Hidden by default) -->
            <div id="edit-personal" class="d-none">
              <div class="card shadow-sm">
                <div class="card-body">
                  <h6 class="fw-bold text-center">Personal Information</h6>
                  <p class="text-center text-muted small">Your contact and personal details.</p>
                  <div class="row mb-3">
                    <div class="col-md-6">
                      <label class="form-label">First Name</label>
                      <input type="text" class="form-control" value="John">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Last Name</label>
                      <input type="text" class="form-control" value="Doe">
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" value="john.doe@neu.edu.ph">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="text" class="form-control" value="+63 912 345 6789">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Address</label>
                    <input type="text" class="form-control" value="123 Main St. Quezon City">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Bio</label>
                    <textarea class="form-control" rows="3">Computer Science student at New Era University with a passion for web development and data science.</textarea>
                  </div>
                  <div class="text-end">
                    <button class="btn btn-success btn-sm"><i class="bi bi-save"></i> Save Changes</button>
                  </div>
                </div>
              </div>
            </div>


            <!-- Edit Academic -->
            <div id="edit-academic" class="d-none">
              <div class="card shadow-sm">
                <div class="card-body">
                  <h6 class="fw-bold text-center">Academic Information</h6>
                  <p class="text-center text-muted small">Your educational background and academic details.</p>
                  <div class="mb-3">
                    <label class="form-label">Course/Program</label>
                    <input type="text" class="form-control" value="Bachelor of Science in Computer Science">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Year Level</label>
                    <input type="text" class="form-control" value="3rd Year">
                  </div>
                  <div class="text-end">
                    <button class="btn btn-success btn-sm"><i class="bi bi-save"></i> Save Changes</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Edit Skills -->
            <div id="edit-skills" class="d-none">
              <div class="card shadow-sm">
                <div class="card-body">
                  <h6 class="fw-bold text-center">Skills</h6>
                  <p class="text-center text-muted small">Your technical and professional skills.</p>
                  <div class="mb-3 text-center">
                    <label class="form-label d-block">Add a skill</label>
                    <div class="input-group">
                      <input type="text" class="form-control" placeholder="Enter a skill">
                      <button class="btn btn-success">Add</button>
                    </div>
                  </div>
                  <div class="text-center mb-3">
                    <label class="form-label d-block">Your skills</label>
                    <span class="badge bg-light text-dark me-1">JavaScript <i class="bi bi-x small"></i></span>
                    <span class="badge bg-light text-dark me-1">React <i class="bi bi-x small"></i></span>
                    <span class="badge bg-light text-dark me-1">HTML/CSS <i class="bi bi-x small"></i></span>
                    <span class="badge bg-light text-dark me-1">Python <i class="bi bi-x small"></i></span>
                    <span class="badge bg-light text-dark me-1">SQL <i class="bi bi-x small"></i></span>
                  </div>
                  <div class="text-end">
                    <button class="btn btn-success btn-sm"><i class="bi bi-save"></i> Save Changes</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Tab Script and edit profile -->
            <script>
              const tabContents = {
                personal: {
                  view: document.getElementById('tab-personal'),
                  edit: document.getElementById('edit-personal')
                },
                academic: {
                  view: document.getElementById('tab-academic'),
                  edit: document.getElementById('edit-academic')
                },
                skills: {
                  view: document.getElementById('tab-skills'),
                  edit: document.getElementById('edit-skills')
                }
              };

              let currentTab = 'personal'; // default tab

              function showTab(tab) {
                currentTab = tab;
                Object.keys(tabContents).forEach(key => {
                  tabContents[key].view.classList.add('d-none');
                  tabContents[key].edit.classList.add('d-none');
                });
                tabContents[tab].view.classList.remove('d-none');

                // Update active class
                const buttons = document.querySelectorAll('.btn-group .btn');
                buttons.forEach(btn => {
                  btn.classList.remove('active');
                  if (btn.textContent.trim().toLowerCase().includes(tab)) {
                    btn.classList.add('active');
                  }
                });
              }

              const editToggle = document.getElementById('editToggle');
              const cancelEdit = document.getElementById('cancelEdit');

              editToggle.addEventListener('click', () => {
                editToggle.classList.add('d-none');
                cancelEdit.classList.remove('d-none');
                tabContents[currentTab].view.classList.add('d-none');
                tabContents[currentTab].edit.classList.remove('d-none');
              });

              cancelEdit.addEventListener('click', () => {
                editToggle.classList.remove('d-none');
                cancelEdit.classList.add('d-none');
                tabContents[currentTab].edit.classList.add('d-none');
                tabContents[currentTab].view.classList.remove('d-none');
              });
            </script>
            
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

      function showSectionById(sectionId) {
      document.querySelectorAll(".page-section").forEach(section => {
        section.classList.remove("active");
      });

      document.getElementById(sectionId)?.classList.add("active");

      document.querySelectorAll(".sidebar .nav-link").forEach(link => {
        link.classList.remove("active");
      });

      const activeLink = document.querySelector(`.sidebar .nav-link[data-target="${sectionId}"]`);
      if (activeLink) activeLink.classList.add("active");
    }

    // Handle section switching and save state
    document.querySelectorAll(".sidebar .nav-link").forEach(link => {
      link.addEventListener("click", e => {
        e.preventDefault();
        const targetId = link.getAttribute("data-target");
        sessionStorage.setItem("activeSection", targetId);
        showSectionById(targetId);

        if (window.innerWidth < 768) {
          document.getElementById("sidebar").classList.remove("show");
        }
      });
    });

    // On page load, restore last section or default to dashboard
    document.addEventListener("DOMContentLoaded", function () {
      const savedSection = sessionStorage.getItem("activeSection") || "dashboardSection";
      showSectionById(savedSection);
    });
  </script>

  <script>
    // Make filterAnnouncements globally available
    function filterAnnouncements() {
      const input = document.getElementById('announcementSearchInput').value.toLowerCase();
      const cols = document.querySelectorAll('#announcementList .col.announcement-card');
      let found = false;

      cols.forEach(col => {
        // Remove previous "no results" message if any
        if (col.id === 'noAnnouncementResult') {
          col.remove();
          return;
        }
        const text = col.textContent.toLowerCase();
        if (text.includes(input)) {
          col.style.display = '';
          found = true;
        } else {
          col.style.display = 'none';
        }
      });

      // Remove previous "no results" message if any (again, for safety)
      let noResult = document.getElementById('noAnnouncementResult');
      if (noResult) noResult.remove();

      // Show "No results found" if nothing matches
      if (!found) {
        const div = document.createElement('div');
        div.id = 'noAnnouncementResult';
        div.className = 'col-12 text-center text-muted py-4';
        div.textContent = 'No results found.';
        document.getElementById('announcementList').appendChild(div);
      }
    }

    // Enter key triggers filter
    document.addEventListener("DOMContentLoaded", function () {
      document.getElementById('announcementSearchInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          filterAnnouncements();
        }
      });
    });
  </script>

  <script>
    function filterOpportunities() {
      const search = document.getElementById('opportunitySearchInput').value.toLowerCase();
      const type = document.getElementById('opportunityTypeSelect').value.toLowerCase();
      const skill = document.getElementById('opportunitySkillsInput').value.toLowerCase();
      const cards = document.querySelectorAll('.opportunity-card');
      let found = false;

      cards.forEach(card => {
        // Get data attributes
        const title = card.getAttribute('data-title');
        const cardType = card.getAttribute('data-type');
        const cardSkills = card.getAttribute('data-skills');

        // Check filters
        const matchesSearch = !search || title.includes(search);
        const matchesType = !type || cardType === type;
        const matchesSkill = !skill || cardSkills.includes(skill);

        if (matchesSearch && matchesType && matchesSkill) {
          card.style.display = '';
          found = true;
        } else {
          card.style.display = 'none';
        }
      });

      // Show "No results found" if nothing matches
      let noResult = document.getElementById('noOpportunityResult');
      if (noResult) noResult.remove();
      if (!found) {
        const div = document.createElement('div');
        div.id = 'noOpportunityResult';
        div.className = 'col-12 text-center text-muted py-4';
        div.textContent = 'No results found.';
        // Insert after the last filter row
        const filterRow = document.querySelector('.row.mb-4');
        filterRow.parentNode.insertBefore(div, filterRow.nextSibling);
      }
    }

    // Attach events
    document.addEventListener("DOMContentLoaded", function () {
      document.getElementById('opportunitySearchInput').addEventListener('input', filterOpportunities);
      document.getElementById('opportunityTypeSelect').addEventListener('change', filterOpportunities);
      document.getElementById('opportunitySkillsInput').addEventListener('input', filterOpportunities);
    });
    </script>

    <script>
    document.getElementById('recommendedSkillsBtn').addEventListener('click', function() {
      setOpportunityBtnActive('recommendedSkillsBtn');
      // If user has no skills, show all jobs
      if (!userSkills.length || (userSkills.length === 1 && userSkills[0] === "")) {
        filterOpportunities(); // fallback to normal filter
        return;
      }

      const cards = document.querySelectorAll('.opportunity-card');
      let found = false;

      cards.forEach(card => {
        const cardSkills = card.getAttribute('data-skills') || '';
        // Check if any user skill matches the job's skills
        const matches = userSkills.some(skill =>
          cardSkills.toLowerCase().includes(skill.toLowerCase())
        );
        if (matches) {
          card.style.display = '';
          found = true;
        } else {
          card.style.display = 'none';
        }
      });

      // Remove previous "no results" message if any
      let noResult = document.getElementById('noOpportunityResult');
      if (noResult) noResult.remove();

      // Show "No results found" if nothing matches
      if (!found) {
        const div = document.createElement('div');
        div.id = 'noOpportunityResult';
        div.className = 'col-12 text-center text-muted py-4';
        div.textContent = 'No results found.';
        const filterRow = document.querySelector('.row.mb-4');
        filterRow.parentNode.insertBefore(div, filterRow.nextSibling);
      }
    });
  </script>

<script>
  document.querySelector('button.btn-outline-secondary i.bi-book').parentElement.addEventListener('click', function() {
    setOpportunityBtnActive('recommendedCourseBtn');
    const cards = document.querySelectorAll('.opportunity-card');
    let found = false;
    cards.forEach(card => {
      if (card.getAttribute('data-recommended-course') === 'yes') {
        card.style.display = '';
        found = true;
      } else {
        card.style.display = 'none';
      }
    });

    // Remove previous "no results" message if any
    let noResult = document.getElementById('noOpportunityResult');
    if (noResult) noResult.remove();

    // Show "No results found" if nothing matches
    if (!found) {
      const div = document.createElement('div');
      div.id = 'noOpportunityResult';
      div.className = 'col-12 text-center text-muted py-4';
      div.textContent = 'No results found.';
      const filterRow = document.querySelector('.row.mb-4');
      filterRow.parentNode.insertBefore(div, filterRow.nextSibling);
    }
  });
</script>

<script>
  document.getElementById('allOpportunitiesBtn').addEventListener('click', function() {
    setOpportunityBtnActive('allOpportunitiesBtn');
    // Reset all filters
    document.getElementById('opportunitySearchInput').value = '';
    document.getElementById('opportunityTypeSelect').value = '';
    document.getElementById('opportunitySkillsInput').value = '';
    // Show all cards
    document.querySelectorAll('.opportunity-card').forEach(card => {
      card.style.display = '';
    });
    // Remove "No results found" message if present
    let noResult = document.getElementById('noOpportunityResult');
    if (noResult) noResult.remove();
    // Show recommended badges
    toggleRecommendedBadges(true);
  });
</script>

<script>
  const userSkills = <?= json_encode(array_map('trim', $skills)) ?>;
</script>

<script>
  function setOpportunityBtnActive(activeBtnId) {
    // Button IDs
    const btns = [
      'allOpportunitiesBtn',
      'recommendedSkillsBtn',
      'recommendedCourseBtn'
    ];
    btns.forEach(id => {
      const btn = document.getElementById(id);
      if (!btn) return;
      btn.classList.remove('btn-success', 'btn-outline-secondary', 'active');
      if (id === activeBtnId) {
        btn.classList.add('btn-success', 'active');
        btn.classList.remove('btn-outline-secondary');
      } else {
        btn.classList.add('btn-outline-secondary');
        btn.classList.remove('btn-success');
      }
    });
  }

  document.getElementById('allOpportunitiesBtn').addEventListener('click', function() {
    setOpportunityBtnActive('allOpportunitiesBtn');
    document.getElementById('opportunitySearchInput').value = '';
    document.getElementById('opportunityTypeSelect').value = '';
    document.getElementById('opportunitySkillsInput').value = '';
    filterOpportunities();
  });

  document.getElementById('recommendedSkillsBtn').addEventListener('click', function() {
    setOpportunityBtnActive('recommendedSkillsBtn');
    // If user has no skills, show all jobs
    if (!userSkills.length || (userSkills.length === 1 && userSkills[0] === "")) {
      filterOpportunities(); // fallback to normal filter
      return;
    }
    const cards = document.querySelectorAll('.opportunity-card');
    let found = false;
    cards.forEach(card => {
      const cardSkills = card.getAttribute('data-skills') || '';
      const matches = userSkills.some(skill =>
        cardSkills.toLowerCase().includes(skill.toLowerCase())
      );
      if (matches) {
        card.style.display = '';
        found = true;
      } else {
        card.style.display = 'none';
      }
    });
    let noResult = document.getElementById('noOpportunityResult');
    if (noResult) noResult.remove();
    if (!found) {
      const div = document.createElement('div');
      div.id = 'noOpportunityResult';
      div.className = 'col-12 text-center text-muted py-4';
      div.textContent = 'No results found.';
      const filterRow = document.querySelector('.row.mb-4');
      filterRow.parentNode.insertBefore(div, filterRow.nextSibling);
    }
  });

  document.getElementById('recommendedCourseBtn').addEventListener('click', function() {
    setOpportunityBtnActive('recommendedCourseBtn');
    const cards = document.querySelectorAll('.opportunity-card');
    let found = false;
    cards.forEach(card => {
      if (card.getAttribute('data-recommended-course') === 'yes') {
        card.style.display = '';
        found = true;
      } else {
        card.style.display = 'none';
      }
    });
    let noResult = document.getElementById('noOpportunityResult');
    if (noResult) noResult.remove();
    if (!found) {
      const div = document.createElement('div');
      div.id = 'noOpportunityResult';
      div.className = 'col-12 text-center text-muted py-4';
      div.textContent = 'No results found.';
      const filterRow = document.querySelector('.row.mb-4');
      filterRow.parentNode.insertBefore(div, filterRow.nextSibling);
    }
  });
</script>

<script>
  function toggleRecommendedBadges(show) {
    document.querySelectorAll('.recommended-badge').forEach(badge => {
      badge.style.display = show ? '' : 'none';
    });
  }
  document.getElementById('allOpportunitiesBtn').addEventListener('click', function() {
    toggleRecommendedBadges(true);
  });
  document.getElementById('recommendedSkillsBtn').addEventListener('click', function() {
    toggleRecommendedBadges(false);
  });
  document.getElementById('recommendedCourseBtn').addEventListener('click', function() {
    toggleRecommendedBadges(false);
  });
  // Show by default on page load
  document.addEventListener('DOMContentLoaded', function() {
    toggleRecommendedBadges(true);
  });
</script>

</body>
</html>
