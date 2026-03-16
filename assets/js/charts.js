// assets/js/charts.js

// Chart configuration and helpers
const chartColors = {
    navy: '#002855',
    red: '#c41e3a',
    gold: '#ffd700',
    green: '#008000',
    orange: '#ffa500',
    purple: '#800080',
    gray: '#808080'
};

// Create attendance pie chart
function createAttendancePieChart(canvasId, data) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;
    
    new Chart(canvas, {
        type: 'pie',
        data: {
            labels: ['Present', 'Absent', 'Late', 'Excused'],
            datasets: [{
                data: [
                    data.present || 0,
                    data.absent || 0,
                    data.late || 0,
                    data.excused || 0
                ],
                backgroundColor: [
                    chartColors.navy,
                    chartColors.red,
                    chartColors.gold,
                    chartColors.gray
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// Create performance line chart
function createPerformanceLineChart(canvasId, labels, datasets) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;
    
    new Chart(canvas, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets.map(dataset => ({
                label: dataset.label,
                data: dataset.data,
                borderColor: dataset.color || chartColors.navy,
                backgroundColor: 'rgba(0, 40, 85, 0.1)',
                tension: 0.4,
                fill: false
            }))
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100
                }
            }
        }
    });
}

// Create fee collection bar chart
function createFeeChart(canvasId, labels, data) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;
    
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Amount (₦)',
                data: data,
                backgroundColor: chartColors.gold,
                borderColor: chartColors.navy,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₦' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

// Create student distribution chart
function createStudentDistributionChart(canvasId, classData) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;
    
    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: classData.map(c => c.class_name),
            datasets: [{
                data: classData.map(c => c.student_count),
                backgroundColor: [
                    chartColors.navy,
                    chartColors.red,
                    chartColors.gold,
                    chartColors.green,
                    chartColors.orange
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// Create gender distribution chart
function createGenderChart(canvasId, male, female) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;
    
    new Chart(canvas, {
        type: 'pie',
        data: {
            labels: ['Male', 'Female'],
            datasets: [{
                data: [male, female],
                backgroundColor: [chartColors.navy, chartColors.gold],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
}

// Update chart data dynamically
function updateChart(chart, newData) {
    if (chart && chart.data) {
        chart.data.datasets.forEach((dataset, index) => {
            dataset.data = newData[index];
        });
        chart.update();
    }
}

// Create multi-series chart
function createMultiSeriesChart(canvasId, type, labels, datasets) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;
    
    return new Chart(canvas, {
        type: type,
        data: {
            labels: labels,
            datasets: datasets.map(dataset => ({
                label: dataset.label,
                data: dataset.data,
                backgroundColor: dataset.backgroundColor || chartColors.navy,
                borderColor: dataset.borderColor || chartColors.navy,
                borderWidth: 1
            }))
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: type === 'bar' ? {
                y: {
                    beginAtZero: true
                }
            } : undefined
        }
    });
}

// Export chart as image
function exportChartAsImage(chartId, filename) {
    const canvas = document.getElementById(chartId);
    if (!canvas) return;
    
    const link = document.createElement('a');
    link.download = filename || 'chart.png';
    link.href = canvas.toDataURL('image/png');
    link.click();
}