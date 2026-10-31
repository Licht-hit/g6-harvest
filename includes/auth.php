<?php

require_once __DIR__ . '/security.php';

// Use strict, cookie-only sessions with HttpOnly and SameSite protection.
start_secure_session();

// Load the database connection and shared helper functions for every page.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
