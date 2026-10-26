<?php
/**
 * Printify API Wrapper
 * 
 * Complete PHP client for Printify REST API
 * Documentation: https://developers.printify.com/
 * 
 * Features:
 * - Rate limiting (100 req/min catalog, 600/min global)
 * - Automatic retry with exponential backoff
 * - Comprehensive error handling
 * - Request/response logging
 */

class PrintifyAPI {
    
    private $apiKey;
    private $baseUrl = 'https://api.printify.com/v1';
    private $shopId = null;
    
    // Rate limiting
    private $catalogRateLimit = 100; // requests per minute
    private $globalRateLimit = 600;  // requests per minute
    private $catalogRequestTimes = [];
    private $globalRequestTimes = [];
    
    // Retry settings
    private $maxRetries = 3;
    private $retryDelay = 1000; // milliseconds
    
    /**
     * Initialize Printify API client
     * 
     * @param string $apiKey Printify API key (JWT token)
     * @param string|null $shopId Default shop ID (optional)
     */
    public function __construct($apiKey = null, $shopId = null) {
        // Load API key from config if not provided
        if ($apiKey === null) {
            $apiKey = $this->getApiKeyFromConfig();
        }
        
        if (empty($apiKey)) {
            throw new Exception('Printify API key is required');
        }
        
        $this->apiKey = $apiKey;
        $this->shopId = $shopId;
    }
    
    /**
     * Get API key from site configuration
     */
    private function getApiKeyFromConfig() {
        try {
            if (function_exists('site_read_config')) {
                $config = site_read_config();
                return $config['printify_api_key'] ?? null;
            }
        } catch (Throwable $e) {
            error_log('PrintifyAPI: Failed to load API key from config - ' . $e->getMessage());
        }
        return null;
    }
    
    /**
     * Set default shop ID
     */
    public function setShopId($shopId) {
        $this->shopId = $shopId;
        return $this;
    }
    
    /**
     * Get default shop ID
     */
    public function getShopId() {
        return $this->shopId;
    }
    
    // ========================================================================
    // SHOPS API
    // ========================================================================
    
    /**
     * Get all shops
     * GET /v1/shops.json
     */
    public function getShops() {
        return $this->request('GET', '/shops.json');
    }
    
    /**
     * Get specific shop by ID
     * GET /v1/shops/{shop_id}.json
     */
    public function getShopById($shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('GET', "/shops/{$shopId}.json");
    }
    
    /**
     * Disconnect shop
     * DELETE /v1/shops/{shop_id}/connection.json
     */
    public function disconnectShop($shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('DELETE', "/shops/{$shopId}/connection.json");
    }
    
    // ========================================================================
    // CATALOG API (Rate limited to 100 req/min)
    // ========================================================================
    
    /**
     * Get all blueprints (product types)
     * GET /v1/catalog/blueprints.json
     */
    public function getBlueprints() {
        return $this->request('GET', '/catalog/blueprints.json', null, true);
    }
    
    /**
     * Get specific blueprint
     * GET /v1/catalog/blueprints/{blueprint_id}.json
     */
    public function getBlueprintById($blueprintId) {
        return $this->request('GET', "/catalog/blueprints/{$blueprintId}.json", null, true);
    }
    
    /**
     * Get print providers for a blueprint
     * GET /v1/catalog/blueprints/{blueprint_id}/print_providers.json
     */
    public function getPrintProviders($blueprintId) {
        return $this->request('GET', "/catalog/blueprints/{$blueprintId}/print_providers.json", null, true);
    }
    
    /**
     * Get specific print provider
     * GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}.json
     */
    public function getPrintProviderById($blueprintId, $printProviderId) {
        return $this->request('GET', "/catalog/blueprints/{$blueprintId}/print_providers/{$printProviderId}.json", null, true);
    }
    
    /**
     * Get variants for a blueprint and print provider
     * GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}/variants.json
     */
    public function getVariants($blueprintId, $printProviderId) {
        return $this->request('GET', "/catalog/blueprints/{$blueprintId}/print_providers/{$printProviderId}/variants.json", null, true);
    }
    
    /**
     * Get shipping info for a variant
     * GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}/shipping.json
     */
    public function getShippingInfo($blueprintId, $printProviderId) {
        return $this->request('GET', "/catalog/blueprints/{$blueprintId}/print_providers/{$printProviderId}/shipping.json", null, true);
    }
    
    // ========================================================================
    // PRODUCTS API
    // ========================================================================
    
    /**
     * Get all products in a shop
     * GET /v1/shops/{shop_id}/products.json
     * 
     * @param int $page Page number (default 1)
     * @param int $limit Results per page (max 100)
     */
    public function getProducts($shopId = null, $page = 1, $limit = 50) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        
        $query = http_build_query(['page' => $page, 'limit' => min($limit, 50)]);
        return $this->request('GET', "/shops/{$shopId}/products.json?{$query}");
    }
    
    /**
     * Get specific product
     * GET /v1/shops/{shop_id}/products/{product_id}.json
     */
    public function getProduct($productId, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('GET', "/shops/{$shopId}/products/{$productId}.json");
    }
    
    /**
     * Create a new product
     * POST /v1/shops/{shop_id}/products.json
     */
    public function createProduct($productData, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('POST', "/shops/{$shopId}/products.json", $productData);
    }
    
    /**
     * Update existing product
     * PUT /v1/shops/{shop_id}/products/{product_id}.json
     */
    public function updateProduct($productId, $productData, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('PUT', "/shops/{$shopId}/products/{$productId}.json", $productData);
    }
    
    /**
     * Delete a product
     * DELETE /v1/shops/{shop_id}/products/{product_id}.json
     */
    public function deleteProduct($productId, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('DELETE', "/shops/{$shopId}/products/{$productId}.json");
    }
    
    /**
     * Publish product to a specific platform
     * POST /v1/shops/{shop_id}/products/{product_id}/publish.json
     */
    public function publishProduct($productId, $publishData, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('POST', "/shops/{$shopId}/products/{$productId}/publish.json", $publishData);
    }
    
    /**
     * Set product publish status (succeeded/failed)
     * POST /v1/shops/{shop_id}/products/{product_id}/publishing_succeeded.json
     * POST /v1/shops/{shop_id}/products/{product_id}/publishing_failed.json
     */
    public function setPublishStatus($productId, $success = true, $data = [], $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        
        $endpoint = $success ? 'publishing_succeeded' : 'publishing_failed';
        return $this->request('POST', "/shops/{$shopId}/products/{$productId}/{$endpoint}.json", $data);
    }
    
    /**
     * Unpublish product
     * POST /v1/shops/{shop_id}/products/{product_id}/unpublish.json
     */
    public function unpublishProduct($productId, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('POST', "/shops/{$shopId}/products/{$productId}/unpublish.json");
    }
    
    // ========================================================================
    // ORDERS API
    // ========================================================================
    
    /**
     * Get all orders
     * GET /v1/shops/{shop_id}/orders.json
     */
    public function getOrders($shopId = null, $page = 1, $limit = 50) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        
        $query = http_build_query(['page' => $page, 'limit' => min($limit, 50)]);
        return $this->request('GET', "/shops/{$shopId}/orders.json?{$query}");
    }
    
    /**
     * Get specific order
     * GET /v1/shops/{shop_id}/orders/{order_id}.json
     */
    public function getOrder($orderId, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('GET', "/shops/{$shopId}/orders/{$orderId}.json");
    }
    
    /**
     * Submit order for production
     * POST /v1/shops/{shop_id}/orders/{order_id}/send_to_production.json
     */
    public function submitOrder($orderId, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('POST', "/shops/{$shopId}/orders/{$orderId}/send_to_production.json");
    }
    
    /**
     * Calculate shipping costs
     * POST /v1/shops/{shop_id}/orders/shipping.json
     */
    public function calculateShipping($shippingData, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('POST', "/shops/{$shopId}/orders/shipping.json", $shippingData);
    }
    
    /**
     * Create order (but don't submit to production yet)
     * POST /v1/shops/{shop_id}/orders.json
     */
    public function createOrder($orderData, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('POST', "/shops/{$shopId}/orders.json", $orderData);
    }
    
    /**
     * Cancel an order
     * POST /v1/shops/{shop_id}/orders/{order_id}/cancel.json
     */
    public function cancelOrder($orderId, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('POST', "/shops/{$shopId}/orders/{$orderId}/cancel.json");
    }
    
    // ========================================================================
    // UPLOADS API
    // ========================================================================
    
    /**
     * Get uploaded images
     * GET /v1/uploads.json
     */
    public function getUploads($page = 1, $limit = 50) {
        $query = http_build_query(['page' => $page, 'limit' => min($limit, 50)]);
        return $this->request('GET', "/uploads.json?{$query}");
    }
    
    /**
     * Get specific upload
     * GET /v1/uploads/{upload_id}.json
     */
    public function getUpload($uploadId) {
        return $this->request('GET', "/uploads/{$uploadId}.json");
    }
    
    /**
     * Upload image by URL
     * POST /v1/uploads/images.json
     */
    public function uploadImageByUrl($imageUrl, $filename = null) {
        $data = [
            'file_name' => $filename ?? basename(parse_url($imageUrl, PHP_URL_PATH)),
            'url' => $imageUrl
        ];
        return $this->request('POST', '/uploads/images.json', $data);
    }
    
    /**
     * Upload image file (base64)
     * POST /v1/uploads/images.json
     */
    public function uploadImageFile($base64Contents, $filename) {
        $data = [
            'file_name' => $filename,
            'contents' => $base64Contents
        ];
        return $this->request('POST', '/uploads/images.json', $data);
    }
    
    /**
     * Archive (delete) upload
     * POST /v1/uploads/{upload_id}/archive.json
     */
    public function archiveUpload($uploadId) {
        return $this->request('POST', "/uploads/{$uploadId}/archive.json");
    }
    
    // ========================================================================
    // WEBHOOKS API
    // ========================================================================
    
    /**
     * Get all webhooks
     * GET /v1/shops/{shop_id}/webhooks.json
     */
    public function getWebhooks($shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('GET', "/shops/{$shopId}/webhooks.json");
    }
    
    /**
     * Create webhook
     * POST /v1/shops/{shop_id}/webhooks.json
     * 
     * @param string $topic Event topic (e.g., 'order:created')
     * @param string $url Your endpoint URL
     */
    public function createWebhook($topic, $url, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        
        $data = [
            'topic' => $topic,
            'url' => $url
        ];
        
        return $this->request('POST', "/shops/{$shopId}/webhooks.json", $data);
    }
    
    /**
     * Update webhook
     * PUT /v1/shops/{shop_id}/webhooks/{webhook_id}.json
     */
    public function updateWebhook($webhookId, $url, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        
        $data = ['url' => $url];
        return $this->request('PUT', "/shops/{$shopId}/webhooks/{$webhookId}.json", $data);
    }
    
    /**
     * Delete webhook
     * DELETE /v1/shops/{shop_id}/webhooks/{webhook_id}.json
     */
    public function deleteWebhook($webhookId, $shopId = null) {
        $shopId = $shopId ?? $this->shopId;
        if (!$shopId) {
            throw new Exception('Shop ID is required');
        }
        return $this->request('DELETE', "/shops/{$shopId}/webhooks/{$webhookId}.json");
    }
    
    // ========================================================================
    // CORE HTTP REQUEST METHOD
    // ========================================================================
    
    /**
     * Make HTTP request to Printify API
     * 
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint
     * @param array|null $data Request body data
     * @param bool $isCatalogRequest Whether this is a catalog endpoint (stricter rate limit)
     * @return array API response
     * @throws Exception on error
     */
    private function request($method, $endpoint, $data = null, $isCatalogRequest = false) {
        // Rate limiting check
        $this->checkRateLimit($isCatalogRequest);
        
        $url = $this->baseUrl . $endpoint;
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->maxRetries) {
            $attempt++;
            
            try {
                // Initialize cURL
                $ch = curl_init();
                
                // Set URL
                curl_setopt($ch, CURLOPT_URL, $url);
                
                // Set method and data
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    if ($data !== null) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    }
                } elseif ($method === 'PUT') {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                    if ($data !== null) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    }
                } elseif ($method === 'DELETE') {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                }
                
                // Set headers
                $headers = [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'User-Agent: Product-Platform/1.0 (PHP)'
                ];
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                
                // Other options
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                
                // Execute request
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                // Handle cURL errors
                if ($response === false) {
                    throw new Exception('cURL error: ' . $curlError);
                }
                
                // Parse response
                $responseData = json_decode($response, true);
                
                // Handle HTTP errors
                if ($httpCode >= 400) {
                    // Check for rate limiting (429)
                    if ($httpCode === 429) {
                        if ($attempt < $this->maxRetries) {
                            $delay = $this->retryDelay * pow(2, $attempt - 1); // Exponential backoff
                            usleep($delay * 1000); // Convert to microseconds
                            continue; // Retry
                        }
                        throw new Exception('Rate limit exceeded (429)');
                    }
                    
                    // Other errors
                    $errorMessage = $responseData['message'] ?? $responseData['error'] ?? 'Unknown error';
                    throw new Exception("HTTP {$httpCode}: {$errorMessage}");
                }
                
                // Log request (for debugging)
                $this->logRequest($method, $endpoint, $data, $responseData, $httpCode);
                
                // Return successful response
                return $responseData;
                
            } catch (Exception $e) {
                $lastError = $e;
                
                // Don't retry on certain errors
                $nonRetryableErrors = [400, 401, 403, 404];
                if (in_array($httpCode ?? 0, $nonRetryableErrors)) {
                    break;
                }
                
                // Wait before retry
                if ($attempt < $this->maxRetries) {
                    $delay = $this->retryDelay * pow(2, $attempt - 1);
                    usleep($delay * 1000);
                }
            }
        }
        
        // All retries failed
        throw $lastError ?? new Exception('Request failed after ' . $this->maxRetries . ' attempts');
    }
    
    /**
     * Check and enforce rate limits
     */
    private function checkRateLimit($isCatalogRequest) {
        $now = microtime(true);
        $windowSize = 60; // 1 minute window
        
        // Clean old requests from tracking arrays
        $this->globalRequestTimes = array_filter(
            $this->globalRequestTimes,
            function($time) use ($now, $windowSize) {
                return ($now - $time) < $windowSize;
            }
        );
        
        if ($isCatalogRequest) {
            $this->catalogRequestTimes = array_filter(
                $this->catalogRequestTimes,
                function($time) use ($now, $windowSize) {
                    return ($now - $time) < $windowSize;
                }
            );
            
            // Check catalog rate limit
            if (count($this->catalogRequestTimes) >= $this->catalogRateLimit) {
                $oldestRequest = min($this->catalogRequestTimes);
                $waitTime = $windowSize - ($now - $oldestRequest);
                if ($waitTime > 0) {
                    usleep($waitTime * 1000000); // Convert to microseconds
                }
            }
            
            $this->catalogRequestTimes[] = microtime(true);
        }
        
        // Check global rate limit
        if (count($this->globalRequestTimes) >= $this->globalRateLimit) {
            $oldestRequest = min($this->globalRequestTimes);
            $waitTime = $windowSize - ($now - $oldestRequest);
            if ($waitTime > 0) {
                usleep($waitTime * 1000000);
            }
        }
        
        $this->globalRequestTimes[] = microtime(true);
    }
    
    /**
     * Log API request for debugging and audit trail
     */
    private function logRequest($method, $endpoint, $requestData, $responseData, $httpCode) {
        // Only log in development or if debug mode enabled
        if (defined('PRODUCT_ENV') && PRODUCT_ENV !== 'prod') {
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'method' => $method,
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'request_size' => $requestData ? strlen(json_encode($requestData)) : 0,
                'response_size' => $responseData ? strlen(json_encode($responseData)) : 0
            ];
            
            error_log('PrintifyAPI: ' . json_encode($logEntry));
        }
    }
    
    /**
     * Test API connection
     * 
     * @return array Connection status and shop info
     */
    public function testConnection() {
        try {
            $shops = $this->getShops();
            return [
                'success' => true,
                'shops' => $shops,
                'message' => 'Connection successful'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Connection failed'
            ];
        }
    }
}

