<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is a customer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    // Get current profile photo
    $stmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || empty($user['profile_photo'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No profile photo to delete']);
        exit();
    }
    
    // Delete file from server
    $profile_photo_path = '../uploads/profile_photos/' . $user['profile_photo'];
    if (file_exists($profile_photo_path)) {
        unlink($profile_photo_path);
    }
    
    // Update database
    $stmt = $pdo->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Profile photo deleted successfully']);
    
} catch (PDOException $e) {
    error_log("Profile photo deletion error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?> 