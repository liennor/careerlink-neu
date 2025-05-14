<?php

require_once 'db/db.php';


try {
    $limit = 5;
    $stmt = $conn->prepare("SELECT * FROM announcements ORDER BY created_at DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $announcements = [];
    error_log("Error fetching announcements: " . $e->getMessage());
    $_SESSION['flash'] = "An error occurred while loading announcements.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CareerLink NEU</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="css/style.css" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>

  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-success">
    <div class="container">
      <a class="navbar-brand fw-bold"href="https://neu.edu.ph/main/" target="_blank" >Career<span class="text-warning">Link</span> NEU</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
        <ul class="navbar-nav">
          <li class="nav-item"><a class="nav-link fw-bold" href="#">Home</a></li>
          <li class="nav-item"><a class="nav-link fw-bold" href="#footerSection">About</a></li>
          <!-- <li class="nav-item"><a class="nav-link" href="#announcementsSection">Announcements</a></li>
          <li class="nav-item"><a class="nav-link fw-bold" href="#">Contact</a></li> -->
          <li class="nav-item"><a class="btn btn-warning" href="register">Sign up</a></li>
        </ul>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <header class="bg-dark text-white" style="background: url('images/bg.jpg') no-repeat center center/cover; padding: 120px 0;">
    <div class="container">
      <div class="row">
        <div class="col-lg-6">
          <h1 class="display-4 fw-bold">CAREER LINK NEU</h1>
          <p class="lead fw-bold">A CICS Portal for NEU Alumni and Student Opportunities with Partner Companies</p>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <a href="login" class="btn btn-success">Login now!</a>
            <a href="#announcementsSection" class="btn btn-outline-light">Announcements</a>
            <a href="#contactSection" class="btn btn-outline-light">Contact Us</a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- Main Section -->
  <main class="container my-5">
    <h2 class="text-center fw-bold mb-5">Welcome to CAREER LINK NEU!</h2>
    <div class="row g-4">
      <div class="col-lg-6">
        <h4 class="fw-semibold">Our Philosophy of Education</h4>
        <p>Godliness is the foundation of knowledge.</p>

        <h4 class="fw-semibold">Our Mission</h4>
        <p>To provide students with the support and guidance to acquire the needed skills, connections and experiences to ensure their success in their chosen careers.</p>

        <h4 class="fw-semibold">Our Vision</h4>
        <p>NEU-OCPIL envisions to be an outstanding department that empowers students holistically in their pursuit for a fulfilling career path aimed at providing selfless service to humanity.</p>

        <h5 class="fw-semibold">Goals & Objectives</h5>
        <ul>
          <li>Institute valid appraisal data of students for career and job placement and continuous follow-up and monitoring on a regular basis.</li>
          <li>Maintain active networking with the school, community, alumni, and other relevant agencies for career and job placement of students.</li>
          <li>Conduct regular career seminars and job placement services for students.</li>
          <li>Conduct job seminars and placement services.</li>
          <li>Establish mechanisms to institutionalize the link with industries.</li>
          <li>Disseminate information to students of the timelines in seeking career and job placement in a specified period of time.</li>
          <li>Explore and sustain partnerships with private and government institutions.</li>
        </ul>
      </div>
      <div class="col-lg-6">
        <div class="d-grid gap-3">
          <div class="card p-3 shadow-sm">
            <h5>Student Tracking</h5>
            <p>We maintain comprehensive records of all CICS students and alumni, allowing us to align them with relevant opportunities.</p>
          </div>
          <div class="card p-3 shadow-sm">
            <h5>Industry Partnerships</h5>
            <p>Our network includes 50+ partners offering exclusive opportunities for internships and job placements.</p>
          </div>
          <div class="card p-3 shadow-sm">
            <h5>Career Services</h5>
            <p>We provide resume reviews, interview training, and skill assessments to ensure students are job-ready.</p>
          </div>
        </div>
      </div>
    </div>
  </main>


    <!-- Our Services Section -->
    <section class="py-5 bg-light text-center">
        <div class="container">
            <h2 class="fw-bold">Our Services</h2>
            <div class="border border-3 rounded mx-auto mb-4" style="width: 60px; border-color: #1d5132 !important;"></div>
            <div class="row g-4 justify-content-center">
            <div class="col-md-4">
                <div class="card p-4 h-100 shadow-sm">
                <div class="mb-3">
                    <span class="bg-success text-white p-3 rounded-circle">
                    <i class="bi bi-calendar2-check" style="font-size: 1.5rem;"></i>
                    </span>
                </div>
                <h5 class="fw-bold">Job Opportunities</h5>
                <p>Access exclusive job and internship opportunities from our network of partner companies.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-4 h-100 shadow-sm">
                <div class="mb-3">
                    <span class="bg-success text-white p-3 rounded-circle">
                    <i class="bi bi-file-earmark-text" style="font-size: 1.5rem;"></i>
                    </span>
                </div>
                <h5 class="fw-bold">Resume Scanner</h5>
                <p>Upload your resume and our AI will analyze your skills to match you with the perfect opportunities.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-4 h-100 shadow-sm">
                <div class="mb-3">
                    <span class="bg-success text-white p-3 rounded-circle">
                    <i class="bi bi-megaphone" style="font-size: 1.5rem;"></i>
                    </span>
                </div>
                <h5 class="fw-bold">Announcements</h5>
                <p>Stay updated with the latest news, events, and career opportunities from the university.</p>
                </div>
            </div>
            </div>
        </div>
    </section>

    <!-- Latest Announcements Section -->
    <section class="py-5" id="announcementsSection">
      <div class="container text-center">
          <h2 class="fw-bold">Latest Announcements</h2>
          <div class="border border-3 rounded mx-auto mb-4" style="width: 60px; border-color: #1d5132 !important;"></div>
          <p class="mb-5">Stay informed about the latest opportunities, events, and news. Login to access full announcements and personalized notifications.</p>
          
          <div class="row g-4 justify-content-center">
          <?php if (empty($announcements)): ?>
              <div class="col-12 text-muted">No announcements available at the moment.</div>
          <?php else: ?>
              <?php foreach ($announcements as $a): ?>
              <div class="col-md-4">
                  <div class="card h-100 shadow-sm">
                      <div class="card-header bg-success text-white text-start">
                          <small class="fw-semibold"><?= htmlspecialchars($a['type'] ?? 'Announcement') ?></small>
                      </div>
                      <div class="card-body text-start">
                          <h5 class="card-title fw-bold"><?= htmlspecialchars($a['title']) ?></h5>
                          <p class="card-text"><?= htmlspecialchars(mb_strimwidth(strip_tags($a['content']), 0, 80, '…')) ?></p>
                      </div>
                      <div class="card-footer text-start d-flex justify-content-between align-items-center">
                          <small class="text-muted"><?= date('M j, Y', strtotime($a['created_at'])) ?></small>
                          <a href="login" class="text-success text-decoration-none">Read more →</a>
                      </div>
                  </div>
              </div>
              <?php endforeach; ?>
          <?php endif; ?>
          </div>
      </div>
    </section>

    <!-- Contact Us Section -->
    <section class="py-5" id="contactSection">
    <div class="container text-center">
        <h2 class="fw-bold">Contact Us</h2>
        <div class="border border-3 rounded mx-auto mb-4" style="width: 60px; border-color: #1d5132 !important;"></div>

        <div class="row justify-content-center">
        <div class="col-md-6 text-start">
            <h5 class="fw-bold">Get In Touch</h5>
            <p>Have questions about CareerLink NEU or need assistance with your account? Our team is here to help. Reach out to us using any of the contact methods listed.</p>
            
            <div class="d-flex align-items-start mb-3">
            <div class="me-3 text-success"><i class="bi bi-geo-alt-fill fs-4"></i></div>
            <div>
                <strong>Address</strong><br>
                New Era University<br>
                No. 9 Central Avenue, New Era<br>
                Quezon City, Philippines 1107
            </div>
            </div>
            
            <div class="d-flex align-items-start mb-3">
            <div class="me-3 text-success"><i class="bi bi-envelope-fill fs-4"></i></div>
            <div>
                <strong>Email</strong><br>
                careerlink@neu.edu.ph<br>
                computerstudies@neu.edu.ph
            </div>
            </div>
            
            <div class="d-flex align-items-start mb-3">
            <div class="me-3 text-success"><i class="bi bi-telephone-fill fs-4"></i></div>
            <div>
                <strong>Phone</strong><br>
                +639164129312
            </div>
            </div>
        </div>

        <div class="col-md-4 text-start bg-light rounded p-4">
            <h5 class="fw-bold">Office Hours</h5>
            <p><strong>Monday – Friday:</strong> 08:00am – 04:00pm<br>
            <strong>Sunday:</strong> Closed</p>
            </div>
        </div>
        </div>
    </div>
    </section>

    <!-- Footer Section -->
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


  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
