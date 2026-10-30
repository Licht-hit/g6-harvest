<?php
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(app_url('index.php'));
}

verify_csrf();

$user = current_user();
if ($user) {
    log_activity('logout', 'user', (int) $user['id'], 'User signed out.');
}

// Clear the session data and expire the session cookie.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
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
redirect(app_url('auth/login.php'));
