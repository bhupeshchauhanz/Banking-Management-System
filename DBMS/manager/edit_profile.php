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

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: ../index.php');
    exit();
}

$error = '';
$success = '';

// Get manager data
try {
    $stmt = $pdo->prepare("
        SELECT m.*, u.username, u.profile_photo
        FROM staff m
        JOIN users u ON m.user_id = u.id
        WHERE u.id = ? AND u.role = 'manager'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $manager = $stmt->fetch();

    if (!$manager) {
        die("Manager record not found");
    }
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
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
                            if (!empty($manager['profile_photo'])) {
                                $old_photo_path = '../uploads/profile_photos/' . $manager['profile_photo'];
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
            
            // Refresh manager data
            $stmt = $pdo->prepare("
                SELECT m.*, u.username, u.profile_photo
                FROM staff m
                JOIN users u ON m.user_id = u.id
                WHERE u.id = ? AND u.role = 'manager'
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $manager = $stmt->fetch();
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
        
        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .page-header p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .edit-profile-container {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            max-width: 800px;
            margin: 0 auto;
        }
        
        .alert {
            padding: 15px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-danger {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
        }
        
        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
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
            font-family: inherit;
            font-size: 0.9rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: var(--border-color);
            color: var(--text-primary);
        }
        
        .btn-secondary:hover {
            background: var(--text-secondary);
            color: white;
        }
        
        .profile-photo-section {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .profile-photo-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 600;
            color: white;
            background: var(--primary-color);
        }
        
        .profile-photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .profile-photo-section {
                flex-direction: column;
                text-align: center;
            }
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1a1b26 0%, #16171e 100%);
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            display: flex;
            flex-direction: column;
            padding: 20px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }

        .sidebar-header {
            padding: 20px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
        }

        .sidebar-logo i {
            font-size: 2rem;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sidebar-logo h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
            background: linear-gradient(135deg, #fff, #e0e0e0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .profile-section {
            padding: 20px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
            text-align: center;
        }

        .profile-photo-container {
            width: 80px;
            height: 80px;
            margin: 0 auto 15px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--primary-color);
            background: var(--primary-color);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
        }

        .profile-photo-container:hover {
            transform: scale(1.05);
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
            font-size: 2rem;
            font-weight: 600;
            color: white;
        }

        .profile-info h2 {
            font-size: 1.1rem;
            margin: 0 0 5px;
            color: white;
            font-weight: 600;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            background: rgba(76, 201, 240, 0.2);
            color: var(--accent-color);
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .profile-details {
            text-align: left;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .sidebar-menu {
            flex: 1;
            overflow-y: auto;
            padding-right: 10px;
            /* Hide scrollbar for Chrome, Safari and Opera */
            &::-webkit-scrollbar {
                display: none;
            }
            /* Hide scrollbar for IE, Edge and Firefox */
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            border-radius: var(--radius-sm);
            margin-bottom: 5px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: var(--primary-color);
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            padding-left: 20px;
        }

        .menu-item:hover::before {
            transform: scaleY(1);
        }

        .menu-item.active {
            background: rgba(67, 97, 238, 0.2);
            color: white;
            padding-left: 20px;
        }

        .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .sidebar-footer {
            padding: 20px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            border-radius: var(--radius-sm);
            transition: all 0.3s ease;
            background: rgba(247, 37, 133, 0.1);
        }

        .logout-btn:hover {
            background: rgba(247, 37, 133, 0.2);
            color: var(--danger);
            transform: translateX(5px);
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding: 15px;
            }

            .sidebar-header {
                padding: 10px 0;
            }

            .profile-section {
                padding: 15px 0;
            }

            .profile-photo-container {
                width: 60px;
                height: 60px;
            }

            .menu-item {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
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
        
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="employees.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>Employees</span>
            </a>
            <a href="customers.php" class="menu-item">
                <i class="fas fa-user-friends"></i>
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
            <a href="reports.php" class="menu-item">
                <i class="fas fa-chart-bar"></i>
                <span>Reports</span>
            </a>
            <a href="edit_profile.php" class="menu-item active">
                <i class="fas fa-user-edit"></i>
                <span>Edit Profile</span>
            </a>
            <a href="change_password.php" class="menu-item">
                <i class="fas fa-key"></i>
                <span>Change Password</span>
            </a>
        </nav>
        
        <div class="sidebar-footer">
            <a href="../auth/logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>
    
    <main class="main-content">
        <div class="page-header">
            <h1>Edit Profile</h1>
            <p>Update your personal information and profile photo</p>
        </div>
        
        <div class="edit-profile-container">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <form action="" method="POST" enctype="multipart/form-data">
                <div class="profile-photo-section">
                    <div class="profile-photo-preview">
                        <?php if (!empty($manager['profile_photo'])): ?>
                            <img src="../uploads/profile_photos/<?php echo htmlspecialchars($manager['profile_photo']); ?>" alt="Profile Photo" id="photo-preview">
                        <?php else: ?>
                            <span id="photo-initial"><?php echo strtoupper(substr($manager['first_name'], 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="profile_photo" class="btn btn-secondary">
                            <i class="fas fa-camera"></i> Change Photo
                        </label>
                        <input type="file" id="profile_photo" name="profile_photo" accept="image/*" style="display: none;" onchange="previewPhoto(this)">
                        <p style="margin-top: 10px; font-size: 0.85rem; color: var(--text-secondary);">
                            JPG, PNG or GIF (max. 5MB)
                        </p>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="form-control" value="<?php echo htmlspecialchars($manager['first_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="form-control" value="<?php echo htmlspecialchars($manager['last_name']); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($manager['email']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($manager['phone']); ?>" pattern="[0-9]{10}" title="Please enter a valid 10-digit phone number" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" class="form-control" value="<?php echo !empty($manager['address']) ? htmlspecialchars($manager['address']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="date_of_birth">Date of Birth</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" value="<?php echo !empty($manager['date_of_birth']) ? htmlspecialchars($manager['date_of_birth']) : ''; ?>" required>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </form>
        </div>
    </main>
    
    <script>
        function previewPhoto(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('photo-preview');
                    const initial = document.getElementById('photo-initial');
                    if (preview) {
                        preview.src = e.target.result;
                    } else {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.id = 'photo-preview';
                        initial.parentNode.replaceChild(img, initial);
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>
</html> 