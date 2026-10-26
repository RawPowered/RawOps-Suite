<?php
$SEO = [
    'title' => 'Operations Dashboard — Product',
    'description' => 'Operations and shipping management dashboard for Product.',
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
?>

<section class="section">
    <div class="container">
        <h1>🚚 Operations Dashboard</h1>
        <p class="muted">Manage inventory, shipping, and order fulfillment</p>
        
        <!-- Operations Navigation -->
        <div class="card" style="margin-bottom: 24px;">
            <h3 style="margin: 0 0 16px 0; color: #374151;">Core Operations</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <a href="/operations/inventory.php" class="btn" style="text-align: center; padding: 20px;">
                    📦 Inventory Management
                </a>
                <a href="/operations/orders.php" class="btn secondary" style="text-align: center; padding: 20px;">
                    📋 Order Processing
                </a>
                <a href="/operations/shipping.php" class="btn secondary" style="text-align: center; padding: 20px;">
                    🚛 Shipping Labels
                </a>
                <a href="/operations/reports.php" class="btn secondary" style="text-align: center; padding: 20px;">
                    📊 Reports
                </a>
            </div>
        </div>
        
        <!-- Manufacturing & Production -->
        <div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 2px solid #059669;">
            <h3 style="margin: 0 0 16px 0; color: #14532d;">🏭 Manufacturing & Production</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px;">
                <a href="/operations/operations_make_order.php" class="btn" style="background: #059669; color: white; text-align: center; padding: 16px;">
                    🏭 Create Work Order
                </a>
                <a href="/operations/work-order-fulfillment.php" class="btn" style="background: #3b82f6; color: white; text-align: center; padding: 16px;">
                    ✅ Fulfill Work Orders
                </a>
                <a href="/operations/product-group-coa-batch-manager.php" class="btn secondary" style="text-align: center; padding: 16px;">
                    🔬 COA & Batch Manager
                </a>
                <a href="/operations/product-group-labor.php" class="btn secondary" style="text-align: center; padding: 16px;">
                    ⏱️ Labor Tracking
                </a>
                <a href="/operations/production-team-members.php" class="btn secondary" style="text-align: center; padding: 16px;">
                    👥 Production Team Members
                </a>
            </div>
            
            <h4 style="margin: 24px 0 12px 0; color: #14532d; font-size: 14px;">Inventory Management</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                <a href="/operations/product-group-ingredients.php" class="btn secondary" style="text-align: center; padding: 12px; font-size: 13px;">
                    🧪 Ingredients Inventory
                </a>
                <a href="/operations/product-group-packaging.php" class="btn secondary" style="text-align: center; padding: 12px; font-size: 13px;">
                    📦 Packaging Inventory
                </a>
                <a href="/operations/product-group-materials.php" class="btn secondary" style="text-align: center; padding: 12px; font-size: 13px;">
                    📦 Materials Inventory
                </a>
                <a href="/operations/product-group-recipe.php" class="btn secondary" style="text-align: center; padding: 12px; font-size: 13px;">
                    📋 Recipe Management
                </a>
            </div>
        </div>

        <!-- Dashboard Overview -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
            <!-- Main Stats -->
            <div class="card">
                <h2 style="color: #059669; margin-bottom: 24px;">📊 Operations Overview</h2>
                <?php
                try {
                    $stats = [
                        'pending_orders' => $pdo->query('SELECT COUNT(*) FROM orders WHERE status = "pending" OR shipping_status = "pending"')->fetchColumn(),
                        'processing_orders' => $pdo->query('SELECT COUNT(*) FROM orders WHERE shipping_status = "processing"')->fetchColumn(),
                        'shipped_today' => $pdo->query('SELECT COUNT(*) FROM orders WHERE shipping_status = "shipped" AND DATE(updated_at) = CURDATE()')->fetchColumn(),
                        'low_stock_items' => $pdo->query('SELECT COUNT(*) FROM products WHERE active = 1 AND stock_quantity <= low_stock_threshold')->fetchColumn(),
                        'total_products' => $pdo->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn(),
                        'orders_this_week' => $pdo->query('SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn(),
                        'active_work_orders' => $pdo->query('SELECT COUNT(*) FROM work_orders WHERE status IN ("scheduled", "in_progress", "draft")')->fetchColumn() ?? 0,
                        'active_batches' => $pdo->query('SELECT COUNT(*) FROM batch_codes WHERE active = 1')->fetchColumn() ?? 0,
                        'work_orders_need_review' => $pdo->query('SELECT COUNT(*) FROM work_orders WHERE COALESCE(requires_ops_review, 0) = 1 AND COALESCE(ops_reviewed, 0) = 0')->fetchColumn() ?? 0,
                        'low_stock_ingredients' => $pdo->query('SELECT COUNT(*) FROM product_group_ingredients WHERE stock_quantity <= low_stock_threshold')->fetchColumn() ?? 0,
                        'low_stock_packaging' => $pdo->query('SELECT COUNT(*) FROM product_group_packaging WHERE stock_quantity <= low_stock_threshold')->fetchColumn() ?? 0,
                        'low_stock_materials' => $pdo->query('SELECT COUNT(*) FROM product_group_materials WHERE stock_quantity <= low_stock_threshold')->fetchColumn() ?? 0
                    ];
                ?>
                
                <!-- Stats Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div style="background: linear-gradient(135deg, #fef3c7, #fed7aa); padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 28px; font-weight: bold; color: #d97706;"><?php echo intval($stats['pending_orders']); ?></div>
                        <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Pending Orders</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 28px; font-weight: bold; color: #2563eb;"><?php echo intval($stats['processing_orders']); ?></div>
                        <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Processing</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 28px; font-weight: bold; color: #059669;"><?php echo intval($stats['shipped_today']); ?></div>
                        <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Shipped Today</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #fef2f2, #fecaca); padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 28px; font-weight: bold; color: #dc2626;">
                            <?php echo intval($stats['low_stock_items']) + intval($stats['low_stock_ingredients']) + intval($stats['low_stock_packaging']) + intval($stats['low_stock_materials']); ?>
                        </div>
                        <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Low Stock Items</div>
                        <div style="font-size: 11px; color: #991b1b; margin-top: 4px;">
                            Products: <?php echo intval($stats['low_stock_items']); ?> | 
                            Ingredients: <?php echo intval($stats['low_stock_ingredients']); ?> | 
                            Pkg: <?php echo intval($stats['low_stock_packaging']); ?> | 
                            Mat: <?php echo intval($stats['low_stock_materials']); ?>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); padding: 20px; border-radius: 8px; border-left: 4px solid #0ea5e9;">
                        <h3 style="margin: 0 0 8px 0; color: #0c4a6e;">📋 Orders This Week</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #0284c7; margin-bottom: 4px;">
                            <?php echo intval($stats['orders_this_week']); ?>
                        </div>
                        <div style="color: #6b7280; font-size: 14px;">Total processed</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #fffbeb, #fef3c7); padding: 20px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                        <h3 style="margin: 0 0 8px 0; color: #92400e;">📦 Inventory Status</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #d97706; margin-bottom: 4px;">
                            <?php echo intval($stats['total_products']); ?>
                        </div>
                        <div style="color: #6b7280; font-size: 14px;">Active products</div>
                    </div>
                </div>
                
                <!-- Manufacturing Stats -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); padding: 20px; border-radius: 8px; border-left: 4px solid #059669;">
                        <h3 style="margin: 0 0 8px 0; color: #14532d;">🏭 Active Work Orders</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #059669; margin-bottom: 4px;">
                            <?php echo intval($stats['active_work_orders']); ?>
                        </div>
                        <div style="color: #6b7280; font-size: 14px;">In production</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); padding: 20px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                        <h3 style="margin: 0 0 8px 0; color: #92400e;">🔬 Active Batches</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #f59e0b; margin-bottom: 4px;">
                            <?php echo intval($stats['active_batches']); ?>
                        </div>
                        <div style="color: #6b7280; font-size: 14px;">Sellable inventory</div>
                    </div>
                </div>
                <?php } catch (Throwable $e) { ?>
                    <p style="color: #8a1f1f;">Unable to load statistics. Please check database connection.</p>
                <?php } ?>
            </div>
            
            <!-- Sidebar -->
            <div>
                <!-- Urgent Actions -->
                <div class="card" style="margin-bottom: 20px;">
                    <h2 style="color: #dc2626; margin-bottom: 16px;">🚨 Urgent Actions</h2>
                    <?php
                    try {
                        // Get low stock items (finished products)
                        $lowStockItems = $pdo->query('
                            SELECT name, stock_quantity, low_stock_threshold 
                            FROM products 
                            WHERE active = 1 AND stock_quantity <= low_stock_threshold 
                            ORDER BY stock_quantity ASC 
                            LIMIT 5
                        ')->fetchAll();
                        
                        // Get low stock ingredients
                        $lowStockIngredients = $pdo->query('
                            SELECT i.ingredient_name, i.stock_quantity, i.low_stock_threshold, i.unit_of_measure, pg.name as group_name
                            FROM product_group_ingredients i
                            JOIN product_groups pg ON pg.id = i.product_group_id
                            WHERE i.stock_quantity <= i.low_stock_threshold
                            ORDER BY i.stock_quantity ASC
                            LIMIT 5
                        ')->fetchAll() ?? [];
                        
                        // Get low stock packaging
                        $lowStockPackaging = $pdo->query('
                            SELECT p.packaging_item, p.stock_quantity, p.low_stock_threshold, pg.name as group_name
                            FROM product_group_packaging p
                            JOIN product_groups pg ON pg.id = p.product_group_id
                            WHERE p.stock_quantity <= p.low_stock_threshold
                            ORDER BY p.stock_quantity ASC
                            LIMIT 5
                        ')->fetchAll() ?? [];
                        
                        // Get low stock materials
                        $lowStockMaterials = $pdo->query('
                            SELECT m.material_name, m.stock_quantity, m.low_stock_threshold, m.unit_of_measure, pg.name as group_name
                            FROM product_group_materials m
                            JOIN product_groups pg ON pg.id = m.product_group_id
                            WHERE m.stock_quantity <= m.low_stock_threshold
                            ORDER BY m.stock_quantity ASC
                            LIMIT 3
                        ')->fetchAll() ?? [];
                        
                        // Get orders needing attention
                        $urgentOrders = $pdo->query('
                            SELECT o.order_number, o.created_at, wa.business_name
                            FROM orders o
                            JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
                            WHERE o.status = "pending" AND o.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            ORDER BY o.created_at ASC
                            LIMIT 3
                        ')->fetchAll();
                        
                        // Get work orders needing review (only if columns exist)
                        $workOrdersNeedReview = [];
                        try {
                            $workOrdersNeedReview = $pdo->query('
                                SELECT wo.wo_number, wo.batch_code, 
                                       COALESCE(wo.actual_quantity_produced, 0) as actual_quantity_produced, 
                                       wo.quantity_to_produce,
                                       pg.name as group_name
                                FROM work_orders wo
                                JOIN product_groups pg ON pg.id = wo.product_group_id
                                WHERE COALESCE(wo.requires_ops_review, 0) = 1 AND COALESCE(wo.ops_reviewed, 0) = 0
                                ORDER BY wo.updated_at DESC
                                LIMIT 5
                            ')->fetchAll() ?? [];
                        } catch (Throwable $e) {
                            // Columns don't exist yet - migration not run
                            $workOrdersNeedReview = [];
                        }
                        
                        $hasUrgent = !empty($lowStockItems) || !empty($urgentOrders) || !empty($lowStockIngredients) || !empty($lowStockPackaging) || !empty($lowStockMaterials) || !empty($workOrdersNeedReview);
                        
                        if ($hasUrgent): ?>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php if (!empty($lowStockItems)): ?>
                                    <div style="margin-bottom: 16px;">
                                        <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">📦 Low Stock Items</h4>
                                        <?php foreach ($lowStockItems as $item): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                                                <div style="font-weight: 600; color: #dc2626;">
                                                    <?php echo htmlspecialchars($item['name']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    <?php echo intval($item['stock_quantity']); ?> remaining (threshold: <?php echo intval($item['low_stock_threshold']); ?>)
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($lowStockIngredients)): ?>
                                    <div style="margin-bottom: 16px;">
                                        <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">🧪 Low Stock Ingredients</h4>
                                        <?php foreach ($lowStockIngredients as $ing): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                                                <div style="font-weight: 600; color: #dc2626;">
                                                    <?php echo htmlspecialchars($ing['ingredient_name']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    <?php echo htmlspecialchars($ing['group_name']); ?> - 
                                                    <?php echo number_format($ing['stock_quantity'], 2); ?> <?php echo $ing['unit_of_measure']; ?> remaining
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <a href="<?php echo path_with_base('/operations/product-group-ingredients.php'); ?>" style="font-size: 12px; color: #059669; display: block; margin-top: 8px;">→ Manage Ingredients</a>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($lowStockPackaging)): ?>
                                    <div style="margin-bottom: 16px;">
                                        <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">📦 Low Stock Packaging</h4>
                                        <?php foreach ($lowStockPackaging as $pkg): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                                                <div style="font-weight: 600; color: #dc2626;">
                                                    <?php echo htmlspecialchars($pkg['packaging_item']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    <?php echo htmlspecialchars($pkg['group_name']); ?> - 
                                                    <?php echo number_format($pkg['stock_quantity']); ?> units remaining
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <a href="<?php echo path_with_base('/operations/product-group-packaging.php'); ?>" style="font-size: 12px; color: #059669; display: block; margin-top: 8px;">→ Manage Packaging</a>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($lowStockMaterials)): ?>
                                    <div style="margin-bottom: 16px;">
                                        <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">📦 Low Stock Materials</h4>
                                        <?php foreach ($lowStockMaterials as $mat): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                                                <div style="font-weight: 600; color: #dc2626;">
                                                    <?php echo htmlspecialchars($mat['material_name']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    <?php echo htmlspecialchars($mat['group_name']); ?> - 
                                                    <?php echo number_format($mat['stock_quantity'], 2); ?> <?php echo $mat['unit_of_measure']; ?> remaining
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <a href="<?php echo path_with_base('/operations/product-group-materials.php'); ?>" style="font-size: 12px; color: #059669; display: block; margin-top: 8px;">→ Manage Materials</a>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($workOrdersNeedReview)): ?>
                                    <div style="margin-bottom: 16px;">
                                        <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">⚠️ Work Orders Need Review</h4>
                                        <?php foreach ($workOrdersNeedReview as $wo): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                                                <div style="font-weight: 600; color: #dc2626;">
                                                    <?php echo htmlspecialchars($wo['wo_number']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    <?php echo htmlspecialchars($wo['group_name']); ?> - 
                                                    Expected: <?php echo number_format($wo['quantity_to_produce']); ?>, 
                                                    Actual: <?php echo number_format($wo['actual_quantity_produced']); ?>
                                                </div>
                                                <div style="font-size: 11px; color: #9ca3af;">
                                                    Variance: <?php echo ($wo['actual_quantity_produced'] - $wo['quantity_to_produce']) > 0 ? '+' : ''; ?><?php echo number_format($wo['actual_quantity_produced'] - $wo['quantity_to_produce']); ?> units
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <a href="<?php echo path_with_base('/operations/work-order-fulfillment.php?status=needs_review'); ?>" style="font-size: 12px; color: #dc2626; display: block; margin-top: 8px;">→ Review Work Orders</a>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($urgentOrders)): ?>
                                    <div>
                                        <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">📋 Overdue Orders</h4>
                                        <?php foreach ($urgentOrders as $order): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                                                <div style="font-weight: 600; color: #dc2626;">
                                                    <?php echo htmlspecialchars($order['order_number']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    <?php echo htmlspecialchars($order['business_name']); ?>
                                                </div>
                                                <div style="font-size: 11px; color: #9ca3af;">
                                                    <?php echo date('M j, g:i a', strtotime($order['created_at'])); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: #059669;">
                                <div style="font-size: 24px; margin-bottom: 8px;">✅</div>
                                <p style="margin: 0; font-size: 14px;">All operations running smoothly!</p>
                            </div>
                        <?php endif; ?>
                    <?php } catch (Throwable $e) { ?>
                        <p style="color: #dc2626;">Unable to load urgent actions.</p>
                    <?php } ?>
                </div>
                
                <!-- Quick Stats -->
                <div class="card">
                    <h2 style="color: #059669; margin-bottom: 16px;">⚡ Quick Actions</h2>
                    <div style="display: grid; gap: 8px;">
                        <a href="/operations/inventory.php?action=bulk_update" class="btn secondary" style="font-size: 12px; padding: 8px 12px;">
                            🔄 Bulk Stock Update
                        </a>
                        <a href="/operations/orders.php?filter=pending" class="btn secondary" style="font-size: 12px; padding: 8px 12px;">
                            📋 Process Pending Orders
                        </a>
                        <a href="/operations/shipping.php?action=print_labels" class="btn secondary" style="font-size: 12px; padding: 8px 12px;">
                            🏷️ Print Shipping Labels
                        </a>
                        <a href="/operations/reports.php?type=daily" class="btn secondary" style="font-size: 12px; padding: 8px 12px;">
                            📊 Daily Report
                        </a>
                        <hr style="margin: 12px 0; border: none; border-top: 1px solid #e5e7eb;">
                        <a href="/operations/operations_make_order.php" class="btn" style="background: #059669; color: white; font-size: 12px; padding: 8px 12px;">
                            🏭 New Work Order
                        </a>
                        <a href="/operations/product-group-ingredients.php" class="btn secondary" style="font-size: 12px; padding: 8px 12px;">
                            🧪 Ingredients
                        </a>
                        <a href="/operations/product-group-packaging.php" class="btn secondary" style="font-size: 12px; padding: 8px 12px;">
                            📦 Packaging
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Auto-refresh for real-time updates -->
<script>
// Auto-refresh dashboard every 60 seconds
let refreshInterval = setInterval(function() {
    if (document.visibilityState === 'visible') {
        const refreshIndicator = document.createElement('div');
        refreshIndicator.innerHTML = '🔄 Updating...';
        refreshIndicator.style.position = 'fixed';
        refreshIndicator.style.top = '20px';
        refreshIndicator.style.right = '20px';
        refreshIndicator.style.background = '#059669';
        refreshIndicator.style.color = 'white';
        refreshIndicator.style.padding = '8px 16px';
        refreshIndicator.style.borderRadius = '6px';
        refreshIndicator.style.fontSize = '14px';
        refreshIndicator.style.zIndex = '1000';
        refreshIndicator.style.opacity = '0.9';
        document.body.appendChild(refreshIndicator);
        
        setTimeout(function() {
            window.location.reload();
        }, 1000);
    }
}, 60000); // 60 seconds

// Clear interval when page is hidden
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
        clearInterval(refreshInterval);
    } else if (document.visibilityState === 'visible') {
        refreshInterval = setInterval(function() {
            if (document.visibilityState === 'visible') {
                const refreshIndicator = document.createElement('div');
                refreshIndicator.innerHTML = '🔄 Updating...';
                refreshIndicator.style.position = 'fixed';
                refreshIndicator.style.top = '20px';
                refreshIndicator.style.right = '20px';
                refreshIndicator.style.background = '#059669';
                refreshIndicator.style.color = 'white';
                refreshIndicator.style.padding = '8px 16px';
                refreshIndicator.style.borderRadius = '6px';
                refreshIndicator.style.fontSize = '14px';
                refreshIndicator.style.zIndex = '1000';
                refreshIndicator.style.opacity = '0.9';
                document.body.appendChild(refreshIndicator);
                
                setTimeout(function() {
                    window.location.reload();
                }, 1000);
            }
        }, 60000);
    }
});

// Add last updated timestamp
const lastUpdated = document.createElement('div');
lastUpdated.innerHTML = 'Last updated: ' + new Date().toLocaleTimeString();
lastUpdated.style.position = 'fixed';
lastUpdated.style.bottom = '20px';
lastUpdated.style.right = '20px';
lastUpdated.style.background = 'rgba(0,0,0,0.7)';
lastUpdated.style.color = 'white';
lastUpdated.style.padding = '4px 8px';
lastUpdated.style.borderRadius = '4px';
lastUpdated.style.fontSize = '11px';
lastUpdated.style.zIndex = '999';
document.body.appendChild(lastUpdated);
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
