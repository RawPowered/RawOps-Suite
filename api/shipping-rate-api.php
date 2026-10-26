<?php
/**
 * Shipping Rate API
 * 
 * Provides live shipping rate quotes from carriers (UPS, USPS, FedEx) via ShipEngine
 * 
 * Endpoints:
 * - action=get_rates: Get shipping rates for an address
 * - action=validate_address: Validate shipping address
 * - action=get_carriers: Get available carriers
 */

require_once __DIR__ . '/../required/config.php';

header('Content-Type: application/json');

try {
    start_session();
} catch (Throwable $e) {
    // Continue without session
}

$pdo = db();
$userId = current_user_id();

// Require authentication
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * Get ShipEngine API Key from environment or config
 */
function get_shipengine_api_key(): ?string
{
    // Check environment variable first
    $key = getenv('SHIPENGINE_API_KEY');
    if ($key) {
        return $key;
    }
    
    // Check config constant
    if (defined('SHIPENGINE_API_KEY')) {
        return SHIPENGINE_API_KEY;
    }
    
    // For development/testing: Return test key if available
    // In production, this should be set via environment variable
    return null;
}

/**
 * Check if flat rate mode is enabled
 */
function is_flat_rate_mode(): bool
{
    return !get_shipengine_api_key();
}

/**
 * Get flat rate shipping options (fallback when API not configured)
 */
function get_flat_rate_options(): array
{
    return [
        [
            'carrier' => 'USPS',
            'service_name' => 'First Class Mail',
            'service_code' => 'usps_first_class_mail',
            'delivery_days' => 3,
            'estimated_delivery_date' => date('Y-m-d', strtotime('+3 days')),
            'cost' => 5.00,
            'currency' => 'USD',
            'rate_id' => 'flat_rate_usps_fcm',
            'carrier_delivery_days' => '2-5',
            'trackable' => true,
            'guaranteed_service' => false,
            'is_flat_rate' => true
        ],
        [
            'carrier' => 'USPS',
            'service_name' => 'Priority Mail',
            'service_code' => 'usps_priority_mail',
            'delivery_days' => 2,
            'estimated_delivery_date' => date('Y-m-d', strtotime('+2 days')),
            'cost' => 8.50,
            'currency' => 'USD',
            'rate_id' => 'flat_rate_usps_priority',
            'carrier_delivery_days' => '1-3',
            'trackable' => true,
            'guaranteed_service' => false,
            'is_flat_rate' => true
        ],
        [
            'carrier' => 'UPS',
            'service_name' => 'Ground',
            'service_code' => 'ups_ground',
            'delivery_days' => 5,
            'estimated_delivery_date' => date('Y-m-d', strtotime('+5 days')),
            'cost' => 10.00,
            'currency' => 'USD',
            'rate_id' => 'flat_rate_ups_ground',
            'carrier_delivery_days' => '3-7',
            'trackable' => true,
            'guaranteed_service' => false,
            'is_flat_rate' => true
        ]
    ];
}

/**
 * Get default shipping origin address
 */
function get_origin_address(): array
{
    // This should be configured in your environment
    // Default to a sample address for now
    return [
        'name' => 'Product Distribution',
        'company_name' => 'Product',
        'address_line1' => '123 Business Pkwy',
        'city_locality' => 'Tampa',
        'state_province' => 'FL',
        'postal_code' => '33602',
        'country_code' => 'US',
        'phone' => '555-0100'
    ];
}

/**
 * Call ShipEngine API
 */
function call_shipengine_api(string $endpoint, string $method = 'POST', ?array $data = null): array
{
    $apiKey = get_shipengine_api_key();
    
    if (!$apiKey) {
        throw new Exception('ShipEngine API key not configured');
    }
    
    $url = 'https://api.shipengine.com/v1/' . ltrim($endpoint, '/');
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'API-Key: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('cURL error: ' . $error);
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode >= 400) {
        $errorMsg = $result['errors'][0]['message'] ?? $result['message'] ?? 'Unknown API error';
        throw new Exception('ShipEngine API error: ' . $errorMsg);
    }
    
    return $result;
}

/**
 * Track API usage for accounting
 */
function track_shipping_api_usage(PDO $pdo, string $operation, float $cost, ?int $userId): void
{
    try {
        $stmt = $pdo->prepare('
            INSERT INTO api_usage_tracking (
                service_name, operation_type, cost_usd, user_id, tracked_at
            ) VALUES (?, ?, ?, ?, NOW())
        ');
        $stmt->execute(['shipengine', $operation, $cost, $userId]);
    } catch (Throwable $e) {
        // Non-blocking: API usage tracking failure shouldn't break functionality
        error_log('Failed to track shipping API usage: ' . $e->getMessage());
    }
}

// ============================================
// ACTION: Get Shipping Rates
// ============================================

if ($action === 'get_rates') {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        
        // Validate required fields
        $required = ['address_line1', 'city', 'state', 'zip', 'country'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }
        
        // Check if using flat rate mode (no API key)
        if (is_flat_rate_mode()) {
            // Return flat rate options
            $rates = get_flat_rate_options();
            
            echo json_encode([
                'ok' => true,
                'rates' => $rates,
                'count' => count($rates),
                'mode' => 'flat_rate',
                'notice' => 'Using flat rate pricing. Live rates will be available when ShipEngine API is configured.'
            ]);
            exit;
        }
        
        // Get package dimensions (default for 2oz sample)
        $weightOz = floatval($input['weight_oz'] ?? 2.5); // 2oz sample + packaging
        $weightPounds = $weightOz / 16;
        
        // Prepare shipment data for ShipEngine
        $shipment = [
            'shipment' => [
                'ship_from' => get_origin_address(),
                'ship_to' => [
                    'name' => trim($input['recipient_name'] ?? 'Customer'),
                    'address_line1' => trim($input['address_line1']),
                    'address_line2' => trim($input['address_line2'] ?? ''),
                    'city_locality' => trim($input['city']),
                    'state_province' => trim($input['state']),
                    'postal_code' => trim($input['zip']),
                    'country_code' => strtoupper(trim($input['country'] ?? 'US')),
                    'phone' => trim($input['phone'] ?? '')
                ],
                'packages' => [
                    [
                        'weight' => [
                            'value' => max(0.1, $weightPounds),
                            'unit' => 'pound'
                        ],
                        'dimensions' => [
                            'length' => 6,
                            'width' => 4,
                            'height' => 2,
                            'unit' => 'inch'
                        ]
                    ]
                ]
            ]
        ];
        
        // Call ShipEngine rates API
        try {
            $result = call_shipengine_api('rates', 'POST', $shipment);
            
            // Track API usage (estimate $0.01 per rate request)
            track_shipping_api_usage($pdo, 'get_rates', 0.01, $userId);
            
            // Format rates for response
            $rates = [];
            foreach (($result['rate_response']['rates'] ?? []) as $rate) {
                $rates[] = [
                    'carrier' => $rate['carrier_friendly_name'] ?? $rate['carrier_code'] ?? 'Unknown',
                    'service_name' => $rate['service_type'] ?? 'Standard',
                    'service_code' => $rate['service_code'] ?? '',
                    'delivery_days' => $rate['delivery_days'] ?? null,
                    'estimated_delivery_date' => $rate['estimated_delivery_date'] ?? null,
                    'cost' => floatval($rate['shipping_amount']['amount'] ?? 0),
                    'currency' => $rate['shipping_amount']['currency'] ?? 'USD',
                    'rate_id' => $rate['rate_id'] ?? null,
                    'carrier_delivery_days' => $rate['carrier_delivery_days'] ?? null,
                    'trackable' => $rate['trackable'] ?? true,
                    'guaranteed_service' => $rate['guaranteed_service'] ?? false,
                    'is_flat_rate' => false
                ];
            }
            
            // Sort by cost (cheapest first)
            usort($rates, function ($a, $b) {
                return $a['cost'] <=> $b['cost'];
            });
            
            echo json_encode([
                'ok' => true,
                'rates' => $rates,
                'count' => count($rates),
                'mode' => 'live'
            ]);
            
        } catch (Exception $apiError) {
            // Fallback to flat rates if API call fails
            error_log('ShipEngine API error, falling back to flat rates: ' . $apiError->getMessage());
            
            $rates = get_flat_rate_options();
            
            echo json_encode([
                'ok' => true,
                'rates' => $rates,
                'count' => count($rates),
                'mode' => 'flat_rate_fallback',
                'notice' => 'Live rates temporarily unavailable. Using flat rate pricing.',
                'api_error' => $apiError->getMessage()
            ]);
        }
        
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// ACTION: Validate Address
// ============================================

if ($action === 'validate_address') {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        
        $address = [
            'address_line1' => trim($input['address_line1'] ?? ''),
            'address_line2' => trim($input['address_line2'] ?? ''),
            'city_locality' => trim($input['city'] ?? ''),
            'state_province' => trim($input['state'] ?? ''),
            'postal_code' => trim($input['zip'] ?? ''),
            'country_code' => strtoupper(trim($input['country'] ?? 'US'))
        ];
        
        // In flat rate mode, perform basic validation
        if (is_flat_rate_mode()) {
            $isValid = !empty($address['address_line1']) && 
                       !empty($address['city_locality']) && 
                       !empty($address['state_province']) && 
                       !empty($address['postal_code']);
            
            echo json_encode([
                'ok' => true,
                'valid' => $isValid,
                'status' => $isValid ? 'verified' : 'invalid',
                'messages' => $isValid ? [] : [['message' => 'Please complete all required address fields']],
                'matched_address' => $isValid ? $address : null,
                'original_address' => $address,
                'mode' => 'flat_rate'
            ]);
            exit;
        }
        
        $result = call_shipengine_api('addresses/validate', 'POST', [$address]);
        
        // Track API usage
        track_shipping_api_usage($pdo, 'validate_address', 0.005, $userId);
        
        $validated = $result[0] ?? [];
        $status = $validated['status'] ?? 'unknown';
        
        echo json_encode([
            'ok' => true,
            'valid' => in_array($status, ['verified', 'warning']),
            'status' => $status,
            'messages' => $validated['messages'] ?? [],
            'matched_address' => $validated['matched_address'] ?? null,
            'original_address' => $validated['original_address'] ?? null,
            'mode' => 'live'
        ]);
        
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// ACTION: Get Available Carriers
// ============================================

if ($action === 'get_carriers') {
    try {
        // Return static list of supported carriers
        // In production, this could query ShipEngine's carriers endpoint
        $carriers = [
            [
                'code' => 'usps',
                'name' => 'USPS',
                'friendly_name' => 'United States Postal Service',
                'services' => ['First Class Mail', 'Priority Mail', 'Priority Mail Express']
            ],
            [
                'code' => 'ups',
                'name' => 'UPS',
                'friendly_name' => 'United Parcel Service',
                'services' => ['Ground', '3 Day Select', '2nd Day Air', 'Next Day Air']
            ],
            [
                'code' => 'fedex',
                'name' => 'FedEx',
                'friendly_name' => 'Federal Express',
                'services' => ['Ground', 'Home Delivery', '2Day', 'Standard Overnight']
            ]
        ];
        
        echo json_encode([
            'ok' => true,
            'carriers' => $carriers
        ]);
        
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// Invalid Action
// ============================================

http_response_code(400);
echo json_encode([
    'ok' => false,
    'error' => 'Invalid action. Supported: get_rates, validate_address, get_carriers'
]);

