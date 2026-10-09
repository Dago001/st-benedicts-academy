// assets/js/teachers.js

// Teacher Management JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Initialize form validation
    const teacherForm = document.querySelector('form.teacher-form');
    if (teacherForm) {
        validateTeacherForm(teacherForm);
    }

    // Handle subject assignment
    const subjectAssignment = document.getElementById('subjectAssignment');
    if (subjectAssignment) {
        initializeSubjectAssignment();
    }
});

// Validate teacher form
function validateTeacherForm(form) {
    form.addEventListener('submit', function(e) {
        let isValid = true;

        // Validate employee ID format
        const empId = document.getElementById('employee_id');
        if (empId && empId.value) {
            const pattern = /^TCH\/\d{4}\/\d{3}$/;
            if (!pattern.test(empId.value)) {
                showError(empId, 'Employee ID must be in format: TCH/YYYY/000');
                isValid = false;
            }
        }

        // Validate email
        const email = document.getElementById('email');
        if (email && email.value) {
            if (!isValidEmail(email.value)) {
                showError(email, 'Please enter a valid email address');
                isValid = false;
            }
        }

        // Validate phone
        const phone = document.getElementById('phone');
        if (phone && phone.value) {
            if (!isValidPhone(phone.value)) {
                showError(phone, 'Please enter a valid Nigerian phone number');
                isValid = false;
            }
        }

        // Validate date of hire
        const doh = document.getElementById('date_of_hire');
        if (doh && doh.value) {
            const hireDate = new Date(doh.value);
            const today = new Date();

            if (hireDate > today) {
                showError(doh, 'Date of hire cannot be in the future');
                isValid = false;
            }
        }

        if (!isValid) {
            e.preventDefault();
        }
    });
}

// Initialize subject assignment interface
function initializeSubjectAssignment() {
    const teacherSelect = document.getElementById('teacher_id');
    const subjectList = document.getElementById('subjectList');

    if (teacherSelect && subjectList) {
        teacherSelect.addEventListener('change', function() {
            loadTeacherSubjects(this.value);
        });
    }

    // Handle drag and drop subject assignment
    const availableSubjects = document.getElementById('availableSubjects');
    const assignedSubjects = document.getElementById('assignedSubjects');

    if (availableSubjects && assignedSubjects) {
        new Sortable(availableSubjects, {
            group: 'subjects',
            animation: 150,
            onEnd: function(evt) {
                updateSubjectAssignment(evt);
            }
        });

        new Sortable(assignedSubjects, {
            group: 'subjects',
            animation: 150,
            onEnd: function(evt) {
                updateSubjectAssignment(evt);
            }
        });
    }
}

// Load teacher's subjects
function loadTeacherSubjects(teacherId) {
    if (!teacherId) return;

    fetch(BASE_URL + '/api/get-teacher-subjects?teacher_id=' + teacherId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayTeacherSubjects(data.subjects);
            }
        })
        .catch(error => console.error('Error:', error));
}

// Display teacher subjects
function displayTeacherSubjects(subjects) {
    const container = document.getElementById('teacherSubjects');
    if (!container) return;

    container.innerHTML = '';

    if (subjects.length === 0) {
        container.innerHTML = '<p class="no-data">No subjects assigned yet.</p>';
        return;
    }

    const ul = document.createElement('ul');
    ul.className = 'subject-list';

    subjects.forEach(subject => {
        const li = document.createElement('li');
        li.className = 'subject-item';
        li.innerHTML = `
            <span class="subject-name">${escapeHtml(subject.subject_name)}</span>
            <span class="subject-class">${escapeHtml(subject.class_name)}</span>
            <button type="button" class="btn-icon" onclick="removeSubject(${escapeHtml(subject.id)})">
                <i class="fas fa-times"></i>
            </button>
        `;
        ul.appendChild(li);
    });

    container.appendChild(ul);
}

// Update subject assignment
function updateSubjectAssignment(evt) {
    const subjectId = evt.item.dataset.subjectId;
    const teacherId = document.getElementById('teacher_id').value;
    const action = evt.to.id === 'assignedSubjects' ? 'assign' : 'unassign';

    if (!teacherId) {
        alert('Please select a teacher first');
        return;
    }

    fetch(BASE_URL + '/api/update-subject-assignment', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            subject_id: subjectId,
            teacher_id: teacherId,
            action: action,
            csrf_token: document.querySelector('input[name="csrf_token"]').value
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Error updating subject assignment');
            // Revert the drag and drop
            location.reload();
        }
    });
}

// Remove subject from teacher
function removeSubject(subjectId) {
    if (!confirm('Are you sure you want to remove this subject?')) {
        return;
    }

    const teacherId = document.getElementById('teacher_id').value;

    fetch(BASE_URL + '/api/update-subject-assignment', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            subject_id: subjectId,
            teacher_id: teacherId,
            action: 'unassign',
            csrf_token: document.querySelector('input[name="csrf_token"]').value
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error removing subject');
        }
    });
}

// Generate teacher schedule
function generateSchedule(teacherId) {
    fetch(BASE_URL + '/api/generate-schedule?teacher_id=' + teacherId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySchedule(data.schedule);
            }
        });
}

// Display teacher schedule
function displaySchedule(schedule) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.id = 'scheduleModal';

    let scheduleHtml = '<table class="schedule-table">';
    scheduleHtml += '<thead><tr><th>Time</th><th>Monday</th><th>Tuesday</th><th>Wednesday</th><th>Thursday</th><th>Friday</th></tr></thead><tbody>';

    // Generate time slots
    const times = ['08:00-09:00', '09:00-10:00', '10:00-11:00', '11:00-12:00', '12:00-13:00', '13:00-14:00', '14:00-15:00'];

    times.forEach(time => {
        scheduleHtml += `<tr><td>${time}</td>`;
        ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'].forEach(day => {
            const period = schedule[day]?.[time] || '-';
            scheduleHtml += `<td>${period}</td>`;
        });
        scheduleHtml += '</tr>';
    });

    scheduleHtml += '</tbody></table>';

    modal.innerHTML = `
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3>Teacher Schedule</h3>
                <button type="button" class="close" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body">
                ${scheduleHtml}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="printSchedule()">Print</button>
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal').remove()">Close</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);
    modal.style.display = 'block';
}

// Print schedule
function printSchedule() {
    const modal = document.getElementById('scheduleModal');
    const content = modal.querySelector('.modal-body').innerHTML;

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Teacher Schedule</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                h1 { color: #002855; }
                .schedule-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                .schedule-table th { background: #002855; color: white; padding: 10px; }
                .schedule-table td { border: 1px solid #ddd; padding: 8px; text-align: center; }
                .schedule-table tr:nth-child(even) { background: #f5f5f5; }
            </style>
        </head>
        <body>
            <h1>Teacher Schedule</h1>
            <p>Generated on: ${new Date().toLocaleDateString()}</p>
            ${content}
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}