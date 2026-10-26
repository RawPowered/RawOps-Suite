<?php
$SEO = [
    'title' => 'Order Details — Product Customer Portal',
    'description' => 'View detailed order information and track your shipment.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

start_session();
require_login_or_redirect('/login.php');

$pdo = db();
$uid = current_user_id();
$orderId = intval($_GET['id'] ?? 0);

if ($orderId <= 0) {
    flash_set('error', 'Invalid order ID.');
    redirect('/customer/dashboard.php?tab=orders');
}

// Fetch order details with security check
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
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->execute([$orderId, $uid]);
    $order = $stmt->fetch();
    
    if (!$order) {
        flash_set('error', 'Order not found or access denied.');
        redirect('/customer/dashboard.php?tab=orders');
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
    
} catch (Exception $e) {
    flash_set('error', 'Database error: ' . $e->getMessage());
    redirect('/customer/dashboard.php?tab=orders');
}

// Fetch payment methods for this wholesale account
$paymentMethods = [];
if ($order['wholesale_account_id'] > 0) {
    try {
        $stmt = $pdo->prepare('SELECT id, type, card_brand, last_four, bank_name, is_default, exp_month, exp_year FROM payment_methods WHERE wholesale_account_id = ? AND user_id = ? ORDER BY is_default DESC, created_at DESC');
        $stmt->execute([$order['wholesale_account_id'], $uid]);
        $paymentMethods = $stmt->fetchAll();
    } catch (Throwable $e) { 
        $paymentMethods = []; 
    }
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment']) && in_array($order['status'], ['pending', 'submitted'])) {
    $paymentMethodId = intval($_POST['payment_method_id'] ?? 0);
    $paymentType = trim($_POST['payment_type'] ?? '');
    $enableAutoPay = isset($_POST['enable_autopay']);
    
    try {
        $pdo->beginTransaction();
        
        // If adding new payment method, insert it first
        if ($paymentType === 'new_card' || $paymentType === 'new_ach') {
            $type = $paymentType === 'new_card' ? 'credit_card' : 'ach';
            
            if ($type === 'credit_card') {
                // Credit card details
                $cardNumber = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
                $cardHolder = trim($_POST['card_holder_name'] ?? '');
                $expMonth = trim($_POST['exp_month'] ?? '');
                $expYear = trim($_POST['exp_year'] ?? '');
                $cvv = trim($_POST['cvv'] ?? '');
                $cardBrand = trim($_POST['card_brand'] ?? 'Card');
                
                if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
                    throw new Exception('Invalid card number');
                }
                
                $lastFour = substr($cardNumber, -4);
                
                // Insert card (stored for manual processing)
                $stmt = $pdo->prepare('
                    INSERT INTO payment_methods 
                    (user_id, wholesale_account_id, type, card_brand, last_four, exp_month, exp_year, 
                     card_holder_name, token, is_default, created_at) 
                    VALUES (?, ?, "credit_card", ?, ?, ?, ?, ?, ?, ?, NOW())
                ');
                $stmt->execute([
                    $uid,
                    $order['wholesale_account_id'],
                    $cardBrand,
                    $lastFour,
                    $expMonth,
                    $expYear,
                    $cardHolder,
                    json_encode(['card_number' => $cardNumber, 'cvv' => $cvv]), // Store for manual processing
                    $enableAutoPay ? 1 : 0
                ]);
                $paymentMethodId = $pdo->lastInsertId();
                
            } else {
                // ACH details
                $routingNumber = preg_replace('/\D/', '', $_POST['routing_number'] ?? '');
                $accountNumber = preg_replace('/\D/', '', $_POST['account_number'] ?? '');
                $accountType = trim($_POST['account_type'] ?? 'checking');
                $bankName = trim($_POST['bank_name'] ?? 'Bank Account');
                $accountHolder = trim($_POST['account_holder_name'] ?? '');
                
                if (strlen($routingNumber) !== 9) {
                    throw new Exception('Invalid routing number (must be 9 digits)');
                }
                if (strlen($accountNumber) < 4 || strlen($accountNumber) > 17) {
                    throw new Exception('Invalid account number');
                }
                
                $lastFour = substr($accountNumber, -4);
                
                // Insert ACH (stored for manual processing)
                $stmt = $pdo->prepare('
                    INSERT INTO payment_methods 
                    (user_id, wholesale_account_id, type, bank_name, last_four, 
                     card_holder_name, token, is_default, created_at) 
                    VALUES (?, ?, "ach", ?, ?, ?, ?, ?, NOW())
                ');
                $stmt->execute([
                    $uid,
                    $order['wholesale_account_id'],
                    $bankName,
                    $lastFour,
                    $accountHolder,
                    json_encode(['routing_number' => $routingNumber, 'account_number' => $accountNumber, 'account_type' => $accountType]), // Store for manual processing
                    $enableAutoPay ? 1 : 0
                ]);
                $paymentMethodId = $pdo->lastInsertId();
            }
            
            // Set as default if auto-pay enabled
            if ($enableAutoPay) {
                $pdo->prepare('UPDATE payment_methods SET is_default = 0 WHERE wholesale_account_id = ? AND id != ?')
                    ->execute([$order['wholesale_account_id'], $paymentMethodId]);
            }
        }
        
        // Check if payment_method_id column exists
        $colCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_method_id'")->fetch();
        
        if ($colCheck) {
            // Column exists, update with payment_method_id
            $stmt = $pdo->prepare('UPDATE orders SET status = "submitted", payment_method_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
            $stmt->execute([$paymentMethodId, $orderId, $uid]);
        } else {
            // Column doesn't exist yet, update without it
            $stmt = $pdo->prepare('UPDATE orders SET status = "submitted", updated_at = NOW() WHERE id = ? AND user_id = ?');
            $stmt->execute([$orderId, $uid]);
        }
        
        // If using existing method and auto-pay enabled, set as default
        if ($paymentType === 'existing' && $enableAutoPay && $paymentMethodId > 0) {
            $pdo->prepare('UPDATE payment_methods SET is_default = 0 WHERE wholesale_account_id = ?')->execute([$order['wholesale_account_id']]);
            $pdo->prepare('UPDATE payment_methods SET is_default = 1 WHERE id = ?')->execute([$paymentMethodId]);
        }
        
        $pdo->commit();
        
        // Notify admin of payment submission
        try {
            require_once __DIR__ . '/../required/email-triggers.php';
            
            // Get admin users
            $adminStmt = $pdo->query("
                SELECT DISTINCT u.id, u.email, u.first_name 
                FROM users u 
                JOIN user_roles ur ON u.id = ur.user_id 
                JOIN roles r ON ur.role_id = r.id 
                WHERE r.name IN ('admin', 'super_admin') AND u.status = 'active'
                LIMIT 5
            ");
            $admins = $adminStmt->fetchAll();
            
            foreach ($admins as $admin) {
                trigger_email_notification('payment_info_submitted', $admin['id'], [
                    'order_id' => $orderId,
                    'order_number' => $order['order_number'] ?: 'ORD-' . $orderId,
                    'customer_name' => $order['first_name'] . ' ' . $order['last_name'],
                    'customer_email' => $order['email'],
                    'order_total' => number_format($order['total'], 2),
                    'payment_method_type' => $paymentType === 'new_card' ? 'Credit Card' : ($paymentType === 'new_ach' ? 'ACH/Bank' : 'Existing Payment Method'),
                    'admin_order_url' => site_base_url() . '/admin/order-details.php?id=' . $orderId
                ]);
            }
        } catch (Throwable $e) {
            error_log("Failed to notify admin of payment submission for order {$orderId}: " . $e->getMessage());
        }
        
        // Set success message
        flash_set('success', '✅ Payment information submitted! Your order will be processed shortly.');
        
        // Redirect - MUST be done before ANY output
        header('Location: ' . path_with_base('/customer/order-details.php?id=' . $orderId));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        
        flash_set('error', 'Payment submission failed: ' . $e->getMessage());
        
        // Redirect on error too
        header('Location: ' . path_with_base('/customer/order-details.php?id=' . $orderId));
        exit;
    }
}

// Calculate order progress
$statusProgress = [
    'pending' => 1,
    'submitted' => 2,
    'paid' => 3,
    'fulfilled' => 4,
    'cancelled' => 0
];
$currentProgress = $statusProgress[$order['status']] ?? 0;
?>

<style>
.order-header {
    background: linear-gradient(135deg, #1e3a8a, #1e40af);
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
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
}
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
.progress-tracker {
    background: #f9fafb;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
}
.progress-steps {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
    position: relative;
}
.progress-step:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 15px;
    right: -50%;
    width: 100%;
    height: 2px;
    background: #e5e7eb;
    z-index: 1;
}
.progress-step.active:not(:last-child)::after {
    background: #3b82f6;
}
.progress-circle {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e5e7eb;
    color: #6b7280;
    font-weight: 600;
    font-size: 12px;
    z-index: 2;
    position: relative;
}
.progress-step.active .progress-circle {
    background: #3b82f6;
    color: white;
}
.progress-step.completed .progress-circle {
    background: #059669;
    color: white;
}
.progress-label {
    font-size: 11px;
    color: #6b7280;
    margin-top: 8px;
    text-align: center;
}
.progress-step.active .progress-label {
    color: #3b82f6;
    font-weight: 600;
}
.agent-card {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
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
.reorder-btn {
    background: #059669;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    width: 100%;
    margin-bottom: 12px;
}
@media (max-width: 768px) {
    .order-container {
        grid-template-columns: 1fr;
    }
    .progress-steps {
        flex-direction: column;
        gap: 16px;
    }
    .progress-step:not(:last-child)::after {
        display: none;
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
                    <?php echo htmlspecialchars($order['order_number'] ?: 'Order #' . str_pad($order['id'], 6, '0', STR_PAD_LEFT)); ?>
                </p>
            </div>
            <div class="no-print">
                <a href="<?php echo path_with_base('/customer/dashboard.php?tab=orders'); ?>" class="btn secondary">← Back to Orders</a>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <div class="order-container">
        <!-- Order Details -->
        <div class="order-main">
            <!-- Order Progress -->
            <div class="progress-tracker">
                <h3 style="margin-bottom: 16px;">Order Progress</h3>
                <div class="progress-steps">
                    <div class="progress-step <?php echo $currentProgress >= 1 ? 'active' : ''; ?>">
                        <div class="progress-circle">1</div>
                        <div class="progress-label">Order<br>Placed</div>
                    </div>
                    <div class="progress-step <?php echo $currentProgress >= 2 ? 'active' : ''; ?>">
                        <div class="progress-circle">2</div>
                        <div class="progress-label">Processing</div>
                    </div>
                    <div class="progress-step <?php echo $currentProgress >= 3 ? 'active' : ''; ?>">
                        <div class="progress-circle">3</div>
                        <div class="progress-label">Payment<br>Confirmed</div>
                    </div>
                    <div class="progress-step <?php echo $currentProgress >= 4 ? 'active' : ''; ?>">
                        <div class="progress-circle">4</div>
                        <div class="progress-label">Shipped &<br>Delivered</div>
                    </div>
                </div>
                <p style="text-align: center; color: #6b7280; font-size: 14px;">
                    <?php if ($order['status'] === 'cancelled'): ?>
                        This order has been cancelled.
                    <?php elseif ($order['status'] === 'fulfilled'): ?>
                        Your order has been delivered! Thank you for your business.
                    <?php elseif ($order['status'] === 'paid'): ?>
                        Your order is being prepared for shipment.
                    <?php elseif ($order['status'] === 'submitted'): ?>
                        Your order is being processed.
                    <?php else: ?>
                        Your order is pending confirmation.
                    <?php endif; ?>
                </p>
            </div>
            
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
                        <p><strong>Shipping Method:</strong> <?php echo htmlspecialchars($order['shipping_method'] ?: 'Standard'); ?></p>
                        <?php if ($order['tracking_number']): ?>
                        <p><strong>Tracking Number:</strong> 
                            <a href="#" style="color: #3b82f6; text-decoration: underline;" onclick="alert('Track your package with: <?php echo htmlspecialchars($order['tracking_number']); ?>')">
                                <?php echo htmlspecialchars($order['tracking_number']); ?>
                            </a>
                        </p>
                        <?php endif; ?>
                        <?php if ($order['updated_at']): ?>
                        <p><strong>Last Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($order['updated_at'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Shipping Information -->
            <div style="margin-bottom: 32px;">
                <h2 class="section-title">Shipping Information</h2>
                <div style="background: #f9fafb; padding: 16px; border-radius: 8px;">
                    <p><strong>Business:</strong> <?php echo htmlspecialchars($order['business_name']); ?></p>
                    <?php if ($order['address_line1']): ?>
                    <p style="margin-top: 8px;"><strong>Shipping Address:</strong></p>
                    <div style="color: #6b7280; margin-top: 4px;">
                        <?php echo htmlspecialchars($order['business_name']); ?><br>
                        <?php echo htmlspecialchars($order['address_line1']); ?><br>
                        <?php if ($order['address_line2']): ?>
                            <?php echo htmlspecialchars($order['address_line2']); ?><br>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($order['city'] . ', ' . $order['state_region'] . ' ' . $order['postal_code']); ?><br>
                        <?php echo htmlspecialchars($order['country']); ?>
                    </div>
                    <?php else: ?>
                    <p style="color: #6b7280; margin-top: 8px;"><em>Shipping address will be confirmed during processing</em></p>
                    <?php endif; ?>
                </div>
            </div>
            
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
            <?php if ($order['notes']): ?>
            <div>
                <h2 class="section-title">Order Notes</h2>
                <p style="background: #f9fafb; padding: 16px; border-radius: 8px; color: #374151;">
                    <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Order Actions Sidebar -->
        <div class="order-sidebar no-print">
            <button onclick="window.print()" class="print-invoice">🖨️ Print Invoice</button>
            
            <!-- Payment Section -->
            <?php if (in_array($order['status'], ['pending', 'submitted'])): ?>
            <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #f59e0b; border-radius: 12px; padding: 20px; margin: 16px 0;">
                <h4 style="color: #92400e; margin: 0 0 12px;">💳 Payment Required</h4>
                <p style="margin: 0 0 16px; color: #78350f; font-size: 20px; font-weight: 600;">
                    Total Due: $<?php echo number_format($order['total'], 2); ?>
                </p>
                
                <form method="post" id="paymentForm">
                    <input type="hidden" name="process_payment" value="1">
                    <input type="hidden" name="payment_type" id="payment_type" value="<?php echo !empty($paymentMethods) ? 'existing' : 'new_card'; ?>">
                    
                    <?php if (!empty($paymentMethods)): ?>
                    <h5 style="margin: 0 0 12px; color: #1e293b; font-size: 14px;">Select Payment Method:</h5>
                    
                    <?php foreach ($paymentMethods as $pm): ?>
                    <label style="display: block; padding: 12px; border: 2px solid <?php echo $pm['is_default'] ? '#059669' : '#e5e7eb'; ?>; border-radius: 8px; margin-bottom: 8px; cursor: pointer; background: white;">
                        <input type="radio" name="payment_method_id" value="<?php echo $pm['id']; ?>" <?php echo $pm['is_default'] ? 'checked' : ''; ?> onchange="document.getElementById('payment_type').value='existing';hideNewPaymentForms();">
                        <strong style="margin-left: 8px;">
                            <?php if ($pm['type'] === 'credit_card'): ?>
                                💳 <?php echo htmlspecialchars($pm['card_brand'] ?: 'Card'); ?> ••••<?php echo htmlspecialchars($pm['last_four']); ?>
                                <?php if ($pm['exp_month']): ?>
                                    <span style="color: #6b7280; font-size: 12px;">(exp <?php echo $pm['exp_month'].'/'.$pm['exp_year']; ?>)</span>
                                <?php endif; ?>
                            <?php elseif ($pm['type'] === 'ach'): ?>
                                🏦 <?php echo htmlspecialchars($pm['bank_name'] ?: 'Bank'); ?> ••••<?php echo htmlspecialchars($pm['last_four']); ?>
                            <?php endif; ?>
                        </strong>
                        <?php if ($pm['is_default']): ?>
                            <span style="background: #059669; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 8px;">Default</span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                    
                    <div style="text-align: center; margin: 12px 0; color: #6b7280; font-size: 14px;">— OR —</div>
                    <?php endif; ?>
                    
                    <!-- Add New Payment Method Options -->
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <button type="button" onclick="showNewCardForm()" style="flex: 1; padding: 10px; background: #3b82f6; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                            💳 New Credit Card
                        </button>
                        <button type="button" onclick="showNewACHForm()" style="flex: 1; padding: 10px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                            🏦 New ACH/Bank
                        </button>
                    </div>
                    
                    <!-- New Credit Card Form -->
                    <div id="newCardForm" style="display: none; background: white; padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                        <h6 style="margin: 0 0 12px; color: #1e293b;">Credit Card Details</h6>
                        <input type="text" name="card_holder_name" placeholder="Cardholder Name" style="width: 100%; padding: 10px; margin-bottom: 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <input type="text" name="card_number" placeholder="Card Number" maxlength="19" style="width: 100%; padding: 10px; margin-bottom: 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 8px;">
                            <input type="text" name="exp_month" placeholder="MM" maxlength="2" style="padding: 10px; border: 1px solid #d1d5db; border-radius: 4px;">
                            <input type="text" name="exp_year" placeholder="YYYY" maxlength="4" style="padding: 10px; border: 1px solid #d1d5db; border-radius: 4px;">
                            <input type="text" name="cvv" placeholder="CVV" maxlength="4" style="padding: 10px; border: 1px solid #d1d5db; border-radius: 4px;">
                        </div>
                        <select name="card_brand" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 4px;">
                            <option value="Visa">Visa</option>
                            <option value="Mastercard">Mastercard</option>
                            <option value="American Express">American Express</option>
                            <option value="Discover">Discover</option>
                        </select>
                    </div>
                    
                    <!-- New ACH Form -->
                    <div id="newACHForm" style="display: none; background: white; padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                        <h6 style="margin: 0 0 12px; color: #1e293b;">Bank Account Details</h6>
                        <input type="text" name="account_holder_name" placeholder="Account Holder Name" style="width: 100%; padding: 10px; margin-bottom: 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <input type="text" name="bank_name" placeholder="Bank Name" style="width: 100%; padding: 10px; margin-bottom: 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <input type="text" name="routing_number" placeholder="Routing Number (9 digits)" maxlength="9" style="width: 100%; padding: 10px; margin-bottom: 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <input type="text" name="account_number" placeholder="Account Number" style="width: 100%; padding: 10px; margin-bottom: 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <select name="account_type" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 4px;">
                            <option value="checking">Checking Account</option>
                            <option value="savings">Savings Account</option>
                        </select>
                    </div>
                    
                    <label style="display: flex; align-items: center; gap: 8px; margin: 12px 0; cursor: pointer;">
                        <input type="checkbox" name="enable_autopay">
                        <span style="font-size: 13px; color: #374151;">Save & use for future auto-pay</span>
                    </label>
                    
                    <button type="submit" style="width: 100%; background: #059669; color: white; padding: 14px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer;">
                        Submit Payment Info — $<?php echo number_format($order['total'], 2); ?>
                    </button>
                    
                    <p style="margin: 12px 0 0; font-size: 12px; color: #6b7280; text-align: center;">
                        🔒 Payment information is securely stored for manual processing
                    </p>
                </form>
                
                <script>
                function hideNewPaymentForms() {
                    document.getElementById('newCardForm').style.display = 'none';
                    document.getElementById('newACHForm').style.display = 'none';
                }
                function showNewCardForm() {
                    document.getElementById('payment_type').value = 'new_card';
                    document.getElementById('newCardForm').style.display = 'block';
                    document.getElementById('newACHForm').style.display = 'none';
                    // Uncheck existing payment methods
                    document.querySelectorAll('input[name="payment_method_id"]').forEach(r => r.checked = false);
                }
                function showNewACHForm() {
                    document.getElementById('payment_type').value = 'new_ach';
                    document.getElementById('newACHForm').style.display = 'block';
                    document.getElementById('newCardForm').style.display = 'none';
                    // Uncheck existing payment methods
                    document.querySelectorAll('input[name="payment_method_id"]').forEach(r => r.checked = false);
                }
                </script>
            </div>
            <?php endif; ?>
            
            <?php if ($order['status'] === 'fulfilled'): ?>
            <form method="post" action="/shop/cart.php">
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <button type="submit" class="reorder-btn">🔄 Reorder Items</button>
            </form>
            <?php endif; ?>
            
            <!-- Sales Agent Contact -->
            <?php if ($order['sales_agent_id'] && $order['agent_first_name']): ?>
            <div class="agent-card">
                <h4 style="color: #14532d; margin-bottom: 8px;">Your Sales Representative</h4>
                <p style="font-weight: 600;">
                    <?php echo htmlspecialchars($order['agent_first_name'] . ' ' . $order['agent_last_name']); ?>
                </p>
                <p style="font-size: 14px; color: #6b7280; margin-top: 4px;">
                    📧 <a href="mailto:<?php echo htmlspecialchars($order['agent_email']); ?>" style="color: #059669;">
                        <?php echo htmlspecialchars($order['agent_email']); ?>
                    </a>
                </p>
                <p style="font-size: 13px; color: #374151; margin-top: 8px;">
                    Contact for order questions or support
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Order Actions -->
            <div style="background: #f9fafb; border-radius: 8px; padding: 16px;">
                <h4 style="margin-bottom: 12px;">Need Help?</h4>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <a href="/support/" class="btn secondary" style="text-decoration: none; text-align: center;">
                        💬 Contact Support
                    </a>
                    <a href="<?php echo path_with_base('/customer/dashboard.php?tab=orders'); ?>" class="btn secondary" style="text-decoration: none; text-align: center;">
                        📋 View All Orders
                    </a>
                    <a href="<?php echo path_with_base('/shop/'); ?>" class="btn secondary" style="text-decoration: none; text-align: center;">
                        🛒 Continue Shopping
                    </a>
                </div>
            </div>
            
            <!-- Estimated Delivery -->
            <?php if ($order['status'] !== 'cancelled' && $order['status'] !== 'fulfilled'): ?>
            <div style="background: #dbeafe; border-radius: 8px; padding: 16px; margin-top: 16px;">
                <h4 style="color: #1e40af; margin-bottom: 8px;">📦 Estimated Delivery</h4>
                <p style="font-size: 16px; font-weight: 600; color: #1e40af;">
                    <?php 
                    $estimatedDate = date('F j, Y', strtotime($order['created_at'] . ' +5 business days'));
                    echo $estimatedDate;
                    ?>
                </p>
                <p style="color: #6b7280; font-size: 13px; margin-top: 4px;">
                    Standard shipping (3-5 business days)
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
