<?php
require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    redirect(app_url('auth/login.php'));
}

if (current_user()['role'] === 'buyer') {
    redirect(app_url('buyer/dashboard.php'));
}

redirect(app_url('dashboard.php'));
