-- Create Database
CREATE DATABASE IF NOT EXISTS banking_system;
USE banking_system;

-- Create Users Table (simplified)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('customer', 'employee', 'manager') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_username (username),
    INDEX idx_users_role (role)
);

-- Create Customers Table (sensitive data no longer encrypted)
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address TEXT,
    date_of_birth DATE,
    gender ENUM('male', 'female', 'other') DEFAULT 'other',
    national_id VARCHAR(255) NOT NULL,
    occupation VARCHAR(100),
    annual_income DECIMAL(15,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_customers_email (email)
);

-- Create Managers Table
CREATE TABLE IF NOT EXISTS managers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create Staff/Employees Table
CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address TEXT,
    designation VARCHAR(100) NOT NULL,
    department VARCHAR(50),
    salary DECIMAL(15,2) NOT NULL,
    hire_date DATE NOT NULL,
    employee_id VARCHAR(20) UNIQUE NOT NULL,
    date_of_birth DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create Employees Table (alternate structure from some files)
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    manager_id INT NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    address TEXT,
    date_of_birth DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (manager_id) REFERENCES managers(id) ON DELETE CASCADE
);

-- Create Accounts Table (simplified)
CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    account_number VARCHAR(20) UNIQUE NOT NULL,
    account_type ENUM('savings', 'checking', 'investment') NOT NULL,
    balance DECIMAL(15,2) DEFAULT 0.00,
    interest_rate DECIMAL(5,2) DEFAULT 0.00,
    status ENUM('active', 'inactive') DEFAULT 'active',
    opening_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_accounts_customer_id (customer_id),
    INDEX idx_accounts_account_number (account_number)
);

-- Create Transactions Table (simplified)
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    recipient_account_id INT DEFAULT NULL,
    type ENUM('deposit', 'withdrawal', 'transfer', 'loan', 'interest', 'fee') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
    description TEXT,
    reference_number VARCHAR(50) UNIQUE,
    processed_by INT,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_transactions_account_id (account_id),
    INDEX idx_transactions_created_at (created_at)
);

-- Create Loans Table
CREATE TABLE IF NOT EXISTS loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    account_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL,
    term_months INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'active', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- Add indexes for better performance
CREATE INDEX idx_loans_customer_id ON loans(customer_id);
CREATE INDEX idx_loans_account_id ON loans(account_id);
CREATE INDEX idx_loans_status ON loans(status);
CREATE INDEX idx_loans_created_at ON loans(created_at);

-- Create Loan Payments Table
CREATE TABLE IF NOT EXISTS loan_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'completed', 'missed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
);

-- Create Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create Settings Table (General App Settings)
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create Bank Settings Table (Banking System Configuration - simplified)
CREATE TABLE IF NOT EXISTS bank_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    min_transfer_amount DECIMAL(10,2) DEFAULT 1.00,
    max_transfer_amount DECIMAL(10,2) DEFAULT 10000.00,
    employee_approval_limit DECIMAL(10,2) DEFAULT 5000.00,
    manager_approval_limit DECIMAL(15,2) DEFAULT 1000000.00,
    savings_interest_rate DECIMAL(5,2) DEFAULT 2.50,
    checking_interest_rate DECIMAL(5,2) DEFAULT 0.25,
    loan_max_amount_percent INT DEFAULT 80,
    transaction_fee DECIMAL(5,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default manager account
INSERT INTO users (username, password, role) VALUES 
('manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager'),
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager')
ON DUPLICATE KEY UPDATE username = username;

-- Insert default employee account
INSERT INTO users (username, password, role) VALUES 
('employee', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee')
ON DUPLICATE KEY UPDATE username = username;

-- Insert default customer account
INSERT INTO users (username, password, role) VALUES 
('customer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer')
ON DUPLICATE KEY UPDATE username = username;

-- Insert default manager details
INSERT INTO managers (user_id, first_name, last_name, email, phone, address)
SELECT id, 'Admin', 'User', 'admin@securebank.com', '1234567890', 'Bank Headquarters'
FROM users WHERE username = 'admin' AND role = 'manager'
ON DUPLICATE KEY UPDATE email = email;

-- Insert default manager details
INSERT INTO staff (user_id, first_name, last_name, email, phone, designation, department, salary, hire_date, employee_id) VALUES 
(1, 'Admin', 'Manager', 'manager@securebank.com', '9876543210', 'Branch Manager', 'Management', 75000.00, '2020-01-01', 'MGR001'),
(2, 'Bhupesh', 'Chauhan', 'employee@securebank.com', '8765432109', 'Customer Service Representative', 'Operations', 45000.00, '2021-06-15', 'EMP001');

-- Insert default customer details
INSERT INTO customers (user_id, first_name, last_name, email, phone, date_of_birth, gender, national_id) VALUES 
(3, 'Bhupesh', 'Chauhan', 'bhupesh@securebank.com', '1234567890', '1990-01-01', 'male', 'ID123456');

-- Insert default account for customer
INSERT INTO accounts (customer_id, account_number, account_type, balance) VALUES 
(1, '1000000001', 'savings', 1000.00);

-- Insert default settings (general app settings)
INSERT INTO settings (setting_key, setting_value, description) VALUES
('app_name', 'SecureBank', 'Application name'),
('app_logo', 'logo.png', 'Application logo'),
('maintenance_mode', 'false', 'Maintenance mode enabled/disabled'),
('support_email', 'support@securebank.com', 'Support email address'),
('support_phone', '1-800-123-4567', 'Support phone number'),
('system_timezone', 'UTC', 'System timezone');

-- Insert default bank settings
INSERT INTO bank_settings (
    min_transfer_amount,
    max_transfer_amount,
    employee_approval_limit,
    manager_approval_limit,
    savings_interest_rate,
    checking_interest_rate,
    loan_max_amount_percent,
    transaction_fee
) VALUES (
    10.00,
    100000.00,
    50000.00,
    1000000.00,
    1.50,
    0.50,
    80,
    0.00
);

-- Add constraints for data integrity
ALTER TABLE accounts ADD CONSTRAINT chk_balance_non_negative CHECK (balance >= 0);
ALTER TABLE transactions ADD CONSTRAINT chk_amount_positive CHECK (amount > 0);
ALTER TABLE loan_payments ADD CONSTRAINT chk_payment_amount_positive CHECK (amount > 0);
ALTER TABLE loans ADD CONSTRAINT chk_loan_amount_positive CHECK (amount > 0);
ALTER TABLE loans ADD CONSTRAINT chk_loan_term_positive CHECK (term_months > 0);