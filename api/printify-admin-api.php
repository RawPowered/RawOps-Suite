<?php
/**
 * Printify Admin API
 * 
 * AJAX endpoints for admin Printify operations
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/printify-sync.php';
require_once __DIR__ . '/../required/printify-fulfillment.php';
require_once __DIR__ . '/../required/printify-api.php';

header('Content-Type: application/json');

start_session();

// Require admin access
if (!current_admin_id()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$pdo = db();

try {
    // Get config
    $config = site_read_config();
    $shopId = $config['printify_shop_id'] ?? null;
    
    if (!$shopId && $action !== 'test_connection') {
        throw new Exception('Printify shop ID not configured');
    }
    
    switch ($action) {
        // ===================================================================
        // CONNECTION TESTING
        // ===================================================================
        case 'test_connection':
            $apiKey = $_POST['api_key'] ?? $config['printify_api_key'] ?? null;
            
            if (!$apiKey) {
                throw new Exception('API key required');
            }
            
            $api = new PrintifyAPI($apiKey);
            $result = $api->testConnection();
            
            echo json_encode($result);
            break;
            
        // ===================================================================
        // PRODUCT SYNC
        // ===================================================================
        case 'sync_product':
            $productId = $_POST['product_id'] ?? null;
            $direction = $_POST['direction'] ?? 'import'; // import or export
            
            if (!$productId) {
                throw new Exception('Product ID required');
            }
            
            $sync = new PrintifySync($shopId, current_user_id());
            
            if ($direction === 'import') {
                // Import from Printify
                $stmt = $pdo->prepare('SELECT printify_product_id FROM products WHERE id = ?');
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                
                if (!$product || !$product['printify_product_id']) {
                    throw new Exception('Product not linked to Printify');
                }
                
                $api = new PrintifyAPI(null, $shopId);
                $printifyProduct = $api->getProduct($product['printify_product_id'], $shopId);
                
                $result = $sync->importSingleProduct($printifyProduct, false);
                
            } else {
                // Export to Printify
                $result = $sync->exportSingleProduct($productId);
            }
            
            echo json_encode([
                'success' => true,
                'result' => $result
            ]);
            break;
            
        case 'bulk_sync':
            $productIds = $_POST['product_ids'] ?? [];
            $direction = $_POST['direction'] ?? 'both';
            
            if (empty($productIds)) {
                throw new Exception('No products selected');
            }
            
            $sync = new PrintifySync($shopId, current_user_id());
            
            $results = [
                'total' => count($productIds),
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];
            
            foreach ($productIds as $productId) {
                try {
                    if ($direction === 'export' || $direction === 'both') {
                        $sync->exportSingleProduct($productId);
                    }
                    $results['success']++;
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'product_id' => $productId,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            echo json_encode([
                'success' => true,
                'results' => $results
            ]);
            break;
            
        case 'sync_status':
            $productId = $_POST['product_id'] ?? null;
            
            if (!$productId) {
                throw new Exception('Product ID required');
            }
            
            $stmt = $pdo->prepare('
                SELECT p.*, pp.last_synced_at as printify_sync,
                       ppm.sync_status, ppm.last_synced_at as mapping_sync
                FROM products p
                LEFT JOIN printify_product_mapping ppm ON ppm.product_id = p.id
                LEFT JOIN printify_products pp ON pp.id = ppm.printify_product_id
                WHERE p.id = ?
            ');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'product' => $product
            ]);
            break;
            
        // ===================================================================
        // ORDER FULFILLMENT
        // ===================================================================
        case 'submit_order':
            $orderId = $_POST['order_id'] ?? null;
            
            if (!$orderId) {
                throw new Exception('Order ID required');
            }
            
            $fulfillment = new PrintifyFulfillment($shopId);
            $result = $fulfillment->submitOrder($orderId, true);
            
            echo json_encode([
                'success' => true,
                'result' => $result
            ]);
            break;
            
        case 'order_status':
            $orderId = $_POST['order_id'] ?? null;
            
            if (!$orderId) {
                throw new Exception('Order ID required');
            }
            
            $fulfillment = new PrintifyFulfillment($shopId);
            $status = $fulfillment->getOrderStatus($orderId);
            
            echo json_encode([
                'success' => true,
                'status' => $status
            ]);
            break;
            
        case 'cancel_order':
            $orderId = $_POST['order_id'] ?? null;
            
            if (!$orderId) {
                throw new Exception('Order ID required');
            }
            
            $fulfillment = new PrintifyFulfillment($shopId);
            $result = $fulfillment->cancelOrder($orderId);
            
            echo json_encode([
                'success' => true,
                'message' => 'Order cancelled successfully'
            ]);
            break;
            
        // ===================================================================
        // WEBHOOKS
        // ===================================================================
        case 'refresh_webhooks':
            $api = new PrintifyAPI(null, $shopId);
            $webhooks = $api->getWebhooks($shopId);
            
            // Update local database
            foreach ($webhooks as $webhook) {
                $stmt = $pdo->prepare('
                    INSERT INTO printify_webhook_registrations (
                        printify_webhook_id, shop_id, topic, url, registered_at, last_verified_at
                    ) VALUES (?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        last_verified_at = NOW(),
                        is_active = 1
                ');
                $stmt->execute([
                    $webhook['id'],
                    $shopId,
                    $webhook['topic'] ?? '',
                    $webhook['url'] ?? ''
                ]);
            }
            
            echo json_encode([
                'success' => true,
                'webhooks' => $webhooks,
                'count' => count($webhooks)
            ]);
            break;
            
        // ===================================================================
        // CATALOG SEARCH
        // ===================================================================
        case 'catalog_search':
            $query = $_GET['q'] ?? '';
            
            $api = new PrintifyAPI(null, $shopId);
            
            // Get blueprints
            $blueprints = $api->getBlueprints();
            
            // Filter if search query provided
            if ($query) {
                $blueprints = array_filter($blueprints, function($bp) use ($query) {
                    return stripos($bp['title'] ?? '', $query) !== false ||
                           stripos($bp['description'] ?? '', $query) !== false;
                });
            }
            
            echo json_encode([
                'success' => true,
                'blueprints' => array_values($blueprints)
            ]);
            break;
            
        // ===================================================================
        // IMAGE SYNC
        // ===================================================================
        case 'upload_image':
            $productId = $_POST['product_id'] ?? null;
            
            if (!$productId) {
                throw new Exception('Product ID required');
            }
            
            $sync = new PrintifySync($shopId, current_user_id());
            $result = $sync->uploadProductImages($productId);
            
            echo json_encode([
                'success' => true,
                'result' => $result
            ]);
            break;
            
        case 'upload_all_images':
            $sync = new PrintifySync($shopId, current_user_id());
            $result = $sync->syncAllProductImages();
            
            echo json_encode([
                'success' => true,
                'result' => $result
            ]);
            break;
            
        case 'download_image':
            $printifyProductId = $_POST['printify_product_id'] ?? null;
            
            if (!$printifyProductId) {
                throw new Exception('Printify product ID required');
            }
            
            $sync = new PrintifySync($shopId, current_user_id());
            $result = $sync->downloadProductImages($printifyProductId);
            
            echo json_encode([
                'success' => true,
                'result' => $result
            ]);
            break;
            
        // ===================================================================
        // PUBLISHING
        // ===================================================================
        case 'publish_product':
            $productId = $_POST['product_id'] ?? null;
            $storeIds = $_POST['store_ids'] ?? [];
            
            if (!$productId) {
                throw new Exception('Product ID required');
            }
            
            $sync = new PrintifySync($shopId, current_user_id());
            $result = $sync->publishProductToStores($productId, $storeIds);
            
            echo json_encode([
                'success' => true,
                'result' => $result
            ]);
            break;
            
        case 'auto_publish_all':
            $sync = new PrintifySync($shopId, current_user_id());
            $result = $sync->autoPublishAllProducts();
            
            echo json_encode([
                'success' => true,
                'result' => $result
            ]);
            break;
            
        case 'get_publish_status':
            $productId = $_POST['product_id'] ?? null;
            
            if (!$productId) {
                throw new Exception('Product ID required');
            }
            
            $stmt = $pdo->prepare('
                SELECT * FROM printify_publish_log 
                WHERE product_id = ? 
                ORDER BY created_at DESC 
                LIMIT 10
            ');
            $stmt->execute([$productId]);
            $logs = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'publish_logs' => $logs
            ]);
            break;
            
        // ===================================================================
        // DEFAULT
        // ===================================================================
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
        'success' => false
    ]);
}

