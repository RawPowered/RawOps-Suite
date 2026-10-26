<?php
$SEO = [
    'title' => 'Ingredient Inventory — Operations',
    'description' => 'Manage product group ingredient inventory with multi-unit tracking.',
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

// Get selected product group
$selectedGroupId = intval($_GET['group_id'] ?? 0);

// Handle ingredient actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add_ingredient') {
            $groupId = intval($_POST['product_group_id'] ?? 0);
            $name = trim($_POST['ingredient_name'] ?? '');
            $type = trim($_POST['ingredient_type'] ?? '');
            $stockQty = floatval($_POST['stock_quantity'] ?? 0);
            $unitOfMeasure = trim($_POST['unit_of_measure'] ?? 'grams');
            $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
            $lowStockThreshold = floatval($_POST['low_stock_threshold'] ?? 0);
            $supplier = trim($_POST['supplier'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if ($groupId <= 0 || $name === '') {
                throw new Exception('Product group and ingredient name are required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO product_group_ingredients
                (product_group_id, ingredient_name, ingredient_type, stock_quantity, unit_of_measure,
                 cost_per_unit, low_stock_threshold, supplier, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$groupId, $name, $type, $stockQty, $unitOfMeasure, $costPerUnit, $lowStockThreshold, $supplier, $notes]);
            flash_set('success', 'Ingredient added successfully');
            header('Location: ' . path_with_base('/operations/product-group-ingredients.php?group_id=' . $groupId));
            exit;
            
        } elseif ($action === 'update_ingredient') {
            $ingredientId = intval($_POST['ingredient_id'] ?? 0);
            $name = trim($_POST['ingredient_name'] ?? '');
            $type = trim($_POST['ingredient_type'] ?? '');
            $unitOfMeasure = trim($_POST['unit_of_measure'] ?? 'grams');
            $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
            $lowStockThreshold = floatval($_POST['low_stock_threshold'] ?? 0);
            $supplier = trim($_POST['supplier'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if ($ingredientId <= 0 || $name === '') {
                throw new Exception('Ingredient ID and name are required');
            }
            
            $stmt = $pdo->prepare("
                UPDATE product_group_ingredients SET
                    ingredient_name = ?,
                    ingredient_type = ?,
                    unit_of_measure = ?,
                    cost_per_unit = ?,
                    low_stock_threshold = ?,
                    supplier = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$name, $type, $unitOfMeasure, $costPerUnit, $lowStockThreshold, $supplier, $notes, $ingredientId]);
            flash_set('success', 'Ingredient updated successfully');
            header('Location: ' . path_with_base('/operations/product-group-ingredients.php?group_id=' . $selectedGroupId));
            exit;
            
        } elseif ($action === 'update_stock') {
            $ingredientId = intval($_POST['ingredient_id'] ?? 0);
            $newStock = floatval($_POST['new_stock'] ?? 0);
            
            if ($ingredientId <= 0) throw new Exception('Invalid ingredient ID');
            
            $stmt = $pdo->prepare("UPDATE product_group_ingredients SET stock_quantity = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStock, $ingredientId]);
            flash_set('success', 'Stock updated successfully');
            header('Location: ' . path_with_base('/operations/product-group-ingredients.php?group_id=' . $selectedGroupId));
            exit;
            
        } elseif ($action === 'delete_ingredient') {
            $ingredientId = intval($_POST['ingredient_id'] ?? 0);
            if ($ingredientId <= 0) throw new Exception('Invalid ingredient ID');
            
            // Get product group ID before deleting
            $stmt = $pdo->prepare("SELECT product_group_id FROM product_group_ingredients WHERE id = ?");
            $stmt->execute([$ingredientId]);
            $groupId = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM product_group_ingredients WHERE id = ?");
            $stmt->execute([$ingredientId]);
            flash_set('success', 'Ingredient deleted successfully');
            header('Location: ' . path_with_base('/operations/product-group-ingredients.php?group_id=' . $groupId));
            exit;
        }
    } catch (Throwable $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
        header('Location: ' . path_with_base('/operations/product-group-ingredients.php?group_id=' . $selectedGroupId));
        exit;
    }
}

// Fetch product groups
$productGroups = $pdo->query("SELECT id, name FROM product_groups WHERE active = 1 ORDER BY name")->fetchAll();

// Fetch ingredients for selected group
$ingredients = [];
if ($selectedGroupId > 0) {
    $stmt = $pdo->prepare("
        SELECT i.*, pg.name as group_name,
               CASE WHEN i.stock_quantity <= i.low_stock_threshold THEN 1 ELSE 0 END as is_low_stock
        FROM product_group_ingredients i
        JOIN product_groups pg ON pg.id = i.product_group_id
        WHERE i.product_group_id = ?
        ORDER BY i.ingredient_type, i.ingredient_name
    ");
    $stmt->execute([$selectedGroupId]);
    $ingredients = $stmt->fetchAll();
}
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>🧪 Ingredient Inventory</h1>
                <p class="muted">Manage product group ingredient inventory with multi-unit tracking</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="<?php echo path_with_base('/operations/'); ?>" class="btn secondary">← Back to Operations</a>
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

        <!-- Product Group Selector -->
        <div class="card" style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Select Product Group</label>
            <select onchange="window.location.href='?group_id=' + this.value" 
                    style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                <option value="">Select a product group...</option>
                <?php foreach ($productGroups as $pg): ?>
                <option value="<?php echo $pg['id']; ?>" <?php echo $selectedGroupId == $pg['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pg['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($selectedGroupId > 0): ?>
        <!-- Add Ingredient Form -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>Add New Ingredient</h2>
            <form method="post">
                <input type="hidden" name="action" value="add_ingredient">
                <input type="hidden" name="product_group_id" value="<?php echo $selectedGroupId; ?>">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Ingredient Name *</label>
                        <input type="text" name="ingredient_name" required 
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Ingredient Type</label>
                        <input type="text" name="ingredient_type" placeholder="e.g., product, product, product-isolate"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Unit of Measure *</label>
                        <select name="unit_of_measure" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="grams">Grams</option>
                            <option value="kg">Kilograms</option>
                            <option value="oz">Ounces</option>
                            <option value="lb">Pounds</option>
                            <option value="ml">Milliliters</option>
                            <option value="liters">Liters</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Initial Stock Quantity</label>
                        <input type="number" name="stock_quantity" step="0.01" value="0"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cost per Unit</label>
                        <input type="number" name="cost_per_unit" step="0.01" value="0"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Low Stock Threshold</label>
                        <input type="number" name="low_stock_threshold" step="0.01" value="0"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Supplier</label>
                        <input type="text" name="supplier" placeholder="Optional"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Notes</label>
                    <textarea name="notes" rows="2" placeholder="Optional notes..."
                              style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"></textarea>
                </div>
                
                <div style="text-align: right;">
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Add Ingredient</button>
                </div>
            </form>
        </div>

        <!-- Ingredient List -->
        <div class="card">
            <h2>Ingredients (<?php echo count($ingredients); ?>)</h2>
            <?php if (empty($ingredients)): ?>
                <p style="color: #6b7280; text-align: center; padding: 40px 20px;">No ingredients yet. Add your first ingredient above.</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Ingredient</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Type</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Stock</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Cost/Unit</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Total Value</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Supplier</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ingredients as $ing): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px;">
                                <strong><?php echo htmlspecialchars($ing['ingredient_name']); ?></strong>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($ing['ingredient_type'] ?? '—'); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <span style="font-weight: bold; color: <?php echo $ing['is_low_stock'] ? '#dc2626' : '#059669'; ?>;">
                                    <?php echo number_format($ing['stock_quantity'], 2); ?> <?php echo htmlspecialchars($ing['unit_of_measure']); ?>
                                </span>
                                <?php if ($ing['is_low_stock']): ?>
                                <div style="font-size: 11px; color: #dc2626;">LOW STOCK</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                $<?php echo number_format($ing['cost_per_unit'], 4); ?>
                            </td>
                            <td style="padding: 12px; text-align: center; font-weight: 600; color: #059669;">
                                $<?php echo number_format($ing['stock_quantity'] * $ing['cost_per_unit'], 2); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php echo htmlspecialchars($ing['supplier'] ?? '—'); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <button onclick="editIngredient(<?php echo $ing['id']; ?>)" 
                                        class="btn-small" style="background: #3b82f6; color: white; margin-bottom: 4px;">Edit</button>
                                <button onclick="updateStock(<?php echo $ing['id']; ?>, '<?php echo htmlspecialchars($ing['ingredient_name'], ENT_QUOTES); ?>', <?php echo $ing['stock_quantity']; ?>)" 
                                        class="btn-small secondary" style="margin-bottom: 4px;">Update Stock</button>
                                <form method="post" style="display: inline; margin-left: 4px;" onsubmit="return confirm('Delete this ingredient?');">
                                    <input type="hidden" name="action" value="delete_ingredient">
                                    <input type="hidden" name="ingredient_id" value="<?php echo $ing['id']; ?>">
                                    <button type="submit" class="btn-small" style="background: #dc2626; color: white;">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Edit Ingredient Modal -->
<div id="edit-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">Edit Ingredient</h3>
        <form method="post" id="edit-form">
            <input type="hidden" name="action" value="update_ingredient">
            <input type="hidden" name="ingredient_id" id="edit-ingredient-id">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Ingredient Name *</label>
                    <input type="text" name="ingredient_name" id="edit-ingredient-name" required
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Ingredient Type</label>
                    <input type="text" name="ingredient_type" id="edit-ingredient-type"
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Unit of Measure *</label>
                    <select name="unit_of_measure" id="edit-unit-of-measure" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <option value="grams">Grams</option>
                        <option value="kg">Kilograms</option>
                        <option value="oz">Ounces</option>
                        <option value="lb">Pounds</option>
                        <option value="ml">Milliliters</option>
                        <option value="liters">Liters</option>
                        <option value="mg">Milligrams</option>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cost per Unit</label>
                    <input type="number" name="cost_per_unit" id="edit-cost-per-unit" step="0.01" min="0"
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Low Stock Threshold</label>
                    <input type="number" name="low_stock_threshold" id="edit-low-stock-threshold" step="0.01" min="0"
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Supplier</label>
                <input type="text" name="supplier" id="edit-supplier"
                       style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Notes</label>
                <textarea name="notes" id="edit-notes" rows="3"
                          style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"></textarea>
            </div>
            
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeEditModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: var(--green); color: white;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Stock Update Modal -->
<div id="stock-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 500px; width: 90%;">
        <h3 style="margin: 0 0 16px 0;">Update Stock Level</h3>
        <form method="post" id="stock-form">
            <input type="hidden" name="action" value="update_stock">
            <input type="hidden" name="ingredient_id" id="modal-ingredient-id">
            
            <div style="margin-bottom: 16px;">
                <strong id="modal-ingredient-name"></strong>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; margin-bottom: 4px;">Current Stock</label>
                    <input type="text" id="modal-current-stock" readonly style="background: #f8fafc; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; width: 100%;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px;">New Stock Level</label>
                    <input type="number" name="new_stock" id="modal-new-stock" step="0.01" min="0" required
                           style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; width: 100%;">
                </div>
            </div>
            
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeStockModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: var(--green); color: white;">Update Stock</button>
            </div>
        </form>
    </div>
</div>

<script>
// Ingredients data for edit modal
const ingredientsData = <?php echo json_encode($ingredients); ?>;

// Edit ingredient
function editIngredient(ingredientId) {
    const ingredient = ingredientsData.find(i => i.id == ingredientId);
    if (!ingredient) {
        alert('Ingredient not found');
        return;
    }
    
    document.getElementById('edit-ingredient-id').value = ingredient.id;
    document.getElementById('edit-ingredient-name').value = ingredient.ingredient_name || '';
    document.getElementById('edit-ingredient-type').value = ingredient.ingredient_type || '';
    document.getElementById('edit-unit-of-measure').value = ingredient.unit_of_measure || 'grams';
    document.getElementById('edit-cost-per-unit').value = ingredient.cost_per_unit || '0';
    document.getElementById('edit-low-stock-threshold').value = ingredient.low_stock_threshold || '0';
    document.getElementById('edit-supplier').value = ingredient.supplier || '';
    document.getElementById('edit-notes').value = ingredient.notes || '';
    
    document.getElementById('edit-modal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
    document.getElementById('edit-form').reset();
}

function updateStock(ingredientId, ingredientName, currentStock) {
    document.getElementById('modal-ingredient-id').value = ingredientId;
    document.getElementById('modal-ingredient-name').textContent = ingredientName;
    document.getElementById('modal-current-stock').value = currentStock;
    document.getElementById('modal-new-stock').value = currentStock;
    document.getElementById('stock-modal').style.display = 'flex';
}

function closeStockModal() {
    document.getElementById('stock-modal').style.display = 'none';
    document.getElementById('stock-form').reset();
}

// Close modals when clicking outside
document.getElementById('edit-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

document.getElementById('stock-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeStockModal();
});

// ESC key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
        closeStockModal();
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

