<?php
$SEO = [
    'title' => 'Order Management — Admin',
    'description' => 'View and manage wholesale orders.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(o.order_number LIKE ? OR wa.business_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $where_conditions[] = "o.status = ?";
    $params[] = $status_filter;
}

if (!empty($from_date)) {
    $where_conditions[] = "DATE(o.created_at) >= ?";
    $params[] = $from_date;
}

if (!empty($to_date)) {
    $where_conditions[] = "DATE(o.created_at) <= ?";
    $params[] = $to_date;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch orders from database
try {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               wa.business_name,
               u.first_name, u.last_name, u.email,
               COUNT(oi.id) as item_count
        FROM orders o
        JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        JOIN users u ON u.id = o.user_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        $where_clause
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
} catch (Exception $e) {
    $orders = [];
    $db_error = "Database error: " . $e->getMessage();
}

// Calculate statistics from database
try {
    $stats_query = $pdo->query("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as shipped,
            SUM(CASE WHEN status = 'fulfilled' THEN 1 ELSE 0 END) as delivered,
            COALESCE(SUM(total), 0) as total_revenue
        FROM orders 
        WHERE status != 'cancelled'
    ");
    $stats = $stats_query->fetch();
} catch (Exception $e) {
    $stats = [
        'total_orders' => 0,
        'pending' => 0,
        'processing' => 0,
        'shipped' => 0,
        'delivered' => 0,
        'total_revenue' => 0
    ];
}

// Handle order actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $order_id = intval($_POST['order_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    
    if ($order_id > 0 && !empty($new_status)) {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $order_id]);
            $message = "Order status updated successfully";
        } catch (Exception $e) {
            $error = "Error updating order: " . $e->getMessage();
        }
    } elseif ($action === 'cleanup_demo_orders') {
        try {
            $pdo->beginTransaction();
            
            // Delete demo order items
            $pdo->query("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'ORD-2024-%')");
            
            // Delete demo commissions
            $pdo->query("DELETE FROM commissions WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'ORD-2024-%')");
            
            // Delete demo orders
            $result = $pdo->query("DELETE FROM orders WHERE order_number LIKE 'ORD-2024-%'");
            $deletedCount = $result->rowCount();
            
            $pdo->commit();
            $message = "Deleted $deletedCount demo orders successfully";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error cleaning up demo orders: " . $e->getMessage();
        }
    }
}
?>

<style>
.admin-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    text-align: center;
}
.stat-value {
    font-size: 28px;
    font-weight: bold;
    margin: 8px 0;
}
.stat-label {
    color: #6b7280;
    font-size: 13px;
    text-transform: uppercase;
}
.orders-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.orders-table table {
    width: 100%;
    border-collapse: collapse;
}
.orders-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 14px;
}
.orders-table td {
    padding: 16px 12px;
    border-bottom: 1px solid #f3f4f6;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.status-draft { background: #f3f4f6; color: #6b7280; }
.status-pending { background: #fef3c7; color: #92400e; }
.status-submitted { background: #dbeafe; color: #1e40af; }
.status-paid { background: #e0e7ff; color: #4338ca; }
.status-fulfilled { background: #dcfce7; color: #14532d; }
.status-cancelled { background: #fee2e2; color: #991b1b; }
.payment-paid { color: #059669; }
.payment-pending { color: #f59e0b; }
.payment-failed { color: #dc2626; }
</style>

<div class="admin-header">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 36px; margin-bottom: 8px;">Order Management</h1>
                <p style="font-size: 18px; opacity: 0.9;">Process and track wholesale orders</p>
            </div>
            <div>
                <a href="<?php echo path_with_base('/admin/create-order.php'); ?>" class="btn" style="background: var(--green); color: white; padding: 12px 24px; text-decoration: none;">
                    + Create Order
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if (isset($message)): ?>
        <div class="card" style="background: #dcfce7; color: #14532d; margin-bottom: 24px;">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($db_error)): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ⚠️ <?php echo htmlspecialchars($db_error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Orders</div>
            <div class="stat-value"><?php echo $stats['total_orders']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending</div>
            <div class="stat-value" style="color: #f59e0b;"><?php echo $stats['pending']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Processing</div>
            <div class="stat-value" style="color: #3b82f6;"><?php echo $stats['processing']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Shipped</div>
            <div class="stat-value" style="color: #6366f1;"><?php echo $stats['shipped']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Delivered</div>
            <div class="stat-value" style="color: #059669;"><?php echo $stats['delivered']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value" style="font-size: 24px;">$<?php echo number_format($stats['total_revenue'], 0); ?></div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="card" style="margin-bottom: 24px;">
        <form method="get" style="display: flex; gap: 16px; flex-wrap: wrap;">
            <input type="text" name="search" placeholder="Search by order ID, customer..." 
                   value="<?php echo htmlspecialchars($search); ?>"
                   style="flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            
            <select name="status" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                <option value="">All Status</option>
                <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="fulfilled" <?php echo $status_filter === 'fulfilled' ? 'selected' : ''; ?>>Fulfilled</option>
                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
            
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            
            <button type="submit" class="btn">Filter</button>
        </form>
    </div>
    
    <!-- Orders Table -->
    <div class="orders-table">
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Shipping</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: #6b7280;">
                        <?php if (isset($db_error)): ?>
                            Unable to load orders due to database error.
                        <?php else: ?>
                            No orders found. Orders will appear here after customers complete checkout.
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>
                            <strong style="font-family: monospace;"><?php echo htmlspecialchars($order['order_number'] ?: 'ORD-' . $order['id']); ?></strong>
                        </td>
                        <td>
                            <?php echo date('M d, Y', strtotime($order['created_at'])); ?>
                            <br><small style="color: #6b7280;"><?php echo date('g:i A', strtotime($order['created_at'])); ?></small>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($order['business_name']); ?></strong>
                            <br><small style="color: #6b7280;"><?php echo htmlspecialchars($order['email']); ?></small>
                        </td>
                        <td><?php echo intval($order['item_count']); ?> items</td>
                        <td style="font-weight: 600;">$<?php echo number_format($order['total'], 2); ?></td>
                        <td>
                            <span class="payment-<?php echo $order['payment_status']; ?>" style="font-weight: 600;">
                                <?php echo ucfirst($order['payment_status']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $order['status']; ?>">
                                <?php echo ucfirst($order['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($order['shipping_method'] ?: 'Standard'); ?>
                            <?php if (!empty($order['tracking_number'])): ?>
                                <br><small style="color: #3b82f6; cursor: pointer;" title="<?php echo htmlspecialchars($order['tracking_number']); ?>">
                                    Track →
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 8px;">
                                <a href="<?php echo path_with_base('/admin/order-details.php'); ?>?id=<?php echo $order['id']; ?>" 
                                   class="btn secondary" style="padding: 6px 12px; font-size: 13px; text-decoration: none;">
                                    View Details
                                </a>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <select name="new_status" onchange="this.form.submit()" style="padding: 6px 8px; font-size: 13px; border: 1px solid #e5e7eb; border-radius: 4px;">
                                        <option value="">Quick Status</option>
                                        <?php if ($order['status'] === 'pending'): ?>
                                            <option value="submitted">→ Submitted</option>
                                        <?php endif; ?>
                                        <?php if ($order['status'] === 'submitted'): ?>
                                            <option value="paid">→ Paid</option>
                                        <?php endif; ?>
                                        <?php if ($order['status'] === 'paid'): ?>
                                            <option value="fulfilled">→ Fulfilled</option>
                                        <?php endif; ?>
                                        <option value="cancelled">❌ Cancel</option>
                                    </select>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Quick Actions -->
    <div class="card" style="margin-top: 32px;">
        <h3>Bulk Actions</h3>
        <div style="display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap;">
            <button class="btn secondary">Export Orders CSV</button>
            <button class="btn secondary">Print Shipping Labels</button>
            <button class="btn secondary">Send Tracking Updates</button>
            <button class="btn secondary">Generate Invoices</button>
            <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete all demo orders? This action cannot be undone.')">
                <input type="hidden" name="action" value="cleanup_demo_orders">
                <button type="submit" class="btn" style="background: #dc2626; color: white;">
                    🗑️ Clean Up Demo Orders
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
