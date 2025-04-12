<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: ../index.php');
    exit();
}

// Get customer and account IDs from URL
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

if (!$customer_id || !$account_id) {
    $_SESSION['error'] = "Invalid customer or account ID.";
    header('Location: dashboard.php');
    exit();
}

// Fetch customer and account details
$stmt = $pdo->prepare("
    SELECT c.*, a.account_number, a.balance, u.username 
    FROM customers c 
    JOIN users u ON c.user_id = u.id 
    JOIN accounts a ON c.id = a.customer_id 
    WHERE c.id = ? AND a.id = ?
");
$stmt->execute([$customer_id, $account_id]);
$customer = $stmt->fetch();

if (!$customer) {
    $_SESSION['error'] = "Customer or account not found.";
    header('Location: dashboard.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    if ($amount <= 0) {
        $_SESSION['error'] = "Please enter a valid amount.";
    } else {
        // Start transaction
        $pdo->beginTransaction();

        try {
            // Update account balance
            $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $account_id]);

            // Record the transaction
            $stmt = $pdo->prepare("
                INSERT INTO transactions (account_id, type, amount, description, status, created_at) 
                VALUES (?, 'deposit', ?, ?, 'completed', NOW())
            ");
            $stmt->execute([$account_id, $amount, $description]);

            $pdo->commit();
            $_SESSION['success'] = "Deposit of $" . number_format($amount, 2) . " has been processed successfully.";
            header('Location: dashboard.php?customer_id=' . $customer_id);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "An error occurred while processing the deposit.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Deposit - SecureBank</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
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
            color: var(--text-primary);
        }

        .profile-info p {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .sidebar-menu {
            flex: 1;
            padding: 0 30px;
            overflow-y: auto;
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
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
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
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .header-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            background: var(--primary-dark);
        }

        /* Form */
        .form-container {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            max-width: 600px;
            margin: 0 auto;
        }

        .customer-info {
            background: var(--primary-light);
            color: white;
            padding: 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 30px;
        }

        .customer-info h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .customer-info p {
            margin: 5px 0;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .alert {
            padding: 15px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: var(--text-secondary);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--text-primary);
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
            }

            .header-actions {
                width: 100%;
                justify-content: stretch;
            }

            .header-btn {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="deposit-container">
        <div class="deposit-header">
            <i class="fas fa-plus-circle"></i>
            <h1>Process Deposit</h1>
        </div>

        <div class="customer-info">
            <p><strong>Customer:</strong> <?php echo htmlspecialchars($customer['username']); ?></p>
            <p><strong>Account Number:</strong> <?php echo htmlspecialchars($customer['account_number']); ?></p>
            <p><strong>Current Balance:</strong> $<?php echo number_format($customer['balance'], 2); ?></p>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php 
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="amount">Amount ($)</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
            </div>

            <div class="form-group">
                <label for="description">Description (Optional)</label>
                <input type="text" id="description" name="description" placeholder="Enter deposit description">
            </div>

            <div class="form-actions">
                <a href="dashboard.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check"></i> Process Deposit
                </button>
            </div>
        </form>
    </div>
</body>
</html> 