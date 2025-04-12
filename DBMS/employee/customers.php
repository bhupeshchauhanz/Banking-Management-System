<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: ../index.php');
    exit();
}

// Get employee data
try {
    $stmt = $pdo->prepare("
        SELECT e.*, u.username, u.profile_photo
        FROM staff e
        JOIN users u ON e.user_id = u.id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        // If no employee found, redirect to login
        header('Location: ../index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching employee data: " . $e->getMessage());
    $employee = [
        'first_name' => 'Unknown',
        'last_name' => 'User',
        'designation' => 'Employee',
        'profile_photo' => null,
        'username' => 'unknown'
    ];
}

try {
    // Get all customers with their account information
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.first_name,
            c.last_name,
            c.email,
            c.phone,
            c.status,
            a.id as account_id,
            a.account_number,
            a.account_type,
            a.balance,
            a.status as account_status
        FROM customers c
        LEFT JOIN accounts a ON c.id = a.customer_id
        WHERE a.status = 'active'
        ORDER BY c.created_at DESC
    ");
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching customers: " . $e->getMessage());
    $customers = [];
}

// Group customers by their ID to handle multiple accounts
$grouped_customers = [];
foreach ($customers as $customer) {
    $customer_id = $customer['id'];
    if (!isset($grouped_customers[$customer_id])) {
        $grouped_customers[$customer_id] = [
            'id' => $customer['id'],
            'first_name' => $customer['first_name'],
            'last_name' => $customer['last_name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'status' => $customer['status'],
            'accounts' => []
        ];
    }
    if ($customer['account_id']) {
        $grouped_customers[$customer_id]['accounts'][] = [
            'id' => $customer['account_id'],
            'account_number' => $customer['account_number'],
            'account_type' => $customer['account_type'],
            'balance' => $customer['balance'],
            'status' => $customer['account_status']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management - SecureBank</title>
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
        
        .profile-info h2 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: white;
        }
        
        .profile-info p {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
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
            background: #f8f9fa;
            min-height: 100vh;
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
            color: white;
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
        
        /* Table */
        .table-container {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th,
        .table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table th {
            font-weight: 600;
            color: var(--text-secondary);
            background: #f8f9fa;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
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
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            text-decoration: none;
            transition: var(--transition);
        }
        
        .action-btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .action-btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .action-btn-secondary {
            background: #f8f9fa;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }
        
        .action-btn-secondary:hover {
            background: #e9ecef;
            border-color: #dee2e6;
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
        }
        
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .table-container {
                padding: 20px;
            }
            
            .table th,
            .table td {
                padding: 10px;
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
            <?php if (isset($employee['profile_photo']) && !empty($employee['profile_photo'])): ?>
                <img src="../uploads/profile_photos/<?php echo htmlspecialchars($employee['profile_photo']); ?>" alt="Profile Photo" class="profile-photo">
            <?php else: ?>
                <div class="profile-photo-initial">
                    <?php echo strtoupper(substr($employee['first_name'] ?? 'U', 0, 1)); ?>
                </div>
            <?php endif; ?>
            
            <div class="profile-info">
                <h2><?php echo htmlspecialchars(($employee['first_name'] ?? 'Unknown') . ' ' . ($employee['last_name'] ?? 'User')); ?></h2>
                <p><?php echo htmlspecialchars($employee['designation'] ?? 'Employee'); ?></p>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <a href="dashboard.php" class="menu-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="customers.php" class="menu-item active">
                <i class="fas fa-users"></i>
                <span>Customers</span>
            </a>
            <a href="transactions.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Transactions</span>
            </a>
            <a href="loans.php" class="menu-item">
                <i class="fas fa-hand-holding-usd"></i>
                <span>Loans</span>
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
            <h1 class="page-title">Customer Management</h1>
            <div class="header-actions">
                <a href="dashboard.php" class="header-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Account</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grouped_customers)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No customers found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($grouped_customers as $customer): ?>
                            <tr>
                                <td>
                                    <div class="customer-info">
                                        <div class="customer-name">
                                            <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="contact-info">
                                        <div class="email"><?php echo htmlspecialchars($customer['email']); ?></div>
                                        <div class="phone"><?php echo htmlspecialchars($customer['phone']); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($customer['accounts'])): ?>
                                        <?php foreach ($customer['accounts'] as $account): ?>
                                            <div class="account-info">
                                                <div class="account-number">
                                                    <?php echo htmlspecialchars($account['account_number']); ?>
                                                </div>
                                                <div class="account-type">
                                                    <?php echo htmlspecialchars($account['account_type']); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No active accounts</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($customer['accounts'])): ?>
                                        <?php foreach ($customer['accounts'] as $account): ?>
                                            <div class="balance">
                                                $<?php echo number_format($account['balance'], 2); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo strtolower($customer['status']); ?>">
                                        <?php echo htmlspecialchars($customer['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if (!empty($customer['accounts'])): ?>
                                            <a href="deposit.php?account_id=<?php echo $customer['accounts'][0]['id']; ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="fas fa-plus"></i> Deposit
                                            </a>
                                            <a href="withdraw.php?account_id=<?php echo $customer['accounts'][0]['id']; ?>" 
                                               class="btn btn-sm btn-warning">
                                                <i class="fas fa-minus"></i> Withdraw
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html> 