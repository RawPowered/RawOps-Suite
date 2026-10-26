<?php
/**
 * Printify Webhook Receiver
 * 
 * Receives and stores webhook events from Printify
 * Validates signatures and processes events asynchronously
 */

require_once __DIR__ . '/../required/config.php';

// Log all webhook attempts
error_log('[Printify Webhook] Request received from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

try {
    $pdo = db();
    
    // Get raw POST body
    $rawPayload = file_get_contents('php://input');
    
    if (empty($rawPayload)) {
        http_response_code(400);
        echo json_encode(['error' => 'No payload received']);
        exit;
    }
    
    // Parse JSON payload
    $payload = json_decode($rawPayload, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }
    
    // Extract webhook data
    $webhookId = $payload['id'] ?? null;
    $eventType = $payload['type'] ?? 'unknown';
    $resourceType = $payload['resource']['type'] ?? 'unknown';
    $resourceId = $payload['resource']['id'] ?? null;
    $shopId = $payload['shop_id'] ?? null;
    
    // Get request metadata
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $signature = $_SERVER['HTTP_X_PRINTIFY_SIGNATURE'] ?? null;
    
    // Validate signature if available
    if ($signature) {
        $config = site_read_config();
        $webhookSecret = $config['printify_webhook_secret'] ?? null;
        
        if ($webhookSecret) {
            $computedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);
            
            if (!hash_equals($computedSignature, $signature)) {
                error_log('[Printify Webhook] Invalid signature from ' . $ipAddress);
                http_response_code(401);
                echo json_encode(['error' => 'Invalid signature']);
                exit;
            }
        }
    }
    
    // Store webhook in database for async processing
    $stmt = $pdo->prepare('
        INSERT INTO printify_webhooks (
            webhook_id, event_type, resource_type, resource_id, shop_id,
            payload, processed, ip_address, user_agent, signature, received_at
        ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NOW())
    ');
    
    $stmt->execute([
        $webhookId,
        $eventType,
        $resourceType,
        $resourceId,
        $shopId,
        json_encode($payload),
        $ipAddress,
        $userAgent,
        $signature
    ]);
    
    $webhookDbId = $pdo->lastInsertId();
    
    error_log('[Printify Webhook] Stored event: ' . $eventType . ' for ' . $resourceType . ' ' . $resourceId);
    
    // Process immediately for critical events
    $criticalEvents = [
        'order:shipment:created',
        'order:shipment:delivered',
        'order:sent-to-production'
    ];
    
    if (in_array($eventType, $criticalEvents)) {
        try {
            require_once __DIR__ . '/../required/printify-fulfillment.php';
            
            $fulfillment = new PrintifyFulfillment($shopId);
            
            if ($resourceType === 'order' && !empty($resourceId)) {
                $fulfillment->updateOrderStatus($resourceId, $payload['resource'] ?? []);
                
                // Mark as processed
                $stmt = $pdo->prepare('
                    UPDATE printify_webhooks SET
                        processed = 1,
                        processed_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$webhookDbId]);
                
                error_log('[Printify Webhook] Processed critical event immediately: ' . $eventType);
            }
        } catch (Exception $e) {
            error_log('[Printify Webhook] Failed to process critical event: ' . $e->getMessage());
            
            // Log error but don't fail the webhook
            $stmt = $pdo->prepare('
                UPDATE printify_webhooks SET
                    processing_error = ?,
                    retry_count = retry_count + 1
                WHERE id = ?
            ');
            $stmt->execute([$e->getMessage(), $webhookDbId]);
        }
    }
    
    // Return success
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Webhook received',
        'event_type' => $eventType,
        'id' => $webhookDbId
    ]);
    
} catch (Exception $e) {
    error_log('[Printify Webhook] Error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

