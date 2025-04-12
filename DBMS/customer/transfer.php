<?php
session_start();
require_once '../config/db_connect.php';
require_once '../includes/dashboard_layout.php';
require_once '../includes/cache_functions.php';
require_once '../includes/customer_sidebar.php';

// Enable output buffering for better performance
ob_start();

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Validate session and role
validateSession('customer');

// Get PDO connection
$pdo = getPDOConnection();

// Get customer accounts
try {
    $stmt = $pdo->prepare("
        SELECT a.*, 
               FORMAT(a.balance, 2) as formatted_balance,
               CASE 
                   WHEN a.type = 'savings' THEN 'Piggy Bank'
                   WHEN a.type = 'checking' THEN 'Wallet'
                   WHEN a.type = 'business' THEN 'Briefcase'
                   ELSE 'Credit Card'
               END as icon
        FROM accounts a
        JOIN customers c ON a.customer_id = c.id
        WHERE c.user_id = ? AND a.status = 'active'
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $accounts = $stmt->fetchAll();
} catch (PDOException $e) {
    handleDatabaseError($e);
}

// Handle transfer request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $from_account = validateNumeric($_POST['from_account'] ?? 0);
    $to_account = validateNumeric($_POST['to_account'] ?? 0);
    $amount = validateNumeric($_POST['amount'] ?? 0, 0.01);
    $description = sanitizeInput($_POST['description'] ?? '');
    
    if (!$from_account || !$to_account || !$amount) {
        $_SESSION['error'] = 'Invalid input. Please check your values.';
        header('Location: transfer.php');
        exit();
    }
    
    // Check if accounts belong to the customer
    $valid_accounts = array_column($accounts, 'id');
    if (!in_array($from_account, $valid_accounts)) {
        $_SESSION['error'] = 'Invalid source account.';
        header('Location: transfer.php');
        exit();
    }
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get source account balance
        $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE id = ? AND status = 'active' FOR UPDATE");
        $stmt->execute([$from_account]);
        $source_account = $stmt->fetch();
        
        if (!$source_account || $source_account['balance'] < $amount) {
            throw new Exception('Insufficient funds');
        }
        
        // Check if destination account exists and is active
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE account_number = ? AND status = 'active'");
        $stmt->execute([$to_account]);
        $destination_account = $stmt->fetch();
        
        if (!$destination_account) {
            throw new Exception('Invalid destination account');
        }
        
        // Update source account balance
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$amount, $from_account]);
        
        // Update destination account balance
        $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$amount, $destination_account['id']]);
        
        // Record transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions (account_id, type, amount, description, status, recipient_account_id)
            VALUES (?, 'transfer', ?, ?, 'completed', ?)
        ");
        $stmt->execute([$from_account, $amount, $description, $destination_account['id']]);
        
        // Commit transaction
        $pdo->commit();
        
        handleSuccess('Transfer completed successfully');
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Transfer failed: " . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
        header('Location: transfer.php');
        exit();
    }
}

// Add security headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval';");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Money - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <!-- Sidebar -->
    <?php include '../includes/customer_sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <?php renderDashboardHeader($customer, 'customer'); ?>
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php 
            echo htmlspecialchars($_SESSION['success']);
            unset($_SESSION['success']);
            ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php 
            echo htmlspecialchars($_SESSION['error']);
            unset($_SESSION['error']);
            ?>
        </div>
        <?php endif; ?>
        
        <div class="dashboard-container">
            <div class="card">
                <h2>Transfer Money</h2>
                <form method="POST" action="transfer.php" class="transfer-form">
                    <div class="form-group">
                        <label for="from_account">From Account</label>
                        <select name="from_account" id="from_account" required>
                            <option value="">Select Account</option>
                            <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>">
                                <?php echo htmlspecialchars($account['account_number']); ?> 
                                (<?php echo htmlspecialchars($account['account_type']); ?>)
                                - $<?php echo number_format($account['balance'], 2); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="to_account">To Account Number</label>
                        <input type="text" name="to_account" id="to_account" required 
                               placeholder="Enter destination account number">
                    </div>
                    
                    <div class="form-group">
                        <label for="amount">Amount</label>
                        <input type="number" name="amount" id="amount" required 
                               min="0.01" step="0.01" placeholder="Enter amount">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea name="description" id="description" 
                                  placeholder="Enter transfer description"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-exchange-alt"></i> Transfer Money
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Form validation
        document.querySelector('.transfer-form').addEventListener('submit', function(e) {
            const amount = parseFloat(document.getElementById('amount').value);
            const fromAccount = document.getElementById('from_account');
            const selectedOption = fromAccount.options[fromAccount.selectedIndex];
            const balance = parseFloat(selectedOption.text.match(/\$([\d,]+\.\d{2})/)[1].replace(/,/g, ''));
            
            if (amount > balance) {
                e.preventDefault();
                alert('Insufficient balance in the selected account');
            }
        });
    </script>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?> 