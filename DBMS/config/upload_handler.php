<?php
session_start();
require_once 'database.php';

class UploadHandler {
    private $uploadDir = '../uploads/profile_photos/';
    private $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    private $maxFileSize = 5242880; // 5MB
    private $db;

    public function __construct($db) {
        $this->db = $db;
        
        // Create upload directory if it doesn't exist
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    public function handleUpload($file, $userId) {
        try {
            // Validate file
            $this->validateFile($file);

            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('profile_') . '.' . $extension;
            $filepath = $this->uploadDir . $filename;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('Failed to move uploaded file.');
            }

            // Update database
            $stmt = $this->db->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
            $stmt->execute([$filename, $userId]);

            return [
                'success' => true,
                'filename' => $filename,
                'message' => 'Profile photo uploaded successfully.'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function validateFile($file) {
        // Check if file was uploaded
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            throw new Exception('No file was uploaded.');
        }

        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            throw new Exception('File size exceeds limit of 5MB.');
        }

        // Check file type
        if (!in_array($file['type'], $this->allowedTypes)) {
            throw new Exception('Invalid file type. Only JPG, PNG and GIF are allowed.');
        }

        // Additional security checks
        if (!getimagesize($file['tmp_name'])) {
            throw new Exception('Invalid image file.');
        }
    }

    public function deleteProfilePhoto($userId) {
        try {
            // Get current photo filename
            $stmt = $this->db->prepare("SELECT profile_photo FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && $result['profile_photo']) {
                $filepath = $this->uploadDir . $result['profile_photo'];
                
                // Delete file if exists
                if (file_exists($filepath)) {
                    unlink($filepath);
                }

                // Update database
                $stmt = $this->db->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?");
                $stmt->execute([$userId]);

                return [
                    'success' => true,
                    'message' => 'Profile photo deleted successfully.'
                ];
            }

            return [
                'success' => false,
                'message' => 'No profile photo found.'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function getProfilePhotoUrl($filename) {
        if (!$filename) {
            return '../assets/images/default-profile.png';
        }
        return $this->uploadDir . $filename;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadHandler = new UploadHandler($conn);
    $response = [];

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'upload':
                if (isset($_FILES['profile_photo']) && isset($_POST['user_id'])) {
                    $response = $uploadHandler->handleUpload($_FILES['profile_photo'], $_POST['user_id']);
                }
                break;

            case 'delete':
                if (isset($_POST['user_id'])) {
                    $response = $uploadHandler->deleteProfilePhoto($_POST['user_id']);
                }
                break;
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?> 