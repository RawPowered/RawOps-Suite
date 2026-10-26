<?php
/**
 * Training Module Helper Functions
 * 
 * Dynamic product data integration for training modules
 * Ensures all training materials update automatically when products change
 */

/**
 * Get a training-enabled product by SKU
 * 
 * @param string $sku Product SKU (e.g., 'BLEND-32-20')
 * @param bool $require_training_enabled Only return if use_in_training = 1
 * @return array|null Product data or null if not found
 */
function get_training_product($sku, $require_training_enabled = true) {
    try {
        $pdo = db();
        
        $sql = "SELECT 
            id, sku, name, description, 
            wholesale_price, msrp,
            attribute_per_oz, size_oz,
            ml_per_10mg_serving, oz_per_10mg_serving,
            drinks_per_oz, cost_per_10mg_serving,
            servings_10mg, total_mg,
            recommended_first_dose_mg,
            use_in_training, training_context, training_features,
            stock_quantity, active
        FROM products 
        WHERE sku = ?";
        
        if ($require_training_enabled) {
            $sql .= " AND use_in_training = 1 AND active = 1";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sku]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product && !empty($product['training_features'])) {
            $product['training_features'] = json_decode($product['training_features'], true);
        }
        
        return $product ?: null;
    } catch (Exception $e) {
        error_log("get_training_product error for SKU {$sku}: " . $e->getMessage());
        return null;
    }
}

/**
 * Get all training-enabled products
 * 
 * @param string|null $training_context Filter by context (beginner, intermediate, etc.)
 * @return array Array of products
 */
function get_all_training_products($training_context = null) {
    try {
        $pdo = db();
        
        $sql = "SELECT 
            id, sku, name, description,
            wholesale_price, msrp,
            attribute_per_oz, size_oz,
            ml_per_10mg_serving, oz_per_10mg_serving,
            drinks_per_oz, cost_per_10mg_serving,
            servings_10mg, total_mg,
            recommended_first_dose_mg,
            training_context, training_features
        FROM products 
        WHERE use_in_training = 1 AND active = 1";
        
        if ($training_context) {
            $sql .= " AND (training_context = ? OR training_context = 'all')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$training_context]);
        } else {
            $stmt = $pdo->query($sql);
        }
        
        $products = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['training_features'])) {
                $row['training_features'] = json_decode($row['training_features'], true);
            }
            $products[] = $row;
        }
        
        return $products;
    } catch (Exception $e) {
        error_log("get_all_training_products error: " . $e->getMessage());
        return [];
    }
}

/**
 * Calculate profit scenario for a product
 * 
 * @param string $sku Product SKU
 * @param float $drink_price Price per drink (default $12)
 * @param float $base_cost Base cost per drink (water, flavors, ice, etc.) (default $3)
 * @return array|null Profit calculations or null if product not found
 */
function calculate_profit_scenario($sku, $drink_price = 12.00, $base_cost = 3.00) {
    $product = get_training_product($sku);
    if (!$product) {
        return null;
    }
    
    $servings = $product['servings_10mg'] ?? 0;
    $wholesale_price = $product['wholesale_price'] ?? 0;
    
    $total_revenue = $servings * $drink_price;
    $gross_profit = $total_revenue - $wholesale_price;
    $total_base_cost = $servings * $base_cost;
    $net_profit = $gross_profit - $total_base_cost;
    $roi_percent = $wholesale_price > 0 ? (($gross_profit / $wholesale_price) * 100) : 0;
    $net_margin_percent = $total_revenue > 0 ? (($net_profit / $total_revenue) * 100) : 0;
    
    return [
        'sku' => $sku,
        'name' => $product['name'],
        'wholesale_price' => $wholesale_price,
        'servings' => $servings,
        'drink_price' => $drink_price,
        'base_cost_per_drink' => $base_cost,
        
        // Revenue
        'total_revenue' => $total_revenue,
        'revenue_per_oz' => $product['size_oz'] > 0 ? ($total_revenue / $product['size_oz']) : 0,
        
        // Costs
        'total_base_cost' => $total_base_cost,
        
        // Profits
        'gross_profit' => $gross_profit,
        'net_profit' => $net_profit,
        
        // Metrics
        'roi_percent' => $roi_percent,
        'net_margin_percent' => $net_margin_percent,
        'cost_per_serving' => $product['cost_per_10mg_serving'] ?? 0
    ];
}

/**
 * Get commission rate for a given quantity (per line item)
 * 
 * @param int $quantity Quantity of items in the line
 * @return float Commission rate percentage
 */
function get_commission_rate($quantity) {
    try {
        $pdo = db();
        
        // Query the commission_tiers table
        // Using the existing structure: name, commission_rate, etc.
        $stmt = $pdo->prepare("
            SELECT commission_rate 
            FROM commission_tiers 
            WHERE ? >= 
                CASE 
                    WHEN name LIKE '%1-4%' THEN 1
                    WHEN name LIKE '%5-9%' THEN 5
                    WHEN name LIKE '%10-14%' THEN 10
                    WHEN name LIKE '%15-19%' THEN 15
                    WHEN name LIKE '%20%' THEN 20
                    ELSE 0
                END
            AND (
                name LIKE '%1-4%' AND ? <= 4 OR
                name LIKE '%5-9%' AND ? <= 9 OR
                name LIKE '%10-14%' AND ? <= 14 OR
                name LIKE '%15-19%' AND ? <= 19 OR
                name LIKE '%20%' AND ? >= 20
            )
            ORDER BY commission_rate DESC
            LIMIT 1
        ");
        $stmt->execute([$quantity, $quantity, $quantity, $quantity, $quantity]);
        $rate = $stmt->fetchColumn();
        
        // Fallback to tier logic if query fails
        if ($rate === false) {
            if ($quantity <= 4) return 20.00;
            if ($quantity <= 9) return 20.00;
            if ($quantity <= 14) return 15.00;
            if ($quantity <= 19) return 12.00;
            return 10.00;
        }
        
        return (float)$rate;
    } catch (Exception $e) {
        error_log("get_commission_rate error: " . $e->getMessage());
        // Default fallback
        if ($quantity <= 4) return 20.00;
        if ($quantity <= 9) return 20.00;
        if ($quantity <= 14) return 15.00;
        if ($quantity <= 19) return 12.00;
        return 10.00;
    }
}

/**
 * Calculate commission for a line item
 * 
 * @param float $line_total Line item total amount
 * @param int $quantity Quantity in the line
 * @return array Commission details
 */
function calculate_commission($line_total, $quantity) {
    $rate = get_commission_rate($quantity);
    $commission = $line_total * ($rate / 100);
    
    return [
        'line_total' => $line_total,
        'quantity' => $quantity,
        'rate_percent' => $rate,
        'commission_amount' => $commission
    ];
}

/**
 * Get bulk pricing for a SKU and quantity
 * 
 * @param string $sku Product SKU
 * @param int $quantity Order quantity
 * @return array|null Pricing info or null if not found
 */
function get_bulk_price($sku, $quantity) {
    try {
        $pdo = db();
        
        // Check if bulk_pricing table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'bulk_pricing'")->fetchAll();
        if (empty($tables)) {
            error_log("bulk_pricing table does not exist yet");
            return null;
        }
        
        // Find the appropriate tier
        $stmt = $pdo->prepare("
            SELECT 
                sku,
                quantity_tier_name,
                quantity_min,
                discount_percent,
                unit_price
            FROM bulk_pricing
            WHERE sku = ?
                AND quantity_min <= ?
                AND active = 1
            ORDER BY quantity_min DESC
            LIMIT 1
        ");
        $stmt->execute([$sku, $quantity]);
        $pricing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pricing) {
            $pricing['line_total'] = $pricing['unit_price'] * $quantity;
            $pricing['quantity'] = $quantity;
        }
        
        return $pricing ?: null;
    } catch (Exception $e) {
        error_log("get_bulk_price error for SKU {$sku}: " . $e->getMessage());
        return null;
    }
}

/**
 * Format a training feature badge
 * 
 * @param string $sku Product SKU
 * @param string $feature_name Feature to check (e.g., 'consistency_advantage')
 * @return string HTML badge or empty string
 */
function training_feature_badge($sku, $feature_name) {
    $product = get_training_product($sku);
    if (!$product || empty($product['training_features'][$feature_name])) {
        return '';
    }
    
    $labels = [
        'consistency_advantage' => 'Consistent',
        'lab_tested' => 'Lab Tested',
        'tasteless' => 'Tasteless',
        'standardization' => 'attribute Standardized',
        'product_grade' => 'Pharma-Grade',
        'beginner_friendly' => 'Beginner Friendly',
        'high_attribute' => 'High product specification',
        'bulk_value' => 'Bulk Value'
    ];
    
    $label = $labels[$feature_name] ?? ucwords(str_replace('_', ' ', $feature_name));
    
    return '<span class="feature-badge">' . htmlspecialchars($label) . '</span>';
}

/**
 * Get all bulk pricing tiers for a SKU
 * 
 * @param string $sku Product SKU
 * @return array Array of pricing tiers
 */
function get_all_bulk_tiers($sku) {
    try {
        $pdo = db();
        
        // Check if bulk_pricing table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'bulk_pricing'")->fetchAll();
        if (empty($tables)) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                quantity_tier_name,
                quantity_min,
                discount_percent,
                unit_price
            FROM bulk_pricing
            WHERE sku = ? AND active = 1
            ORDER BY quantity_min ASC
        ");
        $stmt->execute([$sku]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("get_all_bulk_tiers error for SKU {$sku}: " . $e->getMessage());
        return [];
    }
}

/**
 * Format product display for training modules
 * 
 * @param string $sku Product SKU
 * @param array $options Display options
 * @return string Formatted product info
 */
function format_training_product($sku, $options = []) {
    $product = get_training_product($sku);
    if (!$product) {
        return '<em>Product not available</em>';
    }
    
    $defaults = [
        'show_price' => true,
        'show_attribute' => true,
        'show_servings' => true,
        'show_sku' => false
    ];
    $options = array_merge($defaults, $options);
    
    $output = '<strong>' . htmlspecialchars($product['name']) . '</strong>';
    
    if ($options['show_sku']) {
        $output .= ' <span style="font-family:monospace; font-size:0.9em;">(' . htmlspecialchars($product['sku']) . ')</span>';
    }
    
    $details = [];
    
    if ($options['show_price']) {
        $details[] = '$' . number_format($product['wholesale_price'], 2);
    }
    
    if ($options['show_attribute']) {
        $details[] = number_format($product['total_mg']) . 'mg attribute';
    }
    
    if ($options['show_servings']) {
        $details[] = $product['servings_10mg'] . ' servings';
    }
    
    if (!empty($details)) {
        $output .= ' <span style="color:#6b7280;">• ' . implode(' • ', $details) . '</span>';
    }
    
    return $output;
}

/**
 * Check if a product has a specific training feature
 * 
 * @param string $sku Product SKU
 * @param string $feature_name Feature to check
 * @return bool
 */
function has_training_feature($sku, $feature_name) {
    $product = get_training_product($sku);
    return $product && !empty($product['training_features'][$feature_name]);
}

/**
 * Get training products by context
 * 
 * @param string $context Context filter (beginner, intermediate, advanced, premium)
 * @return array Filtered products
 */
function get_products_by_context($context) {
    return get_all_training_products($context);
}

/**
 * Format currency
 * 
 * @param float $amount Amount to format
 * @param int $decimals Number of decimal places
 * @return string Formatted currency
 */
function format_currency($amount, $decimals = 2) {
    return '$' . number_format($amount, $decimals);
}

/**
 * Calculate serving metrics for display
 * 
 * @param string $sku Product SKU
 * @return array|null Serving metrics
 */
function get_serving_metrics($sku) {
    $product = get_training_product($sku);
    if (!$product) {
        return null;
    }
    
    return [
        'ml_per_serving' => round($product['ml_per_10mg_serving'], 2),
        'oz_per_serving' => round($product['oz_per_10mg_serving'], 3),
        'drinks_per_oz' => round($product['drinks_per_oz'], 1),
        'servings_per_bottle' => $product['servings_10mg'],
        'cost_per_serving' => round($product['cost_per_10mg_serving'], 2)
    ];
}

