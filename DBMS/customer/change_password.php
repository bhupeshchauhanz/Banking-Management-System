<?php
session_start();
require_once '../config/db_connect.php';
require_once '../includes/dashboard_layout.php';
require_once '../includes/customer_sidebar.php';

// Validate session and role
validateSession('customer');

// Get PDO connection
$pdo = getPDOConnection();

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate password requirements
    if (strlen($new_password) < 8) {
        $_SESSION['error'] = 'New password must be at least 8 characters long.';
        header('Location: change_password.php');
        exit();
    }
    
    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = 'New passwords do not match.';
        header('Location: change_password.php');
        exit();
    }
    
    try {
        // Get user's current password hash
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($current_password, $user['password'])) {
            throw new Exception('Current password is incorrect.');
        }
        
        // Hash new password
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$password_hash, $_SESSION['user_id']]);
        
        // Log the password change
        logError("Password changed successfully for user_id: " . $_SESSION['user_id']);
        
        handleSuccess('Password changed successfully. Please login again.');
        
        // Destroy session and redirect to login
        session_destroy();
        header('Location: ../login.php');
        exit();
    } catch (Exception $e) {
        logError("Password change failed: " . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
        header('Location: change_password.php');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <!-- Sidebar -->
    <?php include '../includes/customer_sidebar.php'; ?>
    
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
            <div class="card">
                <h2>Change Password</h2>
                <form method="POST" action="change_password.php" class="password-form">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" name="current_password" id="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" name="new_password" id="new_password" required
                               minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}"
                               title="Must contain at least one number, one uppercase and lowercase letter, and at least 8 characters">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" required>
                    </div>
                    
                    <div class="password-requirements">
                        <p>Password must:</p>
                        <ul>
                            <li id="length">Be at least 8 characters long</li>
                            <li id="letter">Include at least one lowercase letter</li>
                            <li id="capital">Include at least one uppercase letter</li>
                            <li id="number">Include at least one number</li>
                            <li id="match">Match confirmation password</li>
                        </ul>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Password validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const form = document.querySelector('.password-form');
        
        // Update requirements list
        function updateRequirements() {
            const password = newPassword.value;
            const confirm = confirmPassword.value;
            
            document.getElementById('length').className = password.length >= 8 ? 'valid' : '';
            document.getElementById('letter').className = /[a-z]/.test(password) ? 'valid' : '';
            document.getElementById('capital').className = /[A-Z]/.test(password) ? 'valid' : '';
            document.getElementById('number').className = /\d/.test(password) ? 'valid' : '';
            document.getElementById('match').className = password === confirm && password !== '' ? 'valid' : '';
        }
        
        newPassword.addEventListener('keyup', updateRequirements);
        confirmPassword.addEventListener('keyup', updateRequirements);
        
        // Form validation
        form.addEventListener('submit', function(e) {
            if (newPassword.value !== confirmPassword.value) {
                e.preventDefault();
                alert('Passwords do not match');
            }
        });
    </script>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?> 