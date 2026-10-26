/**
 * Accounting Charts - Reusable Chart.js Components
 * Chart visualization library for accounting dashboards
 */

// Global chart defaults
Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
Chart.defaults.plugins.legend.display = true;
Chart.defaults.plugins.legend.position = 'top';
Chart.defaults.responsive = true;
Chart.defaults.maintainAspectRatio = true;

/**
 * Create Revenue vs Expense Trend Line Chart
 */
function createRevenueTrendChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label: 'Revenue',
                    data: data.revenue,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Expenses',
                    data: data.expenses,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Net Profit',
                    data: data.netProfit,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.4,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Revenue vs Expenses Trend',
                    font: { size: 16, weight: 'bold' }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += '$' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

/**
 * Create Profit Margin Chart
 */
function createProfitMarginChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Profit Margin %',
                data: data.margins,
                backgroundColor: data.margins.map(m => 
                    m >= 30 ? 'rgba(40, 167, 69, 0.8)' : 
                    m >= 15 ? 'rgba(255, 193, 7, 0.8)' : 
                    'rgba(220, 53, 69, 0.8)'
                ),
                borderColor: data.margins.map(m => 
                    m >= 30 ? '#28a745' : 
                    m >= 15 ? '#ffc107' : 
                    '#dc3545'
                ),
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Profit Margin by Period',
                    font: { size: 16, weight: 'bold' }
                },
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Margin: ' + context.parsed.y.toFixed(2) + '%';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Create Expense Category Pie Chart
 */
function createExpensePieChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    const colors = [
        '#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8',
        '#6f42c1', '#e83e8c', '#fd7e14', '#20c997', '#6c757d'
    ];
    
    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.categories,
            datasets: [{
                data: data.amounts,
                backgroundColor: colors.slice(0, data.categories.length),
                borderColor: '#fff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Expense Breakdown by Category',
                    font: { size: 16, weight: 'bold' }
                },
                legend: {
                    position: 'right'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                            return context.label + ': $' + context.parsed.toLocaleString() + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Create Commission Breakdown Chart
 */
function createCommissionBreakdownChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.agents,
            datasets: [{
                label: 'Commission Amount',
                data: data.amounts,
                backgroundColor: 'rgba(255, 193, 7, 0.8)',
                borderColor: '#ffc107',
                borderWidth: 2
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Top Agents by Commission',
                    font: { size: 16, weight: 'bold' }
                },
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '$' + context.parsed.x.toLocaleString('en-US', {minimumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

/**
 * Create Account Balance History Chart
 */
function createAccountBalanceChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.periods,
            datasets: [{
                label: 'Balance',
                data: data.balances,
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: data.accountName + ' - Balance History',
                    font: { size: 16, weight: 'bold' }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Balance: $' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

/**
 * Create Journal Entry Type Distribution Chart
 */
function createEntryTypeChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Entry Count',
                data: data.counts,
                backgroundColor: [
                    'rgba(0, 123, 255, 0.8)',
                    'rgba(40, 167, 69, 0.8)',
                    'rgba(220, 53, 69, 0.8)',
                    'rgba(255, 193, 7, 0.8)'
                ],
                borderColor: [
                    '#007bff',
                    '#28a745',
                    '#dc3545',
                    '#ffc107'
                ],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Journal Entries by Type',
                    font: { size: 16, weight: 'bold' }
                },
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
}

/**
 * Create Cash Flow Chart
 */
function createCashFlowChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label: 'Cash Inflow',
                    data: data.inflow,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 2,
                    fill: true
                },
                {
                    label: 'Cash Outflow',
                    data: data.outflow,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    borderWidth: 2,
                    fill: true
                },
                {
                    label: 'Net Cash Flow',
                    data: data.netFlow,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.2)',
                    borderWidth: 3,
                    fill: false,
                    borderDash: [5, 5]
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Cash Flow Analysis',
                    font: { size: 16, weight: 'bold' }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': $' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

/**
 * Create Tax Liability Chart
 */
function createTaxLiabilityChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.quarters,
            datasets: [
                {
                    label: 'Estimated Tax',
                    data: data.estimated,
                    backgroundColor: 'rgba(255, 193, 7, 0.8)',
                    borderColor: '#ffc107',
                    borderWidth: 2
                },
                {
                    label: 'Paid Tax',
                    data: data.paid,
                    backgroundColor: 'rgba(40, 167, 69, 0.8)',
                    borderColor: '#28a745',
                    borderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Quarterly Tax Liability',
                    font: { size: 16, weight: 'bold' }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': $' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

/**
 * Destroy chart instance if it exists
 */
function destroyChart(chartInstance) {
    if (chartInstance) {
        chartInstance.destroy();
    }
}

/**
 * Update chart data dynamically
 */
function updateChartData(chart, newData) {
    chart.data.datasets.forEach((dataset, i) => {
        dataset.data = newData.datasets[i].data;
    });
    chart.data.labels = newData.labels;
    chart.update();
}

/**
 * Export chart as image
 */
function exportChartImage(chartId, filename) {
    const canvas = document.getElementById(chartId);
    const url = canvas.toDataURL('image/png');
    const link = document.createElement('a');
    link.download = filename || 'chart.png';
    link.href = url;
    link.click();
}












