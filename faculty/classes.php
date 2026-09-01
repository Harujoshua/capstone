<?php
include('../db.php');
include('auth.php');

$teacher_id = intval($_SESSION['faculty_id'] ?? 0);
$teacher_name = $_SESSION['faculty_name'] ?? $_SESSION['faculty_email'] ?? '';

// Get filter values from GET/POST
$selected_level = $_GET['level'] ?? 'all';
$selected_section = $_GET['section'] ?? 'all';

// Build WHERE clause
$where_clause = "(s.teacher_id = $teacher_id OR LOWER(s.teacher) = LOWER('" . $conn->real_escape_string($teacher_name) . "'))";

if ($selected_level !== 'all') {
    $where_clause .= " AND s.year_level = '" . $conn->real_escape_string($selected_level) . "'";
}

if ($selected_section !== 'all') {
    $where_clause .= " AND s.section = '" . $conn->real_escape_string($selected_section) . "'";
}

// Get all classes for teacher
$sql = "SELECT s.*, (SELECT COUNT(*) FROM schedule_students ss WHERE ss.schedule_id=s.id) AS student_count FROM schedules s WHERE $where_clause ORDER BY FIELD(s.day,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), s.start_time ASC";
$res = $conn->query($sql);

// Get available levels
$level_sql = "SELECT DISTINCT year_level FROM schedules WHERE (teacher_id = $teacher_id OR LOWER(teacher) = LOWER('" . $conn->real_escape_string($teacher_name) . "')) ORDER BY year_level";
$level_res = $conn->query($level_sql);
$available_levels = [];
while ($row = $level_res->fetch_assoc()) {
    $available_levels[] = $row['year_level'];
}

// Get available sections
$section_sql = "SELECT DISTINCT section FROM schedules WHERE (teacher_id = $teacher_id OR LOWER(teacher) = LOWER('" . $conn->real_escape_string($teacher_name) . "')) ORDER BY section";
$section_res = $conn->query($section_sql);
$available_sections = [];
while ($row = $section_res->fetch_assoc()) {
    $available_sections[] = $row['section'];
}
?>
<!doctype html>
<html>

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Faculty - Classes</title>
    <link rel="stylesheet" href="faculty_assets/faculty_classes.css">
    <link rel="stylesheet" href="faculty_assets/faculty_sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>

<body>
    <div class="app">

        <?php include('navbar.php'); ?>

        <main class="content">
            <div class="header">
                <div class="title">My Classes</div>
            </div>

            <div class="card">
                <div class="filters">
                    <form method="GET" class="filters-form">
                        <select name="level" onchange="this.form.submit()">
                            <option value="all">All Levels</option>
                            <?php foreach ($available_levels as $level): ?>
                                <option value="<?= htmlspecialchars($level) ?>" <?= ($selected_level === $level) ? 'selected' : '' ?>>
                                    Year <?= htmlspecialchars($level) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="section" onchange="this.form.submit()">
                            <option value="all">All Sections</option>
                            <?php foreach ($available_sections as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>" <?= ($selected_section === $sec) ? 'selected' : '' ?>>
                                    Section <?= htmlspecialchars($sec) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if ($selected_level !== 'all' || $selected_section !== 'all'): ?>
                            <a href="classes.php" class="btn">Clear Filters</a>
                        <?php endif; ?>
                        <button type="button" onclick="window.print()" class="btn" style="background:#ef4444; color: white;">
                            <i class="fas fa-print"></i> Print Schedule
                        </button>
                    </form>
                </div>
            </div>
            <?php
            $faculty_dept_code = $_SESSION['faculty_dept'] ?? '';
            $print_dept = "College of Information and Communications Technology";
            $print_logo = "../assets/CICT.png";

            if ($faculty_dept_code === 'BSIT') {
                $print_dept = "College of Information and Communications Technology - Bachelor of Science in Information Technology";
                $print_logo = "../assets/CICT.png";
            } elseif ($faculty_dept_code === 'BEED') {
                $print_dept = "College of Education - Bachelor of Elementary Education";
                $print_logo = "../assets/COED.png";
            } elseif ($faculty_dept_code === 'BSBA') {
                $print_dept = "College of Management and Business Technology - Bachelor of Science in Business Administration";
                $print_logo = "../assets/BSBA.png";
            }
            ?>
            <div class="print-header">
                <img src="<?= htmlspecialchars($print_logo) ?>" alt="Department Logo" class="print-logo">
                <div class="print-text-content">
                    <div class="print-title">Class Schedule</div>
                    <div class="print-subtitle">
                        <div><strong>Faculty:</strong> <?= htmlspecialchars($_SESSION['faculty_name'] ?? 'N/A') ?></div>
                        <div><strong>Department:</strong> <?= htmlspecialchars($print_dept) ?></div>
                        <div><strong>Year Level:</strong> <?= $selected_level === 'all' ? 'All Levels' : 'Year ' . htmlspecialchars($selected_level) ?></div>
                        <div><strong>Section:</strong> <?= $selected_section === 'all' ? 'All Sections' : 'Section ' . htmlspecialchars($selected_section) ?></div>
                    </div>
                </div>
                <img src="../assets/neust_logo.png" alt="NEUST Logo" class="print-logo">
            </div>

            <div class="card">
                <?php if ($res && $res->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Course</th>
                                    <th>Year & Section</th>
                                    <th>Schedule</th>
                                    <th>Room</th>
                                    <th>Students</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $res->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['subject']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['course']) ?></td>
                                        <td><?= htmlspecialchars($row['year_level'] . ' - ' . $row['section']) ?></td>
                                        <td>
                                            <span class="badge"><?= htmlspecialchars($row['day']) ?></span><br>
                                            <small><?= date('h:i A', strtotime($row['start_time'])) ?> -
                                                <?= date('h:i A', strtotime($row['end_time'])) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($row['room'] ?? 'N/A') ?></td>
                                        <td><span class="badge success"><?= intval($row['student_count']) ?> Students</span>
                                        </td>
                                        <td>
                                            <form method="post" action="students.php" class="inline-form">
                                                <input type="hidden" name="schedule_id" value="<?= intval($row['id']) ?>">
                                                <button type="submit" class="btn">View Students</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-classes">
                        <p>No classes found in your schedule.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

</body>

</html>