<?php
$SEO = [
    'title' => 'Global Pricing Manager — Admin',
    'description' => 'Manage global pricing factors and mass update all product prices',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';

// Get selected product group for filtering
$selectedGroupId = intval($_GET['group_id'] ?? 0);

// Handle pricing updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_global_settings') {
            // Update global pricing settings (fallback defaults)
            $settings = [
                'ingredient_cost_per_gram' => floatval($_POST['ingredient_cost'] ?? 0),
                'attribute_percentage' => floatval($_POST['attribute_percentage'] ?? 0),
                'bottle_cost' => floatval($_POST['bottle_cost'] ?? 0),
                'label_cost' => floatval($_POST['label_cost'] ?? 0),
                'labor_cost' => floatval($_POST['labor_cost'] ?? 0),
                'profit_multiplier' => floatval($_POST['profit_multiplier'] ?? 0),
                'mix_cost' => floatval($_POST['mix_cost'] ?? 0)
            ];
            
            $stmt = $pdo->prepare("UPDATE pricing_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            foreach ($settings as $key => $value) {
                $stmt->execute([$value, $key]);
            }
            
            $message = 'Global default pricing settings updated. Product groups without custom costs will use these defaults.';
            
        } elseif ($action === 'update_group_costs') {
            // Update product group specific costs
            $groupId = intval($_POST['group_id'] ?? 0);
            if ($groupId <= 0) throw new Exception('Invalid product group');
            
            $ingredientCost = !empty($_POST['group_ingredient_cost']) ? floatval($_POST['group_ingredient_cost']) : null;
            $mitPercentage = !empty($_POST['group_attribute_percentage']) ? floatval($_POST['group_attribute_percentage']) : null;
            $bottleCost = !empty($_POST['group_bottle_cost']) ? floatval($_POST['group_bottle_cost']) : null;
            $labelCost = !empty($_POST['group_label_cost']) ? floatval($_POST['group_label_cost']) : null;
            $laborCost = !empty($_POST['group_labor_cost']) ? floatval($_POST['group_labor_cost']) : null;
            $mixCost = !empty($_POST['group_mix_cost']) ? floatval($_POST['group_mix_cost']) : null;
            $profitMultiplier = !empty($_POST['group_profit_multiplier']) ? floatval($_POST['group_profit_multiplier']) : null;
            
            $stmt = $pdo->prepare("UPDATE product_groups SET 
                ingredient_cost_per_gram = ?, 
                attribute_percentage = ?,
                bottle_cost = ?,
                label_cost = ?,
                labor_cost = ?,
                mix_cost = ?,
                profit_multiplier = ?,
                updated_at = NOW()
                WHERE id = ?");
            $stmt->execute([$ingredientCost, $mitPercentage, $bottleCost, $labelCost, $laborCost, $mixCost, $profitMultiplier, $groupId]);
            
            $message = 'Product group costs updated. Click "Recalculate Group Prices" to apply.';
            $selectedGroupId = $groupId;
            
        } elseif ($action === 'recalculate_all') {
            // Mass recalculate all product prices (using global defaults)
            $reason = trim($_POST['reason'] ?? 'Mass price update from admin');
            $userId = $_SESSION['user_id'] ?? 1;
            
            $stmt = $pdo->prepare("CALL update_all_product_pricing(?, ?)");
            $stmt->execute([$userId, $reason]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); // Close cursor to clear pending result sets
            
            $message = 'Successfully recalculated prices for ' . ($result['products_updated'] ?? 0) . ' products using global defaults!';
            
        } elseif ($action === 'recalculate_group') {
            // Recalculate prices for specific product group
            $groupId = intval($_POST['group_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? 'Product group price update');
            $userId = $_SESSION['user_id'] ?? 1;
            
            if ($groupId <= 0) throw new Exception('Invalid product group');
            
            $stmt = $pdo->prepare("CALL update_product_group_pricing(?, ?, ?)");
            $stmt->execute([$groupId, $userId, $reason]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); // Close cursor to clear pending result sets
            
            $message = 'Successfully recalculated prices for ' . ($result['products_updated'] ?? 0) . ' products in this group!';
            $selectedGroupId = $groupId;
            
        } elseif ($action === 'recalculate_single') {
            $productId = intval($_POST['product_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? 'Individual price recalculation');
            $userId = $_SESSION['user_id'] ?? 1;
            
            $stmt = $pdo->prepare("CALL recalculate_product_price(?, ?, ?)");
            $stmt->execute([$productId, $userId, $reason]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); // Close cursor to clear pending result sets
            
            $message = 'Recalculated price for ' . ($result['product_sku'] ?? 'product') . ': $' . number_format($result['old_wholesale_price'] ?? 0, 2) . ' → $' . number_format($result['new_wholesale_price'] ?? 0, 2);
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
    
    // Redirect to prevent form resubmission (PRG pattern)
    if ($message || $error) {
        if ($message) flash_set('success', $message);
        if ($error) flash_set('error', $error);
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Fetch current global settings
try {
    $settingsQuery = $pdo->query("SELECT setting_key, setting_value, setting_unit, description, updated_at FROM pricing_settings ORDER BY id");
    $settings = [];
    while ($row = $settingsQuery->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row;
    }
} catch (Exception $e) {
    $settings = [];
    $error = 'Error loading settings: ' . $e->getMessage();
}

// Fetch all product groups
try {
    $productGroups = $pdo->query("
        SELECT pg.*, COUNT(DISTINCT p.id) as product_count
        FROM product_groups pg
        LEFT JOIN products p ON p.product_group_id = pg.id AND p.active = 1
        WHERE pg.active = 1
        GROUP BY pg.id
        ORDER BY pg.display_order ASC, pg.name ASC
    ")->fetchAll();
} catch (Exception $e) {
    $productGroups = [];
}

// Fetch selected product group data
$selectedGroup = null;
if ($selectedGroupId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM product_groups WHERE id = ?");
        $stmt->execute([$selectedGroupId]);
        $selectedGroup = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Fetch product pricing breakdown (filtered by selected group if applicable)
try {
    $sql = "
        SELECT 
            p.id,
            p.sku,
            p.name,
            p.attribute_per_oz,
            p.size_oz,
            p.material_cost,
            p.total_production_cost,
            p.bottle_cost,
            p.label_cost,
            p.labor_cost,
            p.mix_cost,
            p.wholesale_price,
            p.msrp,
            p.profit_margin_percent,
            p.cost_per_10mg_serving,
            p.product_group_id,
            pg.name as group_name,
            -- Get bulk pricing tiers from product_group_commissions
            (SELECT unit_price FROM bulk_pricing WHERE sku = p.sku AND quantity_tier_name = 'X1' LIMIT 1) as price_tier_1_5,
            (SELECT unit_price FROM bulk_pricing WHERE sku = p.sku AND quantity_tier_name = 'X10' LIMIT 1) as price_tier_6_10,
            (SELECT unit_price FROM bulk_pricing WHERE sku = p.sku AND quantity_tier_name = 'X15' LIMIT 1) as price_tier_15_plus
        FROM products p
        LEFT JOIN product_groups pg ON pg.id = p.product_group_id
        WHERE p.active = 1 
            AND p.attribute_per_oz IS NOT NULL 
            AND p.size_oz IS NOT NULL";
    
    if ($selectedGroupId > 0) {
        $sql .= " AND p.product_group_id = " . intval($selectedGroupId);
    }
    
    $sql .= " ORDER BY pg.display_order ASC, p.attribute_per_oz ASC";
    
    $productsQuery = $pdo->query($sql);
    $products = $productsQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
    $error = ($error ? $error . ' | ' : '') . 'Error loading products: ' . $e->getMessage();
}

// Calculate preview prices
$previewIngredientCost = floatval($_GET['preview_ingredient_cost'] ?? ($settings['ingredient_cost_per_gram']['setting_value'] ?? 3.40));
$previewMitPercent = floatval($_GET['preview_attribute_percent'] ?? ($settings['attribute_percentage']['setting_value'] ?? 62));
$previewMultiplier = floatval($_GET['preview_multiplier'] ?? ($settings['profit_multiplier']['setting_value'] ?? 5.0));
$previewBottleCost = floatval($_GET['preview_bottle_cost'] ?? ($settings['bottle_cost']['setting_value'] ?? 2.50));
$previewLabelCost = floatval($_GET['preview_label_cost'] ?? ($settings['label_cost']['setting_value'] ?? 2.50));
$previewLaborCost = floatval($_GET['preview_labor_cost'] ?? ($settings['labor_cost']['setting_value'] ?? 5.00));
$previewMixCost = floatval($_GET['preview_mix_cost'] ?? ($settings['mix_cost']['setting_value'] ?? 1.50));
?>

<style>
.pricing-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
.pricing-header { background: linear-gradient(135deg, #7c3aed, #a855f7); color: white; padding: 40px 0; margin-bottom: 32px; }
.settings-card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); margin-bottom: 24px; }
.settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0; }
.setting-input { width: 100%; }
.setting-input label { display: block; font-weight: 600; margin-bottom: 8px; color: #374151; }
.setting-input input { width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 16px; }
.setting-input small { display: block; color: #6b7280; margin-top: 4px; font-size: 12px; }
.btn-recalculate { background: #7c3aed; color: white; padding: 14px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; }
.btn-recalculate:hover { background: #6d28d9; }
.btn-preview { background: #3b82f6; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; margin-top: 12px; }
.products-comparison { margin-top: 32px; }
.comparison-table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
.comparison-table th { background: #f9fafb; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e5e7eb; }
.comparison-table td { padding: 12px; border-bottom: 1px solid #f3f4f6; }
.price-change { font-weight: 600; }
.price-increase { color: #dc2626; }
.price-decrease { color: #059669; }
.price-same { color: #6b7280; }
.audit-log { max-height: 300px; overflow-y: auto; background: #f9fafb; padding: 16px; border-radius: 8px; margin-top: 16px; }
.audit-entry { padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
.warning-banner { background: #fef3c7; border: 2px solid #f59e0b; color: #92400e; padding: 16px; border-radius: 8px; margin-bottom: 24px; }
</style>

<div class="pricing-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">💰 Pricing Manager</h1>
        <p style="font-size: 18px; opacity: 0.9;">Manage pricing by product group or set global defaults</p>
    </div>
</div>

<div class="pricing-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if ($message): ?>
        <div class="settings-card" style="background: #dcfce7; color: #14532d; border-left: 4px solid #059669;">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="settings-card" style="background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Product Group Selector -->
    <div class="settings-card" style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 2px solid #059669;">
        <h3 style="margin: 0 0 16px 0; color: #14532d;">Select Product Group to Manage</h3>
        <select onchange="window.location.href='?group_id=' + this.value" 
                style="width: 100%; padding: 12px; border: 2px solid #059669; border-radius: 8px; font-size: 16px; font-weight: 600;">
            <option value="0" <?php echo $selectedGroupId === 0 ? 'selected' : ''; ?>>Global Defaults (All Groups)</option>
            <?php foreach ($productGroups as $pg): ?>
            <option value="<?php echo $pg['id']; ?>" <?php echo $selectedGroupId == $pg['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($pg['name']); ?> 
                (<?php echo $pg['product_count']; ?> products)
                <?php if ($pg['has_product']): ?> [Product]<?php endif; ?>
                <?php if ($pg['has_cannabinoids']): ?> [Cannabinoids]<?php endif; ?>
            </option>
            <?php endforeach; ?>
        </select>
        <small style="color: #14532d; display: block; margin-top: 8px;">
            <?php if ($selectedGroupId > 0): ?>
                Managing costs for: <strong><?php echo htmlspecialchars($selectedGroup['name'] ?? 'Unknown'); ?></strong>
            <?php else: ?>
                Managing global default costs (used when product groups don't have custom costs)
            <?php endif; ?>
        </small>
    </div>
    
    <?php if ($selectedGroupId === 0): ?>
    <div class="warning-banner">
        <strong>⚠️ Global Defaults Mode:</strong> These settings apply to products in groups that don't have custom cost overrides. 
        To set unique costs for a specific product line (product, product, etc.), select that product group above.
    </div>
    <?php else: ?>
    <div style="background: #fef3c7; border: 2px solid #f59e0b; color: #92400e; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
        <strong>📦 Product Group Mode:</strong> Changes will only affect the <strong><?php echo htmlspecialchars($selectedGroup['name'] ?? ''); ?></strong> product group (<?php echo $selectedGroup['product_count'] ?? 0; ?> products). 
        Leave fields blank to use global defaults.
    </div>
    <?php endif; ?>
    
    <!-- Pricing Settings -->
    <div class="settings-card">
        <h2 style="margin-bottom: 20px;">
            🎛️ <?php echo $selectedGroupId > 0 ? htmlspecialchars($selectedGroup['name']) . ' - ' : ''; ?>Price Controllers
        </h2>
        <p style="color: #6b7280; margin-bottom: 20px;">
            <?php if ($selectedGroupId > 0): ?>
                Set custom costs for this product group. Leave blank to inherit from global defaults.
            <?php else: ?>
                These are fallback defaults used when product groups don't have custom costs.
            <?php endif; ?>
        </p>
        
        <form method="post" id="settings-form">
            <input type="hidden" name="action" value="<?php echo $selectedGroupId > 0 ? 'update_group_costs' : 'update_global_settings'; ?>">
            <?php if ($selectedGroupId > 0): ?>
            <input type="hidden" name="group_id" value="<?php echo $selectedGroupId; ?>">
            <?php endif; ?>
            
            <div class="settings-grid">
                <?php if ($selectedGroupId === 0 || $selectedGroup['has_product'] || !$selectedGroup['has_cannabinoids']): ?>
                <div class="setting-input">
                    <label>Ingredient Cost (per gram)</label>
                    <input type="number" name="<?php echo $selectedGroupId > 0 ? 'group_ingredient_cost' : 'ingredient_cost'; ?>" step="0.01" 
                           value="<?php echo $selectedGroupId > 0 && $selectedGroup['ingredient_cost_per_gram'] ? number_format($selectedGroup['ingredient_cost_per_gram'], 2, '.', '') : ($selectedGroupId === 0 ? number_format($settings['ingredient_cost_per_gram']['setting_value'] ?? 3.40, 2, '.', '') : ''); ?>" 
                           <?php echo $selectedGroupId === 0 ? 'required' : ''; ?>
                           placeholder="<?php echo $selectedGroupId > 0 ? 'Global: $' . number_format($settings['ingredient_cost_per_gram']['setting_value'] ?? 3.40, 2) : ''; ?>">
                    <small><?php echo $selectedGroupId > 0 ? 'Blank = use global default' : 'Raw ingredient cost per gram'; ?></small>
                </div>
                
                <div class="setting-input">
                    <label>attribute/Active Ingredient %</label>
                    <input type="number" name="<?php echo $selectedGroupId > 0 ? 'group_attribute_percentage' : 'attribute_percentage'; ?>" step="0.1" 
                           value="<?php echo $selectedGroupId > 0 && $selectedGroup['attribute_percentage'] ? number_format($selectedGroup['attribute_percentage'], 1, '.', '') : ($selectedGroupId === 0 ? number_format($settings['attribute_percentage']['setting_value'] ?? 62.0, 1, '.', '') : ''); ?>" 
                           <?php echo $selectedGroupId === 0 ? 'required' : ''; ?>
                           placeholder="<?php echo $selectedGroupId > 0 ? 'Global: ' . number_format($settings['attribute_percentage']['setting_value'] ?? 62.0, 1) . '%' : ''; ?>">
                    <small><?php echo $selectedGroupId > 0 ? 'Blank = use global default' : 'Percentage of active ingredient'; ?></small>
                </div>
                <?php endif; ?>
                
                <div class="setting-input">
                    <label>Profit Multiplier</label>
                    <input type="number" name="<?php echo $selectedGroupId > 0 ? 'group_profit_multiplier' : 'profit_multiplier'; ?>" step="0.1" 
                           value="<?php echo $selectedGroupId > 0 && $selectedGroup['profit_multiplier'] ? number_format($selectedGroup['profit_multiplier'], 1, '.', '') : ($selectedGroupId === 0 ? number_format($settings['profit_multiplier']['setting_value'] ?? 5.0, 1, '.', '') : ''); ?>" 
                           <?php echo $selectedGroupId === 0 ? 'required' : ''; ?>
                           placeholder="<?php echo $selectedGroupId > 0 ? 'Global: ' . number_format($settings['profit_multiplier']['setting_value'] ?? 5.0, 1) . 'x' : ''; ?>">
                    <small>Wholesale = Cost × multiplier</small>
                </div>
                
                <div class="setting-input">
                    <label>Bottle/Container Cost</label>
                    <input type="number" name="<?php echo $selectedGroupId > 0 ? 'group_bottle_cost' : 'bottle_cost'; ?>" step="0.01" 
                           value="<?php echo $selectedGroupId > 0 && $selectedGroup['bottle_cost'] ? number_format($selectedGroup['bottle_cost'], 2, '.', '') : ($selectedGroupId === 0 ? number_format($settings['bottle_cost']['setting_value'] ?? 2.50, 2, '.', '') : ''); ?>" 
                           <?php echo $selectedGroupId === 0 ? 'required' : ''; ?>
                           placeholder="<?php echo $selectedGroupId > 0 ? 'Global: $' . number_format($settings['bottle_cost']['setting_value'] ?? 2.50, 2) : ''; ?>">
                    <small>Per unit packaging</small>
                </div>
                
                <div class="setting-input">
                    <label>Label Cost</label>
                    <input type="number" name="<?php echo $selectedGroupId > 0 ? 'group_label_cost' : 'label_cost'; ?>" step="0.01" 
                           value="<?php echo $selectedGroupId > 0 && $selectedGroup['label_cost'] ? number_format($selectedGroup['label_cost'], 2, '.', '') : ($selectedGroupId === 0 ? number_format($settings['label_cost']['setting_value'] ?? 2.50, 2, '.', '') : ''); ?>" 
                           <?php echo $selectedGroupId === 0 ? 'required' : ''; ?>
                           placeholder="<?php echo $selectedGroupId > 0 ? 'Global: $' . number_format($settings['label_cost']['setting_value'] ?? 2.50, 2) : ''; ?>">
                    <small>Per label</small>
                </div>
                
                <div class="setting-input">
                    <label>Labor Cost</label>
                    <input type="number" name="<?php echo $selectedGroupId > 0 ? 'group_labor_cost' : 'labor_cost'; ?>" step="0.01" 
                           value="<?php echo $selectedGroupId > 0 && $selectedGroup['labor_cost'] ? number_format($selectedGroup['labor_cost'], 2, '.', '') : ($selectedGroupId === 0 ? number_format($settings['labor_cost']['setting_value'] ?? 5.00, 2, '.', '') : ''); ?>" 
                           <?php echo $selectedGroupId === 0 ? 'required' : ''; ?>
                           placeholder="<?php echo $selectedGroupId > 0 ? 'Global: $' . number_format($settings['labor_cost']['setting_value'] ?? 5.00, 2) : ''; ?>">
                    <small>Per unit labor</small>
                </div>
                
                <div class="setting-input">
                    <label>Mix/Prep Cost</label>
                    <input type="number" name="<?php echo $selectedGroupId > 0 ? 'group_mix_cost' : 'mix_cost'; ?>" step="0.01" 
                           value="<?php echo $selectedGroupId > 0 && $selectedGroup['mix_cost'] ? number_format($selectedGroup['mix_cost'], 2, '.', '') : ($selectedGroupId === 0 ? number_format($settings['mix_cost']['setting_value'] ?? 1.50, 2, '.', '') : ''); ?>" 
                           <?php echo $selectedGroupId === 0 ? 'required' : ''; ?>
                           placeholder="<?php echo $selectedGroupId > 0 ? 'Global: $' . number_format($settings['mix_cost']['setting_value'] ?? 1.50, 2) : ''; ?>">
                    <small>Per unit preparation</small>
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; margin-top: 24px;">
                <button type="submit" class="btn-recalculate">💾 Save <?php echo $selectedGroupId > 0 ? 'Group' : 'Global'; ?> Settings</button>
                <?php if ($selectedGroupId === 0): ?>
                <button type="button" class="btn-preview" onclick="previewPriceChanges()">👁️ Preview Impact</button>
                <?php endif; ?>
            </div>
        </form>
        
        <?php if ($selectedGroupId > 0 && $selectedGroup['updated_at']): ?>
        <div style="margin-top: 16px; padding: 12px; background: #f0fdf4; border-radius: 8px; border-left: 4px solid #059669;">
            <strong>Last Updated:</strong> <?php echo date('M d, Y g:i A', strtotime($selectedGroup['updated_at'])); ?>
        </div>
        <?php else: ?>
        <div style="margin-top: 16px; padding: 12px; background: #f0fdf4; border-radius: 8px; border-left: 4px solid #059669;">
            <strong>Last Updated:</strong> <?php echo date('M d, Y g:i A', strtotime($settings['ingredient_cost_per_gram']['updated_at'] ?? 'now')); ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Mass Price Update -->
    <div class="settings-card">
        <h2 style="margin-bottom: 16px;">🔄 Apply Price Changes</h2>
        <p style="color: #6b7280; margin-bottom: 20px;">
            <?php if ($selectedGroupId > 0): ?>
                Recalculate prices for all products in <strong><?php echo htmlspecialchars($selectedGroup['name'] ?? ''); ?></strong> using the costs above.
            <?php else: ?>
                Recalculate prices for ALL products across all product groups using global default costs.
            <?php endif; ?>
        </p>
        
        <form method="post" onsubmit="return confirm('<?php echo $selectedGroupId > 0 ? 'Recalculate prices for ' . ($selectedGroup['product_count'] ?? 0) . ' products in this group?' : 'This will recalculate prices for ALL active products. Are you sure?'; ?>');">
            <input type="hidden" name="action" value="<?php echo $selectedGroupId > 0 ? 'recalculate_group' : 'recalculate_all'; ?>">
            <?php if ($selectedGroupId > 0): ?>
            <input type="hidden" name="group_id" value="<?php echo $selectedGroupId; ?>">
            <?php endif; ?>
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Reason for Update (optional)</label>
                <input type="text" name="reason" placeholder="e.g., Supplier price increase, seasonal adjustment, etc." style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px;">
            </div>
            <button type="submit" class="btn-recalculate" style="background: #059669;">
                ⚡ Recalculate <?php echo $selectedGroupId > 0 ? 'Group' : 'All'; ?> Prices
            </button>
        </form>
    </div>
    
    <!-- Current Product Pricing Breakdown -->
    <div class="settings-card products-comparison">
        <h2 style="margin-bottom: 20px;">
            📊 Current Product Pricing 
            <?php if ($selectedGroupId > 0): ?>
            <span style="color: #6b7280; font-size: 16px; font-weight: normal;">
                (<?php echo count($products); ?> products in <?php echo htmlspecialchars($selectedGroup['name'] ?? ''); ?>)
            </span>
            <?php else: ?>
            <span style="color: #6b7280; font-size: 16px; font-weight: normal;">
                (<?php echo count($products); ?> total products)
            </span>
            <?php endif; ?>
        </h2>
        
        <?php if (empty($products)): ?>
        <div style="text-align: center; padding: 40px; background: #f9fafb; border-radius: 8px;">
            <p style="color: #6b7280;">No products found<?php echo $selectedGroupId > 0 ? ' in this product group' : ''; ?>.</p>
        </div>
        <?php else: ?>
        <div class="comparison-table" style="overflow-x: auto;">
            <table style="width: 100%; min-width: 1200px;">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>attribute/oz</th>
                        <th>Size</th>
                        <th>Material</th>
                        <th>Bottle+Label+Labor</th>
                        <th>Total Cost</th>
                        <th>Wholesale</th>
                        <th>Margin %</th>
                        <th>Cost/10mg</th>
                        <th>Tier 1-5</th>
                        <th>Tier 6-10</th>
                        <th>Tier 15+</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): 
                        $fixedCosts = ($product['bottle_cost'] ?? 0) + ($product['label_cost'] ?? 0) + ($product['labor_cost'] ?? 0) + ($product['mix_cost'] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                            <br><small style="color: #6b7280;"><?php echo $product['sku']; ?></small>
                        </td>
                        <td><?php echo number_format($product['attribute_per_oz'], 0); ?>mg</td>
                        <td><?php echo number_format($product['size_oz'], 0); ?>oz</td>
                        <td style="color: #dc2626; font-weight: 600;">$<?php echo number_format($product['material_cost'] ?? 0, 2); ?></td>
                        <td style="color: #6b7280;">$<?php echo number_format($fixedCosts, 2); ?></td>
                        <td style="font-weight: 600;">$<?php echo number_format($product['total_production_cost'] ?? 0, 2); ?></td>
                        <td style="color: #059669; font-weight: 600; font-size: 16px;">$<?php echo number_format($product['wholesale_price'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($product['profit_margin_percent'] ?? 0, 0); ?>%</td>
                        <td>$<?php echo number_format($product['cost_per_10mg_serving'] ?? 0, 2); ?></td>
                        <td style="background: #f0fdf4;">$<?php echo number_format($product['price_tier_1_5'] ?? 0, 2); ?></td>
                        <td style="background: #fef3c7;">$<?php echo number_format($product['price_tier_6_10'] ?? 0, 2); ?></td>
                        <td style="background: #fee2e2;">$<?php echo number_format($product['price_tier_15_plus'] ?? 0, 2); ?></td>
                        <td>
                            <form method="post" style="display: inline;" onsubmit="return confirm('Recalculate price for this product?');">
                                <input type="hidden" name="action" value="recalculate_single">
                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                <button type="submit" style="padding: 4px 12px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">🔄 Recalc</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Recent Price Changes Audit Log -->
    <div class="settings-card">
        <h2 style="margin-bottom: 16px;">📋 Recent Price Changes</h2>
        <div class="audit-log">
            <?php
            try {
                $auditQuery = $pdo->query("
                    SELECT 
                        pcl.*,
                        u.first_name,
                        u.last_name
                    FROM price_change_log pcl
                    LEFT JOIN users u ON u.id = pcl.changed_by_user_id
                    ORDER BY pcl.created_at DESC
                    LIMIT 20
                ");
                
                while ($log = $auditQuery->fetch(PDO::FETCH_ASSOC)) {
                    $userName = ($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '') ?: 'System';
                    $changeIcon = $log['change_type'] === 'mass_update' ? '⚡' : '🔄';
                    echo '<div class="audit-entry">';
                    echo '<strong>' . $changeIcon . ' ' . ucwords(str_replace('_', ' ', $log['change_type'])) . '</strong> ';
                    if ($log['product_sku']) echo '(' . htmlspecialchars($log['product_sku']) . ') ';
                    if ($log['old_value'] && $log['new_value']) {
                        echo '$' . number_format($log['old_value'], 2) . ' → $' . number_format($log['new_value'], 2);
                    }
                    echo '<br><small style="color: #6b7280;">By ' . htmlspecialchars($userName) . ' on ' . date('M d, Y g:i A', strtotime($log['created_at'])) . '</small>';
                    if ($log['reason']) echo '<br><small style="color: #6b7280;">Reason: ' . htmlspecialchars($log['reason']) . '</small>';
                    echo '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="audit-entry" style="color: #dc2626;">Error loading audit log</div>';
            }
            ?>
        </div>
    </div>
</div>

<script>
function previewPriceChanges() {
    const form = document.getElementById('settings-form');
    const formData = new FormData(form);
    
    // Build preview URL with current form values
    const params = new URLSearchParams();
    params.append('preview_ingredient_cost', formData.get('ingredient_cost'));
    params.append('preview_attribute_percent', formData.get('attribute_percentage'));
    params.append('preview_multiplier', formData.get('profit_multiplier'));
    params.append('preview_bottle_cost', formData.get('bottle_cost'));
    params.append('preview_label_cost', formData.get('label_cost'));
    params.append('preview_labor_cost', formData.get('labor_cost'));
    params.append('preview_mix_cost', formData.get('mix_cost'));
    
    // Open preview in modal or new window
    window.open('<?php echo path_with_base('/admin/pricing-preview.php'); ?>?' + params.toString(), 'pricing_preview', 'width=1200,height=800');
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

