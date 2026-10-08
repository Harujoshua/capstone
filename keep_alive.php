<?php
/**
 * NEUST Gatepass - Keep Alive Endpoint
 * Extends session last activity or checks remaining idle time.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/session_guard.php';

$timeout_seconds = get_session_timeout_seconds();
$now = time();

// Determine if any user role is currently logged in
$is_authenticated = !empty($_SESSION['admin_logged_in'])
    || !empty($_SESSION['faculty_logged_in'])
    || !empty($_SESSION['student_id']);

if (!$is_authenticated) {
    http_response_code(401);
    echo json_encode([
        'status' => 'unauthenticated',
        'message' => 'No active user session found.'
    ]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'ping';

if ($action === 'check') {
    $last_activity = intval($_SESSION['last_activity'] ?? $now);
    $elapsed = $now - $last_activity;
    $remaining = max(0, $timeout_seconds - $elapsed);
    $expired = ($elapsed > $timeout_seconds);

    echo json_encode([
        'status' => $expired ? 'expired' : 'active',
        'timeout' => $timeout_seconds,
        'elapsed' => $elapsed,
        'remaining' => $remaining,
        'expired' => $expired
    ]);
    exit;
}

// Default action: Ping to extend session
$_SESSION['last_activity'] = $now;

echo json_encode([
    'status' => 'active',
    'message' => 'Session extended successfully.',
    'timeout' => $timeout_seconds,
    'last_activity' => $now
]);
exit;
