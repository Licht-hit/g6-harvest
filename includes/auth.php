<?php

// This file loads the shared login/session setup for every PHP page.
// It is kept simple so beginners can understand the app's startup flow.
require_once __DIR__ . '/security.php';

// Start a secure session before any page logic runs.
start_secure_session();

// Load the database and common helper functions.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
