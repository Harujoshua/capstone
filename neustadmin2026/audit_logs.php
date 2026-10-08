<?php
include('admin_db.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

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
$time_from = $_GET['time_from'] ?? '';
$time_to = $_GET['time_to'] ?? '';

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

if (!empty($time_from)) {
    $safe_time_from = $admin_conn->real_escape_string($time_from);
    $where_clauses[] = "TIME(created_at) >= '$safe_time_from'";
}

if (!empty($time_to)) {
    $safe_time_to = $admin_conn->real_escape_string($time_to);
    $where_clauses[] = "TIME(created_at) <= '$safe_time_to'";
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

// Pagination logic
$count_res = $admin_conn->query("SELECT COUNT(*) AS total FROM audit_logs $search_sql");
$total_rows = ($count_res && $count_res->num_rows) ? (int)$count_res->fetch_assoc()['total'] : 0;

$per_page = intval($_GET['per_page'] ?? 25);
if (!in_array($per_page, [10, 25, 50, 100], true)) {
    $per_page = 25;
}

$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = max(1, min($total_pages, intval($_GET['page'] ?? 1)));
$offset = ($page - 1) * $per_page;

$from_entry = ($total_rows > 0) ? ($offset + 1) : 0;
$to_entry = min($offset + $per_page, $total_rows);

// Fetch audit logs
$logs = $admin_conn->query("SELECT * FROM audit_logs $search_sql ORDER BY created_at DESC LIMIT $offset, $per_page");

function audit_page_url($p, $search, $start_date, $end_date, $per_page) {
    $params = ['page' => $p, 'per_page' => $per_page];
    if (!empty($search)) $params['search'] = $search;
    if (!empty($start_date)) $params['start_date'] = $start_date;
    if (!empty($end_date)) $params['end_date'] = $end_date;
    return 'audit_logs.php?' . http_build_query($params);
}
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
        body { overflow: hidden; }
        .content {
            height: calc(100vh - 38px);
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        #auditLogsContainer {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
        }

        .table-wrap {
            width: 100%;
            flex: 1;
            min-height: 0;
            overflow: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
            margin-top: 16px;
        }
        .audit-table { width: 100%; border-collapse: collapse; background: #fff; font-size: 0.88rem; }
        .audit-table th, .audit-table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .audit-table th { background: #f8fafc; font-weight: 600; color: #475569; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; position: sticky; top: 0; z-index: 1; }
        .action-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .action-create { background: #dcfce7; color: #166534; }
        .action-edit { background: #fef9c3; color: #854d0e; }
        .action-delete { background: #fee2e2; color: #991b1b; }
        .action-block { background: #ffedd5; color: #9a3412; }
        .action-default { background: #f1f5f9; color: #475569; }
        .search-sticky-bar { position: sticky; top: 0; z-index: 100; background: rgba(248, 250, 252, 0.92); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 14px 0 10px; margin-bottom: 4px; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
        .audit-search-form { display: flex; flex-direction: column; gap: 12px; }
        .filter-row { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
        .primary-row { padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
        .advanced-filters { display: none; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
        .advanced-filters.open { display: block; }
        .field { display: flex; flex-direction: column; gap: 6px; min-width: 120px; flex: 1 1 120px; }
        .search-field { flex: 1.5 1 220px; }
        .compact-field { min-width: 130px; }
        .date-field, .time-field { min-width: 150px; }
        .field label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 700; }
        .audit-search-form input[type="text"], .audit-search-form input[type="date"], .audit-search-form input[type="time"], .audit-search-form select { width: 100%; padding: 9px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none; background: #fff; box-sizing: border-box; }
        .audit-search-form input[type="text"] { min-width: 180px; }
        .audit-search-btn, .audit-filter-toggle, .audit-clear-btn, .audit-export-btn, .audit-print-btn { padding: 10px 18px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; white-space: nowrap; }
        .audit-search-btn { background: #0ea5e9; color: white; }
        .audit-filter-toggle { background: #e2e8f0; color: #334155; }
        .audit-export-btn { background: #10b981; color: white; }
        .audit-print-btn { background: #ef4444; color: white; }
        .audit-clear-btn { background: #f1f5f9; color: #475569; text-decoration: none; border: 1px solid #cbd5e1; }
        
        /* Pagination Styles */
        .pagination-section { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 16px; padding: 8px 4px; font-size: 0.88rem; color: #475569; flex-shrink: 0; }
        .pagination-info { font-weight: 500; color: #64748b; }
        .pagination-controls { display: inline-flex; align-items: center; gap: 5px; flex-wrap: wrap; }
        .pagination-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.15s ease-in-out; }
        .pagination-btn:hover:not(.active):not(.disabled) { background: #f1f5f9; border-color: #94a3b8; color: #0f172a; }
        .pagination-btn.active { background: #0ea5e9; border-color: #0ea5e9; color: #ffffff; cursor: default; }
        .pagination-btn.disabled { opacity: 0.45; cursor: not-allowed; background: #f8fafc; border-color: #e2e8f0; color: #94a3b8; pointer-events: none; }
        .pagination-ellipsis { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 36px; color: #94a3b8; font-weight: 700; }

        @media (max-width: 640px) {
            body { overflow: auto; }
            .content { height: auto; }
            .table-wrap { max-height: 60vh; }
            .audit-search-form input[type="text"], .audit-search-form input[type="date"], .audit-search-form select, .audit-search-btn, .audit-export-btn, .audit-print-btn, .audit-clear-btn { flex: 1 1 100%; justify-content: center; }
            .audit-table th, .audit-table td { padding: 10px 10px; font-size: 0.82rem; }
            .pagination-section { flex-direction: column; align-items: center; text-align: center; }
        }
        @page {
            size: A4;
            margin: 12mm;
        }

        @media print {
            html, body {
                width: 100%;
                height: auto;
                overflow: visible;
                background: #fff;
            }
            @page {
                margin: 12mm;
                size: A4;
                marks: none;
            }
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .admin-sidebar, .search-sticky-bar, #backToTop, .pagination-section { display: none !important; }
            .content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: auto !important;
                display: block !important;
                overflow: visible !important;
            }
            .report-header {
                display: flex !important;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin: 0 0 12px;
                padding: 18px 20px;
                background: linear-gradient(135deg, #f8fafc 0%, #e0f2fe 100%);
                border: 1px solid #cbd5e1;
                border-radius: 12px;
                color: #0f172a;
            }
            .table-wrap {
                overflow: visible !important;
                max-height: none !important;
                height: auto !important;
                border: 1px solid #000 !important;
                box-shadow: none !important;
            }
            .audit-table {
                width: 100% !important;
                border: 1px solid #000 !important;
                page-break-inside: auto !important;
            }
            .audit-table thead { display: table-header-group; }
            .audit-table tr { page-break-inside: avoid; page-break-after: auto; }
            .audit-table th, .audit-table td { border: 1px solid #000 !important; }
            title, meta, link { display: none !important; }
            @supports (-webkit-touch-callout: none) {
                body::before { content: none !important; }
            }
        }
    </style>
</head>
<body>
    <div class="app">
        <?php include('navbar.php'); ?>

        <main class="content">

            <div class="search-sticky-bar">
                <form method="GET" class="audit-search-form" id="auditFilterForm">
                    <div class="filter-row primary-row">
                        <div class="field search-field">
                            <label>Search</label>
                            <input type="text" name="search" placeholder="Search admin, action, target, details" value="<?= htmlspecialchars($search) ?>">
                        </div>

                        <div class="field compact-field">
                            <label>Per page</label>
                            <select name="per_page" onchange="this.form.submit();" title="Items Per Page">
                                <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10 / page</option>
                                <option value="25" <?= $per_page == 25 ? 'selected' : '' ?>>25 / page</option>
                                <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50 / page</option>
                                <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100 / page</option>
                            </select>
                        </div>

                        <button type="button" class="audit-filter-toggle" id="auditFilterToggle" aria-expanded="false">
                            <i class="fa-solid fa-sliders"></i>
                        </button>

                        <button type="submit" name="export_csv" value="1" class="audit-export-btn" title="Export matching audit logs to CSV" aria-label="Export CSV">
                            <i class="fa-solid fa-file-csv"></i>
                        </button>

                        <button type="button" class="audit-print-btn" onclick="window.print()" title="Print audit logs" aria-label="Print audit logs">
                            <i class="fa-solid fa-print"></i>
                        </button>

                        <?php if (!empty($search) || !empty($start_date) || !empty($end_date) || !empty($time_from) || !empty($time_to)): ?>
                            <a href="audit_logs.php" class="audit-clear-btn"><i class="fa-solid fa-xmark"></i> Clear</a>
                        <?php endif; ?>
                    </div>

                    <div class="advanced-filters" id="advancedFilters">
                        <div class="filter-row">
                            <div class="field date-field">
                                <label>From Date</label>
                                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" title="Start Date">
                            </div>

                            <div class="field date-field">
                                <label>To Date</label>
                                <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" title="End Date">
                            </div>

                            <div class="field time-field">
                                <label>From Time</label>
                                <input type="time" name="time_from" value="<?= htmlspecialchars($time_from) ?>" title="Start Time">
                            </div>

                            <div class="field time-field">
                                <label>To Time</label>
                                <input type="time" name="time_to" value="<?= htmlspecialchars($time_to) ?>" title="End Time">
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div id="auditLogsContainer">
                <div class="report-header" style="display: none;">
                    <div>
                        <div style="font-size: 0.72rem; letter-spacing: 0.12em; text-transform: uppercase; font-weight: 800; color: #0369a1; margin-bottom: 6px;">NEUST Admin</div>
                        <h2 style="margin: 0; font-size: clamp(1.3rem, 2vw, 2rem); font-weight: 800;">Audit Log Report</h2>
                    </div>
                    <div style="text-align: right; font-size: 0.86rem; color: #334155;">
                        <div style="font-weight: 700;">Generated</div>
                        <div><?= date('M j, Y H:i') ?></div>
                        <?php if (!empty($start_date) || !empty($end_date)): ?>
                            <div style="margin-top: 4px; font-weight: 600; color: #0f172a;">
                                <?= !empty($start_date) ? htmlspecialchars(date('M j, Y', strtotime($start_date))) : 'All dates' ?>
                                <?php if (!empty($start_date) && !empty($end_date)): ?>
                                    <span>–</span>
                                <?php endif; ?>
                                <?= !empty($end_date) ? htmlspecialchars(date('M j, Y', strtotime($end_date))) : '' ?>
                            </div>
                        <?php endif; ?>
                    </div>
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
                            <?php if ($logs && $logs->num_rows > 0): ?>
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

                <!-- ================= PAGINATION SECTION ================= -->
                <div class="pagination-section">
                    <div class="pagination-info">
                        <?php if ($total_rows > 0): ?>
                            Showing <strong><?= number_format($from_entry) ?></strong> to <strong><?= number_format($to_entry) ?></strong> of <strong><?= number_format($total_rows) ?></strong> entries
                        <?php else: ?>
                            No audit logs found
                        <?php endif; ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-controls">
                        <!-- First Page -->
                        <a href="<?= htmlspecialchars(audit_page_url(1, $search, $start_date, $end_date, $per_page)) ?>" 
                           class="pagination-btn <?= ($page <= 1) ? 'disabled' : '' ?>" title="First Page">
                            <i class="fa-solid fa-angles-left"></i>
                        </a>

                        <!-- Previous Page -->
                        <a href="<?= htmlspecialchars(audit_page_url(max(1, $page - 1), $search, $start_date, $end_date, $per_page)) ?>" 
                           class="pagination-btn <?= ($page <= 1) ? 'disabled' : '' ?>" title="Previous Page">
                            <i class="fa-solid fa-angle-left"></i>
                        </a>

                        <!-- Page Numbers -->
                        <?php
                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);

                        if ($start_p > 1) {
                            echo '<a href="' . htmlspecialchars(audit_page_url(1, $search, $start_date, $end_date, $per_page)) . '" class="pagination-btn">1</a>';
                            if ($start_p > 2) {
                                echo '<span class="pagination-ellipsis">&hellip;</span>';
                            }
                        }

                        for ($p = $start_p; $p <= $end_p; $p++) {
                            if ($p == $page) {
                                echo '<span class="pagination-btn active">' . $p . '</span>';
                            } else {
                                echo '<a href="' . htmlspecialchars(audit_page_url($p, $search, $start_date, $end_date, $per_page)) . '" class="pagination-btn">' . $p . '</a>';
                            }
                        }

                        if ($end_p < $total_pages) {
                            if ($end_p < $total_pages - 1) {
                                echo '<span class="pagination-ellipsis">&hellip;</span>';
                            }
                            echo '<a href="' . htmlspecialchars(audit_page_url($total_pages, $search, $start_date, $end_date, $per_page)) . '" class="pagination-btn">' . $total_pages . '</a>';
                        }
                        ?>

                        <!-- Next Page -->
                        <a href="<?= htmlspecialchars(audit_page_url(min($total_pages, $page + 1), $search, $start_date, $end_date, $per_page)) ?>" 
                           class="pagination-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>" title="Next Page">
                            <i class="fa-solid fa-angle-right"></i>
                        </a>

                        <!-- Last Page -->
                        <a href="<?= htmlspecialchars(audit_page_url($total_pages, $search, $start_date, $end_date, $per_page)) ?>" 
                           class="pagination-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>" title="Last Page">
                            <i class="fa-solid fa-angles-right"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('auditFilterForm');
            var advancedFilters = document.getElementById('advancedFilters');
            var filterToggle = document.getElementById('auditFilterToggle');

            if (form) {
                var searchInput = form.querySelector('input[name="search"]');
                var dateInputs = form.querySelectorAll('input[type="date"], input[type="time"]');
                var searchTimer;

                if (searchInput) {
                    searchInput.addEventListener('input', function () {
                        clearTimeout(searchTimer);
                        searchTimer = setTimeout(function () {
                            form.submit();
                        }, 400);
                    });
                }

                dateInputs.forEach(function (input) {
                    input.addEventListener('change', function () {
                        form.submit();
                    });
                });
            }

            if (advancedFilters && filterToggle) {
                var shouldOpen = [
                    document.querySelector('input[name="start_date"]'),
                    document.querySelector('input[name="end_date"]'),
                    document.querySelector('input[name="time_from"]'),
                    document.querySelector('input[name="time_to"]')
                ].some(function (field) {
                    return field && field.value;
                });

                if (shouldOpen) {
                    advancedFilters.classList.add('open');
                    filterToggle.setAttribute('aria-expanded', 'true');
                    filterToggle.innerHTML = '<i class="fa-solid fa-sliders"></i> Less filters';
                }

                filterToggle.addEventListener('click', function () {
                    var isOpen = advancedFilters.classList.toggle('open');
                    filterToggle.setAttribute('aria-expanded', String(isOpen));
                    filterToggle.innerHTML = isOpen
                        ? '<i class="fa-solid fa-sliders"></i> Less filters'
                        : '<i class="fa-solid fa-sliders"></i>';
                });
            }
        });

        // Auto-refresh the audit logs table every 5 seconds
        setInterval(function() {
            // Do not refresh if user is actively interacting with form
            if (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'SELECT') {
                return; 
            }
            
            fetch(window.location.href)
            .then(response => response.text())
            .then(html => {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var newContainer = doc.querySelector('#auditLogsContainer');
                var currContainer = document.querySelector('#auditLogsContainer');
                if (newContainer && currContainer) {
                    currContainer.innerHTML = newContainer.innerHTML;
                }
            })
            .catch(err => console.error("Error auto-refreshing audit logs:", err));
        }, 5000);
    </script>
</body>
</html>
