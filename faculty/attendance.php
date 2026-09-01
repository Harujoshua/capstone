<?php
include('../db.php');
include('auth.php');
date_default_timezone_set('Asia/Manila');

$teacher_id = intval($_SESSION['faculty_id'] ?? 0);
$teacher_name = $_SESSION['faculty_name'] ?? $_SESSION['faculty_email'] ?? '';

// use full weekday name to match schedules stored with full names (e.g. Monday)
$dow = date('l');
$dow_esc = $conn->real_escape_string($dow);

// fetch today's classes for this faculty
$today_sql = "SELECT id, course, year_level, section, subject, start_time, end_time, room FROM schedules WHERE (teacher_id = $teacher_id OR LOWER(teacher) = LOWER('" . $conn->real_escape_string($teacher_name) . "')) AND day LIKE '%" . $dow_esc . "%' ORDER BY start_time ASC";
$today_res = $conn->query($today_sql);

$today_classes = [];
if ($today_res) {
	while ($r = $today_res->fetch_assoc())
		$today_classes[] = $r;
}

// fetch all schedules for dropdowns
$schedules_sql = "SELECT id, course, year_level, section, subject, start_time, day FROM schedules WHERE (teacher_id = $teacher_id OR LOWER(teacher) = LOWER('" . $conn->real_escape_string($teacher_name) . "')) ORDER BY FIELD(day,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC";
$scheds_res = $conn->query($schedules_sql);
$schedules = [];
if ($scheds_res) {
	while ($r = $scheds_res->fetch_assoc())
		$schedules[] = $r;
}

$selected_date = date('Y-m-d');
$sel_dow = date('l', strtotime($selected_date));

$schedule_id_raw = $_POST['today_class_id'] ?? null;
$has_selected = ($schedule_id_raw !== null && $schedule_id_raw !== '');
$schedule_id = $has_selected ? intval($schedule_id_raw) : false;

// Auto-select today's schedule if none chosen
if (!$has_selected) {
    foreach ($schedules as $s) {
        if (strcasecmp($s['day'], $sel_dow) === 0 || strpos($s['day'], $sel_dow) !== false) {
            $schedule_id = intval($s['id']);
            $has_selected = true;
            break;
        }
    }
}

// Handle Start Class action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_class') {
    $sid = intval($_POST['schedule_id']);
    $late_grace = intval($_POST['late_grace_period']);
    $absent_grace = intval($_POST['absent_grace_period']);
    $actual_start = date('Y-m-d H:i:s');
    
    $ins_sql = "INSERT IGNORE INTO class_sessions (schedule_id, session_date, actual_start_time, late_grace_period, absent_grace_period) VALUES ($sid, '$selected_date', '$actual_start', $late_grace, $absent_grace)";
    $conn->query($ins_sql);
}

$computed = [];
$session_active = false;
$session_data = null;

if ($schedule_id !== false) {
    $sres = $conn->query("SELECT * FROM schedules WHERE id=" . $schedule_id . " LIMIT 1");
    if ($sres && $sres->num_rows > 0) {
        $sched = $sres->fetch_assoc();
        
        $sess_q = $conn->query("SELECT * FROM class_sessions WHERE schedule_id = $schedule_id AND session_date = '$selected_date'");
        if ($sess_q && $sess_q->num_rows > 0) {
            $session_active = true;
            $session_data = $sess_q->fetch_assoc();
        }
        
        // fetch students registered to this schedule
        $stu_sql = "SELECT s.* FROM schedule_students ss JOIN students s ON ss.student_id = s.id WHERE ss.schedule_id = " . intval($schedule_id) . " ORDER BY s.name ASC";
        $stu_res = $conn->query($stu_sql);

        $start_time = $sched['start_time'];
        $end_time = $sched['end_time'];
        $start_ts = strtotime($selected_date . ' ' . $start_time);
        $end_ts = strtotime($selected_date . ' ' . $end_time);
        
        if ($session_active) {
            $actual_start_ts = strtotime($session_data['actual_start_time']);
            $present_cutoff = $actual_start_ts + ($session_data['late_grace_period'] * 60);
            $absent_threshold = $actual_start_ts + ($session_data['absent_grace_period'] * 60);
        } else {
            $present_cutoff = $start_ts + (15 * 60); // 15 minutes grace period
            $absent_threshold = $end_ts; // Absent when class ends
        }
        $now = time();

        if ($stu_res && $stu_res->num_rows > 0) {
            while ($st = $stu_res->fetch_assoc()) {
                $student_id = intval($st['id']);
                $rfid = $st['rfid_uid'];
                $name = $st['name'];

                // Check existing record in attendance table
                $att_q = $conn->query("SELECT * FROM attendance WHERE schedule_id = $schedule_id AND student_id = $student_id AND attendance_date = '$selected_date'");
                $existing = $att_q->fetch_assoc();

                if ($existing) {
                    $status = $existing['status'];
                    $time_in = $existing['time_logged'];
                } else {
                    // On-the-fly calculation if record doesn't exist
                    $log_q = $conn->query("SELECT time_in FROM logs WHERE rfid_uid='" . $conn->real_escape_string($rfid) . "' AND DATE(time_in)='$selected_date' AND time_in >= '" . date('Y-m-d H:i:s', $start_ts - 10800) . "' ORDER BY time_in ASC LIMIT 1");
                    $log = $log_q->fetch_assoc();
                    
                    $time_in = $log ? $log['time_in'] : null;
                    if ($time_in) {
                        $status = (strtotime($time_in) <= $present_cutoff) ? 'Present' : 'Late';
                    } else {
                        if ($now > $absent_threshold) {
                            $status = 'Absent';
                        } else {
                            $status = 'Pending';
                        }
                    }
                    
                    // Persist if not pending
                    if ($status !== 'Pending') {
                        $conn->query("INSERT INTO attendance (schedule_id, student_id, name, attendance_date, status, time_logged) VALUES ($schedule_id, $student_id, '" . $conn->real_escape_string($name) . "', '$selected_date', '$status', " . ($time_in ? "'$time_in'" : "NULL") . ")");
                    }
                }

                $computed[] = [
                    'student' => $st,
                    'time_in' => $time_in,
                    'status' => $status
                ];
            }
        }
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Faculty - Attendance</title>
    <link rel="stylesheet" href="faculty_assets/faculty_attendance.css">
    <link rel="stylesheet" href="faculty_assets/faculty_sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Grace Period Controls */
        .grace-controls-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin: 14px 0 18px;
        }
        .btn-grace {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border: none;
            border-radius: 9px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity .15s, transform .1s, box-shadow .15s;
            white-space: nowrap;
            text-decoration: none;
        }
        .btn-grace:hover { opacity: .88; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .btn-grace:active { transform: scale(.97); }
        .btn-create-grace { background: #eff6ff; color: #1d4ed8; border: 1.5px solid #bfdbfe; }
        .grace-badge-active {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: #dcfce7;
            color: #15803d;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
        }
        .grace-timers {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            align-items: center;
        }
        .grace-timer-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            background: #fff7ed;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .grace-timer-chip.absent-chip {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        .grace-timer-chip .tv {
            font-weight: 800;
            font-size: 0.9rem;
        }
        /* Modal */
        .grace-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .grace-modal-overlay.show { display: flex; }
        .grace-modal {
            background: #fff;
            border-radius: 16px;
            padding: 30px 34px;
            max-width: 400px;
            width: 92%;
            box-shadow: 0 24px 60px rgba(0,0,0,.22);
        }
        .grace-modal h4 {
            margin: 0 0 4px;
            font-size: 1.1rem;
            color: #0f172a;
        }
        .grace-modal p.sub { font-size: 0.84rem; color: #64748b; margin: 0 0 20px; }
        .grace-modal label {
            display: block;
            font-size: 0.82rem;
            color: #475569;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .grace-modal input[type=number] {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #cbd5e1;
            border-radius: 9px;
            font-size: 0.95rem;
            margin-bottom: 15px;
            box-sizing: border-box;
            transition: border-color .2s;
        }
        .grace-modal input[type=number]:focus { outline: none; border-color: var(--primary, #6366f1); }
        .grace-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }
        .btn-confirm-start {
            padding: 10px 24px;
            background: var(--primary, #6366f1);
            color: #fff;
            border: none;
            border-radius: 9px;
            font-weight: 700;
            font-size: 0.92rem;
            cursor: pointer;
        }
        .btn-cancel-modal {
            padding: 10px 18px;
            background: #f1f5f9;
            color: #475569;
            border: none;
            border-radius: 9px;
            font-weight: 600;
            font-size: 0.92rem;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="app">

        <?php include('navbar.php'); ?>

        <main class="content">
            <div class="header">
                <div class="title">Attendance (<?= date('F j, Y') ?>)</div>
            </div>

            <?php if (!empty($today_classes)): ?>
                <div class="filters">
                    <form method="POST" class="filters-form">
                        <label for="today_class_id" class="filters-label">Select Class:</label>
                        <select name="today_class_id" id="today_class_id" class="filters-select" onchange="this.form.submit()">
                            <option value="">-- Select a Class --</option>
                            <?php foreach ($today_classes as $class): ?>
                                <option value="<?= intval($class['id']) ?>" <?= ($schedule_id !== false && $schedule_id === intval($class['id'])) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['subject']) ?> — <?= htmlspecialchars(date('h:i A', strtotime($class['start_time']))) ?>
                                    (<?= htmlspecialchars($class['course'] . ' ' . $class['year_level'] . ' ' . $class['section']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($schedule_id !== false && isset($sched)): ?>
				<div class="card">
					<h3><?= htmlspecialchars($sched['subject']) ?> (<?= htmlspecialchars($sched['course'] . ' ' . $sched['year_level'] . ' ' . $sched['section']) ?>)</h3>
					<p class="muted">Schedule: <?= htmlspecialchars($sched['day']) ?> <?= htmlspecialchars(date('h:i A', strtotime($sched['start_time']))) ?> - <?= htmlspecialchars(date('h:i A', strtotime($sched['end_time']))) ?></p>
					
                    <?php
                    $now_ts = time();
                    if ($session_active):
                        $late_end_ts   = strtotime($session_data['actual_start_time']) + $session_data['late_grace_period'] * 60;
                        $absent_end_ts = strtotime($session_data['actual_start_time']) + $session_data['absent_grace_period'] * 60;
                        $late_rem   = max(0, $late_end_ts - $now_ts);
                        $absent_rem = max(0, $absent_end_ts - $now_ts);
                    ?>
                        <div class="grace-controls-bar">
                            <span class="grace-badge-active">
                                <i class="fa-solid fa-circle-check"></i>
                                Started <?= date('h:i A', strtotime($session_data['actual_start_time'])) ?>
                            </span>
                            <div class="grace-timers">
                                <div class="grace-timer-chip">
                                    <i class="fa-solid fa-hourglass-half"></i>
                                    Late Grace: <span class="tv" id="late-timer" data-end="<?= $late_end_ts ?>">
                                        <?= $late_rem > 0 ? gmdate('i:s', $late_rem) . ' left' : 'Ended' ?>
                                    </span>
                                </div>
                                <div class="grace-timer-chip absent-chip">
                                    <i class="fa-solid fa-user-xmark"></i>
                                    Absent Grace: <span class="tv" id="absent-timer" data-end="<?= $absent_end_ts ?>">
                                        <?= $absent_rem > 0 ? gmdate('i:s', $absent_rem) . ' left' : 'Ended' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($selected_date === date('Y-m-d')): ?>
                        <div class="grace-controls-bar">
                            <button class="btn-grace btn-create-grace" onclick="openGraceModal(<?= $schedule_id ?>, '<?= htmlspecialchars(addslashes($sched['subject'])) ?>', 15, 60)">
                                <i class="fa-solid fa-clock-rotate-left"></i> Create Late &amp; Absent Period
                            </button>
                        </div>
                    <?php endif; ?>
					
					<div class="table-responsive">
						<table>
							<thead>
								<tr>
									<th>#</th>
									<th>Student Name</th>
									<th>Time In</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
								<?php if (empty($computed)): ?>
									<tr><td colspan="4">No students found for this class.</td></tr>
								<?php else: 
									$i = 1;
									foreach ($computed as $row): 
										$s = strtolower($row['status']);
										$cls = ($s == 'present') ? 'present' : (($s == 'late') ? 'late' : (($s == 'absent') ? 'absent' : 'pending'));
								?>
									<tr>
										<td><?= $i++ ?></td>
										<td><?= htmlspecialchars($row['student']['name']) ?></td>
										<td><?= $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '-' ?></td>
										<td class="<?= $cls ?>"><?= htmlspecialchars($row['status']) ?></td>
									</tr>
								<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php else: ?>
				<div class="info-box">Please select a class to view today's attendance.</div>
			<?php endif; ?>
		</main>
	</div>

    <!-- Grace Period Modal -->
    <div class="grace-modal-overlay" id="graceModalOverlay">
        <div class="grace-modal">
            <h4 id="graceModalTitle">Start Class Session</h4>
            <p class="sub" id="graceModalSub">Set the grace periods then click Start Now.</p>
            <form method="POST" id="graceModalForm">
                <input type="hidden" name="action" value="start_class">
                <input type="hidden" name="schedule_id" id="graceModalSid">
                <?php if ($schedule_id !== false): ?>
                <input type="hidden" name="today_class_id" value="<?= $schedule_id ?>">
                <?php endif; ?>
                <label>Late Grace Period (minutes)</label>
                <input type="number" name="late_grace_period" id="graceModalLate" min="0" max="120">
                <label>Absent Grace Period (minutes)</label>
                <input type="number" name="absent_grace_period" id="graceModalAbsent" min="0" max="300">
                <div class="grace-modal-actions">
                    <button type="button" class="btn-cancel-modal" onclick="closeGraceModal()">Cancel</button>
                    <button type="submit" class="btn-confirm-start"><i class="fa-solid fa-play"></i> Start Now</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openGraceModal(scheduleId, subject, defaultLate, defaultAbsent) {
        document.getElementById('graceModalTitle').textContent = 'Start: ' + subject;
        document.getElementById('graceModalSid').value = scheduleId;
        document.getElementById('graceModalLate').value = defaultLate;
        document.getElementById('graceModalAbsent').value = defaultAbsent;
        document.getElementById('graceModalOverlay').classList.add('show');
    }
    function closeGraceModal() {
        document.getElementById('graceModalOverlay').classList.remove('show');
    }
    document.getElementById('graceModalOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeGraceModal();
    });
    // Live countdown timers
    function updateTimers() {
        const now = Math.floor(Date.now() / 1000);
        document.querySelectorAll('[data-end]').forEach(function(el) {
            const end = parseInt(el.getAttribute('data-end'), 10);
            const rem = end - now;
            if (rem > 0) {
                const m = Math.floor(rem / 60).toString().padStart(2,'0');
                const s = (rem % 60).toString().padStart(2,'0');
                el.textContent = m + ':' + s + ' left';
            } else {
                el.textContent = 'Ended';
                el.style.opacity = '0.55';
            }
        });
    }
    setInterval(updateTimers, 1000);
    updateTimers();
    </script>
</body>
</html>