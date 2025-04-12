// Form validation
document.addEventListener('DOMContentLoaded', function() {
    // Login form validation
    const loginForm = document.querySelector('.login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
            }
        });
    }
    
    // Registration form validation
    const registerForm = document.querySelector('form[action="auth/register.php"]');
    if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
            }
            
            // Validate phone number format
            const phone = document.getElementById('phone').value;
            const phoneRegex = /^\d{10}$/;
            if (!phoneRegex.test(phone)) {
                e.preventDefault();
                alert('Please enter a valid 10-digit phone number');
            }
        });
    }
    
    // Check for URL parameters for error/success messages
    const urlParams = new URLSearchParams(window.location.search);
    const error = urlParams.get('error');
    const success = urlParams.get('success');
    
    if (error) {
        showMessage(error, 'error');
    }
    
    if (success) {
        showMessage(success, 'success');
    }
});

// Function to display messages
function showMessage(message, type) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${type}`;
    messageDiv.textContent = message;
    
    const container = document.querySelector('.container');
    container.insertBefore(messageDiv, container.firstChild);
    
    // Remove message after 5 seconds
    setTimeout(() => {
        messageDiv.remove();
    }, 5000);
}

// Function to format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount);
}

// Function to confirm actions
function confirmAction(message) {
    return confirm(message);
}

// Add event listeners for transaction buttons
document.addEventListener('DOMContentLoaded', function() {
    const transferBtn = document.querySelector('button[onclick="location.href=\'transfer.php\'"]');
    const depositBtn = document.querySelector('button[onclick="location.href=\'deposit.php\'"]');
    const withdrawBtn = document.querySelector('button[onclick="location.href=\'withdraw.php\'"]');
    const loanBtn = document.querySelector('button[onclick="location.href=\'apply_loan.php\'"]');
    
    if (transferBtn) {
        transferBtn.addEventListener('click', function(e) {
            if (!confirmAction('Do you want to transfer money?')) {
                e.preventDefault();
            }
        });
    }
    
    if (depositBtn) {
        depositBtn.addEventListener('click', function(e) {
            if (!confirmAction('Do you want to make a deposit?')) {
                e.preventDefault();
            }
        });
    }
    
    if (withdrawBtn) {
        withdrawBtn.addEventListener('click', function(e) {
            if (!confirmAction('Do you want to make a withdrawal?')) {
                e.preventDefault();
            }
        });
    }
    
    if (loanBtn) {
        loanBtn.addEventListener('click', function(e) {
            if (!confirmAction('Do you want to apply for a loan?')) {
                e.preventDefault();
            }
        });
    }
}); 