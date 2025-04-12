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

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Get loan statistics with caching
$stats = getCachedData('loan_stats', 300); // Cache for 5 minutes
if ($stats === false) {
    $stats_query = "SELECT 
                    COUNT(*) as total_loans,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_loans,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_loans,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_loans,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as total_approved_amount,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as total_pending_amount
                    FROM loans";
    $stats = $pdo->query($stats_query)->fetch();
    cacheData('loan_stats', $stats, 300);
}

// Get recent loans with caching
$recent_loans = getCachedData('recent_loans', 60); // Cache for 1 minute
if ($recent_loans === false) {
    $recent_query = "SELECT l.*, 
                    c.first_name, c.last_name, c.email, c.phone,
                    a.account_number
                    FROM loans l 
                    JOIN customers c ON l.customer_id = c.id 
                    LEFT JOIN accounts a ON l.account_id = a.id 
                    ORDER BY l.created_at DESC 
                    LIMIT 10";
    $recent_loans = $pdo->query($recent_query)->fetchAll();
    cacheData('recent_loans', $recent_loans, 60);
}

// Get high-value loans with caching
$high_value_loans = getCachedData('high_value_loans', 300); // Cache for 5 minutes
if ($high_value_loans === false) {
    $high_value_query = "SELECT l.*, 
                        c.first_name, c.last_name, c.email, c.phone,
                        a.account_number
                        FROM loans l 
                        JOIN customers c ON l.customer_id = c.id 
                        LEFT JOIN accounts a ON l.account_id = a.id 
                        WHERE l.amount > 50000 
                        ORDER BY l.amount DESC 
                        LIMIT 10";
    $high_value_loans = $pdo->query($high_value_query)->fetchAll();
    cacheData('high_value_loans', $high_value_loans, 300);
}

// Get pending loans with caching
$loans_cache_key = 'pending_loans_' . $_SESSION['user_id'];
$pending_loans = getCachedData($loans_cache_key);

if (!$pending_loans) {
    try {
        $stmt = $pdo->query("
            SELECT l.*, a.account_number, c.first_name, c.last_name,
                   c.national_id, c.annual_income
            FROM loans l
            JOIN accounts a ON l.account_id = a.id
            JOIN customers c ON a.customer_id = c.id
            WHERE l.status = 'pending'
            ORDER BY l.created_at DESC
            LIMIT 5
        ");
        $pending_loans = $stmt->fetchAll();
        cacheData($loans_cache_key, $pending_loans, 300);
    } catch (PDOException $e) {
        error_log("Error fetching pending loans: " . $e->getMessage());
        $pending_loans = [];
    }
}

// Build the base query for all loans
$loans_query = "SELECT l.*, 
                c.first_name, c.last_name, c.email, c.phone,
                a.account_number,
                l.term_months as loan_term
                FROM loans l 
                JOIN customers c ON l.customer_id = c.id 
                LEFT JOIN accounts a ON l.account_id = a.id 
                WHERE 1=1";

$params = [];

// Add filters
if ($status) {
    $loans_query .= " AND l.status = ?";
    $params[] = $status;
}
if ($date_from) {
    $loans_query .= " AND l.created_at >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $loans_query .= " AND l.created_at <= ?";
    $params[] = $date_to;
}

$loans_query .= " ORDER BY l.created_at DESC";

// Execute the query with parameters
$stmt = $pdo->prepare($loans_query);
$stmt->execute($params);
$loans = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Loans - Bank Management System</title>
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

        .loan-stats {
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

        .status-pending {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-rejected {
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

            .filter-container {
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
                    <h1>Manage Loans</h1>
                    <p>View and manage all loan applications</p>
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

            <div class="loan-stats">
                <div class="stat-card">
                    <h3>Total Loans</h3>
                    <p class="value"><?php echo $stats['total_loans']; ?></p>
                </div>
                <div class="stat-card">
                    <h3>Approved Loans</h3>
                    <p class="value"><?php echo $stats['approved_loans']; ?></p>
                </div>
                <div class="stat-card">
                    <h3>Pending Loans</h3>
                    <p class="value"><?php echo $stats['pending_loans']; ?></p>
                </div>
                <div class="stat-card">
                    <h3>Rejected Loans</h3>
                    <p class="value"><?php echo $stats['rejected_loans']; ?></p>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Approved Amount</h3>
                        <p>$<?php echo number_format($stats['total_approved_amount'] ?? 0, 2); ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Pending Amount</h3>
                        <p>$<?php echo number_format($stats['total_pending_amount'] ?? 0, 2); ?></p>
                    </div>
                </div>
            </div>

            <div class="filter-container">
                <select class="filter-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
                <input type="date" class="date-input" id="dateFrom" value="<?php echo $date_from; ?>" placeholder="From Date">
                <input type="date" class="date-input" id="dateTo" value="<?php echo $date_to; ?>" placeholder="To Date">
            </div>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Loan ID</th>
                            <th>Customer</th>
                            <th>Account</th>
                            <th>Amount</th>
                            <th>Interest Rate</th>
                            <th>Term</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($loan['id']); ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <img src="<?php echo $loan['profile_photo'] ? '../assets/uploads/profiles/' . $loan['profile_photo'] : '../assets/images/default-avatar.png'; ?>" 
                                             alt="Profile" class="customer-photo">
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></div>
                                            <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                                <?php echo htmlspecialchars($loan['email']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($loan['account_number']); ?></td>
                                <td style="font-weight: 600;">$<?php echo number_format($loan['amount'], 2); ?></td>
                                <td><?php echo $loan['interest_rate']; ?>%</td>
                                <td><?php echo isset($loan['loan_term']) ? $loan['loan_term'] : 'N/A'; ?> months</td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    $status_icon = '';
                                    switch ($loan['status']) {
                                        case 'approved':
                                            $status_class = 'status-approved';
                                            $status_icon = 'check-circle';
                                            break;
                                        case 'pending':
                                            $status_class = 'status-pending';
                                            $status_icon = 'clock';
                                            break;
                                        case 'rejected':
                                            $status_class = 'status-rejected';
                                            $status_icon = 'times-circle';
                                            break;
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <i class="fas fa-<?php echo $status_icon; ?>"></i>
                                        <?php echo ucfirst($loan['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($loan['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view_loan.php?id=<?php echo $loan['id']; ?>" class="btn btn-success">
                                            <i class="fas fa-eye"></i>
                                            View
                                        </a>
                                        <?php if ($loan['status'] === 'pending'): ?>
                                            <a href="approve_loan.php?id=<?php echo $loan['id']; ?>" class="btn btn-primary">
                                                <i class="fas fa-check"></i>
                                                Approve
                                            </a>
                                            <a href="reject_loan.php?id=<?php echo $loan['id']; ?>" class="btn btn-danger">
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
        // Status filter
        document.getElementById('statusFilter').addEventListener('change', function() {
            updateFilters();
        });

        // Date filters
        document.getElementById('dateFrom').addEventListener('change', function() {
            updateFilters();
        });

        document.getElementById('dateTo').addEventListener('change', function() {
            updateFilters();
        });

        function updateFilters() {
            const status = document.getElementById('statusFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;

            let url = 'loans.php?';
            if (status) url += `status=${status}&`;
            if (dateFrom) url += `date_from=${dateFrom}&`;
            if (dateTo) url += `date_to=${dateTo}`;

            window.location.href = url;
        }
    </script>
</body>
</html> 