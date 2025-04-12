<?php
session_start();
require_once '../config/db_connect.php';
require_once '../includes/dashboard_layout.php';
require_once '../includes/customer_sidebar.php';

// Validate session and role
validateSession('customer');

// Get PDO connection
$pdo = getPDOConnection();

// Get customer loans
try {
    $stmt = $pdo->prepare("
        SELECT l.*, a.account_number,
               DATE_FORMAT(l.created_at, '%b %d, %Y') as loan_date,
               DATE_FORMAT(l.due_date, '%b %d, %Y') as formatted_due_date,
               FORMAT(l.amount, 2) as formatted_amount,
               FORMAT(l.remaining_amount, 2) as formatted_remaining,
               FORMAT(l.interest_rate, 2) as formatted_interest,
               CASE 
                   WHEN l.status = 'pending' THEN 'warning'
                   WHEN l.status = 'approved' THEN 'success'
                   WHEN l.status = 'rejected' THEN 'danger'
                   WHEN l.status = 'paid' THEN 'info'
                   ELSE 'secondary'
               END as status_class
        FROM loans l
        JOIN accounts a ON l.account_id = a.id
        JOIN customers c ON a.customer_id = c.id
        WHERE c.user_id = ?
        ORDER BY l.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $loans = $stmt->fetchAll();
} catch (PDOException $e) {
    handleDatabaseError($e);
}

// Get loan payment history if requested
if (isset($_GET['loan_id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT lp.*, 
                   DATE_FORMAT(lp.payment_date, '%b %d, %Y') as formatted_date,
                   FORMAT(lp.amount, 2) as formatted_amount
            FROM loan_payments lp
            JOIN loans l ON lp.loan_id = l.id
            JOIN accounts a ON l.account_id = a.id
            JOIN customers c ON a.customer_id = c.id
            WHERE l.id = ? AND c.user_id = ?
            ORDER BY lp.payment_date DESC
        ");
        $stmt->execute([$_GET['loan_id'], $_SESSION['user_id']]);
        $payments = $stmt->fetchAll();
    } catch (PDOException $e) {
        handleDatabaseError($e);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Loans - SecureBank</title>
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
                <div class="card-header">
                    <h2>My Loans</h2>
                    <a href="apply_loan.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Apply for New Loan
                    </a>
                </div>
                
                <?php if (empty($loans)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <p>You don't have any loans yet.</p>
                    <a href="apply_loan.php" class="btn btn-primary">Apply for a Loan</a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Loan ID</th>
                                <th>Account</th>
                                <th>Amount</th>
                                <th>Remaining</th>
                                <th>Interest Rate</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($loan['id']); ?></td>
                                <td><?php echo htmlspecialchars($loan['account_number']); ?></td>
                                <td>$<?php echo htmlspecialchars($loan['formatted_amount']); ?></td>
                                <td>$<?php echo htmlspecialchars($loan['formatted_remaining']); ?></td>
                                <td><?php echo htmlspecialchars($loan['formatted_interest']); ?>%</td>
                                <td><?php echo htmlspecialchars($loan['formatted_due_date']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $loan['status_class']; ?>">
                                        <?php echo ucfirst(htmlspecialchars($loan['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?loan_id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-history"></i> Payment History
                                    </a>
                                    <?php if ($loan['status'] === 'approved' && $loan['remaining_amount'] > 0): ?>
                                    <a href="make_payment.php?loan_id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-money-bill-wave"></i> Make Payment
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <?php if (isset($payments)): ?>
                <div class="modal" id="paymentHistoryModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Payment History</h3>
                            <a href="my_loans.php" class="close">&times;</a>
                        </div>
                        <div class="modal-body">
                            <?php if (empty($payments)): ?>
                            <p>No payments made yet.</p>
                            <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($payment['formatted_date']); ?></td>
                                        <td>$<?php echo htmlspecialchars($payment['formatted_amount']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['reference_number']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Show payment history modal if loan_id is in URL
        const urlParams = new URLSearchParams(window.location.search);
        const loanId = urlParams.get('loan_id');
        if (loanId) {
            document.getElementById('paymentHistoryModal').style.display = 'block';
        }
        
        // Close modal when clicking the close button
        document.querySelector('.close')?.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('paymentHistoryModal').style.display = 'none';
            history.pushState({}, '', 'my_loans.php');
        });
    </script>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?> 