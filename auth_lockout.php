<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('LOGIN_MAX_ATTEMPTS')) {
    define('LOGIN_MAX_ATTEMPTS', 5);
}

if (!defined('LOGIN_LOCKOUT_SECONDS')) {
    define('LOGIN_LOCKOUT_SECONDS', 300);
}

function login_attempt_key(string $type, string $identifier): string
{
    return strtolower(trim($type) . ':' . trim($identifier));
}

function login_attempt_state(string $type, string $identifier): array
{
    $key = login_attempt_key($type, $identifier);

    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }

    $state = $_SESSION['login_attempts'][$key] ?? ['count' => 0, 'locked_until' => 0];
    $state['count'] = (int) ($state['count'] ?? 0);
    $state['locked_until'] = (int) ($state['locked_until'] ?? 0);

    if ($state['locked_until'] > 0 && $state['locked_until'] <= time()) {
        unset($_SESSION['login_attempts'][$key]);
        return ['count' => 0, 'locked_until' => 0, 'blocked' => false];
    }

    return [
        'count' => $state['count'],
        'locked_until' => $state['locked_until'],
        'blocked' => $state['locked_until'] > time(),
    ];
}

function login_attempt_failed(string $type, string $identifier): void
{
    $key = login_attempt_key($type, $identifier);

    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }

    $state = $_SESSION['login_attempts'][$key] ?? ['count' => 0, 'locked_until' => 0];
    $state['count'] = (int) ($state['count'] ?? 0) + 1;

    if ($state['count'] >= LOGIN_MAX_ATTEMPTS) {
        $state['locked_until'] = time() + LOGIN_LOCKOUT_SECONDS;
        $state['count'] = LOGIN_MAX_ATTEMPTS;
    }

    $_SESSION['login_attempts'][$key] = $state;
}

function login_attempt_reset(string $type, string $identifier): void
{
    $key = login_attempt_key($type, $identifier);
    if (isset($_SESSION['login_attempts'][$key])) {
        unset($_SESSION['login_attempts'][$key]);
    }
}

function login_attempt_message(array $state): string
{
    if (!$state['blocked']) {
        return '';
    }

    $remaining = max(0, (int) ceil(($state['locked_until'] - time()) / 60));
    return 'Too many failed login attempts. Please try again in ' . $remaining . ' minute(s).';
}
