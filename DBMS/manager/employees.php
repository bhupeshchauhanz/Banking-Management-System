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

// Handle employee deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $pdo->beginTransaction();
        
        $employee_id = $_GET['delete'];
        
        // Get employee details for logging
        $employee_query = "SELECT s.first_name, s.last_name, s.user_id 
                          FROM staff s 
                          JOIN users u ON s.user_id = u.id 
                          WHERE s.id = ? AND u.role = 'employee'";
        $stmt = $pdo->prepare($employee_query);
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch();
        
        if ($employee) {
            // Delete from staff table
            $delete_staff = "DELETE FROM staff WHERE id = ?";
            $stmt = $pdo->prepare($delete_staff);
            $stmt->execute([$employee_id]);
            
            // Delete from users table
            $delete_user = "DELETE FROM users WHERE id = ?";
            $stmt = $pdo->prepare($delete_user);
            $stmt->execute([$employee['user_id']]);
            
            // Log the action
            $log_query = "INSERT INTO audit_logs (user_id, action_type, details) 
                         VALUES (?, 'delete_employee', ?)";
            $stmt = $pdo->prepare($log_query);
            $stmt->execute([$manager_id, "Deleted employee: {$employee['first_name']} {$employee['last_name']}"]);
            
            // Clear relevant caches
            clearCachedData('all_employees');
            clearCachedData('employee_stats');
            
            $pdo->commit();
            $_SESSION['success'] = "Employee deleted successfully!";
        } else {
            throw new Exception("Employee not found");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header('Location: employees.php');
    exit();
}

// Get all employees with optimized query and caching
$employees = getCachedData('all_employees', 300);
if ($employees === false) {
    $employees_query = "SELECT s.*, u.username, u.is_active, u.last_login,
                       (SELECT COUNT(*) FROM audit_logs al 
                        WHERE al.user_id = u.id 
                        AND al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as recent_activities
                       FROM staff s 
                       JOIN users u ON s.user_id = u.id 
                       WHERE u.role = 'employee'
                       ORDER BY s.created_at DESC";
    $employees = $pdo->query($employees_query)->fetchAll();
    cacheData('all_employees', $employees, 300);
}

// Get employee statistics with caching
$stats = getCachedData('employee_stats', 300);
if ($stats === false) {
    $stats_query = "SELECT 
                    COUNT(*) as total_employees,
                    SUM(CASE WHEN u.role = 'employee' THEN 1 ELSE 0 END) as active_employees,
                    SUM(CASE WHEN u.role = 'manager' THEN 1 ELSE 0 END) as managers,
                    SUM(CASE WHEN u.is_active = 0 THEN 1 ELSE 0 END) as inactive_employees
                    FROM staff s 
                    JOIN users u ON s.user_id = u.id";
    $stats = $pdo->query($stats_query)->fetch();
    cacheData('employee_stats', $stats, 300);
}

// Get recent employees with caching
$recent_employees = getCachedData('recent_employees', 60);
if ($recent_employees === false) {
    $recent_query = "SELECT s.*, u.username, u.is_active, u.last_login
                    FROM staff s 
                    JOIN users u ON s.user_id = u.id 
                    ORDER BY s.created_at DESC 
                    LIMIT 10";
    $recent_employees = $pdo->query($recent_query)->fetchAll();
    cacheData('recent_employees', $recent_employees, 60);
}

// Get inactive employees with caching
$inactive_employees = getCachedData('inactive_employees', 300);
if ($inactive_employees === false) {
    $inactive_query = "SELECT s.*, u.username, u.role
                      FROM staff s 
                      JOIN users u ON s.user_id = u.id 
                      WHERE u.is_active = 0 
                      ORDER BY s.created_at DESC 
                      LIMIT 10";
    $inactive_employees = $pdo->query($inactive_query)->fetchAll();
    cacheData('inactive_employees', $inactive_employees, 300);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees - SecureBank</title>
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f8f9fa;
            color: var(--text-primary);
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: var(--dark-bg);
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding: 15px 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }
        
        .sidebar-header {
            padding: 0 20px 15px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 15px;
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .sidebar-logo i {
            font-size: 1.8rem;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .sidebar-logo h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: white;
            margin: 0;
        }
        
        .profile-section {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 25px;
            margin-bottom: 25px;
        }
        
        .profile-photo {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-color);
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
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
            margin-top: 10px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 25px;
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

        @media (max-width: 1200px) {
            .sidebar {
                width: 70px;
                padding: 15px 0;
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
                margin-left: 70px;
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

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .page-title {
            font-size: 1.8em;
            color: var(--primary-color);
            margin: 0;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: #d90429;
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: var(--primary-dark);
        }

        .table-container {
            background-color: var(--card-bg);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background-color: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: left;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .status-active {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-inactive {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--danger);
        }

        .employee-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.9em;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background-color: var(--card-bg);
            width: 90%;
            max-width: 500px;
            margin: 50px auto;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            margin: 0;
            color: var(--primary-color);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: var(--text-color);
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 1em;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .alert-danger {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
            }

            .container {
                flex-direction: column;
            }

            .table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-university"></i>
                    <h1>SecureBank</h1>
                </div>
            </div>

            <div class="profile-section">
                <?php if (!empty($manager['profile_photo'])): ?>
                    <img src="../uploads/profile_photos/<?php echo htmlspecialchars($manager['profile_photo']); ?>" alt="Profile Photo" class="profile-photo">
                <?php else: ?>
                    <div class="profile-photo">
                        <?php echo strtoupper(substr($manager['first_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($manager['first_name'] . ' ' . $manager['last_name']); ?></h2>
                    <p>Bank Manager</p>
                </div>
            </div>
            
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="employees.php" class="menu-item active">
                    <i class="fas fa-users"></i>
                    <span>Manage Employees</span>
                </a>
                <a href="customers.php" class="menu-item">
                    <i class="fas fa-user-friends"></i>
                    <span>Manage Customers</span>
                </a>
                <a href="transactions.php" class="menu-item">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Transactions</span>
                </a>
                <a href="loans.php" class="menu-item">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Loans</span>
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
        <main class="main-content">
            <div class="header">
                <h1 class="page-title">Manage Employees</h1>
                <div class="action-buttons">
                    <a href="add_employee.php" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Add Employee
                    </a>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Name</th>
                            <th>Employee ID</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td>
                                    <img src="<?php echo htmlspecialchars($employee['profile_photo'] ?? '../assets/images/default-profile.png'); ?>" 
                                         alt="Employee Photo" class="employee-photo">
                                </td>
                                <td><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($employee['employee_id']); ?></td>
                                <td><?php echo htmlspecialchars($employee['email']); ?></td>
                                <td><?php echo htmlspecialchars($employee['phone']); ?></td>
                                <td><?php echo htmlspecialchars($employee['department']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $employee['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo $employee['last_login'] ? date('M d, Y H:i', strtotime($employee['last_login'])) : 'Never'; ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="edit_employee.php?id=<?php echo $employee['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="GET" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this employee?');">
                                            <input type="hidden" name="delete" value="<?php echo $employee['id']; ?>">
                                            <button type="submit" name="delete" class="btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
        // Add any necessary JavaScript here
    </script>
</body>
</html> 