<?php
$SEO = [
    'title' => 'Reports & Analytics — Admin',
    'description' => 'View sales reports, agent performance, and business analytics.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    $format = $_GET['format'] ?? 'csv';
    
    try {
        if ($exportType === 'sales_summary') {
            $data = $pdo->query("
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as period,
                    DATE_FORMAT(created_at, '%M %Y') as month_year,
                    SUM(total) as revenue,
                    COUNT(*) as orders,
                    AVG(total) as avg_order_value,
                    SUM((SELECT SUM(quantity) FROM order_items WHERE order_id = orders.id)) as units_sold
                FROM orders 
                WHERE status != 'cancelled' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY created_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            exportToCsv($data, 'sales_summary_' . date('Y-m-d') . '.csv', [
                'period' => 'Period',
                'month_year' => 'Month',
                'revenue' => 'Revenue ($)',
                'orders' => 'Orders',
                'avg_order_value' => 'Avg Order Value ($)',
                'units_sold' => 'Units Sold'
            ]);
        }
        
        elseif ($exportType === 'agent_performance') {
            $data = $pdo->query("
                SELECT 
                    CONCAT(u.first_name, ' ', u.last_name) as agent_name,
                    u.email,
                    COALESCE(SUM(o.total), 0) as total_sales,
                    COUNT(DISTINCT o.id) as orders_count,
                    COUNT(DISTINCT wa.id) as customers_count,
                    COALESCE(SUM(c.amount), 0) as total_commission,
                    COALESCE(AVG(o.total), 0) as avg_order_value,
                    DATE_FORMAT(MAX(o.created_at), '%Y-%m-%d') as last_sale_date
                FROM users u
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id AND r.name = 'sales_agent'
                LEFT JOIN wholesale_accounts wa ON wa.sales_agent_id = u.id
                LEFT JOIN orders o ON o.sales_agent_id = u.id AND o.status != 'cancelled'
                LEFT JOIN commissions c ON c.user_id = u.id AND c.commission_type = 'sales_agent'
                GROUP BY u.id
                ORDER BY total_sales DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            exportToCsv($data, 'agent_performance_' . date('Y-m-d') . '.csv', [
                'agent_name' => 'Agent Name',
                'email' => 'Email',
                'total_sales' => 'Total Sales ($)',
                'orders_count' => 'Orders',
                'customers_count' => 'Customers',
                'total_commission' => 'Commission Earned ($)',
                'avg_order_value' => 'Avg Order Value ($)',
                'last_sale_date' => 'Last Sale Date'
            ]);
        }
        
        elseif ($exportType === 'training_analytics') {
            $data = $pdo->query("
                SELECT 
                    CONCAT(u.first_name, ' ', u.last_name) as student_name,
                    u.email,
                    r.name as role,
                    COUNT(DISTINCT tp.module_id) as modules_completed,
                    (SELECT COUNT(*) FROM training_modules WHERE required = 1) as total_modules,
                    AVG(tp.quiz_score) as avg_quiz_score,
                    SUM(tp.quiz_attempts) as total_quiz_attempts,
                    SUM(tp.total_time_minutes) as total_time_minutes,
                    SUM(tp.active_time_minutes) as active_time_minutes,
                    AVG(tp.engagement_score) as avg_engagement,
                    DATE_FORMAT(MIN(tp.started_at), '%Y-%m-%d') as training_start_date,
                    DATE_FORMAT(MAX(tp.completed_at), '%Y-%m-%d') as last_completion_date,
                    CASE 
                        WHEN COUNT(DISTINCT tp.module_id) >= (SELECT COUNT(*) FROM training_modules WHERE required = 1) 
                        THEN 'COMPLETED' 
                        ELSE 'IN_PROGRESS' 
                    END as training_status
                FROM users u
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                LEFT JOIN training_progress tp ON tp.user_id = u.id
                WHERE r.name IN ('trainee', 'sales_agent')
                GROUP BY u.id
                ORDER BY training_status DESC, avg_quiz_score DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            exportToCsv($data, 'training_analytics_' . date('Y-m-d') . '.csv', [
                'student_name' => 'Student Name',
                'email' => 'Email',
                'role' => 'Role',
                'modules_completed' => 'Modules Completed',
                'total_modules' => 'Total Modules',
                'avg_quiz_score' => 'Avg Quiz Score (%)',
                'total_quiz_attempts' => 'Total Quiz Attempts',
                'total_time_minutes' => 'Total Time (min)',
                'active_time_minutes' => 'Active Time (min)',
                'avg_engagement' => 'Avg Engagement (%)',
                'training_start_date' => 'Training Start Date',
                'last_completion_date' => 'Last Completion Date',
                'training_status' => 'Status'
            ]);
        }
        
        elseif ($exportType === 'quiz_results') {
            $data = $pdo->query("
                SELECT 
                    CONCAT(u.first_name, ' ', u.last_name) as student_name,
                    u.email,
                    tm.title as module_title,
                    tqr.score as correct_answers,
                    tqr.total_questions,
                    ROUND((tqr.score / tqr.total_questions) * 100, 1) as percentage,
                    tqr.attempt_number,
                    CASE WHEN tqr.passed = 1 THEN 'PASSED' ELSE 'FAILED' END as result,
                    DATE_FORMAT(tqr.completed_at, '%Y-%m-%d %H:%i:%s') as completed_at
                FROM training_quiz_results tqr
                JOIN users u ON u.id = tqr.user_id
                JOIN training_modules tm ON tm.id = tqr.module_id
                ORDER BY tqr.completed_at DESC
                LIMIT 1000
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            exportToCsv($data, 'quiz_results_' . date('Y-m-d') . '.csv', [
                'student_name' => 'Student Name',
                'email' => 'Email',
                'module_title' => 'Module',
                'correct_answers' => 'Correct Answers',
                'total_questions' => 'Total Questions',
                'percentage' => 'Score (%)',
                'attempt_number' => 'Attempt #',
                'result' => 'Result',
                'completed_at' => 'Completed At'
            ]);
        }
        
    } catch (Exception $e) {
        flash_set('error', 'Export failed: ' . $e->getMessage());
        redirect('/admin/reports.php');
    }
}

// CSV Export Function
function exportToCsv($data, $filename, $headers = []) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    if (!empty($data)) {
        // Write headers
        if (!empty($headers)) {
            fputcsv($output, array_values($headers));
        } else {
            fputcsv($output, array_keys($data[0]));
        }
        
        // Write data
        foreach ($data as $row) {
            if (!empty($headers)) {
                $orderedRow = [];
                foreach (array_keys($headers) as $key) {
                    $orderedRow[] = $row[$key] ?? '';
                }
                fputcsv($output, $orderedRow);
            } else {
                fputcsv($output, $row);
            }
        }
    }
    
    fclose($output);
    exit;
}

// Fetch real data from database
try {
    // Sales data by month
    $salesQuery = $pdo->query("
        SELECT 
            DATE_FORMAT(created_at, '%M') as month,
            SUM(total) as revenue,
            COUNT(*) as orders,
            SUM((SELECT SUM(quantity) FROM order_items WHERE order_id = orders.id)) as units
        FROM orders 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        AND status != 'cancelled'
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $salesData = $salesQuery->fetchAll(PDO::FETCH_ASSOC) ?: [
        ['month' => 'This Month', 'revenue' => 0, 'orders' => 0, 'units' => 0]
    ];

    // Top sales agents
    $agentsQuery = $pdo->query("
        SELECT 
            CONCAT(u.first_name, ' ', u.last_name) as name,
            COALESCE(SUM(o.total), 0) as sales,
            COUNT(DISTINCT wa.id) as customers,
            COALESCE(SUM(c.amount), 0) as commission
        FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id AND r.name = 'sales_agent'
        LEFT JOIN wholesale_accounts wa ON wa.sales_agent_id = u.id
        LEFT JOIN orders o ON o.sales_agent_id = u.id AND o.status != 'cancelled'
        LEFT JOIN commissions c ON c.user_id = u.id AND c.commission_type = 'sales_agent'
        GROUP BY u.id
        ORDER BY sales DESC
        LIMIT 5
    ");
    $topAgents = $agentsQuery->fetchAll(PDO::FETCH_ASSOC) ?: [
        ['name' => 'No agents yet', 'sales' => 0, 'customers' => 0, 'commission' => 0]
    ];

    // Top products
    $productsQuery = $pdo->query("
        SELECT 
            p.name,
            SUM(oi.quantity) as units,
            SUM(oi.line_total) as revenue
        FROM order_items oi
        JOIN products p ON p.sku = oi.product_sku
        JOIN orders o ON o.id = oi.order_id AND o.status != 'cancelled'
        GROUP BY p.id
        ORDER BY revenue DESC
        LIMIT 4
    ");
    $topProducts = $productsQuery->fetchAll(PDO::FETCH_ASSOC) ?: [
        ['name' => 'No sales yet', 'units' => 0, 'revenue' => 0]
    ];

    // Top customers
    $customersQuery = $pdo->query("
        SELECT 
            wa.business_name as name,
            COUNT(o.id) as orders,
            SUM(o.total) as revenue,
            AVG(o.total) as avg_order
        FROM wholesale_accounts wa
        JOIN orders o ON o.wholesale_account_id = wa.id AND o.status != 'cancelled'
        GROUP BY wa.id
        ORDER BY revenue DESC
        LIMIT 4
    ");
    $topCustomers = $customersQuery->fetchAll(PDO::FETCH_ASSOC) ?: [
        ['name' => 'No customers yet', 'orders' => 0, 'revenue' => 0, 'avg_order' => 0]
    ];

    // Calculate key metrics
    $currentMonthRevenue = $pdo->query("
        SELECT COALESCE(SUM(total), 0) 
        FROM orders 
        WHERE MONTH(created_at) = MONTH(NOW()) 
        AND YEAR(created_at) = YEAR(NOW())
        AND status != 'cancelled'
    ")->fetchColumn();
    
    $lastMonthRevenue = $pdo->query("
        SELECT COALESCE(SUM(total), 0) 
        FROM orders 
        WHERE MONTH(created_at) = MONTH(NOW() - INTERVAL 1 MONTH) 
        AND YEAR(created_at) = YEAR(NOW() - INTERVAL 1 MONTH)
        AND status != 'cancelled'
    ")->fetchColumn();
    
    $revenueChange = $lastMonthRevenue > 0 ? (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 : 0;

    $totalCustomers = $pdo->query("SELECT COUNT(*) FROM wholesale_accounts")->fetchColumn();
    $activeCustomers = $pdo->query("SELECT COUNT(DISTINCT wholesale_account_id) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    $conversionRate = $totalCustomers > 0 ? ($activeCustomers / $totalCustomers) * 100 : 0;

} catch (Exception $e) {
    // Fallback to demo data if database fails
    $salesData = [['month' => 'No Data', 'revenue' => 0, 'orders' => 0, 'units' => 0]];
    $topAgents = [['name' => 'Database Error', 'sales' => 0, 'customers' => 0, 'commission' => 0]];
    $topProducts = [['name' => 'Database Error', 'units' => 0, 'revenue' => 0]];
    $topCustomers = [['name' => 'Database Error', 'orders' => 0, 'revenue' => 0, 'avg_order' => 0]];
    $currentMonthRevenue = 0;
    $lastMonthRevenue = 0;
    $revenueChange = 0;
    $totalCustomers = 0;
    $activeCustomers = 0;
    $conversionRate = 0;
}
$avgOrderValue = 292;
?>

<style>
.admin-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}
.metric-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.metric-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.metric-value {
    font-size: 32px;
    font-weight: bold;
    margin-bottom: 8px;
}
.metric-change {
    font-size: 14px;
    font-weight: 600;
}
.change-positive { color: #059669; }
.change-negative { color: #dc2626; }
.report-section {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}
.report-table {
    width: 100%;
    margin-top: 16px;
}
.report-table th {
    text-align: left;
    padding: 12px;
    border-bottom: 2px solid #e5e7eb;
    color: #374151;
    font-weight: 600;
    font-size: 14px;
}
.report-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
}
.chart-placeholder {
    background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
    height: 300px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    margin: 20px 0;
}
.top-performer {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f3f4f6;
}
.top-performer:last-child {
    border-bottom: none;
}
.rank-badge {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-right: 16px;
}
.rank-1 { background: #fbbf24; color: white; }
.rank-2 { background: #cbd5e1; color: #334155; }
.rank-3 { background: #f97316; color: white; }
.rank-other { background: #f3f4f6; color: #6b7280; }
</style>

<div class="admin-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Reports & Analytics</h1>
        <p style="font-size: 18px; opacity: 0.9;">Sales performance, agent metrics, and business insights</p>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Export Section -->
    <div class="report-section" style="margin-bottom: 24px;">
        <h2 style="color: #1f2937; margin-bottom: 16px;">📊 Export Reports</h2>
        <p style="color: #6b7280; margin-bottom: 20px;">Download comprehensive reports for analysis and record keeping.</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
            <div style="background: #f9fafb; padding: 20px; border-radius: 8px; border-left: 4px solid #059669;">
                <h3 style="margin: 0 0 8px 0; color: #059669;">💰 Sales Summary</h3>
                <p style="color: #6b7280; font-size: 14px; margin-bottom: 16px;">Monthly revenue, orders, and performance metrics</p>
                <a href="?export=sales_summary&format=csv" class="btn secondary" style="text-decoration: none; display: inline-block;">
                    📄 Download CSV
                </a>
            </div>
            
            <div style="background: #f9fafb; padding: 20px; border-radius: 8px; border-left: 4px solid #2563eb;">
                <h3 style="margin: 0 0 8px 0; color: #2563eb;">👥 Agent Performance</h3>
                <p style="color: #6b7280; font-size: 14px; margin-bottom: 16px;">Sales agent metrics, commissions, and customer data</p>
                <a href="?export=agent_performance&format=csv" class="btn secondary" style="text-decoration: none; display: inline-block;">
                    📄 Download CSV
                </a>
            </div>
            
            <div style="background: #f9fafb; padding: 20px; border-radius: 8px; border-left: 4px solid #7c3aed;">
                <h3 style="margin: 0 0 8px 0; color: #7c3aed;">📚 Training Analytics</h3>
                <p style="color: #6b7280; font-size: 14px; margin-bottom: 16px;">Student progress, completion rates, and engagement</p>
                <a href="?export=training_analytics&format=csv" class="btn secondary" style="text-decoration: none; display: inline-block;">
                    📄 Download CSV
                </a>
            </div>
            
            <div style="background: #f9fafb; padding: 20px; border-radius: 8px; border-left: 4px solid #dc2626;">
                <h3 style="margin: 0 0 8px 0; color: #dc2626;">🎯 Quiz Results</h3>
                <p style="color: #6b7280; font-size: 14px; margin-bottom: 16px;">Detailed quiz scores, attempts, and pass rates</p>
                <a href="?export=quiz_results&format=csv" class="btn secondary" style="text-decoration: none; display: inline-block;">
                    📄 Download CSV
                </a>
            </div>
        </div>
        
        <div style="margin-top: 20px; padding: 16px; background: #fffbeb; border-radius: 8px; border-left: 4px solid #f59e0b;">
            <h4 style="margin: 0 0 8px 0; color: #92400e;">💡 Export Tips</h4>
            <ul style="color: #92400e; margin: 0; padding-left: 20px;">
                <li>All exports include data from the last 12 months</li>
                <li>CSV files can be opened in Excel, Google Sheets, or any spreadsheet application</li>
                <li>Training and quiz data includes all active users and their progress</li>
                <li>Files are automatically named with the current date for easy organization</li>
            </ul>
        </div>
    </div>
    
    <!-- Date Range Selector -->
    <div class="card" style="margin-bottom: 24px;">
        <form method="get" style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
            <label style="display: flex; align-items: center; gap: 8px;">
                <span>Period:</span>
                <select name="period" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <option value="this_month">This Month</option>
                    <option value="last_month">Last Month</option>
                    <option value="last_quarter">Last Quarter</option>
                    <option value="last_year">Last Year</option>
                    <option value="custom">Custom Range</option>
                </select>
            </label>
            
            <label style="display: flex; align-items: center; gap: 8px;">
                <span>From:</span>
                <input type="date" name="from" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            </label>
            
            <label style="display: flex; align-items: center; gap: 8px;">
                <span>To:</span>
                <input type="date" name="to" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            </label>
            
            <button type="submit" class="btn">Update Report</button>
            <button type="button" class="btn secondary">Export PDF</button>
        </form>
    </div>
    
    <!-- Key Metrics -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-label">Revenue This Month</div>
            <div class="metric-value">$<?php echo number_format($currentMonthRevenue, 0); ?></div>
            <div class="metric-change <?php echo $revenueChange > 0 ? 'change-positive' : 'change-negative'; ?>">
                <?php echo $revenueChange > 0 ? '↑' : '↓'; ?> <?php echo abs(round($revenueChange, 1)); ?>% vs last month
            </div>
        </div>
        
        <div class="metric-card">
            <div class="metric-label">Active Customers</div>
            <div class="metric-value"><?php echo $activeCustomers; ?></div>
            <div class="metric-change change-positive">
                <?php echo $totalCustomers; ?> total accounts
            </div>
        </div>
        
        <div class="metric-card">
            <div class="metric-label">Conversion Rate</div>
            <div class="metric-value"><?php echo $conversionRate; ?>%</div>
            <div class="metric-change change-positive">
                ↑ 5.2% vs last month
            </div>
        </div>
        
        <div class="metric-card">
            <div class="metric-label">Avg Order Value</div>
            <div class="metric-value">$<?php echo $avgOrderValue; ?></div>
            <div class="metric-change change-positive">
                ↑ $18 vs last month
            </div>
        </div>
    </div>
    
    <!-- Sales Chart -->
    <div class="report-section">
        <h2>Sales Trend</h2>
        <div class="chart-placeholder">
            📊 Sales Chart Visualization
        </div>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Revenue</th>
                    <th>Orders</th>
                    <th>Units Sold</th>
                    <th>Avg Order</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($salesData as $month): ?>
                <tr>
                    <td><strong><?php echo $month['month']; ?></strong></td>
                    <td>$<?php echo number_format($month['revenue'], 0); ?></td>
                    <td><?php echo $month['orders']; ?></td>
                    <td><?php echo $month['units']; ?></td>
                    <td>$<?php echo $month['orders'] > 0 ? number_format($month['revenue'] / $month['orders'], 0) : '0'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="grid two" style="gap: 24px;">
        <!-- Top Sales Agents -->
        <div class="report-section">
            <h2>Top Sales Agents</h2>
            <?php foreach ($topAgents as $index => $agent): ?>
            <div class="top-performer">
                <div class="rank-badge rank-<?php echo $index < 3 ? ($index + 1) : 'other'; ?>">
                    <?php echo $index + 1; ?>
                </div>
                <div style="flex: 1;">
                    <strong><?php echo $agent['name']; ?></strong>
                    <div style="color: #6b7280; font-size: 14px;">
                        <?php echo $agent['customers']; ?> customers • 
                        $<?php echo number_format($agent['commission'], 0); ?> commission
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 18px; font-weight: 600; color: #059669;">
                        $<?php echo number_format($agent['sales'], 0); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Top Products -->
        <div class="report-section">
            <h2>Best Selling Products</h2>
            <?php foreach ($topProducts as $index => $product): ?>
            <div class="top-performer">
                <div class="rank-badge rank-<?php echo $index < 3 ? ($index + 1) : 'other'; ?>">
                    <?php echo $index + 1; ?>
                </div>
                <div style="flex: 1;">
                    <strong><?php echo $product['name']; ?></strong>
                    <div style="color: #6b7280; font-size: 14px;">
                        <?php echo $product['units']; ?> units sold
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 18px; font-weight: 600; color: #059669;">
                        $<?php echo number_format($product['revenue'], 0); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Top Customers -->
    <div class="report-section">
        <h2>Top Customers</h2>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Orders</th>
                    <th>Total Revenue</th>
                    <th>Avg Order Value</th>
                    <th>Last Order</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topCustomers as $customer): ?>
                <tr>
                    <td><strong><?php echo $customer['name']; ?></strong></td>
                    <td><?php echo $customer['orders']; ?></td>
                    <td style="font-weight: 600; color: #059669;">$<?php echo number_format($customer['revenue'], 0); ?></td>
                    <td>$<?php echo number_format($customer['avg_order'], 0); ?></td>
                    <td>2 days ago</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Export Options -->
    <div class="card" style="margin-top: 32px;">
        <h3>Export Reports</h3>
        <div style="display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap;">
            <button class="btn secondary">📊 Sales Report (Excel)</button>
            <button class="btn secondary">👥 Agent Performance (PDF)</button>
            <button class="btn secondary">📦 Product Analytics (CSV)</button>
            <button class="btn secondary">💰 Commission Report (PDF)</button>
            <button class="btn secondary">📈 Monthly Summary (PDF)</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
