<?php
require_once __DIR__ . '/../required/config.php';

start_session();

// Check if user is logged in BEFORE loading header
$uid = current_user_id();
if (!$uid) {
    // Show login/signup prompt instead of white screen
    $SEO = [
        'title' => 'Shop — Product Premium Products',
        'description' => 'Browse Product\'s catalog-ready Products.',
        'type' => 'website'
    ];
    require_once __DIR__ . '/../required/header.php';
    ?>
    <section class="section" style="background:linear-gradient(135deg,#f8fafc,#ffffff);min-height:calc(100vh - 200px);">
        <div class="container" style="max-width:700px;text-align:center;">
            <div class="card" style="padding:60px 40px;border:2px solid #e2e8f0;border-radius:20px;box-shadow:0 24px 80px rgba(15,23,42,0.15);">
                <div style="font-size:64px;margin-bottom:24px;">🛍️</div>
                <h1 style="margin:0 0 16px;color:#0c4a6e;font-size:36px;font-weight:900;">Welcome to the Product Shop</h1>
                <p style="margin:0 0 32px;color:#475569;font-size:18px;line-height:1.6;">
                    Please log in or create an account to browse our premium Products.
                </p>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:500px;margin:0 auto 32px;">
                    <a href="<?php echo outbound_path('/login.php?return=' . urlencode('/shop/')); ?>" 
                       class="btn" 
                       style="background:#0ea5e9;color:#fff;padding:16px;font-size:16px;font-weight:700;text-decoration:none;display:block;">
                        <i class="fas fa-sign-in-alt" style="margin-right:8px;"></i>Login
                    </a>
                    
                    <a href="<?php echo outbound_path('/index.php'); ?>" 
                       class="btn" 
                       style="background:#22c55e;color:#fff;padding:16px;font-size:16px;font-weight:700;text-decoration:none;display:block;">
                        <i class="fas fa-user-plus" style="margin-right:8px;"></i>Sign Up
                    </a>
                </div>
                
                <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:20px;text-align:left;">
                    <h3 style="margin:0 0 12px;color:#0369a1;font-size:18px;font-weight:700;">Two Account Types Available:</h3>
                    <div style="display:grid;gap:12px;">
                        <div style="display:flex;gap:12px;align-items:start;">
                            <div style="font-size:24px;">🛒</div>
                            <div>
                                <div style="font-weight:600;color:#0f172a;">Retail Customer</div>
                                <div style="font-size:14px;color:#64748b;">Shop at retail pricing for personal use</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:12px;align-items:start;">
                            <div style="font-size:24px;">🏪</div>
                            <div>
                                <div style="font-weight:600;color:#0f172a;">Business/Wholesale</div>
                                <div style="font-size:14px;color:#64748b;">Access wholesale pricing and bulk orders</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <p style="margin:24px 0 0;color:#64748b;font-size:14px;">
                    Questions? <a href="<?php echo outbound_path('/support/'); ?>" style="color:#0ea5e9;text-decoration:underline;">Contact Support</a>
                </p>
            </div>
        </div>
    </section>
    <?php
    require_once __DIR__ . '/../required/footer.php';
    exit;
}

// Now load remaining content for logged-in users
$SEO = [
    'title' => 'Wholesale Shop — Product Premium Products',
    'description' => 'Browse Product\'s catalog-ready Products. Wholesale pricing for approved accounts.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

$pdo = db();

// Check if user is admin - admins get full access without account requirements
$isAdmin = user_has_role($uid, 'admin');

// Determine user's price tier
$priceTier = get_user_price_tier($uid); // 'msrp' or 'wholesale'
$isRetailCustomer = ($priceTier === 'msrp');
$isWholesaleCustomer = ($priceTier === 'wholesale');

// Resolve wholesale account (only for non-admin wholesale customers)
$wholesaleAccount = null;
if ($isAdmin) {
    // Admins bypass wholesale account requirement - they can shop without a wholesale_accounts record
    $wholesaleAccount = ['business_name' => 'Admin Account', 'id' => null];
    $isWholesaleCustomer = true; // Ensure admins use wholesale pricing
} elseif ($isWholesaleCustomer) {
    $wholesaleAccount = ensure_wholesale_account($uid);
}

// Check if user is also a trainee (for special messaging)
$isTrainee = false;
$trainingProgress = 0;
if ($wholesaleAccount && !$isAdmin) {
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "trainee"');
        $stmt->execute([$uid]);
        $isTrainee = (bool)$stmt->fetch();
        
        if ($isTrainee) {
            // Get training progress
            $stmt = $pdo->prepare('
                SELECT 
                    COUNT(*) as total_modules,
                    SUM(CASE WHEN tp.status = "completed" THEN 1 ELSE 0 END) as completed_modules
                FROM training_modules tm
                LEFT JOIN training_progress tp ON tp.module_id = tm.id AND tp.user_id = ?
            ');
            $stmt->execute([$uid]);
            $progress = $stmt->fetch();
            $trainingProgress = $progress['total_modules'] > 0 ? round(($progress['completed_modules'] / $progress['total_modules']) * 100) : 0;
        }
    } catch (Throwable $e) {}
}

// Retail customers can shop without wholesale account
// Admins bypass this check entirely
if (!$isAdmin && $isWholesaleCustomer && !$wholesaleAccount) {
    // Check if they have a pending application
    $stmt = $pdo->prepare('SELECT * FROM wholesale_accounts WHERE user_id = ? AND approved = 0');
    $stmt->execute([$uid]);
    $pendingAccount = $stmt->fetch();
    
    if ($pendingAccount) {
        // They have a pending wholesale application
        ?>
        <div class="container" style="margin-top: 60px; text-align: center;">
            <div class="card" style="max-width: 600px; margin: 0 auto; padding: 60px;">
                <h1 style="color: #f59e0b; margin-bottom: 16px;">⏳ Application Pending</h1>
                <p style="font-size: 18px; color: #6b7280; margin-bottom: 24px;">
                    Your wholesale account application is currently under review.
                </p>
                <p style="color: #6b7280;">
                    We typically approve applications within 24-48 hours. You'll receive an email once your account is approved.
                </p>
                <div style="margin-top: 32px;">
                    <a href="<?php echo outbound_path('/'); ?>" class="btn">Return to Homepage</a>
                </div>
            </div>
        </div>
        <?php
        require_once __DIR__ . '/../required/footer.php';
        exit;
    } else {
        // Non-wholesale, non-retail customer needs to choose account type
        ?>
        <div class="container" style="margin-top: 60px; text-align: center;">
            <div class="card" style="max-width: 600px; margin: 0 auto; padding: 60px;">
                <h1 style="color: #dc2626; margin-bottom: 16px;">🛍️ Choose Your Account Type</h1>
                <p style="font-size: 18px; color: #6b7280; margin-bottom: 32px;">
                    Shop as a retail customer or apply for wholesale pricing.
                </p>
                <div style="display:grid;gap:16px;margin-top:32px;">
                    <a href="<?php echo outbound_path('/shop/set-retail.php'); ?>" class="btn" style="background: #22c55e; color: white;padding:16px;">
                        🛒 Shop Retail (MSRP Pricing)
                    </a>
                    <a href="<?php echo outbound_path('/customer/'); ?>" class="btn" style="background: #0ea5e9; color: white;padding:16px;">
                        💼 Apply for Wholesale Account
                    </a>
                </div>
            </div>
        </div>
        <?php
        require_once __DIR__ . '/../required/footer.php';
        exit;
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? 'all';
$sort = $_GET['sort'] ?? 'name';

// Load active products from database with all calculated pricing
$rows = $pdo->query("
    SELECT 
        p.id, p.sku, p.name, p.description, p.image_url,
        p.wholesale_price, p.msrp,
        p.attribute_per_oz, p.size_oz, p.total_mg, p.servings_10mg,
        p.material_cost, p.total_production_cost, p.profit_margin_percent,
        p.cost_per_10mg_serving, p.ml_per_10mg_serving, p.oz_per_10mg_serving,
        p.stock_quantity, p.low_stock_threshold,
        pg.name as group_name, pg.group_key,
        p.product_group_id
    FROM products p
    LEFT JOIN product_groups pg ON pg.id = p.product_group_id
    WHERE p.active = 1 
    ORDER BY pg.display_order ASC, p.attribute_per_oz ASC, p.name ASC
")->fetchAll();

$products = [];
foreach ($rows as $r) {
    $idSlug = strtolower(str_replace(['ELIX-','BLEND-'], ['elix-','blend-'], $r['sku']));
    
    // Category from product group or name
    $category = $r['group_name'] ?? (
        (stripos($r['name'],'75') !== false || stripos($r['name'],'High') !== false || stripos($r['name'],'Black') !== false) ? 'High-product specification' : 
        ((stripos($r['name'],'Kit')!==false)?'Bundles':
        ((stripos($r['name'],'Sample')!==false)?'Samples':'Regular'))
    );
    
    // Calculate profit margin for display (already calculated in DB as 60%)
    $profitMargin = round($r['profit_margin_percent'] ?? 60);
    
    // Calculate per-serving prices
    $servings = (int)($r['servings_10mg'] ?? 0);
    $costPerServing = $r['cost_per_10mg_serving'] ?? 0;
    $retailPerServing = $servings > 0 ? ($r['msrp'] / $servings) : 0;
    
    $products[] = [
        'id' => $idSlug,
        'db_id' => intval($r['id']),
        'sku' => $r['sku'],
        'name' => $r['name'],
        'description' => $r['description'] ?? '',
        'image_url' => $r['image_url'] ?? null,
        'wholesale_price' => (float)$r['wholesale_price'],
        'retail_price' => (float)$r['msrp'],
        'category' => $category,
        'in_stock' => ($r['stock_quantity'] ?? 100) > 0,
        'stock_qty' => $r['stock_quantity'] ?? 100,
        'rating' => 5,
        'reviews' => 0,
        'features' => [
            'catalog-ready attribute standardization',
            'Lab-tested for consistency',
            'Precise dosing control',
            'Perfect for bars & restaurants'
        ],
        'attribute_content' => ($r['attribute_per_oz'] ?? 0) . 'mg per oz',
        'recommended_dose' => '10mg attribute (0.5oz serving)',
        'servings' => $servings . ' × 10mg servings',
        'volume' => ($r['size_oz'] ?? 32) . 'oz',
        'profit_margin' => $profitMargin . '%',
        'cost_per_serving' => '$' . number_format($costPerServing, 2),
        'retail_per_serving' => '$' . number_format($retailPerServing, 2),
        'best_for' => $category,
        'badge' => null,
        'product_group_id' => $r['product_group_id'],
        'group_name' => $r['group_name']
    ];
}

// Filter products
$filteredProducts = array_filter($products, function($product) use ($search, $category) {
    $matchesSearch = empty($search) || 
        stripos($product['name'], $search) !== false || 
        stripos($product['description'], $search) !== false ||
        stripos($product['sku'], $search) !== false;
    
    $matchesCategory = $category === 'all' || $product['category'] === $category;
    
    return $matchesSearch && $matchesCategory;
});

// Sort products
usort($filteredProducts, function($a, $b) use ($sort) {
    switch($sort) {
        case 'price_low':
            return $a['wholesale_price'] <=> $b['wholesale_price'];
        case 'price_high':
            return $b['wholesale_price'] <=> $a['wholesale_price'];
        case 'rating':
            return $b['rating'] <=> $a['rating'];
        default:
            return strcmp($a['name'], $b['name']);
    }
});

// Cart functions
function get_cart_item_count() {
    $cart = $_SESSION['cart'] ?? [];
    return array_sum(array_column($cart, 'quantity'));
}

function get_cart_total() {
    global $products, $priceTier;
    $cart = $_SESSION['cart'] ?? [];
    $total = 0;
    foreach ($cart as $item) {
        $product = array_filter($products, fn($p) => $p['id'] === $item['product_id']);
        if ($product) {
            $product = array_values($product)[0];
            // Use appropriate price based on tier
            $price = ($priceTier === 'msrp') ? $product['retail_price'] : $product['wholesale_price'];
            $total += $price * $item['quantity'];
        }
    }
    return $total;
}
?>

<style>
.shop-header {
    background: linear-gradient(135deg, #065f46, #059669);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.wholesale-badge {
    display: inline-block;
    background: rgba(255,255,255,0.2);
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    margin-left: 16px;
}
.filters {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 32px;
}
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 32px;
    margin-bottom: 60px;
}
.product-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    padding: 24px;
    transition: all 0.3s;
    position: relative;
    display: flex;
    flex-direction: column;
    border: 1px solid #f1f5f9;
}
.product-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.15);
    border-color: #e2e8f0;
}
.product-image {
    width: 100%;
    height: 200px;
    object-fit: contain;
    border-radius: 8px;
    margin-bottom: 16px;
    background: #f9fafb;
}
.product-image-placeholder {
    width: 100%;
    height: 200px;
    background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
    font-size: 14px;
}
.product-badge {
    position: absolute;
    top: 16px;
    right: 16px;
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.product-header {
    margin-bottom: 16px;
}
.product-sku {
    color: #6b7280;
    font-size: 12px;
    margin-bottom: 4px;
}
.product-name {
    font-size: 20px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 8px;
}
.product-rating {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}
.stars {
    color: #fbbf24;
}
.dosing-info {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 8px;
    padding: 12px;
    margin: 16px 0;
}
.dosing-info h4 {
    color: #14532d;
    font-size: 14px;
    margin-bottom: 8px;
}
.dosing-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    font-size: 13px;
}
.dosing-item {
    display: flex;
    flex-direction: column;
}
.dosing-label {
    color: #6b7280;
    font-size: 11px;
    text-transform: uppercase;
}
.dosing-value {
    color: #059669;
    font-weight: 600;
}
.pricing-section {
    background: #eff6ff;
    border-radius: 8px;
    padding: 16px;
    margin: 16px 0;
}
.price-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.wholesale-price {
    font-size: 28px;
    font-weight: bold;
    color: #1e40af;
}
.retail-price {
    color: #6b7280;
    text-decoration: line-through;
}
.profit-info {
    background: #fef3c7;
    padding: 8px 12px;
    border-radius: 6px;
    margin-top: 8px;
}
.profit-info strong {
    color: #92400e;
}
.features-list {
    list-style: none;
    padding: 0;
    margin: 16px 0;
}
.features-list li {
    padding: 4px 0;
    color: #374151;
    font-size: 14px;
}
.features-list li:before {
    content: "✓ ";
    color: #059669;
    font-weight: bold;
    margin-right: 8px;
}
.stock-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 16px;
}
.in-stock {
    background: #dcfce7;
    color: #14532d;
}
.out-of-stock {
    background: #fee2e2;
    color: #991b1b;
}
.floating-cart {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    padding: 16px 24px;
    border-radius: 50px;
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.4);
    z-index: 1000;
    transition: all 0.3s;
}
.floating-cart:hover {
    transform: scale(1.05);
}
</style>

<div class="shop-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">
            <?php if ($isRetailCustomer): ?>
                Product Shop
            <?php else: ?>
                Wholesale Shop
                <span class="wholesale-badge">
                    <?php echo htmlspecialchars($wholesaleAccount['business_name'] ?? 'Admin Account'); ?>
                </span>
            <?php endif; ?>
        </h1>
        <p style="font-size: 18px; opacity: 0.9;">
            <?php if ($isRetailCustomer): ?>
                Premium Products • catalog-ready • Fast Shipping
            <?php else: ?>
                Premium Products • Wholesale Pricing • Same-Day Shipping
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="container">
    <!-- Training Progress Notice for Trainees -->
    <?php if ($isTrainee): ?>
    <div style="background: linear-gradient(135deg, #e0f2fe, #b3e5fc); border: 2px solid #0288d1; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="font-size: 32px;">🎓</div>
            <div style="flex: 1;">
                <h3 style="color: #01579b; margin: 0 0 8px 0;">Training in Progress - <?php echo $trainingProgress; ?>% Complete</h3>
                <p style="color: #0277bd; margin: 0 0 12px 0;">
                    You can continue shopping while completing your sales training! Your wholesale account remains fully active.
                </p>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="<?php echo outbound_path('/onboarding/trainee/'); ?>" class="btn" style="background: #0288d1; color: white; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-size: 14px;">
                        Continue Training
                    </a>
                    <a href="<?php echo outbound_path('/customer/profit-calculator.php'); ?>" class="btn" style="background: #f59e0b; color: white; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-size: 14px;">
                        💰 Calculate Profits
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="filters">
        <form method="get" style="display: flex; gap: 16px; flex-wrap: wrap;">
            <input type="text" name="search" placeholder="Search products..." 
                   value="<?php echo htmlspecialchars($search); ?>"
                   style="flex: 1; min-width: 200px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 8px;">
            
            <select name="category" style="padding: 10px; border: 1px solid #e5e7eb; border-radius: 8px;">
                <option value="all">All Categories</option>
                <option value="Regular" <?php echo $category === 'Regular' ? 'selected' : ''; ?>>Regular Formula</option>
                <option value="High-product specification" <?php echo $category === 'High-product specification' ? 'selected' : ''; ?>>High-product specification</option>
                <option value="Bundles" <?php echo $category === 'Bundles' ? 'selected' : ''; ?>>Bundles</option>
                <option value="Samples" <?php echo $category === 'Samples' ? 'selected' : ''; ?>>Samples</option>
            </select>
            
            <select name="sort" style="padding: 10px; border: 1px solid #e5e7eb; border-radius: 8px;">
                <option value="name">Sort by Name</option>
                <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Highest Rated</option>
            </select>
            
            <button type="submit" class="btn" style="padding: 10px 24px;">Filter</button>
        </form>
    </div>
    
    <!-- Products Grid -->
    <div class="product-grid">
        <?php foreach ($filteredProducts as $product): ?>
        <div class="product-card">
            <?php if (isset($product['badge'])): ?>
                <div class="product-badge"><?php echo $product['badge']; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($product['image_url'])): ?>
                <img src="<?php echo outbound_path($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-image">
            <?php else: ?>
                <div class="product-image-placeholder">No Image Available</div>
            <?php endif; ?>
            
            <div class="product-header">
                <div class="product-sku">SKU: <?php echo $product['sku']; ?></div>
                <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                <div class="product-rating">
                    <span class="stars">
                        <?php 
                        $rating = round($product['rating']);
                        echo str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
                        ?>
                    </span>
                    <span style="color: #6b7280; font-size: 14px;">
                        <?php echo $product['rating']; ?> (<?php echo $product['reviews']; ?> reviews)
                    </span>
                </div>
            </div>
            
            <div class="stock-status <?php echo $product['in_stock'] ? 'in-stock' : 'out-of-stock'; ?>">
                <?php echo $product['in_stock'] ? '✓ In Stock' : '✗ Out of Stock'; ?>
            </div>
            
            <p style="color: #6b7280; font-size: 14px; margin-bottom: 16px;">
                <?php 
                // Load shortcode engine for dynamic descriptions
                require_once __DIR__ . '/../required/shortcode-engine.php';
                $processedDescription = process_shortcodes($product['description'], [
                    'product_id' => $product['db_id'],
                    'sku' => $product['sku']
                ]);
                echo $processedDescription;
                ?>
            </p>
            
            <!-- Dosing Information (Only for Products) -->
            <?php if (in_array($product['product_group_id'], [1, 2])): ?>
            <div class="dosing-info">
                <h4>📊 Product Specifications</h4>
                <div class="dosing-grid">
                    <div class="dosing-item">
                        <span class="dosing-label">attribute Content</span>
                        <span class="dosing-value"><?php echo $product['attribute_content']; ?></span>
                    </div>
                    <div class="dosing-item">
                        <span class="dosing-label">Volume</span>
                        <span class="dosing-value"><?php echo $product['volume']; ?></span>
                    </div>
                    <div class="dosing-item">
                        <span class="dosing-label">Serving Size</span>
                        <span class="dosing-value"><?php echo $product['recommended_dose']; ?></span>
                    </div>
                    <div class="dosing-item">
                        <span class="dosing-label">Total Servings</span>
                        <span class="dosing-value"><?php echo $product['servings']; ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Pricing Section -->
            <div class="pricing-section">
                <?php if ($isRetailCustomer): ?>
                    <!-- Retail Customer: Only show MSRP as sale price -->
                    <div style="text-align: center;">
                        <div style="color: #6b7280; font-size: 14px; margin-bottom: 8px;">Price</div>
                        <div class="wholesale-price" style="color: #059669;">$<?php echo number_format($product['retail_price'], 2); ?></div>
                        <?php if (in_array($product['product_group_id'], [1, 2])): ?>
                        <div style="margin-top: 8px; font-size: 13px; color: #6b7280;">
                            <?php echo $product['servings']; ?> • <?php echo $product['retail_per_serving']; ?> per serving
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Wholesale Customer: Show wholesale price AND MSRP they can sell at -->
                    <div class="price-row">
                        <div>
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Your Cost</div>
                            <div class="wholesale-price">$<?php echo number_format($product['wholesale_price'], 2); ?></div>
                        </div>
                        <div style="text-align: right;">
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">You Sell At (MSRP)</div>
                            <div style="font-size: 20px; color: #059669;">$<?php echo number_format($product['retail_price'], 2); ?></div>
                        </div>
                    </div>
                    
                    <div class="profit-info">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Your Profit Margin: <strong><?php echo $product['profit_margin']; ?></strong></span>
                            <span>Best For: <strong><?php echo $product['best_for']; ?></strong></span>
                        </div>
                        <?php if (in_array($product['product_group_id'], [1, 2])): ?>
                        <div style="display: flex; justify-content: space-between; font-size: 12px;">
                            <span>Your Cost/Serving: <?php echo $product['cost_per_serving']; ?></span>
                            <span>MSRP/Serving: <?php echo $product['retail_per_serving']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($isWholesaleCustomer): ?>
                    <!-- Bulk Discount Preview (from product_group_commissions) - Only for wholesale -->
                    <?php
                    // Get bulk pricing tiers for this product's group
                    $bulkTiers = [];
                    if ($product['product_group_id']) {
                        try {
                            $stmt = $pdo->prepare("
                                SELECT tier_name, quantity_min, bulk_discount_percent, commission_percent
                                FROM product_group_commissions
                                WHERE product_group_id = ? AND active = 1
                                ORDER BY quantity_min ASC
                            ");
                            $stmt->execute([$product['product_group_id']]);
                            $bulkTiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {}
                    }
                    ?>
                    <?php if (!empty($bulkTiers)): ?>
                    <div style="background: #f0f9ff; padding: 12px; border-radius: 6px; margin-top: 8px; border: 1px solid #bfdbfe;">
                        <div style="font-size: 12px; font-weight: 600; color: #1e40af; margin-bottom: 6px;">💰 Volume Discounts Available</div>
                        <div style="display: grid; grid-template-columns: repeat(<?php echo min(count($bulkTiers), 4); ?>, 1fr); gap: 8px; font-size: 11px;">
                            <?php foreach ($bulkTiers as $tier): ?>
                            <div style="text-align: center;">
                                <div style="font-weight: 600; color: #dc2626;"><?php echo number_format($tier['bulk_discount_percent'], 0); ?>% off</div>
                                <div style="color: #6b7280;"><?php echo $tier['quantity_min']; ?>+ bottles</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Trust Badges -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 16px 0; padding: 16px; background: #f9fafb; border-radius: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="color: #059669; font-size: 20px;">🔒</div>
                    <div style="font-size: 12px; color: #374151;">
                        <div style="font-weight: 600;">Secure Checkout</div>
                        <div style="font-size: 10px; color: #6b7280;">SSL Encrypted</div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="color: #0ea5e9; font-size: 20px;">🚚</div>
                    <div style="font-size: 12px; color: #374151;">
                        <div style="font-weight: 600;">Fast Shipping</div>
                        <div style="font-size: 10px; color: #6b7280;">Same-Day Dispatch</div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="color: #10b981; font-size: 20px;">↻</div>
                    <div style="font-size: 12px; color: #374151;">
                        <div style="font-weight: 600;">30-Day Guarantee</div>
                        <div style="font-size: 10px; color: #6b7280;">Money Back</div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="color: #f59e0b; font-size: 20px;">✓</div>
                    <div style="font-size: 12px; color: #374151;">
                        <div style="font-weight: 600;">Quality Assured</div>
                        <div style="font-size: 10px; color: #6b7280;">Lab Tested</div>
                    </div>
                </div>
            </div>
            
            <?php 
            // Show GMP & Food Safe certifications for food/supplement products (groups 1, 2, 3)
            $isFoodSupplementProduct = in_array($product['product_group_id'], [1, 2, 3]);
            if ($isFoodSupplementProduct): 
            ?>
            <!-- GMP & Food Safety Certifications -->
            <div style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 2px solid #86efac; border-radius: 8px; padding: 12px; margin: 16px 0;">
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; justify-content: center;">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <div style="background: white; border-radius: 6px; padding: 4px 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <span style="font-weight: 700; color: #059669; font-size: 11px;">GMP</span>
                        </div>
                        <span style="font-size: 11px; color: #14532d; font-weight: 600;">Certified</span>
                    </div>
                    <div style="color: #86efac; font-size: 16px;">•</div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <div style="background: white; border-radius: 6px; padding: 4px 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <span style="font-weight: 700; color: #059669; font-size: 11px;">compliance</span>
                        </div>
                        <span style="font-size: 11px; color: #14532d; font-weight: 600;">Registered</span>
                    </div>
                    <div style="color: #86efac; font-size: 16px;">•</div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <div style="background: white; border-radius: 6px; padding: 4px 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <span style="font-weight: 700; color: #059669; font-size: 11px;">FOOD SAFE</span>
                        </div>
                    </div>
                    <div style="color: #86efac; font-size: 16px;">•</div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span style="font-size: 11px; color: #14532d; font-weight: 600;">🧪 Lab Tested</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Features -->
            <ul class="features-list">
                <?php foreach ($product['features'] as $feature): ?>
                    <li><?php echo htmlspecialchars($feature); ?></li>
                <?php endforeach; ?>
            </ul>
            
            <!-- Add to Cart -->
            <form action="cart.php" method="post" style="margin-top: auto; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                <div style="display: flex; gap: 12px; align-items: stretch;">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Qty</label>
                        <input type="number" name="quantity" value="1" min="1" max="99" 
                               style="width: 70px; padding: 12px 8px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 16px; font-weight: 600; text-align: center;">
                    </div>
                    <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-size: 11px; color: transparent; font-weight: 600; text-transform: uppercase;">Action</label>
                        <button type="submit" class="btn" 
                                style="flex: 1; background: linear-gradient(135deg, #059669, #047857); color: white; font-size: 16px; font-weight: 700; padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s;" 
                                <?php echo !$product['in_stock'] ? 'disabled' : ''; ?>
                                onmouseover="this.style.background='linear-gradient(135deg, #047857, #065f46)'; this.style.transform='scale(1.02)'"
                                onmouseout="this.style.background='linear-gradient(135deg, #059669, #047857)'; this.style.transform='scale(1)'">
                            <?php echo $product['in_stock'] ? '🛒 Add to Cart' : '✗ Out of Stock'; ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php if (empty($filteredProducts)): ?>
    <div class="card" style="text-align: center; padding: 60px;">
        <h2 style="color: #6b7280; margin-bottom: 16px;">No products found</h2>
        <p style="color: #9ca3af;">Try adjusting your filters or search terms.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Floating Cart -->
<?php if (get_cart_item_count() > 0): ?>
<a href="cart.php" class="floating-cart">
    🛒 Cart (<?php echo get_cart_item_count(); ?>) • $<?php echo number_format(get_cart_total(), 2); ?>
</a>
<?php endif; ?>

<?php require_once __DIR__ . '/../required/footer.php'; ?>