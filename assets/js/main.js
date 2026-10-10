// assets/js/main.js

// Site-wide globals (BASE_URL / CSRF_TOKEN are defined by includes/header.php)
function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Mobile Menu Toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const navMenu = document.getElementById('navMenu');

    if (mobileToggle && navMenu) {
        mobileToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
            const icon = mobileToggle.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-bars');
                icon.classList.toggle('fa-times');
            }
        });
    }

    // Close mobile menu when clicking outside
    document.addEventListener('click', function(event) {
        if (navMenu && navMenu.classList.contains('active') &&
            !navMenu.contains(event.target) &&
            !mobileToggle.contains(event.target)) {
            navMenu.classList.remove('active');
            const icon = mobileToggle.querySelector('i');
            if (icon) {
                icon.classList.add('fa-bars');
                icon.classList.remove('fa-times');
            }
        }
    });

    // Dashboard sidebar drawer (phones / tablets)
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebarClose = document.getElementById('sidebarClose');
    function setSidebar(open) {
        document.body.classList.toggle('sidebar-open', open);
        if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () { setSidebar(!document.body.classList.contains('sidebar-open')); });
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', function () { setSidebar(false); });
        if (sidebarClose) sidebarClose.addEventListener('click', function () { setSidebar(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setSidebar(false); });
        window.addEventListener('resize', function () { if (window.innerWidth > 991) setSidebar(false); });
        // Following a link inside the drawer closes it
        document.querySelectorAll('#appSidebar a').forEach(function (a) { a.addEventListener('click', function () { setSidebar(false); }); });
    }

    // Wrap bare tables so wide ones scroll inside their card instead of the page
    document.querySelectorAll('table').forEach(function (t) {
        if (t.closest('.table-responsive, .dataTables_wrapper, .timetable')) return;
        const wrap = document.createElement('div');
        wrap.className = 'table-responsive';
        t.parentNode.insertBefore(wrap, t);
        wrap.appendChild(t);
    });

    // Dropdown in the top navigation: tap to open on touch screens
    document.querySelectorAll('.dropdown-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            const li = toggle.closest('.dropdown');
            if (li) { li.classList.toggle('open'); li.classList.toggle('active'); }
        });
    });

    // aria-expanded for the main menu toggle
    if (mobileToggle && navMenu) {
        mobileToggle.addEventListener('click', function () {
            mobileToggle.setAttribute('aria-expanded', navMenu.classList.contains('active') ? 'true' : 'false');
        });
    }

    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(element => {
        element.addEventListener('mouseenter', showTooltip);
        element.addEventListener('mouseleave', hideTooltip);
    });

    // Lazy loading images
    const lazyImages = document.querySelectorAll('img[loading="lazy"]');
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.classList.add('loaded');
                    imageObserver.unobserve(img);
                }
            });
        });

        lazyImages.forEach(img => imageObserver.observe(img));
    }

    // Form validation
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', validateForm);
    });

    // Check for notifications
    checkNotifications();

    // Initialize data tables
    window.addEventListener("load", initializeDataTables);
});

// Tooltip functions
function showTooltip(event) {
    const element = event.target;
    const text = element.dataset.tooltip;

    const tooltip = document.createElement('div');
    tooltip.className = 'tooltip';
    tooltip.textContent = text;
    tooltip.id = 'current-tooltip';

    document.body.appendChild(tooltip);

    const rect = element.getBoundingClientRect();
    tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
    tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
}

function hideTooltip() {
    const tooltip = document.getElementById('current-tooltip');
    if (tooltip) {
        tooltip.remove();
    }
}

// Form validation
function validateForm(event) {
    const form = event.target;
    let isValid = true;

    // Required fields
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            markFieldInvalid(field, 'This field is required');
            isValid = false;
        } else {
            markFieldValid(field);
        }
    });

    // Email validation
    const emailFields = form.querySelectorAll('input[type="email"]');
    emailFields.forEach(field => {
        if (field.value && !isValidEmail(field.value)) {
            markFieldInvalid(field, 'Please enter a valid email address');
            isValid = false;
        }
    });

    // Phone validation (Nigerian)
    const phoneFields = form.querySelectorAll('input[type="tel"]');
    phoneFields.forEach(field => {
        if (field.value && !isValidPhone(field.value)) {
            markFieldInvalid(field, 'Please enter a valid Nigerian phone number');
            isValid = false;
        }
    });

    // Password match
    const password = form.querySelector('input[name="password"]');
    const confirmPassword = form.querySelector('input[name="confirm_password"]');
    if (password && confirmPassword && password.value !== confirmPassword.value) {
        markFieldInvalid(confirmPassword, 'Passwords do not match');
        isValid = false;
    }

    if (!isValid) {
        event.preventDefault();
        showFormError('Please correct the errors in the form');
    }
}

function markFieldInvalid(field, message) {
    field.classList.add('error');

    let errorDiv = field.parentNode.querySelector('.error-message');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'error-message';
        field.parentNode.appendChild(errorDiv);
    }
    errorDiv.textContent = message;
}

function markFieldValid(field) {
    field.classList.remove('error');
    const errorDiv = field.parentNode.querySelector('.error-message');
    if (errorDiv) {
        errorDiv.remove();
    }
}

function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function isValidPhone(phone) {
    const re = /^0[789][01]\d{8}$/;
    return re.test(phone);
}

function showFormError(message) {
    const alert = document.createElement('div');
    alert.className = 'alert alert-error';
    alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + message;

    const form = document.querySelector('form');
    form.insertBefore(alert, form.firstChild);

    setTimeout(() => {
        alert.remove();
    }, 5000);
}

// Notifications
function checkNotifications() {
    if (typeof BASE_URL === 'undefined' || !window.IS_AUTH) return;
    fetch(BASE_URL + '/api/notifications?action=get_count', { credentials: 'same-origin' })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.count > 0) {
                updateNotificationBadge(data.count);
            }
        })
        .catch(error => console.error('Error checking notifications:', error));
}

function updateNotificationBadge(count) {
    const badge = document.querySelector('.notification-badge');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'block' : 'none';
    }
}

// DataTables initialization
function initializeDataTables() {
    if (typeof $ === 'undefined' || typeof $.fn.DataTable === 'undefined') return;
    // Report DataTables problems in the console instead of popping an alert at visitors
    $.fn.dataTable.ext.errMode = function (settings, helpPage, message) { console.warn(message); };
    const tables = document.querySelectorAll('.data-table');
    tables.forEach(table => {
        // Pages that configure their own table have already initialised it by now
        if (!$.fn.DataTable.isDataTable(table)) {
            $(table).DataTable({
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                responsive: true,
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: {
                        first: '<i class="fas fa-angle-double-left"></i>',
                        previous: '<i class="fas fa-angle-left"></i>',
                        next: '<i class="fas fa-angle-right"></i>',
                        last: '<i class="fas fa-angle-double-right"></i>'
                    }
                }
            });
        }
    });
}

// AJAX Form Submission
function submitFormAjax(formId, url, callback) {
    const form = document.getElementById(formId);
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        }

        fetch(url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Submit';
            }

            if (callback) {
                callback(data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Submit';
            }
            showFormError('An error occurred. Please try again.');
        });
    });
}

// Print function
function printElement(elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;

    const printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Print</title>');
    printWindow.document.write('<link rel="stylesheet" href="' + BASE_URL + '/assets/css/style.css">');
    printWindow.document.write('</head><body>');
    printWindow.document.write(element.innerHTML);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

// Dark/Light mode toggle
function toggleTheme() {
    const body = document.body;
    body.classList.toggle('dark-mode');

    const isDark = body.classList.contains('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');

    const toggleButton = document.querySelector('.theme-toggle i');
    if (toggleButton) {
        toggleButton.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
}

// Load saved theme
const savedTheme = localStorage.getItem('theme');
if (savedTheme === 'dark') {
    document.body.classList.add('dark-mode');
}

// Export to Excel
function exportToExcel(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');

    rows.forEach(row => {
        const rowData = [];
        const cols = row.querySelectorAll('td, th');

        cols.forEach(col => {
            let text = col.innerText.replace(/,/g, '');
            rowData.push(text);
        });

        csv.push(rowData.join(','));
    });

    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || 'export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}