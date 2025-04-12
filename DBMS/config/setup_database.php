<?php
require_once 'database.php';

try {
    // First, create the database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS banking_system");
    $pdo->exec("USE banking_system");
    
    // Read SQL file
    $sql = file_get_contents(__DIR__ . '/create_tables.sql');
    
    // Split SQL file into individual queries
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    
    // Execute each query
    foreach ($queries as $query) {
        if (!empty($query)) {
            $pdo->exec($query);
        }
    }
    
    // Create a default manager user
    $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
    
    // First create the user
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password, role) 
        VALUES (?, ?, 'manager')
    ");
    $stmt->execute(['admin', $hashed_password]);
    $user_id = $pdo->lastInsertId();
    
    // Then create the manager
    $stmt = $pdo->prepare("
        INSERT INTO managers (user_id, first_name, last_name, email) 
        VALUES (?, 'Admin', 'Manager', 'admin@securebank.com')
    ");
    $stmt->execute([$user_id]);
    
    echo "Database tables created successfully!<br>";
    echo "Default manager account created:<br>";
    echo "Username: admin<br>";
    echo "Password: admin123<br>";
    echo "<a href='../index.php'>Go to Login</a>";
} catch (PDOException $e) {
    die("Error setting up database: " . $e->getMessage());
}
?> 