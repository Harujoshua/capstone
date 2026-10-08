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

// Fetch department breakdown for Late Rate (filtered to active academic term)
$dept_late_q = $conn->query("
    SELECT 
        stud.course,
        COUNT(a.id) AS total,
        SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) AS late_count
    FROM attendance a
    JOIN students stud ON a.student_id = stud.id
    JOIN schedules s ON a.schedule_id = s.id
    WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
    GROUP BY stud.course
    ORDER BY (SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) / COUNT(a.id)) DESC
");
$dept_late = [];
if ($dept_late_q) {
    while($row = $dept_late_q->fetch_assoc()) {
        $dept_late[] = $row;
    }
}

// Fetch department breakdown for Absent Rate (filtered to active academic term)
$dept_absent_q = $conn->query("
    SELECT 
        stud.course,
        COUNT(a.id) AS total,
        SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_count
    FROM attendance a
    JOIN students stud ON a.student_id = stud.id
    JOIN schedules s ON a.schedule_id = s.id
    WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
    GROUP BY stud.course
    ORDER BY (SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) / COUNT(a.id)) DESC
");
$dept_absent = [];
if ($dept_absent_q) {
    while($row = $dept_absent_q->fetch_assoc()) {
        $dept_absent[] = $row;
    }
}

// Fetch section-level breakdown for all three rates (course + year_level + section)
$section_stats_q = $conn->query("
    SELECT 
        stud.course,
        stud.year_level,
        stud.section,
        COUNT(a.id) AS total,
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN a.status = 'Late'    THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN a.status = 'Absent'  THEN 1 ELSE 0 END) AS absent_count
    FROM attendance a
    JOIN students stud ON a.student_id = stud.id
    JOIN schedules s ON a.schedule_id = s.id
    WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
    GROUP BY stud.course, stud.year_level, stud.section
    ORDER BY stud.course, stud.year_level, stud.section
");
// Nest by course => array of sections
$section_by_dept = [];
if ($section_stats_q) {
    while ($row = $section_stats_q->fetch_assoc()) {
        $c = $row['course'] ?: 'Unassigned';
        $section_by_dept[$c][] = [
            'year_level'    => $row['year_level'],
            'section'       => $row['section'],
            'total'         => intval($row['total']),
            'present_count' => intval($row['present_count']),
            'late_count'    => intval($row['late_count']),
            'absent_count'  => intval($row['absent_count']),
        ];
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
            <div class="donut-chart-wrapper" style="margin-top: 4px; cursor: pointer;" onclick="openRateStudentsModal('ontime')" title="Click to view On-Time students">
                <canvas id="ontimeDonutChart"></canvas>
                <div class="donut-percentage"><?= $total_records > 0 ? $ontime_rate . '%' : 'N/A' ?></div>
            </div>
            <div class="card-info" style="width: 100%;">
                <div class="rate-card-action-bar">
                    <p class="rate-label" style="margin: 0;">On-Time Rate</p>
                    <a href="javascript:void(0)" onclick="openRateStudentsModal('ontime')" class="view-rate-students-btn ontime" title="View students with on-time attendance">View Students</a>
                </div>
                <p class="rate-sub" style="margin: 2px 0 2px; font-weight: 600; color: #1e293b;"><?= $total_records > 0 ? 'Active Track' : 'No Data Recorded' ?></p>
                <p class="rate-sub"><?= $total_records > 0 ? "On-Time: " . number_format($present_count) . " of " . number_format($total_records) : "No logs recorded" ?></p>
                               <div class="dept-breakdown">
                    <p class="dept-breakdown-title">By Department</p>
                    <?php if (empty($dept_ontime)): ?>
                        <p style="font-size: 0.75rem; color: #94a3b8; margin: 0;">No data</p>
                    <?php else: ?>
                        <div class="dept-scroll">
                            <?php foreach ($dept_ontime as $dept): ?>
                                <?php 
                                    $d_rate = $dept['total'] > 0 ? round(($dept['present_count'] / $dept['total']) * 100, 1) : 0; 
                                    $c_name = empty($dept['course']) ? 'Unassigned' : htmlspecialchars($dept['course']);
                                    $c_key  = empty($dept['course']) ? 'Unassigned' : $dept['course'];
                                ?>
                                <div class="dept-row-btn" onclick="openDeptModal('<?= htmlspecialchars(addslashes($c_key)) ?>', 'ontime')" title="View <?= $c_name ?> sections">
                                    <span class="dept-row-name"><?= $c_name ?></span>
                                    <div class="dept-row-stats">
                                        <div class="dept-row-bar">
                                             <div class="dept-row-fill ontime" style="width: <?= $d_rate ?>%;"></div>
                                        </div>
                                        <span class="dept-row-rate ontime"><?= $d_rate ?>%</span>
                                        <span class="chevron">&#9656;</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Card 3: Late Rate -->
        <div class="rate-card late-donut-card" style="align-items: flex-start;">
            <div class="donut-chart-wrapper" style="margin-top: 4px; cursor: pointer;" onclick="openRateStudentsModal('late')" title="Click to view Late students">
                <canvas id="lateDonutChart"></canvas>
                <div class="donut-percentage"><?= $total_records > 0 ? $late_rate . '%' : 'N/A' ?></div>
            </div>
            <div class="card-info" style="width: 100%;">
                <div class="rate-card-action-bar">
                    <p class="rate-label" style="margin: 0;">Late Rate</p>
                    <a href="javascript:void(0)" onclick="openRateStudentsModal('late')" class="view-rate-students-btn late" title="View students with late attendance">View Students</a>
                </div>
                <p class="rate-sub" style="margin: 2px 0 2px; font-weight: 600; color: #1e293b;"><?= $total_records > 0 ? 'Active Track' : 'No Data Recorded' ?></p>
                <p class="rate-sub"><?= $total_records > 0 ? "Lates: " . number_format($late_count) . " of " . number_format($total_records) : "No logs recorded" ?></p>

                <div class="dept-breakdown">
                    <p class="dept-breakdown-title">By Department</p>
                    <?php if (empty($dept_late)): ?>
                        <p style="font-size: 0.75rem; color: #94a3b8; margin: 0;">No data</p>
                    <?php else: ?>
                        <div class="dept-scroll">
                            <?php foreach ($dept_late as $dept): ?>
                                <?php
                                    $d_rate = $dept['total'] > 0 ? round(($dept['late_count'] / $dept['total']) * 100, 1) : 0;
                                    $c_name = empty($dept['course']) ? 'Unassigned' : htmlspecialchars($dept['course']);
                                    $c_key  = empty($dept['course']) ? 'Unassigned' : $dept['course'];
                                ?>
                                <div class="dept-row-btn" onclick="openDeptModal('<?= htmlspecialchars(addslashes($c_key)) ?>', 'late')" title="View <?= $c_name ?> sections">
                                    <span class="dept-row-name"><?= $c_name ?></span>
                                    <div class="dept-row-stats">
                                        <div class="dept-row-bar">
                                            <div class="dept-row-fill late" style="width: <?= $d_rate ?>%;"></div>
                                        </div>
                                        <span class="dept-row-rate late"><?= $d_rate ?>%</span>
                                        <span class="chevron">&#9656;</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Card 4: Absent Rate -->
        <div class="rate-card absent-donut-card" style="align-items: flex-start;">
            <div class="donut-chart-wrapper" style="margin-top: 4px; cursor: pointer;" onclick="openRateStudentsModal('absent')" title="Click to view Absent students">
                <canvas id="absentDonutChart"></canvas>
                <div class="donut-percentage"><?= $total_records > 0 ? $absent_rate . '%' : 'N/A' ?></div>
            </div>
            <div class="card-info" style="width: 100%;">
                <div class="rate-card-action-bar">
                    <p class="rate-label" style="margin: 0;">Absent Rate</p>
                    <a href="javascript:void(0)" onclick="openRateStudentsModal('absent')" class="view-rate-students-btn absent" title="View students with absent attendance">View Students</a>
                </div>
                <p class="rate-sub" style="margin: 2px 0 2px; font-weight: 600; color: #1e293b;"><?= $total_records > 0 ? 'Active Track' : 'No Data Recorded' ?></p>
                <p class="rate-sub"><?= $total_records > 0 ? "Absents: " . number_format($absent_count) . " of " . number_format($total_records) : "No logs recorded" ?></p>

                <div class="dept-breakdown">
                    <p class="dept-breakdown-title">By Department</p>
                    <?php if (empty($dept_absent)): ?>
                        <p style="font-size: 0.75rem; color: #94a3b8; margin: 0;">No data</p>
                    <?php else: ?>
                        <div class="dept-scroll">
                            <?php foreach ($dept_absent as $dept): ?>
                                <?php
                                    $d_rate = $dept['total'] > 0 ? round(($dept['absent_count'] / $dept['total']) * 100, 1) : 0;
                                    $c_name = empty($dept['course']) ? 'Unassigned' : htmlspecialchars($dept['course']);
                                    $c_key  = empty($dept['course']) ? 'Unassigned' : $dept['course'];
                                ?>
                                <div class="dept-row-btn" onclick="openDeptModal('<?= htmlspecialchars(addslashes($c_key)) ?>', 'absent')" title="View <?= $c_name ?> sections">
                                    <span class="dept-row-name"><?= $c_name ?></span>
                                    <div class="dept-row-stats">
                                        <div class="dept-row-bar">
                                            <div class="dept-row-fill absent" style="width: <?= $d_rate ?>%;"></div>
                                        </div>
                                        <span class="dept-row-rate absent"><?= $d_rate ?>%</span>
                                        <span class="chevron">&#9656;</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
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

<div id="deptDrillModal">
    <div class="dept-drill-box">
        <div class="dept-drill-header">
            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
                <h3 id="ddTitle">Department Breakdown</h3>
                <span id="ddBadge" class="dd-badge"></span>
            </div>
            <button class="dept-drill-close" onclick="closeDeptModal()">&#x2715;</button>
        </div>
        <div class="dd-top-actions">
            <span style="font-size:0.78rem;color:#64748b;font-weight:600;" id="ddDeptSubtitle">Section breakdown by rate</span>
            <button type="button" class="dd-btn-view-students" id="ddViewDeptStudentsBtn" onclick="openRateStudentsModalFromDept()">
                <span id="ddBtnText">View All Department Students</span>
            </button>
        </div>
        <div class="dept-drill-body" id="ddBody"></div>
        <div class="dept-drill-footer">
            <button onclick="closeDeptModal()">Close</button>
        </div>
    </div>
</div>

<!-- Attendance Rate Students Modal -->
<div id="rateStudentsModal" class="custom-modal">
    <div class="custom-modal-content custom-modal-content-wide">
        <!-- Header -->
        <div class="custom-modal-header" style="border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 14px;">
            <div>
                <div style="display:flex; align-items:center; gap: 8px; flex-wrap: wrap;">
                    <h3 id="rsmTitle" style="margin: 0; font-size: 1.25rem;">Rate Students</h3>
                    <span class="active-term-badge" style="font-size: 0.72rem; padding: 2px 8px; margin: 0;">
                        <?= htmlspecialchars($active_school_year) ?> – <?= htmlspecialchars($active_semester) ?>
                    </span>
                </div>
                <p class="custom-modal-subtitle" style="margin: 2px 0 0; font-size: 0.82rem;" id="rsmSubtitle">
                    Viewing students recorded for this status in the active academic term.
                </p>
            </div>
            <span class="close-modal-btn" onclick="closeRateStudentsModal()">&times;</span>
        </div>

        <!-- Status Tabs Bar -->
        <div class="rsm-tabs-bar">
            <button type="button" class="rsm-tab rsm-tab-ontime active" id="rsmTabOntime" data-type="ontime" onclick="switchRateTab('ontime')">
                <span class="rsm-tab-label">On-Time Rate</span>
                <span class="rsm-tab-badge" id="tabBadgeOntime"><?= number_format($present_count) ?></span>
            </button>
            <button type="button" class="rsm-tab rsm-tab-late" id="rsmTabLate" data-type="late" onclick="switchRateTab('late')">
                <span class="rsm-tab-label">Late Rate</span>
                <span class="rsm-tab-badge" id="tabBadgeLate"><?= number_format($late_count) ?></span>
            </button>
            <button type="button" class="rsm-tab rsm-tab-absent" id="rsmTabAbsent" data-type="absent" onclick="switchRateTab('absent')">
                <span class="rsm-tab-label">Absent Rate</span>
                <span class="rsm-tab-badge" id="tabBadgeAbsent"><?= number_format($absent_count) ?></span>
            </button>
        </div>

        <!-- Toolbar: Search & Filters -->
        <div class="rsm-toolbar">
            <div class="rsm-search-box">
                <input type="text" id="rsmSearchInput" placeholder="Search student name, RFID, subject, teacher..." oninput="handleRsmSearch(this.value)">
                <button type="button" id="rsmClearSearch" class="clear-search-btn" onclick="clearRsmSearch()" style="display:none;">&times;</button>
            </div>
            
            <div class="rsm-filter-group">
                <select id="rsmCourseFilter" class="rsm-select" onchange="handleRsmCourseChange(this.value)" title="Filter by Department">
                    <option value="">All Departments</option>
                </select>

                <select id="rsmSectionFilter" class="rsm-select" onchange="handleRsmSectionChange(this.value)" title="Filter by Section">
                    <option value="">All Sections</option>
                </select>

                <!-- View Mode Toggle -->
                <div class="rsm-view-mode-toggle">
                    <button type="button" class="rsm-mode-btn active" id="btnModeLogs" onclick="setRsmViewMode('logs')" title="View detailed session logs">
                        Session Logs
                    </button>
                    <button type="button" class="rsm-mode-btn" id="btnModeSummary" onclick="setRsmViewMode('students')" title="Grouped by student summary">
                        By Student
                    </button>
                </div>

                <button type="button" class="rsm-btn-export" onclick="exportRateStudentsCSV()" title="Export visible list to CSV">
                    Export CSV
                </button>
            </div>
        </div>

        <!-- Table Wrap -->
        <div class="table-wrap" style="max-height: 440px; position: relative; margin-top: 10px; min-height: 220px;">
            <!-- Loading Overlay -->
            <div id="rsmLoading" class="rsm-loading-overlay" style="display:none;">
                <div class="rsm-spinner"></div>
                <span style="font-size:0.85rem; font-weight:600; color:#475569;">Loading students...</span>
            </div>

            <!-- Table -->
            <table class="table" id="rsmTable">
                <thead id="rsmThead">
                    <!-- Injected by JavaScript -->
                </thead>
                <tbody id="rsmTbody">
                    <!-- Injected by JavaScript -->
                </tbody>
            </table>

            <!-- Empty State -->
            <div id="rsmEmpty" class="rsm-empty-state" style="display:none;">
                <h4 id="rsmEmptyTitle">No Records Found</h4>
                <p id="rsmEmptySub">No student records match the selected status and filters.</p>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="custom-modal-footer" style="display:flex; justify-content:space-between; align-items:center; margin-top: 14px; padding-top: 12px; border-top: 1px solid #f1f5f9;">
            <div class="rsm-footer-info" id="rsmFooterInfo">
                Showing 0 records
            </div>
            <button class="custom-modal-close-btn" onclick="closeRateStudentsModal()">Close</button>
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

    // ── Department Drill-Down Modal ──────────────────────────────────────────
    const sectionByDept = <?php echo json_encode($section_by_dept, JSON_UNESCAPED_UNICODE); ?>;

    const rateConfig = {
        ontime: { label: 'On-Time Rate', color: '#10b981', badgeClass: 'ontime', key: 'present_count', status: 'Present' },
        late:   { label: 'Late Rate',    color: '#f59e0b', badgeClass: 'late',   key: 'late_count',    status: 'Late' },
        absent: { label: 'Absent Rate',  color: '#ef4444', badgeClass: 'absent', key: 'absent_count',  status: 'Absent' },
    };

    let currentDeptModalCourse = '';
    let currentDeptModalRateType = 'ontime';

    function openDeptModal(course, rateType) {
        currentDeptModalCourse = course;
        currentDeptModalRateType = rateType;
        const cfg     = rateConfig[rateType] || rateConfig.ontime;
        const sections = sectionByDept[course] || [];

        // Header
        document.getElementById('ddTitle').textContent = (course || 'Unassigned') + ' – Sections';
        const badge = document.getElementById('ddBadge');
        badge.className = 'dd-badge ' + cfg.badgeClass;
        badge.textContent = cfg.label;

        // Top Action Button
        const ddBtnText = document.getElementById('ddBtnText');
        if (ddBtnText) {
            ddBtnText.textContent = 'View All ' + (course && course !== 'Unassigned' ? course + ' ' : '') + cfg.label + ' Students';
        }

        // Body
        const body = document.getElementById('ddBody');
        if (!sections.length) {
            body.innerHTML = '<p class="dd-empty">No section data available for this department.</p>';
        } else {
            // Sort: highest rate first
            const sorted = sections.slice().sort(function(a, b) {
                const ra = a.total > 0 ? (a[cfg.key] / a.total) : 0;
                const rb = b.total > 0 ? (b[cfg.key] / b.total) : 0;
                return rb - ra;
            });
            let html = '';
            sorted.forEach(function(sec) {
                const rate   = sec.total > 0 ? Math.round((sec[cfg.key] / sec.total) * 1000) / 10 : 0;
                const label  = (sec.year_level || '') + (sec.section ? ' – Section ' + sec.section : '');
                const sublbl = sec[cfg.key] + ' of ' + sec.total + ' records';
                const safeC = escHtml(course || '');
                const safeY = escHtml(sec.year_level || '');
                const safeS = escHtml(sec.section || '');
                html += '<div class="dd-section-row" onclick="openRateStudentsModalFromDeptSec(\'' + safeY + '\', \'' + safeS + '\')" style="cursor: pointer;" title="Click to view students in this section">'
                      + '  <div>'
                      + '    <div class="dd-section-label">' + escHtml(label || 'Unspecified') + '</div>'
                      + '    <div class="dd-section-sub">' + escHtml(sublbl) + '</div>'
                      + '  </div>'
                      + '  <div class="dd-bar-wrap">'
                      + '    <div class="dd-bar-track"><div class="dd-bar-fill" style="width:' + rate + '%;background:' + cfg.color + ';"></div></div>'
                      + '    <span class="dd-pct" style="color:' + cfg.color + ';">' + rate + '%</span>'
                      + '    <button type="button" class="dd-sec-view-btn" onclick="event.stopPropagation(); openRateStudentsModalFromDeptSec(\'' + safeY + '\', \'' + safeS + '\')" title="View students in Section ' + safeS + '">Students</button>'
                      + '  </div>'
                      + '</div>';
            });
            body.innerHTML = html;
        }

        document.getElementById('deptDrillModal').classList.add('show');
    }

    function closeDeptModal() {
        document.getElementById('deptDrillModal').classList.remove('show');
    }

    function openRateStudentsModalFromDept() {
        const c = currentDeptModalCourse;
        const r = currentDeptModalRateType;
        closeDeptModal();
        openRateStudentsModal(r, c);
    }

    function openRateStudentsModalFromDeptSec(yearLevel, section) {
        const c = currentDeptModalCourse;
        const r = currentDeptModalRateType;
        closeDeptModal();
        openRateStudentsModal(r, c, yearLevel, section);
    }

    // ── Attendance Rate Students Modal Controller ────────────────────────────
    const rsmState = {
        rateType: 'ontime',
        course: '',
        yearLevel: '',
        section: '',
        search: '',
        viewMode: 'logs', // 'logs' or 'students'
        data: null,
        searchTimeout: null,
        coursesLoaded: false
    };

    const rsmMeta = {
        ontime: { title: 'On-Time Rate Students', badgeClass: 'ontime', color: '#10b981', label: 'On-Time (Present)' },
        late:   { title: 'Late Rate Students',    badgeClass: 'late',   color: '#f59e0b', label: 'Late' },
        absent: { title: 'Absent Rate Students',  badgeClass: 'absent', color: '#ef4444', label: 'Absent' }
    };

    function openRateStudentsModal(rateType, course, yearLevel, section) {
        rsmState.rateType  = rateType || 'ontime';
        rsmState.course    = course || '';
        rsmState.yearLevel = yearLevel || '';
        rsmState.section   = section || '';
        rsmState.search    = '';

        const searchInput = document.getElementById('rsmSearchInput');
        if (searchInput) {
            searchInput.value = '';
            document.getElementById('rsmClearSearch').style.display = 'none';
        }

        document.getElementById('rateStudentsModal').classList.add('show');
        fetchRateStudents();
    }

    function closeRateStudentsModal() {
        document.getElementById('rateStudentsModal').classList.remove('show');
    }

    function switchRateTab(rateType) {
        if (rsmState.rateType === rateType) return;
        rsmState.rateType = rateType;
        fetchRateStudents();
    }

    function setRsmViewMode(mode) {
        if (rsmState.viewMode === mode) return;
        rsmState.viewMode = mode;
        document.getElementById('btnModeLogs').classList.toggle('active', mode === 'logs');
        document.getElementById('btnModeSummary').classList.toggle('active', mode === 'students');
        if (rsmState.data) {
            renderRateStudents();
        }
    }

    function handleRsmSearch(val) {
        clearTimeout(rsmState.searchTimeout);
        document.getElementById('rsmClearSearch').style.display = val.trim() ? 'block' : 'none';
        rsmState.searchTimeout = setTimeout(function() {
            rsmState.search = val.trim();
            fetchRateStudents();
        }, 250);
    }

    function clearRsmSearch() {
        const input = document.getElementById('rsmSearchInput');
        input.value = '';
        document.getElementById('rsmClearSearch').style.display = 'none';
        rsmState.search = '';
        fetchRateStudents();
    }

    function handleRsmCourseChange(val) {
        rsmState.course = val;
        fetchRateStudents();
    }

    function handleRsmSectionChange(val) {
        rsmState.section = val;
        fetchRateStudents();
    }

    function resetRsmFilters() {
        rsmState.course = '';
        rsmState.yearLevel = '';
        rsmState.section = '';
        rsmState.search = '';
        const searchInput = document.getElementById('rsmSearchInput');
        if (searchInput) searchInput.value = '';
        document.getElementById('rsmClearSearch').style.display = 'none';
        const cSel = document.getElementById('rsmCourseFilter');
        if (cSel) cSel.value = '';
        const sSel = document.getElementById('rsmSectionFilter');
        if (sSel) sSel.value = '';
        fetchRateStudents();
    }

    async function fetchRateStudents() {
        const meta = rsmMeta[rsmState.rateType] || rsmMeta.ontime;

        // Update tab active states
        ['ontime', 'late', 'absent'].forEach(function(t) {
            const btn = document.getElementById('rsmTab' + t.charAt(0).toUpperCase() + t.slice(1));
            if (btn) btn.classList.toggle('active', t === rsmState.rateType);
        });

        // Update Header
        const rsmTitle = document.getElementById('rsmTitle');
        if (rsmTitle) rsmTitle.textContent = meta.title;

        // Show loading
        const loader = document.getElementById('rsmLoading');
        if (loader) loader.style.display = 'flex';
        const emptyBox = document.getElementById('rsmEmpty');
        if (emptyBox) emptyBox.style.display = 'none';

        const params = new URLSearchParams({
            rate_type:  rsmState.rateType,
            course:     rsmState.course,
            year_level: rsmState.yearLevel,
            section:    rsmState.section,
            search:     rsmState.search
        });

        try {
            const res = await fetch('get_rate_students.php?' + params.toString());
            const data = await res.json();

            if (!data.success) {
                console.error('Failed to load rate students:', data.error);
                if (loader) loader.style.display = 'none';
                return;
            }

            rsmState.data = data;

            // Update tab badges with current totals
            if (data.counts) {
                const bOntime = document.getElementById('tabBadgeOntime');
                if (bOntime) bOntime.textContent = Number(data.counts.ontime).toLocaleString();
                const bLate = document.getElementById('tabBadgeLate');
                if (bLate) bLate.textContent = Number(data.counts.late).toLocaleString();
                const bAbsent = document.getElementById('tabBadgeAbsent');
                if (bAbsent) bAbsent.textContent = Number(data.counts.absent).toLocaleString();
            }

            // Populate course & section filters if needed
            populateRsmFilters(data);

            renderRateStudents();
        } catch (err) {
            console.error('Error fetching rate students:', err);
        } finally {
            if (loader) loader.style.display = 'none';
        }
    }

    function populateRsmFilters(data) {
        // Course dropdown
        const cSel = document.getElementById('rsmCourseFilter');
        if (cSel && (!rsmState.coursesLoaded || cSel.options.length <= 1)) {
            const cur = rsmState.course;
            cSel.innerHTML = '<option value="">All Departments</option>';
            if (data.courses_list && data.courses_list.length) {
                data.courses_list.forEach(function(c) {
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c;
                    if (c === cur) opt.selected = true;
                    cSel.appendChild(opt);
                });
            }
            rsmState.coursesLoaded = true;
        } else if (cSel) {
            cSel.value = rsmState.course || '';
        }

        // Section dropdown
        const sSel = document.getElementById('rsmSectionFilter');
        if (sSel && sSel.options.length <= 1 && data.sections_list) {
            const curS = rsmState.section;
            sSel.innerHTML = '<option value="">All Sections</option>';
            data.sections_list.forEach(function(s) {
                const opt = document.createElement('option');
                opt.value = s;
                opt.textContent = 'Section ' + s;
                if (s === curS) opt.selected = true;
                sSel.appendChild(opt);
            });
        } else if (sSel) {
            sSel.value = rsmState.section || '';
        }
    }

    function renderRateStudents() {
        const thead = document.getElementById('rsmThead');
        const tbody = document.getElementById('rsmTbody');
        const emptyBox = document.getElementById('rsmEmpty');
        const footerInfo = document.getElementById('rsmFooterInfo');
        const data = rsmState.data;

        if (!data) return;

        const isLogsMode = (rsmState.viewMode === 'logs');
        const items = isLogsMode ? data.records : data.student_summaries;
        const totalItems = items ? items.length : 0;

        if (totalItems === 0) {
            thead.innerHTML = '';
            tbody.innerHTML = '';
            if (emptyBox) {
                const meta = rsmMeta[rsmState.rateType] || rsmMeta.ontime;
                emptyBox.style.display = 'block';
                document.getElementById('rsmEmptyTitle').textContent = 'No ' + meta.label + ' Students Found';
                document.getElementById('rsmEmptySub').textContent = rsmState.search || rsmState.course || rsmState.section
                    ? 'No records match the current filter or search criteria.'
                    : 'No attendance records logged with ' + meta.label + ' status for the current academic term.';
            }
            if (footerInfo) footerInfo.textContent = 'Showing 0 records';
            return;
        }

        if (emptyBox) emptyBox.style.display = 'none';

        // Render Table Headers
        if (isLogsMode) {
            thead.innerHTML = '<tr>'
                + '  <th>Student</th>'
                + '  <th>Course & Section</th>'
                + '  <th>Class / Subject</th>'
                + '  <th>Date & Time Logged</th>'
                + '  <th class="text-right">Status</th>'
                + '</tr>';
        } else {
            thead.innerHTML = '<tr>'
                + '  <th>Student</th>'
                + '  <th>Course & Section</th>'
                + '  <th>Classes Logged</th>'
                + '  <th>Latest Record</th>'
                + '  <th class="text-right">Status</th>'
                + '</tr>';
        }

        // Render Rows
        let html = '';
        const meta = rsmMeta[rsmState.rateType] || rsmMeta.ontime;

        items.forEach(function(rec) {
            const initial = (rec.student_name || 'U').charAt(0).toUpperCase();
            const avatarHtml = rec.photo
                ? '<img src="../uploads/students/' + escHtml(rec.photo) + '" class="student-avatar" style="object-fit:cover;" onerror="this.outerHTML=\'<div class=\\\'student-avatar\\\'>' + initial + '</div>\'">'
                : '<div class="student-avatar">' + initial + '</div>';

            const statusClass = (rec.status === 'Present' ? 'ontime' : (rec.status === 'Late' ? 'late' : 'absent'));
            const statusLabel = (rec.status === 'Present' ? 'On-Time' : (rec.status === 'Late' ? 'Late' : 'Absent'));

            const courseSectionBadge = '<div class="class-badge">'
                + '  <span class="course">' + escHtml(rec.course || 'N/A') + '</span>'
                + '  <span class="divider"></span>'
                + '  <span class="level">' + escHtml((rec.year_level || '') + (rec.section ? ' - ' + rec.section : '')) + '</span>'
                + '</div>';

            if (isLogsMode) {
                const schedParts = [];
                if (rec.teacher && rec.teacher !== 'N/A') schedParts.push(rec.teacher);
                if (rec.room && rec.room !== 'N/A') schedParts.push('Rm ' + rec.room);
                if (rec.schedule_time) schedParts.push(rec.schedule_time);
                const schedDetail = schedParts.join(' • ');

                html += '<tr>'
                    + '  <td>'
                    + '    <div class="student-name-cell">'
                    +        avatarHtml
                    + '      <div>'
                    + '        <div class="student-name-text">' + escHtml(rec.student_name) + '</div>'
                    + '        <div style="font-size:0.75rem; color:#64748b; font-family:monospace;">' + escHtml(rec.rfid_uid || '') + '</div>'
                    + '      </div>'
                    + '    </div>'
                    + '  </td>'
                    + '  <td>' + courseSectionBadge + '</td>'
                    + '  <td>'
                    + '    <div style="font-weight:600; color:#1e293b; font-size:0.86rem;">' + escHtml(rec.subject || 'General Class') + '</div>'
                    + '    <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">' + escHtml(schedDetail) + '</div>'
                    + '  </td>'
                    + '  <td>'
                    + '    <div style="font-weight:600; color:#1e293b; font-size:0.85rem;">' + escHtml(rec.formatted_date) + '</div>'
                    + '    <div style="font-size:0.74rem; color:#64748b; margin-top:1px;">' + escHtml(rec.formatted_time) + '</div>'
                    + '  </td>'
                    + '  <td class="text-right">'
                    + '    <span class="badge-status ' + statusClass + '">' + statusLabel + '</span>'
                    + '  </td>'
                    + '</tr>';
            } else {
                // Grouped by Student view
                const occText = (rsmState.rateType === 'ontime' ? 'on-time classes' : (rsmState.rateType === 'late' ? 'late classes' : 'absences'));
                html += '<tr>'
                    + '  <td>'
                    + '    <div class="student-name-cell">'
                    +        avatarHtml
                    + '      <div>'
                    + '        <div class="student-name-text">' + escHtml(rec.student_name) + '</div>'
                    + '        <div style="font-size:0.75rem; color:#64748b; font-family:monospace;">' + escHtml(rec.rfid_uid || '') + '</div>'
                    + '      </div>'
                    + '    </div>'
                    + '  </td>'
                    + '  <td>' + courseSectionBadge + '</td>'
                    + '  <td>'
                    + '    <strong style="font-size:0.96rem; color:#1e293b;">' + rec.count + '</strong> '
                    + '    <span style="font-size:0.78rem; color:#64748b;">' + occText + '</span>'
                    + '  </td>'
                    + '  <td>'
                    + '    <div style="font-weight:600; color:#1e293b; font-size:0.86rem;">' + escHtml(rec.latest_subject || 'General Class') + '</div>'
                    + '    <div style="font-size:0.74rem; color:#64748b; margin-top:1px;">' + escHtml(rec.latest_date) + ' • ' + escHtml(rec.latest_time) + '</div>'
                    + '  </td>'
                    + '  <td class="text-right">'
                    + '    <span class="badge-status ' + statusClass + '">' + statusLabel + '</span>'
                    + '  </td>'
                    + '</tr>';
            }
        });

        tbody.innerHTML = html;

        if (footerInfo) {
            const uniqueTxt = (data.unique_students > 0 ? ' (' + data.unique_students + ' unique student' + (data.unique_students > 1 ? 's' : '') + ')' : '');
            footerInfo.textContent = 'Showing ' + totalItems + ' ' + (isLogsMode ? 'attendance record' + (totalItems > 1 ? 's' : '') : 'student' + (totalItems > 1 ? 's' : '')) + uniqueTxt;
        }
    }

    function exportRateStudentsCSV() {
        const data = rsmState.data;
        if (!data || !data.records || !data.records.length) {
            alert('No records available to export.');
            return;
        }

        const isLogsMode = (rsmState.viewMode === 'logs');
        let csv = '';

        if (isLogsMode) {
            csv = '\uFEFF"Student Name","RFID UID","Department","Year Level","Section","Subject","Teacher","Room","Attendance Date","Time Logged","Status"\n';
            data.records.forEach(function(r) {
                const row = [
                    r.student_name || '',
                    r.rfid_uid || '',
                    r.course || '',
                    r.year_level || '',
                    r.section || '',
                    r.subject || '',
                    r.teacher || '',
                    r.room || '',
                    r.formatted_date || '',
                    r.formatted_time || '',
                    r.status || ''
                ].map(function(val) { return '"' + String(val).replace(/"/g, '""') + '"'; });
                csv += row.join(',') + '\n';
            });
        } else {
            csv = '\uFEFF"Student Name","RFID UID","Department","Year Level","Section","Total Occurrences","Latest Subject","Latest Date","Latest Time","Status"\n';
            data.student_summaries.forEach(function(r) {
                const row = [
                    r.student_name || '',
                    r.rfid_uid || '',
                    r.course || '',
                    r.year_level || '',
                    r.section || '',
                    r.count || 0,
                    r.latest_subject || '',
                    r.latest_date || '',
                    r.latest_time || '',
                    r.status || ''
                ].map(function(val) { return '"' + String(val).replace(/"/g, '""') + '"'; });
                csv += row.join(',') + '\n';
            });
        }

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const dateStr = new Date().toISOString().slice(0,10);
        a.href = url;
        a.download = 'NEUST_' + rsmState.rateType + '_students_' + dateStr + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Modal close on backdrop and Escape key
    window.addEventListener('click', function(event) {
        var riskModal = document.getElementById('atRiskModal');
        if (event.target === riskModal) closeRiskModal();

        var deptModal = document.getElementById('deptDrillModal');
        if (event.target === deptModal) closeDeptModal();

        var rateModal = document.getElementById('rateStudentsModal');
        if (event.target === rateModal) closeRateStudentsModal();
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeRateStudentsModal();
            closeDeptModal();
            closeRiskModal();
        }
    });

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    // ────────────────────────────────────────────────────────────────────────

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
    
    const trendsChart = new Chart(ctxTrends, {
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
                        display: (window.innerWidth > 640),
                        text: 'On-Time & Late Counts',
                        color: '#475569',
                        font: { family: 'Inter', size: 10, weight: '700' }
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
                        display: (window.innerWidth > 640),
                        text: 'Absence Counts',
                        color: '#f43f5e',
                        font: { family: 'Inter', size: 10, weight: '700' }
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

    window.addEventListener('resize', function() {
        if (trendsChart && trendsChart.options && trendsChart.options.scales && trendsChart.options.scales.y) {
            const showTitle = window.innerWidth > 640;
            if (trendsChart.options.scales.y.title.display !== showTitle) {
                trendsChart.options.scales.y.title.display = showTitle;
                trendsChart.options.scales.y1.title.display = showTitle;
                trendsChart.update();
            }
        }
    });
});
</script>
</body>
</html>