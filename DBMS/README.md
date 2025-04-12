# Banking Management System

A comprehensive banking management system with separate interfaces for customers, employees, and managers. Built with PHP, MySQL, HTML, CSS, and JavaScript.

## Features

### Customer Features
- Account registration and login
- View account balance and transaction history
- Transfer money between accounts
- Deposit and withdraw funds
- Apply for loans

### Employee Features
- View and manage customer transactions
- Approve/reject transactions under $10,000
- Monitor customer activities

### Manager Features
- View total bank deposits
- Manage employee accounts
- Approve/reject high amount transfers (>$10,000)
- Approve/reject loan applications
- View employee details

## Prerequisites

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)
- Modern web browser

## Installation

1. Clone the repository:
```bash
git clone https://github.com/yourusername/banking-system.git
cd banking-system
```

2. Create a MySQL database and import the schema:
```bash
mysql -u root -p
CREATE DATABASE banking_system;
exit;
mysql -u root -p banking_system < database.sql
```

3. Configure the database connection:
   - Open `config/database.php`
   - Update the database credentials:
     ```php
     $host = 'localhost';
     $dbname = 'banking_system';
     $username = 'your_username';
     $password = 'your_password';
     ```

4. Set up the web server:
   - Point your web server's document root to the project directory
   - Ensure PHP has write permissions for the project directory

5. Access the application:
   - Open your web browser
   - Navigate to `http://localhost/banking-system`

## Default Accounts

The system comes with some default accounts for testing:

### Customer
- Username: john_doe
- Password: password123
- Role: customer

### Employee
- Username: emp1
- Password: password123
- Role: employee

### Manager
- Username: manager1
- Password: password123
- Role: manager

## Security Features

- Password hashing using PHP's password_hash()
- Session-based authentication
- Role-based access control
- SQL injection prevention using prepared statements
- XSS prevention using htmlspecialchars()
- CSRF protection for forms

## Directory Structure

```
banking-system/
├── auth/
│   ├── login.php
│   └── register.php
├── config/
│   └── database.php
├── customer/
│   ├── dashboard.php
│   ├── transfer.php
│   ├── deposit.php
│   └── withdraw.php
├── employee/
│   ├── dashboard.php
│   ├── approve_transaction.php
│   └── reject_transaction.php
├── manager/
│   ├── dashboard.php
│   ├── approve_transfer.php
│   ├── approve_loan.php
│   └── reject_loan.php
├── css/
│   └── style.css
├── js/
│   └── main.js
├── index.html
├── register.html
├── database.sql
└── README.md
```

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For support, email support@securebank.com or open an issue in the repository. 