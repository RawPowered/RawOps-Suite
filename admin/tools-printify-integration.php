<?php
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/printify-api.php';

start_session();
require_admin_or_redirect();

$pdo = db();
$uid = current_user_id();

// Handle form submissions
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_api_key') {
        $apiKey = trim($_POST['api_key'] ?? '');
        
        if (empty($apiKey)) {
            $error = 'API key cannot be empty';
        } else {
            // Save to site config
            $config = site_read_config();
            $config['printify_api_key'] = $apiKey;
            $config['printify_updated_at'] = date('c');
            $config['printify_updated_by'] = $uid;
            
            if (site_write_config($config)) {
                $message = 'API key saved successfully';
                
                // Log to sync log
                try {
                    $stmt = $pdo->prepare('INSERT INTO printify_sync_log (action_type, resource_type, status, details, user_id, completed_at) VALUES (?, ?, ?, ?, ?, NOW())');
                    $stmt->execute(['config', 'api_key', 'success', json_encode(['message' => 'API key updated']), $uid]);
                } catch (Exception $e) {}
            } else {
                $error = 'Failed to save API key';
            }
        }
    } elseif ($action === 'save_shop_id') {
        $shopId = trim($_POST['shop_id'] ?? '');
        
        $config = site_read_config();
        $config['printify_shop_id'] = $shopId;
        $config['printify_updated_at'] = date('c');
        
        if (site_write_config($config)) {
            $message = 'Shop ID saved successfully';
        } else {
            $error = 'Failed to save shop ID';
        }
    } elseif ($action === 'save_settings') {
        $config = site_read_config();
        $config['printify_auto_sync'] = isset($_POST['auto_sync']) ? 1 : 0;
        $config['printify_auto_submit_orders'] = isset($_POST['auto_submit_orders']) ? 1 : 0;
        $config['printify_auto_publish'] = isset($_POST['auto_publish']) ? 1 : 0;
        $config['printify_sync_interval'] = intval($_POST['sync_interval'] ?? 60);
        $config['printify_updated_at'] = date('c');
        
        if (site_write_config($config)) {
            $message = 'Settings saved successfully';
        } else {
            $error = 'Failed to save settings';
        }
    }
}

// Load current config
$config = site_read_config();
$apiKey = $config['printify_api_key'] ?? '';
$shopId = $config['printify_shop_id'] ?? '';
$autoSync = $config['printify_auto_sync'] ?? 0;
$autoSubmitOrders = $config['printify_auto_submit_orders'] ?? 1; // Default ON
$autoPublish = $config['printify_auto_publish'] ?? 0;
$syncInterval = $config['printify_sync_interval'] ?? 60;

// Test connection if API key exists
$connectionStatus = null;
$shops = [];
if (!empty($apiKey)) {
    try {
        $api = new PrintifyAPI($apiKey);
        $connectionStatus = $api->testConnection();
        if ($connectionStatus['success']) {
            $shops = $connectionStatus['shops'] ?? [];
        }
    } catch (Exception $e) {
        $connectionStatus = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Get recent sync logs
$recentLogs = [];
try {
    $stmt = $pdo->query('SELECT * FROM printify_sync_log ORDER BY created_at DESC LIMIT 10');
    $recentLogs = $stmt->fetchAll();
} catch (Exception $e) {}

$SEO = [
    'title' => 'Printify Integration — Admin',
    'description' => 'Configure Printify API integration',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/admin-menu.php';
?>

<style>
.config-section {
    background: white;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.config-section h2 {
    margin: 0 0 16px 0;
    color: #0f172a;
    font-size: 20px;
    font-weight: 600;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    color: #334155;
}
.form-group input[type="text"],
.form-group input[type="number"],
.form-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 14px;
}
.form-group small {
    display: block;
    margin-top: 4px;
    color: #64748b;
    font-size: 13px;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-success {
    background: #dcfce7;
    color: #14532d;
}
.status-error {
    background: #fee2e2;
    color: #991b1b;
}
.shop-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
}
.shop-card h3 {
    margin: 0 0 8px 0;
    font-size: 16px;
    color: #0f172a;
}
.shop-card .shop-id {
    font-family: monospace;
    background: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    color: #475569;
}
.log-table {
    width: 100%;
    border-collapse: collapse;
}
.log-table th {
    background: #f8fafc;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
}
.log-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}
.checkbox-group input[type="checkbox"] {
    width: auto;
}
</style>

<div class="container" style="margin-top: 40px; max-width: 1000px;">
    <div style="margin-bottom: 24px;">
        <h1 style="margin: 0 0 8px 0; color: #0f172a; font-size: 28px; font-weight: 700;">
            🖨️ Printify Integration
        </h1>
        <p style="margin: 0; color: #64748b; font-size: 16px;">
            Configure print-on-demand integration for automatic order fulfillment
        </p>
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
    
    <!-- API Key Configuration -->
    <div class="config-section">
        <h2>🔑 API Configuration</h2>
        
        <form method="post">
            <input type="hidden" name="action" value="save_api_key">
            
            <div class="form-group">
                <label for="api_key">Printify API Key</label>
                <input 
                    type="text" 
                    id="api_key" 
                    name="api_key" 
                    value="<?php echo htmlspecialchars($apiKey); ?>" 
                    placeholder="eyJ0eXAiOiJKV1QiLCJhbGc..."
                    required
                >
                <small>
                    Get your API key from 
                    <a href="https://printify.com/app/account/api" target="_blank" style="color: #3b82f6;">
                        Printify Account → Connections
                    </a>
                </small>
            </div>
            
            <button type="submit" class="btn" style="background: #3b82f6; color: white;">
                Save API Key
            </button>
        </form>
        
        <?php if ($connectionStatus): ?>
            <div style="margin-top: 20px; padding: 16px; background: <?php echo $connectionStatus['success'] ? '#f0fdf4' : '#fef2f2'; ?>; border-radius: 8px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span class="status-badge <?php echo $connectionStatus['success'] ? 'status-success' : 'status-error'; ?>">
                        <?php echo $connectionStatus['success'] ? '✓ Connected' : '✗ Connection Failed'; ?>
                    </span>
                    <?php if (!$connectionStatus['success']): ?>
                        <span style="color: #991b1b; font-size: 14px;">
                            <?php echo htmlspecialchars($connectionStatus['error'] ?? 'Unknown error'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Shop Selection -->
    <?php if (!empty($shops)): ?>
    <div class="config-section">
        <h2>🏪 Shop Selection</h2>
        
        <form method="post">
            <input type="hidden" name="action" value="save_shop_id">
            
            <div class="form-group">
                <label for="shop_id">Default Shop</label>
                <select id="shop_id" name="shop_id" required>
                    <option value="">Select a shop...</option>
                    <?php foreach ($shops as $shop): ?>
                        <option value="<?php echo htmlspecialchars($shop['id']); ?>" <?php echo $shopId == $shop['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($shop['title'] ?? 'Shop ' . $shop['id']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>This shop will be used for all product sync and order fulfillment</small>
            </div>
            
            <button type="submit" class="btn" style="background: #059669; color: white;">
                Save Shop Selection
            </button>
        </form>
        
        <!-- Shop Details -->
        <div style="margin-top: 20px;">
            <h3 style="font-size: 16px; margin-bottom: 12px; color: #0f172a;">Available Shops:</h3>
            <?php foreach ($shops as $shop): ?>
                <div class="shop-card">
                    <h3><?php echo htmlspecialchars($shop['title'] ?? 'Untitled Shop'); ?></h3>
                    <div style="display: flex; gap: 16px; margin-top: 8px; font-size: 13px; color: #64748b;">
                        <div>
                            <strong>ID:</strong> 
                            <span class="shop-id"><?php echo htmlspecialchars($shop['id']); ?></span>
                        </div>
                        <?php if (isset($shop['sales_channel'])): ?>
                            <div>
                                <strong>Channel:</strong> <?php echo htmlspecialchars($shop['sales_channel']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Sync Settings -->
    <?php if (!empty($apiKey) && !empty($shopId)): ?>
    <div class="config-section">
        <h2>⚙️ Sync Settings</h2>
        
        <form method="post">
            <input type="hidden" name="action" value="save_settings">
            
            <div class="checkbox-group">
                <input 
                    type="checkbox" 
                    id="auto_sync" 
                    name="auto_sync" 
                    <?php echo $autoSync ? 'checked' : ''; ?>
                >
                <label for="auto_sync" style="margin: 0;">
                    <strong>Enable Automatic Product Sync</strong>
                    <br>
                    <small>Automatically sync products with Printify on schedule</small>
                </label>
            </div>
            
            <div class="checkbox-group">
                <input 
                    type="checkbox" 
                    id="auto_submit_orders" 
                    name="auto_submit_orders" 
                    <?php echo $autoSubmitOrders ? 'checked' : ''; ?>
                >
                <label for="auto_submit_orders" style="margin: 0;">
                    <strong>Auto-Submit Orders to Printify</strong>
                    <br>
                    <small>Automatically send orders to Printify for fulfillment when placed</small>
                </label>
            </div>
            
            <div class="checkbox-group">
                <input 
                    type="checkbox" 
                    id="auto_publish" 
                    name="auto_publish" 
                    <?php echo $autoPublish ? 'checked' : ''; ?>
                >
                <label for="auto_publish" style="margin: 0;">
                    <strong>Auto-Publish Products</strong>
                    <br>
                    <small>Automatically publish new products to connected stores</small>
                </label>
            </div>
            
            <div class="form-group" style="margin-top: 20px;">
                <label for="sync_interval">Sync Interval (minutes)</label>
                <input 
                    type="number" 
                    id="sync_interval" 
                    name="sync_interval" 
                    value="<?php echo $syncInterval; ?>" 
                    min="15" 
                    max="1440"
                >
                <small>How often to check for product updates (15-1440 minutes)</small>
            </div>
            
            <button type="submit" class="btn" style="background: #3b82f6; color: white;">
                Save Settings
            </button>
        </form>
    </div>
    <?php endif; ?>
    
    <!-- Recent Activity -->
    <?php if (!empty($recentLogs)): ?>
    <div class="config-section">
        <h2>📋 Recent Activity</h2>
        
        <table class="log-table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Resource</th>
                    <th>Status</th>
                    <th>Records</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log['action_type']); ?></td>
                        <td><?php echo htmlspecialchars($log['resource_type']); ?></td>
                        <td>
                            <span class="status-badge <?php echo $log['status'] === 'success' ? 'status-success' : 'status-error'; ?>">
                                <?php echo htmlspecialchars($log['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($log['records_processed'] > 0): ?>
                                <?php echo $log['records_processed']; ?> 
                                <?php if ($log['records_failed'] > 0): ?>
                                    <span style="color: #dc2626;">(<?php echo $log['records_failed']; ?> failed)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="color: #64748b;">
                            <?php echo date('M j, g:i A', strtotime($log['created_at'])); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="margin-top: 16px;">
            <a href="<?php echo path_with_base('/admin/tools-printify-products.php'); ?>" class="btn" style="background: #059669; color: white;">
                Manage Product Sync →
            </a>
            <a href="<?php echo path_with_base('/admin/tools-printify-webhooks.php'); ?>" class="btn" style="background: #7c3aed; color: white;">
                Configure Webhooks →
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Quick Links -->
    <div class="config-section">
        <h2>🔗 Quick Links</h2>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
            <a href="https://printify.com/app/products" target="_blank" style="padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a;">
                <div style="font-weight: 600; margin-bottom: 4px;">📦 Printify Products</div>
                <div style="font-size: 12px; color: #64748b;">Manage products in Printify</div>
            </a>
            
            <a href="https://printify.com/app/orders" target="_blank" style="padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a;">
                <div style="font-weight: 600; margin-bottom: 4px;">🚚 Printify Orders</div>
                <div style="font-size: 12px; color: #64748b;">View order status</div>
            </a>
            
            <a href="https://developers.printify.com/" target="_blank" style="padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a;">
                <div style="font-weight: 600; margin-bottom: 4px;">📚 API Documentation</div>
                <div style="font-size: 12px; color: #64748b;">Printify developer docs</div>
            </a>
            
            <a href="https://help.printify.com/" target="_blank" style="padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a;">
                <div style="font-weight: 600; margin-bottom: 4px;">❓ Help Center</div>
                <div style="font-size: 12px; color: #64748b;">Get support</div>
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

