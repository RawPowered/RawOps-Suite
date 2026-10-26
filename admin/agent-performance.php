<?php
$SEO = [
    'title' => 'Agent Performance Details — Admin',
    'description' => 'Detailed performance metrics for sales agents.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$agent_id = intval($_GET['id'] ?? 0);

if (!$agent_id) {
    redirect(path_with_base('/admin/commissions.php'));
}

// Get agent details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$agent_id]);
$agent = $stmt->fetch();

if (!$agent) {
    flash_set('error', 'Agent not found');
    redirect(path_with_base('/admin/commissions.php'));
}

// Get date range
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get agent's performance metrics
$metrics = [];

try {
    // Total sales
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as total_orders,
            COALESCE(SUM(o.total), 0) as total_sales,
            COALESCE(AVG(o.total), 0) as avg_order_value,
            COUNT(DISTINCT o.wholesale_account_id) as unique_customers,
            COUNT(DISTINCT DATE(o.created_at)) as active_days
        FROM orders o
        WHERE o.sales_agent_id = ? 
        AND o.created_at BETWEEN ? AND ?
        AND o.status != 'cancelled'
    ");
    $stmt->execute([$agent_id, $start_date, $end_date]);
    $metrics = $stmt->fetch();
    
    // Commission metrics
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total_commissions,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as paid_commissions,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_commissions,
            COALESCE(AVG(percentage), 0) as avg_commission_rate
        FROM commissions
        WHERE user_id = ?
        AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$agent_id, $start_date, $end_date]);
    $commission_metrics = $stmt->fetch();
    $metrics = array_merge($metrics, $commission_metrics);
    
    // Get top customers
    $stmt = $pdo->prepare("
        SELECT 
            wa.business_name,
            wa.id as account_id,
            COUNT(o.id) as order_count,
            SUM(o.total) as total_spent,
            MAX(o.created_at) as last_order_date,
            MIN(o.created_at) as first_order_date
        FROM orders o
        JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        WHERE o.sales_agent_id = ?
        AND o.created_at BETWEEN ? AND ?
        AND o.status != 'cancelled'
        GROUP BY wa.id
        ORDER BY total_spent DESC
        LIMIT 10
    ");
    $stmt->execute([$agent_id, $start_date, $end_date]);
    $top_customers = $stmt->fetchAll();
    
    // Get repeat customers (customers with 2+ orders)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT wholesale_account_id) 
        FROM (
            SELECT wholesale_account_id, COUNT(*) as cnt
            FROM orders
            WHERE sales_agent_id = ?
            AND created_at BETWEEN ? AND ?
            AND status != 'cancelled'
            GROUP BY wholesale_account_id
            HAVING cnt >= 2
        ) as repeat_customers
    ");
    $stmt->execute([$agent_id, $start_date, $end_date]);
    $metrics['repeat_customers'] = $stmt->fetchColumn();
    
    // Calculate retention rate
    if ($metrics['unique_customers'] > 0) {
        $metrics['retention_rate'] = ($metrics['repeat_customers'] / $metrics['unique_customers']) * 100;
    } else {
        $metrics['retention_rate'] = 0;
    }
    
    // Get monthly performance
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(o.created_at, '%Y-%m') as month,
            COUNT(o.id) as orders,
            SUM(o.total) as sales,
            SUM(c.amount) as commissions
        FROM orders o
        LEFT JOIN commissions c ON c.order_id = o.id AND c.user_id = ?
        WHERE o.sales_agent_id = ?
        AND o.created_at BETWEEN ? AND ?
        AND o.status != 'cancelled'
        GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
        ORDER BY month DESC
    ");
    $stmt->execute([$agent_id, $agent_id, $start_date, $end_date]);
    $monthly_performance = $stmt->fetchAll();
    
    // Get product performance
    $stmt = $pdo->prepare("
        SELECT 
            oi.product_name,
            oi.product_sku,
            COUNT(DISTINCT oi.order_id) as times_sold,
            SUM(oi.quantity) as units_sold,
            SUM(oi.line_total) as revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.sales_agent_id = ?
        AND o.created_at BETWEEN ? AND ?
        AND o.status != 'cancelled'
        GROUP BY oi.product_id
        ORDER BY revenue DESC
    ");
    $stmt->execute([$agent_id, $start_date, $end_date]);
    $product_performance = $stmt->fetchAll();
    
} catch (Exception $e) {
    // Tables might not exist
    $metrics = [
        'total_orders' => 0,
        'total_sales' => 0,
        'avg_order_value' => 0,
        'unique_customers' => 0,
        'active_days' => 0,
        'total_commissions' => 0,
        'paid_commissions' => 0,
        'pending_commissions' => 0,
        'avg_commission_rate' => 0,
        'repeat_customers' => 0,
        'retention_rate' => 0
    ];
    $top_customers = [];
    $monthly_performance = [];
    $product_performance = [];
}

// Get all accounts assigned to this agent
$stmt = $pdo->prepare("
    SELECT 
        wa.*,
        u.email as user_email,
        (SELECT COUNT(*) FROM orders WHERE wholesale_account_id = wa.id) as total_orders,
        (SELECT SUM(total) FROM orders WHERE wholesale_account_id = wa.id) as lifetime_value
    FROM wholesale_accounts wa
    JOIN users u ON u.id = wa.user_id
    WHERE wa.sales_agent_id = ?
    ORDER BY lifetime_value DESC
");
$stmt->execute([$agent_id]);
$assigned_accounts = $stmt->fetchAll();
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
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.metric-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.metric-value {
    font-size: 28px;
    font-weight: bold;
    margin: 8px 0;
}
.metric-label {
    color: #6b7280;
    font-size: 13px;
    text-transform: uppercase;
}
.performance-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}
.performance-table table {
    width: 100%;
    border-collapse: collapse;
}
.performance-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 14px;
}
.performance-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
}
.chart-container {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}
.progress-bar {
    width: 100%;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    background: linear-gradient(to right, #3b82f6, #10b981);
}
.customer-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.badge-gold { background: #fef3c7; color: #92400e; }
.badge-silver { background: #e0e7ff; color: #4338ca; }
.badge-bronze { background: #fed7aa; color: #9a3412; }
</style>

<div class="admin-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">
            <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>
        </h1>
        <p style="font-size: 18px; opacity: 0.9;">
            Performance Analytics • <?php echo htmlspecialchars($agent['email']); ?>
        </p>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Date Filter -->
    <div class="card" style="margin-bottom: 24px;">
        <form method="get" style="display: flex; gap: 16px; align-items: center;">
            <input type="hidden" name="id" value="<?php echo $agent_id; ?>">
            <div>
                <label style="display: block; margin-bottom: 4px; font-size: 14px;">Start Date</label>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>" 
                       style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 4px; font-size: 14px;">End Date</label>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>" 
                       style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
            </div>
            <button type="submit" class="btn" style="margin-top: 20px;">Update</button>
            <a href="<?php echo path_with_base('/admin/commissions.php'); ?>" class="btn secondary" style="margin-top: 20px;">Back to Commissions</a>
        </form>
    </div>
    
    <!-- Key Metrics -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-label">Total Sales</div>
            <div class="metric-value">$<?php echo number_format($metrics['total_sales'], 2); ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Total Orders</div>
            <div class="metric-value"><?php echo $metrics['total_orders']; ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Avg Order Value</div>
            <div class="metric-value">$<?php echo number_format($metrics['avg_order_value'], 2); ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Total Commissions</div>
            <div class="metric-value" style="color: #059669;">$<?php echo number_format($metrics['total_commissions'], 2); ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Unique Customers</div>
            <div class="metric-value"><?php echo $metrics['unique_customers']; ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Retention Rate</div>
            <div class="metric-value"><?php echo round($metrics['retention_rate'], 1); ?>%</div>
            <div style="margin-top: 8px;">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $metrics['retention_rate']; ?>%"></div>
                </div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Repeat Customers</div>
            <div class="metric-value"><?php echo $metrics['repeat_customers']; ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Active Days</div>
            <div class="metric-value"><?php echo $metrics['active_days']; ?></div>
        </div>
    </div>
    
    <!-- Commission Breakdown -->
    <div class="card" style="margin-bottom: 24px;">
        <h2 style="margin-bottom: 16px;">Commission Status</h2>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
            <div>
                <h4 style="color: #059669; margin-bottom: 8px;">Paid</h4>
                <p style="font-size: 24px; font-weight: 600;">$<?php echo number_format($metrics['paid_commissions'], 2); ?></p>
            </div>
            <div>
                <h4 style="color: #f59e0b; margin-bottom: 8px;">Pending</h4>
                <p style="font-size: 24px; font-weight: 600;">$<?php echo number_format($metrics['pending_commissions'], 2); ?></p>
            </div>
            <div>
                <h4 style="color: #3b82f6; margin-bottom: 8px;">Avg Rate</h4>
                <p style="font-size: 24px; font-weight: 600;"><?php echo round($metrics['avg_commission_rate'], 1); ?>%</p>
            </div>
        </div>
    </div>
    
    <!-- Top Customers -->
    <div class="performance-table">
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
            <h2 style="margin: 0;">Top Customers</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Business</th>
                    <th>Orders</th>
                    <th>Total Spent</th>
                    <th>First Order</th>
                    <th>Last Order</th>
                    <th>Days Active</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($top_customers)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;">
                        No customer data for this period
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($top_customers as $index => $customer): 
                        $badge = $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : ''));
                        $days_active = ceil((strtotime($customer['last_order_date']) - strtotime($customer['first_order_date'])) / 86400);
                    ?>
                    <tr>
                        <td>
                            <?php if ($badge): ?>
                                <span class="customer-badge badge-<?php echo $badge; ?>">#<?php echo $index + 1; ?></span>
                            <?php else: ?>
                                #<?php echo $index + 1; ?>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($customer['business_name']); ?></strong></td>
                        <td><?php echo $customer['order_count']; ?></td>
                        <td style="font-weight: 600; color: #059669;">$<?php echo number_format($customer['total_spent'], 2); ?></td>
                        <td><?php echo date('M d, Y', strtotime($customer['first_order_date'])); ?></td>
                        <td><?php echo date('M d, Y', strtotime($customer['last_order_date'])); ?></td>
                        <td><?php echo $days_active; ?> days</td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Monthly Performance -->
    <div class="performance-table">
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
            <h2 style="margin: 0;">Monthly Performance</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Orders</th>
                    <th>Sales</th>
                    <th>Commissions</th>
                    <th>Avg Order</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($monthly_performance)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: #6b7280;">
                        No data for this period
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($monthly_performance as $month): ?>
                    <tr>
                        <td><strong><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></strong></td>
                        <td><?php echo $month['orders']; ?></td>
                        <td>$<?php echo number_format($month['sales'], 2); ?></td>
                        <td style="color: #059669;">$<?php echo number_format($month['commissions'], 2); ?></td>
                        <td>$<?php echo number_format($month['sales'] / max(1, $month['orders']), 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Product Performance -->
    <div class="performance-table">
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
            <h2 style="margin: 0;">Product Performance</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Times Sold</th>
                    <th>Units Sold</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($product_performance)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: #6b7280;">
                        No product data for this period
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($product_performance as $product): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                        <td style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($product['product_sku']); ?></td>
                        <td><?php echo $product['times_sold']; ?></td>
                        <td><?php echo $product['units_sold']; ?></td>
                        <td style="font-weight: 600;">$<?php echo number_format($product['revenue'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Assigned Accounts -->
    <div class="performance-table">
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
            <h2 style="margin: 0;">Assigned Accounts (<?php echo count($assigned_accounts); ?>)</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Business Name</th>
                    <th>Contact Email</th>
                    <th>Location</th>
                    <th>Total Orders</th>
                    <th>Lifetime Value</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assigned_accounts)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">
                        No accounts assigned to this agent
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($assigned_accounts as $account): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($account['business_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($account['user_email']); ?></td>
                        <td><?php echo htmlspecialchars($account['city'] . ', ' . $account['state_region']); ?></td>
                        <td><?php echo intval($account['total_orders']); ?></td>
                        <td style="font-weight: 600; color: #059669;">$<?php echo number_format($account['lifetime_value'] ?? 0, 2); ?></td>
                        <td>
                            <?php if ($account['approved']): ?>
                                <span style="color: #059669;">✓ Active</span>
                            <?php else: ?>
                                <span style="color: #f59e0b;">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
