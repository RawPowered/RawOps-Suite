<?php
$SEO = [
    'title' => 'Order Confirmation — Product Wholesale',
    'description' => 'Your wholesale Product order has been successfully submitted.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

start_session();

// Check if user is logged in and has wholesale account
$uid = current_user_id();
if (!$uid) {
    flash_set('error', 'Please log in to view order details.');
    redirect('/login.php');
}

$pdo = db();
$wholesaleAccount = ensure_wholesale_account($uid);

if (!$wholesaleAccount) {
    flash_set('error', 'Wholesale account required to view orders.');
    redirect('/shop/');
}

// Get order details
$orderNumber = $_GET['order'] ?? '';
if (empty($orderNumber)) {
    flash_set('error', 'Order number is required.');
    redirect('/shop/');
}

// Fetch order details
$stmt = $pdo->prepare('
    SELECT o.*, 
           wa.business_name,
           sa.first_name as agent_first_name, sa.last_name as agent_last_name, sa.email as agent_email
    FROM orders o
    JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
    LEFT JOIN users sa ON sa.id = o.sales_agent_id
    WHERE o.order_number = ? AND o.user_id = ?
');
$stmt->execute([$orderNumber, $uid]);
$order = $stmt->fetch();

if (!$order) {
    flash_set('error', 'Order not found.');
    redirect('/shop/');
}

// Fetch order items
$stmt = $pdo->prepare('
    SELECT * FROM order_items 
    WHERE order_id = ? 
    ORDER BY id
');
$stmt->execute([$order['id']]);
$orderItems = $stmt->fetchAll();

// Calculate order date formatting
$orderDate = date('F j, Y \a\t g:i A', strtotime($order['created_at']));
$estimatedDelivery = date('F j, Y', strtotime($order['created_at'] . ' +3 business days'));
?>

<style>
.success-header {
    background: linear-gradient(135deg, #059669, #047857);
    color: white;
    padding: 60px 0;
    margin-bottom: 40px;
    text-align: center;
}
.success-icon {
    font-size: 64px;
    margin-bottom: 16px;
    display: block;
}
.order-summary-container {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 32px;
    margin-bottom: 40px;
}
.order-details {
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
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e5e7eb;
}
.order-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 0;
    border-bottom: 1px solid #f3f4f6;
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
    font-size: 20px;
    font-weight: 600;
    padding-top: 12px;
}
.status-badge {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
}
.status-pending {
    background: #fef3c7;
    color: #92400e;
}
.next-steps {
    background: #dbeafe;
    border: 1px solid #3b82f6;
    border-radius: 8px;
    padding: 20px;
    margin-top: 24px;
}
.agent-contact {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 8px;
    padding: 16px;
    margin-top: 16px;
}
@media (max-width: 768px) {
    .order-summary-container {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="success-header">
    <div class="container">
        <span class="success-icon">✅</span>
        <h1 style="font-size: 42px; margin-bottom: 12px;">Order Confirmed!</h1>
        <p style="font-size: 20px; opacity: 0.9; margin-bottom: 8px;">
            Order #<?php echo htmlspecialchars($orderNumber); ?>
        </p>
        <p style="font-size: 16px; opacity: 0.8;">
            Placed on <?php echo $orderDate; ?>
        </p>
    </div>
</div>

<div class="container">
    <div class="order-summary-container">
        <!-- Order Details -->
        <div class="order-details">
            <h2 class="section-title">Order Details</h2>
            
            <!-- Order Items -->
            <div style="margin-bottom: 32px;">
                <h3 style="margin-bottom: 16px; font-size: 18px;">Items Ordered</h3>
                <?php foreach ($orderItems as $item): ?>
                <div class="order-item">
                    <div class="item-details">
                        <h4><?php echo htmlspecialchars($item['product_name']); ?></h4>
                        <div class="item-meta">
                            SKU: <?php echo htmlspecialchars($item['product_sku']); ?> • 
                            Qty: <?php echo $item['quantity']; ?> × 
                            $<?php echo number_format($item['unit_price'], 2); ?>
                        </div>
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
            
            <!-- Business Information -->
            <div style="margin-bottom: 32px;">
                <h3 style="margin-bottom: 16px; font-size: 18px;">Shipping Information</h3>
                <div style="background: #f9fafb; padding: 16px; border-radius: 8px;">
                    <p><strong>Business:</strong> <?php echo htmlspecialchars($order['business_name']); ?></p>
                    <?php if (!empty($order['shipping_address'])): ?>
                    <p style="margin-top: 8px;"><strong>Shipping Address:</strong></p>
                    <div style="white-space: pre-line; color: #6b7280; margin-top: 4px;">
                        <?php echo htmlspecialchars($order['shipping_address']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['notes'])): ?>
                    <p style="margin-top: 12px;"><strong>Order Notes:</strong></p>
                    <p style="color: #6b7280; margin-top: 4px;">
                        <?php echo htmlspecialchars($order['notes']); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Payment Information -->
            <div>
                <h3 style="margin-bottom: 16px; font-size: 18px;">Payment Information</h3>
                <div style="background: #f9fafb; padding: 16px; border-radius: 8px;">
                    <p><strong>Payment Method:</strong> 
                        <?php 
                        $paymentMethod = $order['payment_method'];
                        if ($paymentMethod === 'net_terms') {
                            echo 'Net 30 Terms';
                        } else {
                            echo 'Payment Method ID: ' . htmlspecialchars($paymentMethod);
                        }
                        ?>
                    </p>
                    <p style="margin-top: 8px;"><strong>Payment Status:</strong> 
                        <span class="status-badge status-pending">
                            <?php echo ucfirst($order['payment_status']); ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Order Status Sidebar -->
        <div class="order-sidebar">
            <h3 style="margin-bottom: 16px;">Order Status</h3>
            
            <div style="text-align: center; margin-bottom: 24px;">
                <div class="status-badge status-pending">
                    <?php echo ucfirst($order['status']); ?>
                </div>
                <p style="color: #6b7280; margin-top: 8px; font-size: 14px;">
                    We're processing your order
                </p>
            </div>
            
            <div style="margin-bottom: 24px;">
                <h4 style="margin-bottom: 12px;">Estimated Delivery</h4>
                <p style="font-size: 18px; font-weight: 600; color: #059669;">
                    <?php echo $estimatedDelivery; ?>
                </p>
                <p style="color: #6b7280; font-size: 13px; margin-top: 4px;">
                    3-5 business days via <?php echo htmlspecialchars($order['shipping_method'] ?: 'Standard Shipping'); ?>
                </p>
            </div>
            
            <?php if ($order['sales_agent_id'] && $order['agent_first_name']): ?>
            <div class="agent-contact">
                <h4 style="color: #14532d; margin-bottom: 8px;">Your Sales Representative</h4>
                <p style="font-weight: 600;">
                    <?php echo htmlspecialchars($order['agent_first_name'] . ' ' . $order['agent_last_name']); ?>
                </p>
                <p style="font-size: 14px; color: #6b7280; margin-top: 4px;">
                    📧 <?php echo htmlspecialchars($order['agent_email']); ?>
                </p>
                <p style="font-size: 13px; color: #374151; margin-top: 8px;">
                    Contact for order updates or questions
                </p>
            </div>
            <?php endif; ?>
            
            <div class="next-steps">
                <h4 style="color: #1e40af; margin-bottom: 12px;">What's Next?</h4>
                <ol style="color: #374151; font-size: 14px; padding-left: 20px;">
                    <li style="margin-bottom: 8px;">Order confirmation email sent</li>
                    <li style="margin-bottom: 8px;">Processing begins within 24 hours</li>
                    <li style="margin-bottom: 8px;">Shipment tracking provided</li>
                    <li>Delivery within 3-5 business days</li>
                </ol>
            </div>
            
            <div style="margin-top: 24px; text-align: center;">
                <a href="<?php echo path_with_base('/shop/'); ?>" class="btn" style="background: var(--green); color: white; width: 100%; margin-bottom: 12px;">
                    Continue Shopping
                </a>
                <a href="<?php echo path_with_base('/customer/'); ?>" class="btn secondary" style="width: 100%;">
                    View Dashboard
                </a>
            </div>
        </div>
    </div>
    
    <!-- Additional Information -->
    <div style="background: #f9fafb; border-radius: 12px; padding: 24px; margin-bottom: 40px;">
        <h3 style="margin-bottom: 16px;">Important Information</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
            <div>
                <h4 style="color: #059669; margin-bottom: 8px;">📦 Shipping & Handling</h4>
                <ul style="color: #6b7280; font-size: 14px; margin: 0; padding-left: 20px;">
                    <li>All orders processed within 24 hours</li>
                    <li>Free shipping on orders over $500</li>
                    <li>Tracking information provided via email</li>
                    <li>Signature required for delivery</li>
                </ul>
            </div>
            <div>
                <h4 style="color: #059669; margin-bottom: 8px;">💳 Payment Terms</h4>
                <ul style="color: #6b7280; font-size: 14px; margin: 0; padding-left: 20px;">
                    <li>Net 30 terms for approved accounts</li>
                    <li>Payment due within 30 days of invoice</li>
                    <li>Late fees apply after 30 days</li>
                    <li>Multiple payment methods accepted</li>
                </ul>
            </div>
            <div>
                <h4 style="color: #059669; margin-bottom: 8px;">🛡️ Quality Guarantee</h4>
                <ul style="color: #6b7280; font-size: 14px; margin: 0; padding-left: 20px;">
                    <li>catalog-ready quality assured</li>
                    <li>Third-party lab tested</li>
                    <li>100% satisfaction guarantee</li>
                    <li>Compliant with all regulations</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
