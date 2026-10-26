-- ============================================================
-- Migration: Product Group Cost Templates & Active Ingredient System
-- Date: 2025-10-10
-- Purpose: Add flexible active ingredient tracking (product attribute and cannabinoid profiles),
--          custom cost factors, template cloning, and versatile product category support
--
-- Features:
-- - Binary-safe collations (utf8mb4_bin) for all keys to prevent foreign character issues
-- - ON DUPLICATE KEY UPDATE for all inserts to prevent duplication errors
-- - Checkbox-based active ingredient system (has_product, has_cannabinoids)
-- - Cannabinoid profile tracking (THCA, THC-D9, THC-D8, product, CBG, CBN)
-- - Custom cost factors JSON for extensibility
-- - Per-product cost overrides
-- ============================================================

-- ============================================================
-- PART 1: Product Group Active Ingredient & Cost Enhancements
-- ============================================================

-- Add active ingredient type flags (checkbox-based system)
ALTER TABLE product_groups 
ADD COLUMN IF NOT EXISTS has_product TINYINT(1) NOT NULL DEFAULT 0 
  COMMENT 'Product contains product (attribute-based calculations)';
  
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS has_cannabinoids TINYINT(1) NOT NULL DEFAULT 0 
  COMMENT 'Product contains cannabinoids (THC/product/CBN/CBG calculations)';
  
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS product_category VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL 
  COMMENT 'Category: products, gummies, creams, vitamins, drinks, apparel, custom';
  
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS template_source_id INT(11) DEFAULT NULL 
  COMMENT 'ID of group this was cloned from';
  
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS cost_formula_type VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT 'active-ingredient-based' 
  COMMENT 'Formula type: active-ingredient-based, fixed-cost, simple-markup, custom';

-- Add cannabinoid product specification tracking columns (group defaults)
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS default_thca_percent DECIMAL(5,2) DEFAULT NULL 
  COMMENT 'Default THCA percentage for products in this group';
  
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS default_thc_d9_percent DECIMAL(5,2) DEFAULT NULL 
  COMMENT 'Default Delta-9 THC percentage';
  
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS default_thc_d8_percent DECIMAL(5,2) DEFAULT NULL 
  COMMENT 'Default Delta-8 THC percentage';
  
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS default_cbd_percent DECIMAL(5,2) DEFAULT NULL 
  COMMENT 'Default product percentage';
  
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS default_cbg_percent DECIMAL(5,2) DEFAULT NULL 
  COMMENT 'Default CBG percentage';
  
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS default_cbn_percent DECIMAL(5,2) DEFAULT NULL 
  COMMENT 'Default CBN percentage';

-- Add custom cost factors JSON field for extensibility
ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS custom_cost_factors JSON DEFAULT NULL 
  COMMENT 'Custom cost factors as JSON: {"base_blend_cost": 5.50, "special_packaging": 1.25, ...}';

-- Add index for filtering by active ingredients
ALTER TABLE product_groups
ADD INDEX IF NOT EXISTS idx_active_ingredients (has_product, has_cannabinoids);

-- ============================================================
-- PART 2: Product-Level Active Ingredient & Override Columns
-- ============================================================

-- Add per-product cannabinoid tracking
ALTER TABLE products
ADD COLUMN IF NOT EXISTS thca_mg_per_unit DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'THCA milligrams per unit';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS thc_d9_mg_per_unit DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'Delta-9 THC milligrams per unit';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS thc_d8_mg_per_unit DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'Delta-8 THC milligrams per unit';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS cbd_mg_per_unit DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'product milligrams per unit';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS cbg_mg_per_unit DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'CBG milligrams per unit';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS cbn_mg_per_unit DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'CBN milligrams per unit';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS total_cannabinoids_mg DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'Total cannabinoid content per unit (auto-calculated)';

-- Add per-product cost overrides (can override group defaults)
ALTER TABLE products
ADD COLUMN IF NOT EXISTS override_material_cost DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'Override group material cost';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS override_bottle_cost DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'Override group bottle/container cost';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS override_label_cost DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'Override group label/packaging cost';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS override_labor_cost DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'Override group labor cost';
  
ALTER TABLE products
ADD COLUMN IF NOT EXISTS override_mix_cost DECIMAL(10,2) DEFAULT NULL 
  COMMENT 'Override group mix/prep cost';

-- Add custom product cost factors (extends group custom factors)
ALTER TABLE products
ADD COLUMN IF NOT EXISTS custom_cost_factors JSON DEFAULT NULL 
  COMMENT 'Product-specific custom cost factors as JSON';

-- ============================================================
-- PART 3: Update Existing Product Group
-- ============================================================

-- Safe update for existing Product group with new flags
UPDATE product_groups 
SET 
  has_product = 1,
  has_cannabinoids = 0,
  product_category = 'products',
  cost_formula_type = 'active-ingredient-based',
  updated_at = CURRENT_TIMESTAMP
WHERE group_key = 'product-products';

-- ============================================================
-- PART 4: Summary and Verification
-- ============================================================

SELECT 'Product Group Cost Templates Migration Complete' AS status;
SELECT COUNT(*) AS groups_with_categories FROM product_groups WHERE product_category IS NOT NULL;

-- END OF MIGRATION
SELECT CONCAT('✓ Product Group Cost Templates Migration Complete - ', NOW()) AS status;






