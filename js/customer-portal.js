/**
 * Customer Portal - Shared JavaScript Utilities
 * Light theme enhancements and UX improvements
 */

// Toast Notification System
function showToast(message, type = 'success') {
    // Remove existing toast
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    const icon = type === 'success' ? '✓' : type === 'error' ? '⚠️' : 'ℹ️';
    toast.innerHTML = `<span class="toast-icon">${icon}</span><span class="toast-message">${message}</span>`;

    document.body.appendChild(toast);

    // Trigger animation
    setTimeout(() => toast.classList.add('show'), 10);

    // Auto-remove after 4 seconds
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Loading State for Buttons
function setButtonLoading(button, isLoading) {
    if (isLoading) {
        button.disabled = true;
        button.dataset.originalText = button.innerHTML;
        button.innerHTML = '<span class="btn-loading"></span><span>Processing...</span>';
    } else {
        button.disabled = false;
        button.innerHTML = button.dataset.originalText || button.innerHTML;
    }
}

// Form Validation Helper
function validateForm(form) {
    const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
    let isValid = true;

    inputs.forEach(input => {
        const errorElement = input.parentElement.querySelector('.field-error');
        if (errorElement) errorElement.remove();

        if (!input.value.trim()) {
            isValid = false;
            showFieldError(input, 'This field is required');
        } else if (input.type === 'email' && !isValidEmail(input.value)) {
            isValid = false;
            showFieldError(input, 'Please enter a valid email address');
        } else if (input.type === 'tel' && input.value.length < 8) {
            isValid = false;
            showFieldError(input, 'Please enter a valid phone number');
        } else if (input.minLength && input.value.length < input.minLength) {
            isValid = false;
            showFieldError(input, `Minimum ${input.minLength} characters required`);
        }
    });

    return isValid;
}

function showFieldError(input, message) {
    input.classList.add('input-error');
    const errorEl = document.createElement('div');
    errorEl.className = 'field-error';
    errorEl.textContent = message;
    input.parentElement.appendChild(errorEl);
}

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

// Character Counter for Textareas
function initCharacterCounter(textarea, maxLength) {
    const counter = document.createElement('div');
    counter.className = 'char-counter';
    counter.textContent = `0 / ${maxLength}`;
    textarea.parentElement.appendChild(counter);

    textarea.addEventListener('input', () => {
        const length = textarea.value.length;
        counter.textContent = `${length} / ${maxLength}`;
        counter.style.color = length > maxLength * 0.9 ? '#ef4444' : '#6b7280';
    });
}

// Confirm Dialog for Destructive Actions
function confirmAction(message) {
    return confirm(message);
}

// Debounce Helper
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Show/Hide Password Toggle
function initPasswordToggle(passwordInput) {
    const toggleBtn = document.createElement('button');
    toggleBtn.type = 'button';
    toggleBtn.className = 'password-toggle';
    toggleBtn.innerHTML = '👁️';
    toggleBtn.setAttribute('aria-label', 'Toggle password visibility');

    passwordInput.parentElement.style.position = 'relative';
    passwordInput.style.paddingRight = '45px';
    passwordInput.parentElement.appendChild(toggleBtn);

    toggleBtn.addEventListener('click', () => {
        const type = passwordInput.type === 'password' ? 'text' : 'password';
        passwordInput.type = type;
        toggleBtn.innerHTML = type === 'password' ? '👁️' : '🙈';
    });
}

// Smooth Scroll to Element
function scrollToElement(element, offset = 80) {
    const elementPosition = element.getBoundingClientRect().top;
    const offsetPosition = elementPosition + window.pageYOffset - offset;

    window.scrollTo({
        top: offsetPosition,
        behavior: 'smooth'
    });
}

// Mobile Hamburger Menu Toggle
function initHamburgerMenu() {
    const hamburgerBtn = document.getElementById('hamburger-btn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const main = document.getElementById('main-content') || document.querySelector('.main');

    console.log('Hamburger Menu Init:', {
        hamburgerBtn: !!hamburgerBtn,
        sidebar: !!sidebar,
        overlay: !!overlay,
        main: !!main
    });

    if (!hamburgerBtn || !sidebar || !overlay) {
        console.error('Missing elements for hamburger menu');
        return;
    }

    // Prevent scroll chaining on sidebar
    let lastScrollTop = 0;
    sidebar.addEventListener('touchstart', function(e) {
        lastScrollTop = this.scrollTop;
    }, { passive: true });

    sidebar.addEventListener('touchmove', function(e) {
        const scrollTop = this.scrollTop;
        const scrollHeight = this.scrollHeight;
        const offsetHeight = this.offsetHeight;
        const isAtTop = scrollTop === 0;
        const isAtBottom = scrollTop + offsetHeight >= scrollHeight;

        // Prevent overscroll bounce/chaining
        if ((isAtTop && scrollTop < lastScrollTop) || (isAtBottom && scrollTop > lastScrollTop)) {
            e.preventDefault();
        }
        lastScrollTop = scrollTop;
    }, { passive: false });

    // Toggle menu
    function toggleMenu(isOpen) {
        console.log('Toggle menu:', isOpen);
        if (isOpen) {
            sidebar.classList.add('active');
            overlay.classList.add('active');
            if (main) main.classList.add('sidebar-open');
            hamburgerBtn.classList.add('active');
            document.body.style.overflow = 'hidden'; // Prevent body scroll when menu open
        } else {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            if (main) main.classList.remove('sidebar-open');
            hamburgerBtn.classList.remove('active');
            document.body.style.overflow = ''; // Restore body scroll
        }
    }

    // Open menu
    hamburgerBtn.addEventListener('click', (e) => {
        console.log('Hamburger button clicked');
        e.preventDefault();
        e.stopPropagation();
        const isOpen = sidebar.classList.contains('active');
        toggleMenu(!isOpen);
    });

    // Close menu when clicking overlay
    overlay.addEventListener('click', () => {
        console.log('Overlay clicked');
        toggleMenu(false);
    });

    // Close menu when clicking a nav link
    const navLinks = sidebar.querySelectorAll('.nav-links a');
    navLinks.forEach(link => {
        link.addEventListener('click', () => {
            // Small delay so user sees the click before menu closes
            setTimeout(() => toggleMenu(false), 150);
        });
    });

    // Close menu on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            toggleMenu(false);
        }
    });

    // Handle window resize - close menu if switching to desktop
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (window.innerWidth > 900 && sidebar.classList.contains('active')) {
                toggleMenu(false);
            }
        }, 100);
    });

    console.log('Hamburger menu initialized successfully');
}

// Initialize all enhancements on page load
document.addEventListener('DOMContentLoaded', () => {
    // Initialize hamburger menu
    initHamburgerMenu();
    // Add loading states to all forms
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                if (validateForm(this)) {
                    setButtonLoading(submitBtn, true);
                } else {
                    e.preventDefault();
                }
            }
        });
    });

    // Initialize character counters
    document.querySelectorAll('textarea[maxlength]').forEach(textarea => {
        initCharacterCounter(textarea, parseInt(textarea.getAttribute('maxlength')));
    });

    // Initialize password toggles
    document.querySelectorAll('input[type="password"]').forEach(input => {
        if (input.id && !input.classList.contains('no-toggle')) {
            initPasswordToggle(input);
        }
    });

    // Add confirmation to destructive actions
    document.querySelectorAll('[data-confirm]').forEach(element => {
        element.addEventListener('click', function(e) {
            if (!confirmAction(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // Real-time validation on blur
    document.querySelectorAll('input[required], textarea[required]').forEach(input => {
        input.addEventListener('blur', () => {
            if (input.value.trim() === '') {
                input.classList.add('input-error');
            } else {
                input.classList.remove('input-error');
                const errorEl = input.parentElement.querySelector('.field-error');
                if (errorEl) errorEl.remove();
            }
        });

        input.addEventListener('input', () => {
            if (input.value.trim() !== '') {
                input.classList.remove('input-error');
                const errorEl = input.parentElement.querySelector('.field-error');
                if (errorEl) errorEl.remove();
            }
        });
    });

    // Add fade-in animation to cards
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.05}s`;
        card.classList.add('fade-in');
    });
});

// Global CSS for JS enhancements
const style = document.createElement('style');
style.textContent = `
/* Toast Notifications */
.toast {
    position: fixed;
    top: 80px;
    right: 20px;
    padding: 16px 20px;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    transform: translateX(400px);
    transition: transform 0.3s ease-out;
    z-index: 10000;
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 300px;
    max-width: 500px;
}
.toast.show {
    transform: translateX(0);
}
.toast-success {
    background: #10b981;
    color: #ffffff;
    border: 1px solid #059669;
}
.toast-error {
    background: #ef4444;
    color: #ffffff;
    border: 1px solid #dc2626;
}
.toast-info {
    background: #3b82f6;
    color: #ffffff;
    border: 1px solid #2563eb;
}
.toast-icon {
    font-size: 1.25rem;
    flex-shrink: 0;
}
.toast-message {
    flex: 1;
    font-weight: 600;
    font-size: 0.95rem;
}

/* Button Loading State */
.btn-loading {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Field Validation */
.input-error {
    border-color: #ef4444 !important;
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1) !important;
}
.field-error {
    color: #ef4444;
    font-size: 0.85rem;
    margin-top: 6px;
    font-weight: 500;
}

/* Character Counter */
.char-counter {
    text-align: right;
    font-size: 0.8rem;
    color: #6b7280;
    margin-top: 6px;
    font-weight: 500;
}

/* Password Toggle */
.password-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background 0.2s;
}
.password-toggle:hover {
    background: rgba(0, 0, 0, 0.05);
}

/* Fade In Animation */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.fade-in {
    animation: fadeIn 0.4s ease-out forwards;
}

/* Skeleton Loader */
.skeleton {
    background: linear-gradient(90deg, #e5e7eb 25%, #f3f4f6 50%, #e5e7eb 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: 8px;
}
@keyframes shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Mobile: Toast at bottom */
@media (max-width: 640px) {
    .toast {
        top: auto;
        bottom: 20px;
        left: 20px;
        right: 20px;
        transform: translateY(200px);
        min-width: auto;
    }
    .toast.show {
        transform: translateY(0);
    }
}
`;
document.head.appendChild(style);
