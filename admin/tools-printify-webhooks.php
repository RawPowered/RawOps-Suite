<?php
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/printify-api.php';

start_session();
require_admin_or_redirect();

$pdo = db();

// Get config
$config = site_read_config();
$shopId = $config['printify_shop_id'] ?? null;
$apiKey = $config['printify_api_key'] ?? null;

$message = null;
$error = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $shopId && $apiKey) {
    $action = $_POST['action'] ?? '';
    
    try {
        $api = new PrintifyAPI($apiKey, $shopId);
        
        if ($action === 'register_webhook') {
            $topic = $_POST['topic'] ?? '';
            $webhookUrl = site_origin() . '/api/printify-webhook.php';
            
            $result = $api->createWebhook($topic, $webhookUrl, $shopId);
            $webhookId = $result['id'] ?? null;
            
            if ($webhookId) {
                // Save to database
                $stmt = $pdo->prepare('
                    INSERT INTO printify_webhook_registrations (
                        printify_webhook_id, shop_id, topic, url, registered_at
                    ) VALUES (?, ?, ?, ?, NOW())
                ');
                $stmt->execute([$webhookId, $shopId, $topic, $webhookUrl]);
                
                $message = 'Webhook registered successfully';
            }
        } elseif ($action === 'delete_webhook') {
            $webhookId = $_POST['webhook_id'] ?? '';
            
            $api->deleteWebhook($webhookId, $shopId);
            
            // Update local database
            $stmt = $pdo->prepare('
                UPDATE printify_webhook_registrations SET
                    is_active = 0,
                    deactivated_at = NOW()
                WHERE printify_webhook_id = ?
            ');
            $stmt->execute([$webhookId]);
            
            $message = 'Webhook deleted successfully';
        } elseif ($action === 'process_webhooks') {
            // Trigger webhook processor
            $processorUrl = site_origin() . '/api/printify-webhook-processor.php';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $processorUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $message = 'Webhook processing triggered';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get registered webhooks from Printify
$printifyWebhooks = [];
if ($shopId && $apiKey) {
    try {
        $api = new PrintifyAPI($apiKey, $shopId);
        $response = $api->getWebhooks($shopId);
        $printifyWebhooks = $response ?? [];
    } catch (Exception $e) {
        $error = 'Failed to fetch webhooks: ' . $e->getMessage();
    }
}

// Get local webhook registrations
$localWebhooks = $pdo->query('
    SELECT * FROM printify_webhook_registrations
    WHERE is_active = 1
    ORDER BY registered_at DESC
')->fetchAll();

// Get recent webhook events
$recentEvents = $pdo->query('
    SELECT * FROM printify_webhooks
    ORDER BY received_at DESC
    LIMIT 50
')->fetchAll();

// Get unprocessed count
$stmt = $pdo->query('SELECT COUNT(*) as count FROM printify_webhooks WHERE processed = 0');
$unprocessedCount = $stmt->fetch()['count'] ?? 0;

$SEO = [
    'title' => 'Webhooks — Printify Integration',
    'description' => 'Manage Printify webhooks',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/admin-menu.php';
?>

<style>
.webhook-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.webhook-topics {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 12px;
    margin-top: 16px;
}
.topic-btn {
    padding: 12px;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 13px;
}
.topic-btn:hover {
    background: #e0f2fe;
    border-color: #0ea5e9;
}
.event-table {
    width: 100%;
    border-collapse: collapse;
}
.event-table th {
    background: #f8fafc;
    padding: 10px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 12px;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
}
.event-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}
</style>

<div class="container" style="margin-top: 40px; max-width: 1200px;">
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="margin: 0 0 8px 0; color: #0f172a; font-size: 28px; font-weight: 700;">
                🔔 Webhook Management
            </h1>
            <p style="margin: 0; color: #64748b; font-size: 16px;">
                Real-time event notifications from Printify
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
    
    <?php if (!$shopId || !$apiKey): ?>
        <div style="background: #fef3c7; border: 1px solid #fbbf24; color: #92400e; padding: 20px; border-radius: 8px; text-align: center;">
            <p style="margin: 0 0 16px 0; font-weight: 600;">Printify not configured</p>
            <a href="<?php echo path_with_base('/admin/tools-printify-integration.php'); ?>" class="btn" style="background: #3b82f6; color: white;">
                Configure Printify Integration
            </a>
        </div>
    <?php else: ?>
        
        <!-- Webhook Endpoint Info -->
        <div class="webhook-card">
            <h2 style="margin: 0 0 16px 0; font-size: 18px; color: #0f172a;">📍 Webhook Endpoint</h2>
            <div style="background: #f8fafc; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 13px; border: 1px solid #e2e8f0;">
                <?php echo site_origin() . '/api/printify-webhook.php'; ?>
            </div>
            <p style="margin: 12px 0 0 0; font-size: 13px; color: #64748b;">
                Use this URL when registering webhooks in Printify
            </p>
        </div>
        
        <!-- Register New Webhook -->
        <div class="webhook-card">
            <h2 style="margin: 0 0 16px 0; font-size: 18px; color: #0f172a;">➕ Register Webhooks</h2>
            
            <form method="post" style="margin-top: 16px;">
                <input type="hidden" name="action" value="register_webhook">
                
                <div class="webhook-topics">
                    <?php 
                    $availableTopics = [
                        'product:created' => '📦 Product Created',
                        'product:updated' => '📝 Product Updated',
                        'product:deleted' => '🗑️ Product Deleted',
                        'product:publish:started' => '🚀 Publish Started',
                        'product:publish:succeeded' => '✅ Publish Succeeded',
                        'product:publish:failed' => '❌ Publish Failed',
                        'order:created' => '🛒 Order Created',
                        'order:updated' => '📋 Order Updated',
                        'order:sent-to-production' => '⚙️ Sent to Production',
                        'order:shipment:created' => '📬 Shipment Created',
                        'order:shipment:delivered' => '✅ Delivered'
                    ];
                    
                    foreach ($availableTopics as $topic => $label):
                        $isRegistered = false;
                        foreach ($printifyWebhooks as $wh) {
                            if (($wh['topic'] ?? '') === $topic) {
                                $isRegistered = true;
                                break;
                            }
                        }
                    ?>
                        <button 
                            type="submit" 
                            name="topic" 
                            value="<?php echo $topic; ?>" 
                            class="topic-btn"
                            <?php echo $isRegistered ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?>
                            onclick="return confirm('Register webhook for: <?php echo htmlspecialchars($label); ?>?');"
                        >
                            <?php echo $label; ?>
                            <?php if ($isRegistered): ?>
                                <span style="color: #059669;">✓</span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
        
        <!-- Registered Webhooks -->
        <?php if (!empty($printifyWebhooks)): ?>
        <div class="webhook-card">
            <h2 style="margin: 0 0 16px 0; font-size: 18px; color: #0f172a;">✅ Active Webhooks</h2>
            
            <table class="event-table">
                <thead>
                    <tr>
                        <th>Topic</th>
                        <th>Webhook ID</th>
                        <th>URL</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($printifyWebhooks as $webhook): ?>
                        <tr>
                            <td style="font-weight: 500;">
                                <?php echo htmlspecialchars($webhook['topic'] ?? 'unknown'); ?>
                            </td>
                            <td style="font-family: monospace; font-size: 11px; color: #64748b;">
                                <?php echo htmlspecialchars(substr($webhook['id'] ?? '', 0, 20)); ?>...
                            </td>
                            <td style="font-size: 11px; color: #64748b;">
                                <?php echo htmlspecialchars(substr($webhook['url'] ?? '', 0, 50)); ?>...
                            </td>
                            <td>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_webhook">
                                    <input type="hidden" name="webhook_id" value="<?php echo htmlspecialchars($webhook['id'] ?? ''); ?>">
                                    <button 
                                        type="submit" 
                                        class="btn small" 
                                        style="background: #dc2626; color: white; padding: 4px 8px; font-size: 11px;"
                                        onclick="return confirm('Delete this webhook?');"
                                    >
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Webhook Events -->
        <div class="webhook-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h2 style="margin: 0; font-size: 18px; color: #0f172a;">📨 Recent Events</h2>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <?php if ($unprocessedCount > 0): ?>
                        <span style="background: #fbbf24; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                            <?php echo $unprocessedCount; ?> Unprocessed
                        </span>
                    <?php endif; ?>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="process_webhooks">
                        <button type="submit" class="btn" style="background: #3b82f6; color: white;">
                            Process Now
                        </button>
                    </form>
                </div>
            </div>
            
            <?php if (!empty($recentEvents)): ?>
                <table class="event-table">
                    <thead>
                        <tr>
                            <th>Event Type</th>
                            <th>Resource</th>
                            <th>Status</th>
                            <th>Received</th>
                            <th>Processed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentEvents as $event): ?>
                            <tr>
                                <td style="font-weight: 500; font-size: 12px;">
                                    <?php echo htmlspecialchars($event['event_type']); ?>
                                </td>
                                <td style="font-family: monospace; font-size: 11px;">
                                    <?php echo htmlspecialchars($event['resource_type']); ?>
                                    <span style="color: #94a3b8;">#<?php echo htmlspecialchars(substr($event['resource_id'] ?? '', 0, 8)); ?></span>
                                </td>
                                <td>
                                    <?php if ($event['processed']): ?>
                                        <span style="color: #059669;">✓ Processed</span>
                                    <?php else: ?>
                                        <span style="color: #fbbf24;">⏳ Pending</span>
                                    <?php endif; ?>
                                    <?php if ($event['processing_error']): ?>
                                        <br><span style="color: #dc2626; font-size: 11px;"><?php echo htmlspecialchars($event['processing_error']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 11px; color: #64748b;">
                                    <?php echo date('M j, g:i A', strtotime($event['received_at'])); ?>
                                </td>
                                <td style="font-size: 11px; color: #64748b;">
                                    <?php echo $event['processed_at'] ? date('M j, g:i A', strtotime($event['processed_at'])) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #94a3b8;">
                    No webhook events received yet
                </div>
            <?php endif; ?>
        </div>
        
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

