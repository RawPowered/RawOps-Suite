<?php
$SEO = [
    'title' => 'Order Processing — Operations',
    'description' => 'Process and manage wholesale orders for shipping.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Ensure user has operations role
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'operations')) {
    flash_set('error', 'Access denied. Operations role required.');
    redirect('/login.php');
}

$pdo = db();
$message = '';
$error = '';

// Handle order actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_order_status') {
            $orderId = intval($_POST['order_id'] ?? 0);
            $shippingStatus = $_POST['shipping_status'] ?? '';
            $notes = trim($_POST['notes'] ?? '');
            
            if ($orderId <= 0 || !in_array($shippingStatus, ['pending', 'processing', 'shipped', 'delivered', 'exception'])) {
                throw new Exception('Invalid order ID or shipping status');
            }
            
            // Get order details
            $stmt = $pdo->prepare('SELECT order_number FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new Exception('Order not found');
            }
            
            // Update order shipping status
            $stmt = $pdo->prepare('UPDATE orders SET shipping_status = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$shippingStatus, $orderId]);
            
            // Log operations activity
            $stmt = $pdo->prepare('
                INSERT INTO operations_log (user_id, action, target_type, target_id, details, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $details = json_encode([
                'order_number' => $order['order_number'],
                'new_shipping_status' => $shippingStatus,
                'notes' => $notes
            ]);
            $stmt->execute([$userId, 'order_status_update', 'order', $orderId, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            $message = 'Order status updated successfully for ' . htmlspecialchars($order['order_number']);
            
        } elseif ($action === 'process_order') {
            $orderId = intval($_POST['order_id'] ?? 0);
            
            if ($orderId <= 0) {
                throw new Exception('Invalid order ID');
            }
            
            $pdo->beginTransaction();
            
            // Get order details with items
            $stmt = $pdo->prepare('
                SELECT o.*, oi.product_id, oi.quantity, p.name as product_name, p.stock_quantity
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                JOIN products p ON p.id = oi.product_id
                WHERE o.id = ?
            ');
            $stmt->execute([$orderId]);
            $orderItems = $stmt->fetchAll();
            
            if (empty($orderItems)) {
                throw new Exception('Order not found or has no items');
            }
            
            // Check stock availability
            foreach ($orderItems as $item) {
                if ($item['stock_quantity'] < $item['quantity']) {
                    throw new Exception("Insufficient stock for {$item['product_name']}. Available: {$item['stock_quantity']}, Required: {$item['quantity']}");
                }
            }
            
            // Deduct stock and log movements
            foreach ($orderItems as $item) {
                // Update product stock
                $stmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$item['quantity'], $item['product_id']]);
                
                // Log stock movement
                $stmt = $pdo->prepare('
                    INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$item['product_id'], 'out', $item['quantity'], 'order', $orderId, 'Order fulfillment', $userId]);
            }
            
            // Update order status
            $stmt = $pdo->prepare('UPDATE orders SET shipping_status = "processing", updated_at = NOW() WHERE id = ?');
            $stmt->execute([$orderId]);
            
            $pdo->commit();
            
            $message = 'Order processed successfully. Stock deducted and ready for shipping.';
        }
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Get orders based on filter
$filter = $_GET['filter'] ?? 'all';
$whereClause = '';
$params = [];

switch ($filter) {
    case 'pending':
        $whereClause = 'WHERE o.shipping_status = "pending"';
        break;
    case 'processing':
        $whereClause = 'WHERE o.shipping_status = "processing"';
        break;
    case 'shipped':
        $whereClause = 'WHERE o.shipping_status = "shipped"';
        break;
    case 'today':
        $whereClause = 'WHERE DATE(o.created_at) = CURDATE()';
        break;
}

try {
    $orders = $pdo->query("
        SELECT o.*, wa.business_name, 
               CONCAT(u.first_name, ' ', u.last_name) as contact_name,
               COUNT(oi.id) as item_count,
               GROUP_CONCAT(CONCAT(COALESCE(oi.product_name, p.name), ' (', oi.quantity, ')') SEPARATOR ', ') as items_summary
        FROM orders o
        JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        LEFT JOIN users u ON u.id = wa.user_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products p ON p.id = oi.product_id
        {$whereClause}
        GROUP BY o.id
        ORDER BY 
            CASE o.shipping_status 
                WHEN 'pending' THEN 1 
                WHEN 'processing' THEN 2 
                WHEN 'shipped' THEN 3 
                ELSE 4 
            END,
            o.created_at DESC
    ")->fetchAll();
} catch (Throwable $e) {
    $error = 'Unable to load orders: ' . $e->getMessage();
    $orders = [];
}
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>📋 Order Processing</h1>
                <p class="muted">Process wholesale orders and manage shipping</p>
            </div>
            <a href="/operations/" class="btn secondary">← Back to Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Filter Tabs -->
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="?filter=all" class="btn <?php echo $filter === 'all' ? '' : 'secondary'; ?>" style="font-size: 14px;">
                    📋 All Orders (<?php echo count($orders); ?>)
                </a>
                <a href="?filter=pending" class="btn <?php echo $filter === 'pending' ? '' : 'secondary'; ?>" style="font-size: 14px;">
                    ⏳ Pending
                </a>
                <a href="?filter=processing" class="btn <?php echo $filter === 'processing' ? '' : 'secondary'; ?>" style="font-size: 14px;">
                    🔄 Processing
                </a>
                <a href="?filter=shipped" class="btn <?php echo $filter === 'shipped' ? '' : 'secondary'; ?>" style="font-size: 14px;">
                    🚚 Shipped
                </a>
                <a href="?filter=today" class="btn <?php echo $filter === 'today' ? '' : 'secondary'; ?>" style="font-size: 14px;">
                    📅 Today
                </a>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="card">
            <h2>Orders</h2>
            <?php if (empty($orders)): ?>
                <div style="text-align: center; padding: 40px; color: #6b7280;">
                    <div style="font-size: 48px; margin-bottom: 16px;">📋</div>
                    <p>No orders found for the selected filter.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Order</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Customer</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Items</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Total</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Status</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Date</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td style="padding: 12px;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                            <div style="font-size: 11px; color: #6b7280;">ID: <?php echo $order['id']; ?></div>
                                        </div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($order['business_name']); ?></strong>
                                            <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($order['contact_name']); ?></div>
                                        </div>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <div style="font-weight: bold;"><?php echo intval($order['item_count']); ?> items</div>
                                        <div style="font-size: 11px; color: #6b7280; max-width: 200px; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($order['items_summary']); ?>">
                                            <?php echo htmlspecialchars($order['items_summary']); ?>
                                        </div>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <strong>$<?php echo number_format($order['total'], 2); ?></strong>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <?php
                                        $statusColors = [
                                            'pending' => ['bg' => '#fef3c7', 'text' => '#d97706'],
                                            'processing' => ['bg' => '#dbeafe', 'text' => '#2563eb'],
                                            'shipped' => ['bg' => '#f0fdf4', 'text' => '#059669'],
                                            'delivered' => ['bg' => '#f0fdf4', 'text' => '#059669'],
                                            'exception' => ['bg' => '#fef2f2', 'text' => '#dc2626']
                                        ];
                                        $colors = $statusColors[$order['shipping_status']] ?? $statusColors['pending'];
                                        ?>
                                        <span style="background: <?php echo $colors['bg']; ?>; color: <?php echo $colors['text']; ?>; padding: 4px 8px; border-radius: 4px; font-size: 12px; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($order['shipping_status']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <div style="font-size: 12px;"><?php echo date('M j, Y', strtotime($order['created_at'])); ?></div>
                                        <div style="font-size: 11px; color: #6b7280;"><?php echo date('g:i a', strtotime($order['created_at'])); ?></div>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <div style="display: flex; flex-direction: column; gap: 4px; align-items: center;">
                                            <?php if ($order['shipping_status'] === 'pending'): ?>
                                                <button onclick="processOrder(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['order_number'], ENT_QUOTES); ?>')" 
                                                        class="btn" style="font-size: 11px; padding: 4px 8px;">
                                                    🔄 Process
                                                </button>
                                            <?php endif; ?>
                                            <button onclick="updateOrderStatus(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['order_number'], ENT_QUOTES); ?>', '<?php echo $order['shipping_status']; ?>')" 
                                                    class="btn secondary" style="font-size: 11px; padding: 4px 8px;">
                                                📝 Update
                                            </button>
                                            <a href="/operations/order-details.php?id=<?php echo $order['id']; ?>" 
                                               class="btn secondary" style="font-size: 11px; padding: 4px 8px; text-decoration: none;">
                                                👁️ View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Process Order Modal -->
<div id="process-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; padding: 24px; max-width: 500px; width: 90%;">
        <h3 style="margin: 0 0 16px 0;">Process Order</h3>
        <form method="post" id="process-form">
            <input type="hidden" name="action" value="process_order">
            <input type="hidden" name="order_id" id="process-order-id">
            
            <div style="margin-bottom: 16px;">
                <strong>Order: <span id="process-order-number"></span></strong>
            </div>
            
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 16px; margin-bottom: 16px;">
                <p style="margin: 0; font-size: 14px; color: #0c4a6e;">
                    <strong>⚠️ Processing this order will:</strong><br>
                    • Deduct stock quantities from inventory<br>
                    • Set order status to "Processing"<br>
                    • Log stock movements<br>
                    • Make order ready for shipping
                </p>
            </div>
            
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeProcessModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn">Process Order</button>
            </div>
        </form>
    </div>
</div>

<!-- Update Status Modal -->
<div id="status-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; padding: 24px; max-width: 500px; width: 90%;">
        <h3 style="margin: 0 0 16px 0;">Update Order Status</h3>
        <form method="post" id="status-form">
            <input type="hidden" name="action" value="update_order_status">
            <input type="hidden" name="order_id" id="status-order-id">
            
            <div style="margin-bottom: 16px;">
                <strong>Order: <span id="status-order-number"></span></strong>
            </div>
            
            <label style="margin-bottom: 16px; display: block;">
                <span>Shipping Status</span>
                <select name="shipping_status" id="status-select" required>
                    <option value="pending">Pending</option>
                    <option value="processing">Processing</option>
                    <option value="shipped">Shipped</option>
                    <option value="delivered">Delivered</option>
                    <option value="exception">Exception</option>
                </select>
            </label>
            
            <label style="margin-bottom: 16px; display: block;">
                <span>Notes (Optional)</span>
                <textarea name="notes" placeholder="Status update notes..." rows="3" style="width: 100%;"></textarea>
            </label>
            
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeStatusModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn">Update Status</button>
            </div>
        </form>
    </div>
</div>

<script>
function processOrder(orderId, orderNumber) {
    document.getElementById('process-order-id').value = orderId;
    document.getElementById('process-order-number').textContent = orderNumber;
    document.getElementById('process-modal').style.display = 'block';
}

function closeProcessModal() {
    document.getElementById('process-modal').style.display = 'none';
    document.getElementById('process-form').reset();
}

function updateOrderStatus(orderId, orderNumber, currentStatus) {
    document.getElementById('status-order-id').value = orderId;
    document.getElementById('status-order-number').textContent = orderNumber;
    document.getElementById('status-select').value = currentStatus;
    document.getElementById('status-modal').style.display = 'block';
}

function closeStatusModal() {
    document.getElementById('status-modal').style.display = 'none';
    document.getElementById('status-form').reset();
}

// Close modals when clicking outside
document.getElementById('process-modal').addEventListener('click', function(e) {
    if (e.target === this) closeProcessModal();
});

document.getElementById('status-modal').addEventListener('click', function(e) {
    if (e.target === this) closeStatusModal();
});

// ESC key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeProcessModal();
        closeStatusModal();
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
