<?php
$SEO = [
    'title' => 'Shipping Configuration — Admin',
    'description' => 'Configure shipping APIs and label printing settings.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';

// Handle configuration updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_stamps_config') {
            $username = trim($_POST['stamps_username'] ?? '');
            $password = trim($_POST['stamps_password'] ?? '');
            $integrationId = trim($_POST['stamps_integration_id'] ?? '');
            $environment = $_POST['stamps_environment'] ?? 'sandbox';
            $enabled = isset($_POST['stamps_enabled']) ? 1 : 0;
            
            // Store configuration (you'll implement actual storage later)
            $config = [
                'stamps_com' => [
                    'username' => $username,
                    'password' => $password, // In production, encrypt this
                    'integration_id' => $integrationId,
                    'environment' => $environment,
                    'enabled' => $enabled
                ]
            ];
            
            // For now, store in a simple config file (in production, use database)
            file_put_contents(__DIR__ . '/../config/shipping_apis.json', json_encode($config, JSON_PRETTY_PRINT));
            
            $message = 'STAMPS.COM configuration saved successfully';
            
        } elseif ($action === 'save_shipstation_config') {
            $apiKey = trim($_POST['shipstation_api_key'] ?? '');
            $apiSecret = trim($_POST['shipstation_api_secret'] ?? '');
            $storeId = trim($_POST['shipstation_store_id'] ?? '');
            $environment = $_POST['shipstation_environment'] ?? 'production';
            $enabled = isset($_POST['shipstation_enabled']) ? 1 : 0;
            
            $config = [
                'shipstation_com' => [
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret, // In production, encrypt this
                    'store_id' => $storeId,
                    'environment' => $environment,
                    'enabled' => $enabled
                ]
            ];
            
            // For now, store in a simple config file (in production, use database)
            $existingConfig = [];
            $configFile = __DIR__ . '/../config/shipping_apis.json';
            if (file_exists($configFile)) {
                $existingConfig = json_decode(file_get_contents($configFile), true) ?? [];
            }
            
            $existingConfig = array_merge($existingConfig, $config);
            file_put_contents($configFile, json_encode($existingConfig, JSON_PRETTY_PRINT));
            
            $message = 'ShipStation configuration saved successfully';
            
        } elseif ($action === 'test_stamps_connection') {
            // Placeholder for testing STAMPS.COM connection
            $message = 'STAMPS.COM connection test - Feature coming soon';
            
        } elseif ($action === 'test_shipstation_connection') {
            // Placeholder for testing ShipStation connection
            $message = 'ShipStation connection test - Feature coming soon';
        }
        
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Load existing configuration
$configFile = __DIR__ . '/../config/shipping_apis.json';
$config = [];
if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true) ?? [];
}

$stampsConfig = $config['stamps_com'] ?? [];
$shipstationConfig = $config['shipstation_com'] ?? [];
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>🚚 Shipping API Configuration</h1>
                <p class="muted">Configure shipping label providers and API settings</p>
            </div>
            <a href="<?php echo path_with_base('/admin/'); ?>" class="btn secondary">← Back to Admin</a>
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

        <!-- API Configuration Tabs -->
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; gap: 8px; margin-bottom: 24px;">
                <button onclick="showTab('stamps')" id="stamps-tab" class="btn">📮 STAMPS.COM</button>
                <button onclick="showTab('shipstation')" id="shipstation-tab" class="btn secondary">📦 ShipStation</button>
            </div>

            <!-- STAMPS.COM Configuration -->
            <div id="stamps-config" class="config-tab">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h2 style="margin: 0;">📮 STAMPS.COM Configuration</h2>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 12px; color: #6b7280;">Status:</span>
                        <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; <?php echo ($stampsConfig['enabled'] ?? false) ? 'background: #f0fdf4; color: #059669;' : 'background: #fef2f2; color: #dc2626;'; ?>">
                            <?php echo ($stampsConfig['enabled'] ?? false) ? '✅ Enabled' : '❌ Disabled'; ?>
                        </span>
                    </div>
                </div>
                
                <form method="post">
                    <input type="hidden" name="action" value="save_stamps_config">
                    
                    <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 16px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 8px 0; color: #0c4a6e;">📋 Setup Instructions</h4>
                        <ol style="margin: 0; padding-left: 20px; font-size: 14px; color: #0c4a6e;">
                            <li>Create a STAMPS.COM developer account</li>
                            <li>Obtain your Integration ID from the developer portal</li>
                            <li>Use your STAMPS.COM username and password</li>
                            <li>Test in sandbox mode before going live</li>
                        </ol>
                    </div>
                    
                    <div class="form-grid">
                        <label>
                            <span>Username</span>
                            <input type="text" name="stamps_username" value="<?php echo htmlspecialchars($stampsConfig['username'] ?? ''); ?>" placeholder="Your STAMPS.COM username">
                        </label>
                        <label>
                            <span>Password</span>
                            <input type="password" name="stamps_password" value="<?php echo htmlspecialchars($stampsConfig['password'] ?? ''); ?>" placeholder="Your STAMPS.COM password">
                        </label>
                        <label>
                            <span>Integration ID</span>
                            <input type="text" name="stamps_integration_id" value="<?php echo htmlspecialchars($stampsConfig['integration_id'] ?? ''); ?>" placeholder="Developer Integration ID">
                        </label>
                        <label>
                            <span>Environment</span>
                            <select name="stamps_environment">
                                <option value="sandbox" <?php echo ($stampsConfig['environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : ''; ?>>Sandbox (Testing)</option>
                                <option value="production" <?php echo ($stampsConfig['environment'] ?? 'sandbox') === 'production' ? 'selected' : ''; ?>>Production (Live)</option>
                            </select>
                        </label>
                    </div>
                    
                    <div style="margin: 20px 0;">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="stamps_enabled" <?php echo ($stampsConfig['enabled'] ?? false) ? 'checked' : ''; ?>>
                            <span>Enable STAMPS.COM Integration</span>
                        </label>
                    </div>
                    
                    <div style="display: flex; gap: 12px;">
                        <button type="submit" class="btn">💾 Save Configuration</button>
                        <button type="submit" name="action" value="test_stamps_connection" class="btn secondary">🔧 Test Connection</button>
                    </div>
                </form>
            </div>

            <!-- ShipStation Configuration -->
            <div id="shipstation-config" class="config-tab" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h2 style="margin: 0;">📦 ShipStation Configuration</h2>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 12px; color: #6b7280;">Status:</span>
                        <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; <?php echo ($shipstationConfig['enabled'] ?? false) ? 'background: #f0fdf4; color: #059669;' : 'background: #fef2f2; color: #dc2626;'; ?>">
                            <?php echo ($shipstationConfig['enabled'] ?? false) ? '✅ Enabled' : '❌ Disabled'; ?>
                        </span>
                    </div>
                </div>
                
                <form method="post">
                    <input type="hidden" name="action" value="save_shipstation_config">
                    
                    <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 16px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 8px 0; color: #0c4a6e;">📋 Setup Instructions</h4>
                        <ol style="margin: 0; padding-left: 20px; font-size: 14px; color: #0c4a6e;">
                            <li>Log into your ShipStation account</li>
                            <li>Go to Account → API Settings</li>
                            <li>Generate new API Key and Secret</li>
                            <li>Note your Store ID from the stores section</li>
                        </ol>
                    </div>
                    
                    <div class="form-grid">
                        <label>
                            <span>API Key</span>
                            <input type="text" name="shipstation_api_key" value="<?php echo htmlspecialchars($shipstationConfig['api_key'] ?? ''); ?>" placeholder="Your ShipStation API Key">
                        </label>
                        <label>
                            <span>API Secret</span>
                            <input type="password" name="shipstation_api_secret" value="<?php echo htmlspecialchars($shipstationConfig['api_secret'] ?? ''); ?>" placeholder="Your ShipStation API Secret">
                        </label>
                        <label>
                            <span>Store ID</span>
                            <input type="text" name="shipstation_store_id" value="<?php echo htmlspecialchars($shipstationConfig['store_id'] ?? ''); ?>" placeholder="ShipStation Store ID">
                        </label>
                        <label>
                            <span>Environment</span>
                            <select name="shipstation_environment">
                                <option value="production" <?php echo ($shipstationConfig['environment'] ?? 'production') === 'production' ? 'selected' : ''; ?>>Production</option>
                            </select>
                        </label>
                    </div>
                    
                    <div style="margin: 20px 0;">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="shipstation_enabled" <?php echo ($shipstationConfig['enabled'] ?? false) ? 'checked' : ''; ?>>
                            <span>Enable ShipStation Integration</span>
                        </label>
                    </div>
                    
                    <div style="display: flex; gap: 12px;">
                        <button type="submit" class="btn">💾 Save Configuration</button>
                        <button type="submit" name="action" value="test_shipstation_connection" class="btn secondary">🔧 Test Connection</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Shipping Settings -->
        <div class="card">
            <h2>⚙️ General Shipping Settings</h2>
            <div style="background: #fffbeb; border: 1px solid #fed7aa; border-radius: 6px; padding: 16px;">
                <h4 style="margin: 0 0 12px 0; color: #92400e;">🚧 Coming Soon</h4>
                <ul style="margin: 0; padding-left: 20px; color: #92400e;">
                    <li>Default shipping methods and rates</li>
                    <li>Package size and weight defaults</li>
                    <li>Return address configuration</li>
                    <li>Label printing preferences</li>
                    <li>Automated shipping rules</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<script>
function showTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.config-tab').forEach(tab => {
        tab.style.display = 'none';
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('[id$="-tab"]').forEach(btn => {
        btn.classList.remove('btn');
        btn.classList.add('btn', 'secondary');
    });
    
    // Show selected tab
    document.getElementById(tabName + '-config').style.display = 'block';
    
    // Make selected tab button active
    const activeBtn = document.getElementById(tabName + '-tab');
    activeBtn.classList.remove('secondary');
    activeBtn.classList.add('btn');
}

// Initialize with STAMPS.COM tab active
document.addEventListener('DOMContentLoaded', function() {
    showTab('stamps');
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
