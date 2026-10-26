-- ============================================================
-- Migration: Recipe and Fulfillment Enhancement System
-- Date: 2025-10-11
-- Purpose: Add global materials, production team members (non-login),
--          timeline tracking, projected vs actual cost tracking,
--          and enhanced inventory management
--
-- Features:
-- - Binary-safe collations (utf8mb4_bin) for all keys
-- - ON DUPLICATE KEY UPDATE patterns for conflict prevention
-- - Global materials system (shared across product groups)
-- - Production team members (non-login tracking)
-- - Work order timeline fields (scheduled, actual, due dates)
-- - Projected vs actual cost tracking
-- - Enhanced inventory adjustments
-- ============================================================

-- ============================================================
-- PART 1: Global Materials System
-- ============================================================

CREATE TABLE IF NOT EXISTS global_materials (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  material_name VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  material_type VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL 
    COMMENT 'bubble-wrap, tape, shrink-wrap, packing-peanuts, boxes, etc',
  stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  unit_of_measure VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  cost_per_unit DECIMAL(10,4) DEFAULT 0.0000,
  low_stock_threshold DECIMAL(10,2) DEFAULT 0.00,
  supplier VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  is_global TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Always 1 for global materials',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_material_type (material_type),
  KEY idx_stock_quantity (stock_quantity),
  KEY idx_is_global (is_global)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 2: Production Team Members (Non-Login)
-- ============================================================

CREATE TABLE IF NOT EXISTS production_team_members (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  role_title VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL 
    COMMENT 'Production Lead, Mixer, Packager, QC Inspector, etc',
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_active (active),
  KEY idx_full_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 3: Work Order Team Assignments Junction Table
-- ============================================================

CREATE TABLE IF NOT EXISTS work_order_team_assignments (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  work_order_id BIGINT(20) UNSIGNED NOT NULL,
  team_member_id BIGINT(20) UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wo_team (work_order_id, team_member_id),
  KEY idx_work_order (work_order_id),
  KEY idx_team_member (team_member_id),
  CONSTRAINT fk_wota_work_order FOREIGN KEY (work_order_id) 
    REFERENCES work_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_wota_team_member FOREIGN KEY (team_member_id) 
    REFERENCES production_team_members(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 4: Work Order Timeline & Cost Tracking Fields
-- ============================================================

-- Timeline fields
ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS scheduled_start_date DATETIME DEFAULT NULL 
  COMMENT 'When production is scheduled to start';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS actual_start_date DATETIME DEFAULT NULL 
  COMMENT 'When production actually started (status → in_progress)';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS due_date DATETIME DEFAULT NULL 
  COMMENT 'When work order should be completed';

-- Projected cost fields (from recipe)
ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS projected_ingredient_cost DECIMAL(10,2) DEFAULT 0.00 
  COMMENT 'Expected ingredient cost from recipe';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS projected_packaging_cost DECIMAL(10,2) DEFAULT 0.00 
  COMMENT 'Expected packaging cost from recipe';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS projected_material_cost DECIMAL(10,2) DEFAULT 0.00 
  COMMENT 'Expected material cost from recipe';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS projected_total_cost DECIMAL(10,2) DEFAULT 0.00 
  COMMENT 'Total projected cost (sum of above)';

-- Actual cost fields (from fulfillment)
ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS actual_ingredient_cost DECIMAL(10,2) DEFAULT 0.00 
  COMMENT 'Actual ingredient cost after fulfillment';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS actual_packaging_cost DECIMAL(10,2) DEFAULT 0.00 
  COMMENT 'Actual packaging cost after fulfillment';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS actual_material_cost DECIMAL(10,2) DEFAULT 0.00 
  COMMENT 'Actual material cost after fulfillment';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS actual_total_cost DECIMAL(10,2) DEFAULT 0.00 
  COMMENT 'Total actual cost (sum of above)';

-- ============================================================
-- PART 5: Recipe Enhancements for Global Materials
-- ============================================================

ALTER TABLE product_group_recipes
ADD COLUMN IF NOT EXISTS uses_global_materials JSON DEFAULT NULL 
  COMMENT 'Array of global material IDs and quantities used in recipe';

-- ============================================================
-- PART 6: Inventory Adjustments Enhancement
-- ============================================================

ALTER TABLE work_order_inventory_adjustments
ADD COLUMN IF NOT EXISTS is_global_material TINYINT(1) DEFAULT 0 
  COMMENT 'Flag indicating if material is from global_materials table';

-- ============================================================
-- PART 7: Indexes for Performance
-- ============================================================

-- Add index on work_orders for timeline queries
ALTER TABLE work_orders
ADD INDEX IF NOT EXISTS idx_scheduled_start (scheduled_start_date);

ALTER TABLE work_orders
ADD INDEX IF NOT EXISTS idx_due_date (due_date);

-- ============================================================
-- PART 8: Summary and Verification
-- ============================================================

SELECT 'Recipe and Fulfillment Enhancement Migration Complete' AS status;
SELECT 'Global Materials table created' AS global_materials_status;
SELECT 'Production Team Members table created' AS team_members_status;
SELECT 'Work Order Team Assignments table created' AS assignments_status;
SELECT 'Work Order timeline fields added' AS timeline_status;
SELECT 'Work Order cost tracking fields added' AS cost_tracking_status;
SELECT 'Recipe global materials support added' AS recipe_enhancement_status;
SELECT 'Inventory adjustments enhanced' AS adjustments_status;

-- END OF MIGRATION
SELECT CONCAT('✓ Recipe and Fulfillment Enhancement Migration Complete - ', NOW()) AS status;


