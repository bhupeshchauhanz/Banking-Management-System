<?php
session_start();
require_once '../config/db_connect.php';
require_once '../includes/dashboard_layout.php';
require_once '../includes/customer_sidebar.php';

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

// Get loan types
try {
    $stmt = $pdo->prepare("
        SELECT *,
               FORMAT(min_amount, 2) as formatted_min_amount,
               FORMAT(max_amount, 2) as formatted_max_amount
        FROM loan_types
        WHERE status = 'active'
        ORDER BY interest_rate ASC
    ");
    $stmt->execute();
    $loan_types = $stmt->fetchAll();
} catch (PDOException $e) {
    handleDatabaseError($e);
}

// Handle loan application
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $account_id = validateNumeric($_POST['account_id'] ?? 0);
    $loan_type_id = validateNumeric($_POST['loan_type_id'] ?? 0);
    $amount = validateNumeric($_POST['amount'] ?? 0);
    $term_months = validateNumeric($_POST['term_months'] ?? 0);
    $purpose = sanitizeInput($_POST['purpose'] ?? '');
    
    // Validate required fields
    if (!$account_id || !$loan_type_id || !$amount || !$term_months || empty($purpose)) {
        $_SESSION['error'] = 'All fields are required.';
        header('Location: apply_loan.php');
        exit();
    }
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get loan type details
        $stmt = $pdo->prepare("
            SELECT * FROM loan_types 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$loan_type_id]);
        $loan_type = $stmt->fetch();
        
        if (!$loan_type) {
            throw new Exception('Invalid loan type selected.');
        }
        
        // Validate amount against loan type limits
        if ($amount < $loan_type['min_amount'] || $amount > $loan_type['max_amount']) {
            throw new Exception('Loan amount must be between $' . number_format($loan_type['min_amount'], 2) . 
                              ' and $' . number_format($loan_type['max_amount'], 2));
        }
        
        // Validate term months against loan type limits
        if ($term_months < $loan_type['min_term'] || $term_months > $loan_type['max_term']) {
            throw new Exception('Loan term must be between ' . $loan_type['min_term'] . 
                              ' and ' . $loan_type['max_term'] . ' months');
        }
        
        // Calculate monthly payment
        $interest_rate = $loan_type['interest_rate'] / 100;
        $monthly_rate = $interest_rate / 12;
        $monthly_payment = ($amount * $monthly_rate * pow(1 + $monthly_rate, $term_months)) / 
                          (pow(1 + $monthly_rate, $term_months) - 1);
        
        // Calculate total payment and interest
        $total_payment = $monthly_payment * $term_months;
        $total_interest = $total_payment - $amount;
        
        // Insert loan application
        $stmt = $pdo->prepare("
            INSERT INTO loans (
                account_id, loan_type_id, amount, term_months,
                interest_rate, monthly_payment, total_payment,
                total_interest, purpose, status, created_at,
                due_date, remaining_amount
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending',
                NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), ?
            )
        ");
        $stmt->execute([
            $account_id, $loan_type_id, $amount, $term_months,
            $loan_type['interest_rate'], $monthly_payment, $total_payment,
            $total_interest, $purpose, $term_months, $amount
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        handleSuccess('Loan application submitted successfully. We will review your application shortly.');
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Loan application failed: " . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
        header('Location: apply_loan.php');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Loan - SecureBank</title>
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
                <h2>Apply for Loan</h2>
                <form method="POST" action="apply_loan.php" class="loan-form">
                    <div class="form-group">
                        <label for="account_id">Select Account</label>
                        <select name="account_id" id="account_id" required>
                            <option value="">Choose an account</option>
                            <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>">
                                <?php echo htmlspecialchars($account['account_number']); ?> 
                                (<?php echo htmlspecialchars($account['type']); ?>)
                                - $<?php echo $account['formatted_balance']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="loan_type_id">Loan Type</label>
                        <select name="loan_type_id" id="loan_type_id" required>
                            <option value="">Select loan type</option>
                            <?php foreach ($loan_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" 
                                    data-min="<?php echo $type['min_amount']; ?>"
                                    data-max="<?php echo $type['max_amount']; ?>"
                                    data-rate="<?php echo $type['interest_rate']; ?>"
                                    data-min-term="<?php echo $type['min_term']; ?>"
                                    data-max-term="<?php echo $type['max_term']; ?>">
                                <?php echo htmlspecialchars($type['name']); ?> 
                                (<?php echo $type['interest_rate']; ?>% APR)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="loan-type-info" id="loanTypeInfo"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="amount">Loan Amount</label>
                        <input type="number" name="amount" id="amount" required
                               step="0.01" min="0">
                        <div class="amount-info" id="amountInfo"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="term_months">Loan Term (Months)</label>
                        <input type="number" name="term_months" id="term_months" required
                               min="1">
                        <div class="term-info" id="termInfo"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="purpose">Loan Purpose</label>
                        <textarea name="purpose" id="purpose" required
                                  placeholder="Please describe the purpose of this loan"></textarea>
                    </div>
                    
                    <div class="loan-calculator" id="loanCalculator">
                        <h3>Loan Calculator</h3>
                        <div class="calculator-results">
                            <div class="result-item">
                                <span>Monthly Payment:</span>
                                <span id="monthlyPayment">$0.00</span>
                            </div>
                            <div class="result-item">
                                <span>Total Payment:</span>
                                <span id="totalPayment">$0.00</span>
                            </div>
                            <div class="result-item">
                                <span>Total Interest:</span>
                                <span id="totalInterest">$0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Application
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Loan calculator
        const loanTypeSelect = document.getElementById('loan_type_id');
        const amountInput = document.getElementById('amount');
        const termInput = document.getElementById('term_months');
        const loanTypeInfo = document.getElementById('loanTypeInfo');
        const amountInfo = document.getElementById('amountInfo');
        const termInfo = document.getElementById('termInfo');
        const monthlyPayment = document.getElementById('monthlyPayment');
        const totalPayment = document.getElementById('totalPayment');
        const totalInterest = document.getElementById('totalInterest');
        
        function updateLoanInfo() {
            const selectedOption = loanTypeSelect.options[loanTypeSelect.selectedIndex];
            if (selectedOption.value) {
                const minAmount = parseFloat(selectedOption.dataset.min);
                const maxAmount = parseFloat(selectedOption.dataset.max);
                const rate = parseFloat(selectedOption.dataset.rate);
                const minTerm = parseInt(selectedOption.dataset.minTerm);
                const maxTerm = parseInt(selectedOption.dataset.maxTerm);
                
                loanTypeInfo.textContent = `Amount: $${minAmount.toFixed(2)} - $${maxAmount.toFixed(2)}, Term: ${minTerm} - ${maxTerm} months`;
                amountInput.min = minAmount;
                amountInput.max = maxAmount;
                termInput.min = minTerm;
                termInput.max = maxTerm;
                
                calculateLoan();
            }
        }
        
        function calculateLoan() {
            const amount = parseFloat(amountInput.value) || 0;
            const term = parseInt(termInput.value) || 0;
            const selectedOption = loanTypeSelect.options[loanTypeSelect.selectedIndex];
            
            if (selectedOption.value && amount > 0 && term > 0) {
                const rate = parseFloat(selectedOption.dataset.rate) / 100;
                const monthlyRate = rate / 12;
                const monthlyPaymentValue = (amount * monthlyRate * Math.pow(1 + monthlyRate, term)) / 
                                         (Math.pow(1 + monthlyRate, term) - 1);
                const totalPaymentValue = monthlyPaymentValue * term;
                const totalInterestValue = totalPaymentValue - amount;
                
                monthlyPayment.textContent = `$${monthlyPaymentValue.toFixed(2)}`;
                totalPayment.textContent = `$${totalPaymentValue.toFixed(2)}`;
                totalInterest.textContent = `$${totalInterestValue.toFixed(2)}`;
            } else {
                monthlyPayment.textContent = '$0.00';
                totalPayment.textContent = '$0.00';
                totalInterest.textContent = '$0.00';
            }
        }
        
        loanTypeSelect.addEventListener('change', updateLoanInfo);
        amountInput.addEventListener('input', calculateLoan);
        termInput.addEventListener('input', calculateLoan);
        
        // Form validation
        document.querySelector('.loan-form').addEventListener('submit', function(e) {
            const selectedOption = loanTypeSelect.options[loanTypeSelect.selectedIndex];
            if (!selectedOption.value) {
                e.preventDefault();
                alert('Please select a loan type');
                return;
            }
            
            const amount = parseFloat(amountInput.value);
            const minAmount = parseFloat(selectedOption.dataset.min);
            const maxAmount = parseFloat(selectedOption.dataset.max);
            
            if (amount < minAmount || amount > maxAmount) {
                e.preventDefault();
                alert(`Loan amount must be between $${minAmount.toFixed(2)} and $${maxAmount.toFixed(2)}`);
                return;
            }
            
            const term = parseInt(termInput.value);
            const minTerm = parseInt(selectedOption.dataset.minTerm);
            const maxTerm = parseInt(selectedOption.dataset.maxTerm);
            
            if (term < minTerm || term > maxTerm) {
                e.preventDefault();
                alert(`Loan term must be between ${minTerm} and ${maxTerm} months`);
                return;
            }
        });
    </script>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?> 