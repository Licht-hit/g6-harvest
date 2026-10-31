<?php

// Beginner-friendly security helper file.
// This file sets basic browser protection and secure session settings.
function request_uses_https(): bool
{
    // Check if the current request is on HTTPS or uses port 443.
    return (
        isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== ''
        && strtolower((string) $_SERVER['HTTPS']) !== 'off'
    ) || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function apply_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    // Simple browser security headers for safer pages.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Content-Security-Policy: default-src \'self\'; base-uri \'self\'; '
        . 'object-src \'none\'; frame-ancestors \'none\'; form-action \'self\'; '
        . 'img-src \'self\' data:; script-src \'self\'; style-src \'self\'; '
        . 'font-src \'self\' data:; connect-src \'self\';');
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');

    if (
        strtolower((string) getenv('APP_ENV')) === 'production'
        && request_uses_https()
    ) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = request_uses_https();

    // Turn on secure session settings.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $https ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    $scriptDirectory = str_replace(
        '\\',
        '/',
        dirname($_SERVER['SCRIPT_NAME'] ?? '/')
    );
    if (in_array(basename(rtrim($scriptDirectory, '/')), [
        'auth',
        'modules',
        'buyer',
        'webhooks',
    ], true)) {
        $scriptDirectory = dirname($scriptDirectory);
    }
    $cookiePath = rtrim($scriptDirectory, '/') . '/';
    if ($cookiePath === '/') {
        $cookiePath = '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

apply_security_headers();
