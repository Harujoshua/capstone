<?php
include('../db.php');
include('auth.php');

$teacher_id   = intval($_SESSION['faculty_id'] ?? 0);
$teacher_name = $_SESSION['faculty_name'] ?? $_SESSION['faculty_email'] ?? '';
$safe_teacher = $conn->real_escape_string($teacher_name);

// Build teacher condition (same pattern used in students.php)
$teacher_conds = [];
if ($teacher_id > 0)      $teacher_conds[] = "sc.teacher_id = $teacher_id";
if ($safe_teacher !== '')  $teacher_conds[] = "LOWER(sc.teacher) = LOWER('$safe_teacher')";
$teacher_where = count($teacher_conds) ? '(' . implode(' OR ', $teacher_conds) . ')' : '1=0';

// Handle approve / reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($id > 0 && in_array($action, ['approve', 'reject'])) {
        // Verify this excuse belongs to a student in this teacher's schedule
        $check = $conn->query("
            SELECT el.id FROM excuse_letters el
            JOIN schedule_students ss ON ss.student_id = el.student_id
            JOIN schedules sc ON sc.id = ss.schedule_id
            WHERE el.id = $id AND $teacher_where
            LIMIT 1
        ");
        if ($check && $check->num_rows > 0) {
            $new_status = ($action === 'approve') ? 'Approved' : 'Rejected';
            $stmt = $conn->prepare("UPDATE excuse_letters SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $id);
            $stmt->execute();
        }
    }
    header("Location: excuse_letters.php" . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}

// Status filter
$status_filter = $_GET['status'] ?? '';
$schedule_filter = intval($_GET['schedule_id'] ?? 0);

// Status condition
$status_cond = '';
if (in_array($status_filter, ['Pending', 'Approved', 'Rejected'])) {
    $sf = $conn->real_escape_string($status_filter);
    $status_cond = "AND el.status = '$sf'";
}

// Schedule condition
$schedule_cond = '';
if ($schedule_filter > 0) {
    $schedule_cond = "AND el.schedule_id = $schedule_filter";
}

// Fetch excuses scoped to this teacher's students
$sql = "
    SELECT el.*, st.name AS student_name, st.course, st.year_level, st.section, sc.subject, sc.id AS sched_id
    FROM excuse_letters el
    JOIN students st ON el.student_id = st.id
    JOIN schedules sc ON el.schedule_id = sc.id
    JOIN schedule_students ss ON ss.student_id = el.student_id AND ss.schedule_id = el.schedule_id
    WHERE $teacher_where
    $status_cond
    $schedule_cond
    GROUP BY el.id
    ORDER BY el.created_at DESC
";
$result = $conn->query($sql);
$excuses = [];
if ($result) {
    while ($row = $result->fetch_assoc()) $excuses[] = $row;
}

// Count per status for this teacher
$counts_q = $conn->query("
    SELECT el.status, COUNT(DISTINCT el.id) as c
    FROM excuse_letters el
    JOIN schedule_students ss ON ss.student_id = el.student_id AND ss.schedule_id = el.schedule_id
    JOIN schedules sc ON sc.id = ss.schedule_id
    WHERE $teacher_where
    GROUP BY el.status
");
$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
if ($counts_q) {
    while ($cr = $counts_q->fetch_assoc()) $counts[$cr['status']] = (int)$cr['c'];
}
$total_count = array_sum($counts);

// Fetch teacher's schedules for the filter dropdown
$scheds_res = $conn->query("SELECT id, subject, course, year_level, section FROM schedules sc WHERE $teacher_where ORDER BY subject ASC");
$my_schedules = [];
if ($scheds_res) {
    while ($r = $scheds_res->fetch_assoc()) $my_schedules[] = $r;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excuse Letters - Faculty</title>
    <link rel="stylesheet" href="faculty_assets/faculty_sidebar.css">
    <link rel="stylesheet" href="faculty_assets/faculty_students.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent:   #2bb7b3;
            --primary:  #4f46e5;
            --success:  #10b981;
            --danger:   #ef4444;
            --warning:  #f59e0b;
            --text:     #1e293b;
            --muted:    #64748b;
            --border:   #e2e8f0;
            --bg:       #f8fafc;
        }

        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); margin: 0; }

        .page-header {
            margin-bottom: 1.25rem;
        }
        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 0.2rem;
        }
        .page-header p { color: var(--muted); margin: 0; font-size: 0.875rem; }

        /* Filter bar */
        .filter-bar {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        .filter-tabs {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.45rem 1rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            border: 2px solid var(--border);
            background: #fff;
            color: var(--muted);
            transition: all 0.15s;
            cursor: pointer;
        }
        .filter-tab:hover { border-color: var(--accent); color: var(--accent); }
        .filter-tab.active { background: var(--accent); border-color: var(--accent); color: #fff; }

        .badge-count {
            display: inline-block;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            text-align: center;
            line-height: 18px;
            padding: 0 4px;
            background: rgba(255,255,255,0.25);
        }
        .filter-tab:not(.active) .badge-count { background: #eef2ff; color: var(--primary); }

        /* Schedule filter select */
        .sched-filter {
            padding: 0.45rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            min-width: 200px;
        }

        /* Table card */
        .table-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .data-table thead th {
            background: #f1f5f9;
            padding: 0.7rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .data-table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.12s; }
        .data-table tbody tr:last-child { border-bottom: none; }
        .data-table tbody tr:hover { background: #f8fafc; }
        .data-table td { padding: 0.8rem 1rem; vertical-align: middle; }

        .student-name { font-weight: 600; }
        .student-meta { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }

        /* Status pills */
        .pill {
            display: inline-block;
            padding: 0.18rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .pill-pending  { background: #fef3c7; color: #92400e; }
        .pill-approved { background: #d1fae5; color: #065f46; }
        .pill-rejected { background: #fee2e2; color: #7f1d1d; }

        /* Action buttons */
        .act-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.65rem;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: opacity 0.15s;
        }
        .act-btn:hover { opacity: 0.8; }
        .btn-approve { background: #d1fae5; color: #065f46; }
        .btn-reject  { background: #fee2e2; color: #7f1d1d; }
        .act-group   { display: flex; gap: 0.35rem; }

        .reason-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--muted);
        }
        .empty-state i { font-size: 2.5rem; opacity: 0.35; display: block; margin-bottom: 0.75rem; }

        /* Detail modal */
        .modal-bg {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 3000;
            align-items: center;
            justify-content: center;
        }
        .modal-bg.open { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 14px;
            max-width: 520px;
            width: 95%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.18);
            overflow: hidden;
        }
        .modal-hdr {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .modal-hdr h3 { margin: 0; font-size: 1rem; font-weight: 700; }
        .modal-cls { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--muted); }
        .modal-bdy { padding: 1.25rem 1.5rem; }
        .d-row { display: flex; gap: 0.75rem; margin-bottom: 0.75rem; font-size: 0.875rem; }
        .d-lbl { min-width: 130px; font-weight: 600; color: var(--muted); }
        .d-val { flex: 1; color: var(--text); }
        .reason-box {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.875rem;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .attach-link { color: var(--primary); text-decoration: none; font-size: 0.875rem; font-weight: 500; }
        .attach-link:hover { text-decoration: underline; }
        .modal-ftr {
            padding: 0.9rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
        }
        .btn-close-modal { padding: 0.4rem 1rem; background: #f1f5f9; border: none; border-radius: 6px; cursor: pointer; font-family: 'Inter', sans-serif; font-size: 0.875rem; }
    </style>
</head>
<body>
<div class="app">

    <?php include('navbar.php'); ?>

    <main class="content">
        <div class="page-header">
            <h1><i class="fa-solid fa-file-medical" style="color:var(--accent); margin-right:0.4rem;"></i>Excuse Letters</h1>
            <p>Excuse letters submitted by your registered students.</p>
        </div>

        <!-- Filter bar -->
        <div class="filter-bar">
            <!-- Status tabs -->
            <div class="filter-tabs">
                <?php
                $tabs = ['' => 'All', 'Pending' => 'Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected'];
                foreach ($tabs as $val => $label):
                    $active_class = ($status_filter === $val) ? 'active' : '';
                    $cnt = ($val === '') ? $total_count : ($counts[$val] ?? 0);
                    $qs = http_build_query(array_filter(['status' => $val, 'schedule_id' => $schedule_filter ?: null]));
                ?>
                <a href="excuse_letters.php<?= $qs ? "?$qs" : '' ?>" class="filter-tab <?= $active_class ?>">
                    <?= htmlspecialchars($label) ?>
                    <span class="badge-count"><?= $cnt ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Schedule filter -->
            <?php if (!empty($my_schedules)): ?>
            <form method="GET" style="display:inline;">
                <?php if ($status_filter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>"><?php endif; ?>
                <select name="schedule_id" class="sched-filter" onchange="this.form.submit()">
                    <option value="">— All my classes —</option>
                    <?php foreach ($my_schedules as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $schedule_filter === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['subject'] . ' — ' . $s['course'] . ' ' . $s['year_level'] . ' Sec ' . $s['section']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>

        <!-- Table -->
        <div class="table-card">
            <?php if (empty($excuses)): ?>
                <div class="empty-state">
                    <i class="fa-regular fa-folder-open"></i>
                    <p>No excuse letters found<?= $status_filter ? " with status \"$status_filter\"" : '' ?><?= $schedule_filter ? ' for the selected class' : '' ?>.</p>
                </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Subject / Class</th>
                            <th>Date Absent</th>
                            <th>Reason</th>
                            <th>Attachment</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($excuses as $e): ?>
                        <tr>
                            <td>
                                <div class="student-name"><?= htmlspecialchars($e['student_name']) ?></div>
                                <div class="student-meta"><?= htmlspecialchars($e['course'] . ' · ' . $e['year_level'] . ' · Sec ' . $e['section']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($e['subject']) ?></td>
                            <td style="white-space:nowrap;"><?= date('M d, Y', strtotime($e['date_absent'])) ?></td>
                            <td>
                                <span class="reason-cell" title="Click to view" onclick='viewDetail(<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>)'>
                                    <?= htmlspecialchars($e['reason']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($e['file_path'])): ?>
                                    <a href="../<?= htmlspecialchars(ltrim($e['file_path'], './')) ?>" target="_blank" class="attach-link">
                                        <i class="fa-solid fa-paperclip"></i> View
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--muted); font-size:0.78rem;">None</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.78rem; color:var(--muted); white-space:nowrap;">
                                <?= date('M d, Y', strtotime($e['created_at'])) ?>
                            </td>
                            <td>
                                <span class="pill pill-<?= strtolower($e['status']) ?>"><?= htmlspecialchars($e['status']) ?></span>
                            </td>
                            <td>
                                <div class="act-group">
                                    <?php if ($e['status'] !== 'Approved'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="act-btn btn-approve">
                                            <i class="fa-solid fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($e['status'] !== 'Rejected'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="act-btn btn-reject">
                                            <i class="fa-solid fa-xmark"></i> Reject
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Detail Modal -->
<div id="detailModal" class="modal-bg">
    <div class="modal-box">
        <div class="modal-hdr">
            <h3><i class="fa-solid fa-file-lines" style="color:var(--accent); margin-right:0.4rem;"></i>Excuse Letter Details</h3>
            <button class="modal-cls" onclick="closeDetail()">&times;</button>
        </div>
        <div class="modal-bdy">
            <div class="d-row"><span class="d-lbl">Student:</span><span class="d-val" id="d_student"></span></div>
            <div class="d-row"><span class="d-lbl">Course/Year/Sec:</span><span class="d-val" id="d_course"></span></div>
            <div class="d-row"><span class="d-lbl">Subject:</span><span class="d-val" id="d_subject"></span></div>
            <div class="d-row"><span class="d-lbl">Date of Absence:</span><span class="d-val" id="d_date"></span></div>
            <div class="d-row"><span class="d-lbl">Submitted On:</span><span class="d-val" id="d_submitted"></span></div>
            <div class="d-row"><span class="d-lbl">Status:</span><span class="d-val" id="d_status"></span></div>
            <div style="font-weight:600; font-size:0.875rem; color:var(--muted); margin-bottom:0.5rem;">Reason / Explanation:</div>
            <div class="reason-box" id="d_reason"></div>
            <div id="d_attach_wrap" style="margin-top:0.9rem; display:none;">
                <a id="d_attach" href="#" target="_blank" class="attach-link">
                    <i class="fa-solid fa-paperclip"></i> View Attached File
                </a>
            </div>
        </div>
        <div class="modal-ftr">
            <button class="btn-close-modal" onclick="closeDetail()">Close</button>
        </div>
    </div>
</div>

<script>
    function viewDetail(data) {
        document.getElementById('d_student').textContent   = data.student_name;
        document.getElementById('d_course').textContent    = data.course + ' · ' + data.year_level + ' · Sec ' + data.section;
        document.getElementById('d_subject').textContent   = data.subject;
        document.getElementById('d_date').textContent      = data.date_absent;
        document.getElementById('d_submitted').textContent = data.created_at;
        document.getElementById('d_reason').textContent    = data.reason;

        const statusMap = { pending: 'pill-pending', approved: 'pill-approved', rejected: 'pill-rejected' };
        const cls = statusMap[data.status.toLowerCase()] || '';
        document.getElementById('d_status').innerHTML = `<span class="pill ${cls}">${data.status}</span>`;

        const aw = document.getElementById('d_attach_wrap');
        const al = document.getElementById('d_attach');
        if (data.file_path) {
            aw.style.display = 'block';
            al.href = '../' + data.file_path.replace(/^\.\//, '');
        } else {
            aw.style.display = 'none';
        }

        document.getElementById('detailModal').classList.add('open');
    }

    function closeDetail() {
        document.getElementById('detailModal').classList.remove('open');
    }

    window.addEventListener('click', function(e) {
        const m = document.getElementById('detailModal');
        if (e.target === m) closeDetail();
    });
</script>
</body>
</html>
