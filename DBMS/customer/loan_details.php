<?php
session_start();
require_once '../config/db_connect.php';
require_once '../includes/dashboard_layout.php';
require_once '../includes/customer_sidebar.php';

// Validate session and role
validateSession('customer');

// Get PDO connection
$pdo = getPDOConnection();

// Get loan ID from URL
$loan_id = validateNumeric($_GET['id'] ?? 0);

if (!$loan_id) {
    $_SESSION['error'] = 'Invalid loan ID.';
    header('Location: my_loans.php');
    exit();
}

try {
    // Get loan details with formatted amounts and dates
    $stmt = $pdo->prepare("
        SELECT l.*,
               lt.name as loan_type_name,
               lt.interest_rate,
               a.account_number,
               FORMAT(l.amount, 2) as formatted_amount,
               FORMAT(l.monthly_payment, 2) as formatted_monthly_payment,
               FORMAT(l.total_payment, 2) as formatted_total_payment,
               FORMAT(l.total_interest, 2) as formatted_total_interest,
               FORMAT(l.remaining_amount, 2) as formatted_remaining_amount,
               DATE_FORMAT(l.created_at, '%M %d, %Y') as formatted_created_at,
               DATE_FORMAT(l.due_date, '%M %d, %Y') as formatted_due_date,
               CASE 
                   WHEN l.status = 'pending' THEN 'warning'
                   WHEN l.status = 'approved' THEN 'success'
                   WHEN l.status = 'rejected' THEN 'danger'
                   WHEN l.status = 'paid' THEN 'info'
                   ELSE 'secondary'
               END as status_class
        FROM loans l
        JOIN loan_types lt ON l.loan_type_id = lt.id
        JOIN accounts a ON l.account_id = a.id
        JOIN customers c ON a.customer_id = c.id
        WHERE l.id = ? AND c.user_id = ?
    ");
    $stmt->execute([$loan_id, $_SESSION['user_id']]);
    $loan = $stmt->fetch();
    
    if (!$loan) {
        throw new Exception('Loan not found or access denied.');
    }
    
    // Get payment history
    $stmt = $pdo->prepare("
        SELECT p.*,
               FORMAT(p.amount, 2) as formatted_amount,
               DATE_FORMAT(p.payment_date, '%M %d, %Y') as formatted_payment_date,
               CASE 
                   WHEN p.status = 'pending' THEN 'warning'
                   WHEN p.status = 'completed' THEN 'success'
                   WHEN p.status = 'failed' THEN 'danger'
                   ELSE 'secondary'
               END as status_class
        FROM loan_payments p
        WHERE p.loan_id = ?
        ORDER BY p.payment_date DESC
    ");
    $stmt->execute([$loan_id]);
    $payments = $stmt->fetchAll();
    
    // Calculate payment progress
    $total_paid = array_sum(array_map(function($payment) {
        return $payment['status'] === 'completed' ? $payment['amount'] : 0;
    }, $payments));
    $progress_percent = ($total_paid / $loan['total_payment']) * 100;
    
} catch (Exception $e) {
    logError("Error retrieving loan details: " . $e->getMessage());
    $_SESSION['error'] = $e->getMessage();
    header('Location: my_loans.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Details - SecureBank</title>
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
                <div class="loan-header">
                    <h2>
                        <i class="fas fa-file-invoice-dollar"></i>
                        Loan Details
                    </h2>
                    <span class="badge badge-<?php echo $loan['status_class']; ?>">
                        <?php echo ucfirst($loan['status']); ?>
                    </span>
                </div>
                
                <div class="loan-info">
                    <div class="info-group">
                        <label>Loan Type</label>
                        <span><?php echo htmlspecialchars($loan['loan_type_name']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Account Number</label>
                        <span><?php echo htmlspecialchars($loan['account_number']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Amount</label>
                        <span>$<?php echo $loan['formatted_amount']; ?></span>
                    </div>
                    <div class="info-group">
                        <label>Interest Rate</label>
                        <span><?php echo $loan['interest_rate']; ?>%</span>
                    </div>
                    <div class="info-group">
                        <label>Monthly Payment</label>
                        <span>$<?php echo $loan['formatted_monthly_payment']; ?></span>
                    </div>
                    <div class="info-group">
                        <label>Total Payment</label>
                        <span>$<?php echo $loan['formatted_total_payment']; ?></span>
                    </div>
                    <div class="info-group">
                        <label>Total Interest</label>
                        <span>$<?php echo $loan['formatted_total_interest']; ?></span>
                    </div>
                    <div class="info-group">
                        <label>Remaining Amount</label>
                        <span>$<?php echo $loan['formatted_remaining_amount']; ?></span>
                    </div>
                    <div class="info-group">
                        <label>Application Date</label>
                        <span><?php echo $loan['formatted_created_at']; ?></span>
                    </div>
                    <div class="info-group">
                        <label>Due Date</label>
                        <span><?php echo $loan['formatted_due_date']; ?></span>
                    </div>
                </div>
                
                <div class="loan-progress">
                    <h3>Payment Progress</h3>
                    <div class="progress-bar">
                        <div class="progress" style="width: <?php echo $progress_percent; ?>%"></div>
                    </div>
                    <div class="progress-info">
                        <span>Total Paid: $<?php echo number_format($total_paid, 2); ?></span>
                        <span><?php echo number_format($progress_percent, 1); ?>% Complete</span>
                    </div>
                </div>
                
                <div class="payment-history">
                    <h3>Payment History</h3>
                    <?php if (empty($payments)): ?>
                    <p class="no-data">No payments recorded yet.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Payment Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo $payment['formatted_payment_date']; ?></td>
                                    <td>$<?php echo $payment['formatted_amount']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $payment['status_class']; ?>">
                                            <?php echo ucfirst($payment['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['reference_number']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($loan['status'] === 'approved' && $loan['remaining_amount'] > 0): ?>
                <div class="loan-actions">
                    <a href="make_payment.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-primary">
                        <i class="fas fa-credit-card"></i> Make Payment
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?> 