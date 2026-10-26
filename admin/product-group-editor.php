<?php
$SEO = [
    'title' => 'Edit Product Group — Admin',
    'description' => 'Edit product group details, costs, and commission structures.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';
$groupId = intval($_GET['id'] ?? 0);
$isNew = $groupId === 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_group'])) {
    try {
        $groupId = intval($_POST['group_id'] ?? 0);
        $groupKey = trim($_POST['group_key'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $displayOrder = intval($_POST['display_order'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;
        $useCustomCommission = isset($_POST['use_custom_commission']) ? 1 : 0;
        
        // Active ingredient flags
        $hasProduct = isset($_POST['has_product']) ? 1 : 0;
        $hasCannabinoids = isset($_POST['has_cannabinoids']) ? 1 : 0;
        $hasRecipeSystem = isset($_POST['has_recipe_system']) ? 1 : 0;
        
        // Category and formula type
        $productCategory = trim($_POST['product_category'] ?? '');
        $costFormulaType = trim($_POST['cost_formula_type'] ?? 'active-ingredient-based');
        
        // Cost structure
        $ingredientCost = !empty($_POST['ingredient_cost_per_gram']) ? floatval($_POST['ingredient_cost_per_gram']) : null;
        $mitPercentage = !empty($_POST['attribute_percentage']) ? floatval($_POST['attribute_percentage']) : null;
        $bottleCost = !empty($_POST['bottle_cost']) ? floatval($_POST['bottle_cost']) : null;
        $labelCost = !empty($_POST['label_cost']) ? floatval($_POST['label_cost']) : null;
        $laborCost = !empty($_POST['labor_cost']) ? floatval($_POST['labor_cost']) : null;
        $mixCost = !empty($_POST['mix_cost']) ? floatval($_POST['mix_cost']) : null;
        $profitMultiplier = !empty($_POST['profit_multiplier']) ? floatval($_POST['profit_multiplier']) : null;
        
        // Cannabinoid defaults
        $defaultThcaPercent = !empty($_POST['default_thca_percent']) ? floatval($_POST['default_thca_percent']) : null;
        $defaultThcD9Percent = !empty($_POST['default_thc_d9_percent']) ? floatval($_POST['default_thc_d9_percent']) : null;
        $defaultThcD8Percent = !empty($_POST['default_thc_d8_percent']) ? floatval($_POST['default_thc_d8_percent']) : null;
        $defaultCbdPercent = !empty($_POST['default_cbd_percent']) ? floatval($_POST['default_cbd_percent']) : null;
        $defaultCbgPercent = !empty($_POST['default_cbg_percent']) ? floatval($_POST['default_cbg_percent']) : null;
        $defaultCbnPercent = !empty($_POST['default_cbn_percent']) ? floatval($_POST['default_cbn_percent']) : null;
        
        // Custom cost factors
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
            $message = 'Product group updated successfully!';
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
            $newGroupId = $pdo->lastInsertId();
            $message = 'Product group created successfully!';
            $groupId = $newGroupId; // Update for display
        }
        
        // Flash message and redirect
        flash_set('success', $message);
        header('Location: ' . path_with_base('/admin/product-groups.php'));
        exit;
        
    } catch (Throwable $e) {
        $error = 'Error saving product group: ' . $e->getMessage();
    }
}

// Fetch group data
$group = null;
if ($groupId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM product_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        header('Location: ' . path_with_base('/admin/product-groups.php'));
        exit;
    }
} else {
    // New group - check if cloning from template
    $templateId = intval($_GET['clone'] ?? 0);
    if ($templateId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM product_groups WHERE id = ?");
        $stmt->execute([$templateId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($group) {
            $group['id'] = 0; // Mark as new
            $group['group_key'] = ''; // Clear key for new group
            $group['name'] = 'Copy of ' . $group['name'];
            $group['template_source_id'] = $templateId;
        }
    }
}

// Parse custom cost factors
$customFactors = [];
if ($group && !empty($group['custom_cost_factors'])) {
    $customFactors = json_decode($group['custom_cost_factors'], true) ?? [];
}
?>

<style>
.editor-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.editor-header { background: linear-gradient(135deg, #059669, #10b981); color: white; padding: 32px; border-radius: 12px; margin-bottom: 24px; }
.card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
.form-section { margin-bottom: 32px; padding-bottom: 32px; border-bottom: 2px solid #e5e7eb; }
.form-section:last-child { border-bottom: none; }
.form-section h3 { margin: 0 0 16px 0; color: #059669; font-size: 20px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #374151; }
.form-group input[type="text"],
.form-group input[type="number"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
}
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.help-text { font-size: 13px; color: #6b7280; margin-top: 4px; }
.checkbox-card { padding: 12px; background: #f9fafb; border-radius: 8px; cursor: pointer; border: 2px solid transparent; }
.checkbox-card:hover { background: #f3f4f6; }
.checkbox-card.checked { border-color: #059669; background: #f0fdf4; }
.cost-section { background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 16px; }
.alert-success { background: #dcfce7; color: #14532d; padding: 16px; border-radius: 8px; margin-bottom: 24px; border: 2px solid #059669; }
.alert-error { background: #fee2e2; color: #991b1b; padding: 16px; border-radius: 8px; margin-bottom: 24px; border: 2px solid #dc2626; }
</style>

<div class="editor-container">
    <div class="editor-header">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="margin: 0 0 8px 0;"><?php echo $isNew ? '✨ Create New' : '✏️ Edit'; ?> Product Group</h1>
                <p style="margin: 0; opacity: 0.9;">
                    <?php if ($group && $group['name']): ?>
                        <?php echo htmlspecialchars($group['name']); ?>
                    <?php else: ?>
                        Configure costs, active ingredients, and manufacturing settings
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?php echo path_with_base('/admin/product-groups.php'); ?>" class="btn secondary">
                ← Back to Product Groups
            </a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert-success">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert-error">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <form method="post" id="group-form">
        <input type="hidden" name="save_group" value="1">
        <input type="hidden" name="group_id" value="<?php echo $groupId; ?>">
        <input type="hidden" name="template_source_id" value="<?php echo $group['template_source_id'] ?? ''; ?>">
        <input type="hidden" name="custom_cost_factors_json" id="custom_cost_factors_json">
        
        <div class="card">
            <!-- Section 1: Basic Information -->
            <div class="form-section">
                <h3>📋 Basic Information</h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Group Key *</label>
                        <input type="text" name="group_key" value="<?php echo htmlspecialchars($group['group_key'] ?? ''); ?>" required>
                        <div class="help-text">Unique identifier (lowercase, hyphens allowed, e.g., product-products)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Display Name *</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($group['name'] ?? ''); ?>" required>
                        <div class="help-text">Public-facing name (e.g., Products)</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3"><?php echo htmlspecialchars($group['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" value="<?php echo $group['display_order'] ?? 0; ?>" min="0">
                        <div class="help-text">Lower numbers appear first</div>
                    </div>
                    
                    <div class="form-group" style="display: flex; align-items: end; gap: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 0;">
                            <input type="checkbox" name="active" <?php echo ($group['active'] ?? 1) ? 'checked' : ''; ?>>
                            <span>Active</span>
                        </label>
                        
                        <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 0;">
                            <input type="checkbox" name="use_custom_commission" <?php echo ($group['use_custom_commission'] ?? 1) ? 'checked' : ''; ?>>
                            <span>Custom Commission</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Product Category & Active Ingredients -->
            <div class="form-section">
                <h3>🌿 Product Category & Active Ingredients</h3>
                
                <div class="form-group">
                    <label>Product Category</label>
                    <select name="product_category" id="product_category" onchange="updateCategoryDefaults()">
                        <option value="">Select category...</option>
                        <option value="products" <?php echo ($group['product_category'] ?? '') === 'products' ? 'selected' : ''; ?>>Product/product products</option>
                        <option value="gummies" <?php echo ($group['product_category'] ?? '') === 'gummies' ? 'selected' : ''; ?>>Gummies</option>
                        <option value="creams" <?php echo ($group['product_category'] ?? '') === 'creams' ? 'selected' : ''; ?>>Topical Creams/Balms</option>
                        <option value="vitamins" <?php echo ($group['product_category'] ?? '') === 'vitamins' ? 'selected' : ''; ?>>Vitamins/Supplements</option>
                        <option value="drinks" <?php echo ($group['product_category'] ?? '') === 'drinks' ? 'selected' : ''; ?>>Beverages (Non-Infused)</option>
                        <option value="apparel" <?php echo ($group['product_category'] ?? '') === 'apparel' ? 'selected' : ''; ?>>Apparel/Merchandise</option>
                        <option value="custom" <?php echo ($group['product_category'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom/Other</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <label class="checkbox-card <?php echo ($group['has_product'] ?? 0) ? 'checked' : ''; ?>" onclick="toggleCheckbox(this, 'has_product')">
                        <input type="checkbox" name="has_product" id="has_product" <?php echo ($group['has_product'] ?? 0) ? 'checked' : ''; ?> onchange="toggleProductFields()">
                        <strong>Contains Product</strong>
                        <div class="help-text">Enable attribute product specification tracking</div>
                    </label>
                    
                    <label class="checkbox-card <?php echo ($group['has_cannabinoids'] ?? 0) ? 'checked' : ''; ?>" onclick="toggleCheckbox(this, 'has_cannabinoids')">
                        <input type="checkbox" name="has_cannabinoids" id="has_cannabinoids" <?php echo ($group['has_cannabinoids'] ?? 0) ? 'checked' : ''; ?> onchange="toggleCannabinoidFields()">
                        <strong>Contains Cannabinoids</strong>
                        <div class="help-text">Enable THC/product/CBN/CBG tracking</div>
                    </label>
                </div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label>Cost Formula Type</label>
                    <select name="cost_formula_type" id="cost_formula_type">
                        <option value="active-ingredient-based" <?php echo ($group['cost_formula_type'] ?? 'active-ingredient-based') === 'active-ingredient-based' ? 'selected' : ''; ?>>Active Ingredient Formula</option>
                        <option value="fixed-cost" <?php echo ($group['cost_formula_type'] ?? '') === 'fixed-cost' ? 'selected' : ''; ?>>Fixed Per-Unit Cost</option>
                        <option value="simple-markup" <?php echo ($group['cost_formula_type'] ?? '') === 'simple-markup' ? 'selected' : ''; ?>>Simple Cost + Markup</option>
                        <option value="custom" <?php echo ($group['cost_formula_type'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom Formula</option>
                    </select>
                </div>
            </div>
            
            <!-- Section 3: Cost Structure -->
            <div class="form-section">
                <h3>💰 Cost Structure</h3>
                
                <!-- Product Costs -->
                <div id="product-costs" style="display: <?php echo ($group['has_product'] ?? 0) ? 'block' : 'none'; ?>;">
                    <div class="cost-section" style="background: #fef3c7;">
                        <h4 style="margin: 0 0 12px 0; color: #92400e;">Product Ingredient Costs</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Product Cost per Gram</label>
                                <input type="number" name="ingredient_cost_per_gram" step="0.01" value="<?php echo $group['ingredient_cost_per_gram'] ?? ''; ?>" placeholder="e.g., 3.40">
                            </div>
                            <div class="form-group">
                                <label>attribute % in Raw Product</label>
                                <input type="number" name="attribute_percentage" step="0.01" value="<?php echo $group['attribute_percentage'] ?? ''; ?>" placeholder="e.g., 62">
                            </div>
                        </div>
                        <div class="help-text" style="color: #92400e;">Formula: total_mg ÷ (attribute_% × 10) × cost_per_gram</div>
                    </div>
                </div>
                
                <!-- Cannabinoid Costs -->
                <div id="cannabinoid-costs" style="display: <?php echo ($group['has_cannabinoids'] ?? 0) ? 'block' : 'none'; ?>;">
                    <div class="cost-section" style="background: #dbeafe;">
                        <h4 style="margin: 0 0 12px 0; color: #1e40af;">Cannabinoid Default Potencies</h4>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Default THCA %</label>
                                <input type="number" name="default_thca_percent" step="0.01" value="<?php echo $group['default_thca_percent'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label>Default Delta-9 THC %</label>
                                <input type="number" name="default_thc_d9_percent" step="0.01" value="<?php echo $group['default_thc_d9_percent'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label>Default Delta-8 THC %</label>
                                <input type="number" name="default_thc_d8_percent" step="0.01" value="<?php echo $group['default_thc_d8_percent'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label>Default product %</label>
                                <input type="number" name="default_cbd_percent" step="0.01" value="<?php echo $group['default_cbd_percent'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label>Default CBG %</label>
                                <input type="number" name="default_cbg_percent" step="0.01" value="<?php echo $group['default_cbg_percent'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label>Default CBN %</label>
                                <input type="number" name="default_cbn_percent" step="0.01" value="<?php echo $group['default_cbn_percent'] ?? ''; ?>">
                            </div>
                        </div>
                        <div class="help-text" style="color: #1e40af;">These defaults apply to all products in this group</div>
                    </div>
                </div>
                
                <!-- Common Costs -->
                <div class="cost-section">
                    <h4 style="margin: 0 0 12px 0;">Packaging & Production Costs</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Container/Bottle Cost</label>
                            <input type="number" name="bottle_cost" step="0.01" value="<?php echo $group['bottle_cost'] ?? ''; ?>" placeholder="2.50">
                        </div>
                        <div class="form-group">
                            <label>Label/Packaging Cost</label>
                            <input type="number" name="label_cost" step="0.01" value="<?php echo $group['label_cost'] ?? ''; ?>" placeholder="2.50">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Labor Cost per Unit</label>
                            <input type="number" name="labor_cost" step="0.01" value="<?php echo $group['labor_cost'] ?? ''; ?>" placeholder="5.00">
                        </div>
                        <div class="form-group">
                            <label>Mix/Prep Cost</label>
                            <input type="number" name="mix_cost" step="0.01" value="<?php echo $group['mix_cost'] ?? ''; ?>" placeholder="1.50">
                        </div>
                    </div>
                </div>
                
                <!-- Custom Cost Factors -->
                <div class="cost-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h4 style="margin: 0;">Custom Cost Factors</h4>
                        <button type="button" class="btn secondary" style="font-size: 13px; padding: 6px 12px;" onclick="addCustomCostFactor()">+ Add Factor</button>
                    </div>
                    <div id="custom-cost-factors-container">
                        <?php if (empty($customFactors)): ?>
                        <div class="help-text">Add custom cost factors like "cream_base", "special_packaging", etc.</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Profit Multiplier -->
                <div class="form-group">
                    <label>Profit Multiplier</label>
                    <input type="number" name="profit_multiplier" step="0.1" value="<?php echo $group['profit_multiplier'] ?? ''; ?>" placeholder="5.0">
                    <div class="help-text">Wholesale Price = Total Cost × Multiplier</div>
                </div>
                
                <button type="button" class="btn secondary" onclick="testFormula()">🧮 Test Formula Calculator</button>
            </div>
            
            <!-- Section 4: Manufacturing & Operations -->
            <div class="form-section">
                <h3>🏭 Manufacturing & Operations</h3>
                
                <label class="checkbox-card <?php echo ($group['has_recipe_system'] ?? 0) ? 'checked' : ''; ?>" onclick="toggleCheckbox(this, 'has_recipe_system')" style="background: #f0fdf4;">
                    <input type="checkbox" name="has_recipe_system" id="has_recipe_system" <?php echo ($group['has_recipe_system'] ?? 0) ? 'checked' : ''; ?>>
                    <strong>Enable Recipe/Manufacturing System</strong>
                    <div class="help-text">Track ingredients, packaging, labor, and work orders for this product group</div>
                </label>
            </div>
            
            <!-- Save Button -->
            <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 24px; border-top: 2px solid #e5e7eb;">
                <a href="<?php echo path_with_base('/admin/product-groups.php'); ?>" class="btn secondary">Cancel</a>
                <button type="submit" class="btn" style="background: var(--green); color: white;">
                    💾 Save Product Group
                </button>
            </div>
        </div>
    </form>
</div>

<script>
// Load existing custom cost factors
<?php if (!empty($customFactors)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const factors = <?php echo json_encode($customFactors); ?>;
    for (const [name, value] of Object.entries(factors)) {
        addCustomCostFactor(name, value);
    }
});
<?php endif; ?>

// Checkbox card toggle
function toggleCheckbox(card, checkboxId) {
    const checkbox = document.getElementById(checkboxId);
    checkbox.checked = !checkbox.checked;
    checkbox.dispatchEvent(new Event('change'));
    
    if (checkbox.checked) {
        card.classList.add('checked');
    } else {
        card.classList.remove('checked');
    }
}

// Active ingredient field toggles
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
    
    // Update checkbox cards
    document.querySelectorAll('.checkbox-card').forEach(card => {
        const input = card.querySelector('input[type="checkbox"]');
        if (input && input.checked) {
            card.classList.add('checked');
        } else if (input) {
            card.classList.remove('checked');
        }
    });
}

// Custom cost factors
let customCostFactorCount = 0;

function addCustomCostFactor(name = '', value = '') {
    const container = document.getElementById('custom-cost-factors-container');
    const factorId = ++customCostFactorCount;
    
    const row = document.createElement('div');
    row.className = 'custom-cost-factor';
    row.id = `custom-factor-${factorId}`;
    row.style.cssText = 'display: grid; grid-template-columns: 1fr 1fr 60px; gap: 12px; margin-bottom: 12px;';
    row.innerHTML = `
        <input type="text" placeholder="Factor name (e.g., cream_base)" value="${name}" class="custom-factor-name" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
        <input type="number" step="0.01" placeholder="Cost value" value="${value}" class="custom-factor-value" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
        <button type="button" onclick="removeCustomCostFactor(${factorId})" style="background: #dc2626; color: white; border: none; border-radius: 6px; padding: 8px; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

function removeCustomCostFactor(factorId) {
    document.getElementById(`custom-factor-${factorId}`)?.remove();
}

function collectCustomCostFactors() {
    const factors = {};
    document.querySelectorAll('.custom-cost-factor').forEach(row => {
        const nameInput = row.querySelector('.custom-factor-name');
        const valueInput = row.querySelector('.custom-factor-value');
        if (nameInput.value && valueInput.value) {
            factors[nameInput.value] = parseFloat(valueInput.value);
        }
    });
    return factors;
}

// Formula calculator
function testFormula() {
    const hasProduct = document.getElementById('has_product').checked;
    const hasCannabinoids = document.getElementById('has_cannabinoids').checked;
    
    if (!hasProduct && !hasCannabinoids) {
        testFixedCostFormula();
    } else if (hasProduct) {
        testProductFormula();
    } else {
        alert('Cannabinoid formula test: Enter cannabinoid content and cost per gram');
    }
}

function testProductFormula() {
    const ingredientCost = parseFloat(document.querySelector('[name="ingredient_cost_per_gram"]').value) || 0;
    const mitPercent = parseFloat(document.querySelector('[name="attribute_percentage"]').value) || 0;
    const laborCost = parseFloat(document.querySelector('[name="labor_cost"]').value) || 0;
    const profitMult = parseFloat(document.querySelector('[name="profit_multiplier"]').value) || 0;
    
    if (!ingredientCost || !mitPercent || !profitMult) {
        alert('Please fill in: Product cost per gram, attribute %, and Profit Multiplier');
        return;
    }
    
    const attributePerOz = parseFloat(prompt('Test attribute per oz (e.g., 20 for Blue 10):', '20')) || 0;
    const sizeOz = parseFloat(prompt('Test bottle size in oz (e.g., 32):', '32')) || 0;
    
    if (attributePerOz && sizeOz) {
        const totalAttribute = attributePerOz * sizeOz;
        const mitPerGram = mitPercent * 10;
        const gramsNeeded = totalAttribute / mitPerGram;
        const materialCost = gramsNeeded * ingredientCost;
        const totalCost = materialCost + 2.50 + laborCost;
        const wholesale = totalCost * profitMult;
        const commission = wholesale * 0.20;
        const netProfit = wholesale - totalCost - commission;
        const profitMargin = (netProfit / wholesale) * 100;
        
        alert(`Formula Test Results:\n\n` +
              `Grams Needed: ${gramsNeeded.toFixed(2)}g\n` +
              `Material Cost: $${materialCost.toFixed(2)}\n` +
              `Total Cost: $${totalCost.toFixed(2)}\n` +
              `Wholesale: $${wholesale.toFixed(2)}\n` +
              `Net Profit: $${netProfit.toFixed(2)}\n` +
              `Profit Margin: ${profitMargin.toFixed(1)}%`);
    }
}

function testFixedCostFormula() {
    const bottleCost = parseFloat(document.querySelector('[name="bottle_cost"]').value) || 0;
    const labelCost = parseFloat(document.querySelector('[name="label_cost"]').value) || 0;
    const laborCost = parseFloat(document.querySelector('[name="labor_cost"]').value) || 0;
    const mixCost = parseFloat(document.querySelector('[name="mix_cost"]').value) || 0;
    const profitMult = parseFloat(document.querySelector('[name="profit_multiplier"]').value) || 0;
    
    if (!profitMult) {
        alert('Please enter a Profit Multiplier');
        return;
    }
    
    const totalCost = bottleCost + labelCost + laborCost + mixCost;
    const wholesale = totalCost * profitMult;
    const commission = wholesale * 0.20;
    const netProfit = wholesale - totalCost - commission;
    const profitMargin = (netProfit / wholesale) * 100;
    
    alert(`Formula Test Results (Fixed Cost):\n\n` +
          `Total Cost: $${totalCost.toFixed(2)}\n` +
          `Wholesale: $${wholesale.toFixed(2)}\n` +
          `Net Profit: $${netProfit.toFixed(2)}\n` +
          `Profit Margin: ${profitMargin.toFixed(1)}%`);
}

// Form submission
document.getElementById('group-form').addEventListener('submit', function(e) {
    const customFactors = collectCustomCostFactors();
    document.getElementById('custom_cost_factors_json').value = JSON.stringify(customFactors);
});

// Initialize field visibility
document.addEventListener('DOMContentLoaded', function() {
    toggleProductFields();
    toggleCannabinoidFields();
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

