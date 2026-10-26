<?php
/**
 * Printify Product Sync Manager
 * 
 * Handles bidirectional sync between local products and Printify
 * - Import products from Printify
 * - Export products to Printify
 * - SKU-based and ID-based mapping
 * - Inventory synchronization
 */

require_once __DIR__ . '/printify-api.php';

class PrintifySync {
    
    private $pdo;
    private $api;
    private $shopId;
    private $userId;
    
    public function __construct($shopId = null, $userId = null) {
        $this->pdo = db();
        
        // Load shop ID from config if not provided
        if ($shopId === null) {
            $config = site_read_config();
            $shopId = $config['printify_shop_id'] ?? null;
        }
        
        if (empty($shopId)) {
            throw new Exception('Printify shop ID is required for sync operations');
        }
        
        $this->shopId = $shopId;
        $this->userId = $userId ?? current_user_id();
        $this->api = new PrintifyAPI(null, $shopId);
    }
    
    // ========================================================================
    // IMPORT FROM PRINTIFY (Printify → Product)
    // ========================================================================
    
    /**
     * Import all products from Printify
     * 
     * @param bool $autoCreateProducts Create local products if no SKU match found
     * @return array Sync results
     */
    public function importAllProducts($autoCreateProducts = false) {
        $logId = $this->startSyncLog('import', 'product', 'all');
        
        try {
            $allProducts = [];
            $page = 1;
            $hasMore = true;
            
            // Fetch all pages
            while ($hasMore) {
                $response = $this->api->getProducts($this->shopId, $page, 50);
                $products = $response['data'] ?? [];
                
                if (empty($products)) {
                    $hasMore = false;
                } else {
                    $allProducts = array_merge($allProducts, $products);
                    $page++;
                    
                    // Safety limit
                    if ($page > 100) {
                        break;
                    }
                }
            }
            
            $results = [
                'total' => count($allProducts),
                'created' => 0,
                'updated' => 0,
                'mapped' => 0,
                'skipped' => 0,
                'errors' => []
            ];
            
            foreach ($allProducts as $printifyProduct) {
                try {
                    $result = $this->importSingleProduct($printifyProduct, $autoCreateProducts);
                    
                    if ($result['action'] === 'created') {
                        $results['created']++;
                    } elseif ($result['action'] === 'updated') {
                        $results['updated']++;
                    } elseif ($result['action'] === 'mapped') {
                        $results['mapped']++;
                    } else {
                        $results['skipped']++;
                    }
                } catch (Exception $e) {
                    $results['errors'][] = [
                        'product_id' => $printifyProduct['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->completeSyncLog($logId, 'success', $results['total'], count($results['errors']), $results);
            
            return $results;
            
        } catch (Exception $e) {
            $this->completeSyncLog($logId, 'error', 0, 0, ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Import single product from Printify
     * 
     * @param array $printifyProduct Printify product data
     * @param bool $autoCreateProduct Create local product if no match found
     * @return array Action result
     */
    public function importSingleProduct($printifyProduct, $autoCreateProduct = false) {
        $printifyProductId = $printifyProduct['id'];
        $title = $printifyProduct['title'] ?? 'Untitled Product';
        $description = $printifyProduct['description'] ?? '';
        
        // Extract SKU from first variant (if available)
        $variants = $printifyProduct['variants'] ?? [];
        $firstVariant = !empty($variants) ? $variants[0] : null;
        $sku = $firstVariant['sku'] ?? null;
        
        // Check if this Printify product already exists in our cache
        $stmt = $this->pdo->prepare('SELECT * FROM printify_products WHERE printify_product_id = ?');
        $stmt->execute([$printifyProductId]);
        $existingPrintifyProduct = $stmt->fetch();
        
        if ($existingPrintifyProduct) {
            // Update existing Printify product cache
            $stmt = $this->pdo->prepare('
                UPDATE printify_products SET
                    title = ?,
                    description = ?,
                    tags = ?,
                    variants = ?,
                    images = ?,
                    is_published = ?,
                    is_locked = ?,
                    raw_data = ?,
                    last_synced_at = NOW(),
                    updated_at = NOW()
                WHERE printify_product_id = ?
            ');
            
            $stmt->execute([
                $title,
                $description,
                json_encode($printifyProduct['tags'] ?? []),
                json_encode($variants),
                json_encode($printifyProduct['images'] ?? []),
                $printifyProduct['is_published'] ?? 0,
                $printifyProduct['is_locked'] ?? 0,
                json_encode($printifyProduct),
                $printifyProductId
            ]);
            
            $printifyDbId = $existingPrintifyProduct['id'];
            $action = 'updated';
        } else {
            // Create new Printify product cache
            $stmt = $this->pdo->prepare('
                INSERT INTO printify_products (
                    printify_product_id, printify_shop_id, printify_blueprint_id, 
                    printify_print_provider_id, title, description, tags, variants, 
                    images, is_published, is_locked, raw_data, last_synced_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $stmt->execute([
                $printifyProductId,
                $this->shopId,
                $printifyProduct['blueprint_id'] ?? null,
                $printifyProduct['print_provider_id'] ?? null,
                $title,
                $description,
                json_encode($printifyProduct['tags'] ?? []),
                json_encode($variants),
                json_encode($printifyProduct['images'] ?? []),
                $printifyProduct['is_published'] ?? 0,
                $printifyProduct['is_locked'] ?? 0,
                json_encode($printifyProduct)
            ]);
            
            $printifyDbId = $this->pdo->lastInsertId();
            $action = 'created';
        }
        
        // Try to match with local product
        $localProduct = null;
        
        // First, check if already mapped
        $stmt = $this->pdo->prepare('SELECT product_id FROM printify_product_mapping WHERE printify_product_id = ?');
        $stmt->execute([$printifyDbId]);
        $existingMapping = $stmt->fetch();
        
        if ($existingMapping) {
            // Already mapped
            $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$existingMapping['product_id']]);
            $localProduct = $stmt->fetch();
        } elseif ($sku) {
            // Try to match by SKU
            $stmt = $this->pdo->prepare('SELECT * FROM products WHERE sku = ? LIMIT 1');
            $stmt->execute([$sku]);
            $localProduct = $stmt->fetch();
        }
        
        // Create mapping if local product found
        if ($localProduct) {
            $this->createOrUpdateMapping($localProduct['id'], $printifyDbId, $firstVariant['id'] ?? null, $sku);
            
            // Update local product with Printify IDs
            $stmt = $this->pdo->prepare('
                UPDATE products SET
                    printify_product_id = ?,
                    printify_variant_id = ?,
                    printify_last_synced = NOW(),
                    fulfillment_method = "printify"
                WHERE id = ?
            ');
            $stmt->execute([
                $printifyProductId,
                $firstVariant['id'] ?? null,
                $localProduct['id']
            ]);
            
            return ['action' => 'mapped', 'local_product_id' => $localProduct['id']];
        } elseif ($autoCreateProduct && $sku) {
            // Auto-create local product
            $localProductId = $this->createLocalProductFromPrintify($printifyProduct, $printifyDbId);
            return ['action' => 'created', 'local_product_id' => $localProductId];
        }
        
        return ['action' => $action, 'printify_db_id' => $printifyDbId];
    }
    
    /**
     * Create local product from Printify data
     */
    private function createLocalProductFromPrintify($printifyProduct, $printifyDbId) {
        $variants = $printifyProduct['variants'] ?? [];
        $firstVariant = !empty($variants) ? $variants[0] : null;
        $sku = $firstVariant['sku'] ?? 'PRINT-' . substr($printifyProduct['id'], 0, 10);
        
        // Extract pricing from first variant
        $price = isset($firstVariant['price']) ? ($firstVariant['price'] / 100) : 0; // Convert cents to dollars
        
        $stmt = $this->pdo->prepare('
            INSERT INTO products (
                sku, name, description, wholesale_price, msrp, stock_quantity, active,
                printify_product_id, printify_variant_id, printify_sync_enabled,
                fulfillment_method, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        
        $stmt->execute([
            $sku,
            $printifyProduct['title'],
            $printifyProduct['description'] ?? '',
            $price,
            $price * 2.5, // Default 2.5x markup
            100, // Default stock
            1, // Active
            $printifyProduct['id'],
            $firstVariant['id'] ?? null,
            1, // Enable sync
            'printify'
        ]);
        
        $productId = $this->pdo->lastInsertId();
        
        // Create mapping
        $this->createOrUpdateMapping($productId, $printifyDbId, $firstVariant['id'] ?? null, null);
        
        return $productId;
    }
    
    // ========================================================================
    // EXPORT TO PRINTIFY (Product → Printify)
    // ========================================================================
    
    /**
     * Export all enabled products to Printify
     * 
     * @return array Sync results
     */
    public function exportAllProducts() {
        $logId = $this->startSyncLog('export', 'product', 'all');
        
        try {
            // Get all products with sync enabled
            $stmt = $this->pdo->query('
                SELECT * FROM products 
                WHERE printify_sync_enabled = 1 
                AND active = 1
            ');
            $products = $stmt->fetchAll();
            
            $results = [
                'total' => count($products),
                'created' => 0,
                'updated' => 0,
                'published' => 0,
                'errors' => []
            ];
            
            foreach ($products as $product) {
                try {
                    $result = $this->exportSingleProduct($product['id']);
                    
                    if ($result['action'] === 'created') {
                        $results['created']++;
                    } elseif ($result['action'] === 'updated') {
                        $results['updated']++;
                    }
                    
                    if ($result['published'] ?? false) {
                        $results['published']++;
                    }
                } catch (Exception $e) {
                    $results['errors'][] = [
                        'product_id' => $product['id'],
                        'sku' => $product['sku'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->completeSyncLog($logId, 'success', $results['total'], count($results['errors']), $results);
            
            return $results;
            
        } catch (Exception $e) {
            $this->completeSyncLog($logId, 'error', 0, 0, ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Export single product to Printify
     * 
     * @param int $productId Local product ID
     * @return array Action result
     */
    public function exportSingleProduct($productId) {
        // This is a placeholder - actual implementation would require:
        // 1. Blueprint/variant selection UI
        // 2. Image upload to Printify
        // 3. Product data mapping
        
        // For now, just update existing Printify products
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        if (empty($product['printify_product_id'])) {
            throw new Exception('Product not linked to Printify - manual setup required');
        }
        
        // Update existing Printify product
        $updateData = [
            'title' => $product['name'],
            'description' => $product['description'] ?? ''
        ];
        
        $this->api->updateProduct($product['printify_product_id'], $updateData, $this->shopId);
        
        // Update sync timestamp
        $stmt = $this->pdo->prepare('UPDATE products SET printify_last_synced = NOW() WHERE id = ?');
        $stmt->execute([$productId]);
        
        return ['action' => 'updated', 'printify_product_id' => $product['printify_product_id']];
    }
    
    // ========================================================================
    // MAPPING MANAGEMENT
    // ========================================================================
    
    /**
     * Create or update product mapping
     */
    private function createOrUpdateMapping($localProductId, $printifyDbId, $variantId = null, $skuOverride = null) {
        $stmt = $this->pdo->prepare('
            SELECT id FROM printify_product_mapping 
            WHERE product_id = ? AND printify_product_id = ?
        ');
        $stmt->execute([$localProductId, $printifyDbId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing mapping
            $stmt = $this->pdo->prepare('
                UPDATE printify_product_mapping SET
                    printify_variant_id = ?,
                    sku_override = ?,
                    last_synced_at = NOW(),
                    sync_status = "success"
                WHERE id = ?
            ');
            $stmt->execute([$variantId, $skuOverride, $existing['id']]);
        } else {
            // Create new mapping
            $stmt = $this->pdo->prepare('
                INSERT INTO printify_product_mapping (
                    product_id, printify_product_id, printify_variant_id,
                    sku_override, sync_enabled, last_synced_at, sync_status
                ) VALUES (?, ?, ?, ?, 1, NOW(), "success")
            ');
            $stmt->execute([$localProductId, $printifyDbId, $variantId, $skuOverride]);
        }
    }
    
    /**
     * Validate SKU mappings for conflicts
     */
    public function validateSkuMapping() {
        // Check for duplicate SKUs in local products
        $stmt = $this->pdo->query('
            SELECT sku, COUNT(*) as count 
            FROM products 
            WHERE printify_sync_enabled = 1 
            GROUP BY sku 
            HAVING count > 1
        ');
        $duplicates = $stmt->fetchAll();
        
        $issues = [];
        
        foreach ($duplicates as $dup) {
            $issues[] = [
                'type' => 'duplicate_sku',
                'sku' => $dup['sku'],
                'count' => $dup['count'],
                'message' => 'SKU "' . $dup['sku'] . '" is used by ' . $dup['count'] . ' products'
            ];
        }
        
        return $issues;
    }
    
    /**
     * Reconcile inventory between local and Printify
     */
    public function reconcileInventory() {
        // This would fetch inventory from Printify and compare with local
        // For now, just a placeholder
        return [
            'total_checked' => 0,
            'discrepancies' => [],
            'updated' => 0
        ];
    }
    
    // ========================================================================
    // SYNC LOGGING
    // ========================================================================
    
    /**
     * Start a sync operation log
     */
    private function startSyncLog($actionType, $resourceType, $resourceId) {
        $stmt = $this->pdo->prepare('
            INSERT INTO printify_sync_log (
                action_type, resource_type, resource_id, direction, status,
                user_id, started_at
            ) VALUES (?, ?, ?, "import", "running", ?, NOW())
        ');
        $stmt->execute([$actionType, $resourceType, $resourceId, $this->userId]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Complete a sync operation log
     */
    private function completeSyncLog($logId, $status, $recordsProcessed, $recordsFailed, $details = null) {
        $stmt = $this->pdo->prepare('
            UPDATE printify_sync_log SET
                status = ?,
                records_processed = ?,
                records_failed = ?,
                details = ?,
                completed_at = NOW()
            WHERE id = ?
        ');
        
        $stmt->execute([
            $status,
            $recordsProcessed,
            $recordsFailed,
            $details ? json_encode($details) : null,
            $logId
        ]);
    }
    
    // ========================================================================
    // IMAGE SYNC
    // ========================================================================
    
    /**
     * Upload product images to Printify
     * 
     * @param int $productId Local product ID
     * @return array Upload results
     */
    public function uploadProductImages($productId) {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        $results = [
            'uploaded' => 0,
            'failed' => 0,
            'upload_ids' => [],
            'errors' => []
        ];
        
        // Get local image URL
        $imageUrl = $product['image_url'];
        
        if (empty($imageUrl)) {
            throw new Exception('Product has no image to upload');
        }
        
        // Convert relative URL to absolute
        if (strpos($imageUrl, 'http') !== 0) {
            $imageUrl = site_origin() . $imageUrl;
        }
        
        try {
            // Upload to Printify
            $filename = basename(parse_url($imageUrl, PHP_URL_PATH));
            $uploadResult = $this->api->uploadImageByUrl($imageUrl, $filename);
            
            $printifyUploadId = $uploadResult['id'] ?? null;
            $printifyImageUrl = $uploadResult['file_name'] ?? null;
            
            if (!$printifyUploadId) {
                throw new Exception('Printify did not return upload ID');
            }
            
            // Save to database
            $stmt = $this->pdo->prepare('
                INSERT INTO printify_image_uploads (
                    product_id, printify_upload_id, local_image_url,
                    printify_image_url, filename, upload_status, 
                    is_primary, uploaded_at
                ) VALUES (?, ?, ?, ?, ?, "success", 1, NOW())
                ON DUPLICATE KEY UPDATE
                    upload_status = "success",
                    printify_image_url = VALUES(printify_image_url),
                    uploaded_at = NOW()
            ');
            
            $stmt->execute([
                $productId,
                $printifyUploadId,
                $product['image_url'],
                $printifyImageUrl,
                $filename
            ]);
            
            // Mark product images as synced
            $stmt = $this->pdo->prepare('UPDATE products SET printify_images_synced = 1 WHERE id = ?');
            $stmt->execute([$productId]);
            
            $results['uploaded']++;
            $results['upload_ids'][] = $printifyUploadId;
            
        } catch (Exception $e) {
            $results['failed']++;
            $results['errors'][] = $e->getMessage();
            
            // Log error to database
            try {
                $stmt = $this->pdo->prepare('
                    INSERT INTO printify_image_uploads (
                        product_id, local_image_url, filename,
                        upload_status, upload_error
                    ) VALUES (?, ?, ?, "error", ?)
                ');
                $stmt->execute([
                    $productId,
                    $product['image_url'],
                    basename($imageUrl),
                    $e->getMessage()
                ]);
            } catch (Exception $logError) {}
        }
        
        return $results;
    }
    
    /**
     * Download images from Printify product
     * 
     * @param string $printifyProductId Printify product ID
     * @return array Download results
     */
    public function downloadProductImages($printifyProductId) {
        // Get Printify product
        $stmt = $this->pdo->prepare('SELECT * FROM printify_products WHERE printify_product_id = ?');
        $stmt->execute([$printifyProductId]);
        $printifyProduct = $stmt->fetch();
        
        if (!$printifyProduct) {
            throw new Exception('Printify product not found in cache');
        }
        
        $images = json_decode($printifyProduct['images'], true) ?? [];
        
        if (empty($images)) {
            return ['message' => 'No images to download'];
        }
        
        $results = [
            'downloaded' => 0,
            'image_urls' => []
        ];
        
        foreach ($images as $index => $image) {
            $imageUrl = $image['src'] ?? null;
            
            if ($imageUrl) {
                $results['image_urls'][] = $imageUrl;
                $results['downloaded']++;
                
                // Update primary image in printify_products
                if ($index === 0) {
                    $stmt = $this->pdo->prepare('
                        UPDATE printify_products SET primary_image_url = ? WHERE printify_product_id = ?
                    ');
                    $stmt->execute([$imageUrl, $printifyProductId]);
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Sync images for all products with sync enabled
     * 
     * @return array Sync results
     */
    public function syncAllProductImages() {
        $stmt = $this->pdo->query('
            SELECT id FROM products 
            WHERE printify_sync_enabled = 1 
            AND printify_images_synced = 0
            AND image_url IS NOT NULL
            AND image_url != ""
            LIMIT 50
        ');
        $products = $stmt->fetchAll();
        
        $results = [
            'total' => count($products),
            'uploaded' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($products as $product) {
            try {
                $uploadResult = $this->uploadProductImages($product['id']);
                $results['uploaded'] += $uploadResult['uploaded'];
                $results['failed'] += $uploadResult['failed'];
                
                if (!empty($uploadResult['errors'])) {
                    $results['errors'] = array_merge($results['errors'], $uploadResult['errors']);
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'product_id' => $product['id'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    // ========================================================================
    // AUTO-PUBLISH TO STORES
    // ========================================================================
    
    /**
     * Publish product to connected stores
     * 
     * @param int $productId Local product ID
     * @param array $storeIds Specific store IDs (empty = all enabled stores)
     * @return array Publish results
     */
    public function publishProductToStores($productId, $storeIds = []) {
        // Get product
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        
        if (!$product || empty($product['printify_product_id'])) {
            throw new Exception('Product not linked to Printify');
        }
        
        $printifyProductId = $product['printify_product_id'];
        
        // Get enabled stores
        if (empty($storeIds)) {
            $stmt = $this->pdo->query('SELECT * FROM printify_publish_stores WHERE is_enabled = 1 AND auto_publish = 1');
        } else {
            $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
            $stmt = $this->pdo->prepare("SELECT * FROM printify_publish_stores WHERE store_id IN ($placeholders)");
            $stmt->execute($storeIds);
        }
        $stores = $stmt->fetchAll();
        
        if (empty($stores)) {
            return ['message' => 'No stores configured for publishing'];
        }
        
        $results = [
            'total' => count($stores),
            'published' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($stores as $store) {
            try {
                // Prepare publish data
                $publishData = [
                    'title' => true, // Publish with Printify title
                    'description' => true,
                    'images' => true,
                    'variants' => true,
                    'tags' => []
                ];
                
                // Call Printify API to publish
                $publishResult = $this->api->publishProduct($printifyProductId, $publishData, $this->shopId);
                
                // Log publish attempt
                $stmt = $this->pdo->prepare('
                    INSERT INTO printify_publish_log (
                        printify_product_id, product_id, store_id, store_name,
                        publish_status, external_id, metadata, published_at
                    ) VALUES (?, ?, ?, ?, "succeeded", ?, ?, NOW())
                ');
                
                $stmt->execute([
                    $printifyProductId,
                    $productId,
                    $store['store_id'],
                    $store['store_name'],
                    $publishResult['external']['id'] ?? null,
                    json_encode($publishResult)
                ]);
                
                // Update product publish status
                $stmt = $this->pdo->prepare('
                    UPDATE products SET
                        printify_publish_status = "published",
                        printify_published_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$productId]);
                
                $results['published']++;
                
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'store' => $store['store_name'],
                    'error' => $e->getMessage()
                ];
                
                // Log failed publish
                try {
                    $stmt = $this->pdo->prepare('
                        INSERT INTO printify_publish_log (
                            printify_product_id, product_id, store_id, store_name,
                            publish_status, publish_error
                        ) VALUES (?, ?, ?, ?, "failed", ?)
                    ');
                    $stmt->execute([
                        $printifyProductId,
                        $productId,
                        $store['store_id'],
                        $store['store_name'],
                        $e->getMessage()
                    ]);
                } catch (Exception $logError) {}
            }
        }
        
        return $results;
    }
    
    /**
     * Auto-publish all synced products to enabled stores
     * 
     * @return array Publish results
     */
    public function autoPublishAllProducts() {
        // Get products that are synced but not published
        $stmt = $this->pdo->query('
            SELECT id FROM products 
            WHERE printify_sync_enabled = 1 
            AND printify_product_id IS NOT NULL
            AND (printify_publish_status IS NULL OR printify_publish_status != "published")
            LIMIT 50
        ');
        $products = $stmt->fetchAll();
        
        $results = [
            'total' => count($products),
            'published' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($products as $product) {
            try {
                $publishResult = $this->publishProductToStores($product['id']);
                $results['published'] += $publishResult['published'];
                $results['failed'] += $publishResult['failed'];
                
                if (!empty($publishResult['errors'])) {
                    $results['errors'] = array_merge($results['errors'], $publishResult['errors']);
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'product_id' => $product['id'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
}

