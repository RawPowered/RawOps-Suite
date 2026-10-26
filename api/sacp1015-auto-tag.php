<?php
/**
 * SACP Auto-Tag API
 * Automatically detects and suggests tags for leads based on business name and address
 * 
 * @version 1.0.0
 * @prefix sacp1015_
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../required/config.php';
start_session();

// Require authentication
$uid = current_user_id();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$pdo = db();

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$companyName = trim($input['company_name'] ?? '');
$address = trim($input['address'] ?? '');
$city = trim($input['city'] ?? '');
$state = trim($input['state'] ?? '');

if (empty($companyName)) {
    echo json_encode(['error' => 'Company name is required', 'suggestions' => []]);
    exit;
}

/**
 * Auto-detect tags based on company name and address
 */
function sacp1015_detectTags($pdo, $companyName, $address = '', $city = '', $state = '') {
    $suggestions = [];
    
    // Get all active tags with keywords
    $stmt = $pdo->query('
        SELECT id, tag_name, tag_slug, tag_color, tag_icon, auto_detect_keywords 
        FROM crm_lead_tags 
        WHERE is_active = 1 
        ORDER BY sort_order ASC
    ');
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Normalize company name for matching
    $normalizedName = strtolower(trim($companyName));
    $normalizedName = preg_replace('/[^a-z0-9\s]/', ' ', $normalizedName);
    $normalizedName = preg_replace('/\s+/', ' ', $normalizedName);
    
    // Match against each tag's keywords
    foreach ($tags as $tag) {
        if (empty($tag['auto_detect_keywords'])) {
            continue;
        }
        
        $keywords = json_decode($tag['auto_detect_keywords'], true);
        if (!is_array($keywords)) {
            continue;
        }
        
        $matchScore = 0;
        $matchedKeywords = [];
        
        foreach ($keywords as $keyword) {
            $normalizedKeyword = strtolower(trim($keyword));
            
            // Exact phrase match (higher score)
            if (strpos($normalizedName, $normalizedKeyword) !== false) {
                $matchScore += 50;
                $matchedKeywords[] = $keyword;
            }
            
            // Word match (lower score)
            $words = explode(' ', $normalizedName);
            foreach ($words as $word) {
                if ($word === $normalizedKeyword || 
                    (strlen($normalizedKeyword) > 4 && strpos($word, $normalizedKeyword) === 0)) {
                    $matchScore += 25;
                    if (!in_array($keyword, $matchedKeywords)) {
                        $matchedKeywords[] = $keyword;
                    }
                }
            }
        }
        
        if ($matchScore > 0) {
            // Calculate confidence (0-100)
            $confidence = min(100, $matchScore);
            
            $suggestions[] = [
                'tag_id' => $tag['id'],
                'tag_name' => $tag['tag_name'],
                'tag_slug' => $tag['tag_slug'],
                'tag_color' => $tag['tag_color'],
                'tag_icon' => $tag['tag_icon'],
                'confidence' => $confidence,
                'matched_keywords' => $matchedKeywords,
                'is_auto_assigned' => $confidence >= 70
            ];
        }
    }
    
    // Sort by confidence (highest first)
    usort($suggestions, function($a, $b) {
        return $b['confidence'] - $a['confidence'];
    });
    
    // If no strong matches, suggest "Other" as fallback
    if (empty($suggestions) || $suggestions[0]['confidence'] < 50) {
        $otherTag = $pdo->query("
            SELECT id, tag_name, tag_slug, tag_color, tag_icon 
            FROM crm_lead_tags 
            WHERE tag_slug = 'other' AND is_active = 1 
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        
        if ($otherTag) {
            $suggestions[] = [
                'tag_id' => $otherTag['id'],
                'tag_name' => $otherTag['tag_name'],
                'tag_slug' => $otherTag['tag_slug'],
                'tag_color' => $otherTag['tag_color'],
                'tag_icon' => $otherTag['tag_icon'],
                'confidence' => 30,
                'matched_keywords' => [],
                'is_auto_assigned' => false
            ];
        }
    }
    
    return $suggestions;
}

/**
 * Get Google Places types (optional enhancement)
 * Only called if address provided and Google API configured
 */
function sacp1015_getPlaceTypes($address, $city, $state) {
    // Check if Google API key configured
    if (!defined('GOOGLE_PLACES_API_KEY') || empty(GOOGLE_PLACES_API_KEY)) {
        return [];
    }
    
    $fullAddress = trim("$address, $city, $state");
    if (empty($fullAddress)) {
        return [];
    }
    
    try {
        $url = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?' . http_build_query([
            'input' => $fullAddress,
            'inputtype' => 'textquery',
            'fields' => 'types,name',
            'key' => GOOGLE_PLACES_API_KEY
        ]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (!empty($data['candidates'][0]['types'])) {
                return $data['candidates'][0]['types'];
            }
        }
    } catch (Exception $e) {
        // Silently fail - don't break auto-tagging if Google API unavailable
        error_log("SACP Auto-Tag: Google Places API error - " . $e->getMessage());
    }
    
    return [];
}

// Perform auto-detection
$suggestions = sacp1015_detectTags($pdo, $companyName, $address, $city, $state);

// Optional: Enhance with Google Places data
if (!empty($address) || !empty($city)) {
    $placeTypes = sacp1015_getPlaceTypes($address, $city, $state);
    
    // Cross-reference place types with tags
    if (!empty($placeTypes)) {
        $placeTypeBoosts = [
            'convenience_store' => 'convenience-store',
            'gas_station' => 'convenience-store',
            'store' => 'convenience-store',
            'health' => 'herbal-shop',
            'food' => 'partner-bar'
        ];
        
        foreach ($placeTypes as $placeType) {
            if (isset($placeTypeBoosts[$placeType])) {
                $targetSlug = $placeTypeBoosts[$placeType];
                foreach ($suggestions as &$suggestion) {
                    if ($suggestion['tag_slug'] === $targetSlug) {
                        $suggestion['confidence'] = min(100, $suggestion['confidence'] + 20);
                        $suggestion['place_type_match'] = true;
                    }
                }
                unset($suggestion);
            }
        }
        
        // Re-sort after boosting
        usort($suggestions, function($a, $b) {
            return $b['confidence'] - $a['confidence'];
        });
    }
}

// Return suggestions
echo json_encode([
    'success' => true,
    'company_name' => $companyName,
    'suggestions' => array_slice($suggestions, 0, 5), // Top 5 suggestions
    'auto_assign_recommended' => !empty($suggestions) && $suggestions[0]['confidence'] >= 70,
    'timestamp' => date('c')
]);




