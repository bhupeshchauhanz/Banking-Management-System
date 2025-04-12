<?php
session_start();
require_once '../config/database.php';
require_once '../includes/dashboard_layout.php';
require_once '../includes/cache_functions.php';

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
try {
    $stmt = $pdo->prepare("SELECT s.*, u.username, u.profile_photo 
                          FROM staff s 
                          JOIN users u ON s.user_id = u.id 
                          WHERE u.id = ? AND u.role = 'manager' AND u.is_active = 1");
    $stmt->execute([$manager_id]);
    $manager = $stmt->fetch();

    if (!$manager) {
        header('Location: ../auth/logout.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching manager details: " . $e->getMessage());
    die("Error loading manager information. Please try again later.");
}

// Handle account deletion with transaction
if (isset($_POST['delete_account']) && isset($_POST['account_id'])) {
    $account_id = filter_var($_POST['account_id'], FILTER_VALIDATE_INT);
    
    if ($account_id === false) {
        $_SESSION['error'] = "Invalid account ID.";
        header('Location: accounts.php');
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get account details for audit log
        $stmt = $pdo->prepare("SELECT a.*, c.first_name, c.last_name 
                             FROM accounts a 
                             JOIN customers c ON a.customer_id = c.id 
                             WHERE a.id = ?");
        $stmt->execute([$account_id]);
        $account = $stmt->fetch();
        
        if ($account) {
            // Check if account has any pending transactions
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE account_id = ? AND status = 'pending'");
            $stmt->execute([$account_id]);
            $pending_transactions = $stmt->fetchColumn();
            
            if ($pending_transactions > 0) {
                throw new Exception("Cannot delete account with pending transactions.");
            }
            
            // Delete from accounts table
            $stmt = $pdo->prepare("DELETE FROM accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            
            // Log the action
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, details) VALUES (?, 'delete_account', ?)");
            $stmt->execute([$manager_id, "Deleted account: {$account['account_number']} for customer {$account['first_name']} {$account['last_name']}"]);
            
            $pdo->commit();
            $_SESSION['success'] = "Account deleted successfully.";
        } else {
            $_SESSION['error'] = "Account not found.";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deleting account: " . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
    }
    
    header('Location: accounts.php');
    exit();
}

// Get all accounts with optimized query and caching
$accounts = getCachedData('all_accounts', 300); // Cache for 5 minutes
if ($accounts === false) {
    try {
        $accounts_query = "SELECT a.*, c.first_name, c.last_name, c.email, c.phone,
                          u.username, u.profile_photo,
                          (SELECT COUNT(*) FROM transactions WHERE account_id = a.id) as total_transactions,
                          (SELECT COUNT(*) FROM transactions WHERE account_id = a.id AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as recent_transactions
                          FROM accounts a 
                          JOIN customers c ON a.customer_id = c.id 
                          JOIN users u ON c.user_id = u.id
                          WHERE u.role = 'customer'
                          ORDER BY a.created_at DESC";
        $accounts = $pdo->query($accounts_query)->fetchAll();
        cacheData('all_accounts', $accounts, 300);
    } catch (PDOException $e) {
        error_log("Error fetching accounts: " . $e->getMessage());
        $accounts = [];
    }
}

// Get account statistics with caching
$stats = getCachedData('account_stats', 300); // Cache for 5 minutes
if ($stats === false) {
    try {
        $stats_query = "SELECT 
                        COUNT(*) as total_accounts,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_accounts,
                        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_accounts,
                        SUM(CASE WHEN account_type = 'savings' THEN 1 ELSE 0 END) as savings_accounts,
                        SUM(CASE WHEN account_type = 'checking' THEN 1 ELSE 0 END) as checking_accounts,
                        COALESCE(SUM(balance), 0) as total_balance
                        FROM accounts";
        $stats = $pdo->query($stats_query)->fetch();
        cacheData('account_stats', $stats, 300);
    } catch (PDOException $e) {
        error_log("Error fetching account statistics: " . $e->getMessage());
        $stats = [
            'total_accounts' => 0,
            'active_accounts' => 0,
            'inactive_accounts' => 0,
            'savings_accounts' => 0,
            'checking_accounts' => 0,
            'total_balance' => 0
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accounts - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Include the same CSS variables and styles from dashboard.php */
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
            box-shadow: var(--shadow-md);
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
            margin-bottom: 20px;
        }
        
        .sidebar-logo i {
            font-size: 2rem;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .sidebar-logo h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
        }
        
        .profile-section {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 0 30px;
            margin-bottom: 20px;
        }
        
        .profile-photo-container {
            width: 80px;
            height: 80px;
            min-width: 80px;
            min-height: 80px;
            border-radius: 50%;
            overflow: hidden;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            border: 3px solid var(--primary-color);
            flex-shrink: 0;
        }
        
        .profile-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        
        .profile-photo-initial {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-color);
            color: white;
            font-size: 2rem;
            font-weight: bold;
        }
        
        .profile-info h2 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: white;
        }
        
        .profile-info p {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        .sidebar-menu {
            margin-top: 30px;
            flex: 1;
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .sidebar-menu::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar-menu::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
        }
        
        .sidebar-menu::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 3px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 30px;
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
            position: relative;
            border-radius: var(--radius-sm);
        }
        
        .menu-item:hover, .menu-item.active {
            color: white;
            background: rgba(255,255,255,0.1);
        }
        
        .menu-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--primary-color);
        }
        
        .menu-item i {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }
        
        .menu-item span {
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        .sidebar-footer {
            padding: 30px;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: auto;
        }
        
        .logout-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #dc3545;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            background: rgba(220, 53, 69, 0.1);
            transition: var(--transition);
            border: 1px solid rgba(220, 53, 69, 0.2);
            width: 100%;
            justify-content: center;
        }
        
        .logout-btn:hover {
            background: #dc3545;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
        }
        
        .logout-btn i {
            font-size: 1.1rem;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
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
            .sidebar {
                transform: translateX(-100%);
                transition: var(--transition);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Account Statistics */
        .account-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--radius-md);
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
        
        /* Table Styles */
        .table-container {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 500;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table tr:last-child td {
            border-bottom: none;
        }
        
        .table tr:hover {
            background: rgba(67, 97, 238, 0.02);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            cursor: pointer;
            border: none;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #d90429;
        }
        
        /* Status Badges */
        .status-badge {
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
        
        .header-actions {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
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
            <div class="profile-photo-container">
                <?php if (!empty($manager['profile_photo'])): ?>
                    <img src="../uploads/profile_photos/<?php echo htmlspecialchars($manager['profile_photo']); ?>" 
                         alt="Profile Photo" 
                         class="profile-photo"
                         onerror="this.onerror=null; this.src='../assets/images/default-avatar.png';">
                <?php else: ?>
                    <div class="profile-photo-initial">
                        <?php echo strtoupper(substr($manager['first_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="profile-info">
                <h2><?php echo htmlspecialchars($manager['first_name'] . ' ' . $manager['last_name']); ?></h2>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <a href="dashboard.php" class="menu-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="employees.php" class="menu-item">
                <i class="fas fa-user-tie"></i>
                <span>Employees</span>
            </a>
            <a href="customers.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>Customers</span>
            </a>
            <a href="accounts.php" class="menu-item active">
                <i class="fas fa-wallet"></i>
                <span>Accounts</span>
            </a>
            <a href="loans.php" class="menu-item">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Loans</span>
            </a>
            <a href="transactions.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Transactions</span>
            </a>
            <a href="reports.php" class="menu-item">
                <i class="fas fa-chart-bar"></i>
                <span>Reports</span>
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
        <div class="page-header">
            <h1 class="page-title">Manage Accounts</h1>
            <div class="header-actions">
                <a href="create_account.php" class="header-btn">
                    <i class="fas fa-plus-circle"></i> Create Account
                </a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php 
                echo htmlspecialchars($_SESSION['error']);
                unset($_SESSION['error']);
            ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php 
                echo htmlspecialchars($_SESSION['success']);
                unset($_SESSION['success']);
            ?>
        </div>
        <?php endif; ?>

        <!-- Account Statistics -->
        <div class="account-stats">
            <div class="stat-card">
                <h3>Total Accounts</h3>
                <p class="value"><?php echo isset($stats['total_accounts']) ? $stats['total_accounts'] : 0; ?></p>
            </div>
            <div class="stat-card">
                <h3>Active Accounts</h3>
                <p class="value"><?php echo isset($stats['active_accounts']) ? $stats['active_accounts'] : 0; ?></p>
            </div>
            <div class="stat-card">
                <h3>Total Balance</h3>
                <p class="value">$<?php echo isset($stats['total_balance']) ? number_format($stats['total_balance'], 2) : '0.00'; ?></p>
            </div>
            <div class="stat-card">
                <h3>Savings Accounts</h3>
                <p class="value"><?php echo isset($stats['savings_accounts']) ? $stats['savings_accounts'] : 0; ?></p>
            </div>
        </div>

        <!-- Accounts Table -->
        <div class="table-container">
            <div class="table-header">
                <h2 class="table-title">Account List</h2>
                <div class="table-actions">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search accounts...">
                </div>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Account Number</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($account['account_number']); ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php if (!empty($account['profile_photo'])): ?>
                                    <img src="../uploads/profile_photos/<?php echo htmlspecialchars($account['profile_photo']); ?>" 
                                         alt="Profile" class="customer-photo">
                                <?php else: ?>
                                    <div class="customer-photo">
                                        <?php echo strtoupper(substr($account['first_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></div>
                                    <div class="text-muted"><?php echo htmlspecialchars($account['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars(ucfirst($account['account_type'])); ?></td>
                        <td>$<?php echo number_format($account['balance'], 2); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $account['status']; ?>">
                                <?php echo ucfirst($account['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($account['created_at'])); ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="view_account.php?id=<?php echo $account['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="edit_account.php?id=<?php echo $account['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this account?');">
                                    <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                    <button type="submit" name="delete_account" class="btn btn-danger">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
    </script>
</body>
</html> 