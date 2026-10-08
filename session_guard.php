<?php
/**
 * NEUST Gatepass - Session Inactivity Guard
 * Enforces automatic logout after a configured period of inactivity.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Retrieve session timeout in seconds from database settings (default: 15 minutes = 900 seconds)
 */
if (!function_exists('get_session_timeout_seconds')) {
    function get_session_timeout_seconds() {
        static $cached_timeout = null;
        if ($cached_timeout !== null) {
            return $cached_timeout;
        }

        $default_minutes = 15;

        // Try existing connection first
        global $conn, $admin_conn;
        $db = $conn ?? $admin_conn ?? null;

        if (!$db || !($db instanceof mysqli) || $db->connect_error) {
            $db_file = __DIR__ . '/db.php';
            if (file_exists($db_file)) {
                @include_once $db_file;
                $db = $conn ?? null;
            }
        }

        if ($db && ($db instanceof mysqli) && !$db->connect_error) {
            $safeName = $db->real_escape_string('session_timeout_minutes');
            $res = $db->query("SELECT value FROM settings WHERE name = '$safeName' LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $val = intval($row['value']);
                if ($val >= 1 && $val <= 1440) { // between 1 minute and 24 hours
                    $default_minutes = $val;
                }
            }
        }

        $cached_timeout = $default_minutes * 60;
        return $cached_timeout;
    }
}

/**
 * Enforce session inactivity check.
 * If user session has expired, clears session/cookies and redirects to login with ?expired=1.
 */
if (!function_exists('enforce_session_inactivity')) {
    function enforce_session_inactivity($login_page = 'login.php') {
        // Only enforce if an authenticated user session is active
        $has_active_session = !empty($_SESSION['admin_logged_in'])
            || !empty($_SESSION['faculty_logged_in'])
            || !empty($_SESSION['student_id']);

        if (!$has_active_session) {
            return;
        }

        $timeout_seconds = get_session_timeout_seconds();
        $now = time();

        if (isset($_SESSION['last_activity'])) {
            $inactive_seconds = $now - intval($_SESSION['last_activity']);
            if ($inactive_seconds > $timeout_seconds) {
                // Session has expired
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params['path'],
                        $params['domain'],
                        $params['secure'],
                        $params['httponly']
                    );
                }
                session_destroy();

                // Detect AJAX / Fetch requests
                $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                    || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    http_response_code(401);
                    echo json_encode([
                        'session_expired' => true,
                        'message' => 'Session expired due to inactivity.',
                        'redirect' => $login_page . '?expired=1'
                    ]);
                    exit;
                }

                header("Location: {$login_page}?expired=1");
                exit;
            }
        }

        // Update last activity timestamp for current request (skip background auto-refresh to maintain inactivity security)
        if (empty($_SERVER['HTTP_X_BACKGROUND_REFRESH'])) {
            $_SESSION['last_activity'] = $now;
        }
    }
}
