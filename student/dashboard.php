<?php
include('auth.php');
include('../db.php');
date_default_timezone_set('Asia/Manila');

$student_id = $_SESSION['student_id'];

// Fetch student details
$student_res = $conn->query("SELECT * FROM students WHERE id = $student_id");
$student = $student_res->fetch_assoc();

if (!$student) {
    // If student not found, clear session and redirect to login
    session_destroy();
    header("Location: login.php");
    exit();
}

// 1. Quick Stats
// Total classes held for this student's course/year/section
$course = $conn->real_escape_string($student['course'] ?? '');
$year = $conn->real_escape_string($student['year_level'] ?? '');
$section = $conn->real_escape_string($student['section'] ?? '');

$total_classes_q = $conn->query("SELECT COUNT(*) as total FROM attendance WHERE student_id = $student_id");
$total_attended = $total_classes_q->fetch_assoc()['total'] ?? 0;

$present_q = $conn->query("SELECT COUNT(*) as count FROM attendance WHERE student_id = $student_id AND status = 'Present'");
$present_count = $present_q->fetch_assoc()['count'] ?? 0;

$absent_q = $conn->query("SELECT COUNT(*) as count FROM attendance WHERE student_id = $student_id AND status = 'Absent'");
$absent_count = $absent_q->fetch_assoc()['count'] ?? 0;

$late_q = $conn->query("SELECT COUNT(*) as count FROM attendance WHERE student_id = $student_id AND status = 'Late'");
$late_count = $late_q->fetch_assoc()['count'] ?? 0;

$total_records = $present_count + $absent_count + $late_count;
$attendance_rate = $total_records > 0 ? round(($present_count / $total_records) * 100, 1) : 100;

// 2. Course-wise breakdown
$course_stats = [];
$c_sql = "SELECT s.id, s.subject, 
                 SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present,
                 SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent,
                 SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) as late,
                 COUNT(a.id) as total
          FROM schedules s
          LEFT JOIN attendance a ON s.id = a.schedule_id AND a.student_id = $student_id
          JOIN schedule_students ss ON s.id = ss.schedule_id AND ss.student_id = $student_id
          GROUP BY s.id, s.subject";
$c_res = $conn->query($c_sql);
if ($c_res) {
    while ($row = $c_res->fetch_assoc()) {
        $course_stats[] = $row;
    }
}

// 3. Upcoming Classes
$dow = date('l');
$upcoming_q = $conn->query("SELECT s.*, COALESCE(f.name, s.teacher) as teacher_display 
                            FROM schedules s 
                            JOIN schedule_students ss ON s.id = ss.schedule_id 
                            LEFT JOIN faculty f ON s.teacher_id = f.id
                            WHERE ss.student_id = $student_id AND s.day LIKE '%$dow%' 
                            AND s.start_time > CURTIME() 
                            ORDER BY s.start_time ASC LIMIT 5");
$upcoming_classes = [];
if ($upcoming_q) {
    while ($row = $upcoming_q->fetch_assoc()) {
        $upcoming_classes[] = $row;
    }
}

// 4. Recent Activity (Last 10)
$recent_q = $conn->query("SELECT a.*, s.subject, s.start_time 
                          FROM attendance a 
                          JOIN schedules s ON a.schedule_id = s.id 
                          WHERE a.student_id = $student_id 
                          ORDER BY a.attendance_date DESC, a.id DESC LIMIT 10");
$recent_activity = [];
if ($recent_q) {
    while ($row = $recent_q->fetch_assoc()) {
        $recent_activity[] = $row;
    }
}

// 5. Absence Alerts (Low attendance or consecutive absences)
$alerts = [];
foreach ($course_stats as $cs) {
    $rate = $cs['total'] > 0 ? ($cs['present'] / $cs['total']) * 100 : 100;
    if ($rate < 75 && $cs['total'] > 0) {
        $alerts[] = [
            'type' => 'warning',
            'title' => 'Low Attendance: ' . htmlspecialchars($cs['subject']),
            'message' => "Your attendance rate for this course is only " . round($rate, 1) . "%."
        ];
    }
}

// Check for consecutive absences
$consecutive_q = $conn->query("SELECT status FROM attendance WHERE student_id = $student_id ORDER BY attendance_date DESC, id DESC LIMIT 3");
$recent_statuses = [];
while ($row = $consecutive_q->fetch_assoc()) { $recent_statuses[] = $row['status']; }
if (count($recent_statuses) >= 2 && $recent_statuses[0] == 'Absent' && $recent_statuses[1] == 'Absent') {
    $alerts[] = [
        'type' => 'danger',
        'title' => 'Consecutive Absences',
        'message' => "You have missed your last 2 sessions. Please check in with your instructors."
    ];
}

// 6. Gamification: Streak and Badge
$streak = 0;
$streak_q = $conn->query("SELECT status FROM attendance WHERE student_id = $student_id ORDER BY attendance_date DESC, id DESC LIMIT 30");
if ($streak_q) {
    while ($row = $streak_q->fetch_assoc()) {
        if ($row['status'] == 'Present') {
            $streak++;
        } else {
            break;
        }
    }
}
$perfect_attendance = ($attendance_rate == 100 && $total_records > 0);

// 7. Student's submitted excuse letters
$excuses_q = $conn->query("SELECT el.*, sc.subject FROM excuse_letters el JOIN schedules sc ON el.schedule_id = sc.id WHERE el.student_id = $student_id ORDER BY el.created_at DESC LIMIT 10");
$my_excuses = [];
if ($excuses_q) {
    while ($row = $excuses_q->fetch_assoc()) {
        $my_excuses[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - NEUST Gatepass</title>
    <link rel="stylesheet" href="student_assets/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    <style>
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 8px; }
        .close-btn { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close-btn:hover { color: #000; }
        .form-group { margin-bottom: 1rem; }
        .form-control { width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; }
        .btn-primary { background: var(--primary); color: white; padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; font-family: 'Inter', sans-serif; font-size: 0.9rem;}
        .btn-secondary { background: #6c757d; color: white; padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-family: 'Inter', sans-serif; font-size: 0.9rem;}
        .clickable-row { cursor: pointer; transition: background 0.2s; }
        .clickable-row:hover { background-color: #f1f5f9; }
        .badge { background: gold; color: #856404; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: bold; }
    </style>
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="dashboard-container">
        <div class="header-section">
            <div class="welcome-text">
                <h1>Hello, <?= htmlspecialchars($student['name']) ?>!</h1>
                <p>Welcome to your attendance dashboard for the current term.</p>
            </div>
            <div class="header-actions">
                <a href="export_attendance.php" target="_blank" class="btn-secondary"><i class="fa-solid fa-download"></i> Download Report</a>
                <button onclick="openExcuseModal()" class="btn-primary"><i class="fa-solid fa-file-medical"></i> Submit Excuse</button>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert-card" style="border-left: 4px solid var(--success, #10b981); background: #fff; margin-bottom: 1rem; padding: 1rem; display: flex; align-items: flex-start; gap: 1rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <i class="fa-solid fa-check-circle" style="color: var(--success, #10b981); font-size: 1.25rem;"></i>
                <div class="alert-content">
                    <h4 style="margin: 0 0 0.25rem 0; color: #1f2937;">Success</h4>
                    <p style="margin: 0; color: #4b5563; font-size: 0.875rem;"><?= $_SESSION['success_message'] ?></p>
                </div>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert-card" style="border-left: 4px solid var(--danger, #ef4444); background: #fff; margin-bottom: 1rem; padding: 1rem; display: flex; align-items: flex-start; gap: 1rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <i class="fa-solid fa-circle-exclamation" style="color: var(--danger, #ef4444); font-size: 1.25rem;"></i>
                <div class="alert-content">
                    <h4 style="margin: 0 0 0.25rem 0; color: #1f2937;">Error</h4>
                    <p style="margin: 0; color: #4b5563; font-size: 0.875rem;"><?= $_SESSION['error_message'] ?></p>
                </div>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="label"><i class="fa-solid fa-layer-group"></i> Total Classes</div>
                <div class="value"><?= $total_records ?></div>
            </div>
            <div class="stat-card">
                <div class="label"><i class="fa-solid fa-calendar-check"></i> Attended</div>
                <div class="value"><?= $present_count ?></div>
            </div>

            <div class="stat-card">
                <div class="label"><i class="fa-solid fa-percent"></i> Rate / Grade</div>
                <div class="value"><?= $total_records > 0 ? $attendance_rate . '% <span style="font-size: 1rem; color: var(--text-muted);">(' . ($attendance_rate >= 75 ? 'PASSED' : 'FAILED') . ')</span>' : 'N/A <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">(No Logs Yet)</span>' ?></div>
            </div>
            <div class="stat-card">
                <div class="label"><i class="fa-solid fa-fire"></i> Current Streak</div>
                <div class="value"><?= $streak ?> Days</div>
            </div>
            <?php if($perfect_attendance): ?>
            <div class="stat-card" style="background: linear-gradient(135deg, #ffd700 0%, #ff8c00 100%); color: white; border: none;">
                <div class="label" style="color: rgba(255,255,255,0.9);"><i class="fa-solid fa-star"></i> Achievement</div>
                <div class="value" style="color: white; font-size: 1.5rem;">Perfect Attendance!</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="main-grid">
            <!-- Attendance Summary Chart -->
            <div class="card col-4">
                <div class="card-title">Attendance Summary</div>
                <div class="chart-container">
                    <canvas id="attendanceChart"></canvas>
                </div>
                <div style="margin-top: 1rem; font-size: 0.875rem; color: var(--text-muted); text-align: center;">
                    Overall attendance status breakdown
                </div>
            </div>

            <!-- Course-wise Breakdown -->
            <div class="card col-8">
                <div class="card-title">Course-wise Breakdown</div>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Course Subject</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Late</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($course_stats as $cs): ?>
                                <?php $rate = $cs['total'] > 0 ? round(($cs['present'] / $cs['total']) * 100, 1) : 100; ?>
                                <tr class="clickable-row" onclick="viewCourseDetails(<?= $cs['id'] ?>, '<?= htmlspecialchars($cs['subject']) ?>')" title="Click to view detailed logs">
                                    <td style="font-weight: 600;"><?= htmlspecialchars($cs['subject']) ?></td>
                                    <td><?= $cs['present'] ?></td>
                                    <td><?= $cs['absent'] ?></td>
                                    <td><?= $cs['late'] ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <div style="width: 60px; height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                                                <div style="width: <?= $rate ?>%; height: 100%; background: <?= $rate >= 75 ? 'var(--accent)' : 'var(--danger)' ?>;"></div>
                                            </div>
                                            <span><?= $rate ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($course_stats)): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No enrolled courses found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Upcoming Classes -->
            <div class="card col-4">
                <div class="card-title">Upcoming Classes</div>
                <?php if (empty($upcoming_classes)): ?>
                    <p style="color: var(--text-muted); font-size: 0.875rem;">No more classes scheduled for today.</p>
                <?php else: ?>
                    <?php foreach ($upcoming_classes as $uc): ?>
                        <div class="activity-item">
                            <div class="activity-icon" style="background: #eef2ff; color: var(--primary);">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <div class="activity-details">
                                <div class="course"><?= htmlspecialchars($uc['subject']) ?></div>
                                <div class="time"><?= date('h:i A', strtotime($uc['start_time'])) ?> — Room <?= htmlspecialchars($uc['room'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Recent Activity Log -->
            <div class="card col-8">
                <div class="card-title">Recent Activity Log</div>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Course</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $ra): ?>
                                <tr>
                                    <td><?= date('M d, Y', strtotime($ra['attendance_date'])) ?></td>
                                    <td><?= htmlspecialchars($ra['subject']) ?></td>
                                    <td><?= date('h:i A', strtotime($ra['start_time'])) ?></td>
                                    <td>
                                        <span class="status-pill status-<?= strtolower($ra['status']) ?>">
                                            <?= $ra['status'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recent_activity)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No recent attendance activity.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Interactive Calendar -->
            <div class="card col-12">
                <div class="card-title">Monthly Attendance Calendar</div>
                <div id="attendanceCalendar" style="min-height: 400px; margin-top: 1rem;"></div>
            </div>

            <!-- My Submitted Excuses -->
            <div class="card col-12">
                <div class="card-title"><i class="fa-solid fa-file-medical" style="margin-right:0.4rem;"></i>My Submitted Excuse Letters</div>
                <?php if (empty($my_excuses)): ?>
                    <p style="color: var(--text-muted); font-size: 0.875rem; padding: 0.5rem 0;">You have not submitted any excuse letters yet.</p>
                <?php else: ?>
                <div style="overflow-x: auto; margin-top: 0.75rem;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Date of Absence</th>
                                <th>Reason</th>
                                <th>Submitted On</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_excuses as $ex): ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($ex['subject']) ?></td>
                                <td><?= date('M d, Y', strtotime($ex['date_absent'])) ?></td>
                                <td style="max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($ex['reason']) ?>">
                                    <?= htmlspecialchars($ex['reason']) ?>
                                </td>
                                <td style="font-size:0.8rem; color:var(--text-muted);"><?= date('M d, Y h:i A', strtotime($ex['created_at'])) ?></td>
                                <td>
                                    <?php
                                        $ex_status = strtolower($ex['status']);
                                        $pill_colors = [
                                            'pending'  => 'background:#fef3c7; color:#92400e;',
                                            'approved' => 'background:#d1fae5; color:#065f46;',
                                            'rejected' => 'background:#fee2e2; color:#7f1d1d;',
                                        ];
                                        $pill_style = $pill_colors[$ex_status] ?? '';
                                    ?>
                                    <span class="status-pill" style="<?= $pill_style ?>"><?= htmlspecialchars($ex['status']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Absence Alerts -->
            <div class="card col-12">
                <div class="card-title">Notifications & Alerts</div>
                <div class="alerts-grid">
                    <?php if (empty($alerts)): ?>
                        <p style="color: var(--text-muted); font-size: 0.875rem;">No new alerts. Keep up the good work!</p>
                    <?php else: ?>
                        <?php foreach ($alerts as $alert): ?>
                            <div class="alert-card" style="border-left-color: <?= $alert['type'] == 'danger' ? 'var(--danger)' : 'var(--warning)' ?>;">
                                <i class="fa-solid <?= $alert['type'] == 'danger' ? 'fa-circle-exclamation' : 'fa-triangle-exclamation' ?>" 
                                   style="color: <?= $alert['type'] == 'danger' ? 'var(--danger)' : 'var(--warning)' ?>;"></i>
                                <div class="alert-content">
                                    <h4><?= htmlspecialchars($alert['title']) ?></h4>
                                    <p><?= htmlspecialchars($alert['message']) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Excuse Modal -->
    <div id="excuseModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeExcuseModal()">&times;</span>
            <h2 style="margin-top:0; margin-bottom: 1rem;">Submit Excuse Letter</h2>
            <form action="submit_excuse.php" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem;">Select Course</label>
                    <select name="schedule_id" class="form-control" required>
                        <?php foreach($course_stats as $cs): ?>
                            <option value="<?= $cs['id'] ?>"><?= htmlspecialchars($cs['subject']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem;">Date of Absence</label>
                    <input type="date" name="date_absent" class="form-control" required>
                </div>
                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem;">Reason</label>
                    <textarea name="reason" class="form-control" rows="4" required></textarea>
                </div>
                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem;">Attachment (Optional)</label>
                    <input type="file" name="excuse_file" class="form-control" accept="image/*,.pdf">
                </div>
                <button type="submit" class="btn-primary">Submit Excuse</button>
            </form>
        </div>
    </div>

    <!-- Course Details Modal -->
    <div id="courseModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeCourseModal()">&times;</span>
            <h2 id="courseModalTitle" style="margin-top:0; margin-bottom: 1rem;">Course Details</h2>
            <div style="overflow-x: auto;">
                <table class="data-table" id="courseDetailsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time Logged</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php
    // Fetch all attendance for calendar
    $cal_q = $conn->query("SELECT a.attendance_date, a.status, s.subject FROM attendance a JOIN schedules s ON a.schedule_id = s.id WHERE a.student_id = $student_id");
    $events = [];
    if($cal_q){
        while($row = $cal_q->fetch_assoc()) {
            $color = '#10b981'; // present
            if ($row['status'] == 'Absent') $color = '#ef4444';
            if ($row['status'] == 'Late') $color = '#f59e0b';
            
            $events[] = [
                'title' => $row['subject'] . ' (' . $row['status'] . ')',
                'start' => $row['attendance_date'],
                'color' => $color
            ];
        }
    }
    ?>

    <script>
        // Calendar Init
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('attendanceCalendar');
            if(calendarEl) {
                var calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    events: <?= json_encode($events) ?>,
                    height: 500,
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek'
                    }
                });
                calendar.render();
            }
        });

        // Modals Logic
        function openExcuseModal() { document.getElementById('excuseModal').style.display = 'block'; }
        function closeExcuseModal() { document.getElementById('excuseModal').style.display = 'none'; }
        
        function viewCourseDetails(scheduleId, subjectName) {
            document.getElementById('courseModalTitle').innerText = subjectName + " - Attendance Log";
            document.getElementById('courseModal').style.display = 'block';
            
            fetch('get_course_details.php?schedule_id=' + scheduleId)
                .then(response => response.json())
                .then(data => {
                    const tbody = document.querySelector('#courseDetailsTable tbody');
                    tbody.innerHTML = '';
                    if(data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding: 1rem;">No records found.</td></tr>';
                        return;
                    }
                    data.forEach(row => {
                        const tr = document.createElement('tr');
                        const time = row.time_logged ? new Date('1970-01-01T' + row.time_logged).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '-';
                        tr.innerHTML = `
                            <td>${new Date(row.attendance_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'})}</td>
                            <td>${time}</td>
                            <td><span class="status-pill status-${row.status.toLowerCase()}">${row.status}</span></td>
                        `;
                        tbody.appendChild(tr);
                    });
                });
        }
        function closeCourseModal() { document.getElementById('courseModal').style.display = 'none'; }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('excuseModal')) {
                closeExcuseModal();
            }
            if (event.target == document.getElementById('courseModal')) {
                closeCourseModal();
            }
        }

        // Attendance Chart
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Present', 'Absent', 'Late'],
                datasets: [{
                    data: [<?= $present_count ?>, <?= $absent_count ?>, <?= $late_count ?>],
                    backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: { size: 12, family: "'Inter', sans-serif" }
                        }
                    }
                }
            }
        });


    </script>
</body>
</html>
