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
        SELECT t.*, a.id as account_id, a.balance, a.account_number
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

    // Start transaction
    $pdo->beginTransaction();

    // Update transfer status
    $stmt = $pdo->prepare("
        UPDATE transactions 
        SET status = 'completed', 
            approved_by = ?, 
            approved_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $transfer_id]);

    // Update sender's account balance
    $stmt = $pdo->prepare("
        UPDATE accounts 
        SET balance = balance - ? 
        WHERE id = ?
    ");
    $stmt->execute([$transfer['amount'], $transfer['account_id']]);

    // Get recipient's account number from description
    preg_match('/To: (\d+)/', $transfer['description'], $matches);
    if (isset($matches[1])) {
        $recipient_account = $matches[1];
        
        // Get recipient's account
        $stmt = $pdo->prepare("
            SELECT id, balance 
            FROM accounts 
            WHERE account_number = ?
        ");
        $stmt->execute([$recipient_account]);
        $recipient = $stmt->fetch();

        if ($recipient) {
            // Update recipient's balance
            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET balance = balance + ? 
                WHERE id = ?
            ");
            $stmt->execute([$transfer['amount'], $recipient['id']]);

            // Create transaction record for recipient
            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    account_id, type, amount, description, status, created_by
                ) VALUES (?, 'transfer', ?, ?, 'completed', ?)
            ");
            $stmt->execute([
                $recipient['id'],
                $transfer['amount'],
                "Transfer from: " . $transfer['account_number'],
                $_SESSION['user_id']
            ]);
        }
    }

    // Commit transaction
    $pdo->commit();

    $_SESSION['success'] = "Transfer approved successfully.";
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    $_SESSION['error'] = "Error approving transfer: " . $e->getMessage();
}

header('Location: dashboard.php');
exit();
?> 