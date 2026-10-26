<?php
/**
 * Dynamic Product Data Loader
 * Pulls current pricing from database using our pricing model
 * This replaces the static product-data.php with live database queries
 */

// Get database connection
$pdo = db();

// Fetch all active products with calculated pricing
$stmt = $pdo->query("
    SELECT 
        p.id,
        p.sku,
        p.name,
        p.attribute_per_oz,
        p.size_oz,
        p.total_mg,
        p.servings_10mg,
        p.wholesale_price,
        p.msrp,
        p.material_cost,
        p.total_production_cost,
        p.profit_margin_percent,
        p.cost_per_10mg_serving,
        p.ml_per_10mg_serving,
        p.oz_per_10mg_serving,
        p.drinks_per_oz,
        p.product_group_id,
        pg.name AS group_name,
        pg.group_key
    FROM products p
    LEFT JOIN product_groups pg ON pg.id = p.product_group_id
    WHERE p.active = 1 
        AND p.attribute_per_oz IS NOT NULL 
        AND p.size_oz IS NOT NULL
    ORDER BY p.attribute_per_oz ASC, p.size_oz ASC
");

$products = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $products[] = [
        'id' => (int)$row['id'],
        'sku' => $row['sku'],
        'name' => $row['name'],
        'mg_per_oz' => (float)$row['attribute_per_oz'],
        'bottle_size' => (float)$row['size_oz'],
        'total_mg' => (int)$row['total_mg'],
        'servings' => (int)$row['servings_10mg'],
        'wholesale_price' => (float)$row['wholesale_price'],
        'msrp' => (float)$row['msrp'],
        'sale_price' => (float)$row['wholesale_price'], // Wholesale price is the sale price
        'material_cost' => (float)$row['material_cost'],
        'total_production_cost' => (float)$row['total_production_cost'],
        'profit_margin_percent' => (float)$row['profit_margin_percent'],
        'cost_per_10mg_serving' => (float)$row['cost_per_10mg_serving'],
        'ml_per_10mg_serving' => (float)$row['ml_per_10mg_serving'],
        'oz_per_10mg_serving' => (float)$row['oz_per_10mg_serving'],
        'drinks_per_oz' => (float)$row['drinks_per_oz'],
        'product_group_id' => $row['product_group_id'],
        'group_name' => $row['group_name'],
        'group_key' => $row['group_key'],
        // Legacy field names for backwards compatibility
        'serving_size_oz' => (float)$row['oz_per_10mg_serving']
    ];
}

// Fetch bulk pricing tiers from product_group_commissions
// This gives us the actual commission structure per product group
$stmt = $pdo->query("
    SELECT 
        pgc.product_group_id,
        pgc.tier_name,
        pgc.quantity_min,
        pgc.quantity_max,
        pgc.bulk_discount_percent,
        pgc.commission_percent,
        pg.group_key
    FROM product_group_commissions pgc
    JOIN product_groups pg ON pg.id = pgc.product_group_id
    WHERE pgc.active = 1
    ORDER BY pgc.product_group_id, pgc.quantity_min ASC
");

$bulk_pricing_by_group = [];
$commission_tiers = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $groupId = (int)$row['product_group_id'];
    
    // Store bulk pricing tiers by group
    if (!isset($bulk_pricing_by_group[$groupId])) {
        $bulk_pricing_by_group[$groupId] = [];
    }
    
    $bulk_pricing_by_group[$groupId][] = [
        'tier_name' => $row['tier_name'],
        'qty_min' => (int)$row['quantity_min'],
        'qty_max' => $row['quantity_max'] ? (int)$row['quantity_max'] : null,
        'discount' => (float)$row['bulk_discount_percent'],
        'commission' => (float)$row['commission_percent']
    ];
    
    // Also create commission lookup by quantity
    $commission_tiers[] = [
        'group_id' => $groupId,
        'group_key' => $row['group_key'],
        'tier_name' => $row['tier_name'],
        'qty_min' => (int)$row['quantity_min'],
        'qty_max' => $row['quantity_max'] ? (int)$row['quantity_max'] : null,
        'discount_percent' => (float)$row['bulk_discount_percent'],
        'commission_percent' => (float)$row['commission_percent']
    ];
}

// Create simplified bulk_discounts array for backwards compatibility
// Use the first product group's tiers as default
$bulk_discounts = [];
if (!empty($bulk_pricing_by_group)) {
    $firstGroup = reset($bulk_pricing_by_group);
    foreach ($firstGroup as $tier) {
        $bulk_discounts[] = [
            'qty' => $tier['tier_name'],
            'discount' => $tier['discount']
        ];
    }
}

/**
 * Helper function: Get commission rate for a given quantity
 * 
 * @param int $quantity Total quantity ordered
 * @param int|null $groupId Product group ID (optional, uses first group if not specified)
 * @return array ['discount_percent' => float, 'commission_percent' => float, 'tier_name' => string]
 */
function get_commission_for_quantity($quantity, $groupId = null) {
    global $commission_tiers;
    
    // If no group specified, use first group
    if ($groupId === null && !empty($commission_tiers)) {
        $groupId = $commission_tiers[0]['group_id'];
    }
    
    // Find matching tier
    foreach ($commission_tiers as $tier) {
        if ($tier['group_id'] == $groupId) {
            if ($quantity >= $tier['qty_min']) {
                if ($tier['qty_max'] === null || $quantity <= $tier['qty_max']) {
                    return [
                        'discount_percent' => $tier['discount_percent'],
                        'commission_percent' => $tier['commission_percent'],
                        'tier_name' => $tier['tier_name']
                    ];
                }
            }
        }
    }
    
    // Default: no discount, 20% commission
    return [
        'discount_percent' => 0.0,
        'commission_percent' => 20.0,
        'tier_name' => '1-5'
    ];
}

/**
 * Helper function: Calculate order totals with proper commission structure
 * 
 * @param array $items Array of ['sku' => string, 'qty' => int]
 * @return array Order details with totals, commission, and profit breakdown
 */
function calculate_order_totals($items) {
    global $products;
    
    $subtotal = 0;
    $totalQty = 0;
    $orderItems = [];
    $groupId = null;
    
    // Build order items and calculate subtotal
    foreach ($items as $item) {
        $sku = $item['sku'];
        $qty = (int)$item['qty'];
        
        if ($qty <= 0) continue;
        
        // Find product
        $product = null;
        foreach ($products as $p) {
            if ($p['sku'] === $sku) {
                $product = $p;
                break;
            }
        }
        
        if (!$product) continue;
        
        // Store group ID from first product (assumes all products in order are from same group)
        if ($groupId === null) {
            $groupId = $product['product_group_id'];
        }
        
        $lineTotal = $product['wholesale_price'] * $qty;
        $subtotal += $lineTotal;
        $totalQty += $qty;
        
        $orderItems[] = [
            'product' => $product,
            'quantity' => $qty,
            'unit_price' => $product['wholesale_price'],
            'line_total' => $lineTotal
        ];
    }
    
    // Get commission tier for total quantity
    $commissionInfo = get_commission_for_quantity($totalQty, $groupId);
    
    // Apply bulk discount to subtotal
    $discount = $subtotal * ($commissionInfo['discount_percent'] / 100);
    $subtotalAfterDiscount = $subtotal - $discount;
    
    // Calculate shipping (free over $500)
    $shipping = $subtotalAfterDiscount >= 500 ? 0 : 25;
    
    // Calculate tax (8%)
    $taxRate = 0.08;
    $tax = $subtotalAfterDiscount * $taxRate;
    
    // Calculate total
    $total = $subtotalAfterDiscount + $shipping + $tax;
    
    // Calculate commission (on subtotal before tax/shipping)
    $commission = $subtotalAfterDiscount * ($commissionInfo['commission_percent'] / 100);
    
    return [
        'items' => $orderItems,
        'subtotal' => $subtotal,
        'discount_percent' => $commissionInfo['discount_percent'],
        'discount_amount' => $discount,
        'subtotal_after_discount' => $subtotalAfterDiscount,
        'shipping' => $shipping,
        'tax_rate' => $taxRate,
        'tax' => $tax,
        'total' => $total,
        'commission_percent' => $commissionInfo['commission_percent'],
        'commission_amount' => $commission,
        'tier_name' => $commissionInfo['tier_name'],
        'total_quantity' => $totalQty
    ];
}



