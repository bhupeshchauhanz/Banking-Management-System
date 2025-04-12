<?php
require_once 'database.php';

// Function to get PDO connection
function getPDOConnection() {
    global $pdo;
    return $pdo;
}

// Function to validate user session
function validateSession($requiredRole = null) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit();
    }
    
    if ($requiredRole && $_SESSION['role'] !== $requiredRole) {
        header('Location: ../unauthorized.php');
        exit();
    }
    
    return true;
}

// Function to sanitize input
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to validate and sanitize numeric input
function validateNumeric($value, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return false;
    }
    
    if ($min !== null && $value < $min) {
        return false;
    }
    
    if ($max !== null && $value > $max) {
        return false;
    }
    
    return true;
}

// Function to format currency
function formatCurrency($amount) {
    return number_format($amount, 2, '.', ',');
}

// Function to log errors
function logError($message, $error = null) {
    $logMessage = date('[Y-m-d H:i:s] ') . $message;
    if ($error) {
        $logMessage .= ' Error: ' . $error->getMessage();
    }
    error_log($logMessage);
}

// Function to handle database errors
function handleDatabaseError($error) {
    logError('Database error occurred', $error);
    $_SESSION['error'] = 'An error occurred. Please try again later.';
    header('Location: ' . $_SERVER['HTTP_REFERER'] ?? '../index.php');
    exit();
}

// Function to handle success messages
function handleSuccess($message) {
    $_SESSION['success'] = $message;
    header('Location: ' . $_SERVER['HTTP_REFERER'] ?? '../index.php');
    exit();
}

// Function to check if user has permission
function hasPermission($permission) {
    if (!isset($_SESSION['permissions'])) {
        return false;
    }
    return in_array($permission, $_SESSION['permissions']);
}

// Function to get user data
function getUserData($userId) {
    try {
        $pdo = getPDOConnection();
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   CASE 
                       WHEN u.role = 'customer' THEN c.first_name
                       WHEN u.role = 'employee' THEN e.first_name
                       WHEN u.role = 'manager' THEN m.first_name
                   END as first_name,
                   CASE 
                       WHEN u.role = 'customer' THEN c.last_name
                       WHEN u.role = 'employee' THEN e.last_name
                       WHEN u.role = 'manager' THEN m.last_name
                   END as last_name
            FROM users u
            LEFT JOIN customers c ON u.id = c.user_id AND u.role = 'customer'
            LEFT JOIN employees e ON u.id = e.user_id AND u.role = 'employee'
            LEFT JOIN managers m ON u.id = m.user_id AND u.role = 'manager'
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        handleDatabaseError($e);
    }
} 