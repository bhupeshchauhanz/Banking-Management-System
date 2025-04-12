<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: ../index.php');
    exit();
}

// Check if transfer ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid transfer ID.";
    header('Location: dashboard.php');
    exit();
}

$transfer_id = $_GET['id'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get transfer details
    $stmt = $pdo->prepare("
        SELECT t.*, a.id as account_id, a.balance 
        FROM transactions t 
        JOIN accounts a ON t.account_id = a.id 
        WHERE t.id = ? AND t.type = 'transfer' AND t.status = 'pending'
    ");
    $stmt->execute([$transfer_id]);
    $transfer = $stmt->fetch();

    if (!$transfer) {
        throw new Exception("Transfer not found or already processed.");
    }

    // Check if the transfer amount is within employee's approval limit
    if ($transfer['amount'] <= 15000) {
        throw new Exception("This transfer should be processed automatically. No approval needed.");
    } elseif ($transfer['amount'] > 50000) {
        throw new Exception("This transfer requires manager approval. Employees can only approve transfers up to $50,000.");
    }

    // Update the transfer status to rejected
    $stmt = $pdo->prepare("
        UPDATE transactions 
        SET status = 'rejected', updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$transfer_id]);

    // Return the amount to the source account
    $stmt = $pdo->prepare("
        UPDATE accounts 
        SET balance = balance + ? 
        WHERE id = ?
    ");
    $stmt->execute([$transfer['amount'], $transfer['account_id']]);

    // Commit transaction
    $pdo->commit();

    $_SESSION['success'] = "Transfer rejected successfully. Amount has been returned to the source account.";
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