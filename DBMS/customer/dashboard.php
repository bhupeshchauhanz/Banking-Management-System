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

// Get customer information
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.username, u.profile_photo, u.last_login,
               COUNT(a.id) as total_accounts,
               COALESCE(SUM(a.balance), 0) as total_balance,
               SUM(CASE WHEN a.status = 'active' THEN 1 ELSE 0 END) as active_accounts
        FROM users u
        LEFT JOIN customers c ON c.user_id = u.id
        LEFT JOIN accounts a ON a.customer_id = c.id
        WHERE u.id = ? AND u.role = 'customer'
        GROUP BY c.id, u.id
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        logError("Customer not found for user_id: " . $_SESSION['user_id']);
        die("Customer account not found. Please contact support.");
    }
} catch (PDOException $e) {
    handleDatabaseError($e);
}

// Get recent transactions
try {
    $transactions_stmt = $pdo->prepare("
        SELECT t.*, a.account_number,
               CASE 
                   WHEN t.type = 'deposit' THEN 'money-bill-wave'
                   WHEN t.type = 'withdrawal' THEN 'money-bill-wave'
                   WHEN t.type = 'transfer' THEN 'exchange-alt'
                   WHEN t.type = 'loan' THEN 'hand-holding-usd'
                   ELSE 'info-circle'
               END as icon,
               CONCAT(
                   CASE 
                       WHEN t.type = 'deposit' THEN 'Deposit'
                       WHEN t.type = 'withdrawal' THEN 'Withdrawal'
                       WHEN t.type = 'transfer' THEN 'Transfer'
                       WHEN t.type = 'loan' THEN 'Loan'
                       ELSE 'Transaction'
                   END,
                   ' of $',
                   FORMAT(t.amount, 2),
                   CASE 
                       WHEN t.type = 'transfer' THEN CONCAT(' to account ', t.recipient_account_id)
                       ELSE ''
                   END
               ) as title,
               DATE_FORMAT(t.created_at, '%b %d, %Y %h:%i %p') as time
        FROM transactions t
        JOIN accounts a ON t.account_id = a.id
        WHERE a.customer_id = ?
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $transactions_stmt->execute([$customer['id']]);
    $recent_transactions = $transactions_stmt->fetchAll();
} catch (PDOException $e) {
    logError("Error fetching recent transactions", $e);
    $recent_transactions = [];
}

// Get active loans
try {
    $loans_stmt = $pdo->prepare("
        SELECT l.*, a.account_number,
               DATE_FORMAT(l.created_at, '%b %d, %Y') as loan_date,
               DATE_FORMAT(l.due_date, '%b %d, %Y') as due_date,
               FORMAT(l.amount, 2) as formatted_amount,
               FORMAT(l.remaining_amount, 2) as formatted_remaining
        FROM loans l
        JOIN accounts a ON l.account_id = a.id
        WHERE a.customer_id = ? AND l.status IN ('active', 'pending')
        ORDER BY l.created_at DESC
        LIMIT 5
    ");
    $loans_stmt->execute([$customer['id']]);
    $active_loans = $loans_stmt->fetchAll();
} catch (PDOException $e) {
    logError("Error fetching active loans", $e);
    $active_loans = [];
}

// Get dashboard stats with caching
$stats_cache_key = 'customer_stats_' . $_SESSION['user_id'];
$stats = getCachedData($stats_cache_key);

if (!$stats) {
    try {
        $stats = [
            'recent_transactions' => $recent_transactions,
            'active_loans' => $active_loans
        ];
        
        cacheData($stats_cache_key, $stats, 300);
    } catch (PDOException $e) {
        error_log("Error fetching dashboard stats: " . $e->getMessage());
        $stats = [
            'recent_transactions' => [],
            'active_loans' => []
        ];
    }
}

// Get recent activity with caching
$activity_cache_key = 'customer_activity_' . $_SESSION['user_id'];
$activity = getCachedData($activity_cache_key);

if (!$activity) {
    try {
        // Get recent activity directly
        $activity_stmt = $pdo->prepare("
            SELECT 
                t.id,
                t.type,
                t.amount,
                t.status,
                t.created_at,
                a.account_number,
                CASE 
                    WHEN t.type = 'deposit' THEN 'money-bill-wave'
                    WHEN t.type = 'withdrawal' THEN 'money-bill-wave'
                    WHEN t.type = 'transfer' THEN 'exchange-alt'
                    WHEN t.type = 'loan' THEN 'hand-holding-usd'
                    ELSE 'info-circle'
                END as icon,
                CONCAT(
                    CASE 
                        WHEN t.type = 'deposit' THEN 'Deposit'
                        WHEN t.type = 'withdrawal' THEN 'Withdrawal'
                        WHEN t.type = 'transfer' THEN 'Transfer'
                        WHEN t.type = 'loan' THEN 'Loan'
                        ELSE 'Transaction'
                    END,
                    ' of $',
                    FORMAT(t.amount, 2),
                    CASE 
                        WHEN t.type = 'transfer' THEN CONCAT(' to account ', t.recipient_account_id)
                        ELSE ''
                    END
                ) as title,
                DATE_FORMAT(t.created_at, '%b %d, %Y %h:%i %p') as time
            FROM transactions t
            JOIN accounts a ON t.account_id = a.id
            WHERE a.customer_id = ?
            ORDER BY t.created_at DESC
            LIMIT 5
        ");
        $activity_stmt->execute([$customer['id']]);
        $activity = $activity_stmt->fetchAll();
        
        cacheData($activity_cache_key, $activity, 300);
    } catch (PDOException $e) {
        error_log("Error fetching recent activity: " . $e->getMessage());
        $activity = [];
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
    <title>Customer Dashboard - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--dark-bg);
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding: 30px 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }
        
        .sidebar-header {
            padding: 0 30px 30px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 30px;
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .sidebar-menu {
            flex: 1;
            overflow-y: auto;
            padding-right: 17px;
            margin-right: -17px;
        }

        .sidebar-menu::-webkit-scrollbar {
            width: 0;
            background: transparent;
        }

        .sidebar-menu {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        /* Logout Button Styles */
        .sidebar-footer {
            padding: 20px 30px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #fff;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            background: rgba(239, 68, 68, 0.15);
            transition: var(--transition);
            width: 100%;
        }

        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.25);
        }

        .logout-btn i {
            font-size: 1.1rem;
            color: rgba(239, 68, 68, 0.9);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
            overflow-y: auto;
        }

        /* Modern Dashboard Styles */
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .stat-card h3 {
            color: #666;
            font-size: 1rem;
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 600;
            color: #333;
        }

        .stat-card .icon {
            font-size: 2rem;
            color: #4a90e2;
            margin-bottom: 15px;
        }

        .activity-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .activity-section h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.5rem;
        }

        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .activity-item {
            padding: 15px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: #f0f7ff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4a90e2;
        }

        .activity-details {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: #333;
            margin-bottom: 5px;
        }

        .activity-time {
            color: #666;
            font-size: 0.9rem;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .action-button {
            background: white;
            border: none;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            text-decoration: none;
            color: #333;
        }

        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            background: #f0f7ff;
        }

        .action-button i {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #4a90e2;
        }

        .action-button span {
            display: block;
            font-weight: 500;
        }

        /* Profile Section Styles */
        .profile-section {
            padding: 20px 30px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-photo {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .profile-photo-initial {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: rgba(67, 97, 238, 0.3);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.2rem;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .profile-info {
            flex: 1;
        }

        .profile-info h2 {
            color: white;
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .profile-info p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
        }

        /* Sidebar Menu Styles */
        .menu-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            text-decoration: none;
            color: white;
            transition: background 0.3s ease;
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .menu-item.active {
            background: rgba(255, 255, 255, 0.2);
        }

        .menu-item i {
            font-size: 1.1rem;
        }

        .menu-item span {
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-university"></i>
                <h1>SecureBank</h1>
            </div>
        </div>
        
        <div class="profile-section">
            <?php if (!empty($customer['profile_photo'])): ?>
                <img src="../uploads/profile_photos/<?php echo htmlspecialchars($customer['profile_photo']); ?>" alt="Profile Photo" class="profile-photo">
            <?php else: ?>
                <div class="profile-photo-initial">
                    <?php echo strtoupper(substr($customer['first_name'] ?? 'U', 0, 1)); ?>
                </div>
            <?php endif; ?>
            
            <div class="profile-info">
                <h2><?php echo htmlspecialchars(($customer['first_name'] ?? 'Unknown') . ' ' . ($customer['last_name'] ?? 'User')); ?></h2>
                <p>Customer</p>
            </div>
        </div>

        <div class="sidebar-menu">
            <a href="dashboard.php" class="menu-item active">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="transfer.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Transfer Money</span>
            </a>
            <a href="transaction_history.php" class="menu-item">
                <i class="fas fa-history"></i>
                <span>Transaction History</span>
            </a>
            <a href="apply_loan.php" class="menu-item">
                <i class="fas fa-hand-holding-usd"></i>
                <span>Apply for Loan</span>
            </a>
            <a href="my_loans.php" class="menu-item">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>My Loans</span>
            </a>
            <a href="edit_profile.php" class="menu-item">
                <i class="fas fa-user-edit"></i>
                <span>Edit Profile</span>
            </a>
            <a href="change_password.php" class="menu-item">
                <i class="fas fa-key"></i>
                <span>Change Password</span>
            </a>
        </div>

        <div class="sidebar-footer">
            <a href="../auth/logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
    
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
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <h3>Total Balance</h3>
                    <div class="value">$<?php echo number_format((float)$customer['total_balance'], 2); ?></div>
                </div>
                <div class="stat-card">
                    <div class="icon">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <h3>Total Accounts</h3>
                    <div class="value"><?php echo $customer['total_accounts']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <h3>Recent Transactions</h3>
                    <div class="value"><?php echo count($activity); ?></div>
                </div>
            </div>

            <div class="activity-section">
                <h2>Recent Activity</h2>
                <ul class="activity-list">
                    <?php foreach ($activity as $item): ?>
                    <li class="activity-item">
                        <div class="activity-icon">
                            <i class="fas fa-<?php echo $item['icon']; ?>"></i>
                        </div>
                        <div class="activity-details">
                            <div class="activity-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="activity-time"><?php echo $item['time']; ?></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="quick-actions">
                <a href="transfer.php" class="action-button">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Transfer Money</span>
                </a>
                <a href="transaction_history.php" class="action-button">
                    <i class="fas fa-history"></i>
                    <span>Transaction History</span>
                </a>
                <a href="apply_loan.php" class="action-button">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Apply for Loan</span>
                </a>
                <a href="edit_profile.php" class="action-button">
                    <i class="fas fa-user-edit"></i>
                    <span>Edit Profile</span>
                </a>
            </div>
        </div>
    </div>

    <script>
        // Update current date and time
        function updateDateTime() {
            const now = new Date();
            const dateTimeElement = document.getElementById('current-datetime');
            if (dateTimeElement) {
                dateTimeElement.textContent = now.toLocaleString();
            }
        }
        
        // Update every second
        setInterval(updateDateTime, 1000);
        updateDateTime();
        
        // Add smooth scrolling for anchor links
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    document.querySelector(this.getAttribute('href')).scrollIntoView({
                        behavior: 'smooth'
                    });
                });
            });
        });
    </script>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?>