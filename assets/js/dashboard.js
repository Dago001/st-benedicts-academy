// assets/js/dashboard.js

// Dashboard specific functionality
document.addEventListener('DOMContentLoaded', function() {
    // Initialize charts if they exist
    initializeCharts();

    // Setup sidebar toggle for mobile
    setupSidebarToggle();

    // Load recent activities
    loadRecentActivities();

    // Setup notification refresh
    setInterval(refreshNotifications, 60000); // Every minute
});

function setupSidebarToggle() {
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
        });
    }
}

function initializeCharts() {
    // Attendance chart
    const attendanceCanvas = document.getElementById('attendanceChart');
    // Pages that draw their own chart inline (admin dashboard) or have no class selected are skipped
    if (attendanceCanvas && typeof Chart !== 'undefined' && !Chart.getChart(attendanceCanvas) && getCurrentClassId()) {
        fetch(BASE_URL + '/api/attendance?action=get_report&class_id=' + getCurrentClassId())
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    new Chart(attendanceCanvas, {
                        type: 'line',
                        data: {
                            labels: data.data.map(item => item.date),
                            datasets: [{
                                label: 'Present',
                                data: data.data.map(item => item.present),
                                borderColor: '#002855',
                                backgroundColor: 'rgba(0, 40, 85, 0.1)',
                                tension: 0.4
                            }, {
                                label: 'Absent',
                                data: data.data.map(item => item.absent),
                                borderColor: '#c41e3a',
                                backgroundColor: 'rgba(196, 30, 58, 0.1)',
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
            });
    }

    // Performance chart
    const performanceCanvas = document.getElementById('performanceChart');
    if (performanceCanvas && typeof Chart !== 'undefined') {
        // Initialize with data from data attribute
        const subjects = JSON.parse(performanceCanvas.dataset.subjects || '[]');
        const scores = JSON.parse(performanceCanvas.dataset.scores || '[]');

        new Chart(performanceCanvas, {
            type: 'bar',
            data: {
                labels: subjects,
                datasets: [{
                    label: 'Score',
                    data: scores,
                    backgroundColor: '#ffd700',
                    borderColor: '#002855',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100
                    }
                }
            }
        });
    }
}

function getCurrentClassId() {
    const classSelect = document.querySelector('select[name="class"]');
    return classSelect ? classSelect.value : '';
}

function loadRecentActivities() {
    const activitiesContainer = document.querySelector('.recent-activities-list');
    if (!activitiesContainer) return;

    fetch(BASE_URL + '/api/activities?action=recent')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                activitiesContainer.innerHTML = '';
                data.data.forEach(activity => {
                    const item = createActivityItem(activity);
                    activitiesContainer.appendChild(item);
                });
            }
        });
}

function createActivityItem(activity) {
    const div = document.createElement('div');
    div.className = 'activity-item';

    let icon = 'fa-info-circle';
    let color = '#002855';

    switch(activity.action) {
        case 'LOGIN':
            icon = 'fa-sign-in-alt';
            color = '#008000';
            break;
        case 'LOGOUT':
            icon = 'fa-sign-out-alt';
            color = '#c41e3a';
            break;
        case 'MARKED_ATTENDANCE':
            icon = 'fa-calendar-check';
            color = '#ffd700';
            break;
        case 'ADDED_RESULT':
            icon = 'fa-plus-circle';
            color = '#002855';
            break;
    }

    div.innerHTML = `
        <div class="activity-icon" style="color: ${color}">
            <i class="fas ${icon}"></i>
        </div>
        <div class="activity-details">
            <p class="activity-text">${escapeHtml(activity.description)}</p>
            <small class="activity-time">${escapeHtml(activity.time_ago)}</small>
        </div>
    `;

    return div;
}

function refreshNotifications() {
    fetch(BASE_URL + '/api/notifications?action=get_count')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateNotificationBadge(data.count);
            }
        });
}

// Quick action handlers
function quickMarkAttendance() {
    const classId = prompt('Enter Class ID:');
    if (classId) {
        window.location.href = BASE_URL + '/teacher/attendance?class=' + classId;
    }
}

function quickAddResult() {
    window.location.href = BASE_URL + '/teacher/results?add';
}

function quickSendMessage() {
    window.location.href = BASE_URL + '/messages?compose';
}

// Data table enhancements
function enhanceDataTables() {
    const tables = document.querySelectorAll('.data-table');
    tables.forEach(table => {
        // Add export buttons
        const exportBtn = document.createElement('button');
        exportBtn.className = 'btn btn-small btn-outline';
        exportBtn.innerHTML = '<i class="fas fa-download"></i> Export';
        exportBtn.onclick = () => exportTableToCSV(table.id);

        const header = table.closest('.card')?.querySelector('.card-header');
        if (header) {
            header.appendChild(exportBtn);
        }
    });
}

function exportTableToCSV(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const rows = table.querySelectorAll('tr');
    const csv = [];

    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = Array.from(cols).map(col => {
            let text = col.innerText.replace(/"/g, '""');
            return `"${text}"`;
        });
        csv.push(rowData.join(','));
    });

    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'export_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

// Print report card
function printReportCard() {
    const reportCard = document.querySelector('.report-card');
    if (reportCard) {
        printElement('report-card');
    }
}