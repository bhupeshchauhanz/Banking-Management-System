<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: ../index.php');
    exit();
}

// Check if loan ID is provided
if (!isset($_GET['id'])) {
    header('Location: dashboard.php');
    exit();
}

$loan_id = $_GET['id'];

try {
    // Get loan details
    $stmt = $pdo->prepare("
        SELECT l.*, c.id as customer_id
        FROM loans l
        JOIN customers c ON l.customer_id = c.id
        WHERE l.id = ? AND l.status = 'pending'
    ");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();

    if (!$loan) {
        $_SESSION['error'] = "Loan not found or already processed.";
        header('Location: dashboard.php');
        exit();
    }

    // Update loan status
    $stmt = $pdo->prepare("
        UPDATE loans 
        SET status = 'rejected', 
            rejected_by = ?, 
            rejected_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $loan_id]);

    $_SESSION['success'] = "Loan rejected successfully.";
} catch (PDOException $e) {
    $_SESSION['error'] = "Error rejecting loan: " . $e->getMessage();
}

header('Location: dashboard.php');
exit();
?> 