<?php
/**
 * Sample Inventory Management
 * 
 * Detailed inventory tracking with movement history
 */

$SEO = [
    'title' => 'Sample Inventory — Admin — Product',
    'description' => 'Manage sample product inventory',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/sample-inventory-helpers.php';

start_session();
$userId = current_user_id();
$adminId = current_admin_id();

if (!$adminId && !user_has_role($userId, 'admin')) {
    flash_set('error', 'Admin access required');
    redirect(path_with_base('/index.php'));
}

$pdo = db();

// Get inventory with details
$inventory = get_all_sample_inventory($pdo);

// Get recent movements
$productFilter = intval($_GET['product_id'] ?? 0);
$movements = get_inventory_movements($pdo, $productFilter > 0 ? $productFilter : null, 100);

?>

<div class="container">
    <div class="page-header">
        <div>
            <h1 class="page-title">📊 Sample Inventory Management</h1>
            <div class="page-subtitle muted">Track and manage sample product stock</div>
        </div>
        <div class="header-actions">
            <a href="index.php" class="btn secondary">← Back to Samples</a>
        </div>
    </div>

    <!-- Inventory Overview -->
    <div class="card" style="margin-bottom:24px;">
        <h3 style="margin:0 0 20px;">Current Inventory Levels</h3>
        
        <div class="table-wrapper">
            <table class="inventory-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Available</th>
                        <th>Reserved</th>
                        <th>Shipped</th>
                        <th>Truly Available</th>
                        <th>Threshold</th>
                        <th>Last Restocked</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory as $inv): ?>
                    <tr class="<?php echo $inv['is_low_stock'] ? 'low-stock-row' : ''; ?>">
                        <td>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($inv['product_name']); ?></div>
                        </td>
                        <td>
                            <code style="font-size:12px;"><?php echo htmlspecialchars($inv['sku']); ?></code>
                        </td>
                        <td>
                            <span class="qty-badge <?php echo $inv['is_low_stock'] ? 'qty-low' : 'qty-ok'; ?>">
                                <?php echo $inv['quantity_available']; ?>
                            </span>
                        </td>
                        <td><?php echo $inv['quantity_reserved']; ?></td>
                        <td><?php echo $inv['quantity_shipped']; ?></td>
                        <td>
                            <span class="qty-badge <?php echo $inv['truly_available'] < 1 ? 'qty-empty' : ($inv['is_low_stock'] ? 'qty-low' : 'qty-ok'); ?>">
                                <?php echo $inv['truly_available']; ?>
                            </span>
                        </td>
                        <td><?php echo $inv['low_stock_threshold']; ?></td>
                        <td>
                            <?php echo $inv['last_restocked_at'] ? date('M j, Y', strtotime($inv['last_restocked_at'])) : '—'; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;">
                                <button class="btn small primary" onclick="openRestockModal(<?php echo $inv['product_id']; ?>, '<?php echo htmlspecialchars($inv['product_name']); ?>')">
                                    + Restock
                                </button>
                                <a href="?product_id=<?php echo $inv['product_id']; ?>#movements" class="btn small secondary">
                                    History
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Movement History -->
    <div class="card" id="movements">
        <h3 style="margin:0 0 16px;">Inventory Movement History</h3>
        
        <?php if ($productFilter > 0): ?>
        <div style="margin-bottom:16px;">
            <a href="inventory.php" class="btn small secondary">← Show All Products</a>
        </div>
        <?php endif; ?>
        
        <?php if (empty($movements)): ?>
            <div class="empty-state">
                <div style="font-size:48px;margin-bottom:12px;">📋</div>
                <p>No inventory movements yet</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="movements-table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Change</th>
                            <th>After</th>
                            <th>Reason</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $mov): ?>
                        <tr>
                            <td><?php echo date('M j, Y g:i A', strtotime($mov['created_at'])); ?></td>
                            <td>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($mov['product_name']); ?></div>
                                <div class="muted small"><?php echo htmlspecialchars($mov['sku']); ?></div>
                            </td>
                            <td>
                                <span class="movement-badge movement-<?php echo $mov['movement_type']; ?>">
                                    <?php echo ucfirst($mov['movement_type']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="<?php echo $mov['quantity_change'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $mov['quantity_change'] > 0 ? '+' : ''; ?><?php echo $mov['quantity_change']; ?>
                                </span>
                            </td>
                            <td><?php echo $mov['quantity_after']; ?></td>
                            <td class="muted small"><?php echo htmlspecialchars(substr($mov['reason'] ?? '—', 0, 50)); ?></td>
                            <td class="muted small"><?php echo htmlspecialchars($mov['performed_by_name'] ?? 'System'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Restock Modal -->
<div id="restockModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;border-radius:12px;padding:24px;width:500px;max-width:90vw;">
        <h3 style="margin-top:0;">Restock Sample Inventory</h3>
        <form id="restockForm">
            <input type="hidden" name="product_id" id="restock_product_id">
            <div style="display:grid;gap:16px;">
                <div>
                    <label>Product</label>
                    <input type="text" id="restock_product_name" readonly class="input" style="width:100%;background:#f9fafb;">
                </div>
                <div>
                    <label>Quantity to Add *</label>
                    <input type="number" name="quantity" id="restock_quantity" min="1" required class="input" style="width:100%;">
                </div>
                <div>
                    <label>Reason *</label>
                    <textarea name="reason" id="restock_reason" rows="3" required class="input" style="width:100%;" placeholder="e.g., Received shipment from supplier, Manufactured new batch"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn secondary" onclick="closeRestockModal()">Cancel</button>
                <button type="submit" class="btn primary">Add to Inventory</button>
            </div>
        </form>
    </div>
</div>

<style>
.inventory-table,
.movements-table {
    width: 100%;
    border-collapse: collapse;
}

.inventory-table thead tr,
.movements-table thead tr {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.inventory-table th,
.movements-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    font-size: 13px;
}

.inventory-table td,
.movements-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}

.inventory-table tbody tr:hover,
.movements-table tbody tr:hover {
    background: #f9fafb;
}

.low-stock-row {
    background: #fffbeb !important;
}

.qty-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 14px;
}

.qty-ok {
    background: #dcfce7;
    color: #059669;
}

.qty-low {
    background: #fef3c7;
    color: #92400e;
}

.qty-empty {
    background: #fee2e2;
    color: #991b1b;
}

.movement-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.movement-restock {
    background: #dcfce7;
    color: #059669;
}

.movement-reservation {
    background: #fef3c7;
    color: #92400e;
}

.movement-release {
    background: #dbeafe;
    color: #1e40af;
}

.movement-shipment {
    background: #e0e7ff;
    color: #4338ca;
}

.movement-adjustment {
    background: #f3f4f6;
    color: #374151;
}

.text-success {
    color: #059669;
    font-weight: 600;
}

.text-danger {
    color: #dc2626;
    font-weight: 600;
}

.empty-state {
    text-align: center;
    padding: 48px 24px;
    color: #9ca3af;
}
</style>

<script>
function openRestockModal(productId, productName) {
    document.getElementById('restock_product_id').value = productId;
    document.getElementById('restock_product_name').value = productName;
    document.getElementById('restockModal').style.display = 'block';
}

function closeRestockModal() {
    document.getElementById('restockModal').style.display = 'none';
    document.getElementById('restockForm').reset();
}

document.getElementById('restockForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {
        product_id: parseInt(formData.get('product_id')),
        quantity: parseInt(formData.get('quantity')),
        reason: formData.get('reason')
    };
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=restock', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        alert('Inventory restocked successfully');
        location.reload();
        
    } catch (error) {
        alert('Error: ' + error.message);
    }
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>





