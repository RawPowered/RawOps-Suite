-- ============================================================
-- CRITICAL PRICE CORRECTION
-- Date: October 10, 2025
-- Issue: Material cost calculation was incorrect in database
-- ============================================================

-- From spreadsheet analysis:
-- BLUE: Material cost should be $0.86 (currently $3.51)
-- GOLD: Material cost should be $1.41 (currently $10.53)
-- BLACK: Material cost should be $2.23 (currently $21.06)

-- The issue: We're calculating attribute content wrong
-- Spreadsheet shows "10mg attribute per 0.5oz serving"
-- Which means: BLUE has 10mg per 0.5oz = 20mg per 1oz (correct in attribute_per_oz)
-- But servings are calculated as 64 servings of 10mg each = 640mg total

-- CORRECT CALCULATION:
-- BLUE: 640mg total / 620mg per gram = 1.03 grams × $3.40 = $3.51 (this matches!)
-- Wait - the database shows $3.51 which IS correct mathematically

-- BUT the spreadsheet shows $0.86 for material cost
-- Let me recalculate from the spreadsheet perspective:

-- From images: Material cost each shows:
-- BLUE: $0.86
-- GOLD: $1.41  
-- BLACK: $2.23

-- These look like they might be per-SERVING costs, not per-BOTTLE
-- Let's verify: $0.86 × 64 servings = $55.04 (close to wholesale!)
-- No, that's not it either.

-- Let me look at the spreadsheet formula more carefully:
-- The spreadsheet shows these as "Materiel Cost each" under a serving calculation
-- This appears to be material cost PER SERVING (cost per 10mg)

-- So the BOTTLE material costs should be:
-- BLUE: $0.86 × 4.08 = $3.51 (wait, this is what we have!)
-- 
-- Actually, reviewing the images again:
-- "Materiel Cos" column shows:
-- BLUE: $0.86
-- GOLD: $1.41
-- BLACK: $2.23

-- And "Drink cost each" shows $2.36, $2.91, $3.73
-- Which is Material + Mix ($1.50)

-- So: BLUE drink cost = $0.86 + $1.50 = $2.36 ✓
-- This confirms $0.86 is material cost PER 10MG SERVING, not per bottle!

-- But our database stores material_cost as PER BOTTLE
-- So we need to calculate: cost_per_10mg_serving for material

-- Current database shows:
-- BLUE: material_cost = $3.51 (per bottle), cost_per_10mg_serving = $0.86 (wholesale/servings)
-- But cost_per_10mg_serving should be MATERIAL cost per serving!

-- THE FIX:
-- We need to store material_cost_per_serving separately
-- OR recalculate material_cost based on the correct serving calculation

-- From the spreadsheet "COST attribute" column (row with formulas):
-- BLUE: $3.51
-- GOLD: $10.53
-- BLACK: $21.06

-- These ARE the correct per-bottle material costs!
-- The $0.86, $1.41, $2.23 are indeed per-SERVING material costs

-- So our database IS CORRECT!
-- The confusion was interpreting the spreadsheet columns

-- However, let's verify BLACK wholesale price: $142.79 vs $142.80
UPDATE products 
SET wholesale_price = 142.79,
    msrp = 142.79
WHERE sku = 'BLACK-60-32';

-- Update bulk pricing to match exact spreadsheet values:
UPDATE bulk_pricing 
SET unit_price = 55.05
WHERE sku = 'BLUE-10-32' AND quantity_tier_name = 'X1';

UPDATE bulk_pricing 
SET unit_price = 50.64
WHERE sku = 'BLUE-10-32' AND quantity_tier_name = 'X10';

UPDATE bulk_pricing 
SET unit_price = 48.44
WHERE sku = 'BLUE-10-32' AND quantity_tier_name = 'X15';

UPDATE bulk_pricing 
SET unit_price = 90.15
WHERE sku = 'GOLD-30-32' AND quantity_tier_name = 'X1';

UPDATE bulk_pricing 
SET unit_price = 82.93
WHERE sku = 'GOLD-30-32' AND quantity_tier_name = 'X10';

UPDATE bulk_pricing 
SET unit_price = 79.33
WHERE sku = 'GOLD-30-32' AND quantity_tier_name = 'X15';

UPDATE bulk_pricing 
SET unit_price = 142.79
WHERE sku = 'BLACK-60-32' AND quantity_tier_name = 'X1';

UPDATE bulk_pricing 
SET unit_price = 131.37
WHERE sku = 'BLACK-60-32' AND quantity_tier_name = 'X10';

UPDATE bulk_pricing 
SET unit_price = 125.66
WHERE sku = 'BLACK-60-32' AND quantity_tier_name = 'X15';

SELECT '✓ Prices corrected to match spreadsheet exactly' AS status;



