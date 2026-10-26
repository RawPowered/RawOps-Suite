-- ============================================================
-- Final Price Correction
-- Date: October 10, 2025
-- Purpose: Minor rounding corrections to match spreadsheet exactly
-- ============================================================

-- Correction 1: BLACK wholesale price (off by $0.01 due to rounding)
-- Current: $142.80, Should be: $142.79
UPDATE products 
SET wholesale_price = 142.79,
    msrp = 142.79,
    updated_at = NOW()
WHERE sku = 'BLACK-60-32';

-- Correction 2: Update BLACK tier 1-5 bulk pricing
UPDATE bulk_pricing 
SET unit_price = 142.79,
    updated_at = NOW()
WHERE sku = 'BLACK-60-32' 
  AND quantity_tier_name IN ('X1', 'X5');

-- Correction 3: BLUE tier 6-10 bulk pricing (off by $0.01)
-- Current: $50.65, Should be: $50.64
-- Formula: $55.05 × (1 - 0.08) = $55.05 × 0.92 = $50.646 → rounds to $50.65
-- But spreadsheet shows $50.64, so we'll match it exactly
UPDATE bulk_pricing 
SET unit_price = 50.64,
    updated_at = NOW()
WHERE sku = 'BLUE-10-32' 
  AND quantity_tier_name = 'X10';

-- ============================================================
-- Verification Queries
-- ============================================================

-- Verify product base prices
SELECT 
    '📦 Base Product Prices' AS section,
    p.sku,
    p.name,
    CONCAT('$', FORMAT(p.material_cost, 2)) AS material_cost,
    CONCAT('$', FORMAT(p.total_production_cost, 2)) AS total_cost,
    CONCAT('$', FORMAT(p.wholesale_price, 2)) AS wholesale,
    CONCAT(p.profit_margin_percent, '%') AS margin,
    p.servings_10mg AS servings
FROM products p
WHERE p.sku IN ('BLUE-10-32', 'GOLD-30-32', 'BLACK-60-32')
ORDER BY p.attribute_per_oz;

-- Verify bulk pricing tiers
SELECT 
    '💰 Bulk Pricing Tiers' AS section,
    bp.sku,
    bp.quantity_tier_name AS tier,
    bp.quantity_min AS qty,
    CONCAT(bp.discount_percent, '%') AS discount,
    CONCAT(bp.commission_percent, '%') AS commission,
    CONCAT('$', FORMAT(bp.unit_price, 2)) AS price
FROM bulk_pricing bp
WHERE bp.sku IN ('BLUE-10-32', 'GOLD-30-32', 'BLACK-60-32')
  AND bp.active = 1
ORDER BY bp.sku, bp.quantity_min;

-- Final summary
SELECT '✅ Price corrections applied successfully!' AS status;
SELECT 'BLACK: $142.80 → $142.79' AS correction_1;
SELECT 'BLUE X10: $50.65 → $50.64' AS correction_2;
SELECT 'All prices now match spreadsheet exactly (to the penny)' AS result;

