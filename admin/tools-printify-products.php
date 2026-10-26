<?php
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/printify-sync.php';

start_session();
require_admin_or_redirect();

$pdo = db();
$uid = current_user_id();

// Get config
$config = site_read_config();
$shopId = $config['printify_shop_id'] ?? null;

$message = null;
$error = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $shopId) {
    $action = $_POST['action'] ?? '';
    
    try {
        $sync = new PrintifySync($shopId, $uid);
        
        if ($action === 'import_all') {
            $autoCreate = isset($_POST['auto_create']);
            $results = $sync->importAllProducts($autoCreate);
            $message = "Imported {$results['total']} products: {$results['created']} created, {$results['updated']} updated, {$results['mapped']} mapped";
            
            if (!empty($results['errors'])) {
                $error = count($results['errors']) . ' products failed to import';
            }
        } elseif ($action === 'export_all') {
            $results = $sync->exportAllProducts();
            $message = "Exported {$results['total']} products: {$results['created']} created, {$results['updated']} updated";
            
            if (!empty($results['errors'])) {
                $error = count($results['errors']) . ' products failed to export';
            }
        } elseif ($action === 'enable_sync') {
            $productIds = $_POST['product_ids'] ?? [];
            foreach ($productIds as $pid) {
                $stmt = $pdo->prepare('UPDATE products SET printify_sync_enabled = 1 WHERE id = ?');
                $stmt->execute([$pid]);
            }
            $message = 'Enabled sync for ' . count($productIds) . ' products';
        } elseif ($action === 'disable_sync') {
            $productIds = $_POST['product_ids'] ?? [];
            foreach ($productIds as $pid) {
                $stmt = $pdo->prepare('UPDATE products SET printify_sync_enabled = 0 WHERE id = ?');
                $stmt->execute([$pid]);
            }
            $message = 'Disabled sync for ' . count($productIds) . ' products';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get products with sync status
$products = $pdo->query('
    SELECT 
        p.*,
        pp.title as printify_title,
        pp.is_published as printify_published,
        pp.last_synced_at as printify_last_sync,
        ppm.sync_status as mapping_status,
        ppm.last_synced_at as mapping_last_sync
    FROM products p
    LEFT JOIN printify_product_mapping ppm ON ppm.product_id = p.id
    LEFT JOIN printify_products pp ON pp.id = ppm.printify_product_id
    WHERE p.active = 1
    ORDER BY p.printify_sync_enabled DESC, p.id DESC
    LIMIT 100
')->fetchAll();

$SEO = [
    'title' => 'Product Sync — Printify Integration',
    'description' => 'Manage product synchronization with Printify',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/admin-menu.php';
?>

<style>
.sync-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
}
.sync-table th {
    background: #f8fafc;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
}
.sync-table td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 14px;
}
.sync-table tr:hover {
    background: #f8fafc;
}
.sync-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.sync-enabled {
    background: #dcfce7;
    color: #14532d;
}
.sync-disabled {
    background: #f1f5f9;
    color: #64748b;
}
.sync-success {
    background: #dbeafe;
    color: #1e3a8a;
}
.sync-pending {
    background: #fef3c7;
    color: #92400e;
}
.sync-error {
    background: #fee2e2;
    color: #991b1b;
}
.bulk-actions {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    align-items: center;
}
</style>

<div class="container" style="margin-top: 40px; max-width: 1400px;">
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="margin: 0 0 8px 0; color: #0f172a; font-size: 28px; font-weight: 700;">
                📦 Product Sync Manager
            </h1>
            <p style="margin: 0; color: #64748b; font-size: 16px;">
                Synchronize products between Product and Printify
            </p>
        </div>
        <a href="<?php echo path_with_base('/admin/tools-printify-integration.php'); ?>" class="btn">
            ← Back to Settings
        </a>
    </div>
    
    <?php if ($message): ?>
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #14532d; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
            ✓ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
            ✗ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if (!$shopId): ?>
        <div style="background: #fef3c7; border: 1px solid #fbbf24; color: #92400e; padding: 20px; border-radius: 8px; text-align: center;">
            <p style="margin: 0 0 16px 0; font-weight: 600;">Printify shop not configured</p>
            <a href="<?php echo path_with_base('/admin/tools-printify-integration.php'); ?>" class="btn" style="background: #3b82f6; color: white;">
                Configure Printify Integration
            </a>
        </div>
    <?php else: ?>
        
        <!-- Bulk Actions -->
        <form method="post" id="bulkForm">
            <div class="bulk-actions">
                <input type="hidden" name="action" id="bulkAction">
                
                <button type="submit" class="btn" style="background: #3b82f6; color: white;" onclick="document.getElementById('bulkAction').value='import_all'; return confirm('Import all products from Printify?');">
                    ⬇️ Import from Printify
                </button>
                
                <label style="display: flex; align-items: center; gap: 6px;">
                    <input type="checkbox" name="auto_create">
                    <span style="font-size: 13px;">Auto-create missing products</span>
                </label>
                
                <button type="submit" class="btn" style="background: #059669; color: white;" onclick="document.getElementById('bulkAction').value='export_all'; return confirm('Export all enabled products to Printify?');">
                    ⬆️ Export to Printify
                </button>
                
                <div style="flex: 1;"></div>
                
                <button type="button" class="btn" onclick="bulkAction('enable_sync')" style="background: #10b981; color: white;">
                    Enable Sync (Selected)
                </button>
                <button type="button" class="btn" onclick="bulkAction('disable_sync')" style="background: #94a3b8; color: white;">
                    Disable Sync (Selected)
                </button>
            </div>
        </form>
        
        <!-- Products Table -->
        <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow: hidden;">
            <table class="sync-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                        </th>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th>Sync Status</th>
                        <th>Printify ID</th>
                        <th>Last Synced</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="product-checkbox" value="<?php echo $product['id']; ?>">
                            </td>
                            <td style="font-family: monospace; font-size: 12px;">
                                <?php echo htmlspecialchars($product['sku']); ?>
                            </td>
                            <td style="font-weight: 500;">
                                <?php echo htmlspecialchars($product['name']); ?>
                            </td>
                            <td>
                                <?php if ($product['printify_sync_enabled']): ?>
                                    <span class="sync-badge sync-enabled">Enabled</span>
                                <?php else: ?>
                                    <span class="sync-badge sync-disabled">Disabled</span>
                                <?php endif; ?>
                                
                                <?php if ($product['mapping_status']): ?>
                                    <span class="sync-badge sync-<?php echo $product['mapping_status']; ?>">
                                        <?php echo ucfirst($product['mapping_status']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family: monospace; font-size: 11px; color: #64748b;">
                                <?php if ($product['printify_product_id']): ?>
                                    <?php echo htmlspecialchars(substr($product['printify_product_id'], 0, 20)); ?>...
                                <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: #64748b;">
                                <?php if ($product['printify_last_synced'] || $product['mapping_last_sync']): ?>
                                    <?php 
                                    $lastSync = $product['mapping_last_sync'] ?? $product['printify_last_synced'];
                                    echo date('M j, g:i A', strtotime($lastSync)); 
                                    ?>
                                <?php else: ?>
                                    Never
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo path_with_base('/admin/edit-product.php?id=' . $product['id']); ?>" class="btn small" style="font-size: 11px; padding: 4px 8px;">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
    <?php endif; ?>
</div>

<script>
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.product-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
}

function bulkAction(action) {
    const checkboxes = document.querySelectorAll('.product-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one product');
        return;
    }
    
    const form = document.getElementById('bulkForm');
    document.getElementById('bulkAction').value = action;
    
    // Add product IDs to form
    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'product_ids[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    
    if (confirm(`${action.replace('_', ' ')} for ${checkboxes.length} products?`)) {
        form.submit();
    }
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

