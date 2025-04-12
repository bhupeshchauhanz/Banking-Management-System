<?php
session_start();
require_once '../config/database.php';

// Create MySQLi connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check MySQLi connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create PDO connection
try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: ../auth/login.php');
    exit();
}

// Get manager information
$manager_id = $_SESSION['user_id'];
$manager_query = "SELECT s.* FROM staff s 
                 JOIN users u ON s.user_id = u.id 
                 WHERE u.id = ? AND u.role = 'manager'";
$stmt = $conn->prepare($manager_query);
$stmt->bind_param("i", $manager_id);
$stmt->execute();
$manager = $stmt->get_result()->fetch_assoc();

if (!$manager) {
    header('Location: ../auth/logout.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $designation = trim($_POST['designation']);
    $department = trim($_POST['department']);
    $salary = floatval($_POST['salary']);
    $hire_date = $_POST['hire_date'];
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate required fields
    $errors = [];
    if (empty($first_name)) $errors[] = "First name is required";
    if (empty($last_name)) $errors[] = "Last name is required";
    if (empty($email)) $errors[] = "Email is required";
    if (empty($phone)) $errors[] = "Phone number is required";
    if (empty($designation)) $errors[] = "Designation is required";
    if (empty($department)) $errors[] = "Department is required";
    if (empty($salary)) $errors[] = "Salary is required";
    if (empty($hire_date)) $errors[] = "Hire date is required";
    if (empty($username)) $errors[] = "Username is required";
    if (empty($password)) $errors[] = "Password is required";
    if ($password !== $confirm_password) $errors[] = "Passwords do not match";
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Check if username or email already exists
    $check_query = "SELECT COUNT(*) as count FROM users WHERE username = ? OR email = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if ($result['count'] > 0) {
        $errors[] = "Username or email already exists";
    }
    
    // If no errors, proceed with employee creation
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Generate employee ID
            $employee_id = strtoupper(substr($department, 0, 3)) . date('Ymd') . rand(1000, 9999);
            
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert into users table
            $user_query = "INSERT INTO users (username, password, role, is_active) VALUES (?, ?, 'employee', 1)";
            $stmt = $conn->prepare($user_query);
            $stmt->bind_param("ss", $username, $hashed_password);
            $stmt->execute();
            $user_id = $conn->insert_id;
            
            // Insert into staff table
            $staff_query = "INSERT INTO staff (user_id, first_name, last_name, email, phone, address, 
                            designation, department, salary, hire_date, employee_id) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($staff_query);
            $stmt->bind_param("isssssssdss", $user_id, $first_name, $last_name, $email, $phone, 
                            $address, $designation, $department, $salary, $hire_date, $employee_id);
            $stmt->execute();
            
            // Handle profile photo upload
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['profile_photo'];
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $max_size = 5 * 1024 * 1024; // 5MB
                
                if (in_array($file['type'], $allowed_types) && $file['size'] <= $max_size) {
                    $upload_dir = '../assets/uploads/profiles/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $file_name = 'employee_' . $user_id . '_' . time() . '.' . $file_extension;
                    $target_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($file['tmp_name'], $target_path)) {
                        $photo_query = "UPDATE users SET profile_photo = ? WHERE id = ?";
                        $stmt = $conn->prepare($photo_query);
                        $photo_path = 'assets/uploads/profiles/' . $file_name;
                        $stmt->bind_param("si", $photo_path, $user_id);
                        $stmt->execute();
                    }
                }
            }
            
            // Log the action
            $log_query = "INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'add_employee', ?)";
            $stmt = $conn->prepare($log_query);
            $details = "Added new employee: " . $first_name . " " . $last_name;
            $stmt->bind_param("is", $manager_id, $details);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            header('Location: employees.php?success=Employee added successfully');
            exit();
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $errors[] = "Failed to add employee: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Employee - SecureBank</title>
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
            max-width: 1200px;
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
            margin-bottom: 40px;
            text-align: center;
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 10px;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
            margin: 0;
        }

        .employee-form {
            display: grid;
            gap: 40px;
        }

        .form-section {
            background: #fff;
            border-radius: var(--radius-md);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .form-section:hover {
            box-shadow: var(--shadow-md);
        }

        .form-section h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section h2 i {
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            color: var(--text-primary);
            transition: var(--transition);
            background: #fff;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .form-control::placeholder {
            color: var(--text-secondary);
        }

        .form-text {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 8px;
        }

        .photo-upload {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 10px;
        }

        .photo-preview {
            width: 100px;
            height: 100px;
            border-radius: var(--radius-sm);
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 2px dashed var(--border-color);
            transition: var(--transition);
        }

        .photo-preview:hover {
            border-color: var(--primary-color);
        }

        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .photo-preview i {
            font-size: 2rem;
            color: var(--text-secondary);
        }

        .upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            cursor: pointer;
            transition: var(--transition);
        }

        .upload-btn:hover {
            background: var(--border-color);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            font-weight: 500;
            font-size: 1rem;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
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

        .alert-danger {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .main-content {
                padding: 20px;
            }

            .form-section {
                padding: 20px;
            }

            .form-actions {
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
                <h1>Add New Employee</h1>
                <p>Fill in the details below to create a new employee account</p>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data" class="employee-form">
                <!-- Personal Information -->
                <div class="form-section">
                    <h2><i class="fas fa-user"></i>Personal Information</h2>
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" class="form-control" required 
                            value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>"
                            placeholder="Enter first name">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" class="form-control" required
                            value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>"
                            placeholder="Enter last name">
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" class="form-control" required
                            value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                            placeholder="Enter email address">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone Number *</label>
                        <input type="tel" id="phone" name="phone" class="form-control" required
                            value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                            placeholder="Enter phone number">
                    </div>
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" class="form-control"
                            value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>"
                            placeholder="Enter address">
                    </div>
                    <div class="form-group">
                        <label for="profile_photo">Profile Photo</label>
                        <div class="photo-upload">
                            <div class="photo-preview" id="photoPreview">
                                <i class="fas fa-user"></i>
                            </div>
                            <label class="upload-btn">
                                <i class="fas fa-upload"></i>
                                Choose Photo
                                <input type="file" id="profile_photo" name="profile_photo" accept="image/*" style="display: none;">
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Employment Details -->
                <div class="form-section">
                    <h2><i class="fas fa-briefcase"></i>Employment Details</h2>
                    <div class="form-group">
                        <label for="designation">Designation *</label>
                        <input type="text" id="designation" name="designation" class="form-control" required
                            value="<?php echo isset($_POST['designation']) ? htmlspecialchars($_POST['designation']) : ''; ?>"
                            placeholder="Enter designation">
                    </div>
                    <div class="form-group">
                        <label for="department">Department *</label>
                        <select id="department" name="department" class="form-control" required>
                            <option value="">Select Department</option>
                            <option value="Operations">Operations</option>
                            <option value="Customer Service">Customer Service</option>
                            <option value="Finance">Finance</option>
                            <option value="IT">IT</option>
                            <option value="HR">HR</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="salary">Salary (USD) *</label>
                        <input type="number" id="salary" name="salary" class="form-control" required min="0" step="0.01"
                            value="<?php echo isset($_POST['salary']) ? htmlspecialchars($_POST['salary']) : ''; ?>"
                            placeholder="Enter salary">
                    </div>
                    <div class="form-group">
                        <label for="hire_date">Hire Date *</label>
                        <input type="date" id="hire_date" name="hire_date" class="form-control" required
                            value="<?php echo isset($_POST['hire_date']) ? htmlspecialchars($_POST['hire_date']) : date('Y-m-d'); ?>">
                    </div>
                </div>

                <!-- Account Credentials -->
                <div class="form-section">
                    <h2><i class="fas fa-lock"></i>Account Credentials</h2>
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" class="form-control" required
                            value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                            placeholder="Enter username">
                        <small class="form-text">Username must be unique and at least 5 characters long</small>
                    </div>
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" class="form-control" required minlength="8"
                            placeholder="Enter password">
                        <small class="form-text">Password must be at least 8 characters long</small>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8"
                            placeholder="Confirm password">
                    </div>
                </div>

                <div class="form-actions">
                    <a href="employees.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                    <button type="submit" name="add_employee" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i>
                        Add Employee
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Preview profile photo
        document.getElementById('profile_photo').addEventListener('change', function(e) {
            const preview = document.getElementById('photoPreview');
            const file = e.target.files[0];
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Profile Preview">`;
                }
                reader.readAsDataURL(file);
            } else {
                preview.innerHTML = '<i class="fas fa-user"></i>';
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html> 