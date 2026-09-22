<?php
/**
 * KETAN M/A B COMPLEX - Main Entry Point
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configFile = __DIR__ . '/config/db_config.php';

// If configuration doesn't exist, redirect to installer
if (!file_exists($configFile)) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

// Route based on auth state
if (isLoggedIn()) {
    header('Location: ' . url('dashboard.php'));
    exit;
} else {
    header('Location: ' . url('login.php'));
    exit;
}
