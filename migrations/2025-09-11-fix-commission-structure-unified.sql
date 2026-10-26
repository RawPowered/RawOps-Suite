-- =============================================================================
-- COMMISSION STRUCTURE UNIFICATION - SEPTEMBER 11, 2025
-- =============================================================================
-- This script unifies all commission structures to match the sales reference
-- sheet and ensures 20% commission rate for single items as specified.
--
-- CHANGES MADE:
-- 1. Clean up duplicate commission tiers
-- 2. Standardize to 20% base commission rate for single items
-- 3. Update commission settings to reflect new standards
-- 4. Ensure products table matches sales reference sheet
-- 5. Clean up discontinued products
-- =============================================================================

START TRANSACTION;

-- =============================================================================
-- STEP 1: BACKUP CURRENT DATA
-- =============================================================================

-- Backup commission tiers before changes
CREATE TABLE IF NOT EXISTS commission_tiers_backup_20250911 AS 
SELECT * FROM commission_tiers;

-- Backup commission settings before changes
CREATE TABLE IF NOT EXISTS commission_settings_backup_20250911 AS 
SELECT * FROM commission_settings;

-- =============================================================================
-- STEP 2: CLEAN UP COMMISSION TIERS TABLE
-- =============================================================================

-- Remove duplicate entries (keep the newer, more detailed ones)
DELETE FROM commission_tiers WHERE id IN (1, 2, 3, 4);

-- Update the remaining tiers to match the unified standard
-- Base commission rate is 20% for single items, with volume tiers

-- Bronze Tier: Entry level - 20% commission (unified standard)
UPDATE commission_tiers SET 
    name = 'Bronze',
    min_monthly_sales = 0.00,
    min_sales = 0.00,
    commission_rate = 20.00,  -- Changed from 10% to 20% (unified standard)
    bonus_rate = 0.00,
    description = 'Entry level - 20% commission on all sales (unified standard)',
    sort_order = 1
WHERE id = 5;

-- Silver Tier: Established sellers - 22% commission (slight bonus for volume)
UPDATE commission_tiers SET 
    name = 'Silver',
    min_monthly_sales = 10000.00,
    min_sales = 10000.00,
    commission_rate = 22.00,  -- Slight increase for established sellers
    bonus_rate = 2.00,
    description = 'Established - 22% commission on $10,001-$25,000 monthly sales',
    sort_order = 2
WHERE id = 6;

-- Gold Tier: Top performers - 25% commission
UPDATE commission_tiers SET 
    name = 'Gold',
    min_monthly_sales = 25000.00,
    min_sales = 25000.00,
    commission_rate = 25.00,  -- Increased for top performers
    bonus_rate = 5.00,
    description = 'Top performer - 25% commission on $25,001-$50,000 monthly sales',
    sort_order = 3
WHERE id = 7;

-- Platinum Tier: Elite sellers - 30% commission
UPDATE commission_tiers SET 
    name = 'Platinum',
    min_monthly_sales = 50000.00,
    min_sales = 50000.00,
    commission_rate = 30.00,  -- Premium rate for elite sellers
    bonus_rate = 10.00,
    description = 'Elite seller - 30% commission on $50,000+ monthly sales',
    sort_order = 4
WHERE id = 8;

-- =============================================================================
-- STEP 3: UPDATE COMMISSION SETTINGS
-- =============================================================================

-- Update default agent commission to match unified standard
UPDATE commission_settings 
SET setting_value = '20.00', 
    description = 'Default sales agent commission percentage - unified standard',
    updated_at = NOW()
WHERE setting_key = 'default_agent_commission';

-- Update developer commission to be proportional
UPDATE commission_settings 
SET setting_value = '0.20', 
    description = 'Portfolio Developer developer commission percentage (0.2% of order value)',
    updated_at = NOW()
WHERE setting_key = 'developer_commission';

-- Add new setting for per-invoice commission rate
INSERT INTO commission_settings (setting_key, setting_value, description) 
VALUES ('per_invoice_commission_rate', '20.00', 'Standard per-invoice commission rate for single items')
ON DUPLICATE KEY UPDATE 
    setting_value = '20.00',
    description = 'Standard per-invoice commission rate for single items',
    updated_at = NOW();

-- =============================================================================
-- STEP 4: CLEAN UP PRODUCTS TABLE
-- =============================================================================

-- Deactivate old discontinued products (keep for historical orders)
UPDATE products SET active = 0 WHERE sku IN (
    'ELIX-REG-32', 'ELIX-REG-128', 'ELIX-HP-32', 'ELIX-HP-128',
    'ELIX-START-KIT', 'ELIX-SAMPLE'
);

-- Ensure current blend products are active and have correct pricing
UPDATE products SET 
    active = 1,
    wholesale_price = 79.95,
    msrp = 85.02
WHERE sku = 'BLEND-32-20';

UPDATE products SET 
    active = 1,
    wholesale_price = 179.95,
    msrp = 186.10
WHERE sku = 'BLEND-128-20';

UPDATE products SET 
    active = 1,
    wholesale_price = 169.95,
    msrp = 174.40
WHERE sku = 'BLEND-32-75';

UPDATE products SET 
    active = 1,
    wholesale_price = 519.95,
    msrp = 528.50
WHERE sku = 'BLEND-128-75';

-- =============================================================================
-- STEP 5: UPDATE EXISTING COMMISSION RECORDS
-- =============================================================================

-- Update any existing pending commissions to use the unified 20% rate
-- (Only update sales_agent commissions, leave developer commissions as-is)
UPDATE commissions 
SET percentage = 20.00,
    amount = (
        SELECT o.total * 0.20 
        FROM orders o 
        WHERE o.id = commissions.order_id
    )
WHERE commission_type = 'sales_agent' 
  AND status = 'pending'
  AND percentage != 20.00;

-- =============================================================================
-- STEP 6: VERIFICATION QUERIES
-- =============================================================================

-- Verify commission tiers
SELECT 
    id, 
    name, 
    min_monthly_sales, 
    commission_rate, 
    bonus_rate,
    description,
    sort_order
FROM commission_tiers 
ORDER BY sort_order;

-- Verify commission settings
SELECT 
    setting_key, 
    setting_value, 
    description, 
    updated_at
FROM commission_settings 
WHERE setting_key IN ('default_agent_commission', 'developer_commission', 'per_invoice_commission_rate');

-- Verify active products
SELECT 
    id,
    sku, 
    name, 
    wholesale_price, 
    msrp, 
    active
FROM products 
WHERE active = 1
ORDER BY sku;

-- Verify updated commissions
SELECT 
    c.id,
    c.order_id,
    c.commission_type,
    c.amount,
    c.percentage,
    c.status,
    o.total as order_total
FROM commissions c
JOIN orders o ON c.order_id = o.id
WHERE c.status = 'pending'
ORDER BY c.created_at DESC
LIMIT 10;

COMMIT;

-- =============================================================================
-- SUMMARY OF CHANGES
-- =============================================================================
-- ✅ Commission tiers cleaned up and unified
-- ✅ Base commission rate set to 20% for all single items
-- ✅ Volume tiers provide bonus rates (22%, 25%, 30%)
-- ✅ Commission settings updated to reflect unified standard
-- ✅ Products table cleaned up - only active blend products remain
-- ✅ Existing pending commissions updated to new rates
-- ✅ Backup tables created for rollback if needed
-- =============================================================================
