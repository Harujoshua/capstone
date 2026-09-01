<?php
header('Content-Type: application/json');
include('auth.php');
require_once(__DIR__ . '/../classes/OllamaService.php');

$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database Connection Failed: ' . $conn->connect_error]);
    exit;
}

// Fetch active term settings from admin db
$active_school_year = '2025-2026';
$active_semester = '1st Semester';
$term_q = $conn->query("SELECT name, value FROM settings WHERE name IN ('active_school_year', 'active_semester')");
if ($term_q) {
    while ($row = $term_q->fetch_assoc()) {
        if ($row['name'] === 'active_school_year') {
            $active_school_year = $row['value'];
        } elseif ($row['name'] === 'active_semester') {
            $active_semester = $row['value'];
        }
    }
}
$safe_sy = $conn->real_escape_string($active_school_year);
$safe_sem = $conn->real_escape_string($active_semester);

// Parse JSON request input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$model = $input['model'] ?? 'deepseek-r1:1.5b';
$host = $input['host'] ?? 'http://localhost:11434';

// Instantiate Ollama Client
$ollama = new OllamaService($model, $host);

try {
    switch ($action) {
        case 'check_status':
            $available = $ollama->isAvailable();
            $models = [];
            if ($available) {
                $models = $ollama->getLocalModels();
            }
            echo json_encode([
                'success' => true,
                'available' => $available,
                'models' => $models,
                'current_model' => $model
            ]);
            break;

        case 'analyze_attendance':
            // 1. Gather live statistics
            $attendance_q = $conn->query("
                SELECT 
                    COUNT(a.id) AS total, 
                    SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
                    SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) AS late_count,
                    SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_count
                FROM attendance a
                JOIN schedules s ON a.schedule_id = s.id
                WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
            ");
            $attendance_data = $attendance_q ? $attendance_q->fetch_assoc() : null;
            $total_records = intval($attendance_data['total'] ?? 0);
            $present_count = intval($attendance_data['present_count'] ?? 0);
            $late_count = intval($attendance_data['late_count'] ?? 0);
            $absent_count = intval($attendance_data['absent_count'] ?? 0);
            $attended_count = $present_count + $late_count;

            $overall_rate = $total_records > 0 ? round(($attended_count / $total_records) * 100, 1) : 0;
            $ontime_rate = $total_records > 0 ? round(($present_count / $total_records) * 100, 1) : 0;
            $absent_rate = $total_records > 0 ? round(($absent_count / $total_records) * 100, 1) : 0;
            $late_rate = $total_records > 0 ? round(($late_count / $total_records) * 100, 1) : 0;

            // At-Risk
            $at_risk_q = $conn->query("
                SELECT COUNT(*) AS c FROM (
                    SELECT 
                        a.student_id, 
                        COUNT(a.id) AS total_classes,
                        SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS attended_classes
                    FROM attendance a
                    JOIN schedules s ON a.schedule_id = s.id
                    WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
                    GROUP BY a.student_id
                    HAVING total_classes > 0 AND (attended_classes / total_classes * 100) < 80.0
                ) AS sub
            ");
            $at_risk_count = $at_risk_q ? intval($at_risk_q->fetch_assoc()['c'] ?? 0) : 0;

            // Course On-time breakdown
            $dept_q = $conn->query("
                SELECT 
                    stud.course,
                    COUNT(a.id) AS total,
                    SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present_count
                FROM attendance a
                JOIN students stud ON a.student_id = stud.id
                JOIN schedules s ON a.schedule_id = s.id
                WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
                GROUP BY stud.course
                ORDER BY (SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(a.id)) DESC
            ");
            $departments = [];
            while ($row = $dept_q->fetch_assoc()) {
                $d_rate = $row['total'] > 0 ? round(($row['present_count'] / $row['total']) * 100, 1) : 0;
                $departments[] = ($row['course'] ? $row['course'] : 'Unassigned') . " (" . $d_rate . "% on-time rate over " . $row['total'] . " classes)";
            }
            $dept_list = implode(', ', $departments);

            // Fetch top 3 at-risk students for immediate spotlight
            $at_risk_detail_q = $conn->query("
                SELECT stud.name, stud.course, 
                       ROUND((SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) / COUNT(a.id) * 100), 1) AS attendance_rate
                FROM attendance a
                JOIN schedules s ON a.schedule_id = s.id
                JOIN students stud ON a.student_id = stud.id
                WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
                GROUP BY a.student_id
                HAVING (SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) / COUNT(a.id) * 100) < 80.0
                ORDER BY attendance_rate ASC
                LIMIT 3
            ");
            $at_risk_names = [];
            while ($row = $at_risk_detail_q->fetch_assoc()) {
                $at_risk_names[] = $row['name'] . " (" . $row['course'] . ": " . $row['attendance_rate'] . "% attendance)";
            }
            $at_risk_spotlight = implode(', ', $at_risk_names);

            // Proactive Trend Alerts (Early Warning System) - slope analysis on last 150 entries
            $trend_q = $conn->query("
                SELECT stud.name, stud.course, stud.year_level, stud.section,
                       COUNT(sub_a.id) AS total_recent,
                       SUM(CASE WHEN sub_a.status = 'Absent' THEN 1 ELSE 0 END) AS recent_absents,
                       SUM(CASE WHEN sub_a.status = 'Late' THEN 1 ELSE 0 END) AS recent_lates
                FROM (
                    SELECT id, student_id, status 
                    FROM attendance 
                    ORDER BY id DESC 
                    LIMIT 150
                ) sub_a
                JOIN students stud ON sub_a.student_id = stud.id
                GROUP BY sub_a.student_id
                HAVING recent_absents >= 2 OR (recent_absents + recent_lates) >= 3
                ORDER BY recent_absents DESC, recent_lates DESC
                LIMIT 4
            ");
            $trending_down = [];
            if ($trend_q) {
                while ($row = $trend_q->fetch_assoc()) {
                    $trending_down[] = $row['name'] . " (" . $row['course'] . " " . $row['year_level'] . "-" . $row['section'] . ": " . $row['recent_absents'] . " absents, " . $row['recent_lates'] . " lates recently)";
                }
            }
            $trending_spotlight = !empty($trending_down) ? implode(', ', $trending_down) : 'None currently detected';

            // Construct prompt
            $prompt = "You are the \"NEUST Gatepass AI Analyst\". Analyze the following academic term attendance data for $active_school_year – $active_semester and generate an executive analysis summary.

### Attendance Metrics:
- Total Tracked Entry/Exit Records: $total_records
- Status breakdown: Present/On-time: $present_count, Late: $late_count, Absent: $absent_count
- Attendance Rates: Overall Track Attendance: $overall_rate%, On-Time Rate: $ontime_rate%, Late Rate: $late_rate%, Absenteeism Rate: $absent_rate%
- Critical At-Risk Students (Attendance < 80%): $at_risk_count
" . (!empty($at_risk_spotlight) ? "- Top Critically Low Profiles: $at_risk_spotlight\n" : "") . "
- Early Warning Proactive Trend Alerts (Negative Slope): $trending_spotlight
- Department Performance (On-Time Rates): $dept_list

### Instructions:
Generate a structured, professional, executive analytical summary. You MUST format all quantitative breakdowns and metrics in Markdown Tables instead of bullet points.

Structure the response exactly as follows:
1. **Current Overview**: Synthesize the overall health of attendance, highlighting overall statistics and early trend alerts ($trending_spotlight).
2. **Attendance Performance Metrics Table**: Include a Markdown table summarizing the metrics (Parameters vs Values). For example:
| Parameter | Value |
|:---|:---|
| Total Scans Tracked | $total_records |
| On-Time Attendance Rate | $ontime_rate% |
| Tardiness Rate | $late_rate% |
| Absenteeism Rate | $absent_rate% |
| Overall Track Rate | $overall_rate% |
| Critically At-Risk Students | $at_risk_count |

3. **Department Performance Breakdown Table**: Include a Markdown table detailing each Course and its corresponding On-Time Rate based on the provided list: $dept_list. Ensure it is sorted from highest to lowest. For example:
| Course / Department | On-Time Attendance Rate |
|:---|:---|

4. **Critically Low Spotlight Table** (Only if at-risk profiles exist: $at_risk_count): Include a Markdown table detailing the names, courses, and attendance rates of the top critically low profiles ($at_risk_spotlight). For example:
| Student Name | Course | Attendance Rate |
|:---|:---|:---|

5. **Identified Risk Factors**: Focus on the critically low profiles and the early warning trend alerts ($trending_spotlight) in bullet points.
6. **Actionable Recommendations**: Give 3 highly practical administrative actions in bullet points (e.g. counseling, parental alerts, schedule adjustments).

Keep your response highly concise, professional, clear, and structured strictly in Markdown. Do not include raw introductory texts like \"Here is your analysis:\". Get straight to the analysis.";

            $response = $ollama->generate($prompt, "You are a senior educational analyst specializing in student engagement, attendance tracking, and academic risk intervention.");
            
            echo json_encode([
                'success' => true,
                'analysis' => $response
            ]);
            break;

        case 'get_at_risk_students':
            // Fetch student dropdown list for drafting emails
            $students_q = $conn->query("
                SELECT stud.id, stud.name, stud.course,
                       ROUND((SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) / COUNT(a.id) * 100), 1) AS attendance_rate
                FROM attendance a
                JOIN schedules s ON a.schedule_id = s.id
                JOIN students stud ON a.student_id = stud.id
                WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
                GROUP BY a.student_id
                HAVING (SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) / COUNT(a.id) * 100) < 85.0
                ORDER BY attendance_rate ASC
            ");
            
            $students = [];
            while ($row = $students_q->fetch_assoc()) {
                $students[] = $row;
            }
            echo json_encode([
                'success' => true,
                'students' => $students
            ]);
            break;

        case 'draft_email':
            $student_id = intval($input['student_id'] ?? 0);
            if ($student_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid student ID specified.']);
                exit;
            }

            // Fetch details for student and attendance
            $stud_q = $conn->query("
                SELECT id, name, course, year_level, section 
                FROM students 
                WHERE id = $student_id
            ");
            $student = $stud_q ? $stud_q->fetch_assoc() : null;
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found in database.']);
                exit;
            }

            $stats_q = $conn->query("
                SELECT 
                    COUNT(a.id) AS total_classes,
                    SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS attended_classes,
                    SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_classes,
                    SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) AS late_classes
                FROM attendance a
                JOIN schedules s ON a.schedule_id = s.id
                WHERE a.student_id = $student_id AND s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
            ");
            $stats = $stats_q ? $stats_q->fetch_assoc() : null;
            $total_classes = intval($stats['total_classes'] ?? 0);
            $attended = intval($stats['attended_classes'] ?? 0);
            $absent = intval($stats['absent_classes'] ?? 0);
            $late = intval($stats['late_classes'] ?? 0);
            $rate = $total_classes > 0 ? round(($attended / $total_classes) * 100, 1) : 0;

            $prompt = "You are the \"NEUST Department Head & Attendance Coordinator\". Draft a personalized, highly professional, supportive, yet formal email alert to the parent/guardian of the following student:
- Student Name: " . $student['name'] . "
- Course, Year & Section: " . $student['course'] . " " . $student['year_level'] . "-" . $student['section'] . "
- Attendance Metrics: " . $rate . "% Attendance Rate (" . $attended . " classes attended, " . $absent . " absences, " . $late . " tardiness markings out of " . $total_classes . " total sessions)
- Academic Term: " . $active_school_year . " – " . $active_semester . "

### Instructions:
1. Address the parents formally.
2. Note that the student has dropped below the institution's critical 80% attendance threshold, putting them at academic and graduation risk.
3. Express constructive, supportive concern. Mention that consistent attendance is tightly linked to academic performance and safety inside the gatepass parameters.
4. Politely request that they reply to this email, or schedule a quick meeting with the Office of Student Affairs / Department Coordinator to explain their child's situation (e.g., transport difficulties, illness, personal challenges) so the department can assist.
5. Provide standard email placeholders for contact numbers, office location, and signatory details.
6. Deliver only the markdown formatted subject line and email body. Do not include any introductory remarks like \"Here is the email draft:\". Start with the email template immediately.";

            $response = $ollama->generate($prompt, "You are a warm, highly professional administrative assistant writing to parents about attendance intervention.");
            
            echo json_encode([
                'success' => true,
                'draft' => $response,
                'student' => $student,
                'metrics' => [
                    'rate' => $rate,
                    'total' => $total_classes,
                    'attended' => $attended,
                    'absent' => $absent
                ]
            ]);
            break;

        case 'send_ai_email':
            $student_id = intval($input['student_id'] ?? 0);
            $subject = trim($input['subject'] ?? '');
            $body = trim($input['body'] ?? '');

            if ($student_id <= 0 || empty($subject) || empty($body)) {
                echo json_encode(['success' => false, 'message' => 'Missing required parameter details.']);
                exit;
            }

            // Fetch student parent details
            $stud_q = $conn->query("SELECT name, parent_name, parent_email, parent_email2 FROM students WHERE id = $student_id");
            $student = $stud_q ? $stud_q->fetch_assoc() : null;

            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found in the database.']);
                exit;
            }

            $parent_email = trim($student['parent_email'] ?? '');
            $parent_email2 = trim($student['parent_email2'] ?? '');
            $parent_name = trim($student['parent_name'] ?? 'Parent/Guardian');
            $student_name = $student['name'];

            if (empty($parent_email) && empty($parent_email2)) {
                echo json_encode(['success' => false, 'message' => 'No parent/guardian email address is configured for this student.']);
                exit;
            }

            // Load PHPMailer & mailer.php settings
            require_once(__DIR__ . '/../mailer.php');
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'carranglanoffcampusneust@gmail.com';
                $mail->Password = 'gaxw jmjg uvzp gwrc';
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port = 465;

                $mail->setFrom('no-reply@neustgatepass.edu.ph', 'NEUST Gatepass AI Office');
                if (!empty($parent_email)) {
                    $mail->addAddress($parent_email, $parent_name);
                }
                if (!empty($parent_email2)) {
                    $mail->addAddress($parent_email2, $parent_name);
                }

                $mail->isHTML(true);
                $mail->Subject = $subject;

                // Wrap plain text body in premium institutional stylesheet template
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #2bb7b3; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05);'>
                        <div style='background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #fff; padding: 24px; text-align: center;'>
                            <h3 style='margin:0; font-size: 20px; font-weight:700;'>NEUST Academic Intervention Portal</h3>
                            <span style='font-size:0.75rem; color:#2bb7b3; font-weight:700; text-transform:uppercase;'>AI-Generated Attendance Alert</span>
                        </div>
                        <div style='padding: 30px; background-color:#ffffff; line-height: 1.6; font-size: 15px;'>
                            " . nl2br(htmlspecialchars($body)) . "
                        </div>
                        <div style='background-color:#f8fafc; padding:16px; text-align:center; font-size:12px; color:#64748b; border-top:1px solid #e2e8f0;'>
                            This intervention notice was securely dispatched using the NEUST Gatepass Local AI Assistant.
                        </div>
                    </div>
                ";

                $mail->AltBody = $body;
                $mail->send();

                // Log into audit trail
                include_once(__DIR__ . '/logger.php');
                if (function_exists('log_audit')) {
                    log_audit('SEND_EMAIL', 'Students', $student_name, "Sent AI Parent Alert to $parent_email (Parent: $parent_name)");
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Personalized warning notice dispatched successfully to ' . htmlspecialchars($parent_email) . '!'
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'PHPMailer failed: ' . $mail->ErrorInfo
                ]);
            }
            break;

        case 'custom_chat':
            $message = $input['message'] ?? '';
            $chatHistory = $input['history'] ?? []; // Array of message objects: [['role' => 'user', 'content' => '...']]

            if (empty($message)) {
                echo json_encode(['success' => false, 'message' => 'Empty message content.']);
                exit;
            }

            // Gather structural system stats as a compact fact sheet to inject into context
            $stud_total = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'] ?? 0;
            $faculty_total = $conn->query("SELECT COUNT(*) AS c FROM faculty")->fetch_assoc()['c'] ?? 0;
            $scans_total = $conn->query("SELECT COUNT(*) AS c FROM attendance")->fetch_assoc()['c'] ?? 0;
            $risk_total_q = $conn->query("
                SELECT COUNT(*) AS c FROM (
                    SELECT a.student_id
                    FROM attendance a
                    JOIN schedules s ON a.schedule_id = s.id
                    WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
                    GROUP BY a.student_id
                    HAVING COUNT(a.id) > 0 AND (SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) / COUNT(a.id) * 100) < 80.0
                ) AS sub
            ");
            $risk_total = $risk_total_q ? intval($risk_total_q->fetch_assoc()['c'] ?? 0) : 0;

            // Semantic parsing on backend for dynamic real-time query interception
            $search_context = "";
            $msg_lower = strtolower($message);
            
            $detected_course = null;
            if (strpos($msg_lower, 'bsit') !== false) $detected_course = 'BSIT';
            elseif (strpos($msg_lower, 'beed') !== false) $detected_course = 'BEED';
            elseif (strpos($msg_lower, 'bsba') !== false) $detected_course = 'BSBA';
            
            $detected_status = null;
            if (strpos($msg_lower, 'absent') !== false) $detected_status = 'Absent';
            elseif (strpos($msg_lower, 'late') !== false || strpos($msg_lower, 'tardy') !== false) $detected_status = 'Late';
            elseif (strpos($msg_lower, 'present') !== false || strpos($msg_lower, 'on-time') !== false || strpos($msg_lower, 'on time') !== false) $detected_status = 'Present';

            $detected_scan_status = null;
            if (strpos($msg_lower, 'scan') !== false || strpos($msg_lower, 'log') !== false) {
                if (strpos($msg_lower, 'in') !== false) $detected_scan_status = 'IN';
                elseif (strpos($msg_lower, 'out') !== false) $detected_scan_status = 'OUT';
            }

            $detected_date = null;
            if (strpos($msg_lower, 'today') !== false) {
                $detected_date = date('Y-m-d');
            } elseif (strpos($msg_lower, 'yesterday') !== false) {
                $detected_date = date('Y-m-d', strtotime('-1 day'));
            }

            $detected_faculty = null;
            if (strpos($msg_lower, 'faculty') !== false || strpos($msg_lower, 'teacher') !== false || strpos($msg_lower, 'prof') !== false) {
                $detected_faculty = true;
            }

            // Run smart live query if keywords matched
            if ($detected_course || $detected_status || $detected_scan_status || $detected_date || $detected_faculty) {
                $search_context = "\n### 🔍 Realtime Database Context (Auto-Retrieved):\n";
                
                if ($detected_status) {
                    $q_str = "
                        SELECT stud.name, stud.course, stud.year_level, stud.section, a.status, s.class_code
                        FROM attendance a
                        JOIN students stud ON a.student_id = stud.id
                        JOIN schedules s ON a.schedule_id = s.id
                        WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
                    ";
                    if ($detected_course) {
                        $q_str .= " AND stud.course = '" . $conn->real_escape_string($detected_course) . "'";
                    }
                    $q_str .= " AND a.status = '" . $conn->real_escape_string($detected_status) . "'";
                    if ($detected_date) {
                        $q_str .= " AND DATE(a.created_at) = '$detected_date'";
                    }
                    $q_str .= " ORDER BY a.id DESC LIMIT 10";
                    
                    $res = $conn->query($q_str);
                    if ($res && $res->num_rows > 0) {
                        $search_context .= "Matching Attendance Records (Status: $detected_status" . ($detected_course ? ", Course: $detected_course" : "") . "):\n";
                        while ($r = $res->fetch_assoc()) {
                            $search_context .= "- **" . $r['name'] . "** (" . $r['course'] . " " . $r['year_level'] . "-" . $r['section'] . "): marked **" . $r['status'] . "** in class **" . $r['class_code'] . "**\n";
                        }
                    } else {
                        $search_context .= "- No matching attendance records found.\n";
                    }
                } elseif ($detected_scan_status || $detected_date) {
                    $q_str = "
                        SELECT logs.name, logs.rfid_uid, logs.status, COALESCE(logs.time_in, logs.time_out) AS scan_time, students.course, students.year_level
                        FROM logs
                        LEFT JOIN students ON logs.rfid_uid = students.rfid_uid
                        WHERE 1=1
                    ";
                    if ($detected_course) {
                        $q_str .= " AND students.course = '" . $conn->real_escape_string($detected_course) . "'";
                    }
                    if ($detected_scan_status) {
                        $q_str .= " AND logs.status = '" . $conn->real_escape_string($detected_scan_status) . "'";
                    }
                    if ($detected_date) {
                        $q_str .= " AND DATE(COALESCE(logs.time_in, logs.time_out)) = '$detected_date'";
                    }
                    $q_str .= " ORDER BY logs.id DESC LIMIT 10";
                    
                    $res = $conn->query($q_str);
                    if ($res && $res->num_rows > 0) {
                        $search_context .= "Matching RFID Gate Pass Logs:\n";
                        while ($r = $res->fetch_assoc()) {
                            $time_fmt = date('h:i A', strtotime($r['scan_time']));
                            $search_context .= "- **" . $r['name'] . "** (" . ($r['course'] ?? 'Visitor') . " " . ($r['year_level'] ?? '') . "): Gate **" . $r['status'] . "** at " . $time_fmt . "\n";
                        }
                    } else {
                        $search_context .= "- No matching RFID gate scan logs found.\n";
                    }
                }

                if ($detected_faculty) {
                    $q_str = "SELECT name, department, email FROM faculty ORDER BY name ASC LIMIT 20";
                    $res = $conn->query($q_str);
                    if ($res && $res->num_rows > 0) {
                        $search_context .= "Registered Faculty Members:\n";
                        while ($r = $res->fetch_assoc()) {
                            $search_context .= "- **" . $r['name'] . "** (" . ($r['department'] ? $r['department'] : "No Dept") . ") - " . $r['email'] . "\n";
                        }
                    } else {
                        $search_context .= "- No registered faculty members found.\n";
                    }
                }
            }

            // System prompt containing system constraints, database live context, and hardware support
            $systemPrompt = "You are \"Gatepass AI\", the advanced administrative and security assistant for Nueva Ecija University of Science and Technology (NEUST) Gatepass & Attendance System. 

You have direct access to database records. Here is the current fact sheet for the Active Academic Term ($active_school_year – $active_semester):
- Total Registered Students: $stud_total
- Total Registered Faculty: $faculty_total
- Total Scan Logs recorded in database: $scans_total
- At-risk students count (attendance < 80%): $risk_total students.
" . (!empty($search_context) ? $search_context : "") . "

Your role:
- Help school administrators query, understand, and manage attendance patterns, security profiles, and student records.
- Support real-time inquiries using the auto-retrieved database search results when available.
- Explain attendance statistics in simple, informative, friendly language.
- Maintain institutional, professional, highly polite, and supportive tone.
- When asked technical or hardware questions, utilize this hardware troubleshooting guide:
  * Components: Arduino Uno/ESP8266 WiFi microcontrollers, RFID RC522 Reader (SPI), Active Buzzer (PIN D8), dual Status LEDs.
  * Wiring Mapping (RFID RC522 to SPI pins): RST -> D9, SDA (SS) -> D10, MOSI -> D11, MISO -> D12, SCK -> D13, VCC -> 3.3V, GND -> GND.
  * Local Endpoint: Reader client issues HTTP POST requests with 'rfid_uid' and 'device_key' parameters directly to local `scan.php`.
  * Diagnostics:
    1. Continuous Yellow LED: WiFi Connection timeout. Double-check SSID/Password in config block of `arduino.ino`.
    2. Continuous Red LED / Triple Buzzer: Server Connection failed (HTTP 500 / CORS Block). Ensure local XAMPP/Apache is running.
    3. Reader not scanning: Check VCC stable 3.3V supply (do NOT use 5V to prevent burning SPI controllers).

Format all responses in beautiful, clean markdown.";

            // Format chat messages
            $formattedMessages = [];
            $formattedMessages[] = ['role' => 'system', 'content' => $systemPrompt];

            // Build historical context (max 6 messages)
            $historySlice = array_slice($chatHistory, -6);
            foreach ($historySlice as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $formattedMessages[] = [
                        'role' => $msg['role'],
                        'content' => $msg['content']
                    ];
                }
            }

            // Append current message
            $formattedMessages[] = ['role' => 'user', 'content' => $message];

            $chatResult = $ollama->chat($formattedMessages);
            $botResponse = $chatResult['message']['content'] ?? 'No response returned from the model.';

            // Strip out <a href="mailto:...">...</a> and [email](mailto:...) links output by the LLM
            $botResponse = preg_replace('/\[([^\]]+)\]\(mailto:[^\)]+\)/i', '$1', $botResponse);
            $botResponse = preg_replace('/<a[^>]*href="mailto:[^"]*"[^>]*>(.*?)<\/a>/i', '$1', $botResponse);
            $botResponse = preg_replace('/<mailto:([^>]+)>/i', '$1', $botResponse);

            echo json_encode([
                'success' => true,
                'response' => $botResponse
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Undefined AI action.']);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ollama Connection Failed. Please ensure the Ollama desktop app or service is running locally on port 11434.',
        'details' => $e->getMessage()
    ]);
}
?>
