<?php
session_start();
require_once '../config/database.php';
require_once '../includes/cache_functions.php';

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

    // Debug: Check if database connection is successful
    if ($pdo) {
        error_log("Database connection successful");
    } else {
        error_log("Database connection failed");
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

    // Debug: Check if manager data was retrieved
    if ($manager) {
        error_log("Manager data retrieved successfully");
    } else {
        error_log("Failed to retrieve manager data");
    }

    // Get report parameters
    $report_type = isset($_GET['type']) ? $_GET['type'] : 'daily';
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

    // Get daily transaction report with caching
    $daily_transactions = getCachedData('daily_transactions_' . $date_from . '_' . $date_to, 300); // Cache for 5 minutes
    if ($daily_transactions === false) {
        $daily_query = "SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as total_transactions,
                        SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) as total_deposits,
                        SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as total_withdrawals,
                        SUM(CASE WHEN type = 'transfer' THEN amount ELSE 0 END) as total_transfers
                        FROM transactions 
                        WHERE created_at BETWEEN ? AND ?
                        GROUP BY DATE(created_at)
                        ORDER BY date DESC";
        $stmt = $pdo->prepare($daily_query);
        $stmt->execute([$date_from, $date_to]);
        $daily_transactions = $stmt->fetchAll();
        cacheData('daily_transactions_' . $date_from . '_' . $date_to, $daily_transactions, 300);
    }

    // Get customer activity report with caching
    $customer_activity = getCachedData('customer_activity_' . $date_from . '_' . $date_to, 300); // Cache for 5 minutes
    if ($customer_activity === false) {
        $activity_query = "SELECT 
                          c.id, c.first_name, c.last_name,
                          COUNT(DISTINCT t.id) as total_transactions,
                          SUM(CASE WHEN t.type = 'deposit' THEN t.amount ELSE 0 END) as total_deposits,
                          SUM(CASE WHEN t.type = 'withdrawal' THEN t.amount ELSE 0 END) as total_withdrawals,
                          COUNT(DISTINCT l.id) as total_loans,
                          SUM(CASE WHEN l.status = 'approved' THEN l.amount ELSE 0 END) as total_loan_amount
                          FROM customers c 
                          LEFT JOIN accounts a ON c.id = a.customer_id 
                          LEFT JOIN transactions t ON a.id = t.account_id AND t.created_at BETWEEN ? AND ?
                          LEFT JOIN loans l ON c.id = l.customer_id AND l.created_at BETWEEN ? AND ?
                          GROUP BY c.id
                          ORDER BY total_transactions DESC
                          LIMIT 20";
        $stmt = $pdo->prepare($activity_query);
        $stmt->execute([$date_from, $date_to, $date_from, $date_to]);
        $customer_activity = $stmt->fetchAll();
        cacheData('customer_activity_' . $date_from . '_' . $date_to, $customer_activity, 300);
    }

    // Get loan performance report with caching
    $loan_performance = getCachedData('loan_performance_' . $date_from . '_' . $date_to, 300); // Cache for 5 minutes
    if ($loan_performance === false) {
        $performance_query = "SELECT 
                             l.status,
                             COUNT(*) as total_loans,
                             SUM(amount) as total_amount,
                             AVG(amount) as average_amount,
                             MIN(amount) as min_amount,
                             MAX(amount) as max_amount
                             FROM loans l 
                             WHERE l.created_at BETWEEN ? AND ?
                             GROUP BY l.status";
        $stmt = $pdo->prepare($performance_query);
        $stmt->execute([$date_from, $date_to]);
        $loan_performance = $stmt->fetchAll();
        cacheData('loan_performance_' . $date_from . '_' . $date_to, $loan_performance, 300);
    }

    // Get employee performance report with caching
    $employee_performance = getCachedData('employee_performance_' . $date_from . '_' . $date_to, 300); // Cache for 5 minutes
    if ($employee_performance === false) {
        $employee_query = "SELECT 
                          s.id, s.first_name, s.last_name,
                          COUNT(DISTINCT t.id) as total_transactions,
                          SUM(CASE WHEN t.type = 'deposit' THEN t.amount ELSE 0 END) as total_deposits,
                          SUM(CASE WHEN t.type = 'withdrawal' THEN t.amount ELSE 0 END) as total_withdrawals,
                          SUM(CASE WHEN t.type = 'transfer' THEN t.amount ELSE 0 END) as total_transfers
                          FROM staff s 
                          JOIN users u ON s.user_id = u.id
                          LEFT JOIN transactions t ON t.created_at BETWEEN ? AND ?
                          WHERE u.role = 'employee'
                          GROUP BY s.id
                          ORDER BY total_transactions DESC";
        $stmt = $pdo->prepare($employee_query);
        $stmt->execute([$date_from, $date_to]);
        $employee_performance = $stmt->fetchAll();
        cacheData('employee_performance_' . $date_from . '_' . $date_to, $employee_performance, 300);
    }

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Bank Management System</title>
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

        .filter-container {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
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

        .date-input {
            padding: 12px 20px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }

        .date-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .report-section {
            margin-bottom: 40px;
        }

        .report-section h2 {
            color: var(--text-primary);
            font-size: 1.5rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
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

        .stat-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            margin-bottom: 20px;
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
            background: var(--primary-color);
            color: white;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
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

            .filter-container {
                flex-direction: column;
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
                <div>
                    <h1>Reports</h1>
                    <p>View and analyze bank performance metrics</p>
                </div>
            </div>

            <div class="filter-container">
                <select class="filter-select" id="reportType">
                    <option value="daily" <?php echo $report_type === 'daily' ? 'selected' : ''; ?>>Daily Report</option>
                    <option value="customer" <?php echo $report_type === 'customer' ? 'selected' : ''; ?>>Customer Activity</option>
                    <option value="loan" <?php echo $report_type === 'loan' ? 'selected' : ''; ?>>Loan Performance</option>
                    <option value="employee" <?php echo $report_type === 'employee' ? 'selected' : ''; ?>>Employee Performance</option>
                </select>
                <input type="date" class="date-input" id="dateFrom" value="<?php echo $date_from; ?>">
                <input type="date" class="date-input" id="dateTo" value="<?php echo $date_to; ?>">
                <button class="btn" onclick="updateReport()">
                    <i class="fas fa-sync-alt"></i>
                    Update Report
                </button>
            </div>

            <?php if ($report_type === 'daily'): ?>
                <div class="report-section">
                    <h2>Daily Transaction Report</h2>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total Transactions</th>
                                    <th>Total Deposits</th>
                                    <th>Total Withdrawals</th>
                                    <th>Total Transfers</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daily_transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($transaction['date'])); ?></td>
                                        <td><?php echo $transaction['total_transactions']; ?></td>
                                        <td>$<?php echo number_format($transaction['total_deposits'], 2); ?></td>
                                        <td>$<?php echo number_format($transaction['total_withdrawals'], 2); ?></td>
                                        <td>$<?php echo number_format($transaction['total_transfers'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($report_type === 'customer'): ?>
                <div class="report-section">
                    <h2>Customer Activity Report</h2>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Total Transactions</th>
                                    <th>Total Deposits</th>
                                    <th>Total Withdrawals</th>
                                    <th>Total Loans</th>
                                    <th>Total Loan Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customer_activity as $customer): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></td>
                                        <td><?php echo $customer['total_transactions']; ?></td>
                                        <td>$<?php echo number_format($customer['total_deposits'], 2); ?></td>
                                        <td>$<?php echo number_format($customer['total_withdrawals'], 2); ?></td>
                                        <td><?php echo $customer['total_loans']; ?></td>
                                        <td>$<?php echo number_format($customer['total_loan_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($report_type === 'loan'): ?>
                <div class="report-section">
                    <h2>Loan Performance Report</h2>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Total Loans</th>
                                    <th>Total Amount</th>
                                    <th>Average Amount</th>
                                    <th>Min Amount</th>
                                    <th>Max Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($loan_performance as $loan): ?>
                                    <tr>
                                        <td><?php echo ucfirst($loan['status']); ?></td>
                                        <td><?php echo $loan['total_loans']; ?></td>
                                        <td>$<?php echo number_format($loan['total_amount'], 2); ?></td>
                                        <td>$<?php echo number_format($loan['average_amount'], 2); ?></td>
                                        <td>$<?php echo number_format($loan['min_amount'], 2); ?></td>
                                        <td>$<?php echo number_format($loan['max_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($report_type === 'employee'): ?>
                <div class="report-section">
                    <h2>Employee Performance Report</h2>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Total Transactions</th>
                                    <th>Total Deposits</th>
                                    <th>Total Withdrawals</th>
                                    <th>Total Transfers</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employee_performance as $employee): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></td>
                                        <td><?php echo $employee['total_transactions']; ?></td>
                                        <td>$<?php echo number_format($employee['total_deposits'], 2); ?></td>
                                        <td>$<?php echo number_format($employee['total_withdrawals'], 2); ?></td>
                                        <td>$<?php echo number_format($employee['total_transfers'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function updateReport() {
            const reportType = document.getElementById('reportType').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            
            window.location.href = `reports.php?type=${reportType}&date_from=${dateFrom}&date_to=${dateTo}`;
        }
    </script>
</body>
</html> 