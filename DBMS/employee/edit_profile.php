<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: ../index.php');
    exit();
}

$error = '';
$success = '';

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $date_of_birth = trim($_POST['date_of_birth']);

    // Validate input
    if (empty($first_name) || empty($last_name) || empty($email) || empty($phone)) {
        $error = "First name, last name, email, and phone are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = "Please enter a valid 10-digit phone number.";
    } else {
        try {
            // Update staff information
            $stmt = $pdo->prepare("
                UPDATE staff 
                SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, date_of_birth = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$first_name, $last_name, $email, $phone, $address, $date_of_birth, $_SESSION['user_id']]);
            
            // Handle profile photo upload
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == 0) {
                $allowed_types = array('jpg', 'jpeg', 'png', 'gif');
                $file_name = $_FILES['profile_photo']['name'];
                $file_tmp = $_FILES['profile_photo']['tmp_name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                // Check if file type is allowed
                if (in_array($file_ext, $allowed_types)) {
                    // Check file size (max 5MB)
                    if ($_FILES['profile_photo']['size'] <= 5 * 1024 * 1024) {
                        // Generate unique file name
                        $new_file_name = uniqid('profile_', true) . '.' . $file_ext;
                        $upload_path = '../uploads/profile_photos/' . $new_file_name;
                        
                        // Create directory if it doesn't exist
                        if (!file_exists('../uploads/profile_photos/')) {
                            mkdir('../uploads/profile_photos/', 0777, true);
                        }
                        
                        // Move uploaded file
                        if (move_uploaded_file($file_tmp, $upload_path)) {
                            // Delete old photo if exists
                            if (!empty($employee['profile_photo'])) {
                                $old_photo_path = '../uploads/profile_photos/' . $employee['profile_photo'];
                                if (file_exists($old_photo_path)) {
                                    unlink($old_photo_path);
                                }
                            }
                            
                            // Update profile photo in database
                            $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                            $stmt->execute([$new_file_name, $_SESSION['user_id']]);
                        } else {
                            $error = "Failed to upload file. Please check directory permissions.";
                        }
                    } else {
                        $error = "File size too large. Maximum size allowed is 5MB.";
                    }
                } else {
                    $error = "Invalid file type. Allowed types: jpg, jpeg, png, gif";
                }
            }
            
            $success = "Profile updated successfully!";
            
            // Refresh employee data
            $stmt = $pdo->prepare("
                SELECT e.*, u.username, u.profile_photo
                FROM staff e
                JOIN users u ON e.user_id = u.id
                WHERE u.id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $employee = $stmt->fetch();
        } catch (PDOException $e) {
            $error = "Update failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - SecureBank</title>
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
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .page-header {
            margin-bottom: 30px;
            text-align: center;
            width: 100%;
        }
        
        .page-header h2 {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 10px;
        }
        
        .page-header p {
            color: var(--text-secondary);
            font-size: 1rem;
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
        
        /* Form Styling */
        .edit-profile-form {
            background: var(--card-bg);
            padding: 40px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            width: 100%;
            max-width: 800px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .photo-upload {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
            margin-bottom: 40px;
            padding: 30px;
            background: rgba(67, 97, 238, 0.05);
            border-radius: var(--radius-md);
        }
        
        .current-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
            box-shadow: var(--shadow-sm);
        }
        
        .photo-upload-btn {
            background: var(--primary-color);
            color: white;
            padding: 12px 25px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            margin: 0 auto;
        }
        
        .photo-upload-info {
            display: flex;
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .photo-upload-info p {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin: 0;
        }
        
        .photo-upload-info .text-sm {
            font-size: 0.75rem;
        }
        
        .submit-btn {
            background: var(--primary-color);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            margin: 30px auto 0;
            display: block;
        }
        
        .submit-btn:hover {
            background: var(--primary-dark);
        }
        
        .text-sm {
            font-size: 0.875rem;
        }
        
        .text-secondary {
            color: var(--text-secondary);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            width: 100%;
            max-width: 800px;
            text-align: center;
        }
        
        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            border: 1px solid var(--success);
            color: var(--success);
        }
        
        .alert-error {
            background: rgba(247, 37, 133, 0.1);
            border: 1px solid var(--danger);
            color: var(--danger);
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
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
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
            <a href="customers.php" class="menu-item">
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
            <a href="edit_profile.php" class="menu-item active">
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
            <h2>Edit Profile</h2>
            <p>Update your personal information and profile photo</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form class="edit-profile-form" method="POST" enctype="multipart/form-data">
            <div class="photo-upload">
                <div class="photo-preview">
                    <?php if (!empty($employee['profile_photo'])): ?>
                        <img src="../uploads/profile_photos/<?php echo htmlspecialchars($employee['profile_photo']); ?>" 
                             alt="Profile Photo" 
                             class="current-photo">
                    <?php else: ?>
                        <div class="current-photo" style="background: var(--primary-light); display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem; font-weight: 600;">
                            <?php echo strtoupper(substr($employee['first_name'] ?? 'U', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="photo-upload-info">
                    <label class="photo-upload-btn">
                        <i class="fas fa-camera"></i> Change Photo
                        <input type="file" name="profile_photo" style="display: none;" accept="image/*">
                    </label>
                    <p>Upload a new profile photo</p>
                    <p class="text-sm">Supported formats: JPG, PNG, GIF<br>Maximum file size: 5MB</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($employee['first_name'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($employee['last_name'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>" required pattern="[0-9]{10}">
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($employee['address'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="date_of_birth">Date of Birth</label>
                <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($employee['date_of_birth'] ?? ''); ?>">
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </form>
    </div>

    <script>
        // Preview image before upload
        document.querySelector('input[type="file"]').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.querySelector('.current-photo').src = e.target.result;
                }
                reader.readAsDataURL(e.target.files[0]);
            }
        });
    </script>
</body>
</html> 