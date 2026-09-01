<?php
session_start();
include('auth.php');
include('../db.php');
date_default_timezone_set('Asia/Manila');

$student_id = $_SESSION['student_id'];

$student_res = $conn->query("SELECT * FROM students WHERE id = $student_id");
$student = $student_res->fetch_assoc();

if (!$student) {
    header("Location: login.php");
    exit();
}

$start_date = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$end_date = isset($_GET['end']) ? $_GET['end'] : date('Y-m-t');

$query = "SELECT a.*, s.subject, s.start_time, s.end_time 
          FROM attendance a 
          JOIN schedules s ON a.schedule_id = s.id 
          WHERE a.student_id = $student_id AND a.attendance_date BETWEEN '$start_date' AND '$end_date'
          ORDER BY a.attendance_date DESC, a.time_logged DESC";
          
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Report - <?= htmlspecialchars($student['name']) ?></title>
    <style>
        body { font-family: 'Helvetica', Arial, sans-serif; padding: 20px; color: #333; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 5px 0; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f4f4f4; }
        .status-present { color: green; font-weight: bold; }
        .status-absent { color: red; font-weight: bold; }
        .status-late { color: orange; font-weight: bold; }
        .status-excused { color: blue; font-weight: bold; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #0056b3; color: white; border: none; cursor: pointer; border-radius: 5px;">Print / Save as PDF</button>
    </div>
    
    <div class="header">
        <h1>NEUST Gatepass - Attendance Report</h1>
        <p>Student Name: <strong><?= htmlspecialchars($student['name']) ?></strong></p>
        <p>Course & Year: <?= htmlspecialchars($student['course']) ?> - <?= htmlspecialchars($student['year_level']) ?> (<?= htmlspecialchars($student['section']) ?>)</p>
        <p>Date Range: <?= date('M d, Y', strtotime($start_date)) ?> to <?= date('M d, Y', strtotime($end_date)) ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Subject</th>
                <th>Time Logged</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('M d, Y', strtotime($row['attendance_date'])) ?></td>
                        <td><?= htmlspecialchars($row['subject']) ?></td>
                        <td><?= $row['time_logged'] ? date('h:i A', strtotime($row['time_logged'])) : '-' ?></td>
                        <td class="status-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align: center;">No attendance records found for this period.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
