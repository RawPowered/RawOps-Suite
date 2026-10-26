<?php
/**
 * Printify Webhook Processor
 * 
 * Background processor for queued webhook events
 * Run this via cron or manually to process pending webhooks
 * 
 * Usage: php printify-webhook-processor.php
 * Or access via URL: /api/printify-webhook-processor.php
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/printify-sync.php';
require_once __DIR__ . '/../required/printify-fulfillment.php';

// Allow CLI execution
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // If accessed via web, require admin
    start_session();
    require_admin_or_redirect();
}

try {
    $pdo = db();
    $processed = 0;
    $failed = 0;
    $results = [];
    
    // Get config
    $config = site_read_config();
    $shopId = $config['printify_shop_id'] ?? null;
    
    if (!$shopId) {
        throw new Exception('Printify shop ID not configured');
    }
    
    // Fetch unprocessed webhooks (limit to 50 per run)
    $stmt = $pdo->query('
        SELECT * FROM printify_webhooks
        WHERE processed = 0
        AND retry_count < 5
        ORDER BY received_at ASC
        LIMIT 50
    ');
    $webhooks = $stmt->fetchAll();
    
    echo $isCli ? "Processing " . count($webhooks) . " webhooks...\n" : "";
    
    foreach ($webhooks as $webhook) {
        try {
            $payload = json_decode($webhook['payload'], true);
            $eventType = $webhook['event_type'];
            $resourceType = $webhook['resource_type'];
            $resourceId = $webhook['resource_id'];
            
            echo $isCli ? "Processing: {$eventType} for {$resourceType} {$resourceId}...\n" : "";
            
            $handled = false;
            
            // Handle product events
            if ($resourceType === 'product') {
                $sync = new PrintifySync($shopId);
                
                switch ($eventType) {
                    case 'product:created':
                    case 'product:updated':
                        // Import updated product
                        $productData = $payload['resource'] ?? [];
                        if (!empty($productData)) {
                            $sync->importSingleProduct($productData, false);
                            $handled = true;
                        }
                        break;
                        
                    case 'product:deleted':
                        // Mark product as deleted in our cache
                        $stmt = $pdo->prepare('
                            UPDATE printify_products SET
                                is_deleted = 1,
                                updated_at = NOW()
                            WHERE printify_product_id = ?
                        ');
                        $stmt->execute([$resourceId]);
                        $handled = true;
                        break;
                        
                    case 'product:publish:succeeded':
                    case 'product:publish:failed':
                        // Update publish status
                        $success = ($eventType === 'product:publish:succeeded');
                        $stmt = $pdo->prepare('
                            UPDATE printify_products SET
                                is_published = ?,
                                updated_at = NOW()
                            WHERE printify_product_id = ?
                        ');
                        $stmt->execute([$success ? 1 : 0, $resourceId]);
                        $handled = true;
                        break;
                }
            }
            
            // Handle order events
            elseif ($resourceType === 'order') {
                $fulfillment = new PrintifyFulfillment($shopId);
                
                switch ($eventType) {
                    case 'order:created':
                    case 'order:updated':
                    case 'order:sent-to-production':
                    case 'order:shipment:created':
                    case 'order:shipment:delivered':
                        // Update order status
                        $orderData = $payload['resource'] ?? [];
                        if (!empty($orderData) && !empty($resourceId)) {
                            $fulfillment->updateOrderStatus($resourceId, $orderData);
                            $handled = true;
                        }
                        break;
                }
            }
            
            // Mark as processed
            if ($handled) {
                $stmt = $pdo->prepare('
                    UPDATE printify_webhooks SET
                        processed = 1,
                        processed_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$webhook['id']]);
                
                $processed++;
                $results[] = [
                    'id' => $webhook['id'],
                    'event' => $eventType,
                    'status' => 'processed'
                ];
                
                echo $isCli ? "✓ Processed\n" : "";
            } else {
                // Event type not handled - mark as processed anyway to avoid retrying
                $stmt = $pdo->prepare('
                    UPDATE printify_webhooks SET
                        processed = 1,
                        processed_at = NOW(),
                        processing_error = ?
                    WHERE id = ?
                ');
                $stmt->execute(['Event type not handled', $webhook['id']]);
                
                echo $isCli ? "⊘ Skipped (not handled)\n" : "";
            }
            
        } catch (Exception $e) {
            $failed++;
            $errorMsg = $e->getMessage();
            
            // Update retry count and error
            $stmt = $pdo->prepare('
                UPDATE printify_webhooks SET
                    retry_count = retry_count + 1,
                    processing_error = ?
                WHERE id = ?
            ');
            $stmt->execute([$errorMsg, $webhook['id']]);
            
            $results[] = [
                'id' => $webhook['id'],
                'event' => $eventType ?? 'unknown',
                'status' => 'failed',
                'error' => $errorMsg
            ];
            
            echo $isCli ? "✗ Failed: {$errorMsg}\n" : "";
            error_log('[Printify Webhook Processor] Failed to process webhook ' . $webhook['id'] . ': ' . $errorMsg);
        }
    }
    
    $summary = [
        'total' => count($webhooks),
        'processed' => $processed,
        'failed' => $failed,
        'results' => $results
    ];
    
    if ($isCli) {
        echo "\nSummary:\n";
        echo "Total: " . $summary['total'] . "\n";
        echo "Processed: " . $summary['processed'] . "\n";
        echo "Failed: " . $summary['failed'] . "\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode($summary, JSON_PRETTY_PRINT);
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    
    if ($isCli) {
        echo "Error: {$error}\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $error]);
    }
}

