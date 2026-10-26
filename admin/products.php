<?php
$SEO = [
    'title' => 'Product Management — Admin',
    'description' => 'Manage Products, pricing, and inventory.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';

// Handle product actions (edit / archive / add)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_product') {
            $productId = intval($_POST['product_id'] ?? 0);
            $sku = trim($_POST['sku'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $mitContent = trim($_POST['attribute_content'] ?? '');
            $weightGrams = floatval($_POST['weight_grams'] ?? 0);
            $dimensions = trim($_POST['dimensions'] ?? '');
            $stockQuantity = intval($_POST['stock_quantity'] ?? 0);
            $lowStockThreshold = intval($_POST['low_stock_threshold'] ?? 10);
            $wholesalePrice = round(floatval($_POST['wholesale_price'] ?? 0), 2);
            $msrp = round(floatval($_POST['retail_price'] ?? 0), 2);
            $active = isset($_POST['active']) ? 1 : 0;
            
            if ($productId <= 0 || $sku === '' || $name === '') {
                throw new Exception('Missing required fields');
            }
            
            $stmt = $pdo->prepare("UPDATE products 
                SET sku = ?, name = ?, description = ?, attribute_content = ?, weight_grams = ?, dimensions = ?, 
                    stock_quantity = ?, low_stock_threshold = ?, wholesale_price = ?, msrp = ?, active = ?, updated_at = NOW()
                WHERE id = ?");
            $stmt->execute([$sku, $name, $description, $mitContent, $weightGrams > 0 ? $weightGrams : null, 
                          $dimensions, $stockQuantity, $lowStockThreshold, $wholesalePrice, $msrp, $active, $productId]);
            $message = 'Product updated successfully';
        } elseif ($action === 'delete_product') {
            $productId = intval($_POST['product_id'] ?? 0);
            if ($productId <= 0) throw new Exception('Invalid product');
            // Soft-delete for safety (keep historical orders intact)
            $stmt = $pdo->prepare("UPDATE products SET active = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$productId]);
            $message = 'Product archived (set inactive).';
        } elseif ($action === 'add_product') {
            $sku = trim($_POST['sku'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $mitContent = trim($_POST['attribute_content'] ?? '');
            $weightGrams = floatval($_POST['weight_grams'] ?? 0);
            $dimensions = trim($_POST['dimensions'] ?? '');
            $stockQuantity = intval($_POST['stock_quantity'] ?? 100);
            $lowStockThreshold = intval($_POST['low_stock_threshold'] ?? 10);
            $wholesalePrice = round(floatval($_POST['wholesale_price'] ?? 0), 2);
            $msrp = round(floatval($_POST['retail_price'] ?? 0), 2);
            if ($sku === '' || $name === '') throw new Exception('SKU and Name are required');
            $stmt = $pdo->prepare("INSERT INTO products (sku, name, description, attribute_content, weight_grams, dimensions, stock_quantity, low_stock_threshold, wholesale_price, msrp, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$sku, $name, $description, $mitContent, $weightGrams > 0 ? $weightGrams : null, $dimensions, $stockQuantity, $lowStockThreshold, $wholesalePrice, $msrp]);
            $message = 'Product added successfully';
        } elseif ($action === 'restore_product') {
            $productId = intval($_POST['product_id'] ?? 0);
            if ($productId <= 0) throw new Exception('Invalid product');
            $stmt = $pdo->prepare("UPDATE products SET active = 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$productId]);
            $message = 'Product restored and active.';
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch products from database with calculated pricing
try {
    $productsQuery = $pdo->query("
        SELECT 
            p.id,
            p.sku,
            p.name,
            p.description,
            p.attribute_content,
            p.weight_grams,
            p.dimensions,
            p.stock_quantity,
            p.low_stock_threshold,
            p.wholesale_price,
            p.msrp as retail_price,
            p.active,
            p.attribute_per_oz,
            p.size_oz,
            p.total_mg,
            p.servings_10mg,
            -- Pricing model columns
            COALESCE(p.material_cost, 0) as material_cost,
            COALESCE(p.total_production_cost, p.wholesale_price * 0.4) as cost,
            COALESCE(p.profit_margin_percent, 0) as margin_percent,
            COALESCE(p.cost_per_10mg_serving, 0) as cost_per_serving,
            -- Product group info
            p.product_group_id,
            pg.name as group_name,
            pg.group_key,
            -- 30-day sales data
            COALESCE(sales.sold_30_days, 0) as sold_30_days,
            -- Stock status
            CASE 
                WHEN p.stock_quantity <= p.low_stock_threshold THEN 1 
                ELSE 0 
            END as is_low_stock,
            -- Category (from group or name-based)
            COALESCE(pg.name,
                CASE 
                    WHEN p.name LIKE '%High%' OR p.name LIKE '%Black%' THEN 'High-product specification'
                    WHEN p.name LIKE '%Kit%' THEN 'Bundles'
                    WHEN p.name LIKE '%Sample%' THEN 'Samples'
                    ELSE 'Regular'
                END
            ) as category,
            -- Display values
            CONCAT(COALESCE(p.attribute_per_oz, 0), 'mg/oz') as attribute_content_display,
            CONCAT(COALESCE(p.size_oz, 32), 'oz') as volume,
            CONCAT(COALESCE(p.servings_10mg, 0), ' × 10mg') as servings
        FROM products p
        LEFT JOIN product_groups pg ON pg.id = p.product_group_id
        LEFT JOIN (
            SELECT 
                product_id,
                SUM(quantity) as sold_30_days
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND o.status != 'cancelled'
            GROUP BY product_id
        ) sales ON sales.product_id = p.id
        ORDER BY pg.display_order ASC, p.attribute_per_oz ASC, p.name ASC
    ");
    
    $products = [];
    while ($row = $productsQuery->fetch(PDO::FETCH_ASSOC)) {
        $products[] = [
            'id' => intval($row['id']),
            'sku' => $row['sku'],
            'name' => $row['name'],
            'description' => $row['description'],
            'category' => $row['category'],
            'wholesale_price' => floatval($row['wholesale_price']),
            'retail_price' => floatval($row['retail_price']),
            'cost' => floatval($row['cost']),
            'material_cost' => floatval($row['material_cost']),
            'margin_percent' => floatval($row['margin_percent']),
            'cost_per_serving' => floatval($row['cost_per_serving']),
            'in_stock' => $row['stock_quantity'] > 0,
            'stock_qty' => intval($row['stock_quantity']),
            'attribute_content' => $row['attribute_content_display'],
            'volume' => $row['volume'],
            'servings' => $row['servings'],
            'sold_30_days' => intval($row['sold_30_days']),
            'active' => intval($row['active']),
            'product_group_id' => $row['product_group_id'],
            'group_name' => $row['group_name'],
            'group_key' => $row['group_key']
        ];
    }
} catch (Exception $e) {
    $products = [];
    $error_message = "Unable to load products: " . $e->getMessage();
}

// Calculate statistics with proper cost breakdown
$totalInventoryValue = 0;
$totalRevenue30Days = 0;
$totalUnits = 0;
$outOfStock = 0;
$totalInventoryMaterialCost = 0;
$totalInventoryProductionCost = 0;
$totalInventoryWholesaleValue = 0;
$totalServingsInInventory = 0;

// Get active products only for inventory calculations
$activeProducts = array_filter($products, fn($p) => $p['active'] === 1);

foreach ($activeProducts as $product) {
    $qty = $product['stock_qty'];
    
    // Inventory values by cost type
    $totalInventoryMaterialCost += $qty * $product['material_cost'];
    $totalInventoryProductionCost += $qty * $product['cost'];
    $totalInventoryWholesaleValue += $qty * $product['wholesale_price'];
    
    // Revenue calculations
    $totalRevenue30Days += $product['sold_30_days'] * $product['wholesale_price'];
    
    // Units and servings
    $totalUnits += $qty;
    $servings = intval(preg_replace('/[^0-9]/', '', $product['servings']));
    $totalServingsInInventory += $servings * $qty;
    
    if (!$product['in_stock']) $outOfStock++;
}

// Use production cost as the primary inventory value
$totalInventoryValue = $totalInventoryProductionCost;

// Fetch low-stock alerts across all inventory types
$lowStockAlerts = [
    'products' => [],
    'ingredients' => [],
    'packaging' => [],
    'materials' => []
];

try {
    // Low stock finished products
    $stmt = $pdo->query("
        SELECT p.name, p.sku, p.stock_quantity, p.low_stock_threshold, pg.name as group_name
        FROM products p
        LEFT JOIN product_groups pg ON pg.id = p.product_group_id
        WHERE p.active = 1 AND p.stock_quantity <= p.low_stock_threshold
        ORDER BY p.stock_quantity ASC
        LIMIT 10
    ");
    $lowStockAlerts['products'] = $stmt->fetchAll();
    
    // Low stock ingredients
    $stmt = $pdo->query("
        SELECT i.ingredient_name, i.stock_quantity, i.low_stock_threshold, i.unit_of_measure, pg.name as group_name
        FROM product_group_ingredients i
        JOIN product_groups pg ON pg.id = i.product_group_id
        WHERE i.stock_quantity <= i.low_stock_threshold
        ORDER BY i.stock_quantity ASC
        LIMIT 10
    ");
    $lowStockAlerts['ingredients'] = $stmt->fetchAll();
    
    // Low stock packaging
    $stmt = $pdo->query("
        SELECT p.packaging_item, p.stock_quantity, p.low_stock_threshold, pg.name as group_name
        FROM product_group_packaging p
        JOIN product_groups pg ON pg.id = p.product_group_id
        WHERE p.stock_quantity <= p.low_stock_threshold
        ORDER BY p.stock_quantity ASC
        LIMIT 10
    ");
    $lowStockAlerts['packaging'] = $stmt->fetchAll();
    
    // Low stock materials
    $stmt = $pdo->query("
        SELECT m.material_name, m.stock_quantity, m.low_stock_threshold, m.unit_of_measure, pg.name as group_name
        FROM product_group_materials m
        JOIN product_groups pg ON pg.id = m.product_group_id
        WHERE m.stock_quantity <= m.low_stock_threshold
        ORDER BY m.stock_quantity ASC
        LIMIT 10
    ");
    $lowStockAlerts['materials'] = $stmt->fetchAll();
} catch (Throwable $e) {
    // Silently fail if manufacturing tables don't exist yet
}

$totalLowStockCount = count($lowStockAlerts['products']) + count($lowStockAlerts['ingredients']) + 
                      count($lowStockAlerts['packaging']) + count($lowStockAlerts['materials']);
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
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
.products-table {
    background: white;
    border-radius: 12px;
    overflow-x: auto;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.products-table table {
    width: 100%;
    border-collapse: collapse;
}
.products-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 14px;
    white-space: nowrap;
}
.products-table td {
    padding: 16px 12px;
    border-bottom: 1px solid #f3f4f6;
}
.stock-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.in-stock { background: #dcfce7; color: #14532d; }
.out-of-stock { background: #fee2e2; color: #991b1b; }
.low-stock { background: #fef3c7; color: #92400e; }
.profit-margin {
    font-weight: 600;
}
.margin-high { color: #059669; }
.margin-medium { color: #f59e0b; }
.margin-low { color: #dc2626; }
.category-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.category-regular { background: #dbeafe; color: #1e40af; }
.category-high-product specification { background: #fce7f3; color: #a21caf; }
.category-bundles { background: #f0fdf4; color: #14532d; }
.category-samples { background: #fef3c7; color: #92400e; }
</style>

<div class="admin-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Product Management</h1>
        <p style="font-size: 18px; opacity: 0.9;">Manage inventory, pricing, and product details</p>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    <?php $view = $_GET['view'] ?? 'active'; ?>
    <div style="margin-bottom:16px; display:flex; gap:12px;">
        <a href="<?php echo path_with_base('/admin/products.php'); ?>?view=active" class="btn <?php echo $view==='active'?'':'secondary'; ?>">Active</a>
        <a href="<?php echo path_with_base('/admin/products.php'); ?>?view=archived" class="btn <?php echo $view==='archived'?'':'secondary'; ?>">Archived</a>
    </div>
    
    <?php if ($message): ?>
        <div class="card" style="background: #dcfce7; color: #14532d; margin-bottom: 24px;">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics Dashboard with Interactive Controls -->
    <div class="card" style="margin-bottom: 24px; padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0;">📊 Inventory Dashboard</h3>
            <div style="display: flex; gap: 12px; align-items: center;">
                <label style="font-size: 13px; color: #6b7280;">
                    Product Group:
                    <select id="groupFilter" onchange="updateDashboard()" style="margin-left: 8px; padding: 6px 12px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="all">All Groups</option>
                        <?php
                        // Get unique product groups
                        $groups = [];
                        foreach ($activeProducts as $p) {
                            if ($p['product_group_id'] && !isset($groups[$p['product_group_id']])) {
                                $groups[$p['product_group_id']] = $p['group_name'];
                            }
                        }
                        foreach ($groups as $gid => $gname):
                        ?>
                        <option value="<?php echo $gid; ?>"><?php echo htmlspecialchars($gname); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="font-size: 13px; color: #6b7280;">
                    Profit Scenario:
                    <select id="profitScenario" onchange="updateDashboard()" style="margin-left: 8px; padding: 6px 12px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="tier-1-5">Tier 1-5 (0% disc, 20% comm)</option>
                        <option value="tier-6-10">Tier 6-10 (8% disc, 12% comm)</option>
                        <option value="tier-15plus">Tier 15+ (12% disc, 8% comm)</option>
                        <option value="blend-50-50">Blend 50/50 Mix</option>
                        <option value="blend-even">Even Split All Tiers</option>
                    </select>
                </label>
            </div>
        </div>
        
        <!-- Main Stats Grid -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <div class="stat-label">Active Products</div>
                <div class="stat-value" id="stat-total-products"><?php echo count($activeProducts); ?></div>
                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                    <span id="stat-groups-count"><?php echo count($groups); ?></span> product group<?php echo count($groups) != 1 ? 's' : ''; ?>, <?php echo count($products) - count($activeProducts); ?> archived
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Units in Stock</div>
                <div class="stat-value" id="stat-units"><?php echo number_format($totalUnits); ?></div>
                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;" id="stat-servings">
                    <?php echo number_format($totalServingsInInventory); ?> servings
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Production Cost</div>
                <div class="stat-value" style="font-size: 22px; color: #dc2626;" id="stat-production-cost">
                    $<?php echo number_format($totalInventoryProductionCost, 0); ?>
                </div>
                <div style="font-size: 11px; color: #6b7280; margin-top: 4px;" id="stat-material-cost">
                    Material: $<?php echo number_format($totalInventoryMaterialCost, 0); ?>
                </div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #fef3c7, #fed7aa); border: 2px solid #f59e0b;">
                <div class="stat-label" style="color: #92400e;">Projected Revenue</div>
                <div class="stat-value" style="font-size: 22px; color: #059669;" id="stat-projected-revenue">
                    $<?php echo number_format($totalInventoryWholesaleValue, 0); ?>
                </div>
                <div style="font-size: 11px; color: #92400e; margin-top: 4px;">
                    Wholesale sales revenue
                </div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #dcfce7, #bbf7d0); border: 2px solid #059669;">
                <div class="stat-label" style="color: #14532d;">Projected Profit</div>
                <div class="stat-value" style="font-size: 22px; color: #059669;" id="stat-projected-profit">
                    $<?php echo number_format($totalInventoryWholesaleValue - $totalInventoryProductionCost, 0); ?>
                </div>
                <div style="font-size: 11px; color: #14532d; margin-top: 4px;" id="stat-profit-scenario">
                    Tier 1-5 scenario
                </div>
            </div>
        </div>
        
        <!-- Cost Breakdown -->
        <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-top: 20px;">
            <h4 style="margin: 0 0 12px 0; font-size: 14px; color: #374151;">💰 Inventory Value Breakdown</h4>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">
                <div style="text-align: center; padding: 12px; background: white; border-radius: 6px;">
                    <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">Material Costs</div>
                    <div style="font-size: 18px; font-weight: 600; color: #dc2626;">$<?php echo number_format($totalInventoryMaterialCost, 2); ?></div>
                    <div style="font-size: 10px; color: #6b7280; margin-top: 2px;">
                        <?php echo number_format(($totalInventoryProductionCost > 0 ? ($totalInventoryMaterialCost / $totalInventoryProductionCost) * 100 : 0), 1); ?>% of total
                    </div>
                </div>
                <div style="text-align: center; padding: 12px; background: white; border-radius: 6px;">
                    <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">Labor & Packaging</div>
                    <div style="font-size: 18px; font-weight: 600; color: #f59e0b;">$<?php echo number_format($totalInventoryProductionCost - $totalInventoryMaterialCost, 2); ?></div>
                    <div style="font-size: 10px; color: #6b7280; margin-top: 2px;">
                        <?php echo number_format(($totalInventoryProductionCost > 0 ? (($totalInventoryProductionCost - $totalInventoryMaterialCost) / $totalInventoryProductionCost) * 100 : 0), 1); ?>% of total
                    </div>
                </div>
                <div style="text-align: center; padding: 12px; background: white; border-radius: 6px;">
                    <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">Potential Markup</div>
                    <div style="font-size: 18px; font-weight: 600; color: #059669;">$<?php echo number_format($totalInventoryWholesaleValue - $totalInventoryProductionCost, 2); ?></div>
                    <div style="font-size: 10px; color: #6b7280; margin-top: 2px;">
                        <?php echo number_format(($totalInventoryProductionCost > 0 ? (($totalInventoryWholesaleValue - $totalInventoryProductionCost) / $totalInventoryProductionCost) * 100 : 0), 0); ?>% markup
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Low Stock Alerts (All Inventory Types) -->
    <?php if ($totalLowStockCount > 0): ?>
    <div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, #fef2f2, #fecaca); border: 2px solid #dc2626;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; color: #991b1b;">🚨 Low Stock Alerts (<?php echo $totalLowStockCount; ?>)</h3>
            <div style="display: flex; gap: 8px;">
                <a href="<?php echo path_with_base('/operations/product-group-ingredients.php'); ?>" class="btn-small secondary">Manage Ingredients</a>
                <a href="<?php echo path_with_base('/operations/product-group-packaging.php'); ?>" class="btn-small secondary">Manage Packaging</a>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
            <!-- Finished Products Low Stock -->
            <?php if (!empty($lowStockAlerts['products'])): ?>
            <div style="background: white; padding: 16px; border-radius: 8px; border-left: 4px solid #dc2626;">
                <h4 style="margin: 0 0 12px 0; color: #991b1b; font-size: 14px;">📦 Finished Products (<?php echo count($lowStockAlerts['products']); ?>)</h4>
                <div style="max-height: 200px; overflow-y: auto;">
                    <?php foreach ($lowStockAlerts['products'] as $item): ?>
                    <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                        <div style="font-weight: 600; color: #dc2626; font-size: 13px;">
                            <?php echo htmlspecialchars($item['name']); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">
                            SKU: <?php echo htmlspecialchars($item['sku']); ?> | 
                            Stock: <?php echo intval($item['stock_quantity']); ?> 
                            (threshold: <?php echo intval($item['low_stock_threshold']); ?>)
                        </div>
                        <?php if ($item['group_name']): ?>
                        <div style="font-size: 11px; color: #9ca3af;">
                            Group: <?php echo htmlspecialchars($item['group_name']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Ingredients Low Stock -->
            <?php if (!empty($lowStockAlerts['ingredients'])): ?>
            <div style="background: white; padding: 16px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                <h4 style="margin: 0 0 12px 0; color: #92400e; font-size: 14px;">🧪 Ingredients (<?php echo count($lowStockAlerts['ingredients']); ?>)</h4>
                <div style="max-height: 200px; overflow-y: auto;">
                    <?php foreach ($lowStockAlerts['ingredients'] as $item): ?>
                    <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                        <div style="font-weight: 600; color: #d97706; font-size: 13px;">
                            <?php echo htmlspecialchars($item['ingredient_name']); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">
                            Stock: <?php echo number_format($item['stock_quantity'], 2); ?> <?php echo htmlspecialchars($item['unit_of_measure']); ?> 
                            (threshold: <?php echo number_format($item['low_stock_threshold'], 2); ?>)
                        </div>
                        <div style="font-size: 11px; color: #9ca3af;">
                            Group: <?php echo htmlspecialchars($item['group_name']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Packaging Low Stock -->
            <?php if (!empty($lowStockAlerts['packaging'])): ?>
            <div style="background: white; padding: 16px; border-radius: 8px; border-left: 4px solid #3b82f6;">
                <h4 style="margin: 0 0 12px 0; color: #1e40af; font-size: 14px;">📦 Packaging (<?php echo count($lowStockAlerts['packaging']); ?>)</h4>
                <div style="max-height: 200px; overflow-y: auto;">
                    <?php foreach ($lowStockAlerts['packaging'] as $item): ?>
                    <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                        <div style="font-weight: 600; color: #2563eb; font-size: 13px;">
                            <?php echo htmlspecialchars($item['packaging_item']); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">
                            Stock: <?php echo number_format($item['stock_quantity']); ?> units 
                            (threshold: <?php echo intval($item['low_stock_threshold']); ?>)
                        </div>
                        <div style="font-size: 11px; color: #9ca3af;">
                            Group: <?php echo htmlspecialchars($item['group_name']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Materials Low Stock -->
            <?php if (!empty($lowStockAlerts['materials'])): ?>
            <div style="background: white; padding: 16px; border-radius: 8px; border-left: 4px solid #8b5cf6;">
                <h4 style="margin: 0 0 12px 0; color: #6b21a8; font-size: 14px;">📦 Materials (<?php echo count($lowStockAlerts['materials']); ?>)</h4>
                <div style="max-height: 200px; overflow-y: auto;">
                    <?php foreach ($lowStockAlerts['materials'] as $item): ?>
                    <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                        <div style="font-weight: 600; color: #7c3aed; font-size: 13px;">
                            <?php echo htmlspecialchars($item['material_name']); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">
                            Stock: <?php echo number_format($item['stock_quantity'], 2); ?> <?php echo htmlspecialchars($item['unit_of_measure']); ?> 
                            (threshold: <?php echo number_format($item['low_stock_threshold'], 2); ?>)
                        </div>
                        <div style="font-size: 11px; color: #9ca3af;">
                            Group: <?php echo htmlspecialchars($item['group_name']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($totalLowStockCount === 0): ?>
        <div style="text-align: center; padding: 20px; background: #dcfce7; border-radius: 8px;">
            <div style="font-size: 32px; margin-bottom: 8px;">✅</div>
            <p style="margin: 0; color: #14532d; font-weight: 600;">All Inventory Levels Good!</p>
            <p style="margin: 4px 0 0 0; color: #059669; font-size: 13px;">No low-stock items detected across products, ingredients, packaging, or materials</p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Products Table -->
    <div class="products-table">
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>attribute Content</th>
                    <th>Stock</th>
                    <th>Cost</th>
                    <th>Wholesale</th>
                    <th>Retail</th>
                    <th>Margin</th>
                    <th>30-Day Sales</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                    $displayProducts = array_values(array_filter($products, function($p) use ($view){ return $view==='archived' ? ($p['active']===0) : ($p['active']===1); }));
                    foreach ($displayProducts as $product): 
                    // Use calculated margin from pricing model (Net Profit % after commission)
                    $margin = $product['margin_percent'];
                    $marginClass = $margin >= 55 ? 'margin-high' : ($margin >= 40 ? 'margin-medium' : 'margin-low');
                    $stockClass = !$product['in_stock'] ? 'out-of-stock' : ($product['stock_qty'] < 20 ? 'low-stock' : 'in-stock');
                    $categoryClass = 'category-' . strtolower(str_replace([' ', '-'], '-', $product['category']));
                ?>
                <tr>
                    <td style="font-family: monospace; font-size: 12px;">
                        <?php echo $product['sku']; ?>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                        <br><small style="color: #6b7280;"><?php echo $product['volume']; ?> • <?php echo $product['servings']; ?> servings</small>
                    </td>
                    <td>
                        <span class="category-badge <?php echo $categoryClass; ?>">
                            <?php echo $product['category']; ?>
                        </span>
                    </td>
                    <td><?php echo $product['attribute_content']; ?></td>
                    <td>
                        <span class="stock-badge <?php echo $stockClass; ?>">
                            <?php echo $product['in_stock'] ? $product['stock_qty'] . ' units' : 'Out of Stock'; ?>
                        </span>
                    </td>
                    <td>$<?php echo number_format($product['cost'], 2); ?></td>
                    <td style="font-weight: 600; color: #1e40af;">
                        $<?php echo number_format($product['wholesale_price'], 2); ?>
                    </td>
                    <td>$<?php echo number_format($product['retail_price'], 2); ?></td>
                    <td class="profit-margin <?php echo $marginClass; ?>">
                        <?php echo round($margin); ?>%
                    </td>
                    <td>
                        <?php echo $product['sold_30_days']; ?> units
                        <br><small style="color: #6b7280;">$<?php echo number_format($product['sold_30_days'] * $product['wholesale_price'], 0); ?></small>
                    </td>
                    <td>
                        <a href="<?php echo path_with_base('/admin/edit-product.php?id=' . $product['id']); ?>" class="btn-small" style="background: #3b82f6; color: white; margin-bottom: 4px; display: inline-block; text-decoration: none; text-align: center;">Edit</a>
                        <br>
                        <?php if ($product['active'] === 1): ?>
                        <form method="post" onsubmit="return confirm('Archive this product? It will be set inactive and hidden from shop.');">
                            <input type="hidden" name="action" value="delete_product">
                            <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                            <button class="btn-small" style="background: #dc2626; color: white;">Archive</button>
                        </form>
                        <?php else: ?>
                        <form method="post" onsubmit="return confirm('Restore this product to active?');">
                            <input type="hidden" name="action" value="restore_product">
                            <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                            <button class="btn-small" style="background: #059669; color: white;">Restore</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Quick Actions -->
    <div class="card" style="margin-top: 32px;">
        <h3>Quick Actions</h3>
        <div style="display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap;">
            <a href="<?php echo path_with_base('/admin/edit-product.php?id=0'); ?>" class="btn" style="background: var(--green); color: white; text-decoration: none; display: inline-block;">+ Add New Product</a>
            <a href="<?php echo path_with_base('/admin/product-groups.php'); ?>" class="btn" style="background: #3b82f6; color: white; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                📦 Manage Product Groups
            </a>
            <a href="<?php echo path_with_base('/admin/pricing-manager.php'); ?>" class="btn secondary" style="text-decoration: none; display: inline-block;">
                💰 Bulk Update Prices
            </a>
            <button class="btn secondary">
                Import Products CSV
            </button>
            <button class="btn secondary">
                Export Inventory Report
            </button>
        </div>
    </div>
</div>

<!-- Modal removed - using dedicated edit-product.php page instead -->

<script>
// Product data for dashboard calculations (from PHP)
const productsData = <?php echo json_encode($activeProducts); ?>;

// Commission tier structure (from database)
const commissionTiers = {
    'tier-1-5': { discount: 0, commission: 20, name: 'Tier 1-5' },
    'tier-6-10': { discount: 8, commission: 12, name: 'Tier 6-10' },
    'tier-15plus': { discount: 12, commission: 8, name: 'Tier 15+' }
};

function updateDashboard() {
    const groupFilter = document.getElementById('groupFilter').value;
    const profitScenario = document.getElementById('profitScenario').value;
    
    // Filter products by selected group
    let filteredProducts = productsData;
    if (groupFilter !== 'all') {
        filteredProducts = productsData.filter(p => p.product_group_id == groupFilter);
    }
    
    // Calculate totals for filtered products
    let totalUnits = 0;
    let totalServings = 0;
    let totalProductionCost = 0;
    let totalMaterialCost = 0;
    let totalWholesaleValue = 0;
    
    filteredProducts.forEach(p => {
        const qty = p.stock_qty || 0;
        const servings = parseInt((p.servings || '0').replace(/[^0-9]/g, '')) || 0;
        
        totalUnits += qty;
        totalServings += servings * qty;
        totalProductionCost += qty * (p.cost || 0);
        totalMaterialCost += qty * (p.material_cost || 0);
        totalWholesaleValue += qty * (p.wholesale_price || 0);
    });
    
    // Calculate OUR projected revenue (what we make from wholesale sales)
    // and OUR profit (wholesale revenue - production costs)
    let projectedRevenue = totalWholesaleValue; // Base wholesale value
    let projectedProfit = 0;
    let scenarioText = '';
    
    // Apply discount tier to calculate actual revenue
    switch(profitScenario) {
        case 'tier-1-5':
            // No discount - full wholesale price
            projectedRevenue = totalWholesaleValue;
            projectedProfit = projectedRevenue - totalProductionCost;
            scenarioText = 'Tier 1-5 scenario';
            break;
            
        case 'tier-6-10':
            // 8% discount to customer - we get 92% of wholesale
            projectedRevenue = totalWholesaleValue * 0.92;
            projectedProfit = projectedRevenue - totalProductionCost;
            scenarioText = 'Tier 6-10 scenario';
            break;
            
        case 'tier-15plus':
            // 12% discount to customer - we get 88% of wholesale
            projectedRevenue = totalWholesaleValue * 0.88;
            projectedProfit = projectedRevenue - totalProductionCost;
            scenarioText = 'Tier 15+ scenario';
            break;
            
        case 'blend-50-50':
            // 50% sold at tier 1-5, 50% at tier 6-10
            const half = totalWholesaleValue / 2;
            projectedRevenue = half + (half * 0.92);
            projectedProfit = projectedRevenue - totalProductionCost;
            scenarioText = '50/50 Mix scenario';
            break;
            
        case 'blend-even':
            // Equal split across all 3 tiers
            const third = totalWholesaleValue / 3;
            projectedRevenue = third + (third * 0.92) + (third * 0.88);
            projectedProfit = projectedRevenue - totalProductionCost;
            scenarioText = 'Even Split scenario';
            break;
    }
    
    // Update display with formatted numbers
    document.getElementById('stat-total-products').textContent = filteredProducts.length;
    document.getElementById('stat-units').textContent = totalUnits.toLocaleString();
    document.getElementById('stat-servings').textContent = totalServings.toLocaleString() + ' servings';
    document.getElementById('stat-production-cost').textContent = '$' + totalProductionCost.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
    document.getElementById('stat-material-cost').textContent = 'Material: $' + totalMaterialCost.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
    document.getElementById('stat-projected-revenue').textContent = '$' + projectedRevenue.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
    document.getElementById('stat-projected-profit').textContent = '$' + projectedProfit.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
    document.getElementById('stat-profit-scenario').textContent = scenarioText;
    
    // Calculate profit margin percentage
    const profitMargin = totalProductionCost > 0 ? ((projectedProfit / projectedRevenue) * 100) : 0;
    
    console.log('Dashboard Updated:', {
        products: filteredProducts.length,
        units: totalUnits,
        servings: totalServings,
        productionCost: totalProductionCost,
        materialCost: totalMaterialCost,
        wholesaleValue: totalWholesaleValue,
        projectedRevenue: projectedRevenue,
        projectedProfit: projectedProfit,
        scenario: profitScenario,
        profitMargin: profitMargin.toFixed(1) + '%'
    });
}

// Initialize dashboard on page load
document.addEventListener('DOMContentLoaded', function() {
    updateDashboard();
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
