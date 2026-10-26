-- ============================================================================
-- Migration: Product Types, Multi-Unit Measurements, Dropship, and Images
-- Date: 2025-10-29
-- Purpose: Add all product enhancements in one simple migration
-- Rules: Binary safe, UTF8MB4, simple ALTER TABLE statements
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- Add columns (if they don't exist, MySQL will show warning but continue)
-- ============================================================================

-- Product Type & Classification
ALTER TABLE products ADD COLUMN product_type ENUM('manufactured_liquid', 'manufactured_solid', 'dropship', 'simple') DEFAULT 'manufactured_liquid' COMMENT 'Product category' AFTER id;
ALTER TABLE products ADD COLUMN is_liquid TINYINT(1) DEFAULT 1 COMMENT 'Liquid (1) or solid (0)' AFTER product_type;
ALTER TABLE products ADD COLUMN is_dropship TINYINT(1) DEFAULT 0 COMMENT 'External supplier fulfillment' AFTER is_liquid;
ALTER TABLE products ADD COLUMN track_inventory TINYINT(1) DEFAULT 1 COMMENT 'Track physical inventory' AFTER is_dropship;
ALTER TABLE products ADD COLUMN display_unit VARCHAR(20) DEFAULT 'fl_oz' COMMENT 'Customer display unit' AFTER track_inventory;

-- Weight Measurements (for solids)
ALTER TABLE products ADD COLUMN weight_grams DECIMAL(10,3) NULL COMMENT 'Weight in grams' AFTER display_unit;
ALTER TABLE products ADD COLUMN weight_oz DECIMAL(10,4) NULL COMMENT 'Weight in ounces' AFTER weight_grams;
ALTER TABLE products ADD COLUMN weight_kg DECIMAL(10,4) NULL COMMENT 'Weight in kilograms' AFTER weight_oz;
ALTER TABLE products ADD COLUMN weight_mg DECIMAL(12,3) NULL COMMENT 'Weight in milligrams' AFTER weight_kg;

-- Volume Measurements (for liquids)
ALTER TABLE products ADD COLUMN volume_ml DECIMAL(10,3) NULL COMMENT 'Volume in milliliters' AFTER weight_mg;
ALTER TABLE products ADD COLUMN volume_liters DECIMAL(10,4) NULL COMMENT 'Volume in liters' AFTER volume_ml;
ALTER TABLE products ADD COLUMN volume_fl_oz DECIMAL(10,4) NULL COMMENT 'Volume in fluid ounces' AFTER volume_liters;

-- Simple Cost
ALTER TABLE products ADD COLUMN unit_cost DECIMAL(10,2) NULL COMMENT 'Simple per-unit cost' AFTER volume_fl_oz;

-- Dropship/Supplier Fields
ALTER TABLE products ADD COLUMN supplier_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'External supplier name' AFTER unit_cost;
ALTER TABLE products ADD COLUMN supplier_sku VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Supplier product code' AFTER supplier_name;
ALTER TABLE products ADD COLUMN supplier_url TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Supplier product page' AFTER supplier_sku;
ALTER TABLE products ADD COLUMN supplier_lead_time_days INT NULL COMMENT 'Expected lead time from supplier' AFTER supplier_url;
ALTER TABLE products ADD COLUMN fulfillment_notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Special fulfillment instructions' AFTER supplier_lead_time_days;

-- Product Images
ALTER TABLE products ADD COLUMN product_image VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Primary product image URL/path' AFTER fulfillment_notes;
ALTER TABLE products ADD COLUMN product_gallery JSON NULL COMMENT 'Additional product images (JSON array)' AFTER product_image;
ALTER TABLE products ADD COLUMN image_alt_text VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Alt text for primary image (SEO/accessibility)' AFTER product_gallery;

-- ============================================================================
-- Add Indexes
-- ============================================================================

ALTER TABLE products ADD INDEX idx_product_type (product_type);
ALTER TABLE products ADD INDEX idx_is_liquid (is_liquid);
ALTER TABLE products ADD INDEX idx_is_dropship (is_dropship);
ALTER TABLE products ADD INDEX idx_track_inventory (track_inventory);

-- ============================================================================
-- Set Defaults for Existing Products
-- ============================================================================

UPDATE products 
SET 
    product_type = 'manufactured_liquid',
    is_liquid = 1,
    track_inventory = 1,
    display_unit = 'fl_oz'
WHERE product_type IS NULL;

-- Migrate existing size_oz to volume_fl_oz for liquid products
UPDATE products 
SET 
    volume_fl_oz = size_oz,
    volume_ml = ROUND(size_oz * 29.5735, 3),
    volume_liters = ROUND(size_oz * 29.5735 / 1000, 4)
WHERE size_oz IS NOT NULL 
    AND size_oz > 0 
    AND is_liquid = 1 
    AND volume_fl_oz IS NULL;

-- ============================================================================
-- Verification Query
-- ============================================================================

SELECT 
    'Migration Complete!' AS status,
    COUNT(*) AS total_products,
    SUM(CASE WHEN product_type = 'manufactured_liquid' THEN 1 ELSE 0 END) AS liquid_products,
    SUM(CASE WHEN product_image IS NOT NULL THEN 1 ELSE 0 END) AS products_with_images
FROM products;

-- ============================================================================
-- NOTES
-- ============================================================================
-- If you see "Duplicate column name" errors, that's OK - it means the column 
-- already exists. Just ignore those warnings and continue.
-- 
-- If you see "Can't DROP index" errors for indexes, that's also OK - means
-- the index already exists.
-- ============================================================================




