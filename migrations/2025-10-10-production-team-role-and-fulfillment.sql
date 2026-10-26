-- ============================================================
-- Migration: Production Team Role and Work Order Fulfillment
-- Date: 2025-10-10
-- Purpose: Add Production Team Member role and work order fulfillment tracking
--
-- Features:
-- - Binary-safe collations (utf8mb4_bin) for all keys
-- - ON DUPLICATE KEY UPDATE for conflict prevention
-- - New role: production_team
-- - Work order fulfillment fields (actual production, waste, variances)
-- - Inventory adjustments tracking
-- ============================================================

-- ============================================================
-- PART 1: Create Production Team Role
-- ============================================================

-- Insert production_team role (roles table only has id and name)
INSERT INTO roles (name)
VALUES ('production_team')
ON DUPLICATE KEY UPDATE 
    name = VALUES(name);

-- ============================================================
-- PART 2: Add Fulfillment Fields to Work Orders
-- ============================================================

-- Add fulfillment tracking columns
ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS actual_quantity_produced INT(11) DEFAULT NULL 
  COMMENT 'Actual quantity produced (may differ from quantity_to_produce)';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS quantity_waste INT(11) DEFAULT 0 
  COMMENT 'Units wasted during production';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS waste_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL 
  COMMENT 'Explanation for waste';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS variance_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL 
  COMMENT 'Explanation for production variance (over/under target)';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS completed_by BIGINT(20) UNSIGNED DEFAULT NULL 
  COMMENT 'User ID of production team member who completed the work order';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS fulfillment_notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL 
  COMMENT 'Production team notes about fulfillment';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS requires_ops_review TINYINT(1) DEFAULT 0 
  COMMENT 'Flags work order for operations manager review (variance detected)';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS ops_reviewed TINYINT(1) DEFAULT 0 
  COMMENT 'Operations manager has reviewed variance';

ALTER TABLE work_orders
ADD COLUMN IF NOT EXISTS ops_review_notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL 
  COMMENT 'Operations manager review notes';

-- ============================================================
-- PART 3: Create Inventory Adjustments Table
-- ============================================================

CREATE TABLE IF NOT EXISTS work_order_inventory_adjustments (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  work_order_id BIGINT(20) UNSIGNED NOT NULL,
  adjustment_type ENUM('ingredient', 'packaging', 'material') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  item_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'ID from respective inventory table',
  item_name VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  quantity_expected DECIMAL(12,4) NOT NULL COMMENT 'Expected quantity from recipe',
  quantity_actual DECIMAL(12,4) NOT NULL COMMENT 'Actual quantity used',
  quantity_variance DECIMAL(12,4) GENERATED ALWAYS AS (quantity_actual - quantity_expected) STORED,
  unit_of_measure VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  cost_per_unit DECIMAL(10,4) NOT NULL,
  total_cost_variance DECIMAL(10,2) GENERATED ALWAYS AS ((quantity_actual - quantity_expected) * cost_per_unit) STORED,
  status ENUM('missing', 'leftover', 'as-expected', 'wasted') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_work_order (work_order_id),
  KEY idx_adjustment_type (adjustment_type),
  KEY idx_status (status),
  CONSTRAINT fk_adjustment_work_order FOREIGN KEY (work_order_id) 
    REFERENCES work_orders(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 4: Create Work Order Waste Tracking Table
-- ============================================================

CREATE TABLE IF NOT EXISTS work_order_waste (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  work_order_id BIGINT(20) UNSIGNED NOT NULL,
  waste_type ENUM('production', 'spillage', 'defective', 'quality-control', 'other') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  quantity_wasted INT(11) NOT NULL,
  estimated_cost DECIMAL(10,2) NOT NULL COMMENT 'Estimated dollar value of waste',
  waste_description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_work_order (work_order_id),
  KEY idx_waste_type (waste_type),
  CONSTRAINT fk_waste_work_order FOREIGN KEY (work_order_id) 
    REFERENCES work_orders(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 5: Summary and Verification
-- ============================================================

SELECT 'Production Team Role and Fulfillment System Migration Complete' AS status;
SELECT * FROM roles WHERE name = 'production_team';
SELECT COUNT(*) AS work_orders_ready_for_fulfillment FROM work_orders WHERE status = 'scheduled';

-- END OF MIGRATION
SELECT CONCAT('✓ Production Fulfillment Migration Complete - ', NOW()) AS status;

