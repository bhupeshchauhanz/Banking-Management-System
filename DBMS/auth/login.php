<?php
session_start();
require_once '../config/db_connect.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../' . $_SESSION['role'] . '/dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT u.*, 
                       CASE 
                           WHEN e.id IS NOT NULL THEN 'employee'
                           WHEN m.id IS NOT NULL THEN 'manager'
                           WHEN c.id IS NOT NULL THEN 'customer'
                           ELSE NULL
                       END as role
                FROM users u
                LEFT JOIN employees e ON u.id = e.user_id
                LEFT JOIN managers m ON u.id = m.user_id
                LEFT JOIN customers c ON u.id = c.user_id
                WHERE u.username = ? AND u.status = 'active'
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                // Update last login
                $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                // Redirect based on role
                header('Location: ../' . $user['role'] . '/dashboard.php');
                exit();
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (PDOException $e) {
            logError("Login error: " . $e->getMessage());
            $error = 'An error occurred. Please try again later.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>
                    <i class="fas fa-unlock-alt"></i>
                    SecureBank Login
                </h1>
                <p>Welcome back! Please login to your account.</p>
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php" class="auth-form">
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i>
                        Username
                    </label>
                    <input type="text" name="username" id="username" required
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i>
                        Password
                    </label>
                    <input type="password" name="password" id="password" required>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt"></i>
                        Login
                    </button>
                </div>
                
                <div class="auth-links">
                    <a href="forgot_password.php">
                        <i class="fas fa-question-circle"></i>
                        Forgot Password?
                    </a>
                    <a href="register.php">
                        <i class="fas fa-user-plus"></i>
                        Create Account
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html> 