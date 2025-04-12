<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: ../index.php');
    exit();
}

if (isset($_GET['id'])) {
    $transaction_id = $_GET['id'];

    try {
        $pdo->beginTransaction();

        // Get transaction details
        $stmt = $pdo->prepare("
            SELECT t.*, a.id as account_id, a.balance 
            FROM transactions t 
            JOIN accounts a ON t.account_id = a.id 
            WHERE t.id = ? AND t.amount <= 10000
        ");
        $stmt->execute([$transaction_id]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            throw new Exception("Transaction not found or amount exceeds limit");
        }

        // Update transaction status
        $stmt = $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?");
        $stmt->execute([$transaction_id]);

        // Update account balance based on transaction type
        $new_balance = $transaction['balance'];
        if ($transaction['type'] === 'deposit') {
            $new_balance += $transaction['amount'];
        } elseif ($transaction['type'] === 'withdrawal') {
            if ($new_balance < $transaction['amount']) {
                throw new Exception("Insufficient funds");
            }
            $new_balance -= $transaction['amount'];
        }

        $stmt = $pdo->prepare("UPDATE accounts SET balance = ? WHERE id = ?");
        $stmt->execute([$new_balance, $transaction['account_id']]);

        $pdo->commit();
        header('Location: dashboard.php?success=transaction_approved');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        header('Location: dashboard.php?error=' . urlencode($e->getMessage()));
        exit();
    }
} else {
    header('Location: dashboard.php');
    exit();
}
?> 