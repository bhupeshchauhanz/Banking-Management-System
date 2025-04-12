<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: ../index.php');
    exit();
}

if (isset($_GET['id'])) {
    $transfer_id = $_GET['id'];

    try {
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
            throw new Exception("Transfer not found or already processed");
        }

        // Check if transfer amount is within employee's limit
        if ($transfer['amount'] <= 15000) {
            throw new Exception("This transfer should be processed automatically. No approval needed.");
        } elseif ($transfer['amount'] > 50000) {
            throw new Exception("This transfer requires manager approval. Employees can only approve transfers up to $50,000.");
        }

        // Check if source account has sufficient balance
        if ($transfer['balance'] < $transfer['amount']) {
            throw new Exception("Source account has insufficient balance for this transfer");
        }

        // Extract recipient account number from description
        if (!preg_match('/To: (\d+)/', $transfer['description'], $matches)) {
            throw new Exception("Recipient account number not found in transfer description");
        }
        $recipient_account = $matches[1];

        // Get destination account
        $stmt = $pdo->prepare("SELECT id, balance FROM accounts WHERE account_number = ?");
        $stmt->execute([$recipient_account]);
        $destination_account = $stmt->fetch();

        if (!$destination_account) {
            throw new Exception("Destination account not found");
        }

        // Check if source and destination accounts are different
        if ($transfer['account_id'] == $destination_account['id']) {
            throw new Exception("Cannot transfer to the same account");
        }

        // Update source account balance
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$transfer['amount'], $transfer['account_id']]);

        // Update destination account balance
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$transfer['amount'], $destination_account['id']]);

        // Update transfer status
        $stmt = $pdo->prepare("UPDATE transactions SET status = 'completed', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$transfer_id]);

        $pdo->commit();
        $_SESSION['success'] = "Transfer approved successfully. Amount: $" . number_format($transfer['amount'], 2);
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