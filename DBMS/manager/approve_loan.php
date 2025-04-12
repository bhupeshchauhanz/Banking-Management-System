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
        SELECT l.*, a.id as account_id, a.balance, c.id as customer_id
        FROM loans l
        JOIN customers c ON l.customer_id = c.id
        LEFT JOIN accounts a ON c.id = a.customer_id
        WHERE l.id = ? AND l.status = 'pending'
    ");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();

    if (!$loan) {
        $_SESSION['error'] = "Loan not found or already processed.";
        header('Location: dashboard.php');
        exit();
    }

    // Start transaction
    $pdo->beginTransaction();

    // Update loan status
    $stmt = $pdo->prepare("
        UPDATE loans 
        SET status = 'approved', 
            approved_by = ?, 
            approved_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $loan_id]);

    // Update account balance
    if ($loan['account_id']) {
        $stmt = $pdo->prepare("
            UPDATE accounts 
            SET balance = balance + ? 
            WHERE id = ?
        ");
        $stmt->execute([$loan['amount'], $loan['account_id']]);

        // Record the loan disbursement transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                account_id, type, amount, description, status, created_by
            ) VALUES (?, 'loan', ?, ?, 'completed', ?)
        ");
        $stmt->execute([
            $loan['account_id'],
            $loan['amount'],
            "Loan disbursement - Loan ID: " . $loan_id,
            $_SESSION['user_id']
        ]);
    }

    // Commit transaction
    $pdo->commit();

    $_SESSION['success'] = "Loan approved successfully.";
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    $_SESSION['error'] = "Error approving loan: " . $e->getMessage();
}

header('Location: dashboard.php');
exit();
?> 