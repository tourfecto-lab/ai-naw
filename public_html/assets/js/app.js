/**
 * Tourfecto - Main JavaScript
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

(function() {
    'use strict';

    // ============================================
    // 1. DOM Ready
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        initApp();
    });

    // ============================================
    // 2. Initialize App
    // ============================================
    function initApp() {
        initNavigation();
        initForms();
        initAlerts();
        initModals();
        initTooltips();
        initLoadingSpinner();
        initThemeToggle();
    }

    // ============================================
    // 3. Navigation
    // ============================================
    function initNavigation() {
        // Active link highlighting
        const currentPath = window.location.pathname;
        document.querySelectorAll('.nav-link').forEach(link => {
            if (link.getAttribute('href') === currentPath) {
                link.classList.add('active');
            }
        });

        // Mobile menu toggle
        const menuToggle = document.querySelector('.navbar-toggler');
        const navbarNav = document.querySelector('.navbar-nav');
        
        if (menuToggle && navbarNav) {
            menuToggle.addEventListener('click', function(e) {
                e.preventDefault();
                navbarNav.classList.toggle('show');
            });
        }

        // Dropdown toggle
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const dropdown = this.parentElement;
                dropdown.classList.toggle('show');
            });
        });

        // Close dropdown on outside click
        document.addEventListener('click', function(e) {
            document.querySelectorAll('.dropdown.show').forEach(dropdown => {
                if (!dropdown.contains(e.target)) {
                    dropdown.classList.remove('show');
                }
            });
        });
    }

    // ============================================
    // 4. Forms
    // ============================================
    function initForms() {
        // Form validation
        document.querySelectorAll('.needs-validation').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!this.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                this.classList.add('was-validated');
            });
        });

        // Auto-hide errors on input
        document.querySelectorAll('.form-control.is-invalid').forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
                const error = this.parentElement.querySelector('.form-error');
                if (error) error.style.display = 'none';
            });
        });

        // Password toggle visibility
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const input = this.parentElement.querySelector('input');
                if (input) {
                    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                    input.setAttribute('type', type);
                    this.textContent = type === 'password' ? '👁️' : '🙈';
                }
            });
        });

        // Auto-submit on select change
        document.querySelectorAll('.auto-submit').forEach(select => {
            select.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });
    }

    // ============================================
    // 5. Alerts
    // ============================================
    function initAlerts() {
        // Auto-dismiss alerts
        document.querySelectorAll('.alert-auto-dismiss').forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 500);
            }, 5000);
        });

        // Close alert button
        document.querySelectorAll('.alert .close').forEach(button => {
            button.addEventListener('click', function() {
                const alert = this.closest('.alert');
                if (alert) {
                    alert.style.transition = 'opacity 0.3s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }
            });
        });
    }

    // ============================================
    // 6. Modals
    // ============================================
    function initModals() {
        // Open modal
        document.querySelectorAll('[data-toggle="modal"]').forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                const target = this.getAttribute('data-target');
                const modal = document.querySelector(target);
                if (modal) {
                    modal.classList.add('show');
                    document.body.style.overflow = 'hidden';
                }
            });
        });

        // Close modal
        document.querySelectorAll('.modal .close, .modal .btn-close').forEach(button => {
            button.addEventListener('click', function() {
                const modal = this.closest('.modal');
                if (modal) {
                    modal.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });
        });

        // Close modal on backdrop click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    modal.classList.remove('show');
                    document.body.style.overflow = '';
                });
            }
        });
    }

    // ============================================
    // 7. Tooltips
    // ============================================
    function initTooltips() {
        document.querySelectorAll('[data-toggle="tooltip"]').forEach(element => {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = element.getAttribute('title') || '';
            document.body.appendChild(tooltip);

            element.addEventListener('mouseenter', function(e) {
                const rect = this.getBoundingClientRect();
                tooltip.style.display = 'block';
                tooltip.style.left = (rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';
                tooltip.style.top = (rect.top - tooltip.offsetHeight - 8) + 'px';
            });

            element.addEventListener('mouseleave', function() {
                tooltip.style.display = 'none';
            });
        });
    }

    // ============================================
    // 8. Loading Spinner
    // ============================================
    function initLoadingSpinner() {
        // Show spinner on form submit
        document.querySelectorAll('form[data-loading]').forEach(form => {
            form.addEventListener('submit', function() {
                const btn = this.querySelector('button[type="submit"]');
                if (btn) {
                    const originalText = btn.textContent;
                    btn.textContent = 'جاري المعالجة...';
                    btn.disabled = true;
                    btn.dataset.originalText = originalText;
                }
            });
        });
    }

    // ============================================
    // 9. Theme Toggle
    // ============================================
    function initThemeToggle() {
        const themeToggle = document.querySelector('[data-theme-toggle]');
        if (!themeToggle) return;

        const currentTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', currentTheme);

        themeToggle.addEventListener('click', function() {
            const current = document.documentElement.getAttribute('data-theme');
            const newTheme = current === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        });
    }

    // ============================================
    // 10. Utility Functions
    // ============================================
    window.Tourfecto = {
        /**
         * Show toast notification
         * @param {string} message - Toast message
         * @param {string} type - 'success', 'error', 'warning', 'info'
         * @param {number} duration - Display duration in ms
         */
        showToast: function(message, type = 'info', duration = 3000) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('show');
            }, 100);

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, duration);
        },

        /**
         * Format currency
         * @param {number} amount - Amount
         * @param {string} currency - Currency code
         * @returns {string}
         */
        formatCurrency: function(amount, currency = 'USD') {
            const symbols = {
                'USD': '$',
                'EUR': '€',
                'GBP': '£',
                'EGP': 'E£',
                'SAR': '﷼',
                'AED': 'د.إ'
            };
            const symbol = symbols[currency] || currency;
            return symbol + ' ' + Number(amount).toFixed(2);
        },

        /**
         * Format date
         * @param {string|Date} date - Date
         * @param {string} format - Format string
         * @returns {string}
         */
        formatDate: function(date, format = 'YYYY-MM-DD') {
            const d = new Date(date);
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const hours = String(d.getHours()).padStart(2, '0');
            const minutes = String(d.getMinutes()).padStart(2, '0');
            const seconds = String(d.getSeconds()).padStart(2, '0');

            return format
                .replace('YYYY', year)
                .replace('MM', month)
                .replace('DD', day)
                .replace('HH', hours)
                .replace('mm', minutes)
                .replace('ss', seconds);
        },

        /**
         * Format time ago
         * @param {string|Date} date - Date
         * @returns {string}
         */
        timeAgo: function(date) {
            const now = new Date();
            const past = new Date(date);
            const diff = Math.floor((now - past) / 1000);

            if (diff < 60) return 'منذ ' + diff + ' ثانية';
            if (diff < 3600) return 'منذ ' + Math.floor(diff / 60) + ' دقيقة';
            if (diff < 86400) return 'منذ ' + Math.floor(diff / 3600) + ' ساعة';
            if (diff < 2592000) return 'منذ ' + Math.floor(diff / 86400) + ' يوم';
            if (diff < 31536000) return 'منذ ' + Math.floor(diff / 2592000) + ' شهر';
            return 'منذ ' + Math.floor(diff / 31536000) + ' سنة';
        },

        /**
         * Debounce function
         * @param {Function} func - Function to debounce
         * @param {number} wait - Wait time in ms
         * @returns {Function}
         */
        debounce: function(func, wait = 250) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        },

        /**
         * Throttle function
         * @param {Function} func - Function to throttle
         * @param {number} limit - Limit in ms
         * @returns {Function}
         */
        throttle: function(func, limit = 250) {
            let inThrottle;
            return function(...args) {
                if (!inThrottle) {
                    func.apply(this, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        },

        /**
         * Copy text to clipboard
         * @param {string} text - Text to copy
         * @returns {Promise}
         */
        copyToClipboard: function(text) {
            if (navigator.clipboard) {
                return navigator.clipboard.writeText(text);
            }
            return new Promise((resolve, reject) => {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    resolve();
                } catch (err) {
                    reject(err);
                }
                document.body.removeChild(textarea);
            });
        },

        /**
         * Get URL parameter
         * @param {string} name - Parameter name
         * @returns {string|null}
         */
        getUrlParam: function(name) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        },

        /**
         * Set URL parameter without reload
         * @param {string} name - Parameter name
         * @param {string} value - Parameter value
         */
        setUrlParam: function(name, value) {
            const url = new URL(window.location.href);
            url.searchParams.set(name, value);
            window.history.replaceState({}, '', url);
        }
    };

})();