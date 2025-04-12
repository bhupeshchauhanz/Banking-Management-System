<?php
session_start();
require_once '../config/database.php';
require_once '../includes/dashboard_layout.php';
require_once '../includes/cache_functions.php';

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Enable output buffering for better performance
ob_start();

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

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: ../index.php');
    exit();
}

// Get manager information
try {
    // Get manager details with stats
    $stmt = $pdo->prepare("
        SELECT 
            m.id, m.first_name, m.last_name, m.email, m.phone, m.address,
            u.username, u.profile_photo,
            (SELECT COUNT(*) FROM customers) as total_customers,
            (SELECT COUNT(*) FROM staff s JOIN users u ON s.user_id = u.id WHERE u.role = 'employee') as total_employees,
            (SELECT COUNT(*) FROM accounts) as total_accounts,
            (SELECT COUNT(*) FROM loans) as total_loans,
            (SELECT COUNT(*) FROM loans WHERE status = 'pending') as pending_loans,
            (SELECT COALESCE(SUM(amount), 0) FROM loans WHERE status = 'approved') as total_approved_amount,
            (SELECT COALESCE(SUM(amount), 0) FROM loans WHERE status = 'pending') as total_pending_amount,
            (SELECT COALESCE(SUM(balance), 0) FROM accounts) as total_assets,
            (SELECT COUNT(*) FROM transactions WHERE status = 'pending') as pending_transactions
        FROM staff m 
        JOIN users u ON m.user_id = u.id
        WHERE m.user_id = ? AND u.role = 'manager'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $manager = $stmt->fetch();
    
    if (!$manager) {
        // If manager record doesn't exist, create it
        $stmt = $pdo->prepare("
            SELECT id, username, profile_photo 
            FROM users 
            WHERE id = ? AND role = 'manager'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            header('Location: ../index.php');
            exit();
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO staff (user_id, first_name, last_name, email, phone, address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            'Manager', // Default first name
            'User',    // Default last name
            $user['username'] . '@bank.com', // Default email
            '',        // Empty phone
            ''        // Empty address
        ]);
        
        // Fetch the newly created manager record
        $stmt = $pdo->prepare("
            SELECT 
                m.id, m.first_name, m.last_name, m.email, m.phone, m.address,
                u.username, u.profile_photo,
                (SELECT COUNT(*) FROM customers) as total_customers,
                (SELECT COUNT(*) FROM staff s JOIN users u ON s.user_id = u.id WHERE u.role = 'employee') as total_employees,
                (SELECT COUNT(*) FROM accounts) as total_accounts,
                (SELECT COUNT(*) FROM loans) as total_loans,
                (SELECT COUNT(*) FROM loans WHERE status = 'pending') as pending_loans,
                (SELECT COALESCE(SUM(amount), 0) FROM loans WHERE status = 'approved') as total_approved_amount,
                (SELECT COALESCE(SUM(amount), 0) FROM loans WHERE status = 'pending') as total_pending_amount,
                (SELECT COALESCE(SUM(balance), 0) FROM accounts) as total_assets,
                (SELECT COUNT(*) FROM transactions WHERE status = 'pending') as pending_transactions
            FROM staff m 
            JOIN users u ON m.user_id = u.id
            WHERE m.user_id = ? AND u.role = 'manager'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $manager = $stmt->fetch();
    }
} catch (PDOException $e) {
    error_log("Error fetching manager data: " . $e->getMessage());
    die("Error loading dashboard data. Please try again later.");
}

// Get recent transactions with caching
$transactions_cache_key = 'recent_transactions_' . $_SESSION['user_id'];
$recent_transactions = getCachedData($transactions_cache_key);

if (!$recent_transactions) {
    try {
        $stmt = $pdo->query("
            SELECT t.*, a.account_number, c.first_name, c.last_name,
                   (SELECT account_number FROM accounts WHERE id = t.recipient_account_id) as recipient_account
            FROM transactions t
            JOIN accounts a ON t.account_id = a.id
            JOIN customers c ON a.customer_id = c.id
            ORDER BY t.created_at DESC
            LIMIT 5
        ");
        $recent_transactions = $stmt->fetchAll();
        cacheData($transactions_cache_key, $recent_transactions, 300);
    } catch (PDOException $e) {
        error_log("Error fetching recent transactions: " . $e->getMessage());
        $recent_transactions = [];
    }
}

// Get recent loan applications with caching
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

// Get dashboard stats with caching
$stats_cache_key = 'manager_stats_' . $_SESSION['user_id'];
$stats = getCachedData($stats_cache_key);

if (!$stats) {
    $stats = [
        'total_customers' => $manager['total_customers'],
        'total_employees' => $manager['total_employees'],
        'total_assets' => $manager['total_assets'],
        'pending_loans' => $manager['pending_loans'],
        'pending_transactions' => $manager['pending_transactions']
    ];
    cacheData($stats_cache_key, $stats, 300);
}

// Get recent activity with caching
$activity_cache_key = 'manager_activity_' . $_SESSION['user_id'];
$activity = getCachedData($activity_cache_key);

if (!$activity) {
    $activity = getRecentActivity($pdo, 'manager', $manager['id']);
    cacheData($activity_cache_key, $activity, 300);
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
    <title>Manager Dashboard - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        }
        
        .profile-photo-container {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            border: 3px solid var(--primary-color);
        }
        
        .profile-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
        }
        
        .profile-info p {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        .sidebar-menu {
            margin-top: 30px;
            flex: 1;
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
        
        .sidebar-footer {
            padding: 30px;
            border-top: 1px solid rgba(255,255,255,0.1);
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
        
        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        /* Cards */
        .card {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-title i {
            font-size: 1.4rem;
            color: var(--primary-color);
        }
        
        .card-content {
            color: var(--text-secondary);
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
        }
        
        .stat-info p {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        
        /* Tables */
        .table-container {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 25px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .table-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .table-actions {
            display: flex;
            gap: 10px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 2px solid var(--border-color);
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
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
        
        .status-rejected {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            border-radius: 5px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            gap: 8px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.875rem;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #218838;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
            transform: translateY(-2px);
        }
        
        /* Form Controls */
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        .alert-error {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .alert-warning {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
            border-left: 4px solid var(--warning);
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
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .profile-photo-container {
                width: 60px;
                height: 60px;
            }
            
            .profile-photo-initial {
                font-size: 1.5rem;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .table-actions {
                flex-direction: column;
            }
        }
        
        /* Add custom styles for better performance */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
            scrollbar-width: thin;
        }
        
        /* Add loading animation */
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Quick action buttons */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            background: var(--primary-color);
            color: white;
        }
        
        .quick-action-btn:hover i {
            color: white;
        }
        
        .quick-action-btn i {
            font-size: 1.5rem;
            color: var(--primary-color);
            transition: color 0.3s ease;
        }
        
        /* Charts container */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-top: 30px;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .chart-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: 400px; /* Fixed height */
            width: 100%; /* Full width */
            position: relative; /* For absolute positioning of canvas */
        }
        
        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-primary);
        }
        
        .chart-card canvas {
            width: 100% !important;
            height: 100% !important;
            position: absolute;
            top: 0;
            left: 0;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .charts-container {
                grid-template-columns: 1fr;
            }
            
            .chart-card {
                height: 300px;
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
            <a href="dashboard.php" class="menu-item active">
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
            <a href="accounts.php" class="menu-item">
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
        <?php renderDashboardHeader($manager, 'manager'); ?>
        
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
        
        <div class="loading">
            <div class="loading-spinner"></div>
            <p>Loading dashboard data...</p>
        </div>
        
        <div class="dashboard-content">
            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="add_employee.php" class="quick-action-btn">
                    <i class="fas fa-user-plus"></i>
                    <span>Add Employee</span>
                </a>
                <a href="customers.php" class="quick-action-btn">
                    <i class="fas fa-user-friends"></i>
                    <span>Manage Customers</span>
                </a>
                <a href="loans.php" class="quick-action-btn">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Process Loans</span>
                </a>
                <a href="reports.php" class="quick-action-btn">
                    <i class="fas fa-chart-bar"></i>
                    <span>View Reports</span>
                </a>
            </div>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total_customers']); ?></h3>
                        <p>Total Customers</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total_employees']); ?></h3>
                        <p>Total Employees</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-info">
                        <h3>$<?php echo number_format($stats['total_assets'], 2); ?></h3>
                        <p>Total Assets</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['pending_loans']); ?></h3>
                        <p>Pending Loans</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['pending_transactions']); ?></h3>
                        <p>Pending Transactions</p>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="dashboard-grid">
                <!-- Recent Transactions -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-exchange-alt"></i>
                            Recent Transactions
                        </h2>
                        <a href="transactions.php" class="btn btn-sm btn-primary">View All</a>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></td>
                                    <td><?php echo ucfirst($transaction['type']); ?></td>
                                    <td>$<?php echo number_format($transaction['amount'], 2); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                            <?php echo ucfirst($transaction['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($transaction['created_at'])); ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="view_transaction.php?id=<?php echo $transaction['id']; ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i>
                                                View
                                            </a>
                                            <?php if ($transaction['status'] === 'pending'): ?>
                                            <a href="approve_transaction.php?id=<?php echo $transaction['id']; ?>" 
                                               class="btn btn-sm btn-success"
                                               onclick="return confirm('Are you sure you want to approve this transaction?')">
                                                <i class="fas fa-check"></i>
                                                Approve
                                            </a>
                                            <a href="reject_transaction.php?id=<?php echo $transaction['id']; ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('Are you sure you want to reject this transaction?')">
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
                
                <!-- Pending Loans -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-file-invoice-dollar"></i>
                            Pending Loans
                        </h2>
                        <a href="loans.php" class="btn btn-sm btn-primary">View All</a>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Term</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_loans as $loan): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?>
                                        <br>
                                        <small>ID: <?php echo htmlspecialchars($loan['national_id']); ?></small>
                                    </td>
                                    <td>$<?php echo number_format($loan['amount'], 2); ?></td>
                                    <td><?php echo $loan['term_months']; ?> months</td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="view_loan.php?id=<?php echo $loan['id']; ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i>
                                                View
                                            </a>
                                            <a href="approve_loan.php?id=<?php echo $loan['id']; ?>" 
                                               class="btn btn-sm btn-success"
                                               onclick="return confirm('Are you sure you want to approve this loan?')">
                                                <i class="fas fa-check"></i>
                                                Approve
                                            </a>
                                            <a href="reject_loan.php?id=<?php echo $loan['id']; ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('Are you sure you want to reject this loan?')">
                                                <i class="fas fa-times"></i>
                                                Reject
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Charts Section -->
            <div class="charts-container">
                <div class="chart-card">
                    <div class="chart-title">Monthly Transactions</div>
                    <canvas id="transactionsChart"></canvas>
                </div>
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
        
        // Show loading state when fetching data
        document.addEventListener('DOMContentLoaded', function() {
            const loading = document.querySelector('.loading');
            const content = document.querySelector('.dashboard-content');
            
            // Show loading initially
            loading.style.display = 'block';
            content.style.display = 'none';
            
            // Simulate loading (replace with actual data fetching)
            setTimeout(() => {
                loading.style.display = 'none';
                content.style.display = 'block';
                
                // Initialize charts after content is loaded
                initializeCharts();
            }, 500);
        });
        
        // Initialize charts with real data
        function initializeCharts() {
            // Fetch real transaction data
            fetch('get_chart_data.php')
                .then(response => response.json())
                .then(data => {
                    // Transactions Chart
                    const transactionsCtx = document.getElementById('transactionsChart').getContext('2d');
                    new Chart(transactionsCtx, {
                        type: 'bar',
                        data: {
                            labels: data.months,
                            datasets: [
                                {
                                    label: 'Deposits',
                                    data: data.deposits,
                                    backgroundColor: '#4cc9f0',
                                    borderColor: '#4cc9f0',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Withdrawals',
                                    data: data.withdrawals,
                                    backgroundColor: '#f72585',
                                    borderColor: '#f72585',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Transfers',
                                    data: data.transfers,
                                    backgroundColor: '#4361ee',
                                    borderColor: '#4361ee',
                                    borderWidth: 1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Monthly Transaction Volume',
                                    font: {
                                        size: 16,
                                        weight: 'bold'
                                    }
                                },
                                legend: {
                                    position: 'top',
                                    labels: {
                                        boxWidth: 12,
                                        padding: 20
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '$' + value.toLocaleString();
                                        }
                                    },
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.1)'
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });

                    // Account Types Distribution Chart
                    const accountsCtx = document.getElementById('accountsChart').getContext('2d');
                    new Chart(accountsCtx, {
                        type: 'doughnut',
                        data: {
                            labels: data.accountTypes,
                            datasets: [{
                                data: data.accountCounts,
                                backgroundColor: [
                                    '#4cc9f0',
                                    '#f72585',
                                    '#4361ee'
                                ],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Account Types Distribution',
                                    font: {
                                        size: 16,
                                        weight: 'bold'
                                    }
                                },
                                legend: {
                                    position: 'right',
                                    labels: {
                                        boxWidth: 12,
                                        padding: 20,
                                        generateLabels: function(chart) {
                                            const data = chart.data;
                                            if (data.labels.length && data.datasets.length) {
                                                return data.labels.map((label, i) => {
                                                    const value = data.datasets[0].data[i];
                                                    const total = data.datasets[0].data.reduce((a, b) => a + b, 0);
                                                    const percentage = Math.round((value / total) * 100);
                                                    
                                                    return {
                                                        text: `${label}: ${percentage}%`,
                                                        fillStyle: data.datasets[0].backgroundColor[i],
                                                        hidden: false,
                                                        lineCap: 'butt',
                                                        lineDash: [],
                                                        lineDashOffset: 0,
                                                        lineJoin: 'miter',
                                                        lineWidth: 1,
                                                        strokeStyle: data.datasets[0].backgroundColor[i],
                                                        pointStyle: 'circle',
                                                        rotation: 0
                                                    };
                                                });
                                            }
                                            return [];
                                        }
                                    }
                                }
                            },
                            cutout: '60%'
                        }
                    });
                })
                .catch(error => {
                    console.error('Error fetching chart data:', error);
                    // Show error message to user
                    const chartsContainer = document.querySelector('.charts-container');
                    if (chartsContainer) {
                        chartsContainer.innerHTML = '<div class="alert alert-error">Error loading chart data. Please try again later.</div>';
                    }
                });
        }
        
        // Add error handling for failed requests
        window.addEventListener('error', function(e) {
            console.error('Error loading resource:', e);
        });

        // Add confirmation for important actions
        document.addEventListener('DOMContentLoaded', function() {
            // Add confirmation for loan actions
            const loanActions = document.querySelectorAll('.table-actions a');
            loanActions.forEach(action => {
                action.addEventListener('click', function(e) {
                    if (this.classList.contains('btn-success') || this.classList.contains('btn-danger')) {
                        if (!confirm('Are you sure you want to ' + this.textContent.trim() + ' this item?')) {
                            e.preventDefault();
                        }
                    }
                });
            });
            
            // Initialize charts
            initializeCharts();
        });
    </script>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?> 