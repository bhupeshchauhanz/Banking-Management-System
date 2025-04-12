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

// Handle customer deletion with transaction
if (isset($_POST['delete_customer']) && isset($_POST['customer_id'])) {
    $customer_id = (int)$_POST['customer_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get customer details for audit log
        $stmt = $pdo->prepare("SELECT c.*, u.username FROM customers c JOIN users u ON c.user_id = u.id WHERE c.id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        
        if ($customer) {
            // Delete from customers table
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            
            // Delete from users table
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$customer['user_id']]);
            
            // Log the action
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, details) VALUES (?, 'delete_customer', ?)");
            $stmt->execute([$manager_id, "Deleted customer: {$customer['username']}"]);
            
            $pdo->commit();
            $_SESSION['success'] = "Customer deleted successfully.";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error deleting customer: " . $e->getMessage());
        $_SESSION['error'] = "Error deleting customer. Please try again.";
    }
    
    header('Location: customers.php');
    exit();
}

// Get all customers with their account information
$customers_query = "SELECT c.*, u.username, u.profile_photo, u.is_active, u.last_login,
                   COUNT(a.id) as total_accounts,
                   SUM(a.balance) as total_balance,
                   (SELECT COUNT(*) FROM audit_logs WHERE user_id = u.id AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as recent_activity
                   FROM customers c 
                   JOIN users u ON c.user_id = u.id 
                   LEFT JOIN accounts a ON c.id = a.customer_id
                   WHERE u.role = 'customer'
                   GROUP BY c.id
                   ORDER BY c.created_at DESC";
$customers = $pdo->query($customers_query)->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Customers - SecureBank</title>
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

        .status-active {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-inactive {
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
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
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

        .customer-stats {
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
                    <h1>Manage Customers</h1>
                    <p>View and manage customer accounts</p>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="customer-stats">
                <div class="stat-card">
                    <h3>Total Customers</h3>
                    <p class="value"><?php echo count($customers); ?></p>
                </div>
                <div class="stat-card">
                    <h3>Active Customers</h3>
                    <p class="value"><?php echo count(array_filter($customers, function($c) { return $c['is_active']; })); ?></p>
                </div>
                <div class="stat-card">
                    <h3>Total Accounts</h3>
                    <p class="value"><?php echo array_sum(array_column($customers, 'total_accounts')); ?></p>
                </div>
                <div class="stat-card">
                    <h3>Total Balance</h3>
                    <p class="value">$<?php echo number_format(array_sum(array_column($customers, 'total_balance')), 2); ?></p>
                </div>
            </div>

            <div class="search-container">
                <input type="text" class="search-input" placeholder="Search customers..." id="searchInput">
                <select class="filter-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Accounts</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <img src="<?php echo $customer['profile_photo'] ? '../assets/uploads/profiles/' . $customer['profile_photo'] : '../assets/images/default-avatar.png'; ?>" 
                                             alt="Profile" class="customer-photo">
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($customer['username']); ?></div>
                                            <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                                ID: <?php echo $customer['id']; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($customer['email']); ?></div>
                                    <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                        <?php echo htmlspecialchars($customer['phone']); ?>
                                    </div>
                                </td>
                                <td><?php echo $customer['total_accounts']; ?></td>
                                <td>$<?php echo number_format($customer['total_balance'], 2); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $customer['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                        <?php echo $customer['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($customer['recent_activity'] > 0): ?>
                                        <span style="color: var(--success);">
                                            <i class="fas fa-circle"></i> Active
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-secondary);">
                                            <i class="fas fa-circle"></i> No recent activity
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view_customer.php?id=<?php echo $customer['id']; ?>" class="btn btn-primary" style="text-decoration: none;">
                                            <i class="fas fa-eye"></i>
                                            View
                                        </a>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this customer?');">
                                            <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                            <button type="submit" name="delete_customer" class="btn btn-danger">
                                                <i class="fas fa-trash"></i>
                                                Delete
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
                
                const isActive = row.querySelector('.status-badge').textContent.includes('Active');
                row.style.display = (status === 'active' && isActive) || (status === 'inactive' && !isActive) ? '' : 'none';
            });
        });
    </script>
</body>
</html> 