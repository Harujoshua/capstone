<?php
include('auth.php');

$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Fetch active school year & semester settings from neust_gatepass_v3 database
$active_school_year = '2025-2026';
$active_semester = '1st Semester';
$term_q = $conn->query("SELECT name, value FROM settings WHERE name IN ('active_school_year', 'active_semester')");
if ($term_q) {
    while ($row = $term_q->fetch_assoc()) {
        if ($row['name'] === 'active_school_year') {
            $active_school_year = $row['value'];
        } elseif ($row['name'] === 'active_semester') {
            $active_semester = $row['value'];
        }
    }
}
$safe_sy = $conn->real_escape_string($active_school_year);
$safe_sem = $conn->real_escape_string($active_semester);

// Calculate overall attendance stats (filtered to active academic term)
$attendance_q = $conn->query("
    SELECT 
        COUNT(a.id) AS total, 
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_count
    FROM attendance a
    JOIN schedules s ON a.schedule_id = s.id
    WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
");
$attendance_data = $attendance_q ? $attendance_q->fetch_assoc() : null;
$total_records = intval($attendance_data['total'] ?? 0);
$present_count = intval($attendance_data['present_count'] ?? 0);
$late_count = intval($attendance_data['late_count'] ?? 0);
$absent_count = intval($attendance_data['absent_count'] ?? 0);

$attended_count = $present_count + $late_count;

$overall_attendance_rate = $total_records > 0 
    ? round(($attended_count / $total_records) * 100, 1) 
    : 100.0;

$ontime_rate = $total_records > 0 
    ? round(($present_count / $total_records) * 100, 1) 
    : 0.0;

$absent_rate = $total_records > 0 
    ? round(($absent_count / $total_records) * 100, 1) 
    : 0.0;

$late_rate = $total_records > 0 
    ? round(($late_count / $total_records) * 100, 1) 
    : 0.0;

// Fetch department breakdown for On-Time Rate (filtered to active academic term)
$dept_ontime_q = $conn->query("
    SELECT 
        stud.course,
        COUNT(a.id) AS total,
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present_count
    FROM attendance a
    JOIN students stud ON a.student_id = stud.id
    JOIN schedules s ON a.schedule_id = s.id
    WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
    GROUP BY stud.course
    ORDER BY (SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(a.id)) DESC
");
$dept_ontime = [];
if ($dept_ontime_q) {
    while($row = $dept_ontime_q->fetch_assoc()) {
        $dept_ontime[] = $row;
    }
}

// Fetch total students tracked
$totals = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc();
$total_students = intval($totals['c'] ?? 0);

// Fetch at-risk students count (attendance rate < 80% in the active academic term)
$at_risk_q = $conn->query("
    SELECT COUNT(*) AS c FROM (
        SELECT 
            a.student_id, 
            COUNT(a.id) AS total_classes,
            SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS attended_classes
        FROM attendance a
        JOIN schedules s ON a.schedule_id = s.id
        WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
        GROUP BY a.student_id
        HAVING total_classes > 0 AND (attended_classes / total_classes * 100) < 80.0
    ) AS at_risk_sub
");
$at_risk_data = $at_risk_q ? $at_risk_q->fetch_assoc() : null;
$at_risk_students = intval($at_risk_data['c'] ?? 0);

// Fetch list of at-risk students (filtered to active academic term)
$at_risk_list_q = $conn->query("
    SELECT 
        stud.id,
        stud.name,
        stud.rfid_uid,
        stud.course,
        stud.year_level,
        stud.section,
        at_risk_sub.total_classes,
        at_risk_sub.attended_classes,
        ROUND((at_risk_sub.attended_classes / at_risk_sub.total_classes * 100), 1) AS attendance_rate
    FROM (
        SELECT 
            a.student_id, 
            COUNT(a.id) AS total_classes,
            SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS attended_classes
        FROM attendance a
        JOIN schedules s ON a.schedule_id = s.id
        WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
        GROUP BY a.student_id
        HAVING total_classes > 0 AND (attended_classes / total_classes * 100) < 80.0
    ) AS at_risk_sub
    JOIN students stud ON at_risk_sub.student_id = stud.id
    ORDER BY attendance_rate ASC
");

// Fetch attendance & absence trends for the last 7 days (filtered to active academic term)
$day_labels = [];
$weekday_labels = [];
$ontime_day_counts = [];
$absent_day_counts = [];
$late_day_counts = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $day_labels[] = date('M d', strtotime($d));
    $weekday_labels[] = date('D', strtotime($d));
    
    // On-Time (Present)
    $ontime_trend_q = $conn->query("
        SELECT COUNT(a.id) AS c 
        FROM attendance a
        JOIN schedules s ON a.schedule_id = s.id
        WHERE DATE(a.attendance_date) = '$d' 
          AND a.status = 'Present'
          AND s.school_year = '$safe_sy'
          AND s.semester = '$safe_sem'
    ")->fetch_assoc();
    $ontime_day_counts[] = intval($ontime_trend_q['c'] ?? 0);

    // Absences (Absent)
    $absent_trend_q = $conn->query("
        SELECT COUNT(a.id) AS c 
        FROM attendance a
        JOIN schedules s ON a.schedule_id = s.id
        WHERE DATE(a.attendance_date) = '$d' 
          AND a.status = 'Absent'
          AND s.school_year = '$safe_sy'
          AND s.semester = '$safe_sem'
    ")->fetch_assoc();
    $absent_day_counts[] = intval($absent_trend_q['c'] ?? 0);

    // Lates (Late)
    $late_trend_q = $conn->query("
        SELECT COUNT(a.id) AS c 
        FROM attendance a
        JOIN schedules s ON a.schedule_id = s.id
        WHERE DATE(a.attendance_date) = '$d' 
          AND a.status = 'Late'
          AND s.school_year = '$safe_sy'
          AND s.semester = '$safe_sem'
    ")->fetch_assoc();
    $late_day_counts[] = intval($late_trend_q['c'] ?? 0);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="admin_assets/admin_dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <div class="app">
        <?php include('navbar.php'); ?>

        <main class="content">
    <div class="welcome-banner">
        <div>
            <h1>Welcome back, <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?>!</h1>
            <p>You are currently logged in as <strong><?= ($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin' ? 'Super Administrator' : 'Site Supervisor' ?></strong>.</p>
        </div>
        <div class="active-term-badge">
            <i class="fa-solid fa-graduation-cap"></i>
            <span>Active Term: <?= htmlspecialchars($active_school_year) ?> – <?= htmlspecialchars($active_semester) ?></span>
        </div>
    </div>

    <div class="rates-grid">
        <!-- Card: Students Tracked -->
        <div class="rate-card tracked">
            <div class="card-info">
                <h2 class="rate-value"><?= number_format($total_students) ?></h2>
                <p class="rate-label">Students Tracked</p>
                <div class="status-indicator">
                    <span class="dot pulse"></span> Active Tracking
                </div>
                <p class="rate-sub">Registered profiles</p>
            </div>
        </div>

        <!-- Card: At-Risk Students -->
        <div class="rate-card at-risk">
            <div class="card-info">
                <div class="card-header-row">
                    <h2 class="rate-value"><?= number_format($at_risk_students) ?></h2>
                    <a href="javascript:void(0)" onclick="openRiskModal()" class="view-all-link">View All</a>
                </div>
                <p class="rate-label">At-Risk Students</p>
                <div class="risk-indicator">
                    <span class="dot pulse-risk"></span> Rate &lt; 80%
                </div>
                <p class="rate-sub">Require attention</p>
            </div>
        </div>

        <!-- Card 1: Attendance Rate -->
        <div class="rate-card attendance-donut-card">
            <div class="donut-chart-wrapper">
                <canvas id="attendanceDonutChart"></canvas>
                <div class="donut-percentage"><?= $total_records > 0 ? $overall_attendance_rate . '%' : 'N/A' ?></div>
            </div>
            <div class="card-info">
                <p class="rate-label" style="margin: 0 0 6px;">Overall Attendance</p>
                <p class="rate-sub" style="margin: 0 0 2px; font-weight: 600; color: #1e293b;"><?= $total_records > 0 ? 'Active Track' : 'No Data Recorded' ?></p>
                <p class="rate-sub"><?= $total_records > 0 ? "Attended: " . number_format($attended_count) . " of " . number_format($total_records) : "No attendance logs yet" ?></p>
            </div>
        </div>

        <!-- Card 2: On-Time Rate -->
        <div class="rate-card ontime-donut-card" style="align-items: flex-start;">
            <div class="donut-chart-wrapper" style="margin-top: 4px;">
                <canvas id="ontimeDonutChart"></canvas>
                <div class="donut-percentage"><?= $total_records > 0 ? $ontime_rate . '%' : 'N/A' ?></div>
            </div>
            <div class="card-info" style="width: 100%;">
                <p class="rate-label" style="margin: 0 0 6px;">On-Time Rate</p>
                <p class="rate-sub" style="margin: 0 0 2px; font-weight: 600; color: #1e293b;"><?= $total_records > 0 ? 'Active Track' : 'No Data Recorded' ?></p>
                <p class="rate-sub"><?= $total_records > 0 ? "On-Time: " . number_format($present_count) . " of " . number_format($total_records) : "No logs recorded" ?></p>
                
                <div class="dept-breakdown" style="margin-top: 12px; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
                    <p style="font-size: 0.7rem; font-weight: 700; color: #64748b; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 0.5px;">By Department</p>
                    <style>
                        .dept-scroll::-webkit-scrollbar { width: 4px; }
                        .dept-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
                        .dept-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
                        .dept-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
                    </style>
                    <?php if (empty($dept_ontime)): ?>
                        <p style="font-size: 0.75rem; color: #94a3b8; margin: 0;">No data</p>
                    <?php else: ?>
                        <div class="dept-scroll" style="max-height: 85px; overflow-y: auto; padding-right: 4px; display: flex; flex-direction: column; gap: 6px;">
                            <?php foreach ($dept_ontime as $dept): ?>
                                <?php 
                                    $d_rate = $dept['total'] > 0 ? round(($dept['present_count'] / $dept['total']) * 100, 1) : 0; 
                                    $c_name = empty($dept['course']) ? 'Unassigned' : htmlspecialchars($dept['course']);
                                ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem;">
                                    <span style="color: #475569; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 80px;" title="<?= $c_name ?>"><?= $c_name ?></span>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <div style="width: 36px; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= $d_rate ?>%; background: #10b981; border-radius: 2px;"></div>
                                        </div>
                                        <span style="color: #10b981; font-weight: 700; width: 32px; text-align: right;"><?= $d_rate ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Card 3: Late Rate -->
        <div class="rate-card late-donut-card">
            <div class="donut-chart-wrapper">
                <canvas id="lateDonutChart"></canvas>
                <div class="donut-percentage"><?= $total_records > 0 ? $late_rate . '%' : 'N/A' ?></div>
            </div>
            <div class="card-info">
                <p class="rate-label" style="margin: 0 0 6px;">Late Rate</p>
                <p class="rate-sub" style="margin: 0 0 2px; font-weight: 600; color: #1e293b;"><?= $total_records > 0 ? 'Active Track' : 'No Data Recorded' ?></p>
                <p class="rate-sub"><?= $total_records > 0 ? "Lates: " . number_format($late_count) . " of " . number_format($total_records) : "No logs recorded" ?></p>
            </div>
        </div>

        <!-- Card 4: Absent Rate -->
        <div class="rate-card absent-donut-card">
            <div class="donut-chart-wrapper">
                <canvas id="absentDonutChart"></canvas>
                <div class="donut-percentage"><?= $total_records > 0 ? $absent_rate . '%' : 'N/A' ?></div>
            </div>
            <div class="card-info">
                <p class="rate-label" style="margin: 0 0 6px;">Absent Rate</p>
                <p class="rate-sub" style="margin: 0 0 2px; font-weight: 600; color: #1e293b;"><?= $total_records > 0 ? 'Active Track' : 'No Data Recorded' ?></p>
                <p class="rate-sub"><?= $total_records > 0 ? "Absents: " . number_format($absent_count) . " of " . number_format($total_records) : "No logs recorded" ?></p>
            </div>
        </div>
    </div>

    <!-- Charts Grid -->
    <div class="charts-grid">
        <!-- Chart 1: Attendance Trends Line Chart -->
        <div class="chart-box">
            <h3>Attendance Trends (Last 7 Days)</h3>
            <div class="chart-container">
                <canvas id="trendsChart"></canvas>
            </div>
        </div>

        <!-- Chart 2: Absences Bar Chart -->
        <div class="chart-box">
            <h3>Absences by Day (Last 7 Days)</h3>
            <div class="chart-container">
                <canvas id="absencesChart"></canvas>
            </div>
        </div>
    </div>
</main>
</div>

<!-- At-Risk Students Modal -->
<div id="atRiskModal" class="custom-modal">
    <div class="custom-modal-content">
        <div class="custom-modal-header">
            <h3>At-Risk Students List</h3>
            <span class="close-modal-btn" onclick="closeRiskModal()">&times;</span>
        </div>
        <p class="custom-modal-subtitle">Students currently monitored under the 80% critical attendance threshold.</p>
        
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Course & Section</th>
                        <th>Total Classes</th>
                        <th>Attended</th>
                        <th>Attendance Rate</th>
                        <th class="text-right">Risk Assessment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($at_risk_list_q && $at_risk_list_q->num_rows > 0): 
                        $at_risk_list_q->data_seek(0);
                    ?>
                        <?php while ($row = $at_risk_list_q->fetch_assoc()): 
                            $rate = floatval($row['attendance_rate']);
                            $level = 'Medium Risk';
                            $level_class = 'medium-risk';
                            if ($rate < 50.0) {
                                $level = 'Critical Risk';
                                $level_class = 'critical-risk';
                            } elseif ($rate < 70.0) {
                                $level = 'High Risk';
                                $level_class = 'high-risk';
                            }
                        ?>
                            <tr>
                                <td>
                                    <div class="student-name-cell">
                                        <div class="student-avatar"><?= strtoupper(substr($row['name'], 0, 1)) ?></div>
                                        <div class="student-name-text"><?= htmlspecialchars($row['name']) ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="class-badge">
                                        <span class="course"><?= htmlspecialchars($row['course'] ?? 'N/A') ?></span>
                                        <span class="divider"></span>
                                        <span class="level"><?= htmlspecialchars($row['year_level'] ?? '') ?> - <?= htmlspecialchars($row['section'] ?? '') ?></span>
                                    </div>
                                </td>
                                <td><?= number_format($row['total_classes']) ?></td>
                                <td><?= number_format($row['attended_classes']) ?></td>
                                <td>
                                    <div class="rate-progress-cell">
                                        <strong><?= $row['attendance_rate'] ?>%</strong>
                                        <div class="mini-progress">
                                            <div class="mini-bar <?= $level_class ?>" style="width: <?= $row['attendance_rate'] ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <span class="risk-badge <?= $level_class ?>">
                                        <?= $level ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <strong style="color: #334155; display: block; margin-bottom: 4px;">All clear!</strong>
                                <span style="color: var(--muted); font-size: 0.9rem;">No students are currently tracked below the 80% attendance threshold.</span>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="custom-modal-footer">
            <button class="custom-modal-close-btn" onclick="closeRiskModal()">Close</button>
        </div>
    </div>
</div>

<!-- Chart.js script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Modal Functions
    function openRiskModal() {
        document.getElementById('atRiskModal').classList.add('show');
    }
    function closeRiskModal() {
        document.getElementById('atRiskModal').classList.remove('show');
    }
    window.addEventListener('click', function(event) {
        var modal = document.getElementById('atRiskModal');
        if (event.target == modal) {
            closeRiskModal();
        }
    });

document.addEventListener('DOMContentLoaded', function () {
    const dayLabels = <?php echo json_encode($day_labels); ?>;
    const weekdayLabels = <?php echo json_encode($weekday_labels); ?>;
    const dayDataOnTime = <?php echo json_encode($ontime_day_counts); ?>;
    const dayDataAbsences = <?php echo json_encode($absent_day_counts); ?>;
    const dayDataLates = <?php echo json_encode($late_day_counts); ?>;
    
    // Safety checks for maxima (ES5 compatible to avoid any crash if array has nulls or is empty)
    const maxOnTime = dayDataOnTime.length ? Math.max.apply(null, dayDataOnTime) : 0;
    const maxLates = dayDataLates.length ? Math.max.apply(null, dayDataLates) : 0;
    const maxAbsences = dayDataAbsences.length ? Math.max.apply(null, dayDataAbsences) : 0;

    // --- Chart 1: Attendance Trends Line Chart ---
    const ctxTrends = document.getElementById('trendsChart').getContext('2d');
    
    const gradientOnTime = ctxTrends.createLinearGradient(0, 0, 0, 300);
    gradientOnTime.addColorStop(0, 'rgba(16, 185, 129, 0.2)');
    gradientOnTime.addColorStop(1, 'rgba(16, 185, 129, 0)');

    const gradientLates = ctxTrends.createLinearGradient(0, 0, 0, 300);
    gradientLates.addColorStop(0, 'rgba(245, 158, 11, 0.15)');
    gradientLates.addColorStop(1, 'rgba(245, 158, 11, 0)');

    const gradientAbsencesLine = ctxTrends.createLinearGradient(0, 0, 0, 300);
    gradientAbsencesLine.addColorStop(0, 'rgba(244, 63, 94, 0.2)');
    gradientAbsencesLine.addColorStop(1, 'rgba(244, 63, 94, 0)');
    
    new Chart(ctxTrends, {
        type: 'line',
        data: {
            labels: dayLabels,
            datasets: [
                {
                    label: 'On Time',
                    data: dayDataOnTime,
                    borderColor: '#10b981',
                    borderWidth: 3,
                    backgroundColor: gradientOnTime,
                    fill: true,
                    tension: 0.35,
                    yAxisID: 'y',
                    pointBackgroundColor: '#10b981',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                },
                {
                    label: 'Late',
                    data: dayDataLates,
                    borderColor: '#f59e0b',
                    borderWidth: 3,
                    backgroundColor: gradientLates,
                    fill: true,
                    tension: 0.35,
                    yAxisID: 'y',
                    pointBackgroundColor: '#f59e0b',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                },
                {
                    label: 'Absent',
                    data: dayDataAbsences,
                    borderColor: '#f43f5e',
                    borderWidth: 3,
                    backgroundColor: gradientAbsencesLine,
                    fill: true,
                    tension: 0.35,
                    yAxisID: 'y1',
                    pointBackgroundColor: '#f43f5e',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: {
                        boxWidth: 8,
                        boxHeight: 8,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 15,
                        color: '#64748b',
                        font: { family: 'Inter', size: 11, weight: '600' }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: '#1e293b',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    padding: 12,
                    borderRadius: 8,
                    titleFont: { family: 'Inter', size: 12, weight: 'bold' },
                    bodyFont: { family: 'Inter', size: 11 },
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.dataset.label + ': ' + context.raw;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { family: 'Inter', size: 11 }
                    }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    suggestedMax: Math.max(10, maxOnTime, maxLates) + 2,
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        precision: 0,
                        color: '#475569',
                        font: { family: 'Inter', size: 11, weight: '600' }
                    },
                    title: {
                        display: true,
                        text: 'On-Time & Late Counts',
                        color: '#475569',
                        font: { family: 'Inter', size: 11, weight: '700' }
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    suggestedMax: Math.max(5, maxAbsences) + 2,
                    grid: { drawOnChartArea: false },
                    ticks: {
                        precision: 0,
                        color: '#f43f5e',
                        font: { family: 'Inter', size: 11, weight: '600' }
                    },
                    title: {
                        display: true,
                        text: 'Absence Counts',
                        color: '#f43f5e',
                        font: { family: 'Inter', size: 11, weight: '700' }
                    }
                }
            }
        }
    });

    // --- Chart 1B: On-Time Miniature Donut Chart ---
    const ctxDonut = document.getElementById('ontimeDonutChart').getContext('2d');
    const ontimeVal = <?= floatval($ontime_rate) ?>;
    const remainingVal = Math.max(0, 100 - ontimeVal);
    
    new Chart(ctxDonut, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [ontimeVal, remainingVal],
                backgroundColor: [
                    '#10b981',
                    '#e2e8f0'
                ],
                borderWidth: 0,
                hoverBackgroundColor: [
                    '#059669',
                    '#cbd5e1'
                ]
            }]
        },
        options: {
            cutout: '72%',
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            }
        }
    });

    // --- Chart 1C: Attendance Miniature Donut Chart ---
    const ctxAttendanceDonut = document.getElementById('attendanceDonutChart').getContext('2d');
    const attendanceVal = <?= floatval($overall_attendance_rate) ?>;
    const remainingAttendanceVal = Math.max(0, 100 - attendanceVal);
    
    new Chart(ctxAttendanceDonut, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [attendanceVal, remainingAttendanceVal],
                backgroundColor: [
                    '#6366f1',
                    '#e2e8f0'
                ],
                borderWidth: 0,
                hoverBackgroundColor: [
                    '#4f46e5',
                    '#cbd5e1'
                ]
            }]
        },
        options: {
            cutout: '72%',
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            }
        }
    });

    // --- Chart 1D: Late Miniature Donut Chart ---
    const ctxLateDonut = document.getElementById('lateDonutChart').getContext('2d');
    const lateVal = <?= floatval($late_rate) ?>;
    const remainingLateVal = Math.max(0, 100 - lateVal);
    
    new Chart(ctxLateDonut, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [lateVal, remainingLateVal],
                backgroundColor: [
                    '#f59e0b',
                    '#e2e8f0'
                ],
                borderWidth: 0,
                hoverBackgroundColor: [
                    '#d97706',
                    '#cbd5e1'
                ]
            }]
        },
        options: {
            cutout: '72%',
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            }
        }
    });

    // --- Chart 1E: Absent Miniature Donut Chart ---
    const ctxAbsentDonut = document.getElementById('absentDonutChart').getContext('2d');
    const absentVal = <?= floatval($absent_rate) ?>;
    const remainingAbsentVal = Math.max(0, 100 - absentVal);
    
    new Chart(ctxAbsentDonut, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [absentVal, remainingAbsentVal],
                backgroundColor: [
                    '#ef4444',
                    '#e2e8f0'
                ],
                borderWidth: 0,
                hoverBackgroundColor: [
                    '#dc2626',
                    '#cbd5e1'
                ]
            }]
        },
        options: {
            cutout: '72%',
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            }
        }
    });

    // --- Chart 2: Absences Bar Chart ---
    const ctxAbsences = document.getElementById('absencesChart').getContext('2d');
    
    new Chart(ctxAbsences, {
        type: 'bar',
        data: {
            labels: weekdayLabels,
            datasets: [{
                label: 'Class Absences',
                data: dayDataAbsences,
                backgroundColor: 'rgba(239, 68, 68, 0.85)',
                hoverBackgroundColor: '#ef4444',
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    padding: 12,
                    borderRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return ' Absences: ' + context.raw;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { family: 'Inter', size: 11 }
                    }
                },
                y: {
                    beginAtZero: true,
                    suggestedMax: Math.max(5, maxAbsences) + 1,
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        precision: 0,
                        color: '#64748b',
                        font: { family: 'Inter', size: 11 }
                    }
                }
            }
        }
    });
});
</script>
</body>
</html>