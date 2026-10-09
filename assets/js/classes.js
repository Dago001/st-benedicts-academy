// assets/js/classes.js

// Class Management JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Initialize class form
    const classForm = document.querySelector('form.class-form');
    if (classForm) {
        validateClassForm(classForm);
    }

    // Handle capacity warnings
    const capacityInput = document.getElementById('capacity');
    if (capacityInput) {
        capacityInput.addEventListener('input', checkCapacity);
    }

    // Initialize class roster
    const rosterView = document.getElementById('classRoster');
    if (rosterView) {
        loadClassRoster();
    }
});

// Validate class form
function validateClassForm(form) {
    form.addEventListener('submit', function(e) {
        let isValid = true;

        // Validate class name
        const className = document.getElementById('class_name');
        if (className && className.value) {
            if (className.value.length < 3) {
                showError(className, 'Class name must be at least 3 characters');
                isValid = false;
            }
        }

        // Validate academic year format
        const academicYear = document.getElementById('academic_year');
        if (academicYear && academicYear.value) {
            const pattern = /^\d{4}-\d{4}$/;
            if (!pattern.test(academicYear.value)) {
                showError(academicYear, 'Academic year must be in format: YYYY-YYYY');
                isValid = false;
            } else {
                const years = academicYear.value.split('-');
                if (parseInt(years[1]) !== parseInt(years[0]) + 1) {
                    showError(academicYear, 'Academic year must be consecutive (e.g., 2024-2025)');
                    isValid = false;
                }
            }
        }

        // Validate capacity
        const capacity = document.getElementById('capacity');
        if (capacity && capacity.value) {
            const cap = parseInt(capacity.value);
            if (cap < 1 || cap > 100) {
                showError(capacity, 'Capacity must be between 1 and 100');
                isValid = false;
            }
        }

        if (!isValid) {
            e.preventDefault();
        }
    });
}

// Check capacity warning
function checkCapacity(e) {
    const capacity = parseInt(e.target.value);
    const warning = document.getElementById('capacityWarning');

    if (warning) {
        if (capacity > 50) {
            warning.style.display = 'block';
            warning.textContent = 'Warning: Large class size may affect teaching quality';
        } else {
            warning.style.display = 'none';
        }
    }
}

// Load class roster
function loadClassRoster() {
    const classId = document.getElementById('classId').value;

    fetch(BASE_URL + '/api/get-class-roster.php?class_id=' + classId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayClassRoster(data.roster);
            }
        });
}

// Display class roster
function displayClassRoster(roster) {
    const container = document.getElementById('classRoster');
    if (!container) return;

    let html = '<table class="data-table"><thead><tr><th>Admission No.</th><th>Name</th><th>Gender</th><th>Parent</th><th>Actions</th></tr></thead><tbody>';

    roster.forEach(student => {
        html += `<tr>
            <td>${escapeHtml(student.admission_number)}</td>
            <td>${escapeHtml(student.first_name)} ${escapeHtml(student.last_name)}</td>
            <td>${escapeHtml(student.gender)}</td>
            <td>${escapeHtml(student.parent_name || 'Not Assigned')}</td>
            <td>
                <a href="../students/view.php?id=${escapeHtml(student.id)}" class="btn-icon">
                    <i class="fas fa-eye"></i>
                </a>
            </td>
        </tr>`;
    });

    html += '</tbody></table>';

    if (roster.length === 0) {
        html = '<p class="no-data">No students in this class yet.</p>';
    }

    container.innerHTML = html;
}

// Generate seating arrangement
function generateSeating(classId) {
    fetch(BASE_URL + '/api/generate-seating.php?class_id=' + classId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySeatingArrangement(data.seating);
            }
        });
}

// Display seating arrangement
function displaySeatingArrangement(seating) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.id = 'seatingModal';

    let seatingHtml = '<div class="seating-grid">';

    seating.forEach(row => {
        seatingHtml += '<div class="seating-row">';
        row.forEach(student => {
            seatingHtml += `
                <div class="seating-card ${student ? 'occupied' : 'empty'}">
                    ${student ? `<strong>${escapeHtml(student.name)}</strong><br><small>${escapeHtml(student.admission)}</small>` : 'Empty'}
                </div>
            `;
        });
        seatingHtml += '</div>';
    });

    seatingHtml += '</div>';

    modal.innerHTML = `
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3>Seating Arrangement</h3>
                <button type="button" class="close" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body">
                ${seatingHtml}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="printSeating()">Print</button>
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal').remove()">Close</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);
    modal.style.display = 'block';
}

// Print seating arrangement
function printSeating() {
    const modal = document.getElementById('seatingModal');
    const content = modal.querySelector('.modal-body').innerHTML;

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Seating Arrangement</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                h1 { color: #002855; }
                .seating-grid { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
                .seating-row { display: flex; gap: 10px; justify-content: center; }
                .seating-card {
                    width: 100px;
                    height: 100px;
                    border: 2px solid #002855;
                    border-radius: 8px;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    text-align: center;
                    padding: 5px;
                }
                .seating-card.occupied { background: #ffd700; }
                .seating-card.empty { background: #f5f5f5; color: #999; }
            </style>
        </head>
        <body>
            <h1>Class Seating Arrangement</h1>
            <p>Generated on: ${new Date().toLocaleDateString()}</p>
            ${content}
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// Export class list
function exportClassList(classId, format) {
    window.location.href = `export.php?type=class&id=${classId}&format=${format}`;
}

// View class statistics
function viewClassStats(classId) {
    fetch(BASE_URL + '/api/get-class-stats.php?class_id=' + classId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayClassStats(data.stats);
            }
        });
}

// Display class statistics
function displayClassStats(stats) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.id = 'statsModal';

    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3>Class Statistics</h3>
                <button type="button" class="close" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="stats-grid">
                    <div class="stat-item">
                        <label>Total Students:</label>
                        <span class="value">${stats.total_students}</span>
                    </div>
                    <div class="stat-item">
                        <label>Male:</label>
                        <span class="value">${stats.male_count}</span>
                    </div>
                    <div class="stat-item">
                        <label>Female:</label>
                        <span class="value">${stats.female_count}</span>
                    </div>
                    <div class="stat-item">
                        <label>Average Age:</label>
                        <span class="value">${stats.average_age} years</span>
                    </div>
                    <div class="stat-item">
                        <label>Attendance Rate:</label>
                        <span class="value">${stats.attendance_rate}%</span>
                    </div>
                    <div class="stat-item">
                        <label>Average Performance:</label>
                        <span class="value">${stats.average_performance}%</span>
                    </div>
                </div>

                <canvas id="genderChart" width="300" height="300"></canvas>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal').remove()">Close</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);
    modal.style.display = 'block';

    // Create gender distribution chart
    setTimeout(() => {
        const ctx = document.getElementById('genderChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [stats.male_count, stats.female_count],
                    backgroundColor: ['#002855', '#ffd700']
                }]
            }
        });
    }, 100);
}