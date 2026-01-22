/**
 * Materials Management System - JavaScript
 * Minimal enhancements for better UX
 */

document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate slug from section name
    const nameInput = document.getElementById('name');
    const slugInput = document.getElementById('slug');
    
    if (nameInput && slugInput) {
        nameInput.addEventListener('input', function() {
            // Only auto-generate if slug is empty or was auto-generated
            if (!slugInput.dataset.manual) {
                slugInput.value = generateSlug(this.value);
            }
        });
        
        slugInput.addEventListener('input', function() {
            // Mark as manually edited
            this.dataset.manual = this.value !== generateSlug(nameInput.value);
        });
    }
    
    // Confirm delete actions
    document.querySelectorAll('.delete').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
    });
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
});

/**
 * Generate SEO-friendly slug from text
 */
function generateSlug(text) {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * Toggle file fields based on file type selection
 * Called from admin.php inline script
 */
// function toggleFileFields() {
//     const type = document.getElementById('file_type');
//     const gdriveField = document.getElementById('gdrive-field');
//     const imageField = document.getElementById('image-field');
    
//     if (!type || !gdriveField || !imageField) return;
    
//     const value = type.value;
//     gdriveField.style.display = (value === 'gdrive_pdf' || value === 'gdrive_word') ? 'block' : 'none';
//     imageField.style.display = value === 'image' ? 'block' : 'none';
// }

/**
 * Preview uploaded image before submit
 */
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            let preview = input.parentElement.querySelector('.preview-img');
            if (!preview) {
                preview = document.createElement('img');
                preview.className = 'preview-img';
                preview.style.cssText = 'max-width: 200px; margin-top: 1rem; border-radius: 8px;';
                input.parentElement.appendChild(preview);
            }
            preview.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Add image preview listener if image input exists
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('image');
    if (imageInput) {
        imageInput.addEventListener('change', function() {
            previewImage(this);
        });
    }
});
