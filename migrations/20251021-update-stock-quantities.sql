-- =====================================================================
-- Update Stock Quantities for 2oz Products
-- Date: 2025-10-21
-- Description: Set stock quantities for Product B and Product A 2oz bottles
--              47 Product B 2oz bottles + 47 Product A 2oz bottles = 94 total
-- =====================================================================

-- Update Product B 2oz stock (47 bottles)
UPDATE `products` 
SET `stock_quantity` = 47,
    `updated_at` = CURRENT_TIMESTAMP
WHERE `sku` = 'ELIX-Product B-2oz';

-- Update Product A 2oz stock (47 bottles)
UPDATE `products` 
SET `stock_quantity` = 47,
    `updated_at` = CURRENT_TIMESTAMP
WHERE `sku` = 'ELIX-Product A-2oz';

-- Set other sizes to 0 or low stock for now (can adjust as needed)
UPDATE `products` 
SET `stock_quantity` = 0,
    `updated_at` = CURRENT_TIMESTAMP
WHERE `sku` IN (
    'ELIX-Product B-8oz',
    'ELIX-Product B-16oz',
    'ELIX-Product B-32oz',
    'ELIX-Product A-8oz',
    'ELIX-Product A-16oz',
    'ELIX-Product A-32oz'
);

