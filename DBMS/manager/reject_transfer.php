<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: ../index.php');
    exit();
}

// Check if transfer ID is provided
if (!isset($_GET['id'])) {
    header('Location: dashboard.php');
    exit();
}

$transfer_id = $_GET['id'];

try {
    // Get transfer details
    $stmt = $pdo->prepare("
        SELECT t.*, a.id as account_id
        FROM transactions t
        JOIN accounts a ON t.account_id = a.id
        WHERE t.id = ? AND t.status = 'pending' AND t.type = 'transfer'
    ");
    $stmt->execute([$transfer_id]);
    $transfer = $stmt->fetch();

    if (!$transfer) {
        $_SESSION['error'] = "Transfer not found or already processed.";
        header('Location: dashboard.php');
        exit();
    }

    // Update transfer status
    $stmt = $pdo->prepare("
        UPDATE transactions 
        SET status = 'rejected', 
            rejected_by = ?, 
            rejected_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $transfer_id]);

    $_SESSION['success'] = "Transfer rejected successfully.";
} catch (PDOException $e) {
    $_SESSION['error'] = "Error rejecting transfer: " . $e->getMessage();
}

header('Location: dashboard.php');
exit();
?> 