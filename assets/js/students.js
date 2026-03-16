// assets/js/students.js

// Student Management JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Initialize form validation
    const studentForm = document.querySelector('form.student-form');
    if (studentForm) {
        validateStudentForm(studentForm);
    }
    
    // Initialize search
    const searchInput = document.getElementById('searchStudents');
    if (searchInput) {
        searchInput.addEventListener('keyup', debounce(searchStudents, 300));
    }
    
    // Handle bulk actions
    const bulkActionSelect = document.getElementById('bulkAction');
    if (bulkActionSelect) {
        bulkActionSelect.addEventListener('change', handleBulkAction);
    }
    
    // Handle file upload preview
    const photoInput = document.getElementById('profile_image');
    if (photoInput) {
        photoInput.addEventListener('change', previewPhoto);
    }
});

// Validate student form
function validateStudentForm(form) {
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        // Validate admission number format
        const admissionNo = document.getElementById('admission_number');
        if (admissionNo && admissionNo.value) {
            const pattern = /^STB\/\d{4}\/\d{4}$/;
            if (!pattern.test(admissionNo.value)) {
                showError(admissionNo, 'Admission number must be in format: STB/YYYY/0000');
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
        
        // Validate phone (Nigerian format)
        const phone = document.getElementById('phone');
        if (phone && phone.value) {
            if (!isValidPhone(phone.value)) {
                showError(phone, 'Please enter a valid Nigerian phone number (e.g., 08012345678)');
                isValid = false;
            }
        }
        
        // Validate date of birth
        const dob = document.getElementById('date_of_birth');
        if (dob && dob.value) {
            const age = calculateAge(new Date(dob.value));
            if (age < 2 || age > 18) {
                showError(dob, 'Age must be between 2 and 18 years');
                isValid = false;
            }
        }
        
        if (!isValid) {
            e.preventDefault();
        }
    });
}

// Calculate age from date of birth
function calculateAge(dob) {
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const monthDiff = today.getMonth() - dob.getMonth();
    
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
        age--;
    }
    
    return age;
}

// Search students
function searchStudents(e) {
    const searchTerm = e.target.value.toLowerCase();
    const table = document.getElementById('studentsTable');
    
    if (!table) return;
    
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    for (let row of rows) {
        const name = row.cells[2].textContent.toLowerCase();
        const admission = row.cells[3].textContent.toLowerCase();
        const email = row.cells[5].textContent.toLowerCase();
        
        if (name.includes(searchTerm) || admission.includes(searchTerm) || email.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
}

// Handle bulk actions
function handleBulkAction(e) {
    const action = e.target.value;
    if (!action) return;
    
    const selectedStudents = getSelectedStudents();
    
    if (selectedStudents.length === 0) {
        alert('Please select at least one student');
        e.target.value = '';
        return;
    }
    
    switch (action) {
        case 'delete':
            bulkDelete(selectedStudents);
            break;
        case 'activate':
            bulkUpdateStatus(selectedStudents, 'activate');
            break;
        case 'deactivate':
            bulkUpdateStatus(selectedStudents, 'deactivate');
            break;
        case 'export':
            exportStudents(selectedStudents);
            break;
    }
    
    e.target.value = '';
}

// Get selected students from checkboxes
function getSelectedStudents() {
    const checkboxes = document.querySelectorAll('.student-select:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Bulk delete
function bulkDelete(students) {
    if (!confirm('Are you sure you want to delete ' + students.length + ' student(s)?')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'students.php';
    
    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = 'csrf_token';
    csrf.value = document.querySelector('input[name="csrf_token"]').value;
    form.appendChild(csrf);
    
    const action = document.createElement('input');
    action.type = 'hidden';
    action.name = 'action';
    action.value = 'bulk_delete';
    form.appendChild(action);
    
    students.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'student_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

// Export students
function exportStudents(students) {
    let csv = 'Admission Number,Name,Class,Email,Phone,Status\n';
    
    students.forEach(id => {
        const row = document.querySelector(`tr[data-student-id="${id}"]`);
        if (row) {
            const cells = row.cells;
            csv += `${cells[3].textContent},${cells[2].textContent},${cells[4].textContent},${cells[5].textContent},${cells[6].textContent},${cells[7].textContent}\n`;
        }
    });
    
    downloadCSV(csv, 'students_export.csv');
}

// Preview photo before upload
function previewPhoto(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('photoPreview');
            if (preview) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            }
        };
        reader.readAsDataURL(file);
    }
}

// Generate login credentials
function generateLogin(studentId) {
    fetch(BASE_URL + '/api/generate-login.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            student_id: studentId,
            csrf_token: document.querySelector('input[name="csrf_token"]').value
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Login credentials generated successfully', 'success');
            
            // Show credentials modal
            showCredentialsModal(data.username, data.password);
        } else {
            showNotification('Error: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

// Show credentials modal
function showCredentialsModal(username, password) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.id = 'credentialsModal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3>Login Credentials Generated</h3>
                <button type="button" class="close" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Username: <strong>${username}</strong></p>
                <p>Password: <strong>${password}</strong></p>
                <p class="text-warning">Please save these credentials. They will not be shown again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="printCredentials()">Print</button>
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal').remove()">Close</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    modal.style.display = 'block';
}

// Print credentials
function printCredentials() {
    const modal = document.getElementById('credentialsModal');
    const content = modal.querySelector('.modal-body').innerHTML;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Student Login Credentials</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                h1 { color: #002855; }
                .credentials { 
                    border: 2px solid #ffd700; 
                    padding: 20px; 
                    margin: 20px 0; 
                    border-radius: 10px; 
                }
                .credentials p { margin: 10px 0; }
            </style>
        </head>
        <body>
            <h1>Student Login Credentials</h1>
            <div class="credentials">${content}</div>
            <p>School: ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY</p>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}