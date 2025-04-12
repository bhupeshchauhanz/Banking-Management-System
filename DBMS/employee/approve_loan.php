<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: ../index.php');
    exit();
}

if (isset($_GET['id'])) {
    $loan_id = $_GET['id'];

    try {
        $pdo->beginTransaction();

        // Get total current deposits (deposits - approved loans)
        $stmt = $pdo->query("
            SELECT 
                (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'deposit' AND status = 'completed') -
                (SELECT COALESCE(SUM(amount), 0) FROM loans WHERE status = 'approved') as current_deposits
        ");
        $current_deposits = $stmt->fetch()['current_deposits'];

        // Get loan details
        $stmt = $pdo->prepare("
            SELECT l.*, a.id as account_id, a.balance 
            FROM loans l 
            JOIN accounts a ON l.account_id = a.id 
            WHERE l.id = ? AND l.status = 'pending' AND l.amount <= 50000
        ");
        $stmt->execute([$loan_id]);
        $loan = $stmt->fetch();

        if (!$loan) {
            throw new Exception("Loan not found, already processed, or exceeds employee approval limit ($50,000)");
        }

        // Check if loan amount exceeds 80% of current deposits
        $max_loan_amount = $current_deposits * 0.8;
        if ($loan['amount'] > $max_loan_amount) {
            throw new Exception("Loan amount exceeds 80% of current deposits. Maximum allowed: $" . number_format($max_loan_amount, 2));
        }

        // Check if customer already has an active loan
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as active_loans 
            FROM loans 
            WHERE customer_id = ? AND status = 'approved'
        ");
        $stmt->execute([$loan['customer_id']]);
        $active_loans = $stmt->fetch()['active_loans'];

        if ($active_loans > 0) {
            throw new Exception("Customer already has an active loan. Cannot approve another loan.");
        }

        // Update loan status
        $stmt = $pdo->prepare("UPDATE loans SET status = 'approved', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$loan_id]);

        // Update account balance
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$loan['amount'], $loan['account_id']]);

        // Create transaction record
        $stmt = $pdo->prepare("
            INSERT INTO transactions (account_id, type, amount, description, status, created_at) 
            VALUES (?, 'loan', ?, ?, 'completed', NOW())
        ");
        $stmt->execute([
            $loan['account_id'],
            $loan['amount'],
            "Loan approved - " . $loan['description']
        ]);

        $pdo->commit();
        $_SESSION['success'] = "Loan approved successfully. Amount: $" . number_format($loan['amount'], 2);
        header('Location: dashboard.php');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
        header('Location: dashboard.php');
        exit();
    }
} else {
    header('Location: dashboard.php');
    exit();
}
?> 