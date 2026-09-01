<?php
include('auth.php');
include('../db.php');

$student_id = $_SESSION['student_id'];

// Fetch courses/subjects the student is enrolled in
$sql = "SELECT s.*, COALESCE(f.name, s.teacher) as teacher_display 
        FROM schedules s
        JOIN schedule_students ss ON s.id = ss.schedule_id
        LEFT JOIN faculty f ON s.teacher_id = f.id
        WHERE ss.student_id = $student_id
        GROUP BY s.id"; // Using ID is safer than subject for grouping
$res = $conn->query($sql);
$courses = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $courses[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Subjects - Student Portal</title>
    <link rel="stylesheet" href="student_assets/dashboard.css">
    <link rel="stylesheet" href="student_assets/courses.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="dashboard-container">
        <div class="header-section">
            <div class="welcome-text">
                <h1>My Enrolled Subjects</h1>
                <p>Overview of your current academic subjects and instructors.</p>
            </div>
        </div>

        <div class="course-grid">
            <?php foreach ($courses as $c): ?>
                <div class="course-card">
                    <div class="course-banner">
                        <h2><?= htmlspecialchars($c['subject']) ?></h2>
                        <div class="course-tag"><?= htmlspecialchars($c['course']) ?></div>
                    </div>
                    <div class="course-content">
                        <div class="info-row"><i class="fa-solid fa-door-open"></i> Room: <?= htmlspecialchars($c['room'] ?? 'TBA') ?></div>
                        <div class="info-row"><i class="fa-solid fa-clock"></i> <?= htmlspecialchars($c['day']) ?></div>
                        
                        <div class="instructor-info">
                            <div class="instructor-avatar">
                                <?= strtoupper(substr($c['teacher_display'] ?? 'T', 0, 1)) ?>
                            </div>
                            <div>
                                <div class="instructor-name"><?= htmlspecialchars($c['teacher_display'] ?? 'TBA') ?></div>
                                <div class="instructor-label">Subject Instructor</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($courses)): ?>
                <div class="card col-12 empty-courses">
                    <i class="fa-solid fa-book-open empty-courses-icon"></i>
                    <h3 class="empty-courses-title">No courses found</h3>
                    <p class="empty-courses-text">You are not currently enrolled in any subjects.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
