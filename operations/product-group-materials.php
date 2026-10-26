<?php
$SEO = [
    'title' => 'Materials Inventory — Operations',
    'description' => 'Manage shipping and production materials inventory.',
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

$selectedGroupId = intval($_GET['group_id'] ?? 0);

// Handle material actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add_material') {
            $groupId = intval($_POST['product_group_id'] ?? 0);
            $name = trim($_POST['material_name'] ?? '');
            $type = trim($_POST['material_type'] ?? '');
            $stockQty = floatval($_POST['stock_quantity'] ?? 0);
            $unitOfMeasure = trim($_POST['unit_of_measure'] ?? 'units');
            $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
            $lowStockThreshold = floatval($_POST['low_stock_threshold'] ?? 0);
            $supplier = trim($_POST['supplier'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if ($groupId <= 0 || $name === '') {
                throw new Exception('Product group and material name are required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO product_group_materials
                (product_group_id, material_name, material_type, stock_quantity, unit_of_measure,
                 cost_per_unit, low_stock_threshold, supplier, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$groupId, $name, $type, $stockQty, $unitOfMeasure, $costPerUnit, $lowStockThreshold, $supplier, $notes]);
            flash_set('success', 'Material added successfully');
            header('Location: ' . path_with_base('/operations/product-group-materials.php?group_id=' . $groupId));
            exit;
            
        } elseif ($action === 'update_stock') {
            $materialId = intval($_POST['material_id'] ?? 0);
            $newStock = floatval($_POST['new_stock'] ?? 0);
            
            if ($materialId <= 0) throw new Exception('Invalid material ID');
            
            // Get product group ID for redirect
            $stmt = $pdo->prepare("SELECT product_group_id FROM product_group_materials WHERE id = ?");
            $stmt->execute([$materialId]);
            $groupId = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("UPDATE product_group_materials SET stock_quantity = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStock, $materialId]);
            flash_set('success', 'Stock updated successfully');
            header('Location: ' . path_with_base('/operations/product-group-materials.php?group_id=' . $groupId));
            exit;
            
        } elseif ($action === 'delete_material') {
            $materialId = intval($_POST['material_id'] ?? 0);
            if ($materialId <= 0) throw new Exception('Invalid material ID');
            
            // Get product group ID before deleting
            $stmt = $pdo->prepare("SELECT product_group_id FROM product_group_materials WHERE id = ?");
            $stmt->execute([$materialId]);
            $groupId = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM product_group_materials WHERE id = ?");
            $stmt->execute([$materialId]);
            flash_set('success', 'Material deleted successfully');
            header('Location: ' . path_with_base('/operations/product-group-materials.php?group_id=' . $groupId));
            exit;
        }
    } catch (Throwable $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
        header('Location: ' . path_with_base('/operations/product-group-materials.php?group_id=' . $selectedGroupId));
        exit;
    }
}

// Fetch product groups
$productGroups = $pdo->query("SELECT id, name FROM product_groups WHERE active = 1 ORDER BY name")->fetchAll();

// Fetch materials for selected group
$materials = [];
if ($selectedGroupId > 0) {
    $stmt = $pdo->prepare("
        SELECT m.*, pg.name as group_name,
               CASE WHEN m.stock_quantity <= m.low_stock_threshold THEN 1 ELSE 0 END as is_low_stock
        FROM product_group_materials m
        JOIN product_groups pg ON pg.id = m.product_group_id
        WHERE m.product_group_id = ?
        ORDER BY m.material_type, m.material_name
    ");
    $stmt->execute([$selectedGroupId]);
    $materials = $stmt->fetchAll();
}
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>📦 Materials Inventory</h1>
                <p class="muted">Manage bubble wrap, tape, shrink wrap, and shipping materials</p>
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
        <!-- Add Material Form -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>Add New Material</h2>
            <form method="post">
                <input type="hidden" name="action" value="add_material">
                <input type="hidden" name="product_group_id" value="<?php echo $selectedGroupId; ?>">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Material Name *</label>
                        <input type="text" name="material_name" required 
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Material Type</label>
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
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Unit of Measure *</label>
                        <select name="unit_of_measure" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="units">Units</option>
                            <option value="feet">Feet</option>
                            <option value="meters">Meters</option>
                            <option value="rolls">Rolls</option>
                            <option value="sheets">Sheets</option>
                            <option value="boxes">Boxes</option>
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
                
                <div style="text-align: right;">
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Add Material</button>
                </div>
            </form>
        </div>

        <!-- Materials List -->
        <div class="card">
            <h2>Materials (<?php echo count($materials); ?>)</h2>
            <?php if (empty($materials)): ?>
                <p style="color: #6b7280; text-align: center; padding: 40px 20px;">No materials yet.</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Material</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Type</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Stock</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Cost/Unit</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Total Value</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materials as $mat): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px;">
                                <strong><?php echo htmlspecialchars($mat['material_name']); ?></strong>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($mat['material_type'] ?? '—'); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <span style="font-weight: bold; color: <?php echo $mat['is_low_stock'] ? '#dc2626' : '#059669'; ?>;">
                                    <?php echo number_format($mat['stock_quantity'], 2); ?> <?php echo htmlspecialchars($mat['unit_of_measure']); ?>
                                </span>
                                <?php if ($mat['is_low_stock']): ?>
                                <div style="font-size: 11px; color: #dc2626;">LOW STOCK</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                $<?php echo number_format($mat['cost_per_unit'], 4); ?>
                            </td>
                            <td style="padding: 12px; text-align: center; font-weight: 600; color: #059669;">
                                $<?php echo number_format($mat['stock_quantity'] * $mat['cost_per_unit'], 2); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <button onclick="updateStock(<?php echo $mat['id']; ?>, '<?php echo htmlspecialchars($mat['material_name'], ENT_QUOTES); ?>', <?php echo $mat['stock_quantity']; ?>)" 
                                        class="btn-small secondary">Update Stock</button>
                                <form method="post" style="display: inline; margin-left: 4px;" onsubmit="return confirm('Delete this material?');">
                                    <input type="hidden" name="action" value="delete_material">
                                    <input type="hidden" name="material_id" value="<?php echo $mat['id']; ?>">
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

<!-- Stock Update Modal -->
<div id="stock-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 500px; width: 90%;">
        <h3 style="margin: 0 0 16px 0;">Update Stock Level</h3>
        <form method="post" id="stock-form">
            <input type="hidden" name="action" value="update_stock">
            <input type="hidden" name="material_id" id="modal-material-id">
            
            <div style="margin-bottom: 16px;">
                <strong id="modal-material-name"></strong>
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
function updateStock(materialId, materialName, currentStock) {
    document.getElementById('modal-material-id').value = materialId;
    document.getElementById('modal-material-name').textContent = materialName;
    document.getElementById('modal-current-stock').value = currentStock;
    document.getElementById('modal-new-stock').value = currentStock;
    document.getElementById('stock-modal').style.display = 'flex';
}

function closeStockModal() {
    document.getElementById('stock-modal').style.display = 'none';
    document.getElementById('stock-form').reset();
}

document.getElementById('stock-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeStockModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeStockModal();
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


