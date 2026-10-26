<?php
// Load config first to set CSP before any output
require_once __DIR__ . '/../required/config.php';

// Set CSP with TinyMCE support BEFORE any output
header("Content-Security-Policy: script-src 'self' 'unsafe-inline' 'unsafe-eval' https://maps.googleapis.com https://maps.gstatic.com https://cdn.tiny.cloud; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; img-src 'self' data: https: blob:; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; connect-src 'self' https://maps.googleapis.com;");

$SEO = [
    'title' => 'Recipe Editor — Operations',
    'description' => 'Create and edit product group recipes.',
    'type' => 'website',
    'extra_head' => '<meta http-equiv="Content-Security-Policy" content="script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' https://maps.googleapis.com https://maps.gstatic.com https://cdn.tiny.cloud; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com https://cdnjs.cloudflare.com; img-src \'self\' data: https: blob:; font-src \'self\' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; connect-src \'self\' https://maps.googleapis.com;">'
];
require_once __DIR__ . '/../required/header.php';

// Ensure user has operations role
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'operations')) {
    flash_set('error', 'Access denied. Operations role required.');
    redirect('/login.php');
}

$pdo = db();

$recipeId = intval($_GET['recipe_id'] ?? 0);
$groupId = intval($_GET['group_id'] ?? 0);

// Load existing recipe if editing
$recipe = null;
if ($recipeId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM product_group_recipes WHERE id = ?");
    $stmt->execute([$recipeId]);
    $recipe = $stmt->fetch();
    if ($recipe) {
        $groupId = $recipe['product_group_id'];
    }
}

if ($groupId <= 0) {
    flash_set('error', 'Invalid product group');
    redirect('/operations/product-group-recipe.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_recipe') {
            $saveRecipeId = intval($_POST['recipe_id'] ?? 0);
            $saveGroupId = intval($_POST['product_group_id'] ?? 0);
            $recipeName = trim($_POST['recipe_name'] ?? '');
            $recipeVersion = trim($_POST['recipe_version'] ?? 'v1.0');
            $recipeSteps = $_POST['recipe_steps'] ?? ''; // HTML content from TinyMCE
            $batchYield = intval($_POST['batch_yield'] ?? 1);
            $estimatedTime = intval($_POST['estimated_time_minutes'] ?? 0);
            $active = isset($_POST['active']) ? 1 : 0;
            
            // Get JSON lists
            $ingredientListJson = !empty($_POST['ingredient_list_json']) ? $_POST['ingredient_list_json'] : null;
            $packagingListJson = !empty($_POST['packaging_list_json']) ? $_POST['packaging_list_json'] : null;
            $materialListJson = !empty($_POST['material_list_json']) ? $_POST['material_list_json'] : null;
            
            if ($saveGroupId <= 0 || $recipeName === '') {
                throw new Exception('Product group and recipe name are required');
            }
            
            if ($saveRecipeId > 0) {
                // Update existing recipe
                $stmt = $pdo->prepare("
                    UPDATE product_group_recipes
                    SET recipe_name = ?, recipe_version = ?, recipe_steps = ?, 
                        ingredient_list = ?, packaging_list = ?, material_list = ?,
                        batch_yield = ?, estimated_time_minutes = ?, active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$recipeName, $recipeVersion, $recipeSteps, 
                               $ingredientListJson, $packagingListJson, $materialListJson,
                               $batchYield, $estimatedTime, $active, $saveRecipeId]);
                flash_set('success', 'Recipe updated successfully');
            } else {
                // Create new recipe
                $stmt = $pdo->prepare("
                    INSERT INTO product_group_recipes
                    (product_group_id, recipe_name, recipe_version, recipe_steps, 
                     ingredient_list, packaging_list, material_list,
                     batch_yield, estimated_time_minutes, active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$saveGroupId, $recipeName, $recipeVersion, $recipeSteps, 
                               $ingredientListJson, $packagingListJson, $materialListJson,
                               $batchYield, $estimatedTime, $active]);
                flash_set('success', 'Recipe created successfully');
            }
            redirect('/operations/product-group-recipe.php?group_id=' . $saveGroupId);
        }
    } catch (Throwable $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Get product group info
$stmt = $pdo->prepare("SELECT id, name FROM product_groups WHERE id = ? AND active = 1");
$stmt->execute([$groupId]);
$productGroup = $stmt->fetch();

if (!$productGroup) {
    flash_set('error', 'Product group not found');
    redirect('/operations/product-group-recipe.php');
}

// Get available ingredients, packaging, and materials
$stmt = $pdo->prepare("SELECT * FROM product_group_ingredients WHERE product_group_id = ? ORDER BY ingredient_name");
$stmt->execute([$groupId]);
$availableIngredients = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM product_group_packaging WHERE product_group_id = ? ORDER BY packaging_item");
$stmt->execute([$groupId]);
$availablePackaging = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM product_group_materials WHERE product_group_id = ? ORDER BY material_name");
$stmt->execute([$groupId]);
$availableMaterials = $stmt->fetchAll();

$availableGlobalMaterials = $pdo->query("SELECT * FROM global_materials WHERE is_global = 1 ORDER BY material_name")->fetchAll();

// Parse existing recipe data if editing
$ingredientList = [];
$packagingList = [];
$materialList = [];
if ($recipe) {
    $ingredientList = !empty($recipe['ingredient_list']) ? json_decode($recipe['ingredient_list'], true) : [];
    $packagingList = !empty($recipe['packaging_list']) ? json_decode($recipe['packaging_list'], true) : [];
    $materialList = !empty($recipe['material_list']) ? json_decode($recipe['material_list'], true) : [];
    
    // Debug output
    error_log("Recipe Editor - Loading Recipe ID: " . $recipe['id']);
    error_log("Ingredients: " . count($ingredientList));
    error_log("Packaging: " . count($packagingList));
    error_log("Materials: " . count($materialList));
}
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1><?php echo $recipe ? '✏️ Edit Recipe' : '✨ Create New Recipe'; ?></h1>
                <p class="muted"><?php echo htmlspecialchars($productGroup['name']); ?></p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="<?php echo path_with_base('/operations/product-group-recipe.php?group_id=' . $groupId); ?>" class="btn secondary">← Back to Recipes</a>
            </div>
        </div>

        <?php
        $successMsg = flash_get('success');
        $errorMsg = flash_get('error');
        ?>
        <?php if ($successMsg): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                ✅ <?php echo htmlspecialchars($successMsg); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($errorMsg): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                ❌ <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php endif; ?>

        <form method="post" id="recipe-form">
            <input type="hidden" name="action" value="save_recipe">
            <input type="hidden" name="recipe_id" value="<?php echo $recipeId; ?>">
            <input type="hidden" name="product_group_id" value="<?php echo $groupId; ?>">
            <input type="hidden" name="ingredient_list_json" id="ingredient_list_json">
            <input type="hidden" name="packaging_list_json" id="packaging_list_json">
            <input type="hidden" name="material_list_json" id="material_list_json">

            <!-- Basic Recipe Info -->
            <div class="card" style="margin-bottom: 24px;">
                <h2>📝 Recipe Information</h2>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Recipe Name *</label>
                        <input type="text" name="recipe_name" required value="<?php echo htmlspecialchars($recipe['recipe_name'] ?? ''); ?>"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Recipe Version</label>
                        <input type="text" name="recipe_version" value="<?php echo htmlspecialchars($recipe['recipe_version'] ?? 'v1.0'); ?>"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Batch Yield (units)</label>
                        <input type="number" name="batch_yield" value="<?php echo $recipe['batch_yield'] ?? 100; ?>" min="1" id="batch-yield" onchange="recalculateCosts()"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Estimated Production Time (minutes)</label>
                    <input type="number" name="estimated_time_minutes" value="<?php echo $recipe['estimated_time_minutes'] ?? 60; ?>" min="0"
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                
                <div>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="active" <?php echo (!$recipe || $recipe['active']) ? 'checked' : ''; ?>>
                        <span>Active Recipe</span>
                    </label>
                </div>
            </div>

            <!-- Ingredients Section -->
            <div class="card" style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h2 style="margin: 0;">🧪 Ingredients Required</h2>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-small secondary" onclick="openQuickAddIngredientModal()" style="font-size: 12px;">⚡ Quick Add New</button>
                        <button type="button" class="btn-small" onclick="addIngredientRow()" style="background: #059669; color: white;">+ Add Ingredient</button>
                    </div>
                </div>
                
                <div id="ingredients-container" style="background: #f9fafb; padding: 16px; border-radius: 8px; min-height: 100px;">
                    <?php if (empty($availableIngredients)): ?>
                    <small style="color: #dc2626;">⚠️ No ingredients in inventory. Add ingredients first in <a href="<?php echo path_with_base('/operations/product-group-ingredients.php?group_id=' . $groupId); ?>">Ingredient Inventory</a></small>
                    <?php else: ?>
                    <small style="color: #6b7280;">Click "+ Add Ingredient" to select ingredients from inventory</small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Packaging Section -->
            <div class="card" style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h2 style="margin: 0;">📦 Packaging Required</h2>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-small secondary" onclick="openQuickAddPackagingModal()" style="font-size: 12px;">⚡ Quick Add New</button>
                        <button type="button" class="btn-small" onclick="addPackagingRow()" style="background: #059669; color: white;">+ Add Packaging</button>
                    </div>
                </div>
                
                <div id="packaging-container" style="background: #f9fafb; padding: 16px; border-radius: 8px; min-height: 100px;">
                    <?php if (empty($availablePackaging)): ?>
                    <small style="color: #dc2626;">⚠️ No packaging in inventory. Add packaging first in <a href="<?php echo path_with_base('/operations/product-group-packaging.php?group_id=' . $groupId); ?>">Packaging Inventory</a></small>
                    <?php else: ?>
                    <small style="color: #6b7280;">Click "+ Add Packaging" to select packaging from inventory</small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Equipment & Materials Section -->
            <div class="card" style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h2 style="margin: 0;">🔧 Equipment & Materials</h2>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-small secondary" onclick="openQuickAddMaterialModal()" style="font-size: 12px;">⚡ Quick Add New</button>
                        <button type="button" class="btn-small" onclick="addEquipmentRow()" style="background: #059669; color: white;">+ Add Item</button>
                    </div>
                </div>
                
                <div id="equipment-container" style="background: #f9fafb; padding: 16px; border-radius: 8px; min-height: 100px;">
                    <small style="color: #6b7280;">Add equipment, tools, and materials needed. Includes both global materials and product-group-specific items.</small>
                </div>
            </div>

            <!-- Cost Summary -->
            <div class="card" style="background: #fef3c7; border-color: #f59e0b; margin-bottom: 24px;">
                <h3 style="margin: 0 0 12px 0; color: #92400e;">💰 Recipe Cost Summary (Per Batch)</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                    <div>
                        <div style="color: #78350f; font-size: 12px;">Ingredient Cost</div>
                        <div style="font-size: 18px; font-weight: 700; color: #dc2626;" id="total-ingredient-cost">$0.00</div>
                    </div>
                    <div>
                        <div style="color: #78350f; font-size: 12px;">Packaging Cost</div>
                        <div style="font-size: 18px; font-weight: 700; color: #dc2626;" id="total-packaging-cost">$0.00</div>
                    </div>
                    <div>
                        <div style="color: #78350f; font-size: 12px;">Total Recipe Cost</div>
                        <div style="font-size: 18px; font-weight: 700; color: #059669;" id="total-recipe-cost">$0.00</div>
                    </div>
                </div>
                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f59e0b;">
                    <div style="color: #78350f; font-size: 12px;">Cost per Unit (÷ <span id="yield-display">100</span> units)</div>
                    <div style="font-size: 18px; font-weight: 700; color: #059669;" id="cost-per-unit">$0.00</div>
                </div>
            </div>

            <!-- Production Steps - WYSIWYG Editor -->
            <div class="card" style="margin-bottom: 24px;">
                <h2>📋 Production Procedures</h2>
                <p style="color: #6b7280; margin-bottom: 16px;">Create detailed, styled production instructions with formatting, tables, and formulas</p>
                
                <textarea name="recipe_steps" id="recipe-steps-editor"><?php echo $recipe['recipe_steps'] ?? ''; ?></textarea>
            </div>

            <div style="text-align: right; display: flex; gap: 12px; justify-content: flex-end;">
                <a href="<?php echo path_with_base('/operations/product-group-recipe.php?group_id=' . $groupId); ?>" class="recipe-editor-btn recipe-editor-btn-cancel">Cancel</a>
                <button type="submit" class="recipe-editor-btn recipe-editor-btn-save"><?php echo $recipe ? '💾 Update Recipe' : '✨ Create Recipe'; ?></button>
            </div>
        </form>
    </div>
</section>

<!-- Quick Add Modals (same as original file) -->
<div id="quick-add-ingredient-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">⚡ Quick Add Ingredient</h3>
        <form id="quick-add-ingredient-form" onsubmit="return submitQuickAddIngredient(event);">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Ingredient Name *</label>
                    <input type="text" name="ingredient_name" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Type</label>
                    <input type="text" name="ingredient_type" placeholder="product, product, product-isolate" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Unit *</label>
                    <select name="unit_of_measure" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="grams">Grams</option>
                        <option value="kg">Kilograms</option>
                        <option value="oz">Ounces</option>
                        <option value="lb">Pounds</option>
                        <option value="ml">Milliliters</option>
                        <option value="liters">Liters</option>
                        <option value="gallons">Gallons</option>
                        <option value="mg">Milligrams</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Stock Qty</label>
                    <input type="number" name="stock_quantity" step="0.01" value="0" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cost/Unit</label>
                    <input type="number" name="cost_per_unit" step="0.01" value="0" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
            </div>
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeQuickAddIngredientModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: var(--green); color: white;">Add & Use in Recipe</button>
            </div>
        </form>
    </div>
</div>

<div id="quick-add-packaging-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">⚡ Quick Add Packaging</h3>
        <form id="quick-add-packaging-form" onsubmit="return submitQuickAddPackaging(event);">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Packaging Item *</label>
                    <input type="text" name="packaging_item" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Type</label>
                    <select name="packaging_type" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="">Select type...</option>
                        <option value="bottle">Bottle</option>
                        <option value="lid">Lid/Cap</option>
                        <option value="label">Label</option>
                        <option value="seal">Seal</option>
                        <option value="box">Box</option>
                        <option value="jar">Jar</option>
                        <option value="tube">Tube</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Stock Qty</label>
                    <input type="number" name="stock_quantity" value="0" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cost/Unit</label>
                    <input type="number" name="cost_per_unit" step="0.01" value="0" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
            </div>
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeQuickAddPackagingModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: var(--green); color: white;">Add & Use in Recipe</button>
            </div>
        </form>
    </div>
</div>

<div id="quick-add-material-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">⚡ Quick Add Material</h3>
        <form id="quick-add-material-form" onsubmit="return submitQuickAddMaterial(event);">
            <div style="margin-bottom: 16px;">
                <label style="display: flex; align-items: center; gap: 8px; font-weight: 600;">
                    <input type="checkbox" name="is_global" id="material-is-global">
                    <span>Global Material (available to all product groups)</span>
                </label>
                <small style="color: #6b7280; display: block; margin-top: 4px;">Leave unchecked for product-group-specific materials</small>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Material Name *</label>
                    <input type="text" name="material_name" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Type</label>
                    <select name="material_type" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="">Select type...</option>
                        <option value="bubble-wrap">Bubble Wrap</option>
                        <option value="tape">Tape</option>
                        <option value="shrink-wrap">Shrink Wrap</option>
                        <option value="packing-peanuts">Packing Peanuts</option>
                        <option value="boxes">Shipping Boxes</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Unit *</label>
                    <select name="unit_of_measure" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="units">Units</option>
                        <option value="feet">Feet</option>
                        <option value="meters">Meters</option>
                        <option value="rolls">Rolls</option>
                        <option value="sheets">Sheets</option>
                        <option value="boxes">Boxes</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Stock Qty</label>
                    <input type="number" name="stock_quantity" step="0.01" value="0" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cost/Unit</label>
                    <input type="number" name="cost_per_unit" step="0.01" value="0" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
            </div>
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeQuickAddMaterialModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: var(--green); color: white;">Add & Use in Recipe</button>
            </div>
        </form>
    </div>
</div>

<style>
.recipe-editor-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.recipe-editor-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.15);
}

.recipe-editor-btn:active {
    transform: translateY(0);
}

.recipe-editor-btn-cancel {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    color: white;
}

.recipe-editor-btn-cancel:hover {
    background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
}

.recipe-editor-btn-save {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: white;
}

.recipe-editor-btn-save:hover {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%);
}
</style>

<!-- TinyMCE -->
<script src="https://cdn.tiny.cloud/1/ddw9e6102mg6yj9lkghjm6xtbm5tfqdsrkbgscgabohwnzf5/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
// Debug: Recipe data available
console.log('Recipe ID: <?php echo $recipe ? $recipe['id'] : 'new'; ?>');
console.log('Ingredients available from inventory:', <?php echo count($availableIngredients); ?>);
console.log('Packaging available from inventory:', <?php echo count($availablePackaging); ?>);
<?php if ($recipe): ?>
console.log('Existing recipe data - Ingredients:', <?php echo count($ingredientList); ?>, 'Packaging:', <?php echo count($packagingList); ?>, 'Materials:', <?php echo count($materialList); ?>);
<?php endif; ?>

// Available ingredients, packaging, and materials from PHP
const availableIngredients = <?php echo json_encode($availableIngredients); ?>;
const availablePackaging = <?php echo json_encode($availablePackaging); ?>;
const availableMaterials = <?php echo json_encode($availableMaterials); ?>;
const availableGlobalMaterials = <?php echo json_encode($availableGlobalMaterials); ?>;
const selectedGroupId = <?php echo $groupId; ?>;

let ingredientCounter = 0;
let packagingCounter = 0;
let equipmentCounter = 0;

// Unit conversion factors (all to grams as base)
const unitConversions = {
    'mg': 0.001,
    'grams': 1,
    'kg': 1000,
    'oz': 28.3495,
    'lb': 453.592,
    'ml': 1,
    'liters': 1000,
    'gallons': 3785.41
};

// Add ingredient row with unit conversion
function addIngredientRow(presetData = null) {
    if (availableIngredients.length === 0) {
        alert('No ingredients available. Please add ingredients to inventory first.');
        return;
    }
    
    const container = document.getElementById('ingredients-container');
    const rowId = ++ingredientCounter;
    
    const row = document.createElement('div');
    row.className = 'ingredient-row';
    row.id = `ingredient-row-${rowId}`;
    row.style.cssText = 'display: grid; grid-template-columns: 2fr 80px 1fr 80px 1fr 60px; gap: 8px; margin-bottom: 12px; align-items: center;';
    row.innerHTML = `
        <select class="ingredient-select" data-row-id="${rowId}" onchange="updateIngredientCost(${rowId})" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
            <option value="">Select ingredient...</option>
            ${availableIngredients.map(ing => `<option value="${ing.id}" data-cost="${ing.cost_per_unit}" data-unit="${ing.unit_of_measure}" ${presetData && presetData.ingredient_id == ing.id ? 'selected' : ''}>${ing.ingredient_name}</option>`).join('')}
        </select>
        <input type="number" step="0.001" min="0" placeholder="Qty" class="ingredient-quantity" data-row-id="${rowId}" onchange="updateIngredientCost(${rowId})" value="${presetData ? presetData.quantity : ''}" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
        <select class="ingredient-use-unit" data-row-id="${rowId}" onchange="updateIngredientCost(${rowId})" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px;">
            <option value="mg" ${presetData && presetData.unit === 'mg' ? 'selected' : ''}>mg</option>
            <option value="grams" ${!presetData || presetData.unit === 'grams' ? 'selected' : ''}>grams</option>
            <option value="kg" ${presetData && presetData.unit === 'kg' ? 'selected' : ''}>kg</option>
            <option value="oz" ${presetData && presetData.unit === 'oz' ? 'selected' : ''}>oz</option>
            <option value="lb" ${presetData && presetData.unit === 'lb' ? 'selected' : ''}>lb</option>
            <option value="ml" ${presetData && presetData.unit === 'ml' ? 'selected' : ''}>ml</option>
            <option value="liters" ${presetData && presetData.unit === 'liters' ? 'selected' : ''}>liters</option>
            <option value="gallons" ${presetData && presetData.unit === 'gallons' ? 'selected' : ''}>gallons</option>
        </select>
        <div class="ingredient-stock-unit" id="ing-stock-${rowId}" style="font-size: 11px; color: #6b7280;">-</div>
        <div class="ingredient-cost" id="ing-cost-${rowId}" style="font-weight: 600; color: #059669; font-size: 14px;">$0.00</div>
        <button type="button" onclick="removeIngredientRow(${rowId})" style="background: #dc2626; color: white; border: none; border-radius: 4px; padding: 8px; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
    
    if (presetData) {
        setTimeout(() => updateIngredientCost(rowId), 100);
    }
}

function updateIngredientCost(rowId) {
    const row = document.getElementById(`ingredient-row-${rowId}`);
    const select = row.querySelector('.ingredient-select');
    const qtyInput = row.querySelector('.ingredient-quantity');
    const useUnitSelect = row.querySelector('.ingredient-use-unit');
    const stockDisplay = row.querySelector('.ingredient-stock-unit');
    const costDisplay = row.querySelector('.ingredient-cost');
    
    const selectedOption = select.options[select.selectedIndex];
    const costPerStorageUnit = parseFloat(selectedOption.dataset.cost) || 0;
    const storageUnit = selectedOption.dataset.unit || 'grams';
    const recipeQty = parseFloat(qtyInput.value) || 0;
    const recipeUnit = useUnitSelect.value;
    
    if (!recipeQty || !selectedOption.value) {
        costDisplay.textContent = '$0.00';
        stockDisplay.textContent = '-';
        recalculateCosts();
        return;
    }
    
    const recipeInBase = recipeQty * (unitConversions[recipeUnit] || 1);
    const storageInBase = 1 * (unitConversions[storageUnit] || 1);
    const quantityInStorageUnits = recipeInBase / storageInBase;
    const totalCost = costPerStorageUnit * quantityInStorageUnits;
    
    stockDisplay.textContent = `= ${quantityInStorageUnits.toFixed(3)} ${storageUnit}`;
    costDisplay.textContent = '$' + totalCost.toFixed(2);
    
    recalculateCosts();
}

function removeIngredientRow(rowId) {
    document.getElementById(`ingredient-row-${rowId}`)?.remove();
    recalculateCosts();
}

function addPackagingRow(presetData = null) {
    if (availablePackaging.length === 0) {
        alert('No packaging available. Please add packaging to inventory first.');
        return;
    }
    
    const container = document.getElementById('packaging-container');
    const rowId = ++packagingCounter;
    
    const row = document.createElement('div');
    row.className = 'packaging-row';
    row.id = `packaging-row-${rowId}`;
    row.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 1fr 60px; gap: 12px; margin-bottom: 12px; align-items: center;';
    row.innerHTML = `
        <select class="packaging-select" data-row-id="${rowId}" onchange="updatePackagingCost(${rowId})" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
            <option value="">Select packaging...</option>
            ${availablePackaging.map(pkg => `<option value="${pkg.id}" data-cost="${pkg.cost_per_unit}" ${presetData && presetData.packaging_id == pkg.id ? 'selected' : ''}>${pkg.packaging_item} (${pkg.packaging_type})</option>`).join('')}
        </select>
        <input type="number" min="0" placeholder="Quantity" class="packaging-quantity" data-row-id="${rowId}" onchange="updatePackagingCost(${rowId})" value="${presetData ? presetData.quantity : ''}" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
        <div class="packaging-cost" id="pkg-cost-${rowId}" style="font-weight: 600; color: #059669;">$0.00</div>
        <button type="button" onclick="removePackagingRow(${rowId})" style="background: #dc2626; color: white; border: none; border-radius: 4px; padding: 8px; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
    
    if (presetData) {
        setTimeout(() => updatePackagingCost(rowId), 100);
    }
}

function updatePackagingCost(rowId) {
    const row = document.getElementById(`packaging-row-${rowId}`);
    const select = row.querySelector('.packaging-select');
    const qtyInput = row.querySelector('.packaging-quantity');
    const costDisplay = row.querySelector('.packaging-cost');
    
    const selectedOption = select.options[select.selectedIndex];
    const costPerUnit = parseFloat(selectedOption.dataset.cost) || 0;
    const quantity = parseInt(qtyInput.value) || 0;
    const totalCost = costPerUnit * quantity;
    
    costDisplay.textContent = '$' + totalCost.toFixed(2);
    recalculateCosts();
}

function removePackagingRow(rowId) {
    document.getElementById(`packaging-row-${rowId}`)?.remove();
    recalculateCosts();
}

function addEquipmentRow(presetData = null) {
    const container = document.getElementById('equipment-container');
    const rowId = ++equipmentCounter;
    
    const row = document.createElement('div');
    row.className = 'equipment-row';
    row.id = `equipment-row-${rowId}`;
    row.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 60px; gap: 12px; margin-bottom: 12px; align-items: center;';
    row.innerHTML = `
        <input type="text" placeholder="Equipment/Material name" class="equipment-name" data-row-id="${rowId}" value="${presetData ? presetData.name : ''}" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
        <input type="text" placeholder="Notes/specifications" class="equipment-notes" data-row-id="${rowId}" value="${presetData ? presetData.notes : ''}" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
        <button type="button" onclick="removeEquipmentRow(${rowId})" style="background: #dc2626; color: white; border: none; border-radius: 4px; padding: 8px; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

function removeEquipmentRow(rowId) {
    document.getElementById(`equipment-row-${rowId}`)?.remove();
}

function recalculateCosts() {
    let totalIngredientCost = 0;
    let totalPackagingCost = 0;
    
    document.querySelectorAll('.ingredient-cost').forEach(costDisplay => {
        const costText = costDisplay.textContent.replace('$', '');
        const cost = parseFloat(costText) || 0;
        totalIngredientCost += cost;
    });
    
    document.querySelectorAll('.packaging-cost').forEach(costDisplay => {
        const costText = costDisplay.textContent.replace('$', '');
        const cost = parseFloat(costText) || 0;
        totalPackagingCost += cost;
    });
    
    const totalRecipeCost = totalIngredientCost + totalPackagingCost;
    const batchYield = parseInt(document.getElementById('batch-yield').value) || 100;
    const costPerUnit = batchYield > 0 ? (totalRecipeCost / batchYield) : 0;
    
    document.getElementById('total-ingredient-cost').textContent = '$' + totalIngredientCost.toFixed(2);
    document.getElementById('total-packaging-cost').textContent = '$' + totalPackagingCost.toFixed(2);
    document.getElementById('total-recipe-cost').textContent = '$' + totalRecipeCost.toFixed(2);
    document.getElementById('cost-per-unit').textContent = '$' + costPerUnit.toFixed(2);
    document.getElementById('yield-display').textContent = batchYield;
}

// Collect recipe data and submit
document.getElementById('recipe-form')?.addEventListener('submit', function(e) {
    // Sync TinyMCE content before submission
    if (typeof tinymce !== 'undefined' && tinymce.get('recipe-steps-editor')) {
        tinymce.get('recipe-steps-editor').save();
    }
    
    // Collect ingredients
    const ingredients = [];
    document.querySelectorAll('.ingredient-row').forEach(row => {
        const select = row.querySelector('.ingredient-select');
        const qtyInput = row.querySelector('.ingredient-quantity');
        const useUnitSelect = row.querySelector('.ingredient-use-unit');
        if (select.value && qtyInput.value) {
            const selectedOption = select.options[select.selectedIndex];
            const storageUnit = selectedOption.dataset.unit;
            const recipeUnit = useUnitSelect.value;
            const recipeQty = parseFloat(qtyInput.value);
            
            const recipeInBase = recipeQty * (unitConversions[recipeUnit] || 1);
            const storageInBase = 1 * (unitConversions[storageUnit] || 1);
            const quantityInStorageUnits = recipeInBase / storageInBase;
            
            ingredients.push({
                ingredient_id: parseInt(select.value),
                ingredient_name: selectedOption.textContent.trim(),
                quantity: parseFloat(qtyInput.value),
                unit: recipeUnit,
                storage_quantity: quantityInStorageUnits,
                storage_unit: storageUnit,
                cost_per_unit: parseFloat(selectedOption.dataset.cost)
            });
        }
    });
    
    const packaging = [];
    document.querySelectorAll('.packaging-row').forEach(row => {
        const select = row.querySelector('.packaging-select');
        const qtyInput = row.querySelector('.packaging-quantity');
        if (select.value && qtyInput.value) {
            const selectedOption = select.options[select.selectedIndex];
            packaging.push({
                packaging_id: parseInt(select.value),
                packaging_name: selectedOption.textContent.split('(')[0].trim(),
                quantity: parseInt(qtyInput.value),
                cost_per_unit: parseFloat(selectedOption.dataset.cost)
            });
        }
    });
    
    const equipment = [];
    document.querySelectorAll('.equipment-row').forEach(row => {
        const nameInput = row.querySelector('.equipment-name');
        const notesInput = row.querySelector('.equipment-notes');
        if (nameInput.value) {
            equipment.push({
                name: nameInput.value,
                notes: notesInput.value || ''
            });
        }
    });
    
    document.getElementById('ingredient_list_json').value = JSON.stringify(ingredients);
    document.getElementById('packaging_list_json').value = JSON.stringify(packaging);
    document.getElementById('material_list_json').value = JSON.stringify(equipment);
});

// Quick Add Modal Functions (same as original)
function openQuickAddIngredientModal() {
    document.getElementById('quick-add-ingredient-modal').style.display = 'flex';
}

function closeQuickAddIngredientModal() {
    document.getElementById('quick-add-ingredient-modal').style.display = 'none';
    document.getElementById('quick-add-ingredient-form').reset();
}

function submitQuickAddIngredient(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'add_ingredient');
    formData.append('product_group_id', selectedGroupId);
    
    fetch('<?php echo path_with_base('/api/quick-add-inventory.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            availableIngredients.push({
                id: data.id,
                ingredient_name: data.name,
                ingredient_type: data.type,
                unit_of_measure: data.unit,
                cost_per_unit: data.cost_per_unit
            });
            alert('✅ ' + data.message + '\n\nThe page will refresh to show the new ingredient.');
            window.location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('An error occurred while adding the ingredient.');
    });
    
    return false;
}

function openQuickAddPackagingModal() {
    document.getElementById('quick-add-packaging-modal').style.display = 'flex';
}

function closeQuickAddPackagingModal() {
    document.getElementById('quick-add-packaging-modal').style.display = 'none';
    document.getElementById('quick-add-packaging-form').reset();
}

function submitQuickAddPackaging(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'add_packaging');
    formData.append('product_group_id', selectedGroupId);
    
    fetch('<?php echo path_with_base('/api/quick-add-inventory.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            availablePackaging.push({
                id: data.id,
                packaging_item: data.name,
                packaging_type: data.type,
                cost_per_unit: data.cost_per_unit
            });
            alert('✅ ' + data.message + '\n\nThe page will refresh to show the new packaging.');
            window.location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('An error occurred while adding the packaging.');
    });
    
    return false;
}

function openQuickAddMaterialModal() {
    document.getElementById('quick-add-material-modal').style.display = 'flex';
}

function closeQuickAddMaterialModal() {
    document.getElementById('quick-add-material-modal').style.display = 'none';
    document.getElementById('quick-add-material-form').reset();
}

function submitQuickAddMaterial(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    const isGlobal = formData.get('is_global') === 'on';
    
    formData.append('action', isGlobal ? 'add_global_material' : 'add_material');
    if (!isGlobal) {
        formData.append('product_group_id', selectedGroupId);
    }
    
    fetch('<?php echo path_with_base('/api/quick-add-inventory.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const materialData = {
                id: data.id,
                material_name: data.name,
                material_type: data.type,
                unit_of_measure: data.unit,
                cost_per_unit: data.cost_per_unit,
                is_global: data.is_global
            };
            
            if (data.is_global) {
                availableGlobalMaterials.push(materialData);
            } else {
                availableMaterials.push(materialData);
            }
            
            alert('✅ ' + data.message + '\n\nThe page will refresh to show the new material.');
            window.location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('An error occurred while adding the material.');
    });
    
    return false;
}

// Close modals on outside click
document.getElementById('quick-add-ingredient-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeQuickAddIngredientModal();
});
document.getElementById('quick-add-packaging-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeQuickAddPackagingModal();
});
document.getElementById('quick-add-material-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeQuickAddMaterialModal();
});

// ESC key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeQuickAddIngredientModal();
        closeQuickAddPackagingModal();
        closeQuickAddMaterialModal();
    }
});

// Initialize everything when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing TinyMCE...');
    
    // Initialize TinyMCE
    tinymce.init({
        selector: '#recipe-steps-editor',
        plugins: 'lists table image link code preview anchor searchreplace visualblocks fullscreen insertdatetime media help wordcount',
        toolbar: 'undo redo | formatselect | bold italic underline | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table image link | removeformat code preview fullscreen help',
        menubar: 'edit view insert format table',
        height: 600,
        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.6; }',
        table_default_styles: {
            'border-collapse': 'collapse',
            'width': '100%'
        },
        table_default_attributes: {
            'border': '1'
        },
        promotion: false,
        branding: false,
        setup: function(editor) {
            editor.on('init', function() {
                console.log('TinyMCE initialized successfully!');
            });
        }
    });

    // Load existing recipe data if editing
    <?php if ($recipe): ?>
    console.log('Editing existing recipe...');
    <?php if (!empty($ingredientList)): ?>
    // Clear placeholder text
    const ingredientsContainer = document.getElementById('ingredients-container');
    if (ingredientsContainer) {
        ingredientsContainer.innerHTML = '';
    }
    console.log('Loading <?php echo count($ingredientList); ?> ingredients...');
    <?php foreach ($ingredientList as $ing): ?>
    try {
        addIngredientRow(<?php echo json_encode($ing); ?>);
    } catch (err) {
        console.error('Error loading ingredient:', err, <?php echo json_encode($ing); ?>);
    }
    <?php endforeach; ?>
    console.log('Ingredients loaded successfully');
    <?php else: ?>
    console.log('No ingredients to load');
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($recipe): ?>
    <?php if (!empty($packagingList)): ?>
    // Clear placeholder text
    const packagingContainer = document.getElementById('packaging-container');
    if (packagingContainer) {
        packagingContainer.innerHTML = '';
    }
    console.log('Loading <?php echo count($packagingList); ?> packaging items...');
    <?php foreach ($packagingList as $pkg): ?>
    try {
        addPackagingRow(<?php echo json_encode($pkg); ?>);
    } catch (err) {
        console.error('Error loading packaging:', err, <?php echo json_encode($pkg); ?>);
    }
    <?php endforeach; ?>
    console.log('Packaging loaded successfully');
    <?php else: ?>
    console.log('No packaging to load');
    <?php endif; ?>

    <?php if (!empty($materialList)): ?>
    // Clear placeholder text
    const equipmentContainer = document.getElementById('equipment-container');
    if (equipmentContainer) {
        equipmentContainer.innerHTML = '';
    }
    console.log('Loading <?php echo count($materialList); ?> materials/equipment...');
    <?php foreach ($materialList as $mat): ?>
    try {
        addEquipmentRow(<?php echo json_encode($mat); ?>);
    } catch (err) {
        console.error('Error loading material:', err, <?php echo json_encode($mat); ?>);
    }
    <?php endforeach; ?>
    console.log('Materials loaded successfully');
    <?php else: ?>
    console.log('No materials to load');
    <?php endif; ?>
    <?php endif; ?>

    // Initial cost calculation if editing
    <?php if ($recipe): ?>
    setTimeout(recalculateCosts, 300);
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

