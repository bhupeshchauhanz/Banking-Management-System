<?php
session_start();
require_once '../config/db_connect.php';

// Update last logout time if user is logged in
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET last_logout = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {
        logError("Logout error: " . $e->getMessage());
    }
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?> 