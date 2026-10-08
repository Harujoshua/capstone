<?php
date_default_timezone_set('Asia/Manila');

$admin_db = 'neust_gatepass_v3';
$admin_conn = new mysqli('localhost', 'root', '', $admin_db);

if ($admin_conn->connect_error) {
    die("Unable to connect to admin database '{$admin_db}': " . $admin_conn->connect_error );
}

$admin_conn->query("SET time_zone = '+08:00'");

// Database connection established successfully.
// Schema migrations should be handled in migration/setup scripts.

if (!function_exists('get_admin_setting')) {
    function get_admin_setting($conn, $name, $default = '') {
        $safeName = $conn->real_escape_string($name);
        $res = $conn->query("SELECT value FROM settings WHERE name='$safeName' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            return $row['value'];
        }
        return $default;
    }
}

if (!function_exists('set_admin_setting')) {
    function set_admin_setting($conn, $name, $value) {
        $safeName = $conn->real_escape_string($name);
        $safeVal = $conn->real_escape_string($value);
        return $conn->query("INSERT INTO settings (name, value) VALUES ('$safeName', '$safeVal') ON DUPLICATE KEY UPDATE value='$safeVal'");
    }
}

if (!function_exists('check_schedule_conflict')) {
    /**
     * Checks if a proposed schedule conflicts with existing schedules.
     * Prevents:
     * 1. Same or overlapping schedule time for the same faculty/teacher.
     * 2. Same or overlapping schedule time for the same room.
     * 3. Same or overlapping schedule time for the same course, year level, and section.
     *
     * @param mysqli $conn Database connection
     * @param string $day Day of the week (e.g. 'Monday', 'Tuesday')
     * @param string $start_time Start time (e.g. '08:00' or '08:00:00')
     * @param string $end_time End time (e.g. '10:00' or '10:00:00')
     * @param string $school_year Active school year
     * @param string $semester Active semester
     * @param int $teacher_id Faculty ID (optional)
     * @param string $teacher_name Faculty/Teacher name (optional)
     * @param string $room Room number/name (optional)
     * @param string $course Course code (optional)
     * @param string $year_level Year level (optional)
     * @param string $section Section (optional)
     * @param int $exclude_id Schedule ID to exclude when editing (optional)
     * @return string|null Returns descriptive conflict message if conflict exists, or null if no conflict.
     */
    function check_schedule_conflict(
        $conn,
        $day,
        $start_time,
        $end_time,
        $school_year = '',
        $semester = '',
        $teacher_id = 0,
        $teacher_name = '',
        $room = '',
        $course = '',
        $year_level = '',
        $section = '',
        $exclude_id = 0
    ) {
        $day = trim($day ?? '');
        $start_time = trim($start_time ?? '');
        $end_time = trim($end_time ?? '');

        if ($day === '' || $start_time === '' || $end_time === '') {
            return null;
        }

        $st_ts = strtotime($start_time);
        $en_ts = strtotime($end_time);
        if ($st_ts === false || $en_ts === false || $st_ts >= $en_ts) {
            return "Invalid schedule time: End time must be later than start time.";
        }

        $st_norm = date('H:i:s', $st_ts);
        $en_norm = date('H:i:s', $en_ts);
        $d_esc = $conn->real_escape_string($day);
        $d_short = $conn->real_escape_string(substr($day, 0, 3));
        $exclude_id = intval($exclude_id);
        $exclude_sql = ($exclude_id > 0) ? "AND s.id != $exclude_id" : "";

        // Match day (exact, comma-separated or abbreviation)
        $day_match_sql = "(s.day = '$d_esc' OR s.day LIKE '%$d_esc%' OR s.day = '$d_short' OR s.day LIKE '%$d_short%')";

        // Match active school year and semester if provided
        $term_conditions = [];
        if (!empty($school_year)) {
            $sy_esc = $conn->real_escape_string($school_year);
            $term_conditions[] = "(s.school_year = '$sy_esc' OR s.school_year IS NULL OR s.school_year = '')";
        }
        if (!empty($semester)) {
            $sem_esc = $conn->real_escape_string($semester);
            $term_conditions[] = "(s.semester = '$sem_esc' OR s.semester IS NULL OR s.semester = '')";
        }
        $term_match_sql = !empty($term_conditions) ? implode(' AND ', $term_conditions) : "1=1";

        // Time overlap: two intervals [s.start_time, s.end_time] and [$st_norm, $en_norm] overlap
        $time_overlap_sql = "s.start_time IS NOT NULL AND s.end_time IS NOT NULL AND (s.start_time < '$en_norm' AND s.end_time > '$st_norm')";

        // 1. Check Faculty / Teacher Conflict
        $teacher_id = intval($teacher_id);
        $t_name_clean = trim($teacher_name ?? '');
        $t_esc = $conn->real_escape_string($t_name_clean);

        if ($teacher_id > 0 || $t_name_clean !== '') {
            $teacher_conds = [];
            if ($teacher_id > 0) {
                $teacher_conds[] = "s.teacher_id = $teacher_id";
            }
            if ($t_name_clean !== '') {
                $teacher_conds[] = "(s.teacher IS NOT NULL AND TRIM(s.teacher) != '' AND LOWER(TRIM(s.teacher)) = LOWER('$t_esc'))";
            }
            $teacher_where = "(" . implode(' OR ', $teacher_conds) . ")";

            $sql = "SELECT s.id, s.subject, s.teacher, s.course, s.year_level, s.section, s.day, s.start_time, s.end_time, s.room 
                    FROM schedules s 
                    WHERE $teacher_where 
                      AND $day_match_sql 
                      AND $term_match_sql 
                      AND $time_overlap_sql 
                      $exclude_sql 
                    LIMIT 1";
            $res = $conn->query($sql);
            if ($res && $row = $res->fetch_assoc()) {
                $t_display = $row['teacher'] ?: ($t_name_clean ?: 'Faculty member');
                $c_start = date('h:i A', strtotime($row['start_time']));
                $c_end = date('h:i A', strtotime($row['end_time']));
                $class_info = trim(($row['course'] ?? '') . ' ' . ($row['year_level'] ?? '') . ' ' . ($row['section'] ?? ''));
                $subj_info = $row['subject'] ?: 'Class';
                return "Faculty Conflict on {$day}: {$t_display} already has a conflicting schedule ({$subj_info}" . ($class_info !== '' ? " - {$class_info}" : "") . ") from {$c_start} to {$c_end}" . (!empty($row['room']) ? " in Room {$row['room']}" : "") . ".";
            }
        }

        // 2. Check Room Conflict (if room is provided and not TBA/Online/NA)
        $room_clean = trim($room ?? '');
        if ($room_clean !== '' && !in_array(strtoupper($room_clean), ['TBA', 'ONLINE', 'N/A', '-', 'NONE'])) {
            $r_esc = $conn->real_escape_string($room_clean);
            $sql = "SELECT s.id, s.subject, s.teacher, s.course, s.year_level, s.section, s.day, s.start_time, s.end_time, s.room 
                    FROM schedules s 
                    WHERE LOWER(TRIM(s.room)) = LOWER('$r_esc') 
                      AND $day_match_sql 
                      AND $term_match_sql 
                      AND $time_overlap_sql 
                      $exclude_sql 
                    LIMIT 1";
            $res = $conn->query($sql);
            if ($res && $row = $res->fetch_assoc()) {
                $c_start = date('h:i A', strtotime($row['start_time']));
                $c_end = date('h:i A', strtotime($row['end_time']));
                $t_occ = !empty($row['teacher']) ? " (Instructor: " . $row['teacher'] . ")" : "";
                return "Room Conflict on {$day}: Room '{$room_clean}' is already booked from {$c_start} to {$c_end} by '{$row['subject']}'{$t_occ}.";
            }
        }

        // 3. Check Section Conflict (if course and section are specified)
        $course_clean = trim($course ?? '');
        $section_clean = trim($section ?? '');
        $year_clean = trim($year_level ?? '');
        if ($course_clean !== '' && $section_clean !== '') {
            $c_esc = $conn->real_escape_string($course_clean);
            $sec_esc = $conn->real_escape_string($section_clean);
            $sec_where = "LOWER(TRIM(s.course)) = LOWER('$c_esc') AND LOWER(TRIM(s.section)) = LOWER('$sec_esc')";
            if ($year_clean !== '') {
                $y_esc = $conn->real_escape_string($year_clean);
                $sec_where .= " AND (s.year_level IS NULL OR s.year_level = '' OR LOWER(TRIM(s.year_level)) = LOWER('$y_esc'))";
            }
            $sql = "SELECT s.id, s.subject, s.teacher, s.course, s.year_level, s.section, s.day, s.start_time, s.end_time, s.room 
                    FROM schedules s 
                    WHERE $sec_where 
                      AND $day_match_sql 
                      AND $term_match_sql 
                      AND $time_overlap_sql 
                      $exclude_sql 
                    LIMIT 1";
            $res = $conn->query($sql);
            if ($res && $row = $res->fetch_assoc()) {
                $c_start = date('h:i A', strtotime($row['start_time']));
                $c_end = date('h:i A', strtotime($row['end_time']));
                $sec_label = $course_clean . ($year_clean ? " {$year_clean}" : "") . " Section {$section_clean}";
                return "Section Conflict on {$day}: {$sec_label} already has a scheduled class ('{$row['subject']}') from {$c_start} to {$c_end}.";
            }
        }

        return null;
    }
}
?>
