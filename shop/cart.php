<?php
$SEO = [
    'title' => 'Your Cart — Product Wholesale',
    'description' => 'Review your wholesale Product order before checkout.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

start_session();

// Check if user is logged in
$uid = current_user_id();
if (!$uid) {
    flash_set('error', 'Please log in to access the cart.');
    redirect('/login.php');
    exit; // Ensure no further rendering
}

$pdo = db();

// Check if user is admin - admins get full access without account requirements
$isAdmin = user_has_role($uid, 'admin');

// Determine user's price tier
$priceTier = get_user_price_tier($uid); // 'msrp' or 'wholesale'
$isRetailCustomer = ($priceTier === 'msrp');
$isWholesaleCustomer = ($priceTier === 'wholesale');

// Ensure wholesale account exists for wholesale customers (or bypass for admins)
$wholesaleAccount = null;
if ($isAdmin) {
    // Admins bypass wholesale account requirement
    $wholesaleAccount = ['business_name' => 'Admin Account', 'id' => null];
    $isWholesaleCustomer = true; // Ensure admins use wholesale pricing
} elseif ($isWholesaleCustomer) {
    try {
        $wholesaleAccount = ensure_wholesale_account($uid);
        
        if (!$wholesaleAccount) {
            flash_set('error', 'Wholesale account required.');
            redirect('/shop/');
            exit;
        }
    } catch (Exception $e) {
        flash_set('error', 'Error loading account: ' . $e->getMessage());
        redirect('/shop/');
        exit;
    }
}

// Load active products from database with all pricing data
try {
    $rows = $pdo->query("
        SELECT 
            p.id, p.sku, p.name, p.description,
            p.wholesale_price, p.msrp,
            COALESCE(p.attribute_per_oz, 20) as attribute_per_oz,
            COALESCE(p.size_oz, 32) as size_oz,
            COALESCE(p.total_mg, p.attribute_per_oz * p.size_oz) as total_mg,
            COALESCE(p.servings_10mg, FLOOR((p.attribute_per_oz * p.size_oz) / 10)) as servings_10mg,
            p.cost_per_10mg_serving,
            p.profit_margin_percent,
            p.product_group_id,
            pg.name as group_name
        FROM products p
        LEFT JOIN product_groups pg ON pg.id = p.product_group_id
        WHERE p.active = 1 
        ORDER BY COALESCE(pg.display_order, 999) ASC, COALESCE(p.attribute_per_oz, 0) ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // If query fails, try simpler version
    $rows = $pdo->query("
        SELECT id, sku, name, description, wholesale_price, msrp
        FROM products 
        WHERE active = 1 
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$products = [];
foreach ($rows as $r) {
    $idSlug = strtolower(str_replace(['ELIX-','BLEND-'], ['elix-','blend-'], $r['sku']));
    $servings = isset($r['servings_10mg']) ? intval($r['servings_10mg']) : 64;
    
    $products[] = [
        'id' => $idSlug,
        'sku' => $r['sku'],
        'name' => $r['name'],
        'wholesale_price' => (float)($r['wholesale_price'] ?? 0),
        'retail_price' => (float)($r['msrp'] ?? 0),
        'attribute_content' => (isset($r['attribute_per_oz']) ? $r['attribute_per_oz'] : '20') . 'mg per oz',
        'volume' => (isset($r['size_oz']) ? $r['size_oz'] : '32') . 'oz',
        'servings' => $servings,
        'servings_display' => $servings . ' × 10mg',
        'product_group_id' => $r['product_group_id'] ?? null,
        'group_name' => $r['group_name'] ?? null,
        'profit_margin_percent' => $r['profit_margin_percent'] ?? 60
    ];
}

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    
    switch($action) {
        case 'add':
            $product_id = $_POST['product_id'] ?? '';
            $quantity = intval($_POST['quantity'] ?? 1);
            
            // Find product in catalog
            $product = null;
            foreach ($products as $p) {
                if ($p['id'] === $product_id) {
                    $product = $p;
                    break;
                }
            }
            
            if ($product) {
                $found = false;
                foreach ($_SESSION['cart'] as &$item) {
                    if ($item['product_id'] === $product_id) {
                        $item['quantity'] += $quantity;
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    $_SESSION['cart'][] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity
                    ];
                }
                
                flash_set('success', 'Product added to cart!');
            }
            redirect('/shop/');
            break;
            
        case 'update':
            $product_id = $_POST['product_id'] ?? '';
            $quantity = intval($_POST['quantity'] ?? 0);
            
            foreach ($_SESSION['cart'] as &$item) {
                if ($item['product_id'] === $product_id) {
                    if ($quantity > 0) {
                        $item['quantity'] = $quantity;
                    } else {
                        // Remove if quantity is 0
                        $_SESSION['cart'] = array_filter($_SESSION['cart'], 
                            fn($i) => $i['product_id'] !== $product_id);
                        $_SESSION['cart'] = array_values($_SESSION['cart']);
                    }
                    break;
                }
            }
            break;
            
        case 'remove':
            $product_id = $_POST['product_id'] ?? '';
            $_SESSION['cart'] = array_filter($_SESSION['cart'], 
                fn($item) => $item['product_id'] !== $product_id);
            $_SESSION['cart'] = array_values($_SESSION['cart']);
            break;
            
        case 'clear':
            $_SESSION['cart'] = [];
            break;
            
        case 'reorder':
            $order_id = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            if ($order_id > 0) {
                try {
                    // Get order items from database
                    $stmt = $pdo->prepare("
                        SELECT oi.*, p.id as product_db_id
                        FROM order_items oi
                        JOIN orders o ON o.id = oi.order_id
                        LEFT JOIN products p ON p.id = oi.product_id
                        WHERE oi.order_id = ? AND o.user_id = ?
                    ");
                    $stmt->execute([$order_id, $uid]);
                    $orderItems = $stmt->fetchAll();
                    
                    // Map using current catalog SKUs to cart ids
                    $skuToCartId = [];
                    foreach ($products as $p) { $skuToCartId[$p['sku']] = $p['id']; }

                    foreach ($orderItems as $item) {
                        $cart_product_id = $skuToCartId[$item['product_sku']] ?? null;
                        if ($cart_product_id) {
                            // Check if item already in cart
                            $found = false;
                            foreach ($_SESSION['cart'] as &$cartItem) {
                                if ($cartItem['product_id'] === $cart_product_id) {
                                    $cartItem['quantity'] += $item['quantity'];
                                    $found = true;
                                    break;
                                }
                            }
                            
                            if (!$found) {
                                $_SESSION['cart'][] = [
                                    'product_id' => $cart_product_id,
                                    'quantity' => $item['quantity']
                                ];
                            }
                        }
                    }
                    
                    flash_set('success', 'Items from previous order added to cart!');
                } catch (Exception $e) {
                    flash_set('error', 'Error reordering items: ' . $e->getMessage());
                }
            }
            break;
    }
}

// Calculate totals with bulk pricing from product_group_commissions
$cart = $_SESSION['cart'] ?? [];
$subtotal = 0;
$cartItems = [];
$totalQuantity = array_sum(array_column($cart, 'quantity'));

// Get the product group ID from first item in cart (assumes all products from same group)
$productGroupId = null;
foreach ($cart as $cartItem) {
    foreach ($products as $p) {
        if ($p['id'] === $cartItem['product_id']) {
            $productGroupId = $p['product_group_id'];
            break 2;
        }
    }
}

// Determine bulk discount and commission rate from product_group_commissions (wholesale only)
$bulkDiscount = 0;
$commissionRate = 20; // Default
$tierName = '1-5';

if ($isWholesaleCustomer && $productGroupId) {
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
            $bulkDiscount = $tier['bulk_discount_percent'];
            $commissionRate = $tier['commission_percent'];
            $tierName = $tier['tier_name'];
        }
    } catch (Exception $e) {
        // Fallback to default if query fails
    }
}

foreach ($cart as $cartItem) {
    foreach ($products as $product) {
        if ($product['id'] === $cartItem['product_id']) {
            $item = $product;
            $item['quantity'] = $cartItem['quantity'];
            
            if ($isRetailCustomer) {
                // Retail customer: Use MSRP, no bulk discounts
                $item['unit_price'] = $product['retail_price'];
                $item['original_price'] = $product['retail_price'];
                $item['discount_percent'] = 0;
                $item['commission_percent'] = 0;
                $item['tier_name'] = 'Retail';
                $item['savings_per_unit'] = 0;
                $item['line_total'] = $product['retail_price'] * $cartItem['quantity'];
            } else {
                // Wholesale customer: Use wholesale price with bulk discounts
                $discountedPrice = $product['wholesale_price'] * (1 - $bulkDiscount / 100);
                $item['unit_price'] = $discountedPrice;
                $item['original_price'] = $product['wholesale_price'];
                $item['discount_percent'] = $bulkDiscount;
                $item['commission_percent'] = $commissionRate;
                $item['tier_name'] = $tierName;
                $item['savings_per_unit'] = $product['wholesale_price'] - $discountedPrice;
                $item['line_total'] = $discountedPrice * $cartItem['quantity'];
            }
            
            $cartItems[] = $item;
            $subtotal += $item['line_total'];
            break;
        }
    }
}

// Calculate shipping and tax
$freeShippingThreshold = 500;
$shippingCost = $subtotal >= $freeShippingThreshold ? 0 : 25;
$taxRate = 0.08; // 8% tax
$taxAmount = $subtotal * $taxRate;
$total = $subtotal + $shippingCost + $taxAmount;
?>

<style>
.cart-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.cart-container {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 32px;
}
.cart-items {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.cart-item {
    display: grid;
    grid-template-columns: 1fr auto auto auto;
    gap: 20px;
    padding: 20px 0;
    border-bottom: 1px solid #e5e7eb;
    align-items: center;
}
.cart-item:last-child {
    border-bottom: none;
}
.item-details h3 {
    font-size: 18px;
    margin-bottom: 4px;
    color: #111827;
}
.item-meta {
    color: #6b7280;
    font-size: 13px;
    margin-top: 4px;
}
.quantity-control {
    display: flex;
    align-items: center;
    gap: 8px;
}
.quantity-btn {
    width: 32px;
    height: 32px;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}
.quantity-btn:hover {
    background: #f3f4f6;
}
.quantity-input {
    width: 60px;
    padding: 6px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    text-align: center;
}
.price-column {
    text-align: right;
    min-width: 100px;
}
.wholesale-price {
    font-size: 18px;
    font-weight: 600;
    color: #1e40af;
}
.retail-compare {
    font-size: 12px;
    color: #6b7280;
    text-decoration: line-through;
}
.order-summary {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    position: sticky;
    top: 20px;
}
.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #f3f4f6;
}
.summary-row:last-child {
    border-bottom: none;
    padding-top: 16px;
    font-size: 20px;
    font-weight: 600;
}
.shipping-notice {
    background: #fef3c7;
    padding: 12px;
    border-radius: 8px;
    margin: 16px 0;
    font-size: 14px;
}
.shipping-progress {
    margin-top: 8px;
    background: #e5e7eb;
    height: 8px;
    border-radius: 4px;
    overflow: hidden;
}
.shipping-progress-fill {
    height: 100%;
    background: linear-gradient(to right, #fbbf24, #f59e0b);
    transition: width 0.3s;
}
.empty-cart {
    text-align: center;
    padding: 60px;
}
.remove-btn {
    color: #dc2626;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    text-decoration: underline;
}
@media (max-width: 768px) {
    .cart-container {
        grid-template-columns: 1fr;
    }
    .cart-item {
        grid-template-columns: 1fr;
        gap: 12px;
    }
}
</style>

<div class="cart-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Shopping Cart</h1>
        <?php if ($isAdmin): ?>
        <p style="font-size: 18px; opacity: 0.9;">
            Admin Account: Testing Mode
        </p>
        <?php elseif ($isWholesaleCustomer && $wholesaleAccount): ?>
        <p style="font-size: 18px; opacity: 0.9;">
            Wholesale Account: <?php echo htmlspecialchars($wholesaleAccount['business_name']); ?>
        </p>
        <?php elseif ($isWholesaleCustomer): ?>
        <p style="font-size: 18px; opacity: 0.9;">
            Wholesale Account: Admin Account
        </p>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <?php if (empty($cartItems)): ?>
    <div class="card empty-cart">
        <h2 style="color: #6b7280; margin-bottom: 16px;">Your cart is empty</h2>
        <p style="color: #9ca3af; margin-bottom: 24px;">Browse our premium Products and add products to your cart.</p>
        <a href="<?php echo path_with_base('/shop/'); ?>" class="btn" style="background: var(--green); color: white;">Continue Shopping</a>
    </div>
    <?php else: ?>
    <div class="cart-container">
        <!-- Cart Items -->
        <div class="cart-items">
            <h2 style="margin-bottom: 24px;">Cart Items (<?php echo count($cartItems); ?>)</h2>
            
            <?php foreach ($cartItems as $item): ?>
            <div class="cart-item">
                <div class="item-details">
                    <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                    <div class="item-meta">
                        SKU: <?php echo $item['sku']; ?> • 
                        <?php echo $item['volume']; ?> • 
                        attribute: <?php echo $item['attribute_content']; ?>
                    </div>
                </div>
                
                <div class="quantity-control">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                        <input type="hidden" name="quantity" value="<?php echo $item['quantity'] - 1; ?>">
                        <button type="submit" class="quantity-btn" <?php echo $item['quantity'] <= 1 ? 'disabled' : ''; ?>>−</button>
                    </form>
                    
                    <input type="text" class="quantity-input" value="<?php echo $item['quantity']; ?>" readonly>
                    
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                        <input type="hidden" name="quantity" value="<?php echo $item['quantity'] + 1; ?>">
                        <button type="submit" class="quantity-btn">+</button>
                    </form>
                </div>
                
                <div class="price-column">
                    <?php if ($item['discount_percent'] > 0): ?>
                        <div style="text-decoration: line-through; color: #9ca3af; font-size: 14px;">
                            $<?php echo number_format($item['original_price'] * $item['quantity'], 2); ?>
                        </div>
                        <div class="wholesale-price">$<?php echo number_format($item['line_total'], 2); ?></div>
                        <div style="color: #dc2626; font-size: 12px; font-weight: 600;">
                            <?php echo $item['discount_percent']; ?>% bulk discount
                        </div>
                    <?php else: ?>
                        <div class="wholesale-price">$<?php echo number_format($item['line_total'], 2); ?></div>
                    <?php endif; ?>
                    <div class="retail-compare">MSRP: $<?php echo number_format($item['retail_price'] * $item['quantity'], 2); ?></div>
                </div>
                
                <div>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                        <button type="submit" class="remove-btn">Remove</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid #e5e7eb;">
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn" style="background: #fee2e2; color: #dc2626;">
                        Clear Cart
                    </button>
                </form>
                <a href="<?php echo path_with_base('/shop/'); ?>" class="btn" style="margin-left: 12px;">
                    Continue Shopping
                </a>
            </div>
        </div>
        
        <!-- Order Summary -->
        <div class="order-summary">
            <h2 style="margin-bottom: 24px;">Order Summary</h2>
            
            <?php if ($bulkDiscount > 0): ?>
            <div style="background: #f0fdf4; padding: 16px; border-radius: 8px; margin-bottom: 16px; border-left: 4px solid #059669;">
                <h3 style="color: #14532d; margin: 0 0 8px 0;">🎉 Volume Discount Applied!</h3>
                <p style="margin: 0 0 8px 0; color: #374151;">
                    <strong><?php echo number_format($bulkDiscount, 0); ?>% discount</strong> for ordering <?php echo $totalQuantity; ?> bottles
                </p>
                <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0 0;">
                    You saved: $<?php echo number_format(array_sum(array_map(fn($item) => $item['savings_per_unit'] * $item['quantity'], $cartItems)), 2); ?>
                </p>
            </div>
            <?php endif; ?>
            
            <?php if ($isWholesaleCustomer): ?>
            <?php
            // Calculate potential profit (assuming $12 per 10mg drink average) - Wholesale only
            $avgDrinkPrice = 12.00;
            $totalServings = 0;
            $totalMaterialCost = 0;
            
            foreach ($cartItems as $item) {
                // Get servings from product data - it's now a number, not a string
                $servings = isset($item['servings']) && is_numeric($item['servings']) ? intval($item['servings']) : 64;
                
                $totalServings += $servings * $item['quantity'];
                $totalMaterialCost += $item['unit_price'] * $item['quantity'];
            }
            
            $potentialRevenue = $totalServings * $avgDrinkPrice;
            $potentialProfit = $potentialRevenue - $total; // After all costs including tax/shipping
            $roi = $total > 0 ? (($potentialProfit / $total) * 100) : 0;
            ?>
            
            <?php if ($potentialProfit > 0): ?>
            <div style="background: linear-gradient(135deg, #fef3c7, #fed7aa); padding: 20px; border-radius: 12px; margin-bottom: 20px; border: 3px solid #f59e0b;">
                <div style="text-align: center;">
                    <h3 style="color: #92400e; margin: 0 0 12px 0; font-size: 18px;">💰 Your Profit Potential</h3>
                    <div style="background: white; padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                        <div style="font-size: 14px; color: #6b7280; margin-bottom: 8px;">Potential Return on Investment</div>
                        <div style="font-size: 36px; font-weight: bold; color: #059669; margin-bottom: 4px;">
                            $<?php echo number_format($potentialProfit, 2); ?>
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">
                            That's a <strong style="color: #059669;"><?php echo number_format($roi, 0); ?>% return</strong> on your $<?php echo number_format($total, 2); ?> investment!
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px;">
                        <div style="background: rgba(255,255,255,0.5); padding: 10px; border-radius: 6px;">
                            <div style="color: #6b7280; font-size: 11px; text-transform: uppercase;">Total Drinks</div>
                            <div style="font-weight: bold; color: #1f2937;"><?php echo number_format($totalServings); ?> drinks</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.5); padding: 10px; border-radius: 6px;">
                            <div style="color: #6b7280; font-size: 11px; text-transform: uppercase;">Revenue @ $12/drink</div>
                            <div style="font-weight: bold; color: #1f2937;">$<?php echo number_format($potentialRevenue, 2); ?></div>
                        </div>
                    </div>
                    <p style="margin: 12px 0 0 0; font-size: 12px; color: #92400e; font-style: italic;">
                        Based on serving <?php echo number_format($totalServings); ?> drinks at $12 each with 10mg attribute per drink
                    </p>
                </div>
                </div>
            <?php endif; ?>
            <?php endif; /* End wholesale profit calculator */ ?>
            
            <div class="summary-row">
                <span>Subtotal</span>
                <span>$<?php echo number_format($subtotal, 2); ?></span>
            </div>
            
            <div class="summary-row">
                <span>Shipping</span>
                <span><?php echo $shippingCost > 0 ? '$' . number_format($shippingCost, 2) : 'FREE'; ?></span>
            </div>
            
            <div class="summary-row">
                <span>Tax (<?php echo ($taxRate * 100); ?>%)</span>
                <span>$<?php echo number_format($taxAmount, 2); ?></span>
            </div>
            
            <?php if ($isWholesaleCustomer): ?>
            <div class="summary-row" style="color: #059669;">
                <span>Your Savings vs MSRP</span>
                <span>$<?php echo number_format(array_sum(array_map(fn($i) => ($i['retail_price'] - $i['unit_price']) * $i['quantity'], $cartItems)), 2); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="summary-row">
                <span>Total</span>
                <span>$<?php echo number_format($total, 2); ?></span>
            </div>
            
            <?php if ($subtotal < $freeShippingThreshold): ?>
            <div class="shipping-notice">
                <strong>Free shipping on orders over $<?php echo $freeShippingThreshold; ?></strong>
                <div style="margin-top: 4px; font-size: 12px;">
                    Add $<?php echo number_format($freeShippingThreshold - $subtotal, 2); ?> more for free shipping
                </div>
                <div class="shipping-progress">
                    <div class="shipping-progress-fill" style="width: <?php echo min(100, ($subtotal / $freeShippingThreshold) * 100); ?>%"></div>
                </div>
            </div>
            <?php else: ?>
            <div class="shipping-notice" style="background: #dcfce7;">
                <strong style="color: #14532d;">✓ You qualify for FREE shipping!</strong>
            </div>
            <?php endif; ?>
            
            <a href="<?php echo path_with_base('/shop/checkout.php'); ?>" class="btn" style="width: 100%; margin-top: 24px; background: var(--green); color: white; font-size: 18px; text-decoration: none; display: block; text-align: center;">
                Proceed to Checkout
            </a>

            <?php 
            // If current user is a sales agent, provide a shortcut to create invoice with current cart
            $isSalesAgent = false;
            try {
                $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
                $stmt->execute([$uid]);
                $isSalesAgent = (bool)$stmt->fetch();
            } catch (Throwable $e) { $isSalesAgent = false; }
            if ($isSalesAgent): ?>
            <a href="/onboarding/sales-agent/invoice.php?import_cart=1" class="btn" style="width: 100%; margin-top: 12px; background: #0369a1; color: white; font-size: 16px; text-decoration: none; display: block; text-align: center;">
                🧾 Create Invoice (Agent)
            </a>
            <?php endif; ?>
            
            <div style="margin-top: 16px; padding: 12px; background: #f0fdf4; border-radius: 8px;">
                <h4 style="color: #14532d; margin-bottom: 8px;">Wholesale Benefits:</h4>
                <ul style="font-size: 13px; color: #374151; margin: 0; padding-left: 20px;">
                    <li>Net 30 payment terms available</li>
                    <li>Volume discounts on 10+ units</li>
                    <li>Priority shipping & support</li>
                    <li>Marketing materials included</li>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>