-- ============================================================================
-- Migration: Product Types, Multi-Unit Measurements, and Dropship Support
-- Date: 2025-10-29
-- Purpose: Add support for different product types (liquid/solid/dropship/simple),
--          multi-unit measurements, and external fulfillment capabilities
-- Rules: Binary safe, prevent duplicates, proper character set, idempotent
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- HELPER: Add column only if it doesn't exist
-- ============================================================================

-- Product Type
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'product_type');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN product_type ENUM(''manufactured_liquid'', ''manufactured_solid'', ''dropship'', ''simple'') DEFAULT ''manufactured_liquid'' COMMENT ''Product category'' AFTER id',
    'SELECT ''product_type exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Is Liquid
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_liquid');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN is_liquid TINYINT(1) DEFAULT 1 COMMENT ''Liquid (1) or solid (0)'' AFTER product_type',
    'SELECT ''is_liquid exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Weight: Grams
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'weight_grams');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN weight_grams DECIMAL(10,3) NULL COMMENT ''Weight in grams'' AFTER dimensions',
    'SELECT ''weight_grams exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Weight: Ounces
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'weight_oz');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN weight_oz DECIMAL(10,4) NULL COMMENT ''Weight in ounces (1 oz = 28.3495 g)'' AFTER weight_grams',
    'SELECT ''weight_oz exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Weight: Kilograms
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'weight_kg');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN weight_kg DECIMAL(10,4) NULL COMMENT ''Weight in kilograms (1 kg = 1000 g)'' AFTER weight_oz',
    'SELECT ''weight_kg exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Weight: Milligrams
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'weight_mg');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN weight_mg DECIMAL(12,3) NULL COMMENT ''Weight in milligrams (1 g = 1000 mg)'' AFTER weight_kg',
    'SELECT ''weight_mg exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Volume: Milliliters
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'volume_ml');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN volume_ml DECIMAL(10,3) NULL COMMENT ''Volume in milliliters'' AFTER weight_mg',
    'SELECT ''volume_ml exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Volume: Liters
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'volume_liters');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN volume_liters DECIMAL(10,4) NULL COMMENT ''Volume in liters (1 L = 1000 mL)'' AFTER volume_ml',
    'SELECT ''volume_liters exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Volume: Fluid Ounces
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'volume_fl_oz');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN volume_fl_oz DECIMAL(10,4) NULL COMMENT ''Volume in fluid ounces (1 fl oz = 29.5735 mL)'' AFTER volume_liters',
    'SELECT ''volume_fl_oz exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unit Cost (simple)
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'unit_cost');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN unit_cost DECIMAL(10,2) NULL COMMENT ''Simple per-unit cost'' AFTER total_production_cost',
    'SELECT ''unit_cost exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Is Dropship
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_dropship');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN is_dropship TINYINT(1) DEFAULT 0 COMMENT ''External supplier fulfillment'' AFTER is_liquid',
    'SELECT ''is_dropship exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Supplier Name
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'supplier_name');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN supplier_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT ''External supplier name'' AFTER is_dropship',
    'SELECT ''supplier_name exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Supplier SKU
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'supplier_sku');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN supplier_sku VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT ''Supplier product code'' AFTER supplier_name',
    'SELECT ''supplier_sku exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Supplier URL
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'supplier_url');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN supplier_url TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT ''Supplier product page'' AFTER supplier_sku',
    'SELECT ''supplier_url exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Supplier Lead Time
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'supplier_lead_time_days');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN supplier_lead_time_days INT NULL COMMENT ''Expected lead time from supplier'' AFTER supplier_url',
    'SELECT ''supplier_lead_time_days exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Fulfillment Notes
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'fulfillment_notes');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN fulfillment_notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT ''Special fulfillment instructions'' AFTER supplier_lead_time_days',
    'SELECT ''fulfillment_notes exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Track Inventory
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'track_inventory');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN track_inventory TINYINT(1) DEFAULT 1 COMMENT ''Track physical inventory (0 for infinite/dropship)'' AFTER fulfillment_notes',
    'SELECT ''track_inventory exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Display Unit
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'display_unit');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN display_unit VARCHAR(20) DEFAULT ''oz'' COMMENT ''Preferred unit for customer display'' AFTER track_inventory',
    'SELECT ''display_unit exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PRODUCT IMAGES
-- ============================================================================

-- Primary Product Image
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'product_image');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN product_image VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT ''Primary product image URL/path'' AFTER display_unit',
    'SELECT ''product_image exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Product Image Gallery (JSON array of image URLs)
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'product_gallery');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN product_gallery JSON NULL COMMENT ''Additional product images (JSON array)'' AFTER product_image',
    'SELECT ''product_gallery exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Image Alt Text (for SEO and accessibility)
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'image_alt_text');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD COLUMN image_alt_text VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT ''Alt text for primary image (SEO/accessibility)'' AFTER product_gallery',
    'SELECT ''image_alt_text exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- INDEXES for Performance (check if exists)
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_product_type');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD INDEX idx_product_type (product_type)',
    'SELECT ''idx_product_type exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_is_liquid');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD INDEX idx_is_liquid (is_liquid)',
    'SELECT ''idx_is_liquid exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_is_dropship');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD INDEX idx_is_dropship (is_dropship)',
    'SELECT ''idx_is_dropship exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_track_inventory');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE products ADD INDEX idx_track_inventory (track_inventory)',
    'SELECT ''idx_track_inventory exists'' AS notice');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- DATA MIGRATION: Set defaults for existing products (safe update/insert rules)
-- ============================================================================

-- Update existing products only if columns exist and values are NULL
UPDATE products 
SET 
    product_type = COALESCE(product_type, 'manufactured_liquid'),
    is_liquid = COALESCE(is_liquid, 1),
    track_inventory = COALESCE(track_inventory, 1),
    display_unit = COALESCE(display_unit, 'fl_oz')
WHERE product_type IS NULL OR is_liquid IS NULL OR track_inventory IS NULL OR display_unit IS NULL;

-- Migrate existing size_oz to volume_fl_oz for liquid products (prevent duplicates)
UPDATE products 
SET 
    volume_fl_oz = size_oz,
    volume_ml = ROUND(size_oz * 29.5735, 3),
    volume_liters = ROUND(size_oz * 29.5735 / 1000, 4)
WHERE size_oz IS NOT NULL 
    AND size_oz > 0 
    AND is_liquid = 1 
    AND (volume_fl_oz IS NULL OR volume_fl_oz = 0);

-- ============================================================================
-- HELPER VIEW (drop and recreate to prevent errors)
-- ============================================================================

DROP VIEW IF EXISTS products_with_all_units;

CREATE OR REPLACE VIEW products_with_all_units AS
SELECT 
    p.*,
    -- Weight conversions
    CASE 
        WHEN weight_grams IS NOT NULL THEN weight_grams
        WHEN weight_oz IS NOT NULL THEN ROUND(weight_oz * 28.3495, 3)
        WHEN weight_kg IS NOT NULL THEN ROUND(weight_kg * 1000, 3)
        WHEN weight_mg IS NOT NULL THEN ROUND(weight_mg / 1000, 3)
        ELSE NULL
    END as calculated_weight_grams,
    
    -- Volume conversions
    CASE 
        WHEN volume_ml IS NOT NULL THEN volume_ml
        WHEN volume_fl_oz IS NOT NULL THEN ROUND(volume_fl_oz * 29.5735, 3)
        WHEN volume_liters IS NOT NULL THEN ROUND(volume_liters * 1000, 3)
        WHEN size_oz IS NOT NULL AND is_liquid = 1 THEN ROUND(size_oz * 29.5735, 3)
        ELSE NULL
    END as calculated_volume_ml,
    
    -- Display weight/volume in preferred unit
    CASE p.display_unit
        WHEN 'g' THEN CONCAT(COALESCE(weight_grams, ROUND(weight_oz * 28.3495, 3)), 'g')
        WHEN 'oz' THEN CONCAT(COALESCE(weight_oz, ROUND(weight_grams / 28.3495, 4)), 'oz')
        WHEN 'kg' THEN CONCAT(COALESCE(weight_kg, ROUND(weight_grams / 1000, 4)), 'kg')
        WHEN 'ml' THEN CONCAT(COALESCE(volume_ml, ROUND(volume_fl_oz * 29.5735, 3)), 'mL')
        WHEN 'L' THEN CONCAT(COALESCE(volume_liters, ROUND(volume_ml / 1000, 4)), 'L')
        WHEN 'fl_oz' THEN CONCAT(COALESCE(volume_fl_oz, ROUND(volume_ml / 29.5735, 4)), 'fl oz')
        ELSE 'N/A'
    END as display_size,
    
    -- Product type label
    CASE p.product_type
        WHEN 'manufactured_liquid' THEN '🧪 Manufactured Liquid'
        WHEN 'manufactured_solid' THEN '⚗️ Manufactured Solid'
        WHEN 'dropship' THEN '📦 Dropship'
        WHEN 'simple' THEN '📋 Simple'
        ELSE 'Unknown'
    END as product_type_label
FROM products p;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VALIDATION: Verify migration
-- ============================================================================

SELECT '=== MIGRATION COMPLETE ===' AS status;

SELECT 
    'Product Types' AS category,
    product_type,
    COUNT(*) as count,
    SUM(CASE WHEN is_liquid = 1 THEN 1 ELSE 0 END) as liquid_count,
    SUM(CASE WHEN is_dropship = 1 THEN 1 ELSE 0 END) as dropship_count
FROM products
GROUP BY product_type;

SELECT 
    'Sample Products' AS category,
    id,
    sku,
    name,
    product_type,
    is_liquid,
    COALESCE(volume_fl_oz, weight_oz, size_oz) as size,
    display_unit
FROM products
LIMIT 5;

-- ============================================================================
-- NOTES
-- ============================================================================
-- 1. Migration is IDEMPOTENT - safe to run multiple times
-- 2. All column additions check for existence before adding
-- 3. Uses binary-safe utf8mb4 character set
-- 4. Existing products default to 'manufactured_liquid'
-- 5. Prevents duplicate key errors with UPDATE WHERE checks
-- 6. View is dropped and recreated to prevent conflicts
-- ============================================================================
