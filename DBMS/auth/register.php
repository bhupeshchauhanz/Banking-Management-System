<?php
session_start();
require_once '../config/db_connect.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../' . $_SESSION['role'] . '/dashboard.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize input
    $username = sanitizeInput($_POST['username'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name = sanitizeInput($_POST['last_name'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $address = sanitizeInput($_POST['address'] ?? '');
    $city = sanitizeInput($_POST['city'] ?? '');
    $state = sanitizeInput($_POST['state'] ?? '');
    $zip_code = sanitizeInput($_POST['zip_code'] ?? '');
    $country = sanitizeInput($_POST['country'] ?? '');
    
    // Validate input
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) ||
        empty($first_name) || empty($last_name) || empty($phone) || empty($address) ||
        empty($city) || empty($state) || empty($zip_code) || empty($country)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                throw new Exception('Username or email already exists.');
            }
            
            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, password, role, status, created_at)
                VALUES (?, ?, ?, 'customer', 'active', NOW())
            ");
            $stmt->execute([$username, $email, $password_hash]);
            $user_id = $pdo->lastInsertId();
            
            // Insert customer
            $stmt = $pdo->prepare("
                INSERT INTO customers (
                    user_id, first_name, last_name, phone, address,
                    city, state, zip_code, country, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user_id, $first_name, $last_name, $phone, $address,
                $city, $state, $zip_code, $country
            ]);
            
            // Create default savings account
            $stmt = $pdo->prepare("
                INSERT INTO accounts (
                    customer_id, account_number, type, balance, status, created_at
                ) VALUES (?, ?, 'savings', 0, 'active', NOW())
            ");
            $stmt->execute([$user_id, generateAccountNumber()]);
            
            // Commit transaction
            $pdo->commit();
            
            $success = 'Registration successful! You can now login.';
        } catch (Exception $e) {
            $pdo->rollBack();
            logError("Registration error: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

// Function to generate a unique account number
function generateAccountNumber() {
    return 'SAV' . str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>
                    <i class="fas fa-user-plus"></i>
                    Create Account
                </h1>
                <p>Join SecureBank today and start managing your finances.</p>
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
                <p>Please <a href="login.php">login</a> to continue.</p>
            </div>
            <?php else: ?>
            <form method="POST" action="register.php" class="auth-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="username">
                            <i class="fas fa-user"></i>
                            Username
                        </label>
                        <input type="text" name="username" id="username" required
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope"></i>
                            Email
                        </label>
                        <input type="email" name="email" id="email" required
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">
                            <i class="fas fa-lock"></i>
                            Password
                        </label>
                        <input type="password" name="password" id="password" required
                               minlength="8">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">
                            <i class="fas fa-lock"></i>
                            Confirm Password
                        </label>
                        <input type="password" name="confirm_password" id="confirm_password" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">
                            <i class="fas fa-user"></i>
                            First Name
                        </label>
                        <input type="text" name="first_name" id="first_name" required
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">
                            <i class="fas fa-user"></i>
                            Last Name
                        </label>
                        <input type="text" name="last_name" id="last_name" required
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="phone">
                        <i class="fas fa-phone"></i>
                        Phone
                    </label>
                    <input type="tel" name="phone" id="phone" required
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="address">
                        <i class="fas fa-map-marker-alt"></i>
                        Address
                    </label>
                    <input type="text" name="address" id="address" required
                           value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="city">
                            <i class="fas fa-city"></i>
                            City
                        </label>
                        <input type="text" name="city" id="city" required
                               value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="state">
                            <i class="fas fa-map"></i>
                            State
                        </label>
                        <input type="text" name="state" id="state" required
                               value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="zip_code">
                            <i class="fas fa-map-pin"></i>
                            ZIP Code
                        </label>
                        <input type="text" name="zip_code" id="zip_code" required
                               value="<?php echo htmlspecialchars($_POST['zip_code'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="country">
                            <i class="fas fa-globe"></i>
                            Country
                        </label>
                        <input type="text" name="country" id="country" required
                               value="<?php echo htmlspecialchars($_POST['country'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i>
                        Create Account
                    </button>
                </div>
                
                <div class="auth-links">
                    <a href="login.php">
                        <i class="fas fa-sign-in-alt"></i>
                        Already have an account? Login
                    </a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>