<?php
$SEO = [
    'title' => 'Order Details — Admin',
    'description' => 'View detailed order information and invoice.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$orderId = intval($_GET['id'] ?? 0);

if ($orderId <= 0) {
    flash_set('error', 'Invalid order ID.');
    redirect(path_with_base('/admin/orders.php'));
}

// Handle order updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_status') {
            $newStatus = $_POST['status'] ?? '';
            $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStatus, $orderId]);
            flash_set('success', 'Order status updated successfully.');
        } 
        elseif ($action === 'assign_agent') {
            $agentId = intval($_POST['sales_agent_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE orders SET sales_agent_id = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$agentId > 0 ? $agentId : null, $orderId]);
            flash_set('success', 'Sales agent assigned successfully.');
        }
        elseif ($action === 'add_tracking') {
            $tracking = trim($_POST['tracking_number'] ?? '');
            $stmt = $pdo->prepare("UPDATE orders SET tracking_number = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$tracking, $orderId]);
            flash_set('success', 'Tracking number added successfully.');
        }
        elseif ($action === 'update_commission_rate') {
            $rate = is_numeric($_POST['commission_rate'] ?? null) ? round(floatval($_POST['commission_rate']), 2) : null;
            $stmt = $pdo->prepare("UPDATE orders SET commission_rate = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$rate, $orderId]);
            // Recompute commissions: clear existing and reinsert
            $pdo->prepare("DELETE FROM commissions WHERE order_id = ? AND commission_type IN ('sales_agent')")->execute([$orderId]);
            require_once __DIR__ . '/process-commission.php';
            $subtotal = floatval($pdo->query("SELECT subtotal FROM orders WHERE id = $orderId")->fetchColumn());
            $salesAgentId = $pdo->query("SELECT sales_agent_id FROM orders WHERE id = $orderId")->fetchColumn();
            calculateCommissions($orderId, $subtotal, $salesAgentId ?: null);
            flash_set('success', 'Commission rate updated and recalculated.');
        }
        elseif ($action === 'update_notes') {
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $pdo->prepare("UPDATE orders SET notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$notes, $orderId]);
            flash_set('success', 'Order notes updated successfully.');
        }
        elseif ($action === 'delete_order') {
            // Delete order and related data
            $pdo->beginTransaction();
            try {
                require_once __DIR__ . '/../required/commission-utils.php';
                commission_handle_order_cancellation($pdo, $orderId);
                $pdo->prepare("DELETE FROM commissions WHERE order_id = ?")->execute([$orderId]);

                // Delete order items
                $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
                $stmt->execute([$orderId]);
                
                // Delete order
                $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
                $stmt->execute([$orderId]);
                
                $pdo->commit();
                flash_set('success', 'Order deleted successfully.');
                redirect(path_with_base('/admin/orders.php'));
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        
        redirect(path_with_base('/admin/order-details.php') . "?id=$orderId");
        
    } catch (Exception $e) {
        $error = "Error updating order: " . $e->getMessage();
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
        redirect(path_with_base('/admin/orders.php'));
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
    
    // Fetch available sales agents
    $stmt = $pdo->query("
        SELECT u.id, u.first_name, u.last_name, u.email
        FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id
        WHERE r.name = 'sales_agent' AND u.status = 'active'
        ORDER BY u.first_name, u.last_name
    ");
    $salesAgents = $stmt->fetchAll();
    
    // Fetch commissions for this order
    $stmt = $pdo->prepare("
        SELECT c.*, u.first_name, u.last_name
        FROM commissions c
        JOIN users u ON u.id = c.user_id
        WHERE c.order_id = ?
        ORDER BY c.commission_type, c.created_at
    ");
    $stmt->execute([$orderId]);
    $commissions = $stmt->fetchAll();
    
    // Fetch payment method details if available
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
    redirect(path_with_base('/admin/orders.php'));
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
.status-draft { background: #f3f4f6; color: #6b7280; }
.status-pending { background: #fef3c7; color: #92400e; }
.status-submitted { background: #dbeafe; color: #1e40af; }
.status-paid { background: #e0e7ff; color: #4338ca; }
.status-fulfilled { background: #dcfce7; color: #14532d; }
.status-cancelled { background: #fee2e2; color: #991b1b; }
.payment-paid { color: #059669; font-weight: 600; }
.payment-pending { color: #f59e0b; font-weight: 600; }
.payment-failed { color: #dc2626; font-weight: 600; }
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
.commission-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #f3f4f6;
}
.print-invoice {
    background: #3b82f6;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    margin-bottom: 16px;
    width: 100%;
}
@media (max-width: 768px) {
    .order-container {
        grid-template-columns: 1fr;
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
                <a href="<?php echo path_with_base('/admin/orders.php'); ?>" class="btn secondary">← Back to Orders</a>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <?php if (isset($error)): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
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
                        <p><strong>Status:</strong> <span class="status-badge status-<?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span></p>
                        <p><strong>Payment Status:</strong> <span class="payment-<?php echo $order['payment_status']; ?>"><?php echo ucfirst($order['payment_status']); ?></span></p>
                    </div>
                    <div>
                        <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($order['payment_method'] ?: 'Net Terms'); ?></p>
                        <p><strong>Commission Rate (Invoice):</strong> <?php echo $order['commission_rate'] !== null ? number_format($order['commission_rate'], 2) . '%': '<em>Not set (tier-based)</em>'; ?></p>
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
            
            <!-- Sales Agent -->
            <div style="margin-bottom: 32px;">
                <h2 class="section-title">Sales Representative</h2>
                <?php if ($order['sales_agent_id']): ?>
                    <p><strong>Agent:</strong> <?php echo htmlspecialchars($order['agent_first_name'] . ' ' . $order['agent_last_name']); ?></p>
                    <p><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($order['agent_email']); ?>"><?php echo htmlspecialchars($order['agent_email']); ?></a></p>
                <?php else: ?>
                    <p style="color: #6b7280;"><em>No sales agent assigned</em></p>
                <?php endif; ?>
            </div>
            
            <!-- Payment Method Details (Admin Only) -->
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
                            <p style="font-size: 13px; font-weight: 600; color: #991b1b; margin-bottom: 8px;">⚠️ ADMIN ONLY - Full Card Details:</p>
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
                            <p style="font-size: 13px; font-weight: 600; color: #991b1b; margin-bottom: 8px;">⚠️ ADMIN ONLY - Full ACH Details:</p>
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
            
            <!-- Commissions -->
            <?php if (!empty($commissions)): ?>
            <div style="margin-bottom: 32px;">
                <h2 class="section-title">Commissions</h2>
                <?php foreach ($commissions as $commission): ?>
                <div class="commission-item">
                    <div>
                        <strong><?php echo htmlspecialchars($commission['first_name'] . ' ' . $commission['last_name']); ?></strong>
                        <div style="color: #6b7280; font-size: 13px;">
                            <?php echo ucfirst(str_replace('_', ' ', $commission['commission_type'])); ?> (<?php echo $commission['percentage']; ?>%)
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <strong>$<?php echo number_format($commission['amount'], 2); ?></strong>
                        <div style="color: #6b7280; font-size: 13px;">
                            <?php echo ucfirst($commission['status']); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
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
            <button onclick="window.print()" class="print-invoice">🖨️ Print Invoice</button>
            
            <!-- Update Status -->
            <div class="action-form">
                <h3 style="margin-bottom: 12px;">Update Status</h3>
                <form method="post">
                    <input type="hidden" name="action" value="update_status">
                    <select name="status" style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="draft" <?php echo $order['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending" <?php echo $order['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="submitted" <?php echo $order['status'] === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                        <option value="paid" <?php echo $order['status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="fulfilled" <?php echo $order['status'] === 'fulfilled' ? 'selected' : ''; ?>>Fulfilled</option>
                        <option value="cancelled" <?php echo $order['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                    <button type="submit" class="btn" style="width: 100%;">Update Status</button>
                </form>
            </div>
            
            <!-- Assign Sales Agent -->
            <div class="action-form">
                <h3 style="margin-bottom: 12px;">Assign Sales Agent</h3>
                <form method="post">
                    <input type="hidden" name="action" value="assign_agent">
                    <select name="sales_agent_id" style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="">No Agent</option>
                        <?php foreach ($salesAgents as $agent): ?>
                        <option value="<?php echo $agent['id']; ?>" <?php echo $order['sales_agent_id'] == $agent['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn" style="width: 100%;">Assign Agent</button>
                </form>
            </div>
            
            <!-- Add Tracking -->
            <div class="action-form">
                <h3 style="margin-bottom: 12px;">Tracking Number</h3>
                <form method="post">
                    <input type="hidden" name="action" value="add_tracking">
                    <input type="text" name="tracking_number" placeholder="Enter tracking number" 
                           value="<?php echo htmlspecialchars($order['tracking_number'] ?: ''); ?>"
                           style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    <button type="submit" class="btn" style="width: 100%;">Update Tracking</button>
                </form>
            </div>

            <!-- Per-Invoice Commission -->
            <div class="action-form">
                <h3 style="margin-bottom: 12px;">Per-Invoice Commission</h3>
                <form method="post">
                    <input type="hidden" name="action" value="update_commission_rate">
                    <label style="display:block; margin-bottom:8px; font-size:13px; color:#6b7280;">Commission Rate (%) — leave blank to use tier table</label>
                    <input type="text" name="commission_rate" placeholder="e.g., 12"
                           value="<?php echo $order['commission_rate'] !== null ? htmlspecialchars($order['commission_rate']) : ''; ?>"
                           style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    <button type="submit" class="btn" style="width: 100%;">Save Commission Rate</button>
                </form>
                <p style="color:#6b7280; font-size:12px; margin-top:8px;">This rate applies to this invoice only and overrides quantity tiers. Existing sales-agent commission rows will be recalculated.</p>
            </div>
            
            <!-- Update Notes -->
            <div class="action-form">
                <h3 style="margin-bottom: 12px;">Order Notes</h3>
                <form method="post">
                    <input type="hidden" name="action" value="update_notes">
                    <textarea name="notes" rows="4" placeholder="Add order notes..."
                              style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; resize: vertical;"><?php echo htmlspecialchars($order['notes'] ?: ''); ?></textarea>
                    <button type="submit" class="btn" style="width: 100%;">Update Notes</button>
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
