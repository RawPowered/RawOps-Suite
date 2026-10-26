-- Migration: Update Module 2 Products & product categories content to emphasize 2x product specification advantage
-- Date: 2025-09-13
-- Description: Updates training module content and title to highlight our core sales advantage

-- Update the products-product categories module to emphasize product specification advantage and 75mg warnings
UPDATE training_modules 
SET 
    title = 'Product Mastery: 2x product specification Advantage',
    content = 'Master our 2x superior product specification advantage - just 5mg of our catalog-ready blend equals or exceeds 10mg of standard product! Learn the complete Product lineup, why our 75mg varieties require experienced handlers due to exceptional product specification, and how to position catalog-ready quality for maximum sales success.'
WHERE slug = 'products-product categories';

-- Also update the products-and-product categories slug if it exists (backup entry)
UPDATE training_modules 
SET 
    title = 'Product Mastery: 2x product specification Advantage',
    content = 'Master our 2x superior product specification advantage - just 5mg of our catalog-ready blend equals or exceeds 10mg of standard product! Learn the complete Product lineup, why our 75mg varieties require experienced handlers due to exceptional product specification, and how to position catalog-ready quality for maximum sales success.'
WHERE slug = 'products-and-product categories';

-- Verify the updates
SELECT id, slug, title, content 
FROM training_modules 
WHERE slug IN ('products-product categories', 'products-and-product categories');
