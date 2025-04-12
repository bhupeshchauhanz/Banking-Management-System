<?php
session_start();
require_once '../config/database.php';
require_once '../includes/dashboard_layout.php';
require_once '../includes/cache_functions.php';

// Enable output buffering for better performance
ob_start();

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

// Initialize variables
$employee = null;
$stats = [
    'total_customers' => 0,
    'total_accounts' => 0,
    'total_loans' => 0,
    'pending_loans' => 0,
    'total_transactions' => 0,
    'pending_transactions' => 0
];
$recent_activity = [];

try {
    // Fetch employee data
    $stmt = $pdo->prepare("
        SELECT e.*, u.username, u.profile_photo
        FROM staff e
        JOIN users u ON e.user_id = u.id
        WHERE u.id = ?
    ");
    
    $stmt->execute([$_SESSION['user_id']]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        error_log("No employee data found for user_id: " . $_SESSION['user_id']);
    }

    // Get total customers
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM customers");
    $result = $stmt->fetch();
    $stats['total_customers'] = $result ? intval($result['count']) : 0;

    // Get total accounts
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM accounts");
    $result = $stmt->fetch();
    $stats['total_accounts'] = $result ? intval($result['count']) : 0;

    // Get loan statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending
        FROM loans
    ");
    $result = $stmt->fetch();
    $stats['total_loans'] = $result ? intval($result['total']) : 0;
    $stats['pending_loans'] = $result ? intval($result['pending']) : 0;

    // Get transaction statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending
        FROM transactions
    ");
    $result = $stmt->fetch();
    $stats['total_transactions'] = $result ? intval($result['total']) : 0;
    $stats['pending_transactions'] = $result ? intval($result['pending']) : 0;
    
    // Get recent activity
    $stmt = $pdo->query("
        SELECT 
            t.*,
            a.account_number,
            c.first_name,
            c.last_name
        FROM transactions t
        JOIN accounts a ON t.account_id = a.id
        JOIN customers c ON a.customer_id = c.id
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    $recent_activity = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Database error in employee dashboard: " . $e->getMessage());
    // Don't die, just continue with default values
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
    <title>Employee Dashboard - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
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
            margin-bottom: 30px;
        }
        
        .profile-photo {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-light);
        }
        
        .profile-photo-initial {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            border: 2px solid var(--primary-light);
        }
        
        .profile-info h3 {
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
            flex: 1;
            padding: 0 30px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.2) transparent;
        }
        
        .sidebar-menu::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar-menu::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .sidebar-menu::-webkit-scrollbar-thumb {
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: var(--transition);
            border-radius: var(--radius-sm);
            margin-bottom: 5px;
        }
        
        .menu-item:hover, .menu-item.active {
            color: white;
            background: rgba(255,255,255,0.1);
        }
        
        .menu-item i {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }
        
        .sidebar-footer {
            padding: 20px 30px;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: auto;
        }
        
        .logout-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--danger);
            text-decoration: none;
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            background: rgba(247, 37, 133, 0.1);
            transition: var(--transition);
        }
        
        .logout-btn:hover {
            background: var(--danger);
            color: white;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .dashboard-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
            margin-bottom: 10px;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        .current-time {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            background: var(--card-bg);
            color: var(--text-primary);
            box-shadow: var(--shadow-sm);
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .stat-info h3 {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }
        
        .stat-info p {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .recent-activity {
            background: var(--card-bg);
            padding: 30px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
        }
        
        .recent-activity h2 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-primary);
        }
        
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
        }
        
        .activity-item:hover {
            background: rgba(67, 97, 238, 0.05);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(67, 97, 238, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-details h4 {
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 5px;
            color: var(--text-primary);
        }
        
        .account-number {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .activity-details p {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }
        
        .activity-time {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        
        .activity-amount {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .text-success { color: var(--success); }
        .text-danger { color: var(--danger); }
        .text-primary { color: var(--primary-color); }
        .text-info { color: var(--accent-color); }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-university"></i>
                <h1>SecureBank</h1>
            </div>
        </div>
        
        <div class="profile-section">
            <?php if (isset($employee) && !empty($employee['profile_photo'])): ?>
                <img src="../uploads/profile_photos/<?php echo htmlspecialchars($employee['profile_photo']); ?>" 
                     alt="Profile Photo" 
                     class="profile-photo">
            <?php else: ?>
                <div class="profile-photo-initial">
                    <?php 
                        $initial = 'E';
                        if (!empty($employee['first_name'])) {
                            $initial = strtoupper(substr($employee['first_name'], 0, 1));
                        }
                        echo htmlspecialchars($initial);
                    ?>
                </div>
            <?php endif; ?>
            <div class="profile-info">
                <h3>
                    <?php 
                        $name = '';
                        if (!empty($employee['first_name']) || !empty($employee['last_name'])) {
                            if (!empty($employee['first_name'])) {
                                $name .= htmlspecialchars(trim($employee['first_name']));
                            }
                            if (!empty($employee['last_name'])) {
                                $name .= ' ' . htmlspecialchars(trim($employee['last_name']));
                            }
                            echo trim($name);
                        } else {
                            echo 'Employee';
                        }
                    ?>
                </h3>
                <p>
                    <?php 
                        if (!empty($employee['designation'])) {
                            echo htmlspecialchars($employee['designation']);
                        } elseif (!empty($employee['department'])) {
                            echo htmlspecialchars($employee['department']);
                        } else {
                            echo 'Staff Member';
                        }
                    ?>
                </p>
            </div>
        </div>

        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item active">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="customers.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>Customers</span>
            </a>
            <a href="loans.php" class="menu-item">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Loan Applications</span>
            </a>
            <a href="transactions.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Transactions</span>
            </a>
            <a href="edit_profile.php" class="menu-item">
                <i class="fas fa-user-edit"></i>
                <span>Edit Profile</span>
            </a>
            <a href="change_password.php" class="menu-item">
                <i class="fas fa-key"></i>
                <span>Change Password</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="../auth/logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
    
    <div class="main-content">
        <div class="dashboard-header">
            <h1>Welcome, <?php 
                if (!empty($employee['first_name']) && !empty($employee['last_name'])) {
                    echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']);
                } elseif (!empty($employee['first_name'])) {
                    echo htmlspecialchars($employee['first_name']);
                } elseif (!empty($employee['last_name'])) {
                    echo htmlspecialchars($employee['last_name']);
                } else {
                    echo 'Employee';
                }
            ?></h1>
            <div class="header-actions">
                <span class="current-time" id="current-time"></span>
        </div>
        </div>
        
        <div class="dashboard-grid">
            <!-- Stats Cards -->
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Customers</h3>
                    <p><?php echo number_format((int)$stats['total_customers']); ?></p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-piggy-bank"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Accounts</h3>
                    <p><?php echo number_format((int)$stats['total_accounts']); ?></p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Loans</h3>
                    <p><?php echo number_format((int)$stats['total_loans']); ?></p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3>Pending Loans</h3>
                    <p><?php echo number_format((int)$stats['pending_loans']); ?></p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Transactions</h3>
                    <p><?php echo number_format((int)$stats['total_transactions']); ?></p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="stat-info">
                    <h3>Pending Transactions</h3>
                    <p><?php echo number_format((int)$stats['pending_transactions']); ?></p>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="recent-activity">
            <h2>Recent Transactions</h2>
            <div class="activity-list">
                <?php if (!empty($recent_activity)): ?>
                    <?php foreach ($recent_activity as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <?php
                                switch ($activity['type']) {
                                    case 'deposit':
                                        echo '<i class="fas fa-arrow-down text-success"></i>';
                                        break;
                                    case 'withdrawal':
                                        echo '<i class="fas fa-arrow-up text-danger"></i>';
                                        break;
                                    case 'transfer':
                                        echo '<i class="fas fa-exchange-alt text-primary"></i>';
                                        break;
                                    default:
                                        echo '<i class="fas fa-circle text-info"></i>';
                                }
                                ?>
                            </div>
                            <div class="activity-details">
                                <h4>
                                    <?php 
                                        echo isset($activity['first_name']) && isset($activity['last_name'])
                                            ? htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name'])
                                            : 'Unknown Customer';
                                    ?>
                                    <span class="account-number">
                                        <?php echo isset($activity['account_number']) ? '(' . htmlspecialchars($activity['account_number']) . ')' : ''; ?>
                                    </span>
                                </h4>
                                <p><?php echo isset($activity['description']) ? htmlspecialchars($activity['description']) : 'No description available'; ?></p>
                                <span class="activity-time">
                                    <?php echo isset($activity['created_at']) ? date('M d, Y H:i', strtotime($activity['created_at'])) : 'Unknown time'; ?>
                                </span>
                            </div>
                            <div class="activity-amount">
                                <?php echo isset($activity['amount']) ? number_format($activity['amount'], 2) : '0.00'; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="no-activity">No recent transactions found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Update current time
        function updateCurrentTime() {
            const now = new Date();
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = now.toLocaleString();
            }
        }
        
        // Update time every second
        setInterval(updateCurrentTime, 1000);
        updateCurrentTime();
    </script>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?>