document.addEventListener('DOMContentLoaded', function() {
    const photoInput = document.getElementById('photo-input');
    const previewImage = document.getElementById('preview-image');
    const uploadButton = document.getElementById('upload-button');
    const deleteButton = document.getElementById('delete-button');
    const errorMessage = document.getElementById('error-message');
    const successMessage = document.getElementById('success-message');

    // Handle file selection
    photoInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!allowedTypes.includes(file.type)) {
                showError('Invalid file type. Please select a JPEG, PNG, or GIF file.');
                return;
            }

            // Validate file size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                showError('File size too large. Maximum size is 5MB.');
                return;
            }

            // Preview image
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    // Handle upload button click
    uploadButton.addEventListener('click', function() {
        const file = photoInput.files[0];
        if (!file) {
            showError('Please select a file to upload.');
            return;
        }

        const formData = new FormData();
        formData.append('photo', file);

        // Show loading state
        uploadButton.disabled = true;
        uploadButton.textContent = 'Uploading...';

        // Send AJAX request
        fetch('upload-profile-photo.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccess(data.message);
                // Update preview image with new photo URL
                previewImage.src = data.photoUrl;
                // Clear file input
                photoInput.value = '';
            } else {
                showError(data.message);
            }
        })
        .catch(error => {
            showError('An error occurred while uploading the photo.');
            console.error('Error:', error);
        })
        .finally(() => {
            // Reset button state
            uploadButton.disabled = false;
            uploadButton.textContent = 'Upload Photo';
        });
    });

    // Handle delete button click
    deleteButton.addEventListener('click', function() {
        if (confirm('Are you sure you want to delete your profile photo?')) {
            fetch('delete-profile-photo.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message);
                    // Reset preview image to default
                    previewImage.src = 'assets/images/default-profile.png';
                } else {
                    showError(data.message);
                }
            })
            .catch(error => {
                showError('An error occurred while deleting the photo.');
                console.error('Error:', error);
            });
        }
    });

    // Helper functions for showing messages
    function showError(message) {
        errorMessage.textContent = message;
        errorMessage.style.display = 'block';
        successMessage.style.display = 'none';
        setTimeout(() => {
            errorMessage.style.display = 'none';
        }, 5000);
    }

    function showSuccess(message) {
        successMessage.textContent = message;
        successMessage.style.display = 'block';
        errorMessage.style.display = 'none';
        setTimeout(() => {
            successMessage.style.display = 'none';
        }, 5000);
    }
}); 