<?php
/**
 * Shared dashboard layout functions
 */

/**
 * Get dashboard statistics based on user role
 */
function getDashboardStats($pdo, $role, $userId) {
    $stats = [];
    
    switch ($role) {
        case 'customer':
            // Get customer stats
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(a.id) as total_accounts,
                    COALESCE(SUM(a.balance), 0) as total_balance,
                    COUNT(l.id) as total_loans,
                    COUNT(CASE WHEN l.status = 'pending' THEN 1 END) as pending_loans
                FROM customers c
                LEFT JOIN accounts a ON c.id = a.customer_id
                LEFT JOIN loans l ON c.id = l.customer_id
                WHERE c.user_id = ?
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'employee':
            // Get employee stats
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT c.id) as total_customers,
                    COUNT(DISTINCT a.id) as total_accounts,
                    COUNT(l.id) as total_loans,
                    COUNT(CASE WHEN l.status = 'pending' THEN 1 END) as pending_loans
                FROM staff s
                LEFT JOIN customers c ON s.id = c.id
                LEFT JOIN accounts a ON c.id = a.customer_id
                LEFT JOIN loans l ON c.id = l.customer_id
                WHERE s.user_id = ?
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'manager':
            // Get manager stats
            $stmt = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM customers) as total_customers,
                    (SELECT COUNT(*) FROM staff s JOIN users u ON s.user_id = u.id WHERE u.role = 'employee') as total_employees,
                    (SELECT COUNT(*) FROM accounts) as total_accounts,
                    (SELECT COUNT(*) FROM loans) as total_loans,
                    (SELECT COUNT(*) FROM loans WHERE status = 'pending') as pending_loans,
                    (SELECT COALESCE(SUM(amount), 0) FROM loans WHERE status = 'approved') as total_approved_amount,
                    (SELECT COALESCE(SUM(amount), 0) FROM loans WHERE status = 'pending') as total_pending_amount,
                    (SELECT COALESCE(SUM(balance), 0) FROM accounts) as total_assets,
                    (SELECT COUNT(*) FROM transactions WHERE status = 'pending') as pending_transactions
                FROM managers m
                WHERE m.user_id = ?
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
    }
    
    return $stats;
}

/**
 * Get recent activity based on user role
 */
function getRecentActivity($pdo, $role, $userId) {
    $activity = [];
    
    switch ($role) {
        case 'customer':
            // Get customer's recent transactions
            $stmt = $pdo->prepare("
                SELECT t.*, a.account_number,
                    CASE 
                        WHEN t.type = 'transfer' AND t.description LIKE 'To:%' THEN 
                            (SELECT CONCAT(c2.first_name, ' ', c2.last_name)
                             FROM transactions t2
                             JOIN accounts a2 ON t2.account_id = a2.id
                             JOIN customers c2 ON a2.customer_id = c2.id
                             WHERE t2.type = 'transfer'
                             AND t2.description LIKE ?
                             AND t2.amount = t.amount
                             AND t2.created_at = t.created_at
                             LIMIT 1)
                        ELSE NULL
                    END as sender_name
                FROM transactions t
                JOIN accounts a ON t.account_id = a.id
                JOIN customers c ON a.customer_id = c.id
                WHERE c.user_id = ?
                ORDER BY t.created_at DESC
                LIMIT 5
            ");
            $stmt->execute(['%To: ' . $userId . '%', $userId]);
            $activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'employee':
            // Get employee's recent loan applications
            $stmt = $pdo->prepare("
                SELECT l.*, c.first_name, c.last_name
                FROM loans l
                JOIN customers c ON l.customer_id = c.id
                JOIN staff s ON s.id = s.id
                WHERE s.user_id = ?
                ORDER BY l.created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            $activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'manager':
            // Get manager's recent loan applications
            $stmt = $pdo->prepare("
                SELECT l.*, c.first_name, c.last_name, 
                       s.first_name as employee_first_name, s.last_name as employee_last_name
                FROM loans l
                JOIN customers c ON l.customer_id = c.id
                JOIN staff s ON s.id = s.id
                WHERE l.status = 'pending'
                ORDER BY l.created_at DESC
                LIMIT 5
            ");
            $stmt->execute();
            $activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    return $activity;
}

/**
 * Render dashboard header
 */
function renderDashboardHeader($user, $role) {
    $title = ucfirst($role) . ' Dashboard';
    $name = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
    ?>
    <div class="page-header">
        <h1 class="page-title">Welcome, <?php echo $name; ?>!</h1>
        <div class="header-actions">
            <a href="edit_profile.php" class="header-btn">
                <i class="fas fa-user-edit"></i> Edit Profile
            </a>
            <a href="change_password.php" class="header-btn">
                <i class="fas fa-key"></i> Change Password
            </a>
        </div>
    </div>
    <?php
}

/**
 * Render profile photo section
 */
function renderProfilePhoto($user, $role) {
    ?>
    <div class="profile-section">
        <div class="profile-photo-container">
            <?php if (!empty($user['profile_photo'])): ?>
                <img src="../uploads/profile_photos/<?php echo htmlspecialchars($user['profile_photo']); ?>" 
                     alt="Profile Photo" 
                     class="profile-photo"
                     onerror="this.onerror=null; this.src='../assets/images/default-avatar.png';">
            <?php else: ?>
                <div class="profile-photo-initial">
                    <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="profile-info">
            <h2><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h2>
            <p class="role-badge"><?php echo ucfirst($role); ?></p>
        </div>
    </div>
    <style>
        .profile-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            background: #1a1d21;
            border-radius: 8px;
        }

        .profile-photo-container {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--primary-color-light);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
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
            font-size: 1rem;
            font-weight: 500;
            color: var(--primary-color);
            background: var(--primary-color-light);
        }

        .profile-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }

        .profile-info h2 {
            margin: 0;
            font-size: 0.95rem;
            color: #e4e6eb;
            font-weight: 500;
        }

        .role-badge {
            margin: 0;
            color: #b0b3b8;
            font-size: 0.8rem;
            font-weight: 400;
        }
    </style>
    <?php
}

/**
 * Render stats grid
 */
function renderStatsGrid($stats, $role) {
    ?>
    <div class="stats-grid">
        <?php if ($role === 'customer'): ?>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(67, 97, 238, 0.1); color: var(--primary-color);">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($stats['total_balance'] ?? 0, 2); ?></h3>
                    <p>Total Balance</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(76, 201, 240, 0.1); color: var(--success);">
                    <i class="fas fa-id-card"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_accounts'] ?? 0; ?></h3>
                    <p>Total Accounts</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(247, 37, 133, 0.1); color: var(--warning);">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_loans'] ?? 0; ?></h3>
                    <p>Total Loans</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 193, 7, 0.1); color: var(--warning);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pending_loans'] ?? 0; ?></h3>
                    <p>Pending Loans</p>
                </div>
            </div>
            
        <?php elseif ($role === 'employee'): ?>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(67, 97, 238, 0.1); color: var(--primary-color);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_customers'] ?? 0; ?></h3>
                    <p>Total Customers</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(76, 201, 240, 0.1); color: var(--success);">
                    <i class="fas fa-id-card"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_accounts'] ?? 0; ?></h3>
                    <p>Total Accounts</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(247, 37, 133, 0.1); color: var(--warning);">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_loans'] ?? 0; ?></h3>
                    <p>Total Loans</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 193, 7, 0.1); color: var(--warning);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pending_loans'] ?? 0; ?></h3>
                    <p>Pending Loans</p>
                </div>
            </div>
            
        <?php elseif ($role === 'manager'): ?>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(67, 97, 238, 0.1); color: var(--primary-color);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['total_customers'] ?? 0); ?></h3>
                    <p>Total Customers</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(76, 201, 240, 0.1); color: var(--success);">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['total_employees'] ?? 0); ?></h3>
                    <p>Total Employees</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(247, 37, 133, 0.1); color: var(--warning);">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($stats['total_assets'] ?? 0, 2); ?></h3>
                    <p>Total Assets</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 193, 7, 0.1); color: var(--warning);">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['pending_loans'] ?? 0); ?></h3>
                    <p>Pending Loans</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(220, 53, 69, 0.1); color: var(--danger);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['pending_transactions'] ?? 0); ?></h3>
                    <p>Pending Transactions</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render recent activity
 */
function renderRecentActivity($activity, $role) {
    ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-history"></i>
                Recent Activity
            </h2>
        </div>
        <div class="card-content">
            <?php if (empty($activity)): ?>
                <p>No recent activity found.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Type</th>
                            <?php if ($role === 'manager'): ?>
                                <th>Employee</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activity as $item): ?>
                            <tr>
                                <td><?php echo date('M d, Y H:i', strtotime($item['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?></td>
                                <td style="font-weight: 600;">$<?php echo number_format($item['amount'], 2); ?></td>
                                <td>
                                    <?php
                                    $statusClass = '';
                                    $statusIcon = '';
                                    switch($item['status']) {
                                        case 'approved':
                                            $statusClass = 'status-completed';
                                            $statusIcon = 'fa-check-circle';
                                            break;
                                        case 'pending':
                                            $statusClass = 'status-pending';
                                            $statusIcon = 'fa-clock';
                                            break;
                                        case 'rejected':
                                            $statusClass = 'status-rejected';
                                            $statusIcon = 'fa-times-circle';
                                            break;
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <i class="fas <?php echo $statusIcon; ?>"></i>
                                        <?php echo ucfirst($item['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo ucfirst($item['type']); ?></td>
                                <?php if ($role === 'manager'): ?>
                                    <td><?php echo htmlspecialchars($item['employee_first_name'] . ' ' . $item['employee_last_name']); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Render customer dashboard aside
 */
function renderCustomerAside($user, $active_page = 'dashboard') {
    ?>
    <aside class="dashboard-aside">
        <div class="aside-content">
            <div class="profile-section">
                <div class="profile-photo-container">
                    <?php if (!empty($user['profile_photo'])): ?>
                        <img src="../uploads/profile_photos/<?php echo htmlspecialchars($user['profile_photo']); ?>" 
                             alt="Profile Photo" 
                             class="profile-photo"
                             onerror="this.onerror=null; this.src='../assets/images/default-avatar.png';">
                    <?php else: ?>
                        <div class="profile-photo-initial">
                            <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                    <p class="role-badge">Customer</p>
                </div>
            </div>

            <nav class="aside-nav">
                <a href="dashboard.php" class="nav-item <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="accounts.php" class="nav-item <?php echo $active_page === 'accounts' ? 'active' : ''; ?>">
                    <i class="fas fa-wallet"></i>
                    <span>Accounts</span>
                </a>
                <a href="transfer.php" class="nav-item <?php echo $active_page === 'transfer' ? 'active' : ''; ?>">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Transfer</span>
                </a>
                <a href="transactions.php" class="nav-item <?php echo $active_page === 'transactions' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span>Transactions</span>
                </a>
                <a href="loans.php" class="nav-item <?php echo $active_page === 'loans' ? 'active' : ''; ?>">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Loans</span>
                </a>
            </nav>

            <div class="aside-footer">
                <a href="edit_profile.php" class="nav-item <?php echo $active_page === 'profile' ? 'active' : ''; ?>">
                    <i class="fas fa-user-edit"></i>
                    <span>Edit Profile</span>
                </a>
                <a href="../logout.php" class="nav-item logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </aside>
    <style>
        .dashboard-aside {
            width: 240px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: #1a1d21;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }

        .aside-content {
            padding: 1rem;
            display: flex;
            flex-direction: column;
            height: 100%;
            gap: 1.5rem;
        }

        .profile-section {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 6px;
            transition: background-color 0.2s ease;
        }

        .profile-photo-container {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
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
            font-size: 0.85rem;
            font-weight: 500;
            color: #fff;
            background: #4f46e5;
        }

        .profile-info {
            flex: 1;
            min-width: 0;
        }

        .profile-info h2 {
            margin: 0;
            font-size: 0.85rem;
            color: #e4e6eb;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .role-badge {
            margin: 0;
            color: #b0b3b8;
            font-size: 0.7rem;
            font-weight: 400;
        }

        .aside-nav {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 0.75rem;
            color: #b0b3b8;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #e4e6eb;
        }

        .nav-item.active {
            background: rgba(255, 255, 255, 0.06);
            color: #e4e6eb;
            font-weight: 500;
        }

        .nav-item i {
            font-size: 1rem;
            width: 18px;
            text-align: center;
        }

        .aside-footer {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding-top: 0.75rem;
            margin-top: 0.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .nav-item.logout {
            color: #ef4444;
        }

        .nav-item.logout:hover {
            background: rgba(239, 68, 68, 0.1);
        }

        /* Adjust main content margin */
        .dashboard-content {
            margin-left: 240px;
            padding: 1.5rem;
            min-height: 100vh;
            background: #0f1012;
        }

        /* Dark theme adjustments */
        body {
            background: #0f1012;
            color: #e4e6eb;
        }
    </style>
    <?php
} 