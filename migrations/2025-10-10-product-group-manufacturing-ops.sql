-- ============================================================
-- Migration: Product Group Manufacturing Operations System
-- Date: 2025-10-10
-- Purpose: Add comprehensive inventory tracking, work orders, recipes,
--          labor tracking, and batch code management for manufacturing operations
--
-- Features:
-- - Binary-safe collations (utf8mb4_bin) for all keys
-- - ON DUPLICATE KEY UPDATE patterns for conflict prevention
-- - Work orders with batch code generation (SKU-{P|T|RDT}-MMDDYYYY)
-- - Ingredient/packaging/materials inventory tracking
-- - Recipe system with JSON-based ingredient lists
-- - Labor hour tracking and cost calculations
-- - COA batch code management with file attachments
-- ============================================================

-- ============================================================
-- PART 1: Enable Recipe System in Product Groups
-- ============================================================

ALTER TABLE product_groups
ADD COLUMN IF NOT EXISTS has_recipe_system TINYINT(1) NOT NULL DEFAULT 0 
  COMMENT 'Enable recipe/manufacturing tracking for this group';

-- ============================================================
-- PART 2: Work Orders Table
-- ============================================================

CREATE TABLE IF NOT EXISTS work_orders (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  wo_number VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE COMMENT 'Work order number',
  product_group_id INT(11) NOT NULL,
  batch_code VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE COMMENT 'Batch code: SKU-{P|T|RDT}-MMDDYYYY',
  batch_type ENUM('production', 'testing', 'rnd') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'production' 
    COMMENT 'P=production, T=testing, RDT=research & development',
  status ENUM('draft', 'scheduled', 'in_progress', 'completed', 'cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'draft',
  quantity_to_produce INT(11) NOT NULL DEFAULT 0,
  quantity_produced INT(11) DEFAULT 0,
  production_date DATE DEFAULT NULL,
  completion_date DATE DEFAULT NULL,
  manufacturer_id BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'User ID of manufacturer',
  labor_hours DECIMAL(10,2) DEFAULT 0.00,
  labor_cost DECIMAL(10,2) DEFAULT 0.00,
  total_ingredient_cost DECIMAL(10,2) DEFAULT 0.00,
  total_packaging_cost DECIMAL(10,2) DEFAULT 0.00,
  total_material_cost DECIMAL(10,2) DEFAULT 0.00,
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_by BIGINT(20) UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wo_number (wo_number),
  UNIQUE KEY uk_batch_code (batch_code),
  KEY idx_product_group (product_group_id),
  KEY idx_batch_type (batch_type),
  KEY idx_status (status),
  KEY idx_production_date (production_date),
  CONSTRAINT fk_wo_product_group FOREIGN KEY (product_group_id) 
    REFERENCES product_groups(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 3: Ingredient Inventory Table
-- ============================================================

CREATE TABLE IF NOT EXISTS product_group_ingredients (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  product_group_id INT(11) NOT NULL,
  ingredient_name VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  ingredient_type VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL 
    COMMENT 'product, product, product-isolate, flavor, thca, delta-8, etc',
  stock_quantity DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  unit_of_measure VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL 
    COMMENT 'grams, kg, oz, lb, ml, liters',
  cost_per_unit DECIMAL(10,4) DEFAULT 0.0000,
  low_stock_threshold DECIMAL(10,2) DEFAULT 0.00,
  supplier VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_group (product_group_id),
  KEY idx_ingredient_type (ingredient_type),
  KEY idx_stock_quantity (stock_quantity),
  CONSTRAINT fk_ingredient_product_group FOREIGN KEY (product_group_id) 
    REFERENCES product_groups(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 4: Packaging Inventory Table
-- ============================================================

CREATE TABLE IF NOT EXISTS product_group_packaging (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  product_group_id INT(11) NOT NULL,
  packaging_item VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  packaging_type VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL 
    COMMENT 'bottle, lid, label, seal, box, jar, tube, etc',
  stock_quantity INT(11) NOT NULL DEFAULT 0,
  cost_per_unit DECIMAL(10,4) DEFAULT 0.0000,
  low_stock_threshold INT(11) DEFAULT 10,
  supplier VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_group (product_group_id),
  KEY idx_packaging_type (packaging_type),
  KEY idx_stock_quantity (stock_quantity),
  CONSTRAINT fk_packaging_product_group FOREIGN KEY (product_group_id) 
    REFERENCES product_groups(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 5: Materials Inventory Table
-- ============================================================

CREATE TABLE IF NOT EXISTS product_group_materials (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  product_group_id INT(11) NOT NULL,
  material_name VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  material_type VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL 
    COMMENT 'bubble-wrap, tape, shrink-wrap, packing-peanuts, boxes, etc',
  stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  unit_of_measure VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  cost_per_unit DECIMAL(10,4) DEFAULT 0.0000,
  low_stock_threshold DECIMAL(10,2) DEFAULT 0.00,
  supplier VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_group (product_group_id),
  KEY idx_material_type (material_type),
  KEY idx_stock_quantity (stock_quantity),
  CONSTRAINT fk_materials_product_group FOREIGN KEY (product_group_id) 
    REFERENCES product_groups(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 6: Recipe Table
-- ============================================================

CREATE TABLE IF NOT EXISTS product_group_recipes (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  product_group_id INT(11) NOT NULL,
  recipe_version VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT 'v1.0',
  recipe_name VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  recipe_steps TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ingredient_list JSON DEFAULT NULL COMMENT 'Array of {ingredient_id, quantity, unit}',
  packaging_list JSON DEFAULT NULL COMMENT 'Array of {packaging_id, quantity}',
  material_list JSON DEFAULT NULL COMMENT 'Array of {material_id, quantity, unit}',
  batch_yield INT(11) DEFAULT 1 COMMENT 'Number of units produced per batch',
  estimated_time_minutes INT(11) DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_group (product_group_id),
  KEY idx_active (active),
  KEY idx_recipe_version (recipe_version),
  CONSTRAINT fk_recipe_product_group FOREIGN KEY (product_group_id) 
    REFERENCES product_groups(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 7: Labor Tracking Table
-- ============================================================

CREATE TABLE IF NOT EXISTS product_group_labor_logs (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  work_order_id BIGINT(20) UNSIGNED NOT NULL,
  product_group_id INT(11) NOT NULL,
  manufacturer_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'User ID of worker/manufacturer',
  labor_date DATE NOT NULL,
  hours_worked DECIMAL(5,2) NOT NULL,
  hourly_rate DECIMAL(10,2) DEFAULT 0.00,
  total_cost DECIMAL(10,2) GENERATED ALWAYS AS (hours_worked * hourly_rate) STORED,
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_work_order (work_order_id),
  KEY idx_product_group (product_group_id),
  KEY idx_manufacturer (manufacturer_id),
  KEY idx_labor_date (labor_date),
  CONSTRAINT fk_labor_work_order FOREIGN KEY (work_order_id) 
    REFERENCES work_orders(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 8: Batch Code Tracking Table
-- ============================================================

CREATE TABLE IF NOT EXISTS batch_codes (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_code VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE,
  product_group_id INT(11) NOT NULL,
  work_order_id BIGINT(20) UNSIGNED DEFAULT NULL,
  batch_type ENUM('production', 'testing', 'rnd') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'production',
  sku_base VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Base SKU for batch code generation',
  production_date DATE NOT NULL,
  quantity_produced INT(11) DEFAULT 0,
  coa_attached TINYINT(1) DEFAULT 0 COMMENT 'Certificate of Analysis attached',
  coa_file_path VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active batch can be sold',
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_batch_code (batch_code),
  KEY idx_product_group (product_group_id),
  KEY idx_work_order (work_order_id),
  KEY idx_production_date (production_date),
  KEY idx_active (active),
  KEY idx_batch_type (batch_type),
  CONSTRAINT fk_batch_product_group FOREIGN KEY (product_group_id) 
    REFERENCES product_groups(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_batch_work_order FOREIGN KEY (work_order_id) 
    REFERENCES work_orders(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 9: Summary and Verification
-- ============================================================

SELECT 'Manufacturing Operations System Migration Complete' AS status;
SELECT 'Work Orders table created' AS work_orders_status;
SELECT 'Ingredient Inventory table created' AS ingredients_status;
SELECT 'Packaging Inventory table created' AS packaging_status;
SELECT 'Materials Inventory table created' AS materials_status;
SELECT 'Recipes table created' AS recipes_status;
SELECT 'Labor Tracking table created' AS labor_status;
SELECT 'Batch Codes table created' AS batch_codes_status;

-- END OF MIGRATION
SELECT CONCAT('✓ Manufacturing Operations Migration Complete - ', NOW()) AS status;






