<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a customer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../index.php');
    exit();
}

// Get user's information
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.username, u.profile_photo
        FROM customers c 
        JOIN users u ON c.user_id = u.id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $customer = $stmt->fetch();

    if (!$customer) {
        die("Customer record not found");
    }
    
    // Get current profile photo path
    $profilePhoto = $customer['profile_photo'] ?? null;
    $photoUrl = $profilePhoto ? "../uploads/profile_photos/" . $profilePhoto : "../assets/images/default-avatar.png";
    
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    $file = $_FILES['profile_photo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'An error occurred during upload. Please try again.';
        $messageType = 'error';
    } elseif (!in_array($file['type'], $allowedTypes)) {
        $message = 'Invalid file type. Only JPEG, PNG, and GIF files are allowed.';
        $messageType = 'error';
    } elseif ($file['size'] > $maxFileSize) {
        $message = 'File is too large. Maximum size is 5MB.';
        $messageType = 'error';
    } else {
        // Create uploads directory if it doesn't exist
        $uploadDir = '../uploads/profile_photos/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('profile_') . '.' . $extension;
        $targetPath = $uploadDir . $filename;
        
        try {
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Delete old profile photo if it exists
                if ($profilePhoto) {
                    $oldPhotoPath = $uploadDir . $profilePhoto;
                    if (file_exists($oldPhotoPath)) {
                        unlink($oldPhotoPath);
                    }
                }
                
                // Update database with new profile photo
                $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                $stmt->execute([$filename, $_SESSION['user_id']]);
                
                // Update local variable
                $profilePhoto = $filename;
                $photoUrl = "../uploads/profile_photos/" . $filename;
                
                $message = 'Profile photo updated successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to upload file. Please try again.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = 'An error occurred while updating your profile photo.';
            $messageType = 'error';
            error_log($e->getMessage());
        }
    }
}

// Handle profile photo deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo']) && $profilePhoto) {
    try {
        // Delete photo file
        $uploadDir = '../uploads/profile_photos/';
        $photoPath = $uploadDir . $profilePhoto;
        if (file_exists($photoPath)) {
            unlink($photoPath);
        }
        
        // Update database
        $stmt = $pdo->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        // Update local variables
        $profilePhoto = null;
        $photoUrl = "../assets/images/default-avatar.png";
        
        $message = 'Profile photo removed successfully!';
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = 'An error occurred while removing your profile photo.';
        $messageType = 'error';
        error_log($e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Photo - SecureBank</title>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .profile-photo-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 2rem;
            background-color: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .profile-photo-header {
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .profile-photo-header h1 {
            color: var(--primary-color);
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .profile-photo-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
        }
        
        .profile-photo-preview {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            background-color: var(--background);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: var(--text-light);
        }
        
        .profile-photo-actions {
            display: flex;
            flex-direction: column;
            width: 100%;
            gap: 1rem;
        }
        
        .profile-photo-upload-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }
        
        .file-input-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-input-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background-color: var(--background);
            border: 1px dashed var(--border-color);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .file-input-label:hover {
            background-color: var(--border-color);
        }
        
        .file-name {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-light);
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .back-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.875rem;
            transition: var(--transition);
        }
        
        .back-link:hover {
            color: var(--primary-color);
        }
        
        @media (max-width: 768px) {
            .profile-photo-container {
                padding: 1.5rem;
            }
            
            .profile-photo-preview {
                width: 150px;
                height: 150px;
            }
            
            .action-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="profile-photo-container">
            <div class="profile-photo-header">
                <h1><i class="fas fa-camera"></i> Profile Photo</h1>
                <p>Upload or manage your profile photo</p>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div>
                        <strong><?php echo $messageType === 'success' ? 'Success!' : 'Error!'; ?></strong>
                        <p><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="profile-photo-content">
                <?php if ($profilePhoto): ?>
                    <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Profile Photo" class="profile-photo-preview">
                <?php else: ?>
                    <div class="profile-photo-preview">
                        <?php echo strtoupper(substr($customer['first_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                
                <div class="profile-photo-actions">
                    <form method="post" enctype="multipart/form-data" class="profile-photo-upload-form">
                        <div class="file-input-wrapper">
                            <label class="file-input-label">
                                <i class="fas fa-upload"></i> Choose a Photo
                                <input type="file" name="profile_photo" id="profile_photo" accept="image/jpeg,image/png,image/gif" onchange="updateFileName(this)">
                            </label>
                            <div class="file-name" id="file-name"></div>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Photo
                            </button>
                            
                            <?php if ($profilePhoto): ?>
                            <button type="submit" name="delete_photo" value="1" class="btn btn-danger">
                                <i class="fas fa-trash"></i> Delete Photo
                            </button>
                            <?php else: ?>
                            <button type="button" disabled class="btn btn-danger">
                                <i class="fas fa-trash"></i> Delete Photo
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
    
    <script>
        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : '';
            document.getElementById('file-name').textContent = fileName;
        }
    </script>
</body>
</html> 