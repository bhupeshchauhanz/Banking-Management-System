<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    // Create PDO connection with error handling
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // Get monthly transaction data for the last 6 months
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(created_at, '%b') as month,
            COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as deposits,
            COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END), 0) as withdrawals,
            COALESCE(SUM(CASE WHEN type = 'transfer' THEN amount ELSE 0 END), 0) as transfers
        FROM transactions
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        AND status = 'completed'
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY created_at DESC
        LIMIT 6
    ");
    
    if (!$stmt) {
        throw new Exception("Failed to execute transaction query");
    }
    
    $transactionData = $stmt->fetchAll();
    
    // If no transaction data, create empty data structure
    if (empty($transactionData)) {
        $transactionData = [
            ['month' => 'Jan', 'deposits' => 0, 'withdrawals' => 0, 'transfers' => 0],
            ['month' => 'Feb', 'deposits' => 0, 'withdrawals' => 0, 'transfers' => 0],
            ['month' => 'Mar', 'deposits' => 0, 'withdrawals' => 0, 'transfers' => 0],
            ['month' => 'Apr', 'deposits' => 0, 'withdrawals' => 0, 'transfers' => 0],
            ['month' => 'May', 'deposits' => 0, 'withdrawals' => 0, 'transfers' => 0],
            ['month' => 'Jun', 'deposits' => 0, 'withdrawals' => 0, 'transfers' => 0]
        ];
    }

    // Get account type distribution
    $stmt = $pdo->query("
        SELECT 
            type,
            COUNT(*) as count
        FROM accounts
        WHERE status = 'active'
        GROUP BY type
        ORDER BY count DESC
    ");
    
    if (!$stmt) {
        throw new Exception("Failed to execute account query");
    }
    
    $accountData = $stmt->fetchAll();
    
    // If no account data, create empty data structure
    if (empty($accountData)) {
        $accountData = [
            ['type' => 'Savings', 'count' => 0],
            ['type' => 'Checking', 'count' => 0],
            ['type' => 'Investment', 'count' => 0]
        ];
    }

    // Prepare the response data
    $response = [
        'months' => array_column($transactionData, 'month'),
        'deposits' => array_column($transactionData, 'deposits'),
        'withdrawals' => array_column($transactionData, 'withdrawals'),
        'transfers' => array_column($transactionData, 'transfers'),
        'accountTypes' => array_column($accountData, 'type'),
        'accountCounts' => array_column($accountData, 'count')
    ];

    // Send JSON response
    header('Content-Type: application/json');
    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Database Error in get_chart_data.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("General Error in get_chart_data.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'An error occurred while fetching data']);
}
?> 