<?php
$mode = $argv[1] ?? 'http';
$_SERVER['SCRIPT_NAME'] = '/harvest/buyer/dashboard.php';
$_SERVER['HTTPS'] = $mode === 'https' ? 'on' : 'off';
$_SERVER['SERVER_PORT'] = $mode === 'https' ? '443' : '80';

require_once __DIR__ . '/../includes/security.php';
start_secure_session();

$parameters = session_get_cookie_params();
$expectedSecure = $mode === 'https';
$checks = [
    'session.use_strict_mode' => ini_get('session.use_strict_mode') === '1',
    'session.use_only_cookies' => ini_get('session.use_only_cookies') === '1',
    'session.use_trans_sid' => ini_get('session.use_trans_sid') === '0',
    'cookie httponly' => $parameters['httponly'] === true,
    'cookie samesite' => $parameters['samesite'] === 'Lax',
    'cookie secure matches HTTPS' => $parameters['secure'] === $expectedSecure,
    'cookie scoped to application' => $parameters['path'] === '/harvest/',
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        session_destroy();
        throw new RuntimeException('Session security check failed: ' . $name);
    }
}

session_destroy();
echo 'Session security checks passed (' . $mode . ").\n";
