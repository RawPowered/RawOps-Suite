<?php
$SEO = [
    'title' => 'Checkout — Product Wholesale',
    'description' => 'Complete your wholesale Product order.',
    'type' => 'website'
];

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/commission-utils.php';
require_once __DIR__ . '/../required/email-triggers.php'; // Add email trigger system

start_session();

// Check if user is logged in
$uid = current_user_id();
if (!$uid) {
    flash_set('error', 'Please log in to checkout.');
    redirect('/login.php');
    exit; // Ensure no further rendering
}

$pdo = db();

// Check if user is admin - admins get full access without account requirements
$isAdmin = user_has_role($uid, 'admin');

// For admins, create a mock wholesale account for checkout processing
if ($isAdmin) {
    // Get admin user details
    $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $adminUser = $stmt->fetch();
    
    $wholesaleAccount = [
        'id' => null, // No actual wholesale_accounts record
        'user_id' => $uid,
        'business_name' => 'Admin Test Order',
        'first_name' => $adminUser['first_name'] ?? 'Admin',
        'last_name' => $adminUser['last_name'] ?? 'User',
        'email' => $adminUser['email'] ?? '',
        'phone' => $adminUser['phone'] ?? '',
        'sales_agent_id' => null,
        'has_coc' => false,
        'address_line1' => '',
        'address_line2' => '',
        'city' => '',
        'state_region' => '',
        'postal_code' => '',
        'country' => 'USA'
    ];
} else {
    // Regular user - must have wholesale account
    $wholesaleAccount = ensure_wholesale_account($uid);
    
    if (!$wholesaleAccount) {
        flash_set('error', 'Wholesale account required to checkout.');
        redirect('/shop/');
        exit;
    }
}

// Check cart
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    flash_set('error', 'Your cart is empty.');
    redirect('/shop/cart.php');
}

// Get payment methods (check if table exists)
$paymentMethods = [];
try {
    $stmt = $pdo->prepare('
        SELECT * FROM payment_methods 
        WHERE wholesale_account_id = ? 
        ORDER BY is_default DESC, created_at DESC
    ');
    $stmt->execute([$wholesaleAccount['id']]);
    $paymentMethods = $stmt->fetchAll();
} catch (PDOException $e) {
    // Table might not exist, continue with empty payment methods
    $paymentMethods = [];
}

// Load active products from DB to ensure pricing matches spreadsheet
$rows = $pdo->query("SELECT id, sku, name, wholesale_price, msrp FROM products WHERE active = 1 ORDER BY name")->fetchAll();
$products = [];
foreach ($rows as $r) {
    $idSlug = strtolower(str_replace(['ELIX-','BLEND-'], ['elix-','blend-'], $r['sku']));
    $products[] = [
        'id' => $idSlug,
        'sku' => $r['sku'],
        'name' => $r['name'],
        'wholesale_price' => (float)$r['wholesale_price'],
        'retail_price' => (float)$r['msrp']
    ];
}

// Calculate totals with bulk pricing
$subtotal = 0;
$cartItems = [];
$totalQuantity = array_sum(array_column($cart, 'quantity'));

// Determine bulk discount
$bulkDiscount = 0;
if ($totalQuantity >= 20) {
    $bulkDiscount = 15;
} elseif ($totalQuantity >= 15) {
    $bulkDiscount = 11;
} elseif ($totalQuantity >= 10) {
    $bulkDiscount = 8;
} elseif ($totalQuantity >= 5) {
    $bulkDiscount = 5;
}

foreach ($cart as $cartItem) {
    foreach ($products as $product) {
        if ($product['id'] === $cartItem['product_id']) {
            $item = $product;
            $item['quantity'] = $cartItem['quantity'];
            
            // Apply bulk discount
            $discountedPrice = $product['wholesale_price'] * (1 - $bulkDiscount / 100);
            $item['unit_price'] = $discountedPrice;
            $item['original_price'] = $product['wholesale_price'];
            $item['discount_percent'] = $bulkDiscount;
            $item['line_total'] = $discountedPrice * $cartItem['quantity'];
            $item['wholesale_price'] = $product['wholesale_price']; // Add this for order processing
            
            $cartItems[] = $item;
            $subtotal += $item['line_total'];
            break;
        }
    }
}

$shippingCost = $subtotal >= 500 ? 0 : 25;
$taxRate = 0.08;
$taxAmount = $subtotal * $taxRate;
$total = $subtotal + $shippingCost + $taxAmount;

// Determine commission rate from product_group_commissions (dynamic tier system)
// Get product group ID from first cart item
$productGroupId = null;
if (!empty($cartItems)) {
    foreach ($cartItems as $item) {
        if (!empty($item['product_group_id'])) {
            $productGroupId = $item['product_group_id'];
            break;
        }
    }
}

// Query commission tier based on total quantity
$agentCommissionRate = 20; // Default
$bulkDiscountPercent = 0;
$tierName = '1-5';

if ($productGroupId) {
    try {
        $stmt = $pdo->prepare("
            SELECT tier_name, bulk_discount_percent, commission_percent
            FROM product_group_commissions
            WHERE product_group_id = ?
              AND active = 1
              AND ? >= quantity_min
              AND (quantity_max IS NULL OR ? <= quantity_max)
            ORDER BY quantity_min DESC
            LIMIT 1
        ");
        $stmt->execute([$productGroupId, $totalQuantity, $totalQuantity]);
        $tier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tier) {
            $agentCommissionRate = $tier['commission_percent'];
            $bulkDiscountPercent = $tier['bulk_discount_percent'];
            $tierName = $tier['tier_name'];
        }
    } catch (Exception $e) {
        // Fallback to default 20% if query fails
    }
}

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'] ?? '';
    $shipping_address = $_POST['shipping_address'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Generate order number
        $orderNumber = 'ORD-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Create order
        $stmt = $pdo->prepare('
            INSERT INTO orders (
                order_number, user_id, wholesale_account_id, sales_agent_id,
                subtotal, shipping, tax, total, commission_rate, status, payment_status,
                payment_method, shipping_method, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        // For admin orders, wholesale_account_id can be NULL
        $wholesaleAccountId = $isAdmin ? null : $wholesaleAccount['id'];
        
        $stmt->execute([
            $orderNumber,
            $uid,
            $wholesaleAccountId,
            $wholesaleAccount['sales_agent_id'] ?? null,
            $subtotal,
            $shippingCost,
            $taxAmount,
            $total,
            $agentCommissionRate,
            'pending',
            ($wholesaleAccount['has_coc'] ?? false) ? 'pending' : 'pending',
            $payment_method,
            $shippingCost > 0 ? 'Standard' : 'Free Shipping',
            $notes . ($isAdmin ? ' [ADMIN TEST ORDER]' : '')
        ]);
        
        $orderId = $pdo->lastInsertId();
        
        // Add order items
        $stmt = $pdo->prepare('
            INSERT INTO order_items (
                order_id, product_id, product_name, product_sku,
                quantity, unit_price, line_total
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        
        // Insert items using DB product id looked up by SKU to avoid hardcoded IDs
        $findProductIdStmt = $pdo->prepare('SELECT id FROM products WHERE sku = ?');
        foreach ($cartItems as $item) {
            $findProductIdStmt->execute([$item['sku']]);
            $dbProductId = $findProductIdStmt->fetchColumn();
            if ($dbProductId) {
                $stmt->execute([
                    $orderId,
                    $dbProductId,
                    $item['name'],
                    $item['sku'],
                    $item['quantity'],
                    $item['wholesale_price'],
                    $item['line_total']
                ]);
            }
        }
        
        // Compute and persist line-by-line commissions (agent, developer, AI)
        commission_compute_for_order($pdo, (int)$orderId);
        
        // Note: Commission calculations are completely hidden from customer interface
        
        $pdo->commit();
        
        // Generate accounting journal entry for revenue recognition
        try {
            require_once __DIR__ . '/../required/expense-automation.php';
            create_journal_entry_from_order($orderId, 'accrual');
        } catch (Exception $e) {
            // Continue even if journal entry fails - don't block order
            error_log("Journal entry creation failed for order {$orderId}: " . $e->getMessage());
        }

        // --- Printify Fulfillment ---
        // Auto-submit order to Printify if enabled and order contains Printify products
        try {
            $config = site_read_config();
            $autoSubmitOrders = $config['printify_auto_submit_orders'] ?? 1; // Default ON
            
            if ($autoSubmitOrders) {
                require_once __DIR__ . '/../required/printify-fulfillment.php';
                $fulfillment = new PrintifyFulfillment();
                
                // Check if order has Printify products
                if ($fulfillment->orderHasPrintifyProducts($orderId)) {
                    $result = $fulfillment->submitOrder($orderId, true);
                    
                    if ($result['success']) {
                        error_log("Printify: Order {$orderId} submitted successfully (Printify Order: {$result['printify_order_id']})");
                    }
                }
            }
        } catch (Exception $e) {
            // Log error but don't block checkout
            error_log("Printify fulfillment failed for order {$orderId}: " . $e->getMessage());
        }

        // --- Email Automation Triggers ---
        // Check if this is the user's first order
        $orderCountStmt = $pdo->prepare("SELECT COUNT(id) FROM orders WHERE user_id = ?");
        $orderCountStmt->execute([$uid]);
        $orderCount = $orderCountStmt->fetchColumn();

        if ($orderCount == 1) {
            trigger_email_notification('first_purchase', $uid);
        }

        // Always trigger a 'new invoice' email for every order
        trigger_email_notification('new_invoice', $uid);
        // --- End Email Automation Triggers ---
        
        // Clear cart
        $_SESSION['cart'] = [];
        
        // Redirect to success page
        flash_set('success', "Order $orderNumber submitted successfully!");
        redirect('/shop/order-success.php?order=' . $orderNumber);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error submitting order: " . $e->getMessage();
    }
}
?>

<style>
.checkout-header {
    background: linear-gradient(135deg, #065f46, #059669);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.checkout-container {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 32px;
}
.checkout-main {
    background: white;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.section-title {
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e5e7eb;
}
.form-section {
    margin-bottom: 32px;
}
.payment-method-card {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    cursor: pointer;
    transition: all 0.2s;
}
.payment-method-card:hover {
    border-color: #3b82f6;
}
.payment-method-card.selected {
    border-color: #059669;
    background: #f0fdf4;
}
.no-payment-warning {
    background: #fef3c7;
    border: 2px solid #f59e0b;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 24px;
}
.coc-notice {
    background: #dbeafe;
    border: 1px solid #3b82f6;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 24px;
}
.order-summary-sidebar {
    position: sticky;
    top: 20px;
}
.order-item {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #f3f4f6;
}
.agent-info {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 16px;
}
@media (max-width: 768px) {
    .checkout-container {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="checkout-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Checkout</h1>
        <?php if ($isAdmin): ?>
        <p style="font-size: 18px; opacity: 0.9;">
            Admin Test Order Mode
        </p>
        <?php else: ?>
        <p style="font-size: 18px; opacity: 0.9;">
            Complete your wholesale order
        </p>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <?php if (isset($error)): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <div class="checkout-container">
        <div class="checkout-main">
            <form method="post">
                <!-- Business Information -->
                <div class="form-section">
                    <h2 class="section-title">Business Information</h2>
                    <?php if ($isAdmin): ?>
                    <div class="card" style="background: #fef3c7; border: 2px solid #f59e0b;">
                        <p style="color: #92400e; margin-bottom: 12px;"><strong>⚠️ Admin Test Order Mode</strong></p>
                        <p style="color: #78350f; font-size: 14px;">This order will be marked as an admin test order. No commissions will be calculated, and this order can be used for testing purposes.</p>
                    </div>
                    <?php endif; ?>
                    <div class="card" style="background: #f9fafb;">
                        <p><strong>Business:</strong> <?php echo htmlspecialchars($wholesaleAccount['business_name']); ?></p>
                        <p><strong>Contact:</strong> <?php echo htmlspecialchars($wholesaleAccount['first_name'] . ' ' . $wholesaleAccount['last_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($wholesaleAccount['email']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($wholesaleAccount['phone'] ?: 'Not provided'); ?></p>
                    </div>
                    
                    <?php if (!$isAdmin && $wholesaleAccount['sales_agent_id']): ?>
                    <div class="agent-info">
                        <h4 style="margin-bottom: 8px;">Your Sales Representative</h4>
                        <p><strong><?php echo htmlspecialchars($wholesaleAccount['agent_first_name'] . ' ' . $wholesaleAccount['agent_last_name']); ?></strong></p>
                        <p style="font-size: 14px; color: #6b7280;">
                            Email: <?php echo htmlspecialchars($wholesaleAccount['agent_email']); ?>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="no-payment-warning">
                        <strong>⚠️ No Sales Agent Assigned</strong>
                        <p style="margin-top: 8px;">
                            <?php if ($isAdmin): ?>
                                Admin orders don't require sales agent assignment.
                            <?php else: ?>
                                A sales representative will be assigned to your account shortly.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Payment Method -->
                <div class="form-section">
                    <h2 class="section-title">Payment Method</h2>
                    
                    <?php if ($wholesaleAccount['has_coc']): ?>
                        <div class="coc-notice">
                            <strong>✅ Credit on File</strong>
                            <p style="margin-top: 8px;">Your payment will be processed using your authorized payment method.</p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (empty($paymentMethods)): ?>
                        <div class="no-payment-warning">
                            <strong>⚠️ No Payment Method on File</strong>
                            <p style="margin-top: 8px;">You'll need to provide payment information after order submission.</p>
                            <p style="margin-top: 8px;">Available options:</p>
                            <ul style="margin-left: 20px; margin-top: 8px;">
                                <li>Net 30 Terms (subject to approval)</li>
                                <li>Wire Transfer</li>
                                <li>Company Check</li>
                                <li>Credit Card (add in dashboard)</li>
                            </ul>
                        </div>
                        <input type="hidden" name="payment_method" value="net_terms">
                    <?php else: ?>
                        <?php foreach ($paymentMethods as $method): ?>
                        <label class="payment-method-card">
                            <input type="radio" name="payment_method" value="<?php echo $method['id']; ?>" 
                                   <?php echo $method['is_default'] ? 'checked' : ''; ?>>
                            <strong>
                                <?php if ($method['type'] === 'credit_card'): ?>
                                    <?php echo $method['card_brand']; ?> ending in <?php echo $method['last_four']; ?>
                                <?php elseif ($method['type'] === 'ach'): ?>
                                    Bank Account (<?php echo $method['bank_name']; ?>)
                                <?php else: ?>
                                    <?php echo ucwords(str_replace('_', ' ', $method['type'])); ?>
                                <?php endif; ?>
                            </strong>
                            <?php if ($method['is_default']): ?>
                                <span style="background: #059669; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 8px;">Default</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Shipping Address -->
                <div class="form-section">
                    <h2 class="section-title">Shipping Information</h2>
                    <label style="display: block; margin-bottom: 16px;">
                        <span style="display: block; margin-bottom: 4px; font-weight: 500;">Shipping Address</span>
                        <textarea name="shipping_address" rows="4" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px;" required><?php 
                            $address_parts = [];
                            if (!empty($wholesaleAccount['address_line1'])) $address_parts[] = $wholesaleAccount['address_line1'];
                            if (!empty($wholesaleAccount['address_line2'])) $address_parts[] = $wholesaleAccount['address_line2'];
                            $address = implode("\n", $address_parts);
                            if (!empty($wholesaleAccount['city']) || !empty($wholesaleAccount['state_region']) || !empty($wholesaleAccount['postal_code'])) {
                                $city_state_zip = trim($wholesaleAccount['city'] . ', ' . $wholesaleAccount['state_region'] . ' ' . $wholesaleAccount['postal_code']);
                                if ($city_state_zip !== ', ') $address .= "\n" . $city_state_zip;
                            }
                            if (!empty($wholesaleAccount['country'])) $address .= "\n" . $wholesaleAccount['country'];
                            echo htmlspecialchars($address ?: 'Please provide shipping address');
                        ?></textarea>
                    </label>
                    
                    <label style="display: block;">
                        <span style="display: block; margin-bottom: 4px; font-weight: 500;">Order Notes (Optional)</span>
                        <textarea name="notes" rows="3" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px;" 
                                  placeholder="Special delivery instructions, PO number, etc."></textarea>
                    </label>
                </div>
                
                <!-- Submit Button -->
                <button type="submit" class="btn" style="width: 100%; font-size: 18px; padding: 16px; background: var(--green); color: white;">
                    Submit Order - $<?php echo number_format($total, 2); ?>
                </button>
                
                <p style="margin-top: 16px; color: #6b7280; font-size: 14px; text-align: center;">
                    By submitting this order, you agree to our terms and conditions.
                </p>
            </form>
        </div>
        
        <!-- Order Summary Sidebar -->
        <div class="order-summary-sidebar">
            <div class="card">
                <h3 style="margin-bottom: 20px;">Order Summary</h3>
                
                <?php foreach ($cartItems as $item): ?>
                <div class="order-item">
                    <div>
                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                        <br><small style="color: #6b7280;">Qty: <?php echo $item['quantity']; ?> × $<?php echo number_format($item['wholesale_price'], 2); ?></small>
                    </div>
                    <div style="text-align: right;">
                        $<?php echo number_format($item['line_total'], 2); ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div style="border-top: 2px solid #e5e7eb; margin-top: 16px; padding-top: 16px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>Subtotal</span>
                        <span>$<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>Shipping</span>
                        <span><?php echo $shippingCost > 0 ? '$' . number_format($shippingCost, 2) : 'FREE'; ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>Tax (8%)</span>
                        <span>$<?php echo number_format($taxAmount, 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 20px; font-weight: 600; margin-top: 12px; padding-top: 12px; border-top: 2px solid #e5e7eb;">
                        <span>Total</span>
                        <span>$<?php echo number_format($total, 2); ?></span>
                    </div>
                </div>
                
                <?php
                // Calculate potential profit
                $avgDrinkPrice = 12.00;
                $totalServings = 0;
                foreach ($cartItems as $item) {
                    $servings = intval(preg_replace('/[^0-9]/', '', $item['servings'] ?? '0'));
                    $totalServings += $servings * $item['quantity'];
                }
                $potentialRevenue = $totalServings * $avgDrinkPrice;
                $potentialProfit = $potentialRevenue - $total;
                $roi = $total > 0 ? (($potentialProfit / $total) * 100) : 0;
                ?>
                
                <!-- Profit Potential Display -->
                <div style="background: linear-gradient(135deg, #fef3c7, #fed7aa); padding: 20px; border-radius: 12px; margin-top: 20px; border: 3px solid #f59e0b; text-align: center;">
                    <h4 style="color: #92400e; margin: 0 0 12px 0; font-size: 16px;">💰 Your Investment</h4>
                    <div style="background: white; padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                        <div style="font-size: 13px; color: #6b7280; margin-bottom: 6px;">The potential return on your investment is</div>
                        <div style="font-size: 32px; font-weight: bold; color: #059669; margin-bottom: 6px;">
                            $<?php echo number_format($potentialProfit, 2); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">
                            <strong style="color: #059669;"><?php echo number_format($roi, 0); ?>% ROI</strong> on your $<?php echo number_format($total, 2); ?> investment
                        </div>
                    </div>
                    <div style="font-size: 12px; color: #92400e; background: rgba(255,255,255,0.5); padding: 10px; border-radius: 6px;">
                        Serve <strong><?php echo number_format($totalServings); ?> drinks</strong> @ $12 each = <strong>$<?php echo number_format($potentialRevenue, 2); ?></strong>
                    </div>
                    <p style="margin: 10px 0 0 0; font-size: 11px; color: #78350f; font-style: italic;">
                        *Profit calculation based on 10mg servings at $12/drink
                    </p>
                </div>
                
                <!-- Bulk Discount Info -->
                <?php if ($bulkDiscount > 0): ?>
                <div style="background: #f0fdf4; padding: 12px; border-radius: 8px; margin-top: 16px; border-left: 4px solid #059669;">
                    <h4 style="color: #14532d; margin: 0 0 6px 0; font-size: 14px;">🎉 Volume Savings!</h4>
                    <p style="font-size: 13px; color: #374151; margin: 0;">
                        <strong><?php echo number_format($bulkDiscount, 0); ?>%</strong> discount on <?php echo $totalQuantity; ?> bottles
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
