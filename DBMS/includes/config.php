<?php
// Define secure access constant
define('SECURE_ACCESS', true);

// Database configuration
$db_host = 'localhost';
$db_name = 'banking_system';
$db_user = 'root';
$db_pass = '';

// Security settings
define('DEVELOPMENT_MODE', false);
define('SESSION_LIFETIME', 3600);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT', 900); // 15 minutes

// File upload settings
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_FILE_TYPES', ['image/jpeg', 'image/png', 'image/gif']);
define('UPLOAD_DIR', '../uploads/profile_photos/');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', DEVELOPMENT_MODE ? 1 : 0);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/error.log');

// Create logs directory if it doesn't exist
if (!file_exists('../logs')) {
    mkdir('../logs', 0755, true);
}

// Create uploads directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Include security functions
require_once 'security.php';

// Set security headers
setSecurityHeaders();

// Start secure session
secureSession();

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Connection failed. Please try again later.");
}
?> 