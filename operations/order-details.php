<?php
$SEO = [
    'title' => 'Order Details — Operations',
    'description' => 'View and manage order details.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Ensure user has operations or admin role
$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'operations') && !user_has_role($userId, 'admin'))) {
    flash_set('error', 'Access denied. Operations role required.');
    redirect('/login.php');
}

$pdo = db();
$orderId = intval($_GET['id'] ?? 0);
$message = '';
$error = '';

if ($orderId <= 0) {
    flash_set('error', 'Invalid order ID.');
    redirect(path_with_base('/operations/orders.php'));
}

// Handle order updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $saveAction = $_POST['save_action'] ?? 'stay'; // 'stay' or 'return'
    
    try {
        if ($action === 'update_shipping_status') {
            $shippingStatus = $_POST['shipping_status'] ?? '';
            $notes = trim($_POST['notes'] ?? '');
            
            if (!in_array($shippingStatus, ['pending', 'processing', 'shipped', 'delivered', 'exception'])) {
                throw new Exception('Invalid shipping status');
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
            
            flash_set('success', 'Shipping status updated successfully.');
            
        } elseif ($action === 'update_tracking') {
            $tracking = trim($_POST['tracking_number'] ?? '');
            $stmt = $pdo->prepare("UPDATE orders SET tracking_number = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$tracking, $orderId]);
            flash_set('success', 'Tracking number updated successfully.');
            
        } elseif ($action === 'update_notes') {
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $pdo->prepare("UPDATE orders SET notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$notes, $orderId]);
            flash_set('success', 'Order notes updated successfully.');
            
        } elseif ($action === 'delete_order') {
            // Delete order and related data
            $pdo->beginTransaction();
            try {
                // Delete commissions if they exist
                $pdo->prepare("DELETE FROM commissions WHERE order_id = ?")->execute([$orderId]);
                
                // Delete order items
                $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
                $stmt->execute([$orderId]);
                
                // Delete order
                $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
                $stmt->execute([$orderId]);
                
                $pdo->commit();
                flash_set('success', 'Order deleted successfully.');
                redirect(path_with_base('/operations/orders.php'));
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        
        // Redirect based on save action
        if ($saveAction === 'return') {
            redirect(path_with_base('/operations/orders.php'));
        } else {
            redirect(path_with_base("/operations/order-details.php?id=$orderId"));
        }
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch order details
try {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               wa.business_name, wa.address_line1, wa.address_line2, wa.city, wa.state_region, wa.postal_code, wa.country,
               u.first_name, u.last_name, u.email, u.phone,
               sa.first_name as agent_first_name, sa.last_name as agent_last_name, sa.email as agent_email
        FROM orders o
        JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        JOIN users u ON u.id = o.user_id
        LEFT JOIN users sa ON sa.id = o.sales_agent_id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        flash_set('error', 'Order not found.');
        redirect(path_with_base('/operations/orders.php'));
    }
    
    // Fetch order items - check if product_name column exists
    $columns = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'product_name'")->fetch();
    $hasProductNameColumn = !empty($columns);
    
    if ($hasProductNameColumn) {
        $stmt = $pdo->prepare("
            SELECT oi.*, p.sku as product_sku_db, p.name as product_name_db
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
            ORDER BY oi.id
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT oi.*, p.sku as product_sku, p.name as product_name
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
            ORDER BY oi.id
        ");
    }
    $stmt->execute([$orderId]);
    $orderItems = $stmt->fetchAll();
    
    // Fetch payment method details if available (for admin/operations)
    $paymentMethodDetails = null;
    if ($order['payment_method_id'] ?? 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT type, card_brand, last_four, bank_name, exp_month, exp_year, 
                       card_holder_name, token, is_default
                FROM payment_methods 
                WHERE id = ?
            ");
            $stmt->execute([$order['payment_method_id']]);
            $paymentMethodDetails = $stmt->fetch();
        } catch (Throwable $e) {
            $paymentMethodDetails = null;
        }
    }
    
} catch (Exception $e) {
    flash_set('error', 'Database error: ' . $e->getMessage());
    redirect(path_with_base('/operations/orders.php'));
}
?>

<style>
.order-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.order-container {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 32px;
    margin-bottom: 40px;
}
.order-main {
    background: white;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.order-sidebar {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    position: sticky;
    top: 20px;
}
.section-title {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e5e7eb;
}
.status-badge {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
}
.shipping-pending { background: #fef3c7; color: #92400e; }
.shipping-processing { background: #dbeafe; color: #1e40af; }
.shipping-shipped { background: #dcfce7; color: #14532d; }
.shipping-delivered { background: #e0e7ff; color: #4338ca; }
.shipping-exception { background: #fee2e2; color: #991b1b; }
.order-item {
    display: grid;
    grid-template-columns: 1fr auto auto auto;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid #f3f4f6;
    align-items: center;
}
.order-item:last-child {
    border-bottom: none;
}
.item-details h4 {
    font-size: 16px;
    margin-bottom: 4px;
    color: #111827;
}
.item-meta {
    color: #6b7280;
    font-size: 13px;
}
.order-total {
    background: #f9fafb;
    border-radius: 8px;
    padding: 16px;
    margin-top: 16px;
}
.total-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e5e7eb;
}
.total-row:last-child {
    border-bottom: none;
    font-size: 18px;
    font-weight: 600;
    padding-top: 12px;
}
.action-form {
    margin-bottom: 16px;
    padding: 16px;
    background: #f9fafb;
    border-radius: 8px;
}
.save-buttons {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}
.save-buttons button {
    flex: 1;
}
@media (max-width: 768px) {
    .order-container {
        grid-template-columns: 1fr;
    }
    .save-buttons {
        flex-direction: column;
    }
}
@media print {
    .order-sidebar, .no-print { display: none !important; }
    .order-container { grid-template-columns: 1fr !important; }
}
</style>

<div class="order-header">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 36px; margin-bottom: 8px;">Order Details</h1>
                <p style="font-size: 18px; opacity: 0.9;">
                    <?php echo htmlspecialchars($order['order_number'] ?: 'ORD-' . $order['id']); ?>
                </p>
            </div>
            <div class="no-print">
                <a href="<?php echo path_with_base('/operations/orders.php'); ?>" class="btn secondary">← Back to Orders</a>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <?php if ($message): ?>
        <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
            ✓ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <div class="order-container">
        <!-- Order Details -->
        <div class="order-main">
            <!-- Order Information -->
            <div style="margin-bottom: 32px;">
                <h2 class="section-title">Order Information</h2>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div>
                        <p><strong>Order Number:</strong> <?php echo htmlspecialchars($order['order_number'] ?: 'ORD-' . $order['id']); ?></p>
                        <p><strong>Order Date:</strong> <?php echo date('F j, Y \a\t g:i A', strtotime($order['created_at'])); ?></p>
                        <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($order['status'] ?? 'pending')); ?></p>
                        <p><strong>Payment Status:</strong> <?php echo htmlspecialchars(ucfirst($order['payment_status'] ?? 'pending')); ?></p>
                    </div>
                    <div>
                        <p><strong>Shipping Status:</strong> 
                            <span class="status-badge shipping-<?php echo $order['shipping_status']; ?>">
                                <?php echo htmlspecialchars(ucfirst($order['shipping_status'])); ?>
                            </span>
                        </p>
                        <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($order['payment_method'] ?: 'Net Terms'); ?></p>
                        <p><strong>Shipping Method:</strong> <?php echo htmlspecialchars($order['shipping_method'] ?: 'Standard'); ?></p>
                        <?php if ($order['tracking_number']): ?>
                        <p><strong>Tracking:</strong> <?php echo htmlspecialchars($order['tracking_number']); ?></p>
                        <?php endif; ?>
                        <p><strong>Last Updated:</strong> <?php echo $order['updated_at'] ? date('M j, Y g:i A', strtotime($order['updated_at'])) : 'Never'; ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Customer Information -->
            <div style="margin-bottom: 32px;">
                <h2 class="section-title">Customer Information</h2>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div>
                        <p><strong>Business:</strong> <?php echo htmlspecialchars($order['business_name']); ?></p>
                        <p><strong>Contact:</strong> <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></p>
                        <p><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($order['email']); ?>"><?php echo htmlspecialchars($order['email']); ?></a></p>
                        <?php if ($order['phone']): ?>
                        <p><strong>Phone:</strong> <a href="tel:<?php echo htmlspecialchars($order['phone']); ?>"><?php echo htmlspecialchars($order['phone']); ?></a></p>
                        <?php endif; ?>
                        <p><strong>Wholesale Account ID:</strong> #<?php echo intval($order['wholesale_account_id']); ?>
                            <a href="<?php echo path_with_base('/admin/account-payment-methods.php?account_id=' . intval($order['wholesale_account_id'])); ?>" 
                               style="margin-left: 8px; font-size: 13px; color: #3b82f6; text-decoration: underline;">
                                View All Payment Methods
                            </a>
                        </p>
                    </div>
                    <div>
                        <p><strong>Shipping Address:</strong></p>
                        <div style="color: #6b7280; margin-top: 4px;">
                            <?php echo htmlspecialchars($order['business_name']); ?><br>
                            <?php if ($order['address_line1']): ?>
                                <?php echo htmlspecialchars($order['address_line1']); ?><br>
                                <?php if ($order['address_line2']): ?>
                                    <?php echo htmlspecialchars($order['address_line2']); ?><br>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($order['city'] . ', ' . $order['state_region'] . ' ' . $order['postal_code']); ?><br>
                                <?php echo htmlspecialchars($order['country']); ?>
                            <?php else: ?>
                                <em>Address not provided</em>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Payment Method Details (Operations) -->
            <?php if ($paymentMethodDetails): ?>
            <div style="margin-bottom: 32px;">
                <h2 class="section-title">💳 Payment Method Details</h2>
                <div style="background: <?php echo $order['status'] === 'submitted' ? '#fef3c7' : '#f9fafb'; ?>; border: 2px solid <?php echo $order['status'] === 'submitted' ? '#f59e0b' : '#e5e7eb'; ?>; border-radius: 8px; padding: 16px;">
                    <?php if ($order['status'] === 'submitted'): ?>
                        <p style="color: #92400e; font-weight: 600; margin-bottom: 12px;">⚠️ Payment Info Submitted - Pending Processing</p>
                    <?php endif; ?>
                    
                    <?php if ($paymentMethodDetails['type'] === 'credit_card'): ?>
                        <p><strong>Type:</strong> Credit Card</p>
                        <p><strong>Card Brand:</strong> <?php echo htmlspecialchars($paymentMethodDetails['card_brand'] ?: 'Unknown'); ?></p>
                        <p><strong>Last Four:</strong> ••••<?php echo htmlspecialchars($paymentMethodDetails['last_four']); ?></p>
                        <p><strong>Cardholder:</strong> <?php echo htmlspecialchars($paymentMethodDetails['card_holder_name'] ?: 'N/A'); ?></p>
                        <?php if ($paymentMethodDetails['exp_month']): ?>
                        <p><strong>Expiration:</strong> <?php echo htmlspecialchars($paymentMethodDetails['exp_month'] . '/' . $paymentMethodDetails['exp_year']); ?></p>
                        <?php endif; ?>
                        
                        <?php if ($paymentMethodDetails['token'] && $order['status'] === 'submitted'): ?>
                        <div style="margin-top: 16px; padding: 12px; background: white; border-radius: 6px; border: 1px solid #d1d5db;">
                            <p style="font-size: 13px; font-weight: 600; color: #991b1b; margin-bottom: 8px;">⚠️ OPERATIONS/ADMIN ONLY - Full Card Details:</p>
                            <?php 
                            $tokenData = json_decode($paymentMethodDetails['token'], true);
                            if ($tokenData && isset($tokenData['card_number'])): 
                            ?>
                                <p style="font-family: monospace; font-size: 14px;"><strong>Card Number:</strong> <?php echo htmlspecialchars($tokenData['card_number']); ?></p>
                                <p style="font-family: monospace; font-size: 14px;"><strong>CVV:</strong> <?php echo htmlspecialchars($tokenData['cvv'] ?? 'N/A'); ?></p>
                                <p style="font-size: 11px; color: #6b7280; margin-top: 8px;">⚠️ Process payment manually, then update order status to "Paid"</p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                    <?php elseif ($paymentMethodDetails['type'] === 'ach'): ?>
                        <p><strong>Type:</strong> ACH / Bank Transfer</p>
                        <p><strong>Bank Name:</strong> <?php echo htmlspecialchars($paymentMethodDetails['bank_name'] ?: 'Unknown'); ?></p>
                        <p><strong>Last Four:</strong> ••••<?php echo htmlspecialchars($paymentMethodDetails['last_four']); ?></p>
                        <p><strong>Account Holder:</strong> <?php echo htmlspecialchars($paymentMethodDetails['card_holder_name'] ?: 'N/A'); ?></p>
                        
                        <?php if ($paymentMethodDetails['token'] && $order['status'] === 'submitted'): ?>
                        <div style="margin-top: 16px; padding: 12px; background: white; border-radius: 6px; border: 1px solid #d1d5db;">
                            <p style="font-size: 13px; font-weight: 600; color: #991b1b; margin-bottom: 8px;">⚠️ OPERATIONS/ADMIN ONLY - Full ACH Details:</p>
                            <?php 
                            $tokenData = json_decode($paymentMethodDetails['token'], true);
                            if ($tokenData && isset($tokenData['routing_number'])): 
                            ?>
                                <p style="font-family: monospace; font-size: 14px;"><strong>Routing Number:</strong> <?php echo htmlspecialchars($tokenData['routing_number']); ?></p>
                                <p style="font-family: monospace; font-size: 14px;"><strong>Account Number:</strong> <?php echo htmlspecialchars($tokenData['account_number']); ?></p>
                                <p style="font-family: monospace; font-size: 14px;"><strong>Account Type:</strong> <?php echo htmlspecialchars(ucfirst($tokenData['account_type'] ?? 'checking')); ?></p>
                                <p style="font-size: 11px; color: #6b7280; margin-top: 8px;">⚠️ Process ACH payment manually, then update order status to "Paid"</p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($paymentMethodDetails['is_default']): ?>
                        <p style="margin-top: 12px; padding: 6px 12px; background: #dcfce7; color: #14532d; border-radius: 6px; font-size: 13px; display: inline-block;">
                            ✓ Default Payment Method
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Sales Agent -->
            <?php if ($order['sales_agent_id']): ?>
            <div style="margin-bottom: 32px;">
                <h2 class="section-title">Sales Representative</h2>
                <p><strong>Agent:</strong> <?php echo htmlspecialchars($order['agent_first_name'] . ' ' . $order['agent_last_name']); ?></p>
                <p><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($order['agent_email']); ?>"><?php echo htmlspecialchars($order['agent_email']); ?></a></p>
            </div>
            <?php endif; ?>
            
            <!-- Order Items -->
            <div style="margin-bottom: 32px;">
                <h2 class="section-title">Order Items</h2>
                <?php foreach ($orderItems as $item): ?>
                <div class="order-item">
                    <div class="item-details">
                        <h4><?php 
                            $productName = $item['product_name'] ?? $item['product_name_db'] ?? 'Unknown Product';
                            echo htmlspecialchars($productName); 
                        ?></h4>
                        <div class="item-meta">
                            SKU: <?php 
                                $productSku = $item['product_sku'] ?? $item['product_sku_db'] ?? 'N/A';
                                echo htmlspecialchars($productSku); 
                            ?>
                        </div>
                    </div>
                    <div style="text-align: center;">
                        <strong><?php echo $item['quantity']; ?></strong>
                        <div style="color: #6b7280; font-size: 12px;">Qty</div>
                    </div>
                    <div style="text-align: right;">
                        <strong>$<?php echo number_format($item['unit_price'], 2); ?></strong>
                        <div style="color: #6b7280; font-size: 12px;">Each</div>
                    </div>
                    <div style="text-align: right; font-weight: 600; color: #1e40af;">
                        $<?php echo number_format($item['line_total'], 2); ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div class="order-total">
                    <div class="total-row">
                        <span>Subtotal</span>
                        <span>$<?php echo number_format($order['subtotal'], 2); ?></span>
                    </div>
                    <div class="total-row">
                        <span>Shipping</span>
                        <span><?php echo $order['shipping'] > 0 ? '$' . number_format($order['shipping'], 2) : 'FREE'; ?></span>
                    </div>
                    <div class="total-row">
                        <span>Tax</span>
                        <span>$<?php echo number_format($order['tax'], 2); ?></span>
                    </div>
                    <div class="total-row">
                        <span>Total</span>
                        <span>$<?php echo number_format($order['total'], 2); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Order Notes -->
            <div>
                <h2 class="section-title">Order Notes</h2>
                <?php if ($order['notes']): ?>
                    <p style="background: #f9fafb; padding: 16px; border-radius: 8px; color: #374151;">
                        <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                    </p>
                <?php else: ?>
                    <p style="color: #6b7280;"><em>No notes added</em></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Order Actions Sidebar -->
        <div class="order-sidebar no-print">
            <button onclick="window.print()" class="btn" style="width: 100%; margin-bottom: 16px;">🖨️ Print Order</button>
            
            <!-- Update Shipping Status -->
            <div class="action-form">
                <h3 style="margin-bottom: 12px;">Shipping Status</h3>
                <form method="post">
                    <input type="hidden" name="action" value="update_shipping_status">
                    <select name="shipping_status" style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="pending" <?php echo $order['shipping_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="processing" <?php echo $order['shipping_status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                        <option value="shipped" <?php echo $order['shipping_status'] === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                        <option value="delivered" <?php echo $order['shipping_status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                        <option value="exception" <?php echo $order['shipping_status'] === 'exception' ? 'selected' : ''; ?>>Exception</option>
                    </select>
                    <label style="margin-bottom: 12px; display: block;">
                        <span style="font-size: 13px; color: #6b7280;">Notes (Optional)</span>
                        <textarea name="notes" placeholder="Status update notes..." rows="2" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; resize: vertical;"></textarea>
                    </label>
                    <div class="save-buttons">
                        <button type="submit" name="save_action" value="stay" class="btn">Save & Stay</button>
                        <button type="submit" name="save_action" value="return" class="btn secondary">Save & Return</button>
                    </div>
                </form>
            </div>
            
            <!-- Add Tracking -->
            <div class="action-form">
                <h3 style="margin-bottom: 12px;">Tracking Number</h3>
                <form method="post">
                    <input type="hidden" name="action" value="update_tracking">
                    <input type="text" name="tracking_number" placeholder="Enter tracking number" 
                           value="<?php echo htmlspecialchars($order['tracking_number'] ?: ''); ?>"
                           style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    <div class="save-buttons">
                        <button type="submit" name="save_action" value="stay" class="btn">Save & Stay</button>
                        <button type="submit" name="save_action" value="return" class="btn secondary">Save & Return</button>
                    </div>
                </form>
            </div>
            
            <!-- Update Notes -->
            <div class="action-form">
                <h3 style="margin-bottom: 12px;">Order Notes</h3>
                <form method="post">
                    <input type="hidden" name="action" value="update_notes">
                    <textarea name="notes" rows="4" placeholder="Add order notes..."
                              style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; resize: vertical;"><?php echo htmlspecialchars($order['notes'] ?: ''); ?></textarea>
                    <div class="save-buttons">
                        <button type="submit" name="save_action" value="stay" class="btn">Save & Stay</button>
                        <button type="submit" name="save_action" value="return" class="btn secondary">Save & Return</button>
                    </div>
                </form>
            </div>
            
            <!-- Danger Zone -->
            <div style="border-top: 2px solid #fee2e2; padding-top: 16px; margin-top: 24px;">
                <h3 style="color: #dc2626; margin-bottom: 12px;">Danger Zone</h3>
                <form method="post" onsubmit="return confirm('Are you sure you want to delete this order? This action cannot be undone.')">
                    <input type="hidden" name="action" value="delete_order">
                    <button type="submit" class="btn" style="width: 100%; background: #dc2626; color: white;">
                        🗑️ Delete Order
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


