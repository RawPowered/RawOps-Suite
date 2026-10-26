<?php
$SEO = [
    'title' => 'Inventory Management — Operations',
    'description' => 'Manage product inventory, stock levels, and attribute content.',
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
$message = '';
$error = '';

// Handle inventory actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_stock') {
            $productId = intval($_POST['product_id'] ?? 0);
            $newStock = intval($_POST['stock_quantity'] ?? 0);
            $movementType = $_POST['movement_type'] ?? 'adjustment';
            $notes = trim($_POST['notes'] ?? '');
            
            if ($productId <= 0) {
                throw new Exception('Invalid product ID');
            }
            
            // Get current stock
            $stmt = $pdo->prepare('SELECT stock_quantity, name FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new Exception('Product not found');
            }
            
            $oldStock = intval($product['stock_quantity']);
            $stockDiff = $newStock - $oldStock;
            
            // Update product stock
            $stmt = $pdo->prepare('UPDATE products SET stock_quantity = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$newStock, $productId]);
            
            // Log stock movement
            $stmt = $pdo->prepare('
                INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$productId, $stockDiff > 0 ? 'in' : 'out', abs($stockDiff), 'manual', $notes, $userId]);
            
            // Log operations activity
            $stmt = $pdo->prepare('
                INSERT INTO operations_log (user_id, action, target_type, target_id, details, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $details = json_encode([
                'product_name' => $product['name'],
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'difference' => $stockDiff,
                'notes' => $notes
            ]);
            $stmt->execute([$userId, 'stock_update', 'product', $productId, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            $message = 'Stock updated successfully for ' . htmlspecialchars($product['name']);
            
        } elseif ($action === 'bulk_stock_update') {
            $updates = $_POST['bulk_updates'] ?? [];
            $successCount = 0;
            
            $pdo->beginTransaction();
            
            foreach ($updates as $productId => $data) {
                $productId = intval($productId);
                $newStock = intval($data['stock'] ?? 0);
                $notes = trim($data['notes'] ?? '');
                
                if ($productId <= 0 || $newStock < 0) continue;
                
                // Get current stock
                $stmt = $pdo->prepare('SELECT stock_quantity, name FROM products WHERE id = ?');
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                if (!$product) continue;
                
                $oldStock = intval($product['stock_quantity']);
                $stockDiff = $newStock - $oldStock;
                
                if ($stockDiff == 0) continue; // No change needed
                
                // Update product stock
                $stmt = $pdo->prepare('UPDATE products SET stock_quantity = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$newStock, $productId]);
                
                // Log stock movement
                $stmt = $pdo->prepare('
                    INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$productId, $stockDiff > 0 ? 'in' : 'out', abs($stockDiff), 'manual', $notes, $userId]);
                
                $successCount++;
            }
            
            $pdo->commit();
            $message = "Bulk update completed. {$successCount} products updated.";
            
        } elseif ($action === 'update_product_details') {
            $productId = intval($_POST['product_id'] ?? 0);
            $mitContent = trim($_POST['attribute_content'] ?? '');
            $weight = floatval($_POST['weight_grams'] ?? 0);
            $dimensions = trim($_POST['dimensions'] ?? '');
            $lowStockThreshold = intval($_POST['low_stock_threshold'] ?? 10);
            
            if ($productId <= 0) {
                throw new Exception('Invalid product ID');
            }
            
            $stmt = $pdo->prepare('
                UPDATE products 
                SET attribute_content = ?, weight_grams = ?, dimensions = ?, low_stock_threshold = ?, updated_at = NOW() 
                WHERE id = ?
            ');
            $stmt->execute([$mitContent, $weight > 0 ? $weight : null, $dimensions, $lowStockThreshold, $productId]);
            
            // Log operations activity
            $stmt = $pdo->prepare('
                INSERT INTO operations_log (user_id, action, target_type, target_id, details, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $details = json_encode([
                'attribute_content' => $mitContent,
                'weight_grams' => $weight,
                'dimensions' => $dimensions,
                'low_stock_threshold' => $lowStockThreshold
            ]);
            $stmt->execute([$userId, 'product_details_update', 'product', $productId, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            $message = 'Product details updated successfully';
        }
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Get products with current stock levels
$products = $pdo->query('
    SELECT p.*, 
           CASE WHEN p.stock_quantity <= p.low_stock_threshold THEN 1 ELSE 0 END as is_low_stock
    FROM products p 
    WHERE p.active = 1 
    ORDER BY p.name ASC
')->fetchAll();

$action = $_GET['action'] ?? 'list';
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>📦 Inventory Management</h1>
                <p class="muted">Manage stock levels, product details, and attribute content</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="/operations/" class="btn secondary">← Back to Dashboard</a>
                <button onclick="toggleBulkMode()" class="btn" id="bulk-toggle">Enable Bulk Mode</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Bulk Update Form (Hidden by default) -->
        <div id="bulk-form" class="card" style="display: none; margin-bottom: 24px;">
            <h2>🔄 Bulk Stock Update</h2>
            <form method="post">
                <input type="hidden" name="action" value="bulk_stock_update">
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Product</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Current Stock</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">New Stock</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td style="padding: 12px;">
                                        <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                        <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($product['sku']); ?></div>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <span style="color: <?php echo $product['is_low_stock'] ? '#dc2626' : '#059669'; ?>;">
                                            <?php echo intval($product['stock_quantity']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <input type="number" name="bulk_updates[<?php echo $product['id']; ?>][stock]" 
                                               value="<?php echo intval($product['stock_quantity']); ?>" 
                                               min="0" style="width: 80px; text-align: center;">
                                    </td>
                                    <td style="padding: 12px;">
                                        <input type="text" name="bulk_updates[<?php echo $product['id']; ?>][notes]" 
                                               placeholder="Optional notes" style="width: 100%;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 16px; text-align: right;">
                    <button type="button" onclick="toggleBulkMode()" class="btn secondary">Cancel</button>
                    <button type="submit" class="btn">Update All Selected</button>
                </div>
            </form>
        </div>

        <!-- Individual Product Management -->
        <div id="individual-form">
            <div class="card">
                <h2>📋 Product Inventory</h2>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Product</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Stock</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Threshold</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Status</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;" data-product-id="<?php echo $product['id']; ?>">
                                    <td style="padding: 12px;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                            <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($product['sku']); ?></div>
                                            <?php if ($product['description']): ?>
                                                <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">
                                                    <?php echo htmlspecialchars(substr($product['description'], 0, 100)); ?><?php echo strlen($product['description']) > 100 ? '...' : ''; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <span style="font-weight: bold; color: <?php echo $product['is_low_stock'] ? '#dc2626' : '#059669'; ?>;">
                                            <?php echo intval($product['stock_quantity']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <?php echo intval($product['low_stock_threshold']); ?>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <?php if ($product['is_low_stock']): ?>
                                            <span style="background: #fef2f2; color: #dc2626; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                🚨 LOW STOCK
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #f0fdf4; color: #059669; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                ✅ OK
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <button onclick="openStockModal(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>', <?php echo $product['stock_quantity']; ?>)" 
                                                class="btn secondary" style="font-size: 12px; padding: 6px 12px;">
                                            📝 Update Stock
                                        </button>
                                        <button onclick="openDetailsModal(<?php echo $product['id']; ?>)" 
                                                class="btn secondary" style="font-size: 12px; padding: 6px 12px; margin-left: 4px;">
                                            ⚙️ Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Stock Update Modal -->
<div id="stock-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; padding: 24px; max-width: 500px; width: 90%;">
        <h3 style="margin: 0 0 16px 0;">Update Stock Level</h3>
        <form method="post" id="stock-form">
            <input type="hidden" name="action" value="update_stock">
            <input type="hidden" name="product_id" id="modal-product-id">
            
            <div style="margin-bottom: 16px;">
                <strong id="modal-product-name"></strong>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <label>
                    <span>Current Stock</span>
                    <input type="number" id="modal-current-stock" readonly style="background: #f8fafc;">
                </label>
                <label>
                    <span>New Stock Level</span>
                    <input type="number" name="stock_quantity" id="modal-new-stock" min="0" required>
                </label>
            </div>
            
            <label style="margin-bottom: 16px; display: block;">
                <span>Movement Type</span>
                <select name="movement_type">
                    <option value="adjustment">Stock Adjustment</option>
                    <option value="in">Stock In (Received)</option>
                    <option value="out">Stock Out (Used)</option>
                </select>
            </label>
            
            <label style="margin-bottom: 16px; display: block;">
                <span>Notes (Optional)</span>
                <textarea name="notes" placeholder="Reason for stock change..." rows="3" style="width: 100%;"></textarea>
            </label>
            
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeStockModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn">Update Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- Product Details Modal -->
<div id="details-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; padding: 24px; max-width: 600px; width: 90%; max-height: 90%; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">Product Details & attribute Content</h3>
        <form method="post" id="details-form">
            <input type="hidden" name="action" value="update_product_details">
            <input type="hidden" name="product_id" id="details-product-id">
            
            <div id="details-loading">Loading product details...</div>
            <div id="details-content" style="display: none;">
                <label style="margin-bottom: 16px; display: block;">
                    <span>attribute Content / Lab Results</span>
                    <textarea name="attribute_content" id="details-attribute-content" placeholder="Enter attribute content, lab results, or quality information..." rows="6" style="width: 100%;"></textarea>
                </label>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <label>
                        <span>Weight (grams)</span>
                        <input type="number" name="weight_grams" id="details-weight" step="0.01" min="0">
                    </label>
                    <label>
                        <span>Low Stock Threshold</span>
                        <input type="number" name="low_stock_threshold" id="details-threshold" min="0" required>
                    </label>
                </div>
                
                <label style="margin-bottom: 16px; display: block;">
                    <span>Dimensions (L x W x H)</span>
                    <input type="text" name="dimensions" id="details-dimensions" placeholder="e.g., 10cm x 5cm x 3cm">
                </label>
                
                <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                    <button type="button" onclick="closeDetailsModal()" class="btn secondary">Cancel</button>
                    <button type="submit" class="btn">Update Details</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function toggleBulkMode() {
    const bulkForm = document.getElementById('bulk-form');
    const individualForm = document.getElementById('individual-form');
    const bulkToggle = document.getElementById('bulk-toggle');
    
    if (bulkForm.style.display === 'none') {
        bulkForm.style.display = 'block';
        individualForm.style.display = 'none';
        bulkToggle.textContent = 'Disable Bulk Mode';
        bulkToggle.classList.remove('btn');
        bulkToggle.classList.add('btn', 'secondary');
    } else {
        bulkForm.style.display = 'none';
        individualForm.style.display = 'block';
        bulkToggle.textContent = 'Enable Bulk Mode';
        bulkToggle.classList.remove('secondary');
        bulkToggle.classList.add('btn');
    }
}

function openStockModal(productId, productName, currentStock) {
    document.getElementById('modal-product-id').value = productId;
    document.getElementById('modal-product-name').textContent = productName;
    document.getElementById('modal-current-stock').value = currentStock;
    document.getElementById('modal-new-stock').value = currentStock;
    document.getElementById('stock-modal').style.display = 'block';
}

function closeStockModal() {
    document.getElementById('stock-modal').style.display = 'none';
    document.getElementById('stock-form').reset();
}

function openDetailsModal(productId) {
    document.getElementById('details-product-id').value = productId;
    document.getElementById('details-modal').style.display = 'block';
    document.getElementById('details-loading').style.display = 'block';
    document.getElementById('details-content').style.display = 'none';
    
    // Fetch product details via AJAX
    fetch('/operations/ajax/get-product-details.php?id=' + productId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('details-attribute-content').value = data.product.attribute_content || '';
                document.getElementById('details-weight').value = data.product.weight_grams || '';
                document.getElementById('details-threshold').value = data.product.low_stock_threshold || 10;
                document.getElementById('details-dimensions').value = data.product.dimensions || '';
                
                document.getElementById('details-loading').style.display = 'none';
                document.getElementById('details-content').style.display = 'block';
            } else {
                alert('Failed to load product details');
                closeDetailsModal();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to load product details');
            closeDetailsModal();
        });
}

function closeDetailsModal() {
    document.getElementById('details-modal').style.display = 'none';
    document.getElementById('details-form').reset();
}

// Close modals when clicking outside
document.getElementById('stock-modal').addEventListener('click', function(e) {
    if (e.target === this) closeStockModal();
});

document.getElementById('details-modal').addEventListener('click', function(e) {
    if (e.target === this) closeDetailsModal();
});

// ESC key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeStockModal();
        closeDetailsModal();
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
