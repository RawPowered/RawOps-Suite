<?php
$SEO = [
    'title' => 'Customer Dashboard — Product',
    'description' => 'Manage your wholesale account, view orders, and track shipments.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_login_or_redirect(path_with_base('/login.php'));

$uid = current_user_id();
$pdo = db();

// Get user and wholesale account info
$user = null;
$wholesaleAccount = null;
$orders = [];
$loyaltyPoints = 0;
$betaSampleRequest = null;
$isRetailCustomer = false;

try {
    // Get user info
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    
    // Check if user is retail customer
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ? AND r.name = "retail_customer"
    ');
    $stmt->execute([$uid]);
    $isRetailCustomer = (bool)$stmt->fetchColumn();
    
    // Get beta sample request status if exists
    if ($isRetailCustomer) {
        $stmt = $pdo->prepare('
            SELECT sr.*, p.name as product_name
            FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            WHERE sr.agent_id = ? AND sr.is_beta_sample = 1
            ORDER BY sr.requested_at DESC
            LIMIT 1
        ');
        $stmt->execute([$uid]);
        $betaSampleRequest = $stmt->fetch();
    }
    
    // Get wholesale account
    $stmt = $pdo->prepare('SELECT * FROM wholesale_accounts WHERE user_id = ?');
    $stmt->execute([$uid]);
    $wholesaleAccount = $stmt->fetch();
    
    if ($wholesaleAccount) {
        // Get recent orders
        $stmt = $pdo->prepare('
            SELECT o.*, 
                   COUNT(oi.id) as item_count,
                   SUM(oi.quantity) as total_items
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            WHERE o.wholesale_account_id = ?
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT 10
        ');
        $stmt->execute([$wholesaleAccount['id']]);
        $orders = $stmt->fetchAll();
        
        // Calculate loyalty points (simple: $1 = 1 point)
        $stmt = $pdo->prepare('SELECT SUM(total) FROM orders WHERE wholesale_account_id = ? AND status = "fulfilled"');
        $stmt->execute([$wholesaleAccount['id']]);
        $loyaltyPoints = intval($stmt->fetchColumn());
    }
} catch (Throwable $e) {
    // Handle error
}

// Get current tab
$tab = $_GET['tab'] ?? 'overview';
?>

<style>
.dashboard-header {
    background: linear-gradient(135deg, #1e3a8a, #1e40af);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.tab-nav {
    display: flex;
    gap: 8px;
    background: white;
    padding: 8px;
    border-radius: 12px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.tab-btn {
    flex: 1;
    padding: 12px 20px;
    background: transparent;
    border: none;
    border-radius: 8px;
    color: #6b7280;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    text-align: center;
}
.tab-btn:hover {
    background: #f3f4f6;
}
.tab-btn.active {
    background: #1e40af;
    color: white;
}
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.stat-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.stat-value {
    font-size: 32px;
    font-weight: bold;
    color: #1e40af;
    margin: 8px 0;
}
.stat-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.order-table {
    width: 100%;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.order-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
}
.order-table td {
    padding: 16px 12px;
    border-bottom: 1px solid #f3f4f6;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-draft { background: #f3f4f6; color: #6b7280; }
.status-submitted { background: #fef3c7; color: #92400e; }
.status-paid { background: #dbeafe; color: #1e40af; }
.status-fulfilled { background: #dcfce7; color: #14532d; }
.status-cancelled { background: #fee2e2; color: #991b1b; }
.loyalty-card {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: white;
    padding: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.loyalty-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
}
.quick-action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-top: 24px;
}
.quick-action {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
}
.quick-action:hover {
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
}
.quick-action-icon {
    font-size: 32px;
    margin-bottom: 8px;
}
</style>

<div class="dashboard-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">
            Welcome back, <?php echo htmlspecialchars($user['first_name'] ?? 'Customer'); ?>!
        </h1>
        <p style="font-size: 18px; opacity: 0.9;">
            <?php if($wholesaleAccount && $wholesaleAccount['approved']): ?>
                Wholesale Account: <?php echo htmlspecialchars($wholesaleAccount['business_name']); ?>
            <?php else: ?>
                Manage your account and orders
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="container">
    <!-- Beta Sample Success Message -->
    <?php if (!empty($_GET['beta_sample_success'])): ?>
    <div class="card" style="background:#ecfdf5;border:2px solid #10b981;padding:24px;margin-bottom:24px;">
        <div style="display:flex;align-items:center;gap:16px;">
            <div style="font-size:40px;">✅</div>
            <div>
                <h3 style="margin:0 0 8px;color:#065f46;font-size:20px;font-weight:800;">Beta Sample Request Submitted!</h3>
                <p style="margin:0;color:#047857;font-size:15px;">
                    Your free beta sample request has been submitted successfully. You'll receive an email confirmation shortly, 
                    and we'll notify you when your sample ships (typically within 2-3 business days).
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Tab Navigation -->
    <div class="tab-nav">
        <a href="?tab=overview" class="tab-btn <?php echo $tab === 'overview' ? 'active' : ''; ?>">
            📊 Overview
        </a>
        <a href="?tab=orders" class="tab-btn <?php echo $tab === 'orders' ? 'active' : ''; ?>">
            📦 Orders
        </a>
        <a href="?tab=products" class="tab-btn <?php echo $tab === 'products' ? 'active' : ''; ?>">
            🛍️ Quick Order
        </a>
        <a href="?tab=loyalty" class="tab-btn <?php echo $tab === 'loyalty' ? 'active' : ''; ?>">
            ⭐ Loyalty
        </a>
        <a href="?tab=account" class="tab-btn <?php echo $tab === 'account' ? 'active' : ''; ?>">
            👤 Account
        </a>
        <a href="?tab=payment" class="tab-btn <?php echo $tab === 'payment' ? 'active' : ''; ?>">
            💳 Payment
        </a>
    </div>

    <?php if($tab === 'overview'): ?>
    <!-- Overview Tab -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label">Account Status</div>
            <div class="stat-value">
                <?php if($wholesaleAccount && $wholesaleAccount['approved']): ?>
                    <span style="color: #059669;">Approved</span>
                <?php elseif($wholesaleAccount): ?>
                    <span style="color: #f59e0b;">Pending</span>
                <?php else: ?>
                    <span style="color: #6b7280;">No Account</span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Total Orders</div>
            <div class="stat-value"><?php echo count($orders); ?></div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Loyalty Points</div>
            <div class="stat-value"><?php echo number_format($loyaltyPoints); ?></div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Member Since</div>
            <div class="stat-value" style="font-size: 20px;">
                <?php echo date('M Y', strtotime($user['created_at'] ?? 'now')); ?>
            </div>
        </div>
    </div>
    
    <div class="quick-action-grid">
        <!-- Beta Sample Request Card (for retail customers only) -->
        <?php if ($isRetailCustomer): ?>
            <?php if (!$betaSampleRequest): ?>
                <!-- User can request beta sample -->
                <a href="<?php echo outbound_path('/beta-sample-request.php'); ?>" class="quick-action" style="background: linear-gradient(135deg, #fef2f2, #fee2e2); border: 2px solid #dc2626;">
                    <div class="quick-action-icon">🧪</div>
                    <h3 style="color: #991b1b;">FREE Beta Sample</h3>
                    <p style="color: #991b1b; font-size: 14px;">Limited time offer!</p>
                </a>
            <?php else: ?>
                <!-- User already requested -->
                <div class="quick-action" style="background: #f0f9ff; border: 2px solid #3b82f6; cursor: default;">
                    <div class="quick-action-icon">✅</div>
                    <h3 style="color: #1e40af;">Beta Sample: <?php echo ucfirst($betaSampleRequest['status']); ?></h3>
                    <p style="color: #1e40af; font-size: 14px;">
                        <?php 
                            if ($betaSampleRequest['status'] === 'shipped') {
                                echo 'Shipped ' . date('M j', strtotime($betaSampleRequest['shipped_at']));
                            } elseif ($betaSampleRequest['status'] === 'delivered') {
                                echo 'Delivered!';
                            } elseif ($betaSampleRequest['status'] === 'approved') {
                                echo 'Shipping soon';
                            } else {
                                echo 'Processing...';
                            }
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <a href="<?php echo outbound_path('/shop/'); ?>" class="quick-action">
            <div class="quick-action-icon">🛒</div>
            <h3>Shop Products</h3>
            <p style="color: #6b7280; font-size: 14px;">Browse our catalog</p>
        </a>
        
        <a href="?tab=orders" class="quick-action">
            <div class="quick-action-icon">📋</div>
            <h3>View Orders</h3>
            <p style="color: #6b7280; font-size: 14px;">Track shipments</p>
        </a>
        
        <a href="?tab=loyalty" class="quick-action">
            <div class="quick-action-icon">🎁</div>
            <h3>Rewards</h3>
            <p style="color: #6b7280; font-size: 14px;">Redeem points</p>
        </a>
        
        <a href="<?php echo outbound_path('/support/'); ?>" class="quick-action">
            <div class="quick-action-icon">💬</div>
            <h3>Support</h3>
            <p style="color: #6b7280; font-size: 14px;">Get help</p>
        </a>
        
        <a href="<?php echo outbound_path('/customer/profit-calculator.php'); ?>" class="quick-action" style="background: linear-gradient(135deg, #fef3c7, #fed7aa); border: 2px solid #f59e0b;">
            <div class="quick-action-icon">💰</div>
            <h3 style="color: #92400e;">Profit Calculator</h3>
            <p style="color: #92400e; font-size: 14px;">See your earnings potential</p>
        </a>
    </div>
    
    <?php elseif($tab === 'orders'): ?>
    <!-- Orders Tab -->
    <div class="card">
        <h2 style="margin-bottom: 24px;">Order History</h2>
        
        <?php if(empty($orders)): ?>
            <p style="text-align: center; color: #6b7280; padding: 40px;">No orders yet. Start shopping to see your orders here!</p>
        <?php else: ?>
        <table class="order-table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Date</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($orders as $order): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($order['order_number'] ?: '#' . str_pad($order['id'], 6, '0', STR_PAD_LEFT)); ?></strong>
                        <?php if ($order['tracking_number']): ?>
                            <br><small style="color: #3b82f6; font-size: 11px;">📦 Tracking Available</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo date('M d, Y', strtotime($order['created_at'])); ?>
                        <br><small style="color: #6b7280;"><?php echo date('g:i A', strtotime($order['created_at'])); ?></small>
                    </td>
                    <td>
                        <?php echo $order['total_items'] ?? 0; ?> items
                        <br><small style="color: #6b7280;">
                            <?php echo htmlspecialchars($order['payment_method'] ?: 'Net Terms'); ?>
                        </small>
                    </td>
                    <td>
                        <strong>$<?php echo number_format($order['total'], 2); ?></strong>
                        <br><small style="color: <?php echo $order['payment_status'] === 'paid' ? '#059669' : '#f59e0b'; ?>;">
                            <?php echo ucfirst($order['payment_status']); ?>
                        </small>
                    </td>
                    <td>
                        <span class="status-badge status-<?php echo $order['status']; ?>">
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (in_array($order['status'], ['pending', 'submitted'])): ?>
                            <a href="<?php echo outbound_path('/customer/order-details.php?id=' . intval($order['id']) . '&pay=1'); ?>" 
                               style="display: inline-block; padding: 8px 16px; background: #059669; color: white; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600; margin-right: 8px;">
                                💳 Pay Now
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo outbound_path('/customer/order-details.php?id=' . intval($order['id'])); ?>" class="btn" style="padding: 6px 12px; font-size: 14px; text-decoration: none;">
                            View Details
                        </a>
                        <?php if ($order['status'] === 'fulfilled'): ?>
                            <br><a href="<?php echo outbound_path('/shop/cart.php?action=reorder&order_id=' . intval($order['id'])); ?>" style="font-size: 11px; color: #059669; text-decoration: none;">🔄 Reorder</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <?php elseif($tab === 'products'): ?>
    <!-- Quick Order Tab -->
    <div class="card">
        <h2 style="margin-bottom: 24px;">Quick Reorder</h2>
        <p style="color: #6b7280; margin-bottom: 24px;">Quickly reorder official blends at current pricing</p>

        <?php
        // Load active products dynamically to avoid stale hardcoding
        try {
            $rows = $pdo->query("SELECT sku, name, wholesale_price, msrp FROM products WHERE active = 1 ORDER BY name")->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
        ?>

        <div class="product-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px;">
            <?php foreach ($rows as $p): 
                $idSlug = strtolower(str_replace(['ELIX-','BLEND-'], ['elix-','blend-'], $p['sku']));
            ?>
            <div class="product-card" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
                <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">SKU: <?php echo htmlspecialchars($p['sku']); ?></div>
                <h4 style="margin:0 0 8px 0;"><?php echo htmlspecialchars($p['name']); ?></h4>
                <p style="color: #059669; font-size: 20px; font-weight: bold; margin:0 0 12px 0;">
                    $<?php echo number_format((float)$p['wholesale_price'], 2); ?>
                    <span style="font-size:12px; color:#9ca3af; text-decoration: line-through; margin-left:8px;">$<?php echo number_format((float)$p['msrp'], 2); ?></span>
                </p>
                <form method="post" action="/shop/cart.php">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?php echo $idSlug; ?>">
                    <div style="display:flex; gap:8px; align-items:center;">
                        <input type="number" name="quantity" value="1" min="1" max="99" style="width:72px; padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
                        <button type="submit" class="btn" style="flex:1;">Quick Add</button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <div style="color:#9ca3af;">No products available.</div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php elseif($tab === 'loyalty'): ?>
    <!-- Loyalty Tab -->
    <div class="loyalty-card">
        <h2 style="color: white; margin-bottom: 8px;">Loyalty Rewards Program</h2>
        <p style="opacity: 0.9; margin-bottom: 24px;">Earn points on every purchase and unlock exclusive rewards</p>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 32px;">
            <div>
                <h3 style="color: white; font-size: 48px; margin: 0;">
                    <?php echo number_format($loyaltyPoints); ?>
                </h3>
                <p style="opacity: 0.9;">Total Points Earned</p>
            </div>
            <div>
                <h3 style="color: white; font-size: 24px; margin: 0;">
                    <?php 
                    $tier = 'Bronze';
                    if($loyaltyPoints >= 10000) $tier = 'Platinum';
                    elseif($loyaltyPoints >= 5000) $tier = 'Gold';
                    elseif($loyaltyPoints >= 1000) $tier = 'Silver';
                    echo $tier;
                    ?>
                </h3>
                <p style="opacity: 0.9;">Current Tier</p>
            </div>
        </div>
    </div>
    
    <div class="card" style="margin-top: 24px;">
        <h3>Available Rewards</h3>
        
        <div style="display: grid; gap: 16px; margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; background: #f9fafb; border-radius: 8px;">
                <div>
                    <h4 style="margin: 0;">$25 Store Credit</h4>
                    <p style="color: #6b7280; margin: 4px 0 0;">Redeem for store credit</p>
                </div>
                <button class="btn" <?php echo $loyaltyPoints >= 500 ? '' : 'disabled'; ?>>
                    500 pts
                </button>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; background: #f9fafb; border-radius: 8px;">
                <div>
                    <h4 style="margin: 0;">Free Shipping</h4>
                    <p style="color: #6b7280; margin: 4px 0 0;">On your next order</p>
                </div>
                <button class="btn" <?php echo $loyaltyPoints >= 250 ? '' : 'disabled'; ?>>
                    250 pts
                </button>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; background: #f9fafb; border-radius: 8px;">
                <div>
                    <h4 style="margin: 0;">10% Off Coupon</h4>
                    <p style="color: #6b7280; margin: 4px 0 0;">Valid for 30 days</p>
                </div>
                <button class="btn" <?php echo $loyaltyPoints >= 100 ? '' : 'disabled'; ?>>
                    100 pts
                </button>
            </div>
        </div>
    </div>
    
    <?php elseif($tab === 'account'): ?>
    <!-- Account Tab -->
    <div class="grid two" style="gap: 24px;">
        <div class="card">
            <h3>Account Information</h3>
            
            <div style="margin-top: 20px; space-y: 16px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; color: #6b7280; font-size: 14px; margin-bottom: 4px;">Name</label>
                    <p style="font-size: 18px;"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; color: #6b7280; font-size: 14px; margin-bottom: 4px;">Email</label>
                    <p style="font-size: 18px;"><?php echo htmlspecialchars($user['email']); ?></p>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; color: #6b7280; font-size: 14px; margin-bottom: 4px;">Phone</label>
                    <p style="font-size: 18px;"><?php echo htmlspecialchars($user['phone'] ?? 'Not provided'); ?></p>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; color: #6b7280; font-size: 14px; margin-bottom: 4px;">Member Since</label>
                    <p style="font-size: 18px;"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                </div>
                
                <button class="btn secondary" style="width: 100%;">Edit Profile</button>
            </div>
        </div>
        
        <?php if($wholesaleAccount): ?>
        <div class="card">
            <h3>Wholesale Information</h3>
            
            <div style="margin-top: 20px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; color: #6b7280; font-size: 14px; margin-bottom: 4px;">Business Name</label>
                    <p style="font-size: 18px;"><?php echo htmlspecialchars($wholesaleAccount['business_name']); ?></p>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; color: #6b7280; font-size: 14px; margin-bottom: 4px;">Status</label>
                    <p style="font-size: 18px;">
                        <?php if($wholesaleAccount['approved']): ?>
                            <span style="color: #059669;">✅ Approved</span>
                        <?php else: ?>
                            <span style="color: #f59e0b;">⏳ Pending Review</span>
                        <?php endif; ?>
                    </p>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; color: #6b7280; font-size: 14px; margin-bottom: 4px;">Website</label>
                    <p style="font-size: 18px;"><?php echo htmlspecialchars($wholesaleAccount['website'] ?? 'Not provided'); ?></p>
                </div>
                
                <button class="btn secondary" style="width: 100%;">Update Business Info</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php elseif($tab === 'payment'): ?>
    <!-- Payment Tab (Pay Upon Request + Cards on File) -->
    <?php 
    // Enforce Pay Upon Request (no NET terms)
    try {
        $pdo->prepare('UPDATE wholesale_accounts SET pay_upon_request = 1 WHERE id = ?')->execute([$wholesaleAccount['id']]);
    } catch (Throwable $e) {}

    // Fetch masked payment methods (tokenized)
    $paymentMethods = [];
    try {
        $stmt = $pdo->prepare('SELECT id, type, card_brand, last_four, bank_name, is_default, exp_month, exp_year FROM payment_methods WHERE wholesale_account_id = ? ORDER BY is_default DESC, created_at DESC');
        $stmt->execute([$wholesaleAccount['id']]);
        $paymentMethods = $stmt->fetchAll();
    } catch (Throwable $e) { $paymentMethods = []; }
    ?>

    <div class="grid two" style="gap: 24px;">
        <div class="card">
            <h3 style="margin-bottom: 12px;">💳 Payment Preference</h3>
            <div style="background:#ecfeff; border:1px solid #06b6d4; padding:12px; border-radius:8px; margin-bottom:12px;">
                <strong>Pay Upon Request</strong><br>
                We do not offer NET 30/60/90. Payment is requested at time of fulfillment.
            </div>
            <p style="color:#6b7280; font-size:14px;">Add a card on file to speed up fulfillment. Only brand and last 4 are shown; full details are vaulted.</p>
        </div>

        <div class="card">
            <h3 style="margin-bottom: 12px;">Stored Payment Methods</h3>
            <?php if (empty($paymentMethods)): ?>
                <p style="color:#6b7280;">No payment methods on file.</p>
            <?php else: ?>
                <div style="display:grid; gap:12px;">
                    <?php foreach ($paymentMethods as $pm): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px; border:1px solid #e5e7eb; border-radius:8px;">
                        <div>
                            <div style="font-weight:600;">
                                <?php if ($pm['type'] === 'credit_card'): ?>
                                    <?php echo htmlspecialchars($pm['card_brand'] ?: 'Card'); ?> •••• <?php echo htmlspecialchars($pm['last_four'] ?: ''); ?>
                                    <?php if ($pm['exp_month'] && $pm['exp_year']): ?>
                                        <span style="color:#6b7280; font-size:12px;">exp <?php echo htmlspecialchars($pm['exp_month'] . '/' . substr($pm['exp_year'], -2)); ?></span>
                                    <?php endif; ?>
                                <?php elseif ($pm['type'] === 'ach'): ?>
                                    Bank (<?php echo htmlspecialchars($pm['bank_name'] ?: 'ACH'); ?>) •••• <?php echo htmlspecialchars($pm['last_four'] ?: ''); ?>
                                <?php else: ?>
                                    <?php echo ucwords(str_replace('_',' ', $pm['type'])); ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($pm['is_default']): ?><div style="font-size:12px; color:#059669;">Default</div><?php endif; ?>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <a href="<?php echo outbound_path('/support/'); ?>" class="btn secondary small">Update</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
