<?php
/**
 * Supplier Matching Helper Functions
 * Shared fuzzy matching logic for supplier-to-expense linking
 */

/**
 * Calculate Levenshtein distance percentage similarity
 */
function calculateSimilarity($str1, $str2) {
    $str1 = strtolower(trim($str1));
    $str2 = strtolower(trim($str2));
    
    $maxLen = max(strlen($str1), strlen($str2));
    if ($maxLen == 0) return 100;
    
    $distance = levenshtein($str1, $str2);
    $similarity = (1 - ($distance / $maxLen)) * 100;
    
    return round($similarity, 2);
}

/**
 * Fuzzy match vendor name to suppliers
 */
function findSupplierMatches($vendorName, $pdo) {
    $matches = [];
    
    // Get all active suppliers
    $stmt = $pdo->query("
        SELECT id, supplier_name 
        FROM suppliers 
        WHERE is_active = 1
    ");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($suppliers as $supplier) {
        $similarity = calculateSimilarity($vendorName, $supplier['supplier_name']);
        
        // Also check for partial matches
        $vendorLower = strtolower($vendorName);
        $supplierLower = strtolower($supplier['supplier_name']);
        
        if (strpos($vendorLower, $supplierLower) !== false || strpos($supplierLower, $vendorLower) !== false) {
            $similarity = max($similarity, 75); // Boost for substring matches
        }
        
        if ($similarity >= 60) { // Threshold for suggesting matches
            $matches[] = [
                'supplier_id' => $supplier['id'],
                'supplier_name' => $supplier['supplier_name'],
                'confidence' => $similarity,
                'match_algorithm' => 'levenshtein'
            ];
        }
    }
    
    // Sort by confidence descending
    usort($matches, function($a, $b) {
        return $b['confidence'] <=> $a['confidence'];
    });
    
    return $matches;
}











