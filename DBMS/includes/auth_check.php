<?php
// Prevent direct access
if (!defined('SECURE_ACCESS')) {
    die('Direct access not permitted');
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Check session timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
    // Session expired
    session_unset();
    session_destroy();
    header('Location: ../index.php?session=expired');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Check for session hijacking
if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
    // IP address changed
    session_unset();
    session_destroy();
    header('Location: ../index.php?session=hijacked');
    exit();
}

// Store IP address if not set
if (!isset($_SESSION['ip_address'])) {
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
}

// Check for session fixation
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else if (time() - $_SESSION['created'] > 1800) {
    // Session started more than 30 minutes ago
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
?> 