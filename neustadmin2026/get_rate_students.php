<?php
include('auth.php');
header('Content-Type: application/json; charset=utf-8');

$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

// Fetch active school year & semester settings
$active_school_year = '2025-2026';
$active_semester = '1st Semester';
$term_q = $conn->query("SELECT name, value FROM settings WHERE name IN ('active_school_year', 'active_semester')");
if ($term_q) {
    while ($row = $term_q->fetch_assoc()) {
        if ($row['name'] === 'active_school_year' && !empty($row['value'])) {
            $active_school_year = $row['value'];
        } elseif ($row['name'] === 'active_semester' && !empty($row['value'])) {
            $active_semester = $row['value'];
        }
    }
}

$safe_sy = $conn->real_escape_string($active_school_year);
$safe_sem = $conn->real_escape_string($active_semester);

// Parameters
$rate_type  = trim($_REQUEST['rate_type'] ?? 'ontime'); // 'ontime', 'late', 'absent', or 'all'
$course     = trim($_REQUEST['course'] ?? '');
$year_level = trim($_REQUEST['year_level'] ?? '');
$section    = trim($_REQUEST['section'] ?? '');
$search     = trim($_REQUEST['search'] ?? '');

// Map rate_type to attendance status
$status_map = [
    'ontime' => 'Present',
    'late'   => 'Late',
    'absent' => 'Absent'
];

$target_status = $status_map[$rate_type] ?? null;

// Build WHERE conditions
$where = " WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'";

if ($target_status !== null) {
    $safe_status = $conn->real_escape_string($target_status);
    $where .= " AND a.status = '$safe_status'";
}

if ($course !== '' && $course !== 'all') {
    $safe_c = $conn->real_escape_string($course);
    $where .= " AND (stud.course = '$safe_c' OR (stud.course IS NULL AND s.course = '$safe_c'))";
}

if ($year_level !== '' && $year_level !== 'all') {
    $safe_yl = $conn->real_escape_string($year_level);
    $where .= " AND (stud.year_level = '$safe_yl' OR (stud.year_level IS NULL AND s.year_level = '$safe_yl'))";
}

if ($section !== '' && $section !== 'all') {
    $safe_sec = $conn->real_escape_string($section);
    $where .= " AND (stud.section = '$safe_sec' OR (stud.section IS NULL AND s.section = '$safe_sec'))";
}

if ($search !== '') {
    $safe_s = $conn->real_escape_string($search);
    $where .= " AND (
        stud.name LIKE '%$safe_s%' 
        OR a.name LIKE '%$safe_s%' 
        OR stud.rfid_uid LIKE '%$safe_s%' 
        OR s.subject LIKE '%$safe_s%' 
        OR s.teacher LIKE '%$safe_s%'
    )";
}

// Global counts for active term (unfiltered by status/course/search to display in tab badges)
$counts_q = $conn->query("
    SELECT 
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS ontime_count,
        SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_count,
        COUNT(a.id) AS total_count
    FROM attendance a
    JOIN schedules s ON a.schedule_id = s.id
    WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
");
$counts_row = $counts_q ? $counts_q->fetch_assoc() : [];
$ontime_total = intval($counts_row['ontime_count'] ?? 0);
$late_total   = intval($counts_row['late_count'] ?? 0);
$absent_total = intval($counts_row['absent_count'] ?? 0);
$all_total    = intval($counts_row['total_count'] ?? 0);

// Get distinct courses and sections for active term to populate dropdown filters
$courses_list = [];
$courses_q = $conn->query("
    SELECT DISTINCT course FROM (
        SELECT COALESCE(NULLIF(stud.course, ''), s.course) AS course
        FROM attendance a
        JOIN schedules s ON a.schedule_id = s.id
        LEFT JOIN students stud ON a.student_id = stud.id
        WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
        UNION
        SELECT course FROM students WHERE course IS NOT NULL AND course != ''
    ) AS courses_sub
    WHERE course IS NOT NULL AND course != ''
    ORDER BY course ASC
");
if ($courses_q) {
    while ($row = $courses_q->fetch_assoc()) {
        if (!empty($row['course'])) {
            $courses_list[] = $row['course'];
        }
    }
}

$sections_list = [];
$sections_q = $conn->query("
    SELECT DISTINCT section FROM (
        SELECT COALESCE(NULLIF(stud.section, ''), s.section) AS section
        FROM attendance a
        JOIN schedules s ON a.schedule_id = s.id
        LEFT JOIN students stud ON a.student_id = stud.id
        WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
        UNION
        SELECT section FROM students WHERE section IS NOT NULL AND section != ''
    ) AS sec_sub
    WHERE section IS NOT NULL AND section != ''
    ORDER BY section ASC
");
if ($sections_q) {
    while ($row = $sections_q->fetch_assoc()) {
        if (!empty($row['section'])) {
            $sections_list[] = $row['section'];
        }
    }
}

// Fetch attendance records
$sql = "
    SELECT 
        a.id AS attendance_id,
        a.student_id,
        COALESCE(NULLIF(stud.name, ''), a.name, 'Unknown Student') AS student_name,
        COALESCE(stud.rfid_uid, 'N/A') AS rfid_uid,
        COALESCE(stud.photo, '') AS photo,
        COALESCE(stud.gender, '') AS gender,
        COALESCE(NULLIF(stud.course, ''), s.course, 'Unassigned') AS course,
        COALESCE(NULLIF(stud.year_level, ''), s.year_level, '') AS year_level,
        COALESCE(NULLIF(stud.section, ''), s.section, '') AS section,
        a.status,
        a.attendance_date,
        a.time_logged,
        s.id AS schedule_id,
        COALESCE(s.subject, 'General Class') AS subject,
        COALESCE(s.teacher, 'N/A') AS teacher,
        COALESCE(s.room, 'N/A') AS room,
        s.start_time,
        s.end_time,
        s.school_year,
        s.semester
    FROM attendance a
    JOIN schedules s ON a.schedule_id = s.id
    LEFT JOIN students stud ON a.student_id = stud.id
    $where
    ORDER BY a.attendance_date DESC, a.time_logged DESC, a.id DESC
";

$records_q = $conn->query($sql);
$records = [];
$student_map = [];

if ($records_q) {
    while ($row = $records_q->fetch_assoc()) {
        $formatted_date = !empty($row['attendance_date']) ? date('M d, Y', strtotime($row['attendance_date'])) : 'N/A';
        $formatted_time = !empty($row['time_logged']) ? date('h:i A', strtotime($row['time_logged'])) : 'N/A';
        $sched_time = (!empty($row['start_time']) && !empty($row['end_time']))
            ? date('h:i A', strtotime($row['start_time'])) . ' - ' . date('h:i A', strtotime($row['end_time']))
            : '';

        $record_item = [
            'attendance_id'   => intval($row['attendance_id']),
            'student_id'      => intval($row['student_id']),
            'student_name'    => $row['student_name'],
            'rfid_uid'        => $row['rfid_uid'],
            'photo'           => $row['photo'],
            'gender'          => $row['gender'],
            'course'          => $row['course'],
            'year_level'      => $row['year_level'],
            'section'         => $row['section'],
            'status'          => $row['status'],
            'attendance_date' => $row['attendance_date'],
            'formatted_date'  => $formatted_date,
            'time_logged'     => $row['time_logged'],
            'formatted_time'  => $formatted_time,
            'schedule_id'     => intval($row['schedule_id']),
            'subject'         => $row['subject'],
            'teacher'         => $row['teacher'],
            'room'            => $row['room'],
            'schedule_time'   => $sched_time,
        ];
        $records[] = $record_item;

        // Group by student for student summary view
        $sid = $row['student_id'] > 0 ? 'id_' . $row['student_id'] : 'name_' . md5($row['student_name']);
        if (!isset($student_map[$sid])) {
            $student_map[$sid] = [
                'student_id'     => intval($row['student_id']),
                'student_name'   => $row['student_name'],
                'rfid_uid'       => $row['rfid_uid'],
                'photo'          => $row['photo'],
                'gender'         => $row['gender'],
                'course'         => $row['course'],
                'year_level'     => $row['year_level'],
                'section'        => $row['section'],
                'count'          => 0,
                'status'         => $row['status'],
                'latest_date'    => $formatted_date,
                'latest_time'    => $formatted_time,
                'latest_subject' => $row['subject'],
                'sessions'       => []
            ];
        }
        $student_map[$sid]['count']++;
        if (count($student_map[$sid]['sessions']) < 5) {
            $student_map[$sid]['sessions'][] = [
                'date'    => $formatted_date,
                'time'    => $formatted_time,
                'subject' => $row['subject'],
                'status'  => $row['status']
            ];
        }
    }
}

// Convert student map to indexed array and sort by count descending
$student_summaries = array_values($student_map);
usort($student_summaries, function ($a, $b) {
    if ($b['count'] === $a['count']) {
        return strcmp($a['student_name'], $b['student_name']);
    }
    return $b['count'] - $a['count'];
});

echo json_encode([
    'success'           => true,
    'active_term'       => [
        'school_year' => $active_school_year,
        'semester'    => $active_semester,
    ],
    'rate_type'         => $rate_type,
    'target_status'     => $target_status,
    'counts'            => [
        'ontime' => $ontime_total,
        'late'   => $late_total,
        'absent' => $absent_total,
        'total'  => $all_total,
    ],
    'filtered_count'    => count($records),
    'unique_students'   => count($student_summaries),
    'records'           => $records,
    'student_summaries' => $student_summaries,
    'courses_list'      => $courses_list,
    'sections_list'     => $sections_list,
], JSON_UNESCAPED_UNICODE);
