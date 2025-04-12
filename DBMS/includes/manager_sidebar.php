<?php
// Get the current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
?>

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
            <p>Manager</p>
        </div>
    </div>
    
    <nav class="sidebar-menu">
        <a href="dashboard.php" class="menu-item <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        <a href="employees.php" class="menu-item <?php echo $current_page === 'employees.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-tie"></i>
            <span>Employees</span>
        </a>
        <a href="customers.php" class="menu-item <?php echo $current_page === 'customers.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <span>Customers</span>
        </a>
        <a href="accounts.php" class="menu-item <?php echo $current_page === 'accounts.php' ? 'active' : ''; ?>">
            <i class="fas fa-wallet"></i>
            <span>Accounts</span>
        </a>
        <a href="loans.php" class="menu-item <?php echo $current_page === 'loans.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Loans</span>
        </a>
        <a href="transactions.php" class="menu-item <?php echo $current_page === 'transactions.php' ? 'active' : ''; ?>">
            <i class="fas fa-exchange-alt"></i>
            <span>Transactions</span>
        </a>
        <a href="reports.php" class="menu-item <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
        <a href="edit_profile.php" class="menu-item <?php echo $current_page === 'edit_profile.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-edit"></i>
            <span>Edit Profile</span>
        </a>
        <a href="change_password.php" class="menu-item <?php echo $current_page === 'change_password.php' ? 'active' : ''; ?>">
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

<style>
    /* Sidebar Styles */
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
        box-shadow: var(--shadow-md);
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
    
    .profile-photo-container {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        overflow: hidden;
        background-color: #f0f0f0;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 15px;
        border: 3px solid var(--primary-color);
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
        background-color: var(--primary-color);
        color: white;
        font-size: 2rem;
        font-weight: bold;
    }
    
    .profile-info h2 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 5px;
        color: white;
    }
    
    .profile-info p {
        font-size: 0.85rem;
        color: var(--text-secondary);
    }
    
    .sidebar-menu {
        margin-top: 30px;
        flex: 1;
        overflow-y: auto;
        padding-right: 10px;
    }
    
    .menu-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px 30px;
        color: var(--text-secondary);
        text-decoration: none;
        transition: var(--transition);
        position: relative;
        border-radius: var(--radius-sm);
        margin-bottom: 5px;
    }
    
    .menu-item:hover, .menu-item.active {
        color: white;
        background: rgba(255,255,255,0.1);
    }
    
    .menu-item.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 4px;
        background: var(--primary-color);
    }
    
    .menu-item i {
        font-size: 1.2rem;
        width: 24px;
        text-align: center;
    }
    
    .sidebar-footer {
        padding: 30px;
        border-top: 1px solid rgba(255,255,255,0.1);
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

    /* Custom Scrollbar */
    .sidebar-menu::-webkit-scrollbar {
        width: 6px;
    }
    
    .sidebar-menu::-webkit-scrollbar-track {
        background: rgba(255,255,255,0.1);
        border-radius: 3px;
    }
    
    .sidebar-menu::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.2);
        border-radius: 3px;
    }
    
    .sidebar-menu::-webkit-scrollbar-thumb:hover {
        background: rgba(255,255,255,0.3);
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
        
        .profile-photo-container {
            width: 40px;
            height: 40px;
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
</style> 