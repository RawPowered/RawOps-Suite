<?php
/**
 * Printify Order Fulfillment Handler
 * 
 * Automatically submits orders to Printify for production and shipping
 * Handles order creation, submission, tracking updates
 */

require_once __DIR__ . '/printify-api.php';

class PrintifyFulfillment {
    
    private $pdo;
    private $api;
    private $shopId;
    
    public function __construct($shopId = null) {
        $this->pdo = db();
        
        // Load shop ID from config if not provided
        if ($shopId === null) {
            $config = site_read_config();
            $shopId = $config['printify_shop_id'] ?? null;
        }
        
        if (empty($shopId)) {
            throw new Exception('Printify shop ID is required for fulfillment');
        }
        
        $this->shopId = $shopId;
        $this->api = new PrintifyAPI(null, $shopId);
    }
    
    /**
     * Submit order to Printify for fulfillment
     * 
     * @param int $orderId Local order ID
     * @param bool $autoSubmit Automatically submit to production (default true)
     * @return array Result with Printify order ID
     */
    public function submitOrder($orderId, $autoSubmit = true) {
        try {
            // Get local order
            $stmt = $this->pdo->prepare('
                SELECT o.*, u.email, u.first_name, u.last_name, u.phone,
                       wa.address_line1, wa.address_line2, wa.city, 
                       wa.state_region, wa.postal_code, wa.country
                FROM orders o
                LEFT JOIN users u ON u.id = o.user_id
                LEFT JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
                WHERE o.id = ?
            ');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception('Order not found');
            }
            
            // Check if already submitted to Printify
            $stmt = $this->pdo->prepare('SELECT * FROM printify_orders WHERE order_id = ?');
            $stmt->execute([$orderId]);
            $existingPrintifyOrder = $stmt->fetch();
            
            if ($existingPrintifyOrder && !empty($existingPrintifyOrder['printify_order_id'])) {
                return [
                    'success' => true,
                    'message' => 'Order already submitted to Printify',
                    'printify_order_id' => $existingPrintifyOrder['printify_order_id'],
                    'already_submitted' => true
                ];
            }
            
            // Get order items
            $stmt = $this->pdo->prepare('
                SELECT oi.*, p.printify_product_id, p.printify_variant_id,
                       ppm.printify_variant_id as mapping_variant_id
                FROM order_items oi
                JOIN products p ON p.id = oi.product_id
                LEFT JOIN printify_product_mapping ppm ON ppm.product_id = p.id
                WHERE oi.order_id = ?
            ');
            $stmt->execute([$orderId]);
            $orderItems = $stmt->fetchAll();
            
            // Filter Printify items
            $printifyItems = array_filter($orderItems, function($item) {
                return !empty($item['printify_product_id']) && !empty($item['printify_variant_id']);
            });
            
            if (empty($printifyItems)) {
                throw new Exception('No Printify products in this order');
            }
            
            // Build Printify order payload
            $lineItems = [];
            foreach ($printifyItems as $item) {
                $variantId = $item['mapping_variant_id'] ?: $item['printify_variant_id'];
                
                $lineItems[] = [
                    'product_id' => $item['printify_product_id'],
                    'variant_id' => (int)$variantId,
                    'quantity' => (int)$item['quantity']
                ];
            }
            
            // Build shipping address
            $shippingAddress = [
                'first_name' => $order['first_name'] ?? 'Customer',
                'last_name' => $order['last_name'] ?? '',
                'email' => $order['email'] ?? '',
                'phone' => $order['phone'] ?? '',
                'country' => $order['country'] ?? 'US',
                'region' => $order['state_region'] ?? '',
                'address1' => $order['address_line1'] ?? '',
                'address2' => $order['address_line2'] ?? '',
                'city' => $order['city'] ?? '',
                'zip' => $order['postal_code'] ?? ''
            ];
            
            $printifyOrderData = [
                'external_id' => 'ORDER-' . $orderId,
                'label' => $order['order_number'] ?? ('Order #' . $orderId),
                'line_items' => $lineItems,
                'shipping_method' => 1, // Standard shipping (default)
                'address_to' => $shippingAddress
            ];
            
            // Create order in Printify
            $printifyResponse = $this->api->createOrder($printifyOrderData, $this->shopId);
            $printifyOrderId = $printifyResponse['id'] ?? null;
            
            if (!$printifyOrderId) {
                throw new Exception('Failed to create Printify order - no ID returned');
            }
            
            // Save Printify order record
            if ($existingPrintifyOrder) {
                // Update existing record
                $stmt = $this->pdo->prepare('
                    UPDATE printify_orders SET
                        printify_order_id = ?,
                        status = ?,
                        metadata = ?,
                        submitted_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([
                    $printifyOrderId,
                    'pending',
                    json_encode($printifyResponse),
                    $existingPrintifyOrder['id']
                ]);
            } else {
                // Create new record
                $stmt = $this->pdo->prepare('
                    INSERT INTO printify_orders (
                        order_id, printify_order_id, printify_shop_id, status,
                        metadata, submitted_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())
                ');
                $stmt->execute([
                    $orderId,
                    $printifyOrderId,
                    $this->shopId,
                    'pending',
                    json_encode($printifyResponse)
                ]);
            }
            
            // Auto-submit to production if enabled
            if ($autoSubmit) {
                try {
                    $this->api->submitOrder($printifyOrderId, $this->shopId);
                    
                    // Update status
                    $stmt = $this->pdo->prepare('
                        UPDATE printify_orders SET
                            status = "in_production",
                            in_production_at = NOW()
                        WHERE printify_order_id = ?
                    ');
                    $stmt->execute([$printifyOrderId]);
                    
                    // Update local order notes
                    $this->updateOrderNotes($orderId, 'Submitted to Printify for production (Order ID: ' . $printifyOrderId . ')');
                    
                } catch (Exception $e) {
                    // Log error but don't fail the whole operation
                    $this->updateOrderNotes($orderId, 'Created in Printify but auto-submit failed: ' . $e->getMessage());
                    error_log('Printify auto-submit failed for order ' . $orderId . ': ' . $e->getMessage());
                }
            }
            
            return [
                'success' => true,
                'message' => 'Order submitted to Printify successfully',
                'printify_order_id' => $printifyOrderId,
                'auto_submitted' => $autoSubmit
            ];
            
        } catch (Exception $e) {
            // Log error to printify_orders table
            try {
                $stmt = $this->pdo->prepare('
                    INSERT INTO printify_orders (
                        order_id, printify_shop_id, status, submission_error
                    ) VALUES (?, ?, "error", ?)
                    ON DUPLICATE KEY UPDATE
                        submission_error = ?,
                        updated_at = NOW()
                ');
                $stmt->execute([
                    $orderId,
                    $this->shopId,
                    $e->getMessage(),
                    $e->getMessage()
                ]);
            } catch (Exception $logError) {
                error_log('Failed to log Printify submission error: ' . $logError->getMessage());
            }
            
            // Update order notes
            $this->updateOrderNotes($orderId, 'Printify submission failed: ' . $e->getMessage());
            
            throw $e;
        }
    }
    
    /**
     * Update order status from Printify webhook
     * 
     * @param string $printifyOrderId Printify order ID
     * @param array $webhookData Webhook payload
     */
    public function updateOrderStatus($printifyOrderId, $webhookData) {
        try {
            // Get local order
            $stmt = $this->pdo->prepare('
                SELECT po.*, o.id as local_order_id
                FROM printify_orders po
                JOIN orders o ON o.id = po.order_id
                WHERE po.printify_order_id = ?
            ');
            $stmt->execute([$printifyOrderId]);
            $printifyOrder = $stmt->fetch();
            
            if (!$printifyOrder) {
                throw new Exception('Printify order not found in local database');
            }
            
            $status = $webhookData['status'] ?? null;
            $updateData = ['status' => $status];
            $orderNote = null;
            
            // Handle different statuses
            switch ($status) {
                case 'in-production':
                    $updateData['in_production_at'] = 'NOW()';
                    $orderNote = 'Printify: Order is in production';
                    break;
                    
                case 'shipped':
                    $updateData['shipped_at'] = 'NOW()';
                    $updateData['tracking_number'] = $webhookData['tracking_number'] ?? null;
                    $updateData['tracking_url'] = $webhookData['tracking_url'] ?? null;
                    $updateData['carrier'] = $webhookData['carrier'] ?? null;
                    $orderNote = 'Printify: Order shipped';
                    
                    // Update local order shipping status
                    $stmt = $this->pdo->prepare('
                        UPDATE orders SET
                            shipping_status = "shipped",
                            tracking_number = ?
                        WHERE id = ?
                    ');
                    $stmt->execute([
                        $webhookData['tracking_number'] ?? null,
                        $printifyOrder['local_order_id']
                    ]);
                    break;
                    
                case 'delivered':
                    $updateData['delivered_at'] = 'NOW()';
                    $orderNote = 'Printify: Order delivered';
                    
                    // Update local order shipping status
                    $stmt = $this->pdo->prepare('
                        UPDATE orders SET shipping_status = "delivered" WHERE id = ?
                    ');
                    $stmt->execute([$printifyOrder['local_order_id']]);
                    break;
                    
                case 'cancelled':
                    $updateData['cancelled_at'] = 'NOW()';
                    $orderNote = 'Printify: Order cancelled';
                    break;
            }
            
            // Build update SQL
            $updateFields = [];
            $updateValues = [];
            foreach ($updateData as $field => $value) {
                if ($value === 'NOW()') {
                    $updateFields[] = "$field = NOW()";
                } else {
                    $updateFields[] = "$field = ?";
                    $updateValues[] = $value;
                }
            }
            $updateFields[] = "metadata = ?";
            $updateValues[] = json_encode($webhookData);
            $updateFields[] = "updated_at = NOW()";
            $updateValues[] = $printifyOrderId;
            
            $sql = 'UPDATE printify_orders SET ' . implode(', ', $updateFields) . ' WHERE printify_order_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($updateValues);
            
            // Add order note
            if ($orderNote) {
                $this->updateOrderNotes($printifyOrder['local_order_id'], $orderNote);
                
                // Add tracking info to note if available
                if (!empty($webhookData['tracking_number'])) {
                    $trackingNote = 'Tracking: ' . $webhookData['tracking_number'];
                    if (!empty($webhookData['tracking_url'])) {
                        $trackingNote .= ' (' . $webhookData['tracking_url'] . ')';
                    }
                    $this->updateOrderNotes($printifyOrder['local_order_id'], $trackingNote);
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Failed to update Printify order status: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get order status from Printify
     * 
     * @param int $orderId Local order ID
     * @return array Printify order data
     */
    public function getOrderStatus($orderId) {
        $stmt = $this->pdo->prepare('SELECT * FROM printify_orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $printifyOrder = $stmt->fetch();
        
        if (!$printifyOrder || empty($printifyOrder['printify_order_id'])) {
            return null;
        }
        
        try {
            // Fetch fresh data from Printify
            $printifyData = $this->api->getOrder($printifyOrder['printify_order_id'], $this->shopId);
            
            // Update local cache
            $stmt = $this->pdo->prepare('
                UPDATE printify_orders SET
                    status = ?,
                    metadata = ?,
                    updated_at = NOW()
                WHERE printify_order_id = ?
            ');
            $stmt->execute([
                $printifyData['status'] ?? 'unknown',
                json_encode($printifyData),
                $printifyOrder['printify_order_id']
            ]);
            
            return $printifyData;
            
        } catch (Exception $e) {
            error_log('Failed to fetch Printify order status: ' . $e->getMessage());
            return $printifyOrder; // Return cached data
        }
    }
    
    /**
     * Cancel Printify order
     * 
     * @param int $orderId Local order ID
     * @return bool Success
     */
    public function cancelOrder($orderId) {
        $stmt = $this->pdo->prepare('SELECT * FROM printify_orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $printifyOrder = $stmt->fetch();
        
        if (!$printifyOrder || empty($printifyOrder['printify_order_id'])) {
            throw new Exception('Printify order not found');
        }
        
        try {
            $this->api->cancelOrder($printifyOrder['printify_order_id'], $this->shopId);
            
            // Update local status
            $stmt = $this->pdo->prepare('
                UPDATE printify_orders SET
                    status = "cancelled",
                    cancelled_at = NOW()
                WHERE printify_order_id = ?
            ');
            $stmt->execute([$printifyOrder['printify_order_id']]);
            
            $this->updateOrderNotes($orderId, 'Printify order cancelled');
            
            return true;
            
        } catch (Exception $e) {
            error_log('Failed to cancel Printify order: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if order contains Printify products
     * 
     * @param int $orderId Local order ID
     * @return bool Has Printify products
     */
    public function orderHasPrintifyProducts($orderId) {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) as count
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
            AND p.fulfillment_method = "printify"
            AND p.printify_product_id IS NOT NULL
        ');
        $stmt->execute([$orderId]);
        $result = $stmt->fetch();
        
        return ($result['count'] ?? 0) > 0;
    }
    
    /**
     * Update order notes
     */
    private function updateOrderNotes($orderId, $note) {
        try {
            $stmt = $this->pdo->prepare('SELECT notes FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            $existingNotes = $order['notes'] ?? '';
            $timestamp = date('Y-m-d H:i:s');
            $newNote = "[{$timestamp}] {$note}";
            
            $updatedNotes = trim($existingNotes) !== '' 
                ? $existingNotes . "\n" . $newNote 
                : $newNote;
            
            $stmt = $this->pdo->prepare('UPDATE orders SET notes = ? WHERE id = ?');
            $stmt->execute([$updatedNotes, $orderId]);
            
        } catch (Exception $e) {
            error_log('Failed to update order notes: ' . $e->getMessage());
        }
    }
}

