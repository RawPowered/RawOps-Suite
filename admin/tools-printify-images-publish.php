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
        
        if ($action === 'upload_images') {
            $productId = $_POST['product_id'] ?? null;
            
            if ($productId) {
                // Upload single product images
                $results = $sync->uploadProductImages($productId);
                $message = "Uploaded {$results['uploaded']} image(s)";
                
                if ($results['failed'] > 0) {
                    $error = "Failed: " . implode(', ', $results['errors']);
                }
            } else {
                // Upload all product images
                $results = $sync->syncAllProductImages();
                $message = "Uploaded {$results['uploaded']} image(s) for {$results['total']} products";
                
                if (!empty($results['errors'])) {
                    $error = count($results['errors']) . ' products failed';
                }
            }
            
        } elseif ($action === 'publish_product') {
            $productId = $_POST['product_id'] ?? null;
            $storeIds = $_POST['store_ids'] ?? [];
            
            if (!$productId) {
                throw new Exception('Product ID required');
            }
            
            $results = $sync->publishProductToStores($productId, $storeIds);
            $message = "Published to {$results['published']} store(s)";
            
            if ($results['failed'] > 0) {
                $error = "Failed: " . implode(', ', array_column($results['errors'], 'error'));
            }
            
        } elseif ($action === 'auto_publish_all') {
            $results = $sync->autoPublishAllProducts();
            $message = "Published {$results['published']} products to stores";
            
            if (!empty($results['errors'])) {
                $error = count($results['errors']) . ' products failed to publish';
            }
            
        } elseif ($action === 'add_store') {
            $storeName = trim($_POST['store_name'] ?? '');
            $storeId = trim($_POST['store_id'] ?? '');
            $autoPublish = isset($_POST['auto_publish']) ? 1 : 0;
            $publishOnUpdate = isset($_POST['publish_on_update']) ? 1 : 0;
            
            if (empty($storeName) || empty($storeId)) {
                throw new Exception('Store name and ID required');
            }
            
            $stmt = $pdo->prepare('
                INSERT INTO printify_publish_stores (
                    store_name, store_id, is_enabled, auto_publish, publish_on_update
                ) VALUES (?, ?, 1, ?, ?)
            ');
            $stmt->execute([$storeName, $storeId, $autoPublish, $publishOnUpdate]);
            
            $message = 'Store added successfully';
            
        } elseif ($action === 'toggle_store') {
            $storeId = $_POST['store_id'] ?? null;
            
            if (!$storeId) {
                throw new Exception('Store ID required');
            }
            
            $stmt = $pdo->prepare('UPDATE printify_publish_stores SET is_enabled = NOT is_enabled WHERE id = ?');
            $stmt->execute([$storeId]);
            
            $message = 'Store status updated';
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get image upload stats
$imageStats = $pdo->query('
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN upload_status = "success" THEN 1 ELSE 0 END) as uploaded,
        SUM(CASE WHEN upload_status = "error" THEN 1 ELSE 0 END) as failed
    FROM printify_image_uploads
')->fetch();

// Get products needing image upload
$productsNeedingImages = $pdo->query('
    SELECT COUNT(*) as count FROM products 
    WHERE printify_sync_enabled = 1 
    AND printify_images_synced = 0
    AND image_url IS NOT NULL
')->fetch()['count'] ?? 0;

// Get configured stores
$stores = $pdo->query('SELECT * FROM printify_publish_stores ORDER BY created_at DESC')->fetchAll();

// Get publish stats
$publishStats = $pdo->query('
    SELECT 
        COUNT(DISTINCT product_id) as products_published,
        COUNT(*) as total_publishes,
        SUM(CASE WHEN publish_status = "succeeded" THEN 1 ELSE 0 END) as successful,
        SUM(CASE WHEN publish_status = "failed" THEN 1 ELSE 0 END) as failed
    FROM printify_publish_log
')->fetch();

// Get products needing publish
$productsNeedingPublish = $pdo->query('
    SELECT COUNT(*) as count FROM products 
    WHERE printify_sync_enabled = 1 
    AND printify_product_id IS NOT NULL
    AND (printify_publish_status IS NULL OR printify_publish_status != "published")
')->fetch()['count'] ?? 0;

// Get recent image uploads
$recentImageUploads = $pdo->query('
    SELECT piu.*, p.name as product_name, p.sku
    FROM printify_image_uploads piu
    LEFT JOIN products p ON p.id = piu.product_id
    ORDER BY piu.created_at DESC
    LIMIT 20
')->fetchAll();

// Get recent publish log
$recentPublishes = $pdo->query('
    SELECT ppl.*, p.name as product_name, p.sku
    FROM printify_publish_log ppl
    LEFT JOIN products p ON p.id = ppl.product_id
    ORDER BY ppl.created_at DESC
    LIMIT 20
')->fetchAll();

$SEO = [
    'title' => 'Images & Publishing — Printify Integration',
    'description' => 'Manage product images and store publishing',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/admin-menu.php';
?>

<style>
.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    text-align: center;
}
.stat-number {
    font-size: 32px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
}
.stat-label {
    font-size: 13px;
    color: #64748b;
    text-transform: uppercase;
}
.store-card {
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.store-card.active {
    border-color: #10b981;
    background: #f0fdf4;
}
.log-table {
    width: 100%;
    border-collapse: collapse;
}
.log-table th {
    background: #f8fafc;
    padding: 10px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 12px;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
}
.log-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}
</style>

<div class="container" style="margin-top: 40px; max-width: 1400px;">
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="margin: 0 0 8px 0; color: #0f172a; font-size: 28px; font-weight: 700;">
                🖼️ Images & Publishing
            </h1>
            <p style="margin: 0; color: #64748b; font-size: 16px;">
                Manage product images and store publishing
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
    
    <!-- Stats Overview -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px;">
        <div class="stat-card">
            <div class="stat-number" style="color: #3b82f6;"><?php echo $imageStats['uploaded'] ?? 0; ?></div>
            <div class="stat-label">Images Uploaded</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: #f59e0b;"><?php echo $productsNeedingImages; ?></div>
            <div class="stat-label">Pending Upload</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: #10b981;"><?php echo $publishStats['successful'] ?? 0; ?></div>
            <div class="stat-label">Products Published</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: #f59e0b;"><?php echo $productsNeedingPublish; ?></div>
            <div class="stat-label">Pending Publish</div>
        </div>
    </div>
    
    <!-- Image Upload Section -->
    <div style="background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <h2 style="margin: 0 0 16px 0; font-size: 20px; color: #0f172a;">📤 Upload Images to Printify</h2>
        
        <form method="post">
            <input type="hidden" name="action" value="upload_images">
            
            <div style="display: flex; gap: 12px; align-items: center;">
                <button type="submit" class="btn" style="background: #3b82f6; color: white;">
                    Upload All Product Images (<?php echo $productsNeedingImages; ?> pending)
                </button>
                
                <div style="flex: 1;"></div>
                
                <span style="font-size: 13px; color: #64748b;">
                    Images uploaded: <?php echo $imageStats['uploaded'] ?? 0; ?> | 
                    Failed: <?php echo $imageStats['failed'] ?? 0; ?>
                </span>
            </div>
        </form>
        
        <?php if (!empty($recentImageUploads)): ?>
        <div style="margin-top: 20px;">
            <h3 style="font-size: 16px; margin-bottom: 12px; color: #0f172a;">Recent Uploads</h3>
            <table class="log-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Filename</th>
                        <th>Status</th>
                        <th>Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($recentImageUploads, 0, 10) as $upload): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($upload['product_name'] ?? 'N/A'); ?></strong>
                                <br>
                                <span style="font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($upload['sku'] ?? ''); ?></span>
                            </td>
                            <td style="font-family: monospace; font-size: 12px;">
                                <?php echo htmlspecialchars($upload['filename']); ?>
                            </td>
                            <td>
                                <?php if ($upload['upload_status'] === 'success'): ?>
                                    <span style="color: #10b981;">✓ Success</span>
                                <?php else: ?>
                                    <span style="color: #dc2626;">✗ Failed</span>
                                    <?php if ($upload['upload_error']): ?>
                                        <br><span style="font-size: 11px;"><?php echo htmlspecialchars(substr($upload['upload_error'], 0, 50)); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: #64748b;">
                                <?php echo $upload['uploaded_at'] ? date('M j, g:i A', strtotime($upload['uploaded_at'])) : 'Pending'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Store Management -->
    <div style="background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <h2 style="margin: 0 0 16px 0; font-size: 20px; color: #0f172a;">🏪 Connected Stores</h2>
        
        <!-- Add Store Form -->
        <details style="background: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
            <summary style="cursor: pointer; font-weight: 600; color: #0f172a;">➕ Add New Store</summary>
            <form method="post" style="margin-top: 12px;">
                <input type="hidden" name="action" value="add_store">
                
                <div style="display: grid; gap: 12px; grid-template-columns: 1fr 1fr;">
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 13px;">Store Name</label>
                        <input type="text" name="store_name" placeholder="My Shopify Store" required style="width: 100%; padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 13px;">Store ID (from Printify)</label>
                        <input type="text" name="store_id" placeholder="12345" required style="width: 100%; padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    </div>
                </div>
                
                <div style="display: flex; gap: 16px; margin-top: 12px;">
                    <label style="display: flex; align-items: center; gap: 6px;">
                        <input type="checkbox" name="auto_publish" checked>
                        <span style="font-size: 13px;">Auto-publish new products</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px;">
                        <input type="checkbox" name="publish_on_update">
                        <span style="font-size: 13px;">Publish on product updates</span>
                    </label>
                </div>
                
                <button type="submit" class="btn" style="background: #10b981; color: white; margin-top: 12px;">
                    Add Store
                </button>
            </form>
        </details>
        
        <!-- Store List -->
        <?php if (!empty($stores)): ?>
            <?php foreach ($stores as $store): ?>
                <div class="store-card <?php echo $store['is_enabled'] ? 'active' : ''; ?>">
                    <div>
                        <div style="font-weight: 600; font-size: 16px; margin-bottom: 4px;">
                            <?php echo htmlspecialchars($store['store_name']); ?>
                        </div>
                        <div style="font-size: 12px; color: #64748b;">
                            ID: <?php echo htmlspecialchars($store['store_id']); ?> |
                            Auto-publish: <?php echo $store['auto_publish'] ? 'Yes' : 'No'; ?> |
                            Publish on update: <?php echo $store['publish_on_update'] ? 'Yes' : 'No'; ?>
                        </div>
                    </div>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="toggle_store">
                        <input type="hidden" name="store_id" value="<?php echo $store['id']; ?>">
                        <button type="submit" class="btn" style="background: <?php echo $store['is_enabled'] ? '#dc2626' : '#10b981'; ?>; color: white; padding: 6px 12px; font-size: 13px;">
                            <?php echo $store['is_enabled'] ? 'Disable' : 'Enable'; ?>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #94a3b8;">
                No stores configured yet. Add your first store above.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Auto-Publish Section -->
    <div style="background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <h2 style="margin: 0 0 16px 0; font-size: 20px; color: #0f172a;">🚀 Publish to Stores</h2>
        
        <form method="post">
            <input type="hidden" name="action" value="auto_publish_all">
            
            <div style="display: flex; gap: 12px; align-items: center;">
                <button type="submit" class="btn" style="background: #10b981; color: white;" onclick="return confirm('Publish all pending products to enabled stores?');">
                    Auto-Publish All Products (<?php echo $productsNeedingPublish; ?> pending)
                </button>
                
                <div style="flex: 1;"></div>
                
                <span style="font-size: 13px; color: #64748b;">
                    Published: <?php echo $publishStats['successful'] ?? 0; ?> | 
                    Failed: <?php echo $publishStats['failed'] ?? 0; ?>
                </span>
            </div>
        </form>
        
        <?php if (!empty($recentPublishes)): ?>
        <div style="margin-top: 20px;">
            <h3 style="font-size: 16px; margin-bottom: 12px; color: #0f172a;">Recent Publishes</h3>
            <table class="log-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Store</th>
                        <th>Status</th>
                        <th>Published</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($recentPublishes, 0, 10) as $publish): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($publish['product_name'] ?? 'N/A'); ?></strong>
                                <br>
                                <span style="font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($publish['sku'] ?? ''); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($publish['store_name'] ?? 'Unknown'); ?></td>
                            <td>
                                <?php if ($publish['publish_status'] === 'succeeded'): ?>
                                    <span style="color: #10b981;">✓ Published</span>
                                <?php elseif ($publish['publish_status'] === 'failed'): ?>
                                    <span style="color: #dc2626;">✗ Failed</span>
                                    <?php if ($publish['publish_error']): ?>
                                        <br><span style="font-size: 11px;"><?php echo htmlspecialchars(substr($publish['publish_error'], 0, 50)); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #f59e0b;">⏳ <?php echo ucfirst($publish['publish_status']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: #64748b;">
                                <?php echo $publish['published_at'] ? date('M j, g:i A', strtotime($publish['published_at'])) : 'Pending'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

