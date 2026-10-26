-- =============================================================================
-- COMMISSION TIER CORRECTION - SEPTEMBER 11, 2025
-- =============================================================================
-- This script corrects the commission tiers to match the actual spreadsheet
-- where commission rates DECREASE with volume (not increase as previously set)
--
-- CORRECT STRUCTURE:
-- Quantity 5:  20.00% commission
-- Quantity 10: 15.00% commission  
-- Quantity 15: 12.00% commission
-- Quantity 20: 10.00% commission
-- =============================================================================

START TRANSACTION;

-- =============================================================================
-- STEP 1: CORRECT THE COMMISSION TIERS
-- =============================================================================

-- Clear existing incorrect tiers and create correct volume-based structure
DELETE FROM commission_tiers;

-- Insert correct commission tiers based on quantity (volume discounts)
INSERT INTO commission_tiers (id, name, min_monthly_sales, min_sales, commission_rate, bonus_rate, description, sort_order, created_at) VALUES
(1, 'Single Item (1-4)', 0.00, 0.00, 20.00, 0.00, 'Single item orders - 20% commission rate', 1, NOW()),
(2, 'Small Volume (5-9)', 0.00, 0.00, 20.00, 0.00, 'Small volume orders (5-9 items) - 20% commission rate', 2, NOW()),
(3, 'Medium Volume (10-14)', 0.00, 0.00, 15.00, 0.00, 'Medium volume orders (10-14 items) - 15% commission rate', 3, NOW()),
(4, 'Large Volume (15-19)', 0.00, 0.00, 12.00, 0.00, 'Large volume orders (15-19 items) - 12% commission rate', 4, NOW()),
(5, 'Bulk Volume (20+)', 0.00, 0.00, 10.00, 0.00, 'Bulk volume orders (20+ items) - 10% commission rate', 5, NOW());

-- =============================================================================
-- STEP 2: UPDATE COMMISSION SETTINGS
-- =============================================================================

-- Update default agent commission to match single item rate
UPDATE commission_settings 
SET setting_value = '20.00', 
    description = 'Default sales agent commission percentage for single items',
    updated_at = NOW()
WHERE setting_key = 'default_agent_commission';

-- Add/update quantity-based commission settings
INSERT INTO commission_settings (setting_key, setting_value, description) 
VALUES 
('commission_qty_5_9', '20.00', 'Commission rate for 5-9 item orders'),
('commission_qty_10_14', '15.00', 'Commission rate for 10-14 item orders'),
('commission_qty_15_19', '12.00', 'Commission rate for 15-19 item orders'),
('commission_qty_20_plus', '10.00', 'Commission rate for 20+ item orders')
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    description = VALUES(description),
    updated_at = NOW();

-- =============================================================================
-- STEP 3: VERIFICATION QUERIES
-- =============================================================================

-- Verify commission tiers
SELECT 
    id, 
    name, 
    commission_rate,
    description,
    sort_order
FROM commission_tiers 
ORDER BY sort_order;

-- Verify commission settings
SELECT 
    setting_key, 
    setting_value, 
    description
FROM commission_settings 
WHERE setting_key LIKE 'commission%' OR setting_key = 'default_agent_commission'
ORDER BY setting_key;

COMMIT;

-- =============================================================================
-- SUMMARY OF CORRECT COMMISSION STRUCTURE
-- =============================================================================
-- ✅ Single Items (1-4):   20% commission
-- ✅ Small Volume (5-9):   20% commission  
-- ✅ Medium Volume (10-14): 15% commission
-- ✅ Large Volume (15-19):  12% commission
-- ✅ Bulk Volume (20+):     10% commission
-- 
-- This matches the spreadsheet where commission rates DECREASE with volume
-- =============================================================================
