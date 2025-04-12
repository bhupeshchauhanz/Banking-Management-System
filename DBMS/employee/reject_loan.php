<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: ../index.php');
    exit();
}

// Check if loan ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid loan ID.";
    header('Location: dashboard.php');
    exit();
}

$loan_id = $_GET['id'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get loan details
    $stmt = $pdo->prepare("
        SELECT l.*, a.id as account_id 
        FROM loans l 
        JOIN accounts a ON l.account_id = a.id 
        WHERE l.id = ? AND l.status = 'pending'
    ");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();

    if (!$loan) {
        throw new Exception("Loan not found or already processed.");
    }

    // Check if the loan amount is within employee's approval limit
    if ($loan['amount'] > 50000) {
        throw new Exception("This loan requires manager approval. Employees can only approve loans up to $50,000.");
    }

    // Update the loan status to rejected
    $stmt = $pdo->prepare("
        UPDATE loans 
        SET status = 'rejected', updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$loan_id]);

    // Commit transaction
    $pdo->commit();

    $_SESSION['success'] = "Loan rejected successfully.";
    header('Location: dashboard.php');
    exit();

} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    $_SESSION['error'] = "Error: " . $e->getMessage();
    header('Location: dashboard.php');
    exit();
}
?> 