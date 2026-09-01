<?php
include('admin_db.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only Super Admin can view audit logs
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || ($_SESSION['admin_role'] ?? 'sub_admin') !== 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

// Ensure table exists
$table_sql = "CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_username VARCHAR(100) NOT NULL,
    admin_role VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_name VARCHAR(255),
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$admin_conn->query($table_sql);

// Handle Search
$search = $_GET['search'] ?? '';
$search_date = $_GET['search_date'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if (!empty($search_date) && empty($start_date) && empty($end_date)) {
    $start_date = $search_date;
    $end_date = $search_date;
}

$where_clauses = [];

if (!empty($search)) {
    $search_escaped = $admin_conn->real_escape_string($search);
    $where_clauses[] = "(admin_username LIKE '%$search_escaped%' OR action LIKE '%$search_escaped%' OR target_type LIKE '%$search_escaped%' OR target_name LIKE '%$search_escaped%' OR details LIKE '%$search_escaped%')";
}

if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $admin_conn->real_escape_string($start_date);
    $safe_end = $admin_conn->real_escape_string($end_date);
    $where_clauses[] = "DATE(created_at) BETWEEN '$safe_start' AND '$safe_end'";
} elseif (!empty($start_date)) {
    $safe_start = $admin_conn->real_escape_string($start_date);
    $where_clauses[] = "DATE(created_at) >= '$safe_start'";
} elseif (!empty($end_date)) {
    $safe_end = $admin_conn->real_escape_string($end_date);
    $where_clauses[] = "DATE(created_at) <= '$safe_end'";
}

$search_sql = '';
if (!empty($where_clauses)) {
    $search_sql = " WHERE " . implode(' AND ', $where_clauses);
}

// Handle Export CSV
if (isset($_GET['export_csv'])) {
    $logs_export = $admin_conn->query("SELECT * FROM audit_logs $search_sql ORDER BY created_at DESC");
    $date_suffix = '';
    if (!empty($start_date) && !empty($end_date)) {
        $date_suffix = ($start_date === $end_date) ? $start_date : $start_date . '_to_' . $end_date;
    } elseif (!empty($start_date)) {
        $date_suffix = 'from_' . $start_date;
    } elseif (!empty($end_date)) {
        $date_suffix = 'until_' . $end_date;
    } else {
        $date_suffix = date('Y-m-d_H-i-s');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . $date_suffix . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Timestamp', 'Admin Username', 'Admin Role', 'Action', 'Target Type', 'Target Name', 'Details']);
    while ($r = $logs_export->fetch_assoc()) {
        fputcsv($output, [
            $r['created_at'],
            $r['admin_username'],
            $r['admin_role'],
            $r['action'],
            $r['target_type'],
            $r['target_name'],
            $r['details']
        ]);
    }
    fclose($output);
    exit;
}

// Fetch audit logs
$logs = $admin_conn->query("SELECT * FROM audit_logs $search_sql ORDER BY created_at DESC LIMIT 500");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Logs - Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="admin_assets/admin_sidebar.css">
    <link rel="stylesheet" href="admin_assets/admin_settings.css">
    <style>
        .table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #e2e8f0; background: #fff; margin-top: 16px; }
        .audit-table { width: 100%; border-collapse: collapse; background: #fff; font-size: 0.88rem; }
        .audit-table th, .audit-table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .audit-table th { background: #f8fafc; font-weight: 600; color: #475569; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        .action-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .action-create { background: #dcfce7; color: #166534; }
        .action-edit { background: #fef9c3; color: #854d0e; }
        .action-delete { background: #fee2e2; color: #991b1b; }
        .action-block { background: #ffedd5; color: #9a3412; }
        .action-default { background: #f1f5f9; color: #475569; }
        .search-sticky-bar { position: sticky; top: 0; z-index: 100; background: rgba(248, 250, 252, 0.92); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 14px 0 10px; margin-bottom: 4px; border-bottom: 1px solid #e2e8f0; }
        .audit-search-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .audit-search-form input[type="text"] { flex: 1 1 200px; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none; }
        .audit-search-form input[type="date"] { flex: 0 1 150px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none; background: #fff; }
        .audit-search-btn { padding: 10px 18px; background: #0ea5e9; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .audit-export-btn { padding: 10px 18px; background: #10b981; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .audit-print-btn { padding: 10px 18px; background: #ef4444; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .audit-clear-btn { padding: 10px 18px; background: #f1f5f9; color: #475569; text-decoration: none; border-radius: 8px; font-weight: 700; border: 1px solid #cbd5e1; display: inline-flex; align-items: center; gap: 6px; }
        @media (max-width: 640px) {
            .audit-search-form input[type="text"], .audit-search-form input[type="date"], .audit-search-btn, .audit-export-btn, .audit-print-btn, .audit-clear-btn { flex: 1 1 100%; justify-content: center; }
            .audit-table th, .audit-table td { padding: 10px 10px; font-size: 0.82rem; }
        }
        @media print {
            .admin-sidebar, .search-sticky-bar, #backToTop { display: none !important; }
            .content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            .audit-table { border: 1px solid #000 !important; }
            .audit-table th, .audit-table td { border: 1px solid #000 !important; }
        }
    </style>
</head>
<body>
    <div class="app">
        <?php include('navbar.php'); ?>

        <main class="content">

            <div class="search-sticky-bar">
                <form method="GET" class="audit-search-form">
                    <input type="text" name="search" placeholder="Search logs..." value="<?= htmlspecialchars($search) ?>">
                    <span style="font-size:0.85rem; font-weight:600; color:#64748b;">From:</span>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" title="Start Date">
                    <span style="font-size:0.85rem; font-weight:600; color:#64748b;">To:</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" title="End Date">
                    <button type="submit" class="audit-search-btn"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                    <?php if (!empty($search) || !empty($start_date) || !empty($end_date)): ?>
                        <a href="audit_logs.php" class="audit-clear-btn"><i class="fa-solid fa-xmark"></i> Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-wrap">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Admin</th>
                            <th>Action</th>
                            <th>Target</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs->num_rows > 0): ?>
                            <?php while ($row = $logs->fetch_assoc()): 
                                $actionClass = 'action-default';
                                if (stripos($row['action'], 'CREATE') !== false || stripos($row['action'], 'REGISTER') !== false) $actionClass = 'action-create';
                                elseif (stripos($row['action'], 'EDIT') !== false || stripos($row['action'], 'UPDATE') !== false) $actionClass = 'action-edit';
                                elseif (stripos($row['action'], 'DELETE') !== false) $actionClass = 'action-delete';
                                elseif (stripos($row['action'], 'BLOCK') !== false) $actionClass = 'action-block';
                            ?>
                            <tr>
                                <td style="white-space: nowrap; font-weight: 500;"><?= date('M j, Y H:i', strtotime($row['created_at'])) ?></td>
                                <td>
                                    <strong style="color: #0f172a;"><?= htmlspecialchars($row['admin_username']) ?></strong><br>
                                    <small style="color: #64748b;"><?= htmlspecialchars($row['admin_role']) ?></small>
                                </td>
                                <td>
                                    <span class="action-badge <?= $actionClass ?>"><?= htmlspecialchars($row['action']) ?></span>
                                </td>
                                <td>
                                    <small style="color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 0.7rem;"><?= htmlspecialchars($row['target_type']) ?>:</small><br>
                                    <span style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($row['target_name']) ?></span>
                                </td>
                                <td style="max-width: 320px; word-break: break-word;"><?= htmlspecialchars($row['details']) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">No audit logs found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    <script>
        // Auto-refresh the audit logs table every 5 seconds
        setInterval(function() {
            // Do not refresh if user is actively searching
            if (document.activeElement.tagName === 'INPUT') {
                return; 
            }
            
            fetch(window.location.href)
            .then(response => response.text())
            .then(html => {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var newTableWrap = doc.querySelector('.table-wrap');
                if (newTableWrap) {
                    document.querySelector('.table-wrap').innerHTML = newTableWrap.innerHTML;
                }
            })
            .catch(err => console.error("Error auto-refreshing audit logs:", err));
        }, 5000);
    </script>
</body>
</html>
