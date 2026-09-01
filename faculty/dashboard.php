<?php
include('../db.php');
include('auth.php');
date_default_timezone_set('Asia/Manila');

$teacher_id = intval($_SESSION['faculty_id'] ?? 0);
$teacher_name = $_SESSION['faculty_name'] ?? $_SESSION['faculty_email'] ?? '';

// Today's weekday name
$dow = date('l');
$dow_esc = $conn->real_escape_string($dow);

// Fetch today's classes
$today_sql = "SELECT id, course, year_level, section, subject, start_time, end_time, room FROM schedules WHERE (teacher_id = $teacher_id OR LOWER(teacher) = LOWER('" . $conn->real_escape_string($teacher_name) . "')) AND day LIKE '%" . $dow_esc . "%' ORDER BY start_time ASC";
$today_res = $conn->query($today_sql);
$today_classes = [];
if ($today_res) {
    while ($r = $today_res->fetch_assoc()) {
        $today_classes[] = $r;
    }
}
$today_count = count($today_classes);

// Live Attendance Today (separated by class)
$now = time();

foreach ($today_classes as &$sched) {
    $schedule_id = intval($sched['id']);
    $stu_sql = "SELECT s.* FROM schedule_students ss JOIN students s ON ss.student_id = s.id WHERE ss.schedule_id = $schedule_id";
    $stu_res = $conn->query($stu_sql);
    
    $p = 0; $a = 0; $l = 0;
    
    if ($stu_res) {
        $start_time = $sched['start_time'];
        $start_ts = strtotime(date('Y-m-d') . ' ' . $start_time);
        
        $sess_q = $conn->query("SELECT * FROM class_sessions WHERE schedule_id = $schedule_id AND session_date = CURDATE()");
        if ($sess_q && $sess_q->num_rows > 0) {
            $sess = $sess_q->fetch_assoc();
            $actual_start_ts = strtotime($sess['actual_start_time']);
            $present_cutoff = $actual_start_ts + ($sess['late_grace_period'] * 60);
            $absent_cutoff = $actual_start_ts + ($sess['absent_grace_period'] * 60);
        } else {
            $present_cutoff = $start_ts + (15 * 60);
            $absent_cutoff = $start_ts + (60 * 60);
        }

        while ($st = $stu_res->fetch_assoc()) {
            $rfid = $st['rfid_uid'];
            $log_q = $conn->query("SELECT time_in FROM logs WHERE rfid_uid='" . $conn->real_escape_string($rfid) . "' AND DATE(time_in)=CURDATE() AND time_in >= '" . date('Y-m-d H:i:s', $start_ts - 10800) . "' ORDER BY time_in ASC LIMIT 1");
            $log = $log_q->fetch_assoc();
            
            if ($log) {
                if (strtotime($log['time_in']) <= $present_cutoff) {
                    $p++;
                } else {
                    $l++;
                }
            } elseif ($now > $absent_cutoff) {
                $a++;
            }
        }
    }
    
    $sched['present'] = $p;
    $sched['absent'] = $a;
    $sched['late'] = $l;
}
unset($sched);

// Weekly Attendance Rate (Last 7 Days) separated by class
$weekly_stats = [];
$safeName = $conn->real_escape_string($teacher_name);
$wsql = "SELECT 
            s.id AS schedule_id,
            s.subject,
            s.start_time,
            SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present, 
            COUNT(*) AS total 
         FROM attendance a 
         JOIN schedules s ON a.schedule_id = s.id 
         WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
           AND (s.teacher_id = $teacher_id OR LOWER(s.teacher) = LOWER('$safeName'))
         GROUP BY s.id, s.subject, s.start_time
         ORDER BY s.start_time ASC";
$wr = $conn->query($wsql);
if ($wr) {
    while ($wrow = $wr->fetch_assoc()) {
        $rate = 0;
        if ((int)$wrow['total'] > 0) {
            $rate = round(((int)$wrow['present'] / (int)$wrow['total']) * 100, 1);
        }
        $weekly_stats[] = [
            'subject' => $wrow['subject'],
            'start_time' => $wrow['start_time'],
            'rate' => $rate
        ];
    }
}


?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Faculty Dashboard</title>
    <link rel="stylesheet" href="faculty_assets/faculty_dashboard.css">
    <link rel="stylesheet" href="faculty_assets/faculty_sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .class-stat-group:last-child {
            border-bottom: none !important;
            margin-bottom: 0 !important;
            padding-bottom: 0 !important;
        }
        .scrollable-card {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 8px;
        }
        .scrollable-card::-webkit-scrollbar { width: 6px; }
        .scrollable-card::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .scrollable-card::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .scrollable-card::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body>
    <div class="app">

        <?php include('navbar.php'); ?>

        <main class="content">
            <div class="header">
                <div class="title">Dashboard</div>
            </div>
            
            <div class="stats-grid">
                <div class="card">
                    <h3>Today's Classes</h3>
                    <div class="big-num"><?= $today_count ?></div>
                    <ul class="list">
                        <?php if (!empty($today_classes)): ?>
                            <?php foreach ($today_classes as $t): ?>
                                <li><?= htmlspecialchars($t['subject']) ?> — <?= date('h:i A', strtotime($t['start_time'])) ?> (<?= htmlspecialchars($t['room'] ?? '-') ?>)</li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>No classes today.</li>
                        <?php endif; ?>
                    </ul>
                </div>



                <div class="card scrollable-card">
                    <h3>Today's Attendance</h3>
                    <?php if (empty($today_classes)): ?>
                        <div class="muted">No classes today.</div>
                    <?php else: ?>
                        <?php foreach ($today_classes as $sched): ?>
                            <div class="class-stat-group" style="margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px;">
                                <h4 style="margin: 0 0 10px; font-size: 0.95rem; color: #334155;"><?= htmlspecialchars($sched['subject']) ?> (<?= date('h:i A', strtotime($sched['start_time'])) ?>)</h4>
                                <div class="att-row">
                                    <div class="att present">Present<br><strong><?= $sched['present'] ?></strong></div>
                                    <div class="att absent">Absent<br><strong><?= $sched['absent'] ?></strong></div>
                                    <div class="att late">Late<br><strong><?= $sched['late'] ?></strong></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="card scrollable-card">
                    <h3>Performance</h3>
                    <div class="muted" style="margin-bottom: 16px;">Weekly Attendance Rate</div>
                    <?php if (empty($weekly_stats)): ?>
                        <div class="muted">Not enough data for weekly rate.</div>
                    <?php else: ?>
                        <?php foreach ($weekly_stats as $stat): ?>
                            <div class="class-stat-group" style="margin-bottom: 20px;">
                                <h4 style="margin: 0 0 8px; font-size: 0.95rem; color: #334155;">
                                    <?= htmlspecialchars($stat['subject']) ?> (<?= date('h:i A', strtotime($stat['start_time'])) ?>)
                                </h4>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div class="big-num" style="font-size: 1.25rem; margin: 0; min-width: 60px;"><?= $stat['rate'] ?>%</div>
                                    <div class="progress-bar-container" style="flex: 1; background:#e2e8f0; border-radius:999px; height:8px;">
                                        <div style="background: var(--accent); height:100%; border-radius:999px; width:<?= $stat['rate'] ?>%;"></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

</body>
</html>