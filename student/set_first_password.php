<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
    || !empty($_POST['ajax']);

function respond($status, $message, $is_ajax) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['status' => $status, 'message' => $message]);
        exit;
    } else {
        if ($status === 'success') {
            header('Location: dashboard.php?password_set=1');
        } else {
            header('Location: dashboard.php?error=' . urlencode($message));
        }
        exit;
    }
}

if (!isset($_SESSION['student_id'])) {
    respond('error', 'Session expired. Please log in again.', $is_ajax);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', 'Invalid request method.', $is_ajax);
}

require_once(__DIR__ . '/../db.php');

$student_id = intval($_SESSION['student_id']);
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if (empty($new_password) || empty($confirm_password)) {
    respond('error', 'Please fill in both password fields.', $is_ajax);
}

if (strlen($new_password) < 6) {
    respond('error', 'Password must be at least 6 characters long.', $is_ajax);
}

if ($new_password !== $confirm_password) {
    respond('error', 'Passwords do not match.', $is_ajax);
}

$hashed = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE students SET password_hash = ?, is_first_login = 0 WHERE id = ?");
if (!$stmt) {
    respond('error', 'Database preparation error: ' . $conn->error, $is_ajax);
}

$stmt->bind_param("si", $hashed, $student_id);

if ($stmt->execute()) {
    $stmt->close();
    $_SESSION['student_is_first_login'] = false;
    respond('success', 'Password set successfully!', $is_ajax);
} else {
    $err = $stmt->error;
    $stmt->close();
    respond('error', 'Failed to update password: ' . $err, $is_ajax);
}
