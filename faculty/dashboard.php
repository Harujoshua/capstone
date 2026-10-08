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
    
    $p = 0; $a = 0; $l = 0; $e = 0;
    $student_statuses = [];
    
    if ($stu_res) {
        $start_time = $sched['start_time'];
        $start_ts = strtotime(date('Y-m-d') . ' ' . $start_time);
        
        $end_ts = strtotime(date('Y-m-d') . ' ' . $sched['end_time']);
        $sess_q = $conn->query("SELECT * FROM class_sessions WHERE schedule_id = $schedule_id AND session_date = CURDATE()");
        if ($sess_q && $sess_q->num_rows > 0) {
            $sess = $sess_q->fetch_assoc();
            $actual_start_ts = strtotime($sess['actual_start_time']);
            $present_cutoff = $actual_start_ts + ($sess['late_grace_period'] * 60);
            $absent_cutoff = $actual_start_ts + ($sess['absent_grace_period'] * 60);
        } else {
            $present_cutoff = $start_ts + (15 * 60);
            $absent_cutoff = $end_ts;
        }

        while ($st = $stu_res->fetch_assoc()) {
            $rfid = $st['rfid_uid'];
            $sid  = intval($st['id']);

            // Excuse check
            $ex_q = $conn->query("SELECT status FROM excuse_letters WHERE student_id = $sid AND schedule_id = $schedule_id AND date_absent = CURDATE() ORDER BY id DESC LIMIT 1");
            $ex   = $ex_q ? $ex_q->fetch_assoc() : null;
            $ex_status = $ex ? $ex['status'] : null;

            $log_q = $conn->query("SELECT time_in FROM logs WHERE rfid_uid='" . $conn->real_escape_string($rfid) . "' AND DATE(time_in)=CURDATE() AND time_in >= '" . date('Y-m-d H:i:s', $start_ts - 10800) . "' ORDER BY time_in ASC LIMIT 1");
            $log = $log_q->fetch_assoc();
            
            if ($log) {
                $tap_ts = strtotime($log['time_in']);
                if ($tap_ts <= $present_cutoff) {
                    $final_status = 'Present';
                    $p++;
                } elseif ($tap_ts <= $absent_cutoff) {
                    $final_status = 'Late';
                    $l++;
                } else {
                    if ($ex_status === 'Approved') {
                        $final_status = 'Excused';
                        $e++;
                    } else {
                        $final_status = 'Absent';
                        $a++;
                    }
                }
            } elseif ($now > $absent_cutoff) {
                if ($ex_status === 'Approved') {
                    $final_status = 'Excused';
                    $e++;
                } else {
                    $final_status = 'Absent';
                    $a++;
                }
            } else {
                $final_status = 'Pending';
            }

            $student_statuses[] = [
                'name'          => $st['name'],
                'final_status'  => $final_status,
                'excuse_status' => $ex_status,
                'tapped'        => (bool)$log,
            ];
        }
    }
    
    $sched['present'] = $p;
    $sched['absent']  = $a;
    $sched['late']    = $l;
    $sched['excused'] = $e;
    $sched['student_statuses'] = $student_statuses;
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


// Render the 3 dashboard stat cards
function render_faculty_dashboard_stats($today_count, $today_classes, $weekly_stats) {
?>
    <div class="card" id="cardTodayClasses">
        <h3>Today's Classes</h3>
        <div class="big-num"><?= $today_count ?></div>
        <ul class="list">
            <?php if (!empty($today_classes)): ?>
                <?php foreach ($today_classes as $t): ?>
                    <li><?= htmlspecialchars($t['subject']) ?> &mdash; <?= date('h:i A', strtotime($t['start_time'])) ?> (<?= htmlspecialchars($t['room'] ?? '-') ?>)</li>
                <?php endforeach; ?>
            <?php else: ?>
                <li style="color: #64748b;">No classes today.</li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="card scrollable-card" id="cardTodayAttendance">
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
                        <div class="att" style="background:#e0f2fe; color:#0369a1; border-radius:8px; padding:8px 12px; text-align:center;">Excused<br><strong><?= $sched['excused'] ?></strong></div>
                    </div>
                    <?php if (!empty($sched['student_statuses'])): ?>
                    <div style="margin-top: 12px;">
                        <div style="font-size:0.78rem; font-weight:600; color:#64748b; margin-bottom:6px;">STUDENT BREAKDOWN</div>
                        <?php foreach ($sched['student_statuses'] as $ss): ?>
                            <?php
                                $sc = strtolower($ss['final_status']);
                                $bg = match($sc) {
                                    'present' => '#d1fae5', 'late' => '#fef3c7',
                                    'absent'  => '#fee2e2', 'excused' => '#e0f2fe',
                                    default   => '#f1f5f9'
                                };
                                $fc = match($sc) {
                                    'present' => '#065f46', 'late' => '#92400e',
                                    'absent'  => '#7f1d1d', 'excused' => '#0369a1',
                                    default   => '#64748b'
                                };
                            ?>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:4px 0; font-size:0.82rem; border-bottom:1px solid #f1f5f9;">
                                <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#334155;"><?= htmlspecialchars($ss['name']) ?></span>
                                <div style="display:flex; gap:5px; align-items:center; flex-shrink:0; margin-left:8px;">
                                    <?php if ($ss['tapped']): ?>
                                        <span style="font-size:0.72rem; color:#065f46;" title="RFID Tapped"><i class="fa-solid fa-id-card"></i></span>
                                    <?php else: ?>
                                        <span style="font-size:0.72rem; color:#d1d5db;" title="No RFID Tap"><i class="fa-solid fa-ban"></i></span>
                                    <?php endif; ?>
                                    <?php if ($ss['excuse_status']): ?>
                                        <?php
                                            $ec = strtolower($ss['excuse_status']);
                                            $ebg = match($ec) { 'approved'=>'#d1fae5','pending'=>'#fef3c7','rejected'=>'#fee2e2', default=>'#f1f5f9' };
                                            $efc = match($ec) { 'approved'=>'#065f46','pending'=>'#92400e','rejected'=>'#7f1d1d', default=>'#64748b' };
                                        ?>
                                        <span style="font-size:0.72rem; padding:1px 6px; border-radius:99px; background:<?= $ebg ?>; color:<?= $efc ?>;">Excuse: <?= htmlspecialchars($ss['excuse_status']) ?></span>
                                    <?php endif; ?>
                                    <span style="font-size:0.75rem; font-weight:700; padding:2px 8px; border-radius:99px; background:<?= $bg ?>; color:<?= $fc ?>;"> <?= htmlspecialchars($ss['final_status']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div style="margin-top:8px; text-align:right;">
                            <a href="excuse_letters.php" style="font-size:0.78rem; color:var(--accent); text-decoration:none; font-weight:600;"><i class="fa-solid fa-file-medical"></i> Manage Excuses</a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card scrollable-card" id="cardPerformance">
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
<?php
}

// If this is an AJAX auto-refresh request, respond only with the grid cards
if (isset($_GET['ajax_refresh'])) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    render_faculty_dashboard_stats($today_count, $today_classes, $weekly_stats);
    exit;
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
            
            <div class="stats-grid" id="dashboardStatsGrid">
                <?php render_faculty_dashboard_stats($today_count, $today_classes, $weekly_stats); ?>
            </div>
        </main>
    </div>

    <!-- Auto-Refresh Script -->
    <script>
    (function() {
        'use strict';

        const REFRESH_INTERVAL_MS = 4000; // Refresh every 4 seconds
        const statsGrid = document.getElementById('dashboardStatsGrid');

        if (!statsGrid) return;

        let isFetching = false;
        let timerId = null;

        function shouldSkipRefresh() {
            // 1. Tab is not active/visible
            if (document.hidden) return true;

            // 2. A modal is open (e.g. Session Timeout, Logout Modal)
            if (document.querySelector('.modal.show, #sessionTimeoutModal.show, #logoutModal.show')) {
                return true;
            }

            // 3. User is actively selecting text inside the stats grid
            const sel = window.getSelection();
            if (sel && sel.toString().trim().length > 0 && statsGrid.contains(sel.anchorNode)) {
                return true;
            }

            return false;
        }

        async function performAutoRefresh() {
            if (isFetching || shouldSkipRefresh()) return;

            isFetching = true;

            try {
                const refreshUrl = 'dashboard.php?ajax_refresh=1&_t=' + Date.now();
                const response = await fetch(refreshUrl, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Background-Refresh': '1'
                    },
                    cache: 'no-store'
                });

                if (response.status === 401) {
                    // Session expired; redirect cleanly
                    window.location.href = 'login.php?expired=1';
                    return;
                }

                if (!response.ok) {
                    throw new Error('HTTP status ' + response.status);
                }

                const rawHtml = await response.text();

                // Check if response contains the grid cards
                let newContent = rawHtml;
                if (rawHtml.includes('id="dashboardStatsGrid"') || rawHtml.includes('class="stats-grid"')) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(rawHtml, 'text/html');
                    const parsedGrid = doc.getElementById('dashboardStatsGrid') || doc.querySelector('.stats-grid');
                    if (parsedGrid) {
                        newContent = parsedGrid.innerHTML;
                    }
                }

                // Only update DOM if the rendered content has actually changed
                if (newContent && statsGrid.innerHTML.trim() !== newContent.trim()) {
                    // Record scroll positions of any scrollable cards
                    const attCard = document.getElementById('cardTodayAttendance');
                    const perfCard = document.getElementById('cardPerformance');
                    const attScrollTop = attCard ? attCard.scrollTop : 0;
                    const perfScrollTop = perfCard ? perfCard.scrollTop : 0;

                    // Update container
                    statsGrid.innerHTML = newContent;

                    // Restore scroll positions
                    const newAttCard = document.getElementById('cardTodayAttendance');
                    if (newAttCard && attScrollTop > 0) {
                        newAttCard.scrollTop = attScrollTop;
                    }
                    const newPerfCard = document.getElementById('cardPerformance');
                    if (newPerfCard && perfScrollTop > 0) {
                        newPerfCard.scrollTop = perfScrollTop;
                    }
                }

            } catch (err) {
                console.warn('[Dashboard Auto-Refresh] Polling error:', err);
            } finally {
                isFetching = false;
            }
        }

        // Start periodic interval
        timerId = setInterval(performAutoRefresh, REFRESH_INTERVAL_MS);

        // If tab comes back into view, refresh immediately
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                performAutoRefresh();
            }
        });
    })();
    </script>
</body>
</html>