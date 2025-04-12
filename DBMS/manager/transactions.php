<?php
session_start();
require_once '../config/database.php';
require_once '../includes/cache_functions.php';

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create MySQLi connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check MySQLi connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create PDO connection
try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: ../auth/login.php');
    exit();
}

// Get manager information
$manager_id = $_SESSION['user_id'];
$manager_query = "SELECT s.* FROM staff s 
                 JOIN users u ON s.user_id = u.id 
                 WHERE u.id = ? AND u.role = 'manager'";
$stmt = $conn->prepare($manager_query);
$stmt->bind_param("i", $manager_id);
$stmt->execute();
$manager = $stmt->get_result()->fetch_assoc();

if (!$manager) {
    header('Location: ../auth/logout.php');
    exit();
}

// Get transaction statistics with caching
$stats = getCachedData('transaction_stats', 300); // Cache for 5 minutes
if ($stats === false) {
    $stats_query = "SELECT 
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_transactions,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_transactions,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_transactions,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as total_completed_amount,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as total_pending_amount
                    FROM transactions";
    $stats = $pdo->query($stats_query)->fetch();
    cacheData('transaction_stats', $stats, 300);
}

// Get recent transactions with caching
$recent_transactions = getCachedData('recent_transactions', 60); // Cache for 1 minute
if ($recent_transactions === false) {
    $recent_query = "SELECT t.*, 
                    c.first_name, c.last_name, c.email, c.phone,
                    a.account_number
                    FROM transactions t 
                    JOIN accounts a ON t.account_id = a.id 
                    JOIN customers c ON a.customer_id = c.id 
                    ORDER BY t.created_at DESC 
                    LIMIT 10";
    $recent_transactions = $pdo->query($recent_query)->fetchAll();
    cacheData('recent_transactions', $recent_transactions, 60);
}

// Get high-value transactions with caching
$high_value_transactions = getCachedData('high_value_transactions', 300); // Cache for 5 minutes
if ($high_value_transactions === false) {
    $high_value_query = "SELECT t.*, 
                        c.first_name, c.last_name, c.email, c.phone,
                        a.account_number
                        FROM transactions t 
                        JOIN accounts a ON t.account_id = a.id 
                        JOIN customers c ON a.customer_id = c.id 
                        WHERE t.amount > 50000 
                        ORDER BY t.amount DESC 
                        LIMIT 10";
    $high_value_transactions = $pdo->query($high_value_query)->fetchAll();
    cacheData('high_value_transactions', $high_value_transactions, 300);
}

// Get all transactions with account and customer details
$transactions_query = "SELECT t.*, 
                      a.account_number,
                      c.first_name, c.last_name, c.email,
                      u.username, u.profile_photo
                      FROM transactions t
                      JOIN accounts a ON t.account_id = a.id
                      JOIN customers c ON a.customer_id = c.id
                      JOIN users u ON c.user_id = u.id
                      ORDER BY t.created_at DESC";
$transactions = $pdo->query($transactions_query)->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Transactions - SecureBank</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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

        body {
            background: #f8f9fa;
            min-height: 100vh;
            margin: 0;
            padding: 30px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .main-content {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 40px;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
            margin: 0;
        }

        .transaction-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .stat-card h3 {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0 0 10px;
        }

        .stat-card .value {
            color: var(--text-primary);
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }

        .search-container {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }

        .search-input {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .filter-select {
            padding: 12px 20px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            background: white;
            cursor: pointer;
            transition: var(--transition);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .table-container {
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-top: 20px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: var(--primary-color);
            color: white;
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .table td {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover {
            background: rgba(67, 97, 238, 0.02);
        }

        .customer-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border-color);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-completed {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-pending {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .status-failed {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-weight: 500;
            font-size: 0.9rem;
            transition: var(--transition);
            cursor: pointer;
            border: none;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #3aa8d1;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #d90429;
            transform: translateY(-2px);
        }

        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .alert-danger {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .main-content {
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .search-container {
                flex-direction: column;
            }

            .table-container {
                overflow-x: auto;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1>Manage Transactions</h1>
                    <p>View and manage all bank transactions</p>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <div class="transaction-stats">
                <div class="stat-card">
                    <h3>Total Transactions</h3>
                    <p class="value"><?php echo isset($stats['total_transactions']) ? $stats['total_transactions'] : 0; ?></p>
                </div>
                <div class="stat-card">
                    <h3>Completed Transactions</h3>
                    <p class="value"><?php echo isset($stats['completed_transactions']) ? $stats['completed_transactions'] : 0; ?></p>
                </div>
                <div class="stat-card">
                    <h3>Pending Transactions</h3>
                    <p class="value"><?php echo isset($stats['pending_transactions']) ? $stats['pending_transactions'] : 0; ?></p>
                </div>
                <div class="stat-card">
                    <h3>Failed Transactions</h3>
                    <p class="value"><?php echo isset($stats['failed_transactions']) ? $stats['failed_transactions'] : 0; ?></p>
                </div>
            </div>

            <div class="search-container">
                <input type="text" class="search-input" placeholder="Search transactions..." id="searchInput">
                <select class="filter-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="completed">Completed</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                </select>
            </div>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Transaction ID</th>
                            <th>Account</th>
                            <th>Customer</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($transaction['transaction_id']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['account_number']); ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <img src="<?php echo $transaction['profile_photo'] ? '../assets/uploads/profiles/' . $transaction['profile_photo'] : '../assets/images/default-avatar.png'; ?>" 
                                             alt="Profile" class="customer-photo">
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></div>
                                            <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                                <?php echo htmlspecialchars($transaction['email']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge" style="background: rgba(67, 97, 238, 0.1); color: var(--primary-color);">
                                        <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                        <?php echo ucfirst($transaction['transaction_type']); ?>
                                    </span>
                                </td>
                                <td style="font-weight: 600;">$<?php echo number_format($transaction['amount'], 2); ?></td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    $status_icon = '';
                                    switch ($transaction['status']) {
                                        case 'completed':
                                            $status_class = 'status-completed';
                                            $status_icon = 'check-circle';
                                            break;
                                        case 'pending':
                                            $status_class = 'status-pending';
                                            $status_icon = 'clock';
                                            break;
                                        case 'failed':
                                            $status_class = 'status-failed';
                                            $status_icon = 'times-circle';
                                            break;
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <i class="fas fa-<?php echo $status_icon; ?>"></i>
                                        <?php echo ucfirst($transaction['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view_transaction.php?id=<?php echo $transaction['id']; ?>" class="btn btn-success">
                                            <i class="fas fa-eye"></i>
                                            View
                                        </a>
                                        <?php if ($transaction['status'] === 'pending'): ?>
                                            <a href="approve_transaction.php?id=<?php echo $transaction['id']; ?>" class="btn btn-primary">
                                                <i class="fas fa-check"></i>
                                                Approve
                                            </a>
                                            <a href="reject_transaction.php?id=<?php echo $transaction['id']; ?>" class="btn btn-danger">
                                                <i class="fas fa-times"></i>
                                                Reject
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchText = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('.table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchText) ? '' : 'none';
            });
        });

        // Status filter
        document.getElementById('statusFilter').addEventListener('change', function(e) {
            const status = e.target.value;
            const rows = document.querySelectorAll('.table tbody tr');
            
            rows.forEach(row => {
                if (!status) {
                    row.style.display = '';
                    return;
                }
                
                const statusBadge = row.querySelector('.status-badge').textContent.trim();
                row.style.display = statusBadge.toLowerCase().includes(status) ? '' : 'none';
            });
        });
    </script>
</body>
</html> 