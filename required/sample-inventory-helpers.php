<?php
/**
 * Sample Inventory Management Helpers
 * 
 * Provides functions for managing sample inventory:
 * - Reserving inventory for pending requests
 * - Releasing inventory from rejected requests
 * - Deducting inventory for shipped samples
 * - Checking availability
 * - Logging movements
 * - Low stock alerts
 */

if (!function_exists('get_sample_inventory')) {
    /**
     * Get current inventory for a sample product
     * 
     * @param PDO $pdo Database connection
     * @param int $productId Product ID
     * @return array|null Inventory record or null if not found
     */
    function get_sample_inventory(PDO $pdo, int $productId): ?array
    {
        $stmt = $pdo->prepare('
            SELECT * FROM sample_inventory WHERE product_id = ? LIMIT 1
        ');
        $stmt->execute([$productId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}

if (!function_exists('check_sample_availability')) {
    /**
     * Check if requested quantity is available
     * 
     * @param PDO $pdo Database connection
     * @param int $productId Product ID
     * @param int $quantity Requested quantity
     * @return bool True if available, false otherwise
     */
    function check_sample_availability(PDO $pdo, int $productId, int $quantity): bool
    {
        $inventory = get_sample_inventory($pdo, $productId);
        if (!$inventory) {
            return false;
        }
        
        $available = (int)$inventory['quantity_available'];
        return $available >= $quantity;
    }
}

if (!function_exists('reserve_sample_inventory')) {
    /**
     * Reserve inventory for a pending sample request
     * 
     * @param PDO $pdo Database connection
     * @param int $productId Product ID
     * @param int $quantity Quantity to reserve
     * @param int $requestId Sample request ID
     * @param int|null $userId User performing the reservation
     * @return bool True on success, false on failure
     * @throws Exception If inventory not available
     */
    function reserve_sample_inventory(PDO $pdo, int $productId, int $quantity, int $requestId, ?int $userId = null): bool
    {
        if (!check_sample_availability($pdo, $productId, $quantity)) {
            throw new Exception('Insufficient inventory available for reservation');
        }
        
        // Check if already in transaction (nested transaction handling)
        $needsTransaction = !$pdo->inTransaction();
        
        if ($needsTransaction) {
            $pdo->beginTransaction();
        }
        
        try {
            // Update inventory: decrease available, increase reserved
            $stmt = $pdo->prepare('
                UPDATE sample_inventory
                SET quantity_available = quantity_available - ?,
                    quantity_reserved = quantity_reserved + ?,
                    updated_at = NOW()
                WHERE product_id = ?
                AND quantity_available >= ?
            ');
            $stmt->execute([$quantity, $quantity, $productId, $quantity]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Failed to reserve inventory - concurrent modification');
            }
            
            // Get quantity after reservation
            $inventory = get_sample_inventory($pdo, $productId);
            $quantityAfter = (int)$inventory['quantity_available'];
            
            // Log the movement
            log_inventory_movement(
                $pdo,
                $productId,
                'reservation',
                -$quantity,
                $quantityAfter,
                'sample_request',
                $requestId,
                "Reserved $quantity units for sample request #$requestId",
                $userId
            );
            
            if ($needsTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($needsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('release_sample_inventory')) {
    /**
     * Release reserved inventory (e.g., when request is rejected)
     * 
     * @param PDO $pdo Database connection
     * @param int $productId Product ID
     * @param int $quantity Quantity to release
     * @param int $requestId Sample request ID
     * @param int|null $userId User performing the release
     * @return bool True on success
     */
    function release_sample_inventory(PDO $pdo, int $productId, int $quantity, int $requestId, ?int $userId = null): bool
    {
        $needsTransaction = !$pdo->inTransaction();
        
        if ($needsTransaction) {
            $pdo->beginTransaction();
        }
        
        try {
            // Update inventory: increase available, decrease reserved
            $stmt = $pdo->prepare('
                UPDATE sample_inventory
                SET quantity_available = quantity_available + ?,
                    quantity_reserved = quantity_reserved - ?,
                    updated_at = NOW()
                WHERE product_id = ?
                AND quantity_reserved >= ?
            ');
            $stmt->execute([$quantity, $quantity, $productId, $quantity]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Failed to release inventory - invalid reserved quantity');
            }
            
            // Get quantity after release
            $inventory = get_sample_inventory($pdo, $productId);
            $quantityAfter = (int)$inventory['quantity_available'];
            
            // Log the movement
            log_inventory_movement(
                $pdo,
                $productId,
                'release',
                $quantity,
                $quantityAfter,
                'sample_request',
                $requestId,
                "Released $quantity units from rejected/cancelled request #$requestId",
                $userId
            );
            
            if ($needsTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($needsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('deduct_sample_inventory')) {
    /**
     * Deduct inventory when sample is shipped (approved request)
     * 
     * @param PDO $pdo Database connection
     * @param int $productId Product ID
     * @param int $quantity Quantity to deduct
     * @param int $requestId Sample request ID
     * @param int|null $userId User performing the deduction
     * @return bool True on success
     */
    function deduct_sample_inventory(PDO $pdo, int $productId, int $quantity, int $requestId, ?int $userId = null): bool
    {
        $needsTransaction = !$pdo->inTransaction();
        
        if ($needsTransaction) {
            $pdo->beginTransaction();
        }
        
        try {
            // Update inventory: decrease reserved, increase shipped
            $stmt = $pdo->prepare('
                UPDATE sample_inventory
                SET quantity_reserved = quantity_reserved - ?,
                    quantity_shipped = quantity_shipped + ?,
                    updated_at = NOW()
                WHERE product_id = ?
                AND quantity_reserved >= ?
            ');
            $stmt->execute([$quantity, $quantity, $productId, $quantity]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Failed to deduct inventory - insufficient reserved quantity');
            }
            
            // Get quantity after shipment
            $inventory = get_sample_inventory($pdo, $productId);
            $quantityAfter = (int)$inventory['quantity_available'];
            
            // Log the movement
            log_inventory_movement(
                $pdo,
                $productId,
                'shipment',
                -$quantity,
                $quantityAfter,
                'sample_request',
                $requestId,
                "Shipped $quantity units for approved request #$requestId",
                $userId
            );
            
            // Check for low stock and alert if needed
            alert_low_stock($pdo, $productId);
            
            if ($needsTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($needsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('restock_sample_inventory')) {
    /**
     * Add inventory (restock)
     * 
     * @param PDO $pdo Database connection
     * @param int $productId Product ID
     * @param int $quantity Quantity to add
     * @param string $reason Reason for restock
     * @param int|null $userId User performing the restock
     * @return bool True on success
     */
    function restock_sample_inventory(PDO $pdo, int $productId, int $quantity, string $reason, ?int $userId = null): bool
    {
        $needsTransaction = !$pdo->inTransaction();
        
        if ($needsTransaction) {
            $pdo->beginTransaction();
        }
        
        try {
            // Update inventory: increase available
            $stmt = $pdo->prepare('
                UPDATE sample_inventory
                SET quantity_available = quantity_available + ?,
                    last_restocked_at = NOW(),
                    updated_at = NOW()
                WHERE product_id = ?
            ');
            $stmt->execute([$quantity, $productId]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Failed to restock inventory - product not found');
            }
            
            // Get quantity after restock
            $inventory = get_sample_inventory($pdo, $productId);
            $quantityAfter = (int)$inventory['quantity_available'];
            
            // Log the movement
            log_inventory_movement(
                $pdo,
                $productId,
                'restock',
                $quantity,
                $quantityAfter,
                'manual_adjustment',
                null,
                $reason,
                $userId
            );
            
            if ($needsTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($needsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('log_inventory_movement')) {
    /**
     * Log an inventory movement for audit trail
     * 
     * @param PDO $pdo Database connection
     * @param int $productId Product ID
     * @param string $movementType Type: restock, reservation, release, shipment, adjustment
     * @param int $quantityChange Change in quantity (positive or negative)
     * @param int $quantityAfter Quantity after movement
     * @param string|null $referenceType Reference type (sample_request, manual_adjustment)
     * @param int|null $referenceId Reference ID
     * @param string|null $reason Description/reason
     * @param int|null $userId User who performed the movement
     * @return bool True on success
     */
    function log_inventory_movement(
        PDO $pdo,
        int $productId,
        string $movementType,
        int $quantityChange,
        int $quantityAfter,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?int $userId = null
    ): bool {
        $stmt = $pdo->prepare('
            INSERT INTO sample_inventory_movements (
                product_id, movement_type, quantity_change, quantity_after,
                reference_type, reference_id, reason, performed_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        return $stmt->execute([
            $productId,
            $movementType,
            $quantityChange,
            $quantityAfter,
            $referenceType,
            $referenceId,
            $reason,
            $userId
        ]);
    }
}

if (!function_exists('alert_low_stock')) {
    /**
     * Check if inventory is below threshold and send alert
     * 
     * @param PDO $pdo Database connection
     * @param int $productId Product ID
     * @return bool True if alert was sent
     */
    function alert_low_stock(PDO $pdo, int $productId): bool
    {
        $inventory = get_sample_inventory($pdo, $productId);
        if (!$inventory) {
            return false;
        }
        
        $available = (int)$inventory['quantity_available'];
        $threshold = (int)$inventory['low_stock_threshold'];
        
        if ($available <= $threshold) {
            // Get product details
            $stmt = $pdo->prepare('SELECT sku, name FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                $subject = "Low Stock Alert: {$product['name']}";
                $message = "Sample inventory for {$product['name']} (SKU: {$product['sku']}) is running low.\n\n";
                $message .= "Current available: $available units\n";
                $message .= "Threshold: $threshold units\n\n";
                $message .= "Please restock soon to avoid delays in sample requests.";
                
                // Send email notification to admin
                // Note: Implement email sending based on your system
                // For now, log to error log as a notification method
                error_log("LOW STOCK ALERT: " . $product['name'] . " - Only $available units remaining");
                
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('get_all_sample_inventory')) {
    /**
     * Get inventory status for all sample products
     * 
     * @param PDO $pdo Database connection
     * @return array Array of inventory records with product details
     */
    function get_all_sample_inventory(PDO $pdo): array
    {
        $stmt = $pdo->query('
            SELECT 
                si.*,
                p.sku,
                p.name AS product_name,
                p.is_sample,
                (si.quantity_available - si.quantity_reserved) AS truly_available,
                CASE 
                    WHEN si.quantity_available <= si.low_stock_threshold THEN 1
                    ELSE 0
                END AS is_low_stock
            FROM sample_inventory si
            JOIN products p ON p.id = si.product_id
            WHERE p.is_sample = 1 AND p.active = 1
            ORDER BY p.name
        ');
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_inventory_movements')) {
    /**
     * Get inventory movement history
     * 
     * @param PDO $pdo Database connection
     * @param int|null $productId Optional: Filter by product
     * @param int $limit Number of records to return
     * @return array Array of movement records
     */
    function get_inventory_movements(PDO $pdo, ?int $productId = null, int $limit = 50): array
    {
        $where = $productId ? 'WHERE sim.product_id = ?' : '';
        
        $stmt = $pdo->prepare("
            SELECT 
                sim.*,
                p.sku,
                p.name AS product_name,
                u.first_name,
                u.last_name,
                CONCAT(u.first_name, ' ', u.last_name) AS performed_by_name
            FROM sample_inventory_movements sim
            JOIN products p ON p.id = sim.product_id
            LEFT JOIN users u ON u.id = sim.performed_by
            $where
            ORDER BY sim.created_at DESC
            LIMIT ?
        ");
        
        if ($productId) {
            $stmt->execute([$productId, $limit]);
        } else {
            $stmt->execute([$limit]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

