<?php
include('auth.php');
$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");

// ================= CLEAR ALL LOGS =================
if (isset($_POST['clear_all'])) {
    if (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin') {
        $conn->query("TRUNCATE TABLE logs");
        include_once('logger.php');
        log_audit('DELETE', 'Logs', 'All Logs', 'All attendance logs cleared/truncated.');
    }
    header("Location: logs.php");
    exit;
}

// ================= SEARCH + FILTER =================
$filter = $_REQUEST['filter'] ?? '';
$selected_date = $_REQUEST['log_date'] ?? '';
$start_date = $_REQUEST['start_date'] ?? '';
$end_date = $_REQUEST['end_date'] ?? '';

// If single log_date is set without start_date/end_date, populate them
if (!empty($selected_date) && empty($start_date) && empty($end_date)) {
    $start_date = $selected_date;
    $end_date = $selected_date;
}

$sql = "SELECT logs.*, students.course AS course, students.year_level AS year_level, students.section AS section \n";
$sql .= "FROM logs LEFT JOIN students ON logs.rfid_uid = students.rfid_uid WHERE 1=1";

if (!empty($filter) && ($filter == "IN" || $filter == "OUT")) {
    $sql .= " AND logs.status='$filter'";
}

// Date filter
if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $safe_end = $conn->real_escape_string($end_date);
    $sql .= " AND DATE(COALESCE(time_in, time_out)) BETWEEN '$safe_start' AND '$safe_end'";
} elseif (!empty($start_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $sql .= " AND DATE(COALESCE(time_in, time_out)) >= '$safe_start'";
} elseif (!empty($end_date)) {
    $safe_end = $conn->real_escape_string($end_date);
    $sql .= " AND DATE(COALESCE(time_in, time_out)) <= '$safe_end'";
}

// Course / Year / Section filters
$course = trim($_REQUEST['course'] ?? '');
$year_level = trim($_REQUEST['year_level'] ?? '');
$section = trim($_REQUEST['section'] ?? '');

if ($course !== '') {
    $safe = $conn->real_escape_string($course);
    $sql .= " AND students.course = '$safe'";
}

if ($year_level !== '') {
    $safe = $conn->real_escape_string($year_level);
    $sql .= " AND students.year_level = '$safe'";
}

if ($section !== '') {
    $safe = $conn->real_escape_string($section);
    $sql .= " AND students.section = '$safe'";
}

$sql .= " ORDER BY COALESCE(time_in, time_out) DESC, id DESC";
$result = $conn->query($sql);

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

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_logs_' . $date_suffix . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Output column headings
    fputcsv($output, ['Name', 'RFID UID', 'Course', 'Year', 'Section', 'Status', 'Time IN', 'Time OUT', 'Date']);
    
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
            $row['course'] ?? '',
            $row['year_level'] ?? '',
            $row['section'] ?? '',
            $row['status'],
            $time_in,
            $time_out,
            $row_date
        ]);
    }
    fclose($output);
    exit;
}
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
                <h3>ATTENDANCE LOGS REPORT</h3>
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
                    <?php if (!empty($course)): ?> &bull; <strong>Course:</strong> <?= htmlspecialchars($course) ?><?php endif; ?>
                    <?php if (!empty($year_level)): ?> &bull; <strong>Year:</strong> <?= htmlspecialchars($year_level) ?><?php endif; ?>
                    <?php if (!empty($section)): ?> &bull; <strong>Section:</strong> <?= htmlspecialchars($section) ?><?php endif; ?>
                    <?php if (!empty($filter)): ?> &bull; <strong>Status:</strong> <?= htmlspecialchars($filter) ?><?php endif; ?>
                </p>
                <p class="print-timestamp">Generated on: <?= date('F j, Y h:i A') ?></p>
            </div>
        </div>
    </div>

    <!-- ================= CONTROLS ================= -->
    <div class="controls">
        <form id="filterForm" method="POST">

            <select name="filter" title="Status Filter">
                <option value="">ALL STATUS</option>
                <option value="IN" <?= $filter == "IN" ? "selected" : "" ?>>IN</option>
                <option value="OUT" <?= $filter == "OUT" ? "selected" : "" ?>>OUT</option>
            </select>

            <select name="course" title="Course Filter">
                <option value="">All courses</option>
                <option value="BSIT" <?= (isset($course) && $course === 'BSIT') ? "selected" : "" ?>>BSIT</option>
                <option value="BEED" <?= (isset($course) && $course === 'BEED') ? "selected" : "" ?>>BEED</option>
                <option value="BSBA" <?= (isset($course) && $course === 'BSBA') ? "selected" : "" ?>>BSBA</option>
            </select>

            <select name="year_level" title="Year Level Filter">
                <option value="">All years</option>
                <option value="1st Year" <?= (isset($year_level) && $year_level === '1st Year') ? "selected" : "" ?>>1st Year</option>
                <option value="2nd Year" <?= (isset($year_level) && $year_level === '2nd Year') ? "selected" : "" ?>>2nd Year</option>
                <option value="3rd Year" <?= (isset($year_level) && $year_level === '3rd Year') ? "selected" : "" ?>>3rd Year</option>
                <option value="4th Year" <?= (isset($year_level) && $year_level === '4th Year') ? "selected" : "" ?>>4th Year</option>
            </select>

            <select name="section" title="Section Filter">
                <option value="">All sections</option>
                <option value="A" <?= (isset($section) && $section === 'A') ? "selected" : "" ?>>A</option>
                <option value="B" <?= (isset($section) && $section === 'B') ? "selected" : "" ?>>B</option>
            </select>

            <!-- Date Range Controls -->
            <div class="date-range-wrap">
                <div class="date-input-box">
                    <label>From:</label>
                    <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" title="Start Date">
                </div>
                <div class="date-input-box">
                    <label>To:</label>
                    <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" title="End Date">
                </div>
            </div>

            <!-- Quick Date Presets -->
            <select id="quickPreset" onchange="applyPreset(this.value)" class="preset-select" title="Date Range Preset">
                <option value="">Custom / Presets...</option>
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="this_week">This Week</option>
                <option value="this_month">This Month</option>
                <option value="all">All Dates</option>
            </select>

            <button type="button" onclick="openExportModal()" class="btn-export-options" title="Configure Export / Print Date Range">
                <i class="fa-solid fa-calendar-days"></i> Select Date & Export
            </button>

            <?php if (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin'): ?>
            <button type="button" onclick="clearLogs()" class="btn-clear">CLEAR LOGS</button>
            <?php endif; ?>

        </form>

        <form id="clearForm" method="POST" class="hidden-form">
            <input type="hidden" name="clear_all" value="1">
        </form>
    </div>

    <br>

    <div class="table-wrap">
    <table class="table">

        <tr>
            <th>Name</th>
            <th>RFID UID</th>
            <th>Course</th>
            <th>Year</th>
            <th>Section</th>
            <th>Status</th>
            <th>Time IN</th>
            <th>Time OUT</th>
        </tr>

        <?php
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
                    <td colspan="8">
                        <?= $current_date ? date('F j, Y', strtotime($current_date)) : 'No date' ?>
                    </td>
                </tr>
                <?php
            }
            ?>

            <tr>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars($row['rfid_uid']) ?></td>

                <td><?= htmlspecialchars($row['course'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['year_level'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['section'] ?? '') ?></td>

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

        <?php } ?>

    </table>
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
        if (confirm("ARE YOU SURE YOU WANT TO DELETE ALL LOGS?")) {
            document.getElementById('clearForm').submit();
        }
    }

    function formatDate(d) {
        var year = d.getFullYear();
        var month = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        return year + '-' + month + '-' + day;
    }

    function applyPreset(preset) {
        var startInput = document.getElementById('start_date');
        var endInput = document.getElementById('end_date');
        if (!startInput || !endInput) return;

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

        document.getElementById('filterForm').submit();
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
            // Apply filter then trigger print
            var form = document.getElementById('filterForm');
            var formData = new FormData(form);
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
        
        // Listen to select changes
        var selects = form.querySelectorAll('select:not(#quickPreset)');
        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', function () {
                form.submit();
            });
        }
        
        // Listen to date input changes
        var dateInputs = form.querySelectorAll('input[type="date"]');
        dateInputs.forEach(function(input) {
            input.addEventListener('change', function () {
                document.getElementById('quickPreset').value = '';
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
            var newTableWrap = doc.querySelector('.table-wrap');
            if (newTableWrap) {
                document.querySelector('.table-wrap').innerHTML = newTableWrap.innerHTML;
            }
        })
        .catch(err => console.error('Error auto-refreshing logs:', err));
    }, 5000);
</script>