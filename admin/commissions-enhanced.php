<?php
$SEO = [
    'title' => 'Commission Management — Admin',
    'description' => 'Manage sales commissions, tiers, and payouts.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();
$message = '';
$error = '';

// Get date range parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Default to today
$agent_filter = $_GET['agent'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Handle commission actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_settings':
                $developer_commission = floatval($_POST['developer_commission'] ?? 5);
                $default_agent_commission = floatval($_POST['default_agent_commission'] ?? 10);
                
                // Check if settings exist first
                $stmt = $pdo->query("SELECT COUNT(*) FROM commission_settings WHERE setting_key = 'developer_commission'");
                if ($stmt->fetchColumn() == 0) {
                    $stmt = $pdo->prepare("INSERT INTO commission_settings (setting_key, setting_value, description) VALUES ('developer_commission', ?, 'Portfolio Developer developer commission percentage')");
                    $stmt->execute([$developer_commission]);
                } else {
                    $stmt = $pdo->prepare('UPDATE commission_settings SET setting_value = ? WHERE setting_key = ?');
                    $stmt->execute([$developer_commission, 'developer_commission']);
                }
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM commission_settings WHERE setting_key = 'default_agent_commission'");
                if ($stmt->fetchColumn() == 0) {
                    $stmt = $pdo->prepare("INSERT INTO commission_settings (setting_key, setting_value, description) VALUES ('default_agent_commission', ?, 'Default sales agent commission percentage')");
                    $stmt->execute([$default_agent_commission]);
                } else {
                    $stmt = $pdo->prepare('UPDATE commission_settings SET setting_value = ? WHERE setting_key = ?');
                    $stmt->execute([$default_agent_commission, 'default_agent_commission']);
                }
                
                $message = "Commission settings updated successfully";
                break;
                
            case 'add_commission_recipient':
                $user_id = intval($_POST['user_id'] ?? 0);
                $commission_type = $_POST['commission_type'] ?? 'sales_agent';
                $base_rate = floatval($_POST['base_rate'] ?? 10);
                
                // Add user to commission recipients (we'll track this in user_roles or a custom table)
                // For now, ensure they have the sales_agent role
                if ($commission_type === 'sales_agent') {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'sales_agent'");
                    $stmt->execute([$user_id]);
                }
                
                $message = "Commission recipient added successfully";
                break;
                
            case 'approve_commission':
                $commission_id = intval($_POST['commission_id'] ?? 0);
                $stmt = $pdo->prepare('UPDATE commissions SET status = "approved" WHERE id = ?');
                $stmt->execute([$commission_id]);
                $message = "Commission approved";
                break;
                
            case 'mark_paid':
                $commission_ids = $_POST['commission_ids'] ?? [];
                if (!empty($commission_ids)) {
                    $placeholders = str_repeat('?,', count($commission_ids) - 1) . '?';
                    $stmt = $pdo->prepare("UPDATE commissions SET status = 'paid', paid_date = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($commission_ids);
                    $message = count($commission_ids) . " commissions marked as paid";
                }
                break;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all commission recipients (including Portfolio Developer as developer)
// First, ensure Portfolio Developer exists in the system
$developer_id = 1; // Default ID for Portfolio Developer
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR (first_name = ? AND last_name = ?)");
$stmt->execute(['sample-005@example.com', 'Portfolio', 'Developer']);
$developer = $stmt->fetch();
if ($developer) {
    $developer_id = $developer['id'];
} else {
    // Create Portfolio Developer if not exists
    try {
        $stmt = $pdo->prepare("INSERT INTO users (email, first_name, last_name, password_hash, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute(['sample-005@example.com', 'Portfolio', 'Developer', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        $developer_id = $pdo->lastInsertId();
        
        // Add admin role
        $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'admin'");
        $stmt->execute([$developer_id]);
    } catch (Exception $e) {
        // Developer might already exist with different email
    }
}

// Get all commission recipients
$query = "
    SELECT DISTINCT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        CASE 
            WHEN u.id = ? THEN 'developer'
            WHEN r.name = 'sales_agent' THEN 'sales_agent'
            ELSE 'other'
        END as commission_type,
        COUNT(DISTINCT c.id) as total_commissions,
        COALESCE(SUM(CASE WHEN c.created_at BETWEEN ? AND ? THEN c.amount ELSE 0 END), 0) as period_earnings,
        COALESCE(SUM(c.amount), 0) as lifetime_earnings,
        COALESCE(SUM(CASE WHEN c.status = 'paid' THEN c.amount ELSE 0 END), 0) as paid_total,
        COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.amount ELSE 0 END), 0) as pending_total,
        COUNT(DISTINCT wa.id) as active_accounts,
        COUNT(DISTINCT CASE WHEN o.created_at BETWEEN ? AND ? THEN o.id END) as period_orders
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    LEFT JOIN commissions c ON c.user_id = u.id
    LEFT JOIN wholesale_accounts wa ON wa.sales_agent_id = u.id
    LEFT JOIN orders o ON o.sales_agent_id = u.id
    WHERE u.id = ? OR r.name = 'sales_agent' OR u.id IN (SELECT DISTINCT user_id FROM commissions)
    GROUP BY u.id
    ORDER BY period_earnings DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$developer_id, $start_date, $end_date, $start_date, $end_date, $developer_id]);
$recipients = $stmt->fetchAll();

// Get detailed commission history
$history_query = "
    SELECT 
        c.*,
        u.first_name,
        u.last_name,
        u.email,
        o.order_number,
        o.total as order_total,
        wa.business_name
    FROM commissions c
    JOIN users u ON u.id = c.user_id
    LEFT JOIN orders o ON o.id = c.order_id
    LEFT JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
    WHERE c.created_at BETWEEN ? AND ?
";

$params = [$start_date, $end_date];

if ($agent_filter !== 'all') {
    $history_query .= " AND c.user_id = ?";
    $params[] = $agent_filter;
}

if ($status_filter !== 'all') {
    $history_query .= " AND c.status = ?";
    $params[] = $status_filter;
}

$history_query .= " ORDER BY c.created_at DESC";

$stmt = $pdo->prepare($history_query);
$stmt->execute($params);
$commission_history = $stmt->fetchAll();

// Get performance metrics for selected period
$metrics = [
    'total_sales' => 0,
    'total_commissions' => 0,
    'total_orders' => 0,
    'avg_order_value' => 0,
    'top_performer' => null,
    'growth_rate' => 0
];

try {
    // Total sales for period
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $metrics['total_sales'] = $stmt->fetchColumn();
    
    // Total commissions for period
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $metrics['total_commissions'] = $stmt->fetchColumn();
    
    // Total orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $metrics['total_orders'] = $stmt->fetchColumn();
    
    // Average order value
    if ($metrics['total_orders'] > 0) {
        $metrics['avg_order_value'] = $metrics['total_sales'] / $metrics['total_orders'];
    }
    
    // Calculate growth rate (compare to previous period)
    $period_days = (strtotime($end_date) - strtotime($start_date)) / 86400;
    $prev_start = date('Y-m-d', strtotime($start_date) - ($period_days * 86400));
    $prev_end = date('Y-m-d', strtotime($start_date) - 86400);
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$prev_start, $prev_end]);
    $prev_sales = $stmt->fetchColumn();
    
    if ($prev_sales > 0) {
        $metrics['growth_rate'] = (($metrics['total_sales'] - $prev_sales) / $prev_sales) * 100;
    }
} catch (Exception $e) {
    // Tables might not exist yet
}

// Get commission settings
$settings = [
    'developer_commission' => '5.00',
    'default_agent_commission' => '10.00'
];

try {
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM commission_settings');
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Table might not exist
}
?>

<style>
.admin-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.date-filter {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.metric-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.metric-value {
    font-size: 32px;
    font-weight: bold;
    margin: 8px 0;
}
.metric-label {
    color: #6b7280;
    font-size: 13px;
    text-transform: uppercase;
}
.recipients-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.recipient-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    position: relative;
}
.recipient-type {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.type-developer {
    background: #fbbf24;
    color: #92400e;
}
.type-sales_agent {
    background: #3b82f6;
    color: white;
}
.recipient-name {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 4px;
}
.recipient-email {
    color: #6b7280;
    font-size: 14px;
    margin-bottom: 16px;
}
.recipient-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}
.stat-item {
    text-align: center;
}
.stat-value {
    font-size: 20px;
    font-weight: 600;
    color: #059669;
}
.stat-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
}
.commission-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.commission-table table {
    width: 100%;
    border-collapse: collapse;
}
.commission-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 14px;
}
.commission-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-approved { background: #dbeafe; color: #1e40af; }
.status-paid { background: #dcfce7; color: #14532d; }
.growth-indicator {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 8px;
}
.growth-positive { background: #dcfce7; color: #14532d; }
.growth-negative { background: #fee2e2; color: #991b1b; }
</style>

<div class="admin-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Commission Management</h1>
        <p style="font-size: 18px; opacity: 0.9;">Track performance, manage payouts, and analyze commission data</p>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if ($message): ?>
        <div class="card" style="background: #dcfce7; color: #14532d; margin-bottom: 24px;">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Date Range Filter -->
    <div class="date-filter">
        <form method="get" style="display: flex; gap: 16px; align-items: end; flex-wrap: wrap;">
            <div>
                <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px;">Start Date</label>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>" 
                       style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px;">End Date</label>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>" 
                       style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px;">Agent</label>
                <select name="agent" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <option value="all">All Agents</option>
                    <?php foreach ($recipients as $recipient): ?>
                        <option value="<?php echo $recipient['id']; ?>" <?php echo $agent_filter == $recipient['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($recipient['first_name'] . ' ' . $recipient['last_name']); ?>
                            <?php if ($recipient['commission_type'] === 'developer'): ?>(Developer)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px;">Status</label>
                <select name="status" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <option value="all">All Status</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                </select>
            </div>
            <button type="submit" class="btn">Apply Filters</button>
            <button type="button" onclick="window.print()" class="btn secondary">Print Report</button>
        </form>
    </div>
    
    <!-- Period Metrics -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-label">Total Sales</div>
            <div class="metric-value">$<?php echo number_format($metrics['total_sales'], 2); ?></div>
            <?php if ($metrics['growth_rate'] != 0): ?>
                <span class="growth-indicator <?php echo $metrics['growth_rate'] > 0 ? 'growth-positive' : 'growth-negative'; ?>">
                    <?php echo $metrics['growth_rate'] > 0 ? '↑' : '↓'; ?> <?php echo abs(round($metrics['growth_rate'], 1)); ?>%
                </span>
            <?php endif; ?>
        </div>
        <div class="metric-card">
            <div class="metric-label">Total Commissions</div>
            <div class="metric-value" style="color: #f59e0b;">$<?php echo number_format($metrics['total_commissions'], 2); ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Total Orders</div>
            <div class="metric-value"><?php echo $metrics['total_orders']; ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Avg Order Value</div>
            <div class="metric-value">$<?php echo number_format($metrics['avg_order_value'], 2); ?></div>
        </div>
    </div>
    
    <!-- Commission Recipients -->
    <h2 style="margin-bottom: 20px;">Commission Recipients</h2>
    <div class="recipients-grid">
        <?php foreach ($recipients as $recipient): ?>
        <div class="recipient-card">
            <span class="recipient-type type-<?php echo $recipient['commission_type']; ?>">
                <?php echo ucwords(str_replace('_', ' ', $recipient['commission_type'])); ?>
            </span>
            <div class="recipient-name"><?php echo htmlspecialchars($recipient['first_name'] . ' ' . $recipient['last_name']); ?></div>
            <div class="recipient-email"><?php echo htmlspecialchars($recipient['email']); ?></div>
            
            <div class="recipient-stats">
                <div class="stat-item">
                    <div class="stat-value">$<?php echo number_format($recipient['period_earnings'], 2); ?></div>
                    <div class="stat-label">Period Earnings</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">$<?php echo number_format($recipient['lifetime_earnings'], 2); ?></div>
                    <div class="stat-label">Lifetime</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $recipient['period_orders']; ?></div>
                    <div class="stat-label">Orders</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $recipient['active_accounts']; ?></div>
                    <div class="stat-label">Accounts</div>
                </div>
            </div>
            
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="color: #6b7280; font-size: 14px;">Pending:</span>
                    <span style="color: #f59e0b; font-weight: 600;">$<?php echo number_format($recipient['pending_total'], 2); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #6b7280; font-size: 14px;">Paid:</span>
                    <span style="color: #059669; font-weight: 600;">$<?php echo number_format($recipient['paid_total'], 2); ?></span>
                </div>
            </div>
            
            <button onclick="viewAgentDetails(<?php echo $recipient['id']; ?>)" 
                    class="btn" style="width: 100%; margin-top: 16px; font-size: 14px;">
                View Details
            </button>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Commission Settings -->
    <div class="card" style="margin-bottom: 32px;">
        <h2 style="margin-bottom: 20px;">Commission Settings</h2>
        <form method="post">
            <input type="hidden" name="action" value="update_settings">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px;">
                        <strong>Developer Commission (Portfolio Developer)</strong>
                        <input type="number" name="developer_commission" 
                               value="<?php echo $settings['developer_commission']; ?>" 
                               step="0.1" min="0" max="100"
                               style="width: 100px; margin-left: 12px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <span>%</span>
                    </label>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px;">
                        <strong>Default Agent Commission</strong>
                        <input type="number" name="default_agent_commission" 
                               value="<?php echo $settings['default_agent_commission']; ?>" 
                               step="0.1" min="0" max="100"
                               style="width: 100px; margin-left: 12px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <span>%</span>
                    </label>
                </div>
            </div>
            <button type="submit" class="btn" style="margin-top: 16px; background: var(--green); color: white;">
                Update Settings
            </button>
        </form>
    </div>
    
    <!-- Commission History -->
    <h2 style="margin-bottom: 20px;">Commission History</h2>
    <div class="commission-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Recipient</th>
                    <th>Type</th>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Order Total</th>
                    <th>Commission</th>
                    <th>Rate</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($commission_history)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 40px; color: #6b7280;">
                        No commissions found for the selected period
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($commission_history as $commission): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($commission['first_name'] . ' ' . $commission['last_name']); ?></strong>
                            <br><small style="color: #6b7280;"><?php echo htmlspecialchars($commission['email']); ?></small>
                        </td>
                        <td><?php echo ucwords(str_replace('_', ' ', $commission['commission_type'])); ?></td>
                        <td><?php echo htmlspecialchars($commission['order_number'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($commission['business_name'] ?? 'N/A'); ?></td>
                        <td>$<?php echo number_format($commission['order_total'] ?? 0, 2); ?></td>
                        <td style="font-weight: 600; color: #059669;">
                            $<?php echo number_format($commission['amount'], 2); ?>
                        </td>
                        <td><?php echo $commission['percentage']; ?>%</td>
                        <td>
                            <span class="status-badge status-<?php echo $commission['status']; ?>">
                                <?php echo ucfirst($commission['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($commission['status'] === 'pending'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="approve_commission">
                                    <input type="hidden" name="commission_id" value="<?php echo $commission['id']; ?>">
                                    <button type="submit" class="btn-small" style="background: #059669; color: white; padding: 4px 8px; font-size: 12px; border: none; border-radius: 4px;">
                                        Approve
                                    </button>
                                </form>
                            <?php elseif ($commission['status'] === 'approved'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="commission_ids[]" value="<?php echo $commission['id']; ?>">
                                    <button type="submit" class="btn-small" style="background: #3b82f6; color: white; padding: 4px 8px; font-size: 12px; border: none; border-radius: 4px;">
                                        Mark Paid
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function viewAgentDetails(agentId) {
    // In a real implementation, this would open a modal or navigate to a detailed view
    window.location.href = '/admin/agent-performance.php?id=' + agentId;
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
