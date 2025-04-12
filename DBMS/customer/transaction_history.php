<?php
session_start();
require_once '../config/database.php';
require_once '../includes/customer_sidebar.php';

// Check if user is logged in and is a customer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../index.php');
    exit();
}

// Get customer information
try {
    // First get the customer record
    $stmt = $pdo->prepare("
        SELECT c.*, u.username, u.profile_photo
        FROM customers c 
        JOIN users u ON c.user_id = u.id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $customer = $stmt->fetch();

    if (!$customer) {
        die("Customer record not found");
    }

    // Get customer's account information
    $stmt = $pdo->prepare("
        SELECT a.id as account_id, a.account_number, a.balance 
        FROM accounts a 
        WHERE a.customer_id = ?
    ");
    $stmt->execute([$customer['id']]);
    $account = $stmt->fetch();

    if (!$account) {
        die("No account found for this customer");
    }

    // Get filter parameters
    $type = isset($_GET['type']) ? $_GET['type'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

    // Build query for transactions
    $query = "
        SELECT t.*, 
               CASE 
                   WHEN t.type = 'transfer' AND t.amount > 0 THEN 'incoming'
                   WHEN t.type = 'transfer' AND t.amount < 0 THEN 'outgoing'
                   ELSE t.type
               END as transaction_type
        FROM transactions t
        WHERE t.account_id = ?
    ";
    $params = [$account['account_id']];

    if ($type) {
        $query .= " AND t.type = ?";
        $params[] = $type;
    }
    if ($status) {
        $query .= " AND t.status = ?";
        $params[] = $status;
    }
    if ($start_date) {
        $query .= " AND DATE(t.created_at) >= ?";
        $params[] = $start_date;
    }
    if ($end_date) {
        $query .= " AND DATE(t.created_at) <= ?";
        $params[] = $end_date;
    }

    $query .= " ORDER BY t.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/customer_sidebar.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --primary-dark: #3730a3;
            --primary-light: #4895ef;
            --secondary-color: #3f37c9;
            --accent-color: #4cc9f0;
            --success: #4cc9f0;
            --warning: #f72585;
            --danger: #f72585;
            --dark-bg: #1a1b26;
            --card-bg: #ffffff;
            --text-primary: #2b2d42;
            --text-secondary: #8d99ae;
            --border-color: #e9ecef;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.07);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: #f8f9fa;
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            background: #f8f9fa;
            min-height: 100vh;
            overflow-y: auto;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        .header-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            background: var(--card-bg);
            color: var(--text-primary);
            text-decoration: none;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .header-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        /* Transaction History */
        .history-container {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 30px;
            box-shadow: var(--shadow-md);
        }
        
        .history-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .history-header i {
            font-size: 2rem;
            color: var(--primary-color);
        }
        
        .history-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .account-info {
            background: rgba(67, 97, 238, 0.05);
            border-radius: var(--radius-sm);
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .account-details h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-primary);
        }
        
        .account-details p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .account-balance {
            text-align: right;
        }
        
        .balance-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .balance-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        /* Filters */
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: var(--radius-sm);
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .filter-control {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            transition: var(--transition);
        }
        
        .filter-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        /* Transactions Table */
        .transactions-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
        }
        
        .transactions-table th {
            background: #f8f9fa;
            padding: 15px;
            font-weight: 600;
            text-align: left;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-color);
        }
        
        .transactions-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .transactions-table tr:last-child td {
            border-bottom: none;
        }
        
        .transactions-table tr:hover {
            background: #f8f9fa;
        }
        
        .transaction-type {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .transaction-type i {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        
        .transaction-type.incoming i {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }
        
        .transaction-type.outgoing i {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
        }
        
        .transaction-amount {
            font-weight: 600;
        }
        
        .transaction-amount.incoming {
            color: var(--success);
        }
        
        .transaction-amount.outgoing {
            color: var(--danger);
        }
        
        .transaction-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .status-pending {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }
        
        .status-completed {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }
        
        .status-rejected {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
        }
        
        .transaction-date {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .no-transactions {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }
        
        .no-transactions i {
            font-size: 3rem;
            margin-bottom: 20px;
            color: var(--border-color);
        }
        
        .no-transactions p {
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        
        .no-transactions small {
            font-size: 0.9rem;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .sidebar {
                width: 80px;
                padding: 30px 0;
            }
            
            .sidebar-logo h1,
            .profile-info,
            .menu-item span {
                display: none;
            }
            
            .profile-photo {
                width: 40px;
                height: 40px;
            }
            
            .main-content {
                margin-left: 80px;
            }
            
            .menu-item {
                justify-content: center;
                padding: 15px;
            }
            
            .menu-item i {
                font-size: 1.4rem;
            }
            
            .menu-item.active::before {
                width: 100%;
                height: 4px;
                top: auto;
                bottom: 0;
            }
        }
        
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
            }
            
            .header-btn {
                flex: 1;
                justify-content: center;
            }
            
            .account-info {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .account-balance {
                text-align: center;
            }
            
            .filters {
                grid-template-columns: 1fr;
            }
            
            .transactions-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .transactions-table th,
            .transactions-table td {
                min-width: 120px;
            }
        }
    </style>
</head>
<body>
    <?php renderCustomerSidebar($customer, 'history'); ?>
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Transaction History</h1>
            <div class="header-actions">
                <a href="dashboard.php" class="header-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <div class="history-container">
            <div class="history-header">
                <i class="fas fa-history"></i>
                <h2>Your Transactions</h2>
            </div>
            
            <div class="account-info">
                <div class="account-details">
                    <h3>Your Account</h3>
                    <p><?php echo htmlspecialchars($account['account_number']); ?></p>
                </div>
                <div class="account-balance">
                    <div class="balance-amount">$<?php echo number_format($account['balance'], 2); ?></div>
                    <div class="balance-label">Available Balance</div>
                </div>
            </div>
            
            <form method="GET" class="filters">
                <div class="filter-group">
                    <label for="type" class="filter-label">Transaction Type</label>
                    <select name="type" id="type" class="filter-control">
                        <option value="">All Types</option>
                        <option value="transfer" <?php echo $type === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                        <option value="deposit" <?php echo $type === 'deposit' ? 'selected' : ''; ?>>Deposit</option>
                        <option value="withdrawal" <?php echo $type === 'withdrawal' ? 'selected' : ''; ?>>Withdrawal</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="status" class="filter-label">Status</label>
                    <select name="status" id="status" class="filter-control">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="start_date" class="filter-label">Start Date</label>
                    <input type="date" id="start_date" name="start_date" class="filter-control" value="<?php echo $start_date; ?>">
                </div>
                
                <div class="filter-group">
                    <label for="end_date" class="filter-label">End Date</label>
                    <input type="date" id="end_date" name="end_date" class="filter-control" value="<?php echo $end_date; ?>">
                </div>
            </form>
            
            <?php if (empty($transactions)): ?>
            <div class="no-transactions">
                <i class="fas fa-receipt"></i>
                <p>No transactions found</p>
                <small>Try adjusting your filters or make a new transaction</small>
            </div>
            <?php else: ?>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                    <tr>
                        <td>
                            <div class="transaction-type <?php echo $transaction['transaction_type']; ?>">
                                <i class="fas fa-<?php 
                                    echo $transaction['transaction_type'] === 'incoming' ? 'arrow-down' : 
                                        ($transaction['transaction_type'] === 'outgoing' ? 'arrow-up' : 
                                        ($transaction['type'] === 'deposit' ? 'plus' : 'minus')); 
                                ?>"></i>
                                <span><?php 
                                    echo $transaction['transaction_type'] === 'incoming' ? 'Incoming' : 
                                        ($transaction['transaction_type'] === 'outgoing' ? 'Outgoing' : 
                                        ucfirst($transaction['type'])); 
                                ?></span>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                        <td>
                            <span class="transaction-amount <?php echo $transaction['transaction_type']; ?>">
                                <?php echo $transaction['transaction_type'] === 'incoming' ? '+' : '-'; ?>
                                $<?php echo number_format(abs($transaction['amount']), 2); ?>
                            </span>
                        </td>
                        <td>
                            <span class="transaction-status status-<?php echo $transaction['status']; ?>">
                                <i class="fas fa-<?php 
                                    echo $transaction['status'] === 'completed' ? 'check-circle' : 
                                        ($transaction['status'] === 'pending' ? 'clock' : 'times-circle'); 
                                ?>"></i>
                                <?php echo ucfirst($transaction['status']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="transaction-date">
                                <?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-submit form when filters change
        document.querySelectorAll('.filter-control').forEach(control => {
            control.addEventListener('change', () => {
                document.querySelector('.filters').submit();
            });
        });
    </script>
</body>
</html> 