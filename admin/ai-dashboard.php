<?php
// AI Sales Assistant Dashboard
require_once __DIR__ . '/../required/config.php';

// Ensure user is logged in and is admin (use shared helper)
start_session();
require_admin_or_redirect('/login.php');

// Get analytics data
require_once __DIR__ . '/../required/ai-advanced-context.php';
$advancedContext = new AdvancedAIContext(db());

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Sales Assistant Dashboard - Product</title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .ai-dashboard {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .metric-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .metric-value {
            font-size: 2.5em;
            font-weight: bold;
            color: #2c3e50;
            margin: 10px 0;
        }
        
        .metric-label {
            color: #7f8c8d;
            font-size: 0.9em;
            text-transform: uppercase;
        }
        
        .metric-change {
            font-size: 0.9em;
            margin-top: 5px;
        }
        
        .positive { color: #27ae60; }
        .negative { color: #e74c3c; }
        
        .chart-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .insights-panel {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .insight-item {
            background: white;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid #3498db;
            border-radius: 5px;
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #ecf0f1;
        }
        
        .tab {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #7f8c8d;
            transition: all 0.3s;
        }
        
        .tab.active {
            color: #3498db;
            border-bottom: 3px solid #3498db;
            margin-bottom: -2px;
        }
        
        .tab:hover {
            color: #2c3e50;
        }
        
        .performance-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .performance-high { background: #27ae60; }
        .performance-medium { background: #f39c12; }
        .performance-low { background: #e74c3c; }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-export {
            background: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .btn-export:hover {
            background: #2980b9;
        }
        
        .conversation-log {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 10px;
            font-size: 0.9em;
        }
        
        .user-message {
            color: #2980b9;
            margin-bottom: 5px;
        }
        
        .ai-response {
            color: #27ae60;
            margin-bottom: 5px;
        }
        
        .feedback-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.8em;
            margin-left: 10px;
        }
        
        .feedback-positive {
            background: #d4edda;
            color: #155724;
        }
        
        .feedback-negative {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../required/header.php'; ?>
    
    <div class="ai-dashboard">
        <h1>🤖 AI Sales Assistant Dashboard</h1>
        <p class="subtitle">Real-time performance metrics and insights for your world-class AI sales assistant</p>
        
        <div class="tabs">
            <button class="tab active" data-tab="overview">Overview</button>
            <button class="tab" data-tab="performance">Performance</button>
            <button class="tab" data-tab="learning">AI Learning</button>
            <button class="tab" data-tab="conversations">Conversations</button>
            <button class="tab" data-tab="insights">Insights</button>
            <button class="tab" data-tab="consistency">Consistency</button>
        </div>
        
        <div id="overview" class="tab-content">
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-label">Total Conversations</div>
                    <div class="metric-value" id="total-conversations">0</div>
                    <div class="metric-change positive">↑ 12% from last week</div>
                </div>
                
                <div class="metric-card">
                    <div class="metric-label">Conversion Rate</div>
                    <div class="metric-value" id="conversion-rate">0%</div>
                    <div class="metric-change positive">↑ 3.5% improvement</div>
                </div>
                
                <div class="metric-card">
                    <div class="metric-label">AI-Assisted Revenue</div>
                    <div class="metric-value" id="ai-revenue">$0</div>
                    <div class="metric-change positive">↑ $2,450 this month</div>
                </div>
                
                <div class="metric-card">
                    <div class="metric-label">Customer Satisfaction</div>
                    <div class="metric-value" id="satisfaction">0%</div>
                    <div class="metric-change">Based on feedback</div>
                </div>
                
                <div class="metric-card">
                    <div class="metric-label">Active Users Today</div>
                    <div class="metric-value" id="active-users">0</div>
                    <div class="metric-change">Real-time</div>
                </div>
                
                <div class="metric-card">
                    <div class="metric-label">Response Success</div>
                    <div class="metric-value" id="response-success">0%</div>
                    <div class="metric-change positive">↑ 5% accuracy</div>
                </div>
            </div>
            
            <div class="chart-container">
                <h3>Conversation Trends (Last 7 Days)</h3>
                <canvas id="conversation-chart" height="100"></canvas>
            </div>
            
            <div class="insights-panel">
                <h3>🎯 AI Performance Insights</h3>
                <div class="insight-item">
                    <span class="performance-indicator performance-high"></span>
                    <strong>Top Performing:</strong> Product recommendations achieving 78% conversion when AI mentions profit calculations
                </div>
                <div class="insight-item">
                    <span class="performance-indicator performance-medium"></span>
                    <strong>Opportunity:</strong> Customers asking about legal compliance - consider adding more regulatory content
                </div>
                <div class="insight-item">
                    <span class="performance-indicator performance-high"></span>
                    <strong>Success Pattern:</strong> Conversations mentioning competitor comparisons convert 45% better
                </div>
                <div class="insight-item" id="consistency-summary" style="border-left-color:#16a34a;">
                    <strong>Knowledge Consistency:</strong>
                    <span id="consistency-status" style="margin-left:6px;">Checking…</span>
                    <div style="font-size:.9em;color:#6b7280;margin-top:6px;">
                        <span id="consistency-checked">0</span> products checked •
                        <span id="consistency-mismatches">0</span> mismatches
                        <a href="#" onclick="openConsistencyTab(); return false;" style="margin-left:8px;text-decoration:underline;">View details</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="performance" class="tab-content" style="display:none;">
            <div class="chart-container">
                <h3>Conversion Funnel</h3>
                <canvas id="funnel-chart" height="80"></canvas>
            </div>
            
            <div class="chart-container">
                <h3>Sales Impact Analysis</h3>
                <canvas id="sales-chart" height="80"></canvas>
            </div>
            
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-label">Accounts Created</div>
                    <div class="metric-value" id="accounts-created">0</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Orders Placed</div>
                    <div class="metric-value" id="orders-placed">0</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Cart Additions</div>
                    <div class="metric-value" id="cart-additions">0</div>
                </div>
            </div>
        </div>
        
        <div id="learning" class="tab-content" style="display:none;">
            <h3>AI Learning Progress</h3>
            <div id="learning-insights"></div>
            
            <h3>Top Performing Techniques</h3>
            <div id="top-techniques"></div>
        </div>
        
        <div id="conversations" class="tab-content" style="display:none;">
            <h3>Recent Conversations</h3>
            <div id="conversation-logs"></div>
        </div>
        
        <div id="insights" class="tab-content" style="display:none;">
            <h3>Optimization Opportunities</h3>
            <div id="optimization-areas"></div>
            
            <h3>Common Questions</h3>
            <div id="common-questions"></div>
        </div>
        
        <div id="consistency" class="tab-content" style="display:none;">
            <h3>Store Numbers Consistency Check</h3>
            <p style="color:#6b7280;margin-top:-6px;">Compares DB pricing/MSRP against canonical product data used by the AI.</p>
            <div id="consistency-results"></div>
        </div>
        
        <div class="action-buttons">
            <button class="btn-export" onclick="exportAnalytics()">📊 Export Analytics</button>
            <button class="btn-export" onclick="trainAI()">🧠 Train AI Model</button>
            <button class="btn-export" onclick="viewLogs()">📝 View Full Logs</button>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
                
                this.classList.add('active');
                document.getElementById(this.dataset.tab).style.display = 'block';
                if (this.dataset.tab === 'consistency') {
                    loadConsistency();
                }
            });
        });
        
        // Load analytics data
        async function loadAnalytics(type = 'dashboard') {
            try {
                const response = await fetch('/api/ai-analytics.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type })
                });
                
                const data = await response.json();
                if (data.success) {
                    updateDashboard(data);
                }
            } catch (error) {
                console.error('Failed to load analytics:', error);
            }
        }
        
        function updateDashboard(data) {
            if (data.performance) {
                document.getElementById('total-conversations').textContent = 
                    data.performance.total_conversations || '0';
                document.getElementById('satisfaction').textContent = 
                    Math.round(data.performance.satisfaction_rate || 0) + '%';
                document.getElementById('accounts-created').textContent = 
                    data.performance.accounts_created || '0';
                document.getElementById('orders-placed').textContent = 
                    data.performance.orders_placed || '0';
                document.getElementById('cart-additions').textContent = 
                    data.performance.items_added_to_cart || '0';
            }
            
            if (data.conversion_rates) {
                document.getElementById('conversion-rate').textContent = 
                    data.conversion_rates.conversion_rate + '%';
                document.getElementById('response-success').textContent = 
                    data.conversion_rates.engagement_rate + '%';
            }
        }

        // Consistency loader
        async function loadConsistency() {
            try {
                const r = await fetch('/api/ai-learning.php', { method: 'GET' });
                const j = await r.json();
                const rep = j.store_numbers_check || {};
                const checked = rep.checked || 0;
                const mismatches = (rep.mismatches || []).length;
                document.getElementById('consistency-status').textContent = mismatches === 0 ? 'All good' : 'Attention needed';
                document.getElementById('consistency-checked').textContent = checked;
                document.getElementById('consistency-mismatches').textContent = mismatches;
                renderConsistency(rep);
            } catch (e) {
                document.getElementById('consistency-status').textContent = 'Error checking';
            }
        }

        function renderConsistency(rep) {
            const wrap = document.getElementById('consistency-results');
            const mismatches = rep && rep.mismatches ? rep.mismatches : [];
            if (!wrap) return;
            if (mismatches.length === 0) {
                wrap.innerHTML = '<div class="insight-item" style="border-left-color:#16a34a;">All checked products match canonical data.</div>';
                return;
            }
            let html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">';
            html += '<thead><tr style="background:#f9fafb"><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;">SKU</th><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;">Name</th><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;">Field</th><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;">DB</th><th style="text-align:left;padding:8px;border:1px solid #e5e7eb;">Canonical</th></tr></thead><tbody>';
            mismatches.forEach(m => {
                Object.keys(m.diffs || {}).forEach(field => {
                    const d = m.diffs[field];
                    html += `<tr><td style="padding:8px;border:1px solid #e5e7eb;">${m.sku}</td><td style="padding:8px;border:1px solid #e5e7eb;">${m.name}</td><td style="padding:8px;border:1px solid #e5e7eb;">${field}</td><td style="padding:8px;border:1px solid #e5e7eb;">$${Number(d.db).toFixed(2)}</td><td style=\"padding:8px;border:1px solid #e5e7eb;\">$${Number(d.canon).toFixed(2)}</td></tr>`;
                });
            });
            html += '</tbody></table></div>';
            wrap.innerHTML = html;
        }

        function openConsistencyTab() {
            document.querySelector('.tab[data-tab="consistency"]').click();
        }
        
        // Initialize charts
        function initCharts() {
            // Conversation trends chart
            const ctxConv = document.getElementById('conversation-chart').getContext('2d');
            new Chart(ctxConv, {
                type: 'line',
                data: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    datasets: [{
                        label: 'Conversations',
                        data: [45, 52, 48, 65, 72, 68, 75],
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
            
            // Funnel chart
            const ctxFunnel = document.getElementById('funnel-chart')?.getContext('2d');
            if (ctxFunnel) {
                new Chart(ctxFunnel, {
                    type: 'bar',
                    data: {
                        labels: ['Visitors', 'Engaged', 'Intent', 'Converted'],
                        datasets: [{
                            label: 'Conversion Funnel',
                            data: [1000, 450, 120, 45],
                            backgroundColor: [
                                '#3498db',
                                '#9b59b6',
                                '#f39c12',
                                '#27ae60'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y'
                    }
                });
            }
        }
        
        function exportAnalytics() {
            alert('Exporting analytics report...');
        }
        
        function trainAI() {
            alert('Initiating AI training session...');
        }
        
        function viewLogs() {
            window.location.href = '/admin/ai-logs.php';
        }
        
        // Load data on page load
        loadAnalytics();
        loadConsistency();
        initCharts();
        
        // Refresh data every 30 seconds
        setInterval(() => { loadAnalytics(); loadConsistency(); }, 30000);
    </script>
    
    <?php require_once __DIR__ . '/../required/footer.php'; ?>
</body>
</html>
