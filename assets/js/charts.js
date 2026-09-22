/**
 * KETAN M/A B COMPLEX - Chart.js Visualizations
 */

const SchoolCharts = {
    colors: {
        navy: '#0f2942',
        royal: '#1e40af',
        amber: '#f59e0b',
        emerald: '#10b981',
        cyan: '#06b6d4',
        rose: '#ef4444',
        indigo: '#6366f1'
    },

    renderBarChart(canvasId, labels, data, label = 'Average Score (%)') {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return null;

        return new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: label,
                    data: data,
                    backgroundColor: 'rgba(30, 64, 175, 0.75)',
                    borderColor: '#1e40af',
                    borderWidth: 1.5,
                    borderRadius: 6,
                    hoverBackgroundColor: '#0f2942'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: value => value + '%'
                        }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    },

    renderDoughnutChart(canvasId, labels, data) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return null;

        return new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: [
                        '#10b981', // A (Emerald)
                        '#0284c7', // B (Sky)
                        '#6366f1', // C (Indigo)
                        '#f59e0b', // D (Amber)
                        '#ea580c', // E (Orange)
                        '#ef4444'  // F (Rose)
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                cutout: '65%'
            }
        });
    },

    renderHorizontalBar(canvasId, labels, data, label = 'Subject Average') {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return null;

        return new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: label,
                    data: data,
                    backgroundColor: 'rgba(245, 158, 11, 0.8)',
                    borderColor: '#d97706',
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: v => v + '%' }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
};

window.SchoolCharts = SchoolCharts;
