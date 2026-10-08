<?php
include('auth.php');
$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");

// ================= CLEAR FACULTY LOGS =================
if (isset($_POST['clear_all'])) {
    if (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin') {
        $conn->query("DELETE FROM logs WHERE student_id IS NULL");
        include_once('logger.php');
        log_audit('DELETE', 'Faculty Logs', 'All Faculty Logs', 'All faculty gate attendance logs cleared.');
    }
    header("Location: faculty_logs.php");
    exit;
}

// ================= SEARCH + FILTER =================
$filter = $_REQUEST['filter'] ?? '';
$selected_date = $_REQUEST['log_date'] ?? '';
$start_date = $_REQUEST['start_date'] ?? '';
$end_date = $_REQUEST['end_date'] ?? '';
$search_term = trim($_REQUEST['search'] ?? '');
$time_from = $_REQUEST['time_from'] ?? '';
$time_to = $_REQUEST['time_to'] ?? '';

// If single log_date is set without start_date/end_date, populate them
if (!empty($selected_date) && empty($start_date) && empty($end_date)) {
    $start_date = $selected_date;
    $end_date = $selected_date;
}

$where = " WHERE logs.student_id IS NULL";

if (!empty($filter) && ($filter == "IN" || $filter == "OUT")) {
    $where .= " AND logs.status='$filter'";
}

if (!empty($search_term)) {
    $safe_search = $conn->real_escape_string($search_term);
    $where .= " AND (logs.name LIKE '%$safe_search%' OR logs.rfid_uid LIKE '%$safe_search%' OR faculty.department LIKE '%$safe_search%')";
}

// Date filter
if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $safe_end = $conn->real_escape_string($end_date);
    $where .= " AND DATE(COALESCE(time_in, time_out)) BETWEEN '$safe_start' AND '$safe_end'";
} elseif (!empty($start_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $where .= " AND DATE(COALESCE(time_in, time_out)) >= '$safe_start'";
} elseif (!empty($end_date)) {
    $safe_end = $conn->real_escape_string($end_date);
    $where .= " AND DATE(COALESCE(time_in, time_out)) <= '$safe_end'";
}

if (!empty($time_from)) {
    $safe_time_from = $conn->real_escape_string($time_from);
    $where .= " AND TIME(COALESCE(time_in, time_out)) >= '$safe_time_from'";
}

if (!empty($time_to)) {
    $safe_time_to = $conn->real_escape_string($time_to);
    $where .= " AND TIME(COALESCE(time_in, time_out)) <= '$safe_time_to'";
}

// Department filter
$dept = trim($_REQUEST['department'] ?? '');

if ($dept !== '') {
    $safe = $conn->real_escape_string($dept);
    $where .= " AND faculty.department = '$safe'";
}

$base_select = "SELECT logs.*, faculty.department AS department, faculty.photo AS photo FROM logs LEFT JOIN faculty ON UPPER(logs.rfid_uid) = UPPER(faculty.rfid_uid)" . $where;

// ================= EXPORT CSV =================
if (isset($_REQUEST['export_csv'])) {
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

    $export_sql = $base_select . " ORDER BY COALESCE(time_in, time_out) DESC, logs.id DESC";
    $result = $conn->query($export_sql);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="faculty_attendance_logs_' . $date_suffix . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Output column headings matching exactly the student logs style
    fputcsv($output, ['Name', 'RFID UID', 'Department', 'Status', 'First IN', 'Last OUT', 'Date']);
    
    while ($row = $result->fetch_assoc()) {
        $row_date = '';
        if (!empty($row['time_in']) && $row['time_in'] != '0000-00-00 00:00:00') {
            $row_date = date('Y-m-d', strtotime($row['time_in']));
        } elseif (!empty($row['time_out']) && $row['time_out'] != '0000-00-00 00:00:00') {
            $row_date = date('Y-m-d', strtotime($row['time_out']));
        }
        
        $time_in = (!empty($row['time_in']) && $row['time_in'] != '0000-00-00 00:00:00') ? date('h:i A', strtotime($row['time_in'])) : '-';
        $time_out = (!empty($row['time_out']) && $row['time_out'] != '0000-00-00 00:00:00') ? date('h:i A', strtotime($row['time_out'])) : '-';
        
        fputcsv($output, [
            $row['name'],
            $row['rfid_uid'],
            $row['department'] ?? '',
            $row['status'],
            $time_in,
            $time_out,
            $row_date
        ]);
    }
    fclose($output);
    exit;
}

// ================= PAGINATION LOGIC =================
$count_sql = "SELECT COUNT(*) AS total FROM logs LEFT JOIN faculty ON UPPER(logs.rfid_uid) = UPPER(faculty.rfid_uid)" . $where;
$count_res = $conn->query($count_sql);
$total_rows = ($count_res && $count_res->num_rows) ? (int)$count_res->fetch_assoc()['total'] : 0;

$per_page = intval($_REQUEST['per_page'] ?? 25);
if (!in_array($per_page, [10, 25, 50, 100], true)) {
    $per_page = 25;
}

$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = max(1, min($total_pages, intval($_REQUEST['page'] ?? 1)));
$offset = ($page - 1) * $per_page;

$from_entry = ($total_rows > 0) ? ($offset + 1) : 0;
$to_entry = min($offset + $per_page, $total_rows);

$print_all = !empty($_REQUEST['print_all']);
if ($print_all) {
    $sql = $base_select . " ORDER BY COALESCE(time_in, time_out) DESC, logs.id DESC";
} else {
    $sql = $base_select . " ORDER BY COALESCE(time_in, time_out) DESC, logs.id DESC LIMIT $offset, $per_page";
}
$result = $conn->query($sql);
?>
<?php include('navbar.php'); ?>
<link rel="stylesheet" href="admin_assets/admin_logs.css">

<div class="container">
    <!-- Printable Only Header -->
    <div class="print-only-header">
        <div class="print-header-content">
            <img src="admin_assets/neust_logo.png" alt="NEUST Logo" class="print-logo">
            <div class="print-header-text">
                <h2>NUEVA ECIJA UNIVERSITY OF SCIENCE AND TECHNOLOGY</h2>
                <h3>FACULTY ATTENDANCE LOGS REPORT</h3>
                <p class="print-filter-info">
                    <strong>Date Range:</strong> 
                    <?php 
                    if (!empty($start_date) && !empty($end_date)) {
                        echo ($start_date === $end_date) ? date('F j, Y', strtotime($start_date)) : date('F j, Y', strtotime($start_date)) . " to " . date('F j, Y', strtotime($end_date));
                    } elseif (!empty($start_date)) {
                        echo "From " . date('F j, Y', strtotime($start_date));
                    } elseif (!empty($end_date)) {
                        echo "Until " . date('F j, Y', strtotime($end_date));
                    } else {
                        echo "All Dates";
                    }
                    ?>
                    <?php if (!empty($dept)): ?> &bull; <strong>Department:</strong> <?= htmlspecialchars($dept) ?><?php endif; ?>
                    <?php if (!empty($filter)): ?> &bull; <strong>Status:</strong> <?= htmlspecialchars($filter) ?><?php endif; ?>
                </p>
                <p class="print-timestamp">Generated on: <?= date('F j, Y h:i A') ?></p>
            </div>
        </div>
    </div>

    <!-- ================= CONTROLS ================= -->
    <div class="controls">
        <form id="filterForm" method="POST" class="log-filter-form">
            <div class="filter-row primary-row">
                <div class="field search-field">
                    <label for="search_input">Search</label>
                    <input id="search_input" type="text" name="search" value="<?= htmlspecialchars($search_term) ?>" placeholder="Search name or RFID" title="Search by faculty name or RFID UID">
                </div>

                <div class="field compact-field">
                    <label>Status</label>
                    <select name="filter" title="Status Filter">
                        <option value="">ALL STATUS</option>
                        <option value="IN" <?= $filter == "IN" ? "selected" : "" ?>>IN</option>
                        <option value="OUT" <?= $filter == "OUT" ? "selected" : "" ?>>OUT</option>
                    </select>
                </div>

                <div class="field compact-field">
                    <label>Department</label>
                    <select name="department" title="Department Filter">
                        <option value="">All departments</option>
                        <option value="BSIT" <?= (isset($dept) && $dept === 'BSIT') ? "selected" : "" ?>>BSIT</option>
                        <option value="BEED" <?= (isset($dept) && $dept === 'BEED') ? "selected" : "" ?>>BEED</option>
                        <option value="BSBA" <?= (isset($dept) && $dept === 'BSBA') ? "selected" : "" ?>>BSBA</option>
                    </select>
                </div>

                <button type="button" class="filter-toggle" id="filterToggle" aria-expanded="false" aria-label="More filters" title="More filters">
                    <i class="fa-solid fa-sliders"></i>
                </button>
            </div>

            <div class="advanced-filters" id="advancedFilters">
                <div class="filter-row secondary-row">
                    <div class="field date-field">
                        <label>From</label>
                        <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" title="Start Date">
                    </div>

                    <div class="field date-field">
                        <label>To</label>
                        <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" title="End Date">
                    </div>

                    <div class="field time-field">
                        <label>Time From</label>
                        <input type="time" name="time_from" value="<?= htmlspecialchars($time_from) ?>" title="Start Time">
                    </div>

                    <div class="field time-field">
                        <label>Time To</label>
                        <input type="time" name="time_to" value="<?= htmlspecialchars($time_to) ?>" title="End Time">
                    </div>

                    <div class="field compact-field per-page-field">
                        <label>Per page</label>
                        <select name="per_page" id="per_page_select" class="per-page-select" title="Items Per Page">
                            <option value="10" <?= $per_page == 10 ? "selected" : "" ?>>10 / page</option>
                            <option value="25" <?= $per_page == 25 ? "selected" : "" ?>>25 / page</option>
                            <option value="50" <?= $per_page == 50 ? "selected" : "" ?>>50 / page</option>
                            <option value="100" <?= $per_page == 100 ? "selected" : "" ?>>100 / page</option>
                        </select>
                    </div>

                    <button type="button" onclick="openExportModal()" class="btn-export-options" title="Configure Export / Print Date Range">
                        <i class="fa-solid fa-calendar-days"></i> Select Date & Export
                    </button>

                    <?php if (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin'): ?>
                    <button type="button" onclick="clearLogs()" class="btn-clear">CLEAR LOGS</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Hidden Page Input for Pagination -->
            <input type="hidden" name="page" id="page_input" value="<?= $page ?>">
        </form>

        <form id="clearForm" method="POST" class="hidden-form">
            <input type="hidden" name="clear_all" value="1">
        </form>
    </div>

    <br>

    <div id="logsContainer">
        <div class="table-wrap">
        <table class="table">

            <tr>
                <th>Name</th>
                <th>RFID UID</th>
                <th>Department</th>
                <th>Status</th>
                <th>IN</th>
                <th>OUT</th>
            </tr>

            <?php
            if ($result && $result->num_rows > 0) {
                $current_date = '';
                while ($row = $result->fetch_assoc()) {
                    // determine the row date using time_in first, then time_out
                    $row_date = '';
                    if (!empty($row['time_in']) && $row['time_in'] != '0000-00-00 00:00:00') {
                        $row_date = date('Y-m-d', strtotime($row['time_in']));
                    } elseif (!empty($row['time_out']) && $row['time_out'] != '0000-00-00 00:00:00') {
                        $row_date = date('Y-m-d', strtotime($row['time_out']));
                    }

                    if ($row_date !== $current_date) {
                        $current_date = $row_date;
                        ?>
                        <tr class="date-header">
                            <td colspan="6">
                                <?= $current_date ? date('F j, Y', strtotime($current_date)) : 'No date' ?>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>

                    <tr>
                        <td>
                            <div class="user-cell">
                                <?php if (!empty($row['photo']) && file_exists(__DIR__ . '/../uploads/faculty/' . $row['photo'])): ?>
                                    <img src="../uploads/faculty/<?= htmlspecialchars($row['photo']) ?>" alt="<?= htmlspecialchars($row['name']) ?>" class="user-avatar-img">
                                <?php else: ?>
                                    <span class="user-avatar-placeholder"><?= strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $row['name']), 0, 1) ?: 'F') ?></span>
                                <?php endif; ?>
                                <span class="user-name-text"><?= htmlspecialchars($row['name']) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($row['rfid_uid']) ?></td>
                        <td><?= htmlspecialchars($row['department'] ?? '') ?></td>

                        <td>
                            <?php if ($row['status'] == "IN") { ?>
                                <span class="status-in">IN</span>
                            <?php } else { ?>
                                <span class="status-out">OUT</span>
                            <?php } ?>
                        </td>

                        <td><?= (!empty($row['time_in']) && $row['time_in'] != '0000-00-00 00:00:00') ? date('h:i A', strtotime($row['time_in'])) : '-' ?></td>
                        <td><?= (!empty($row['time_out']) && $row['time_out'] != '0000-00-00 00:00:00') ? date('h:i A', strtotime($row['time_out'])) : '-' ?></td>
                    </tr>

                <?php }
            } else { ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 36px 16px; color: #64748b;">
                        <i class="fa-solid fa-inbox" style="font-size: 2rem; margin-bottom: 8px; display: block; opacity: 0.5;"></i>
                        No faculty attendance logs found matching your criteria.
                    </td>
                </tr>
            <?php } ?>

        </table>
        </div>

        <!-- ================= PAGINATION SECTION ================= -->
        <div class="pagination-section">
            <?php if ($total_pages > 1): ?>
            <div class="pagination-controls">
                <!-- First Page -->
                <button type="button" class="pagination-btn <?= ($page <= 1) ? 'disabled' : '' ?>" 
                        onclick="goToPage(1)" <?= ($page <= 1) ? 'disabled' : '' ?> title="First Page">
                    <i class="fa-solid fa-angles-left"></i>
                </button>

                <!-- Previous Page -->
                <button type="button" class="pagination-btn <?= ($page <= 1) ? 'disabled' : '' ?>" 
                        onclick="goToPage(<?= max(1, $page - 1) ?>)" <?= ($page <= 1) ? 'disabled' : '' ?> title="Previous Page">
                    <i class="fa-solid fa-angle-left"></i>
                </button>

                <!-- Page Numbers -->
                <?php
                $start_p = max(1, $page - 2);
                $end_p = min($total_pages, $page + 2);

                if ($start_p > 1) {
                    echo '<button type="button" class="pagination-btn" onclick="goToPage(1)">1</button>';
                    if ($start_p > 2) {
                        echo '<span class="pagination-ellipsis">&hellip;</span>';
                    }
                }

                for ($p = $start_p; $p <= $end_p; $p++) {
                    if ($p == $page) {
                        echo '<button type="button" class="pagination-btn active">' . $p . '</button>';
                    } else {
                        echo '<button type="button" class="pagination-btn" onclick="goToPage(' . $p . ')">' . $p . '</button>';
                    }
                }

                if ($end_p < $total_pages) {
                    if ($end_p < $total_pages - 1) {
                        echo '<span class="pagination-ellipsis">&hellip;</span>';
                    }
                    echo '<button type="button" class="pagination-btn" onclick="goToPage(' . $total_pages . ')">' . $total_pages . '</button>';
                }
                ?>

                <!-- Next Page -->
                <button type="button" class="pagination-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>" 
                        onclick="goToPage(<?= min($total_pages, $page + 1) ?>)" <?= ($page >= $total_pages) ? 'disabled' : '' ?> title="Next Page">
                    <i class="fa-solid fa-angle-right"></i>
                </button>

                <!-- Last Page -->
                <button type="button" class="pagination-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>" 
                        onclick="goToPage(<?= $total_pages ?>)" <?= ($page >= $total_pages) ? 'disabled' : '' ?> title="Last Page">
                    <i class="fa-solid fa-angles-right"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Modal for Selecting Date to Export / Print -->
<div id="exportModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fa-solid fa-file-export"></i> Select Export Date & Range</h3>
            <button type="button" class="modal-close-btn" onclick="closeExportModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="modal-subtext">Choose the date range for your CSV export or Print / PDF report.</p>

            <div class="preset-buttons">
                <button type="button" class="preset-btn" onclick="setModalPreset('today')">Today</button>
                <button type="button" class="preset-btn" onclick="setModalPreset('yesterday')">Yesterday</button>
                <button type="button" class="preset-btn" onclick="setModalPreset('this_week')">This Week</button>
                <button type="button" class="preset-btn" onclick="setModalPreset('this_month')">This Month</button>
                <button type="button" class="preset-btn" onclick="setModalPreset('all')">All Dates</button>
            </div>

            <div class="modal-date-inputs">
                <div class="modal-input-group">
                    <label for="m_start_date">From Date:</label>
                    <input type="date" id="m_start_date" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="modal-input-group">
                    <label for="m_end_date">To Date:</label>
                    <input type="date" id="m_end_date" value="<?= htmlspecialchars($end_date) ?>">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeExportModal()" class="btn-modal-cancel">Cancel</button>
            <button type="button" onclick="submitModalExport('csv')" class="btn-modal-csv"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
            <button type="button" onclick="submitModalExport('print')" class="btn-modal-print"><i class="fa-solid fa-print"></i> Print / PDF</button>
        </div>
    </div>
</div>

<!-- ================= JS LOGIC ================= -->
<script>
    function clearLogs() {
        if (confirm("ARE YOU SURE YOU WANT TO DELETE ALL FACULTY LOGS?")) {
            document.getElementById('clearForm').submit();
        }
    }

    function goToPage(p) {
        var pageInput = document.getElementById('page_input');
        if (pageInput) {
            pageInput.value = p;
            document.getElementById('filterForm').submit();
        }
    }

    function formatDate(d) {
        var year = d.getFullYear();
        var month = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        return year + '-' + month + '-' + day;
    }

    function openExportModal() {
        var startVal = document.getElementById('start_date').value;
        var endVal = document.getElementById('end_date').value;
        document.getElementById('m_start_date').value = startVal;
        document.getElementById('m_end_date').value = endVal;
        document.getElementById('exportModal').classList.add('show');
    }

    function closeExportModal() {
        document.getElementById('exportModal').classList.remove('show');
    }

    function setModalPreset(preset) {
        var startInput = document.getElementById('m_start_date');
        var endInput = document.getElementById('m_end_date');
        var today = new Date();

        if (preset === 'today') {
            var dateStr = formatDate(today);
            startInput.value = dateStr;
            endInput.value = dateStr;
        } else if (preset === 'yesterday') {
            var y = new Date(today);
            y.setDate(y.getDate() - 1);
            var dateStr = formatDate(y);
            startInput.value = dateStr;
            endInput.value = dateStr;
        } else if (preset === 'this_week') {
            var day = today.getDay();
            var diffToMon = today.getDate() - day + (day === 0 ? -6 : 1);
            var mon = new Date(today.setDate(diffToMon));
            startInput.value = formatDate(mon);
            endInput.value = formatDate(new Date());
        } else if (preset === 'this_month') {
            var firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            startInput.value = formatDate(firstDay);
            endInput.value = formatDate(today);
        } else if (preset === 'all') {
            startInput.value = '';
            endInput.value = '';
        }
    }

    function submitModalExport(type) {
        var mStart = document.getElementById('m_start_date').value;
        var mEnd = document.getElementById('m_end_date').value;

        document.getElementById('start_date').value = mStart;
        document.getElementById('end_date').value = mEnd;
        var pageInput = document.getElementById('page_input');
        if (pageInput) pageInput.value = 1;
        closeExportModal();

        if (type === 'csv') {
            var form = document.getElementById('filterForm');
            var hiddenExport = document.createElement('input');
            hiddenExport.type = 'hidden';
            hiddenExport.name = 'export_csv';
            hiddenExport.value = '1';
            form.appendChild(hiddenExport);
            form.submit();
            form.removeChild(hiddenExport);
        } else if (type === 'print') {
            // Apply filter then trigger print with all records fetched
            var form = document.getElementById('filterForm');
            var formData = new FormData(form);
            formData.set('print_all', '1');
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(r => r.text())
            .then(html => {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var newTableWrap = doc.querySelector('.table-wrap');
                var newPrintHeader = doc.querySelector('.print-only-header');
                if (newTableWrap) document.querySelector('.table-wrap').innerHTML = newTableWrap.innerHTML;
                if (newPrintHeader) document.querySelector('.print-only-header').innerHTML = newPrintHeader.innerHTML;
                window.print();
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('filterForm');
        if (!form) return;

        var advancedFilters = document.getElementById('advancedFilters');
        var filterToggle = document.getElementById('filterToggle');
        if (advancedFilters && filterToggle) {
            var shouldOpen = [
                document.getElementById('start_date'),
                document.getElementById('end_date'),
                form.querySelector('input[name="time_from"]'),
                form.querySelector('input[name="time_to"]')
            ].some(function (field) {
                return field && field.value;
            });

            if (shouldOpen) {
                advancedFilters.classList.add('open');
                filterToggle.setAttribute('aria-expanded', 'true');
                filterToggle.setAttribute('aria-label', 'Less filters');
                filterToggle.setAttribute('title', 'Less filters');
                filterToggle.innerHTML = '<i class="fa-solid fa-sliders"></i>';
            }

            filterToggle.addEventListener('click', function () {
                var isOpen = advancedFilters.classList.toggle('open');
                filterToggle.setAttribute('aria-expanded', String(isOpen));
                var nextLabel = isOpen ? 'Less filters' : 'More filters';
                filterToggle.setAttribute('aria-label', nextLabel);
                filterToggle.setAttribute('title', nextLabel);
                filterToggle.innerHTML = '<i class="fa-solid fa-sliders"></i>';
            });
        }
        
        // Listen to select changes
        var selects = form.querySelectorAll('select');
        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', function () {
                var pageInput = document.getElementById('page_input');
                if (pageInput) pageInput.value = 1;
                form.submit();
            });
        }
        
        // Listen to date input changes
        var dateInputs = form.querySelectorAll('input[type="date"]');
        dateInputs.forEach(function(input) {
            input.addEventListener('change', function () {
                var pageInput = document.getElementById('page_input');
                if (pageInput) pageInput.value = 1;
                form.submit();
            });
        });
    });

    // Auto-refresh the logs table every 5 seconds
    setInterval(function() {
        // Do not refresh if user is actively interacting with inputs or modal is open
        if (document.activeElement.tagName === 'SELECT' || 
            document.activeElement.tagName === 'INPUT' || 
            document.querySelector('#exportModal.show')) {
            return;
        }

        var form = document.getElementById('filterForm');
        if (!form) return;
        
        var formData = new FormData(form);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(html => {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var newContainer = doc.querySelector('#logsContainer');
            var currContainer = document.querySelector('#logsContainer');
            if (newContainer && currContainer) {
                currContainer.innerHTML = newContainer.innerHTML;
            }
        })
        .catch(err => console.error('Error auto-refreshing logs:', err));
    }, 5000);
</script>
