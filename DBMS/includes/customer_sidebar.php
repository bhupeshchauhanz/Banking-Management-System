<?php
if (!function_exists('renderCustomerSidebar')) {
    function renderCustomerSidebar($customer, $activePage = 'dashboard') {
        ?>
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-university"></i>
                    <h1>SecureBank</h1>
                </div>
            </div>

            <div class="profile-section">
                <?php if (!empty($customer['profile_photo'])): ?>
                    <img src="../uploads/profile_photos/<?php echo htmlspecialchars($customer['profile_photo']); ?>" 
                         alt="Profile Photo" 
                         class="profile-photo"
                         loading="lazy">
                <?php else: ?>
                    <div class="profile-photo-initial">
                        <?php echo strtoupper(substr($customer['first_name'] ?? 'U', 0, 1)); ?>
                    </div>
                <?php endif; ?>
                
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars(($customer['first_name'] ?? 'Unknown') . ' ' . ($customer['last_name'] ?? 'User')); ?></h2>
                    <p>Customer</p>
                </div>
            </div>

            <nav class="sidebar-menu">
                <a href="dashboard.php" class="menu-item <?php echo $activePage === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="transfer.php" class="menu-item <?php echo $activePage === 'transfer' ? 'active' : ''; ?>">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Transfer Money</span>
                </a>
                <a href="transaction_history.php" class="menu-item <?php echo $activePage === 'history' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span>Transaction History</span>
                </a>
                <a href="apply_loan.php" class="menu-item <?php echo $activePage === 'loan' ? 'active' : ''; ?>">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Apply for Loan</span>
                </a>
                <a href="my_loans.php" class="menu-item <?php echo $activePage === 'my_loans' ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>My Loans</span>
                </a>
                <a href="edit_profile.php" class="menu-item <?php echo $activePage === 'profile' ? 'active' : ''; ?>">
                    <i class="fas fa-user-edit"></i>
                    <span>Edit Profile</span>
                </a>
                <a href="change_password.php" class="menu-item <?php echo $activePage === 'password' ? 'active' : ''; ?>">
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
        <?php
    }
}
?> 