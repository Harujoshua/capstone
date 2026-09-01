<?php
include('auth.php');
include('logger.php');

$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");

// ================= ACTIONS =================
if (isset($_POST['block'])) {
    $id = intval($_POST['block']);
    $res = $conn->query("SELECT name FROM students WHERE id=$id")->fetch_assoc();
    $name = $res['name'] ?? 'Unknown';
    $conn->query("UPDATE students SET status='BLOCKED' WHERE id=$id");
    log_audit('BLOCK', 'Student', $name, "Student blocked.");
    header("Location: students.php");
    exit;
}

if (isset($_POST['unblock'])) {
    $id = intval($_POST['unblock']);
    $res = $conn->query("SELECT name FROM students WHERE id=$id")->fetch_assoc();
    $name = $res['name'] ?? 'Unknown';
    $conn->query("UPDATE students SET status='ACTIVE' WHERE id=$id");
    log_audit('UNBLOCK', 'Student', $name, "Student unblocked.");
    header("Location: students.php");
    exit;
}

if (isset($_POST['delete'])) {
    $id = intval($_POST['delete']);
    if (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin') {
        $res = $conn->query("SELECT name FROM students WHERE id=$id")->fetch_assoc();
        $name = $res['name'] ?? 'Unknown';
        $conn->query("DELETE FROM students WHERE id=$id");
        log_audit('DELETE', 'Student', $name, "Student permanently deleted.");
    }
    header("Location: students.php");
    exit;
}

// ================= SEARCH & FILTER =================
$search = trim($_POST['search'] ?? $_GET['search'] ?? '');
$selected_course = trim($_POST['course'] ?? $_GET['course'] ?? '');
$selected_year = trim($_POST['year_level'] ?? $_GET['year_level'] ?? '');
$selected_section = trim($_POST['section'] ?? $_GET['section'] ?? '');
$selected_status = trim($_POST['status'] ?? $_GET['status'] ?? '');
if (!in_array($selected_status, ['ACTIVE', 'BLOCKED'], true)) {
    $selected_status = '';
}

$where = [];
if ($search !== '') {
    $search_safe = $conn->real_escape_string($search);
    $where[] = "(name LIKE '%$search_safe%' OR rfid_uid LIKE '%$search_safe%')";
}

if ($selected_course !== '') {
    $course_safe = $conn->real_escape_string($selected_course);
    $where[] = "course = '$course_safe'";
}

if ($selected_year !== '') {
    $year_safe = $conn->real_escape_string($selected_year);
    $where[] = "year_level = '$year_safe'";
}

if ($selected_section !== '') {
    $section_safe = $conn->real_escape_string($selected_section);
    $where[] = "section = '$section_safe'";
}

if ($selected_status !== '') {
    $status_safe = $conn->real_escape_string($selected_status);
    $where[] = "status = '$status_safe'";
}

$sql = "SELECT * FROM students" . (count($where) ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY id DESC";
$result = $conn->query($sql);
?>
<?php include('navbar.php'); ?>
<link rel="stylesheet" href="admin_assets/admin_students.css">

<?php
// Statistics
$stats = $conn->query("SELECT 
    COUNT(*) as total, 
    SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status='BLOCKED' THEN 1 ELSE 0 END) as blocked 
FROM students")->fetch_assoc();
?>

<div class="container">
    <div class="page-header">
        <div class="header-content">
            <h2>Student Management</h2>
            <p class="page-subtitle">View and manage all registered students in the system.</p>
        </div>
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Total Students</span>
                <span class="stat-value"><?= number_format($stats['total'] ?? 0) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Active</span>
                <span class="stat-value status-active"><?= number_format($stats['active'] ?? 0) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Blocked</span>
                <span class="stat-value status-blocked"><?= number_format($stats['blocked'] ?? 0) ?></span>
            </div>
        </div>
    </div>

    <div class="controls-panel">
        <form method="POST" action="students.php" class="filter-panel">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="search" placeholder="Search students..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="filter-group">
                <select name="course">
                    <option value="" <?= $selected_course === '' ? 'selected' : '' ?>>All Courses</option>
                    <option value="BSIT" <?= $selected_course === 'BSIT' ? 'selected' : '' ?>>Bachelor of Science in Information Technology (BSIT)</option>
                    <option value="BEED" <?= $selected_course === 'BEED' ? 'selected' : '' ?>>Bachelor of Elementary Education (BEED)</option>
                    <option value="BSBA" <?= $selected_course === 'BSBA' ? 'selected' : '' ?>>Bachelor of Science in Business Administration (BSBA)</option>
                </select>
                <select name="year_level">
                    <option value="" <?= $selected_year === '' ? 'selected' : '' ?>>All Years</option>
                    <option value="1st Year" <?= $selected_year === '1st Year' ? 'selected' : '' ?>>1st Year</option>
                    <option value="2nd Year" <?= $selected_year === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                    <option value="3rd Year" <?= $selected_year === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                    <option value="4th Year" <?= $selected_year === '4th Year' ? 'selected' : '' ?>>4th Year</option>
                </select>
                <select name="section">
                    <option value="" <?= $selected_section === '' ? 'selected' : '' ?>>All Sections</option>
                    <option value="A" <?= $selected_section === 'A' ? 'selected' : '' ?>>A</option>
                    <option value="B" <?= $selected_section === 'B' ? 'selected' : '' ?>>B</option>
                </select>
                <select name="status">
                    <option value="" <?= $selected_status === '' ? 'selected' : '' ?>>All Status</option>
                    <option value="ACTIVE" <?= $selected_status === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                    <option value="BLOCKED" <?= $selected_status === 'BLOCKED' ? 'selected' : '' ?>>Blocked</option>
                </select>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Student Details</th>
                    <th>RFID UID</th>
                    <th>Class / Section</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()) { ?>
                        <tr>
                            <td>
                                <div class="student-info">
                                    <?php if (!empty($row['photo'])): ?>
                                        <img src="../uploads/students/<?= htmlspecialchars($row['photo']) ?>" class="student-photo">
                                    <?php else: ?>
                                        <div class="student-photo-placeholder">
                                            <?= strtoupper(substr($row['name'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="student-text">
                                        <div class="student-name"><?= htmlspecialchars($row['name']) ?></div>

                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="rfid-tag">
                                    <i class="fa-solid fa-id-card"></i>
                                    <?= htmlspecialchars($row['rfid_uid']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="class-badge">
                                    <span class="course"><?= htmlspecialchars($row['course'] ?? 'N/A') ?></span>
                                    <span class="divider"></span>
                                    <span class="level"><?= htmlspecialchars($row['year_level'] ?? '') ?> - <?= htmlspecialchars($row['section'] ?? '') ?></span>
                                </div>
                            </td>
                            <td>
                                <?php if ($row['status'] == "BLOCKED"): ?>
                                    <span class="status-pill status-blocked">
                                        <i class="fa-solid fa-circle-xmark"></i> Blocked
                                    </span>
                                <?php else: ?>
                                    <span class="status-pill status-active">
                                        <i class="fa-solid fa-circle-check"></i> Active
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions justify-end">
                                    <button type="button" class="action-btn edit-btn edit-link" data-id="<?= $row['id'] ?>" title="Edit">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    
                                    <?php if ($row['status'] == "BLOCKED"): ?>
                                        <form method="POST" action="students.php" class="inline-form">
                                            <input type="hidden" name="unblock" value="<?= $row['id'] ?>">
                                            <button type="submit" class="action-btn unblock-btn" title="Unblock">
                                                <i class="fa-solid fa-user-check"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="students.php" class="inline-form">
                                            <input type="hidden" name="block" value="<?= $row['id'] ?>">
                                            <button type="submit" class="action-btn block-btn" title="Block">
                                                <i class="fa-solid fa-user-slash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (($_SESSION['admin_role'] ?? 'sub_admin') === 'super_admin'): ?>
                                    <form method="POST" action="students.php" class="inline-form">
                                        <input type="hidden" name="delete" value="<?= $row['id'] ?>">
                                        <button type="submit" class="action-btn delete-btn" onclick="return confirm('Delete student?')" title="Delete">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty-state">
                            <i class="fa-solid fa-users-slash"></i>
                            <p>No students found matching your criteria.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- Edit Student Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <h3>Edit Student</h3>
        <div id="modalError" class="modal-error">
        </div>
        <form id="editForm">
            <input type="hidden" name="id" id="m_id">
            <div class="form-group"><label>Name</label><input type="text" name="name" id="m_name" class="form-input"></div>
            <div class="form-group"><label>Gender</label>
                <select name="gender" id="m_gender" class="form-select">
                    <option value="">Select...</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
            <div class="modal-row">
                <div class="modal-col"><label>Municipality</label>
                    <select name="municipality" id="m_municipality" class="form-select">
                        <option value="Carranglan">Carranglan</option>
                    </select>
                </div>
                <div class="modal-col"><label>Barangay</label>
                    <select name="barangay" id="m_barangay" class="form-select">
                        <option value="">Select...</option>
                        <option value="Bantug">Bantug</option>
                        <option value="Bunga">Bunga</option>
                        <option value="Burgos">Burgos</option>
                        <option value="Capintalan">Capintalan</option>
                        <option value="General Luna">General Luna</option>
                        <option value="Joson (formerly Digdig)">Joson (formerly Digdig)</option>
                        <option value="Minuli">Minuli</option>
                        <option value="Piut">Piut</option>
                        <option value="Puncan">Puncan</option>
                        <option value="Putlan">Putlan</option>
                        <option value="R. A. Padilla (formerly Baluarte)">R. A. Padilla (formerly Baluarte)</option>
                        <option value="Salazar">Salazar</option>
                        <option value="San Agustin">San Agustin</option>
                        <option value="T. L. Padilla Poblacion (Barangay I)">T. L. Padilla Poblacion (Barangay I)</option>
                        <option value="F. C. Otic Poblacion (Barangay II)">F. C. Otic Poblacion (Barangay II)</option>
                        <option value="D. L. Maglanoc Poblacion (Barangay III)">D. L. Maglanoc Poblacion (Barangay III)</option>
                        <option value="G. S. Rosario Poblacion (Barangay IV)">G. S. Rosario Poblacion (Barangay IV)</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Course</label>
                <select name="course" id="m_course" class="form-select">
                    <option value="">Select...</option>
                    <option value="BSIT">Bachelor of Science in Information Technology (BSIT)</option>
                    <option value="BEED">Bachelor of Elementary Education (BEED)</option>
                    <option value="BSBA">Bachelor of Science in Business Administration (BSBA)</option>
                </select>
            </div>
            <div class="modal-row">
                <div class="modal-col"><label>Year</label>
                    <select name="year_level" id="m_year" class="form-select">
                        <option value="">Select...</option>
                        <option value="1st Year">1st Year</option>
                        <option value="2nd Year">2nd Year</option>
                        <option value="3rd Year">3rd Year</option>
                        <option value="4th Year">4th Year</option>
                    </select>
                </div>
                <div class="modal-col-fixed"><label>Section</label>
                    <select name="section" id="m_section" class="form-select">
                        <option value="">-</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Parent Name</label><input type="text" name="parent_name" id="m_parent_name" class="form-input"></div>
            <div class="form-group"><label>Parent Email</label><input type="email" name="parent_email" id="m_parent_email" class="form-input"></div>
            <div class="form-group"><label>Parent Email 2</label><input type="email" name="parent_email2" id="m_parent_email2" class="form-input"></div>
            <div class="form-group"><label>RFID UID</label><input type="text" name="rfid_uid" id="m_rfid" class="form-input"></div>
            <div class="form-group"><label>Photo</label><input type="file" name="photo" id="m_photo" accept="image/*">
                <div id="m_photo_preview" class="photo-preview"></div>
            </div>
            <div class="modal-buttons">
                <button type="button" id="closeModal" class="btn-cancel">Cancel</button>
                <button type="submit" class="btn-save">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        var filterForm = document.querySelector('form.filter-panel');
        if (!filterForm) return;

        var searchInput = filterForm.querySelector('input[name="search"]');
        var selects = filterForm.querySelectorAll('select');
        var timer;

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    filterForm.submit();
                }, 350);
            });
        }

        selects.forEach(function (select) {
            select.addEventListener('change', function () {
                filterForm.submit();
            });
        });
    })();

    document.addEventListener('click', function (e) {
        const editBtn = e.target.closest('.edit-link');
        if (editBtn) {
            e.preventDefault();
            const id = editBtn.dataset.id;
            openEditModal(id);
        }
    });

    function openEditModal(id) {
        fetch('get_student.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id)
        })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { alert('Student not found'); return; }
                const s = data.student;
                document.getElementById('m_id').value = s.id;
                document.getElementById('m_name').value = s.name || '';
                document.getElementById('m_gender').value = s.gender || '';
                document.getElementById('m_municipality').value = s.municipality || 'Carranglan';
                document.getElementById('m_barangay').value = s.barangay || '';
                document.getElementById('m_course').value = s.course || '';
                document.getElementById('m_year').value = s.year_level || '';
                document.getElementById('m_section').value = s.section || '';
                document.getElementById('m_parent_name').value = s.parent_name || '';
                document.getElementById('m_parent_email').value = s.parent_email || '';
                document.getElementById('m_parent_email2').value = s.parent_email2 || '';
                document.getElementById('m_rfid').value = s.rfid_uid || '';
                document.getElementById('m_photo').value = '';
                const prev = document.getElementById('m_photo_preview');
                if (s.photo) { prev.innerHTML = '<img src="../uploads/students/' + s.photo + '" style="max-width:100px;border-radius:6px;object-fit:cover;">'; } else { prev.innerHTML = ''; }
                document.getElementById('modalError').classList.remove('show');
                document.getElementById('editModal').classList.add('show');
            })
            .catch(() => alert('Failed to fetch student'));
    }

    document.getElementById('m_photo').addEventListener('change', function () {
        const file = this.files && this.files[0];
        const prev = document.getElementById('m_photo_preview');
        if (!file) { prev.innerHTML = ''; return; }
        const reader = new FileReader();
        reader.onload = function (e) { prev.innerHTML = '<img src="' + e.target.result + '" style="max-width:100px;border-radius:6px;object-fit:cover;">'; };
        reader.readAsDataURL(file);
    });

    document.getElementById('closeModal').addEventListener('click', function () {
        document.getElementById('editModal').classList.remove('show');
    });

    document.getElementById('editForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        const form = new FormData(this);
        fetch('register.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: form
        }).then(r => r.json())
            .then(j => {
                if (j && j.success) {
                    location.reload();
                } else {
                    const msg = (j && j.error) ? j.error : 'Save failed';
                    const err = document.getElementById('modalError');
                    err.textContent = msg;
                    err.classList.add('show');
                }
            }).catch(() => {
                const err = document.getElementById('modalError');
                err.textContent = 'Network error';
                err.classList.add('show');
            });
    });
</script>