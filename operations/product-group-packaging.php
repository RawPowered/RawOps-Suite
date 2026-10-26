<?php
$SEO = [
    'title' => 'Packaging Inventory — Operations',
    'description' => 'Manage product group packaging inventory.',
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

// Handle packaging actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add_packaging') {
            $groupId = intval($_POST['product_group_id'] ?? 0);
            $item = trim($_POST['packaging_item'] ?? '');
            $type = trim($_POST['packaging_type'] ?? '');
            $stockQty = intval($_POST['stock_quantity'] ?? 0);
            $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
            $lowStockThreshold = intval($_POST['low_stock_threshold'] ?? 10);
            $supplier = trim($_POST['supplier'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if ($groupId <= 0 || $item === '') {
                throw new Exception('Product group and packaging item are required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO product_group_packaging
                (product_group_id, packaging_item, packaging_type, stock_quantity,
                 cost_per_unit, low_stock_threshold, supplier, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$groupId, $item, $type, $stockQty, $costPerUnit, $lowStockThreshold, $supplier, $notes]);
            flash_set('success', 'Packaging item added successfully');
            header('Location: ' . path_with_base('/operations/product-group-packaging.php?group_id=' . $groupId));
            exit;
            
        } elseif ($action === 'update_stock') {
            $packagingId = intval($_POST['packaging_id'] ?? 0);
            $newStock = intval($_POST['new_stock'] ?? 0);
            
            if ($packagingId <= 0) throw new Exception('Invalid packaging ID');
            
            // Get product group ID for redirect
            $stmt = $pdo->prepare("SELECT product_group_id FROM product_group_packaging WHERE id = ?");
            $stmt->execute([$packagingId]);
            $groupId = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("UPDATE product_group_packaging SET stock_quantity = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStock, $packagingId]);
            flash_set('success', 'Stock updated successfully');
            header('Location: ' . path_with_base('/operations/product-group-packaging.php?group_id=' . $groupId));
            exit;
            
        } elseif ($action === 'delete_packaging') {
            $packagingId = intval($_POST['packaging_id'] ?? 0);
            if ($packagingId <= 0) throw new Exception('Invalid packaging ID');
            
            // Get product group ID before deleting
            $stmt = $pdo->prepare("SELECT product_group_id FROM product_group_packaging WHERE id = ?");
            $stmt->execute([$packagingId]);
            $groupId = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM product_group_packaging WHERE id = ?");
            $stmt->execute([$packagingId]);
            flash_set('success', 'Packaging item deleted successfully');
            header('Location: ' . path_with_base('/operations/product-group-packaging.php?group_id=' . $groupId));
            exit;
        }
    } catch (Throwable $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
        header('Location: ' . path_with_base('/operations/product-group-packaging.php?group_id=' . $selectedGroupId));
        exit;
    }
}

// Fetch product groups
$productGroups = $pdo->query("SELECT id, name FROM product_groups WHERE active = 1 ORDER BY name")->fetchAll();

// Fetch packaging for selected group
$packaging = [];
if ($selectedGroupId > 0) {
    $stmt = $pdo->prepare("
        SELECT p.*, pg.name as group_name,
               CASE WHEN p.stock_quantity <= p.low_stock_threshold THEN 1 ELSE 0 END as is_low_stock
        FROM product_group_packaging p
        JOIN product_groups pg ON pg.id = p.product_group_id
        WHERE p.product_group_id = ?
        ORDER BY p.packaging_type, p.packaging_item
    ");
    $stmt->execute([$selectedGroupId]);
    $packaging = $stmt->fetchAll();
}
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>📦 Packaging Inventory</h1>
                <p class="muted">Manage bottles, lids, labels, seals, and boxes</p>
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
        <!-- Add Packaging Form -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>Add New Packaging Item</h2>
            <form method="post">
                <input type="hidden" name="action" value="add_packaging">
                <input type="hidden" name="product_group_id" value="<?php echo $selectedGroupId; ?>">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Packaging Item *</label>
                        <input type="text" name="packaging_item" required 
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Packaging Type</label>
                        <select name="packaging_type" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="">Select type...</option>
                            <option value="bottle">Bottle</option>
                            <option value="lid">Lid/Cap</option>
                            <option value="label">Label</option>
                            <option value="seal">Seal</option>
                            <option value="box">Box</option>
                            <option value="jar">Jar</option>
                            <option value="tube">Tube</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Initial Stock Quantity</label>
                        <input type="number" name="stock_quantity" value="0" min="0"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cost per Unit</label>
                        <input type="number" name="cost_per_unit" step="0.01" value="0"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Low Stock Threshold</label>
                        <input type="number" name="low_stock_threshold" value="10" min="0"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Supplier</label>
                        <input type="text" name="supplier" placeholder="Optional"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                </div>
                
                <div style="text-align: right;">
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Add Packaging</button>
                </div>
            </form>
        </div>

        <!-- Packaging List -->
        <div class="card">
            <h2>Packaging Items (<?php echo count($packaging); ?>)</h2>
            <?php if (empty($packaging)): ?>
                <p style="color: #6b7280; text-align: center; padding: 40px 20px;">No packaging items yet.</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Item</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Type</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Stock</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Cost/Unit</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Total Value</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packaging as $pkg): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px;">
                                <strong><?php echo htmlspecialchars($pkg['packaging_item']); ?></strong>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($pkg['packaging_type'] ?? '—'); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <span style="font-weight: bold; color: <?php echo $pkg['is_low_stock'] ? '#dc2626' : '#059669'; ?>;">
                                    <?php echo number_format($pkg['stock_quantity']); ?> units
                                </span>
                                <?php if ($pkg['is_low_stock']): ?>
                                <div style="font-size: 11px; color: #dc2626;">LOW STOCK</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                $<?php echo number_format($pkg['cost_per_unit'], 4); ?>
                            </td>
                            <td style="padding: 12px; text-align: center; font-weight: 600; color: #059669;">
                                $<?php echo number_format($pkg['stock_quantity'] * $pkg['cost_per_unit'], 2); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <button onclick="updateStock(<?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['packaging_item'], ENT_QUOTES); ?>', <?php echo $pkg['stock_quantity']; ?>)" 
                                        class="btn-small secondary">Update Stock</button>
                                <form method="post" style="display: inline; margin-left: 4px;" onsubmit="return confirm('Delete this item?');">
                                    <input type="hidden" name="action" value="delete_packaging">
                                    <input type="hidden" name="packaging_id" value="<?php echo $pkg['id']; ?>">
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
            <input type="hidden" name="packaging_id" id="modal-packaging-id">
            
            <div style="margin-bottom: 16px;">
                <strong id="modal-packaging-name"></strong>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; margin-bottom: 4px;">Current Stock</label>
                    <input type="text" id="modal-current-stock" readonly style="background: #f8fafc; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; width: 100%;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px;">New Stock Level</label>
                    <input type="number" name="new_stock" id="modal-new-stock" min="0" required
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
function updateStock(packagingId, packagingName, currentStock) {
    document.getElementById('modal-packaging-id').value = packagingId;
    document.getElementById('modal-packaging-name').textContent = packagingName;
    document.getElementById('modal-current-stock').value = currentStock + ' units';
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


