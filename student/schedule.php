<?php
include('auth.php');
include('../db.php');
date_default_timezone_set('Asia/Manila');

$student_id = $_SESSION['student_id'];

// Get student info for context
$st_res = $conn->query("SELECT * FROM students WHERE id = $student_id");
$student = $st_res->fetch_assoc();

// Days of the week
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$current_day = date('l');
$selected_day = $_GET['day'] ?? $current_day;
if (!in_array($selected_day, $days)) {
    $selected_day = $current_day;
}

// Fetch schedule for the student
// We join with schedule_students to get classes specifically assigned to them
$sql = "SELECT s.*, COALESCE(f.name, s.teacher) as teacher_display 
        FROM schedules s
        JOIN schedule_students ss ON s.id = ss.schedule_id
        LEFT JOIN faculty f ON s.teacher_id = f.id
        WHERE ss.student_id = $student_id
        AND s.day LIKE '%$selected_day%'
        ORDER BY s.start_time ASC";
$res = $conn->query($sql);
$schedules = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $schedules[] = $row;
    }
}

// Function to check if a class is happening now
function isNow($start, $end, $day) {
    $current_day = date('l');
    if (stripos($day, $current_day) === false) return false;
    
    $now = strtotime(date('H:i:s'));
    $s = strtotime($start);
    $e = strtotime($end);
    return ($now >= $s && $now <= $e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - Student Portal</title>
    <link rel="stylesheet" href="student_assets/dashboard.css">
    <link rel="stylesheet" href="student_assets/schedule.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="schedule-container">
        <div class="header-section">
            <div class="welcome-text">
                <h1>Class Schedule</h1>
                <p>Manage and view your weekly academic timetable.</p>
            </div>
            <div class="header-actions" style="display: flex; gap: 1rem;">
                <div class="search-box" style="position: relative;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                    <input type="text" id="scheduleSearch" placeholder="Search subject..." class="btn btn-outline" style="padding-left: 2.5rem; text-align: left; width: 200px;">
                </div>
                <button class="btn btn-outline" onclick="window.print()">
                    <i class="fa-solid fa-print"></i> Print
                </button>
            </div>
        </div>

        <!-- Day Selector -->
        <div class="day-selector">
            <?php foreach ($days as $day): ?>
                <a href="?day=<?= $day ?>" class="day-btn <?= $selected_day == $day ? 'active' : '' ?>">
                    <?= $day ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Schedule Grid -->
        <div class="schedule-grid">
            <?php if (empty($schedules)): ?>
                <div class="card col-12" style="text-align: center; padding: 4rem;">
                    <i class="fa-solid fa-calendar-xmark" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                    <h3 style="margin: 0; color: #64748b;">No classes scheduled for <?= $selected_day ?></h3>
                    <p style="color: #94a3b8; margin-top: 0.5rem;">Enjoy your free time or use it for self-study!</p>
                </div>
            <?php else: ?>
                <?php foreach ($schedules as $s): ?>
                    <?php $happening = isNow($s['start_time'], $s['end_time'], $s['day']); ?>
                    <div class="schedule-card <?= $happening ? 'current' : '' ?>">
                        <div class="time-slot">
                            <i class="fa-regular fa-clock"></i>
                            <?= date('h:i A', strtotime($s['start_time'])) ?> - <?= date('h:i A', strtotime($s['end_time'])) ?>
                        </div>
                        <div class="subject-name"><?= htmlspecialchars($s['subject']) ?></div>
                        
                        <div class="info-row">
                            <i class="fa-solid fa-user-tie"></i>
                            <span><?= htmlspecialchars($s['teacher_display'] ?? 'TBA') ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fa-solid fa-location-dot"></i>
                            <span><?= htmlspecialchars($s['room'] ?? 'TBA') ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fa-solid fa-graduation-cap"></i>
                            <span><?= htmlspecialchars($s['course']) ?> - <?= htmlspecialchars($s['year_level']) ?> (<?= htmlspecialchars($s['section']) ?>)</span>
                        </div>


                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>



    <script>
        // Search Filter
        document.getElementById('scheduleSearch')?.addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase();
            const cards = document.querySelectorAll('.schedule-card');
            
            cards.forEach(card => {
                const subject = card.querySelector('.subject-name').textContent.toLowerCase();
                if (subject.includes(term)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });


    </script>
</body>
</html>
