<?php
$SEO = [
    'title' => 'Product Groups — Admin',
    'description' => 'Manage product groups and their commission structures.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';

// Handle product group actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'save_group':
                $groupId = intval($_POST['group_id'] ?? 0);
                $groupKey = trim($_POST['group_key'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $displayOrder = intval($_POST['display_order'] ?? 0);
                $active = isset($_POST['active']) ? 1 : 0;
                $useCustomCommission = isset($_POST['use_custom_commission']) ? 1 : 0;
                
                // NEW: Active ingredient flags
                $hasProduct = isset($_POST['has_product']) ? 1 : 0;
                $hasCannabinoids = isset($_POST['has_cannabinoids']) ? 1 : 0;
                $hasRecipeSystem = isset($_POST['has_recipe_system']) ? 1 : 0;
                
                // NEW: Category and formula type
                $productCategory = trim($_POST['product_category'] ?? '');
                $costFormulaType = trim($_POST['cost_formula_type'] ?? 'active-ingredient-based');
                
                // NEW: Cost structure
                $ingredientCost = !empty($_POST['ingredient_cost_per_gram']) ? floatval($_POST['ingredient_cost_per_gram']) : null;
                $mitPercentage = !empty($_POST['attribute_percentage']) ? floatval($_POST['attribute_percentage']) : null;
                $bottleCost = !empty($_POST['bottle_cost']) ? floatval($_POST['bottle_cost']) : null;
                $labelCost = !empty($_POST['label_cost']) ? floatval($_POST['label_cost']) : null;
                $laborCost = !empty($_POST['labor_cost']) ? floatval($_POST['labor_cost']) : null;
                $mixCost = !empty($_POST['mix_cost']) ? floatval($_POST['mix_cost']) : null;
                $profitMultiplier = !empty($_POST['profit_multiplier']) ? floatval($_POST['profit_multiplier']) : null;
                
                // NEW: Cannabinoid defaults
                $defaultThcaPercent = !empty($_POST['default_thca_percent']) ? floatval($_POST['default_thca_percent']) : null;
                $defaultThcD9Percent = !empty($_POST['default_thc_d9_percent']) ? floatval($_POST['default_thc_d9_percent']) : null;
                $defaultThcD8Percent = !empty($_POST['default_thc_d8_percent']) ? floatval($_POST['default_thc_d8_percent']) : null;
                $defaultCbdPercent = !empty($_POST['default_cbd_percent']) ? floatval($_POST['default_cbd_percent']) : null;
                $defaultCbgPercent = !empty($_POST['default_cbg_percent']) ? floatval($_POST['default_cbg_percent']) : null;
                $defaultCbnPercent = !empty($_POST['default_cbn_percent']) ? floatval($_POST['default_cbn_percent']) : null;
                
                // NEW: Custom cost factors (from hidden JSON field populated by JS)
                $customCostFactorsJson = !empty($_POST['custom_cost_factors_json']) ? $_POST['custom_cost_factors_json'] : null;
                
                $templateSourceId = !empty($_POST['template_source_id']) ? intval($_POST['template_source_id']) : null;
                
                if ($groupKey === '' || $name === '') {
                    throw new Exception('Group key and name are required');
                }
                
                if ($groupId > 0) {
                    // Update existing group
                    $stmt = $pdo->prepare("UPDATE product_groups SET 
                        group_key = ?, name = ?, description = ?, display_order = ?, active = ?, 
                        use_custom_commission = ?,
                        has_product = ?, has_cannabinoids = ?, has_recipe_system = ?,
                        product_category = ?, cost_formula_type = ?,
                        ingredient_cost_per_gram = ?, attribute_percentage = ?,
                        bottle_cost = ?, label_cost = ?, labor_cost = ?, mix_cost = ?,
                        profit_multiplier = ?,
                        default_thca_percent = ?, default_thc_d9_percent = ?, default_thc_d8_percent = ?,
                        default_cbd_percent = ?, default_cbg_percent = ?, default_cbn_percent = ?,
                        custom_cost_factors = ?, template_source_id = ?,
                        updated_at = NOW() 
                        WHERE id = ?");
                    $stmt->execute([
                        $groupKey, $name, $description, $displayOrder, $active, 
                        $useCustomCommission,
                        $hasProduct, $hasCannabinoids, $hasRecipeSystem,
                        $productCategory, $costFormulaType,
                        $ingredientCost, $mitPercentage,
                        $bottleCost, $labelCost, $laborCost, $mixCost,
                        $profitMultiplier,
                        $defaultThcaPercent, $defaultThcD9Percent, $defaultThcD8Percent,
                        $defaultCbdPercent, $defaultCbgPercent, $defaultCbnPercent,
                        $customCostFactorsJson, $templateSourceId,
                        $groupId
                    ]);
                    $message = 'Product group updated successfully';
                } else {
                    // Create new group
                    $stmt = $pdo->prepare("INSERT INTO product_groups 
                        (group_key, name, description, display_order, active, use_custom_commission,
                         has_product, has_cannabinoids, has_recipe_system, product_category, cost_formula_type,
                         ingredient_cost_per_gram, attribute_percentage,
                         bottle_cost, label_cost, labor_cost, mix_cost, profit_multiplier,
                         default_thca_percent, default_thc_d9_percent, default_thc_d8_percent,
                         default_cbd_percent, default_cbg_percent, default_cbn_percent,
                         custom_cost_factors, template_source_id, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $groupKey, $name, $description, $displayOrder, $active, $useCustomCommission,
                        $hasProduct, $hasCannabinoids, $hasRecipeSystem, $productCategory, $costFormulaType,
                        $ingredientCost, $mitPercentage,
                        $bottleCost, $labelCost, $laborCost, $mixCost, $profitMultiplier,
                        $defaultThcaPercent, $defaultThcD9Percent, $defaultThcD8Percent,
                        $defaultCbdPercent, $defaultCbgPercent, $defaultCbnPercent,
                        $customCostFactorsJson, $templateSourceId
                    ]);
                    $message = 'Product group created successfully';
                }
                break;
                
            case 'save_commission_tiers':
                $groupId = intval($_POST['group_id'] ?? 0);
                if ($groupId <= 0) throw new Exception('Invalid group ID');
                
                // Delete existing tiers for this group
                $stmt = $pdo->prepare('DELETE FROM product_group_commissions WHERE product_group_id = ?');
                $stmt->execute([$groupId]);
                
                // Insert new tiers
                $tiers = json_decode($_POST['tiers_json'] ?? '[]', true);
                if (!empty($tiers)) {
                    $stmt = $pdo->prepare("INSERT INTO product_group_commissions (product_group_id, tier_name, quantity_min, quantity_max, bulk_discount_percent, commission_percent, active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
                    foreach ($tiers as $tier) {
                        $stmt->execute([
                            $groupId,
                            $tier['name'],
                            intval($tier['min']),
                            $tier['max'] !== '' ? intval($tier['max']) : null,
                            floatval($tier['discount']),
                            floatval($tier['commission'])
                        ]);
                    }
                }
                
                $message = 'Commission tiers updated successfully';
                break;
                
            case 'delete_group':
                $groupId = intval($_POST['group_id'] ?? 0);
                if ($groupId <= 0) throw new Exception('Invalid group ID');
                
                // Check if any products are in this group
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE product_group_id = ?');
                $stmt->execute([$groupId]);
                $productCount = $stmt->fetchColumn();
                
                if ($productCount > 0) {
                    throw new Exception("Cannot delete group: $productCount product(s) are assigned to it");
                }
                
                // Delete commission tiers first
                $stmt = $pdo->prepare('DELETE FROM product_group_commissions WHERE product_group_id = ?');
                $stmt->execute([$groupId]);
                
                // Delete group
                $stmt = $pdo->prepare('DELETE FROM product_groups WHERE id = ?');
                $stmt->execute([$groupId]);
                
                $message = 'Product group deleted successfully';
                break;
                
            case 'assign_products':
                $groupId = intval($_POST['group_id'] ?? 0);
                $productIds = $_POST['product_ids'] ?? [];
                
                if ($groupId <= 0) throw new Exception('Invalid group ID');
                
                // Update products
                if (!empty($productIds)) {
                    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                    $stmt = $pdo->prepare("UPDATE products SET product_group_id = ?, updated_at = NOW() WHERE id IN ($placeholders)");
                    $params = array_merge([$groupId], $productIds);
                    $stmt->execute($params);
                    $message = count($productIds) . ' product(s) assigned to group';
                } else {
                    throw new Exception('No products selected');
                }
                break;
                
            case 'unassign_products':
                $productIds = $_POST['product_ids'] ?? [];
                
                if (!empty($productIds)) {
                    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                    $stmt = $pdo->prepare("UPDATE products SET product_group_id = NULL, updated_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($productIds);
                    $message = count($productIds) . ' product(s) removed from group';
                } else {
                    throw new Exception('No products selected');
                }
                break;
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch all product groups
$groups = [];
try {
    $stmt = $pdo->query("
        SELECT 
            pg.*,
            COUNT(DISTINCT p.id) as product_count,
            COUNT(DISTINCT pgc.id) as tier_count
        FROM product_groups pg
        LEFT JOIN products p ON p.product_group_id = pg.id
        LEFT JOIN product_group_commissions pgc ON pgc.product_group_id = pg.id
        GROUP BY pg.id
        ORDER BY pg.display_order ASC, pg.name ASC
    ");
    $groups = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = 'Error loading groups: ' . $e->getMessage();
}

// Fetch ungrouped products
$ungroupedProducts = [];
try {
    $stmt = $pdo->query("SELECT id, sku, name, wholesale_price FROM products WHERE product_group_id IS NULL AND active = 1 ORDER BY name");
    $ungroupedProducts = $stmt->fetchAll();
} catch (Throwable $e) {}
?>

<style>
.admin-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.groups-grid {
    display: grid;
    gap: 24px;
    margin-bottom: 32px;
}
.group-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    border: 2px solid #e5e7eb;
    overflow: hidden;
}
.group-header {
    background: linear-gradient(135deg, #059669, #10b981);
    color: white;
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.group-body {
    padding: 24px;
}
.tier-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr 80px;
    gap: 12px;
    margin-bottom: 12px;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
    align-items: center;
}
.product-list {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px;
}
.product-item {
    padding: 8px;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.badge-success { background: #dcfce7; color: #14532d; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-info { background: #dbeafe; color: #1e40af; }
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal.active { display: flex; }
.modal-content {
    background: white;
    border-radius: 12px;
    max-width: 800px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}
.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-body {
    padding: 24px;
}
</style>

<div class="admin-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Product Groups & Commission Management</h1>
        <p style="font-size: 18px; opacity: 0.9;">Organize products and configure commission structures</p>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php 
    // Check for flash messages
    $flashSuccess = flash_get('success');
    $flashError = flash_get('error');
    ?>
    
    <?php if ($message || $flashSuccess): ?>
        <div class="card" style="background: #dcfce7; color: #14532d; margin-bottom: 24px;">
            ✅ <?php echo htmlspecialchars($message ?: $flashSuccess); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error || $flashError): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error ?: $flashError); ?>
        </div>
    <?php endif; ?>
    
    <!-- Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <a href="<?php echo path_with_base('/admin/products.php'); ?>" class="btn secondary">← Back to Products</a>
        </div>
        <div style="display: flex; gap: 12px;">
            <a href="<?php echo path_with_base('/admin/product-group-editor.php'); ?>" class="btn" style="background: var(--green); color: white; text-decoration: none; display: inline-block;">
                + Create New Group
            </a>
        </div>
    </div>
    
    <!-- Product Groups -->
    <div class="groups-grid">
        <?php foreach ($groups as $group): ?>
        <div class="group-card">
            <div class="group-header">
                <div>
                    <h3 style="margin: 0; font-size: 22px;"><?php echo htmlspecialchars($group['name']); ?></h3>
                    <p style="margin: 4px 0 0 0; opacity: 0.9; font-size: 14px;">
                        Key: <code style="background: rgba(255,255,255,0.2); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($group['group_key']); ?></code>
                    </p>
                </div>
                <div>
                    <?php if ($group['active']): ?>
                        <span class="badge badge-success">Active</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="group-body">
                <?php if ($group['description']): ?>
                    <p style="color: #6b7280; margin-bottom: 20px;"><?php echo htmlspecialchars($group['description']); ?></p>
                <?php endif; ?>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <div style="font-size: 24px; font-weight: 700; color: #059669;"><?php echo $group['product_count']; ?></div>
                        <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Products</div>
                    </div>
                    <div>
                        <div style="font-size: 24px; font-weight: 700; color: #3b82f6;"><?php echo $group['tier_count']; ?></div>
                        <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Commission Tiers</div>
                    </div>
                </div>
                
                <!-- Commission Tiers -->
                <?php
                $stmt = $pdo->prepare("SELECT * FROM product_group_commissions WHERE product_group_id = ? ORDER BY quantity_min ASC");
                $stmt->execute([$group['id']]);
                $tiers = $stmt->fetchAll();
                ?>
                
                <?php if (!empty($tiers)): ?>
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #374151;">Commission Structure</h4>
                        <div style="background: #f9fafb; border-radius: 8px; padding: 12px;">
                            <?php foreach ($tiers as $tier): ?>
                            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                                <div>
                                    <strong><?php echo htmlspecialchars($tier['tier_name']); ?></strong>
                                    <span style="color: #6b7280; margin-left: 8px;">
                                        (<?php echo $tier['quantity_min']; ?><?php echo $tier['quantity_max'] ? '-' . $tier['quantity_max'] : '+'; ?> items)
                                    </span>
                                </div>
                                <div style="display: flex; gap: 16px;">
                                    <div>
                                        <span style="color: #6b7280; font-size: 12px;">Discount:</span>
                                        <strong style="color: #dc2626;"><?php echo number_format($tier['bulk_discount_percent'], 1); ?>%</strong>
                                    </div>
                                    <div>
                                        <span style="color: #6b7280; font-size: 12px;">Commission:</span>
                                        <strong style="color: #059669;"><?php echo number_format($tier['commission_percent'], 1); ?>%</strong>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button class="btn small" onclick="openEditTiersModal(<?php echo $group['id']; ?>)">
                        ⚙️ Edit Commission Tiers
                    </button>
                    <button class="btn small secondary" onclick="openAssignProductsModal(<?php echo $group['id']; ?>)">
                        📦 Manage Products
                    </button>
                    <a href="<?php echo path_with_base('/admin/product-group-editor.php?id=' . $group['id']); ?>" class="btn small secondary" style="text-decoration: none; display: inline-block;">
                        ✏️ Edit Group
                    </a>
                    <a href="<?php echo path_with_base('/admin/product-group-editor.php?clone=' . $group['id']); ?>" class="btn small secondary" style="text-decoration: none; display: inline-block; background: #3b82f6; color: white;">
                        📋 Clone
                    </a>
                    <form method="post" style="display: inline;" onsubmit="return confirm('Delete this product group? Products will be unassigned.');">
                        <input type="hidden" name="action" value="delete_group">
                        <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                        <button type="submit" class="btn small" style="background: #dc2626; color: white;">
                            🗑️ Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($groups)): ?>
        <div class="card" style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 48px; margin-bottom: 16px;">📦</div>
            <h3>No Product Groups Yet</h3>
            <p style="color: #6b7280;">Create your first product group to organize products and manage commission structures.</p>
            <a href="<?php echo path_with_base('/admin/product-group-editor.php'); ?>" class="btn" style="background: var(--green); color: white; margin-top: 16px; text-decoration: none; display: inline-block;">
                Create First Group
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Ungrouped Products -->
    <?php if (!empty($ungroupedProducts)): ?>
    <div class="card" style="margin-top: 32px;">
        <h3 style="margin: 0 0 16px 0;">⚠️ Ungrouped Products (<?php echo count($ungroupedProducts); ?>)</h3>
        <p style="color: #6b7280; margin-bottom: 16px;">These products are not assigned to any group and use default commission settings:</p>
        <div class="product-list">
            <?php foreach ($ungroupedProducts as $product): ?>
            <div class="product-item">
                <div>
                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                    <span style="color: #6b7280; margin-left: 8px; font-size: 12px;"><?php echo htmlspecialchars($product['sku']); ?></span>
                </div>
                <div style="color: #6b7280;">$<?php echo number_format($product['wholesale_price'], 2); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Create/Edit Group Modal -->
<div id="groupModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="groupModalTitle" style="margin: 0;">Create Product Group</h3>
            <button onclick="closeGroupModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="groupForm">
                <input type="hidden" name="action" value="save_group">
                <input type="hidden" name="group_id" id="group_id">
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Group Key</label>
                    <input type="text" name="group_key" id="group_key" required 
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"
                           placeholder="e.g., product-products">
                    <small style="color: #6b7280;">Unique identifier (lowercase, hyphens allowed)</small>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Display Name</label>
                    <input type="text" name="name" id="group_name" required 
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"
                           placeholder="e.g., Products">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Description</label>
                    <textarea name="description" id="group_description" rows="3"
                              style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"
                              placeholder="Optional description of this product group"></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Display Order</label>
                        <input type="number" name="display_order" id="display_order" value="0" min="0"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <div style="display: flex; align-items: end;">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="active" id="group_active" checked>
                            <span>Active</span>
                        </label>
                    </div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="use_custom_commission" id="use_custom_commission" checked>
                        <span>Use custom commission structure (recommended)</span>
                    </label>
                    <small style="color: #6b7280;">If unchecked, products will use global default commission settings</small>
                </div>
                
                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
                
                <!-- NEW SECTION 2: Product Category & Active Ingredients -->
                <div style="margin-bottom: 24px;">
                    <h4 style="margin: 0 0 12px 0;">Product Category & Active Ingredients</h4>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Product Category</label>
                        <select name="product_category" id="product_category" onchange="updateCategoryDefaults()"
                                style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="">Select category...</option>
                            <option value="products">Product/product products</option>
                            <option value="gummies">Gummies</option>
                            <option value="creams">Topical Creams/Balms</option>
                            <option value="vitamins">Vitamins/Supplements</option>
                            <option value="drinks">Beverages (Non-Infused)</option>
                            <option value="apparel">Apparel/Merchandise</option>
                            <option value="custom">Custom/Other</option>
                        </select>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; padding: 12px; background: #f9fafb; border-radius: 8px; cursor: pointer;">
                            <input type="checkbox" name="has_product" id="has_product" onchange="toggleProductFields()">
                            <div>
                                <strong>Contains Product</strong>
                                <small style="display: block; color: #6b7280;">Enable attribute product specification tracking</small>
                            </div>
                        </label>
                        
                        <label style="display: flex; align-items: center; gap: 8px; padding: 12px; background: #f9fafb; border-radius: 8px; cursor: pointer;">
                            <input type="checkbox" name="has_cannabinoids" id="has_cannabinoids" onchange="toggleCannabinoidFields()">
                            <div>
                                <strong>Contains Cannabinoids</strong>
                                <small style="display: block; color: #6b7280;">Enable THC/product/CBN/CBG tracking</small>
                            </div>
                        </label>
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Cost Formula Type</label>
                        <select name="cost_formula_type" id="cost_formula_type"
                                style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="active-ingredient-based">Active Ingredient Formula (calculated per mg)</option>
                            <option value="fixed-cost">Fixed Per-Unit Cost</option>
                            <option value="simple-markup">Simple Cost + Markup</option>
                            <option value="custom">Custom Formula</option>
                        </select>
                    </div>
                </div>
                
                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
                
                <!-- NEW SECTION 3: Cost Structure Builder -->
                <div style="margin-bottom: 24px;">
                    <h4 style="margin: 0 0 12px 0;">Cost Structure</h4>
                    
                    <!-- Product-specific costs (shown if has_product checked) -->
                    <div id="product-costs" style="display: none; margin-bottom: 16px; padding: 16px; background: #fef3c7; border-radius: 8px;">
                        <h5 style="margin: 0 0 12px 0;">Product Ingredient Costs</h5>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 8px;">
                            <label>
                                Product Cost per Gram
                                <input type="number" name="ingredient_cost_per_gram" step="0.01" placeholder="e.g., 3.40"
                                       style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                            <label>
                                attribute % in Raw Product
                                <input type="number" name="attribute_percentage" step="0.01" placeholder="e.g., 62"
                                       style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                        </div>
                        <small style="color: #92400e;">Formula: total_mg ÷ (attribute_% × 10) × cost_per_gram</small>
                    </div>
                    
                    <!-- Cannabinoid-specific costs (shown if has_cannabinoids checked) -->
                    <div id="cannabinoid-costs" style="display: none; margin-bottom: 16px; padding: 16px; background: #dbeafe; border-radius: 8px;">
                        <h5 style="margin: 0 0 12px 0;">Cannabinoid Ingredient Costs & Default Potencies</h5>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                            <label>
                                Default THCA %
                                <input type="number" name="default_thca_percent" step="0.01"
                                       style="width: 100%; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                            <label>
                                Default Delta-9 THC %
                                <input type="number" name="default_thc_d9_percent" step="0.01"
                                       style="width: 100%; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                            <label>
                                Default Delta-8 THC %
                                <input type="number" name="default_thc_d8_percent" step="0.01"
                                       style="width: 100%; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                            <label>
                                Default product %
                                <input type="number" name="default_cbd_percent" step="0.01"
                                       style="width: 100%; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                            <label>
                                Default CBG %
                                <input type="number" name="default_cbg_percent" step="0.01"
                                       style="width: 100%; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                            <label>
                                Default CBN %
                                <input type="number" name="default_cbn_percent" step="0.01"
                                       style="width: 100%; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                        </div>
                        <small style="color: #1e40af;">These defaults apply to all products in this group (can be overridden per product)</small>
                    </div>
                    
                    <!-- Common costs (all product types) -->
                    <div style="margin-bottom: 16px;">
                        <h5 style="margin: 0 0 12px 0;">Packaging & Production Costs</h5>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <label>
                                Container/Bottle Cost
                                <input type="number" name="bottle_cost" step="0.01" placeholder="2.50"
                                       style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                            <label>
                                Label/Packaging Cost
                                <input type="number" name="label_cost" step="0.01" placeholder="2.50"
                                       style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                            <label>
                                Labor Cost per Unit
                                <input type="number" name="labor_cost" step="0.01" placeholder="5.00"
                                       style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                            <label>
                                Mix/Prep Cost
                                <input type="number" name="mix_cost" step="0.01" placeholder="1.50"
                                       style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                            </label>
                        </div>
                    </div>
                    
                    <!-- Custom cost factors -->
                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <h5 style="margin: 0;">Custom Cost Factors</h5>
                            <button type="button" class="btn-small secondary" onclick="addCustomCostFactor()">+ Add Factor</button>
                        </div>
                        <div id="custom-cost-factors-container">
                            <small style="color: #6b7280;">Add custom cost factors like "cream_base", "special_packaging", etc.</small>
                        </div>
                        <input type="hidden" name="custom_cost_factors_json" id="custom_cost_factors_json">
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <label>
                            Profit Multiplier
                            <input type="number" name="profit_multiplier" step="0.1" placeholder="5.0"
                                   style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 4px;">
                        </label>
                        <small style="color: #6b7280; display: block; margin-top: 4px;">Wholesale Price = Total Cost × Multiplier</small>
                    </div>
                    
                    <button type="button" class="btn secondary" onclick="testFormula()">Test Formula Calculator</button>
                </div>
                
                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
                
                <!-- NEW SECTION 4: Manufacturing & Operations -->
                <div style="margin-bottom: 24px;">
                    <h4 style="margin: 0 0 12px 0;">Manufacturing & Operations</h4>
                    <label style="display: flex; align-items: center; gap: 8px; padding: 12px; background: #f0fdf4; border-radius: 8px; cursor: pointer;">
                        <input type="checkbox" name="has_recipe_system" id="has_recipe_system">
                        <div>
                            <strong>Enable Recipe/Manufacturing System</strong>
                            <small style="display: block; color: #6b7280;">Track ingredients, packaging, labor, and work orders for this product group</small>
                        </div>
                    </label>
                    <small style="color: #6b7280; display: block; margin-top: 8px;">Can be enabled later. When enabled, full manufacturing operations become available.</small>
                </div>
                
                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
                
                <!-- NEW SECTION 5: Clone from Template -->
                <div style="margin-bottom: 24px; padding: 16px; background: #fef3c7; border-radius: 8px;">
                    <h4 style="margin: 0 0 12px 0;">Or Clone Existing Group Setup</h4>
                    <select id="template_group" onchange="cloneGroupSetup(this.value)"
                            style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 8px;">
                        <option value="">Start from scratch</option>
                        <?php
                        $templateGroups = $pdo->query("SELECT id, name, has_product, has_cannabinoids, product_category FROM product_groups WHERE active = 1 ORDER BY name")->fetchAll();
                        foreach ($templateGroups as $tg):
                            $badges = [];
                            if ($tg['has_product']) $badges[] = 'Product';
                            if ($tg['has_cannabinoids']) $badges[] = 'Cannabinoids';
                            $badgeText = !empty($badges) ? ' (' . implode(' + ', $badges) . ')' : '';
                        ?>
                        <option value="<?php echo $tg['id']; ?>">
                            <?php echo htmlspecialchars($tg['name']); ?><?php echo $badgeText; ?><?php echo $tg['product_category'] ? ' - ' . htmlspecialchars($tg['product_category']) : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #92400e;">Pre-fills all costs, commission tiers, and active ingredient settings from selected group</small>
                    <input type="hidden" name="template_source_id" id="template_source_id">
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" class="btn secondary" onclick="closeGroupModal()">Cancel</button>
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Save Group</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Commission Tiers Modal -->
<div id="tiersModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="margin: 0;">Edit Commission Tiers</h3>
            <button onclick="closeTiersModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="tiersForm">
                <input type="hidden" name="action" value="save_commission_tiers">
                <input type="hidden" name="group_id" id="tiers_group_id">
                <input type="hidden" name="tiers_json" id="tiers_json">
                
                <p style="color: #6b7280; margin-bottom: 16px;">
                    Define commission and discount tiers based on order quantity. Lower quantities typically get higher commissions and lower discounts.
                </p>
                
                <div id="tiersContainer"></div>
                
                <button type="button" class="btn secondary" style="margin-top: 12px;" onclick="addTierRow()">
                    + Add Tier
                </button>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                    <button type="button" class="btn secondary" onclick="closeTiersModal()">Cancel</button>
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Save Tiers</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Products Modal -->
<div id="assignModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="margin: 0;">Manage Group Products</h3>
            <button onclick="closeAssignModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <div style="margin-bottom: 24px;">
                <h4 style="margin: 0 0 12px 0;">Current Products in Group</h4>
                <div id="currentProducts" style="max-height: 200px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;"></div>
            </div>
            
            <form method="post" id="assignForm">
                <input type="hidden" name="action" value="assign_products">
                <input type="hidden" name="group_id" id="assign_group_id">
                
                <h4 style="margin: 0 0 12px 0;">Add Products to Group</h4>
                <div style="max-height: 300px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                    <?php foreach ($ungroupedProducts as $product): ?>
                    <label style="display: block; padding: 8px; border-bottom: 1px solid #f3f4f6;">
                        <input type="checkbox" name="product_ids[]" value="<?php echo $product['id']; ?>">
                        <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                        <span style="color: #6b7280; margin-left: 8px; font-size: 12px;">
                            <?php echo htmlspecialchars($product['sku']); ?> - $<?php echo number_format($product['wholesale_price'], 2); ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                    <button type="button" class="btn secondary" onclick="closeAssignModal()">Close</button>
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Assign Selected</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Group Modal Management
function openCreateGroupModal() {
    document.getElementById('groupModalTitle').textContent = 'Create Product Group';
    document.getElementById('groupForm').reset();
    document.getElementById('group_id').value = '';
    document.getElementById('group_active').checked = true;
    document.getElementById('use_custom_commission').checked = true;
    document.getElementById('groupModal').classList.add('active');
}

function openEditGroupModal(groupId) {
    // Fetch group data with costs
    fetch(`<?php echo path_with_base('/api/get-product-group.php'); ?>?id=${groupId}&include_costs=true`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('groupModalTitle').textContent = 'Edit Product Group';
            document.getElementById('group_id').value = data.id;
            document.getElementById('group_key').value = data.group_key;
            document.getElementById('group_name').value = data.name;
            document.getElementById('group_description').value = data.description || '';
            document.getElementById('display_order').value = data.display_order;
            document.getElementById('group_active').checked = data.active == 1;
            document.getElementById('use_custom_commission').checked = data.use_custom_commission == 1;
            
            // Load new fields
            if (data.product_category) document.getElementById('product_category').value = data.product_category;
            if (data.cost_formula_type) document.getElementById('cost_formula_type').value = data.cost_formula_type;
            document.getElementById('has_product').checked = data.has_product == 1;
            document.getElementById('has_cannabinoids').checked = data.has_cannabinoids == 1;
            if (data.has_recipe_system) document.getElementById('has_recipe_system').checked = data.has_recipe_system == 1;
            
            // Load cost structure
            if (data.ingredient_cost_per_gram) document.querySelector('[name="ingredient_cost_per_gram"]').value = data.ingredient_cost_per_gram;
            if (data.attribute_percentage) document.querySelector('[name="attribute_percentage"]').value = data.attribute_percentage;
            if (data.bottle_cost) document.querySelector('[name="bottle_cost"]').value = data.bottle_cost;
            if (data.label_cost) document.querySelector('[name="label_cost"]').value = data.label_cost;
            if (data.labor_cost) document.querySelector('[name="labor_cost"]').value = data.labor_cost;
            if (data.mix_cost) document.querySelector('[name="mix_cost"]').value = data.mix_cost;
            if (data.profit_multiplier) document.querySelector('[name="profit_multiplier"]').value = data.profit_multiplier;
            
            // Load cannabinoid defaults
            if (data.default_thca_percent) document.querySelector('[name="default_thca_percent"]').value = data.default_thca_percent;
            if (data.default_thc_d9_percent) document.querySelector('[name="default_thc_d9_percent"]').value = data.default_thc_d9_percent;
            if (data.default_thc_d8_percent) document.querySelector('[name="default_thc_d8_percent"]').value = data.default_thc_d8_percent;
            if (data.default_cbd_percent) document.querySelector('[name="default_cbd_percent"]').value = data.default_cbd_percent;
            if (data.default_cbg_percent) document.querySelector('[name="default_cbg_percent"]').value = data.default_cbg_percent;
            if (data.default_cbn_percent) document.querySelector('[name="default_cbn_percent"]').value = data.default_cbn_percent;
            
            // Load custom cost factors
            if (data.custom_cost_factors) {
                const factors = typeof data.custom_cost_factors === 'string' ? JSON.parse(data.custom_cost_factors) : data.custom_cost_factors;
                if (factors && Object.keys(factors).length > 0) {
                    loadCustomCostFactors(factors);
                }
            }
            
            // Update field visibility
            toggleProductFields();
            toggleCannabinoidFields();
            
            document.getElementById('groupModal').classList.add('active');
        })
        .catch(err => {
            alert('Error loading group data: ' + err.message);
        });
}

function closeGroupModal() {
    document.getElementById('groupModal').classList.remove('active');
}

// Commission Tiers Modal Management
let tierCounter = 0;
let currentTiers = [];

function openEditTiersModal(groupId) {
    document.getElementById('tiers_group_id').value = groupId;
    
    // Fetch existing tiers
    fetch(`<?php echo path_with_base('/api/get-product-group-tiers.php'); ?>?id=${groupId}`)
        .then(r => r.json())
        .then(data => {
            currentTiers = data;
            renderTiers();
            document.getElementById('tiersModal').classList.add('active');
        })
        .catch(err => {
            currentTiers = [];
            renderTiers();
            document.getElementById('tiersModal').classList.add('active');
        });
}

function renderTiers() {
    const container = document.getElementById('tiersContainer');
    container.innerHTML = '';
    
    if (currentTiers.length === 0) {
        // Add default tiers
        currentTiers = [
            {name: '1-5', min: 1, max: 5, discount: 0, commission: 20},
            {name: '6-10', min: 6, max: 10, discount: 8, commission: 12},
            {name: '15+', min: 15, max: '', discount: 12, commission: 8}
        ];
    }
    
    currentTiers.forEach((tier, index) => {
        const row = document.createElement('div');
        row.className = 'tier-row';
        row.innerHTML = `
            <input type="text" placeholder="Tier Name" value="${tier.name || ''}" onchange="updateTier(${index}, 'name', this.value)" style="padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
            <input type="number" placeholder="Min Qty" value="${tier.min || 1}" min="1" onchange="updateTier(${index}, 'min', this.value)" style="padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
            <input type="number" placeholder="Max Qty" value="${tier.max || ''}" min="1" onchange="updateTier(${index}, 'max', this.value)" style="padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
            <div style="display: flex; gap: 8px;">
                <div>
                    <input type="number" placeholder="Discount %" value="${tier.discount || 0}" min="0" max="100" step="0.1" onchange="updateTier(${index}, 'discount', this.value)" style="width: 80px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    <small style="color: #6b7280;">Discount</small>
                </div>
                <div>
                    <input type="number" placeholder="Comm %" value="${tier.commission || 20}" min="0" max="100" step="0.1" onchange="updateTier(${index}, 'commission', this.value)" style="width: 80px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    <small style="color: #6b7280;">Commission</small>
                </div>
            </div>
            <button type="button" onclick="removeTier(${index})" style="background: #dc2626; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer;">✕</button>
        `;
        container.appendChild(row);
    });
}

function updateTier(index, field, value) {
    currentTiers[index][field] = value;
}

function addTierRow() {
    currentTiers.push({name: '', min: 1, max: '', discount: 0, commission: 20});
    renderTiers();
}

function removeTier(index) {
    currentTiers.splice(index, 1);
    renderTiers();
}

function closeTiersModal() {
    document.getElementById('tiersModal').classList.remove('active');
}

document.getElementById('tiersForm').addEventListener('submit', function(e) {
    document.getElementById('tiers_json').value = JSON.stringify(currentTiers);
});

// Assign Products Modal Management
function openAssignProductsModal(groupId) {
    document.getElementById('assign_group_id').value = groupId;
    
    // Fetch products in this group
    fetch(`<?php echo path_with_base('/api/get-group-products.php'); ?>?id=${groupId}`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('currentProducts');
            if (data.length === 0) {
                container.innerHTML = '<p style="color: #6b7280; text-align: center;">No products in this group</p>';
            } else {
                container.innerHTML = data.map(p => `
                    <div style="display: flex; justify-content: space-between; padding: 8px; border-bottom: 1px solid #f3f4f6;">
                        <div>
                            <strong>${p.name}</strong>
                            <span style="color: #6b7280; margin-left: 8px; font-size: 12px;">${p.sku}</span>
                        </div>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="unassign_products">
                            <input type="hidden" name="product_ids[]" value="${p.id}">
                            <button type="submit" style="background: #dc2626; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px;">Remove</button>
                        </form>
                    </div>
                `).join('');
            }
            document.getElementById('assignModal').classList.add('active');
        })
        .catch(err => {
            document.getElementById('currentProducts').innerHTML = '<p style="color: #991b1b;">Error loading products</p>';
            document.getElementById('assignModal').classList.add('active');
        });
}

function closeAssignModal() {
    document.getElementById('assignModal').classList.remove('active');
}

// NEW: Active Ingredient Field Toggle
function toggleProductFields() {
    const hasProduct = document.getElementById('has_product').checked;
    document.getElementById('product-costs').style.display = hasProduct ? 'block' : 'none';
}

function toggleCannabinoidFields() {
    const hasCannabinoids = document.getElementById('has_cannabinoids').checked;
    document.getElementById('cannabinoid-costs').style.display = hasCannabinoids ? 'block' : 'none';
}

function updateCategoryDefaults() {
    const category = document.getElementById('product_category').value;
    
    // Set smart defaults based on category
    switch(category) {
        case 'products':
            document.getElementById('has_product').checked = true;
            document.getElementById('has_cannabinoids').checked = false;
            document.getElementById('cost_formula_type').value = 'active-ingredient-based';
            break;
        case 'gummies':
        case 'creams':
            document.getElementById('has_product').checked = false;
            document.getElementById('has_cannabinoids').checked = true;
            document.getElementById('cost_formula_type').value = 'active-ingredient-based';
            break;
        case 'drinks':
            document.getElementById('has_product').checked = false;
            document.getElementById('has_cannabinoids').checked = false;
            document.getElementById('cost_formula_type').value = 'fixed-cost';
            break;
        case 'apparel':
            document.getElementById('has_product').checked = false;
            document.getElementById('has_cannabinoids').checked = false;
            document.getElementById('cost_formula_type').value = 'simple-markup';
            break;
    }
    
    toggleProductFields();
    toggleCannabinoidFields();
}

// NEW: Template Cloning
function cloneGroupSetup(groupId) {
    if (!groupId) return;
    
    fetch(`<?php echo path_with_base('/api/get-product-group.php'); ?>?id=${groupId}&include_costs=true`)
        .then(r => r.json())
        .then(data => {
            // Fill category and formula
            if (data.product_category) document.getElementById('product_category').value = data.product_category;
            if (data.cost_formula_type) document.getElementById('cost_formula_type').value = data.cost_formula_type;
            
            // Set active ingredient checkboxes
            document.getElementById('has_product').checked = data.has_product == 1;
            document.getElementById('has_cannabinoids').checked = data.has_cannabinoids == 1;
            if (data.has_recipe_system) document.getElementById('has_recipe_system').checked = data.has_recipe_system == 1;
            
            // Fill product costs
            if (data.ingredient_cost_per_gram) document.querySelector('[name="ingredient_cost_per_gram"]').value = data.ingredient_cost_per_gram;
            if (data.attribute_percentage) document.querySelector('[name="attribute_percentage"]').value = data.attribute_percentage;
            
            // Fill cannabinoid defaults
            if (data.default_thca_percent) document.querySelector('[name="default_thca_percent"]').value = data.default_thca_percent;
            if (data.default_thc_d9_percent) document.querySelector('[name="default_thc_d9_percent"]').value = data.default_thc_d9_percent;
            if (data.default_thc_d8_percent) document.querySelector('[name="default_thc_d8_percent"]').value = data.default_thc_d8_percent;
            if (data.default_cbd_percent) document.querySelector('[name="default_cbd_percent"]').value = data.default_cbd_percent;
            if (data.default_cbg_percent) document.querySelector('[name="default_cbg_percent"]').value = data.default_cbg_percent;
            if (data.default_cbn_percent) document.querySelector('[name="default_cbn_percent"]').value = data.default_cbn_percent;
            
            // Fill common costs
            if (data.bottle_cost) document.querySelector('[name="bottle_cost"]').value = data.bottle_cost;
            if (data.label_cost) document.querySelector('[name="label_cost"]').value = data.label_cost;
            if (data.labor_cost) document.querySelector('[name="labor_cost"]').value = data.labor_cost;
            if (data.mix_cost) document.querySelector('[name="mix_cost"]').value = data.mix_cost;
            if (data.profit_multiplier) document.querySelector('[name="profit_multiplier"]').value = data.profit_multiplier;
            
            // Load custom cost factors
            if (data.custom_cost_factors_parsed) {
                loadCustomCostFactors(data.custom_cost_factors_parsed);
            } else if (data.custom_cost_factors) {
                const factors = typeof data.custom_cost_factors === 'string' ? JSON.parse(data.custom_cost_factors) : data.custom_cost_factors;
                if (factors && Object.keys(factors).length > 0) {
                    loadCustomCostFactors(factors);
                }
            }
            
            // Load commission tiers
            fetchAndLoadCommissionTiers(groupId);
            
            // Store template source
            document.getElementById('template_source_id').value = groupId;
            
            // Update visibility
            toggleProductFields();
            toggleCannabinoidFields();
            
            alert('Template loaded! Review and adjust values as needed.');
        })
        .catch(err => {
            console.error('Error loading template:', err);
            alert('Error loading template: ' + err.message);
        });
}

// Helper to fetch and load commission tiers
function fetchAndLoadCommissionTiers(groupId) {
    fetch(`<?php echo path_with_base('/api/get-product-group-tiers.php'); ?>?id=${groupId}`)
        .then(r => r.json())
        .then(tiers => {
            if (tiers && tiers.length > 0) {
                currentTiers = tiers;
                console.log('Loaded commission tiers from template:', tiers);
            }
        })
        .catch(err => {
            console.log('No existing tiers or error fetching tiers:', err);
        });
}

// NEW: Custom Cost Factors Management
let customCostFactorCount = 0;

function addCustomCostFactor() {
    const container = document.getElementById('custom-cost-factors-container');
    const factorId = ++customCostFactorCount;
    
    const factorDiv = document.createElement('div');
    factorDiv.className = 'custom-cost-factor';
    factorDiv.id = `custom-factor-${factorId}`;
    factorDiv.style.cssText = 'display: grid; grid-template-columns: 1fr 1fr 60px; gap: 8px; margin-top: 8px;';
    factorDiv.innerHTML = `
        <input type="text" placeholder="Factor name (e.g., cream_base)" data-factor-name="${factorId}" class="custom-factor-name" style="padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px;">
        <input type="number" step="0.01" placeholder="Cost value" data-factor-value="${factorId}" class="custom-factor-value" style="padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px;">
        <button type="button" onclick="removeCustomCostFactor(${factorId})" style="background: #dc2626; color: white; border: none; border-radius: 4px; cursor: pointer;">✕</button>
    `;
    container.appendChild(factorDiv);
}

function removeCustomCostFactor(factorId) {
    const element = document.getElementById(`custom-factor-${factorId}`);
    if (element) element.remove();
}

function collectCustomCostFactors() {
    const factors = {};
    document.querySelectorAll('.custom-cost-factor').forEach(div => {
        const nameInput = div.querySelector('.custom-factor-name');
        const valueInput = div.querySelector('.custom-factor-value');
        if (nameInput && valueInput && nameInput.value && valueInput.value) {
            factors[nameInput.value] = parseFloat(valueInput.value);
        }
    });
    return factors;
}

function loadCustomCostFactors(factors) {
    const container = document.getElementById('custom-cost-factors-container');
    container.innerHTML = '<small style="color: #6b7280;">Custom cost factors from template:</small>';
    
    for (const [name, value] of Object.entries(factors)) {
        addCustomCostFactor();
        const lastFactor = container.lastElementChild;
        lastFactor.querySelector('.custom-factor-name').value = name;
        lastFactor.querySelector('.custom-factor-value').value = value;
    }
}

// NEW: Formula Test Calculator
function testFormula() {
    const hasProduct = document.getElementById('has_product').checked;
    const hasCannabinoids = document.getElementById('has_cannabinoids').checked;
    
    if (!hasProduct && !hasCannabinoids) {
        testFixedCostFormula();
    } else if (hasProduct) {
        testProductFormula();
    } else if (hasCannabinoids) {
        testCannabinoidFormula();
    }
}

function testProductFormula() {
    const ingredientCost = parseFloat(document.querySelector('[name="ingredient_cost_per_gram"]').value) || 0;
    const mitPercent = parseFloat(document.querySelector('[name="attribute_percentage"]').value) || 0;
    const laborCost = parseFloat(document.querySelector('[name="labor_cost"]').value) || 0;
    const profitMult = parseFloat(document.querySelector('[name="profit_multiplier"]').value) || 0;
    
    const attributePerOz = parseFloat(prompt('Test attribute per oz (e.g., 20 for Blue 10):', '20')) || 0;
    const sizeOz = parseFloat(prompt('Test bottle size in oz (e.g., 32):', '32')) || 0;
    
    if (attributePerOz && sizeOz && mitPercent && ingredientCost && profitMult) {
        const totalAttribute = attributePerOz * sizeOz;
        const mitPerGram = mitPercent * 10;
        const gramsNeeded = totalAttribute / mitPerGram;
        const materialCost = gramsNeeded * ingredientCost;
        const totalCost = materialCost + 2.50 + laborCost;
        const wholesale = totalCost * profitMult;
        const commission = wholesale * 0.20;
        const netProfit = wholesale - totalCost - commission;
        const profitMargin = (netProfit / wholesale) * 100;
        
        showFormulaResults({
            productType: 'Product',
            details: `${attributePerOz}mg/oz × ${sizeOz}oz = ${totalAttribute}mg attribute`,
            gramsNeeded,
            materialCost,
            totalCost,
            wholesale,
            commission,
            netProfit,
            profitMargin
        });
    } else {
        alert('Please fill in all required cost fields first');
    }
}

function testCannabinoidFormula() {
    alert('Cannabinoid formula test:\nEnter total cannabinoid mg and cost per gram.\nSimilar to product formula but uses cannabinoid percentages.');
}

function testFixedCostFormula() {
    const bottleCost = parseFloat(document.querySelector('[name="bottle_cost"]').value) || 0;
    const labelCost = parseFloat(document.querySelector('[name="label_cost"]').value) || 0;
    const laborCost = parseFloat(document.querySelector('[name="labor_cost"]').value) || 0;
    const mixCost = parseFloat(document.querySelector('[name="mix_cost"]').value) || 0;
    const profitMult = parseFloat(document.querySelector('[name="profit_multiplier"]').value) || 0;
    
    if (profitMult) {
        const totalCost = bottleCost + labelCost + laborCost + mixCost;
        const wholesale = totalCost * profitMult;
        const commission = wholesale * 0.20;
        const netProfit = wholesale - totalCost - commission;
        const profitMargin = (netProfit / wholesale) * 100;
        
        showFormulaResults({
            productType: 'Fixed Cost',
            details: 'No active ingredients',
            gramsNeeded: 0,
            materialCost: 0,
            totalCost,
            wholesale,
            commission,
            netProfit,
            profitMargin
        });
    } else {
        alert('Please enter a profit multiplier first');
    }
}

function showFormulaResults(results) {
    const modal = document.createElement('div');
    modal.className = 'modal active';
    modal.style.cssText = 'display: flex; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;';
    modal.innerHTML = `
        <div class="modal-content" style="background: white; border-radius: 12px; padding: 24px; max-width: 600px; width: 90%;">
            <h3 style="margin: 0 0 8px 0;">Formula Test Results: ${results.productType}</h3>
            <p style="color: #6b7280; margin-bottom: 16px;">${results.details}</p>
            <table style="width: 100%; border-collapse: collapse;">
                ${results.gramsNeeded > 0 ? `<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 8px;">Grams Needed:</td><td style="padding: 8px; text-align: right;">${results.gramsNeeded.toFixed(2)}g</td></tr>` : ''}
                ${results.materialCost > 0 ? `<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 8px;">Material Cost:</td><td style="padding: 8px; text-align: right;">$${results.materialCost.toFixed(2)}</td></tr>` : ''}
                <tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 8px;"><strong>Total Production Cost:</strong></td><td style="padding: 8px; text-align: right;"><strong>$${results.totalCost.toFixed(2)}</strong></td></tr>
                <tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 8px;">Wholesale Price:</td><td style="padding: 8px; text-align: right; color: #059669;">$${results.wholesale.toFixed(2)}</td></tr>
                <tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 8px;">20% Commission:</td><td style="padding: 8px; text-align: right; color: #dc2626;">$${results.commission.toFixed(2)}</td></tr>
                <tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 8px;"><strong>Net Profit:</strong></td><td style="padding: 8px; text-align: right; color: #059669;"><strong>$${results.netProfit.toFixed(2)}</strong></td></tr>
                <tr><td style="padding: 8px;"><strong>Profit Margin:</strong></td><td style="padding: 8px; text-align: right; color: #059669;"><strong>${results.profitMargin.toFixed(1)}%</strong></td></tr>
            </table>
            <button class="btn" style="margin-top: 16px; width: 100%; background: var(--green); color: white;" onclick="this.closest('.modal').remove()">Close</button>
        </div>
    `;
    document.body.appendChild(modal);
    modal.addEventListener('click', function(e) {
        if (e.target === modal) modal.remove();
    });
}

// Update form submission to collect custom cost factors
document.getElementById('groupForm').addEventListener('submit', function(e) {
    const customFactors = collectCustomCostFactors();
    document.getElementById('custom_cost_factors_json').value = JSON.stringify(customFactors);
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


