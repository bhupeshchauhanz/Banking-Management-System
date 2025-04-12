<?php
session_start();
require_once '../config/database.php';

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create MySQLi connection with error handling
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed. Please try again later.");
}

// Create PDO connection with error handling
try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch(PDOException $e) {
    error_log("PDO Connection failed: " . $e->getMessage());
    die("Connection failed. Please try again later.");
}

// Session and role validation
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: ../auth/login.php');
    exit();
}

// Get manager information with prepared statement
$manager_id = $_SESSION['user_id'];
$manager_query = "SELECT s.*, u.username, u.profile_photo 
                 FROM staff s 
                 JOIN users u ON s.user_id = u.id 
                 WHERE u.id = ? AND u.role = 'manager' AND u.is_active = 1";
$stmt = $conn->prepare($manager_query);
$stmt->bind_param("i", $manager_id);
$stmt->execute();
$manager = $stmt->get_result()->fetch_assoc();

if (!$manager) {
    header('Location: ../auth/logout.php');
    exit();
}

// Get customer ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: customers.php');
    exit();
}

$customer_id = (int)$_GET['id'];

// Get customer details with prepared statement
$customer_query = "SELECT c.*, u.username, u.profile_photo, u.is_active, u.last_login,
                  COUNT(a.id) as total_accounts,
                  SUM(a.balance) as total_balance,
                  (SELECT COUNT(*) FROM audit_logs WHERE user_id = u.id AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as recent_activity
                  FROM customers c 
                  JOIN users u ON c.user_id = u.id 
                  LEFT JOIN accounts a ON c.id = a.customer_id
                  WHERE c.id = ? AND u.role = 'customer'
                  GROUP BY c.id";
$stmt = $pdo->prepare($customer_query);
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: customers.php');
    exit();
}

// Get customer's accounts
$accounts_query = "SELECT a.* 
                  FROM accounts a 
                  WHERE a.customer_id = ? 
                  ORDER BY a.created_at DESC";
$stmt = $pdo->prepare($accounts_query);
$stmt->execute([$customer_id]);
$accounts = $stmt->fetchAll();

// Get recent transactions
$transactions_query = "SELECT t.*, a.account_number 
                      FROM transactions t 
                      JOIN accounts a ON t.account_id = a.id 
                      WHERE a.customer_id = ? 
                      ORDER BY t.created_at DESC 
                      LIMIT 10";
$stmt = $pdo->prepare($transactions_query);
$stmt->execute([$customer_id]);
$transactions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Customer - SecureBank</title>
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

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--primary-color);
            color: white;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .back-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .customer-profile {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }

        .profile-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 30px;
            box-shadow: var(--shadow-sm);
        }

        .profile-photo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 20px;
            display: block;
            border: 3px solid var(--border-color);
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            text-align: center;
            margin-bottom: 5px;
        }

        .profile-email {
            color: var(--text-secondary);
            text-align: center;
            margin-bottom: 20px;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            background: rgba(67, 97, 238, 0.05);
            border-radius: var(--radius-sm);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
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

        .status-active {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .table-container {
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 30px;
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

        .amount-positive {
            color: var(--success);
        }

        .amount-negative {
            color: var(--danger);
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .main-content {
                padding: 20px;
            }

            .customer-profile {
                grid-template-columns: 1fr;
            }

            .profile-stats {
                grid-template-columns: 1fr;
            }

            .table-container {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <h1>Customer Details</h1>
                <a href="customers.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    Back to Customers
                </a>
            </div>

            <div class="customer-profile">
                <div class="profile-card">
                    <img src="<?php echo $customer['profile_photo'] ? '../assets/uploads/profiles/' . $customer['profile_photo'] : '../assets/images/default-avatar.png'; ?>" 
                         alt="Profile" class="profile-photo">
                    <h2 class="profile-name"><?php echo htmlspecialchars($customer['username']); ?></h2>
                    <div class="profile-email"><?php echo htmlspecialchars($customer['username']); ?></div>
                    
                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $customer['total_accounts']; ?></div>
                            <div class="stat-label">Accounts</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">$<?php echo number_format($customer['total_balance'], 2); ?></div>
                            <div class="stat-label">Total Balance</div>
                        </div>
                    </div>

                    <div style="margin-top: 20px; text-align: center;">
                        <span class="status-badge <?php echo $customer['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                            <?php echo $customer['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                </div>

                <div>
                    <h3 class="section-title">Account Information</h3>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Account Number</th>
                                    <th>Type</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($accounts as $account): ?>
                                    <tr>
                                        <td><?php echo $account['account_number']; ?></td>
                                        <td><?php echo ucfirst($account['account_type']); ?></td>
                                        <td>$<?php echo number_format($account['balance'], 2); ?></td>
                                        <td>
                                            <span class="status-badge status-active">
                                                Active
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($account['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="section-title">Recent Transactions</h3>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Account</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                        <td><?php echo $transaction['account_number']; ?></td>
                                        <td><?php echo ucfirst($transaction['transaction_type']); ?></td>
                                        <td class="<?php echo $transaction['amount'] >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                            $<?php echo number_format(abs($transaction['amount']), 2); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 