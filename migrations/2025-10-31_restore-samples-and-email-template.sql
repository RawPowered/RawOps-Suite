-- ================================================================
-- Restore Sample Products and Create Sample Offer Email Template
-- Date: 2025-10-31
-- 
-- Purpose:
-- 1. Ensure sample products exist and are active (Blue 60 and Gold 120)
-- 2. Create professional email template for offering samples
-- 
-- Rules: Binary-safe, ON DUPLICATE KEY UPDATE patterns
-- ================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_bin;

-- ================================================================
-- PART 1: RESTORE/CREATE SAMPLE PRODUCTS
-- ================================================================

-- Create product-pack Blue 60 Sample (60mg total attribute)
INSERT INTO `products` (
    `product_type`,
    `is_liquid`,
    `track_inventory`,
    `display_unit`,
    `product_group_id`,
    `sku`,
    `name`,
    `description`,
    `attribute_per_oz`,
    `size_oz`,
    `volume_fl_oz`,
    `volume_ml`,
    `attribute_content`,
    `servings_10mg`,
    `total_mg`,
    `wholesale_price`,
    `msrp`,
    `stock_quantity`,
    `low_stock_threshold`,
    `active`,
    `is_sample`,
    `bottle_cost`,
    `label_cost`,
    `labor_cost`,
    `mix_cost`,
    `material_cost`,
    `total_production_cost`,
    `created_at`,
    `updated_at`
) VALUES (
    'manufactured_liquid',
    1,
    1,
    'fl_oz',
    1, -- Products
    'SAMPLE-BLUE-60-product-pack',
    'Blue 60 Sample (product-pack)',
    'Perfect introductory sample with 60mg total attribute. Gentle 20mg attribute per fl oz for precise, controlled dosing. Ideal for customers new to product concentrates. catalog-ready attribute concentration.',
    20.00, -- 20mg attribute per oz
    3.00,  -- product-pack size
    3.00,  -- 3 fluid ounces
    88.72, -- ~product-pack in ml
    60.00, -- 60mg total attribute (20mg/oz * product-pack)
    60,    -- 60 servings at 10mg each
    60.00, -- 60mg total attribute
    0.00,  -- No wholesale price (promotional)
    0.00,  -- No MSRP (promotional)
    100,   -- Stock quantity
    15,    -- Low stock threshold
    1,     -- Active
    1,     -- Is sample
    2.50,  -- Bottle cost
    2.50,  -- Label cost
    2.50,  -- Labor cost
    0.75,  -- Mix cost
    1.50,  -- Material cost
    9.75,  -- Total production cost
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    `active` = 1,
    `stock_quantity` = GREATEST(`stock_quantity`, 50),
    `is_sample` = 1,
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `updated_at` = NOW();

-- Create product-pack Gold 120 Sample (120mg total attribute)
INSERT INTO `products` (
    `product_type`,
    `is_liquid`,
    `track_inventory`,
    `display_unit`,
    `product_group_id`,
    `sku`,
    `name`,
    `description`,
    `attribute_per_oz`,
    `size_oz`,
    `volume_fl_oz`,
    `volume_ml`,
    `attribute_content`,
    `servings_10mg`,
    `total_mg`,
    `wholesale_price`,
    `msrp`,
    `stock_quantity`,
    `low_stock_threshold`,
    `active`,
    `is_sample`,
    `bottle_cost`,
    `label_cost`,
    `labor_cost`,
    `mix_cost`,
    `material_cost`,
    `total_production_cost`,
    `created_at`,
    `updated_at`
) VALUES (
    'manufactured_liquid',
    1,
    1,
    'fl_oz',
    1, -- Products
    'SAMPLE-GOLD-120-product-pack',
    'Gold 120 Sample (product-pack)',
    'Our most popular strength in trial size with 120mg total attribute. Gold 120 delivers 40mg attribute per fl oz for experienced users. Balanced product specification with clean, consistent effects. Perfect for retail customers and wholesale buyers testing our premium formulation.',
    40.00, -- 40mg attribute per oz
    3.00,  -- product-pack size
    3.00,  -- 3 fluid ounces
    88.72, -- ~product-pack in ml
    120.00, -- 120mg total attribute (40mg/oz * product-pack)
    120,    -- 120 servings at 10mg each
    120.00, -- 120mg total attribute
    0.00,  -- No wholesale price (promotional)
    0.00,  -- No MSRP (promotional)
    100,   -- Stock quantity
    15,    -- Low stock threshold
    1,     -- Active
    1,     -- Is sample
    2.50,  -- Bottle cost
    2.50,  -- Label cost
    2.50,  -- Labor cost
    0.75,  -- Mix cost
    1.50,  -- Material cost
    9.75,  -- Total production cost
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    `active` = 1,
    `stock_quantity` = GREATEST(`stock_quantity`, 50),
    `is_sample` = 1,
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `updated_at` = NOW();

-- ================================================================
-- PART 2: CREATE SAMPLE OFFER EMAIL TEMPLATE
-- ================================================================

INSERT INTO `email_templates` (
    `slug`,
    `name`,
    `subject`,
    `html_body`,
    `text_body`,
    `category`,
    `scope`,
    `is_active`,
    `created_at`,
    `updated_at`
) VALUES (
    'sample_offer',
    'Sample Offer - Try Product Free',
    'Try Product Risk-Free - Complimentary Sample',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Try Product - Free Sample</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,''Segoe UI'',Roboto,Helvetica,Arial,sans-serif;background:#f8fafc;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;box-shadow:0 4px 12px rgba(0,0,0,0.08);">
                    
                    <!-- Greeting -->
                    <tr>
                        <td style="padding:32px 32px 0;">
                            <p style="margin:0;font-size:16px;color:#1f2937;line-height:1.6;">
                                Hi {recipient_name},
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Main Content -->
                    <tr>
                        <td style="padding:20px 32px;">
                            <p style="margin:0 0 16px;font-size:16px;color:#1f2937;line-height:1.6;">
                                I wanted to personally reach out and offer you a <strong>complimentary sample</strong> of our Product. Here''s why our customers love it:
                            </p>
                            
                            <div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:16px 20px;margin:24px 0;border-radius:6px;">
                                <ul style="margin:0;padding-left:20px;color:#166534;line-height:1.8;">
                                    <li><strong>catalog-ready quality information:</strong> Lab-tested for consistent attribute content</li>
                                    <li><strong>Precise Dosing:</strong> Know exactly what you''re getting every time</li>
                                    <li><strong>Clean Formula:</strong> No additives, no guesswork, just pure quality</li>
                                    <li><strong>Multiple Strengths:</strong> Choose from Blue 60 or Gold 120</li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Sample Options -->
                    <tr>
                        <td style="padding:0 32px 24px;">
                            <h2 style="margin:0 0 16px;font-size:20px;color:#1f2937;font-weight:700;">Choose Your Strength:</h2>
                            
                            <div style="display:grid;gap:12px;">
                                <!-- Blue 60 -->
                                <div style="border:2px solid #e5e7eb;border-radius:10px;padding:16px;background:#fafbfc;">
                                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                                        <div style="background:#3b82f6;color:#fff;font-weight:800;padding:8px 16px;border-radius:8px;font-size:16px;box-shadow:0 2px 6px rgba(59,130,246,0.4);">BLUE 60</div>
                                        <span style="color:#6b7280;font-size:14px;font-weight:600;">60mg total attribute (product-pack)</span>
                                    </div>
                                    <p style="margin:0;font-size:14px;color:#374151;line-height:1.5;">
                                        Perfect for beginners and those new to product concentrates. Gentle 20mg per oz for precise control.
                                    </p>
                                </div>
                                
                                <!-- Gold 120 -->
                                <div style="border:2px solid #f59e0b;border-radius:10px;padding:16px;background:#fffbeb;">
                                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                                        <div style="background:#f59e0b;color:#fff;font-weight:800;padding:8px 16px;border-radius:8px;font-size:16px;box-shadow:0 2px 6px rgba(245,158,11,0.4);">GOLD 120</div>
                                        <span style="color:#6b7280;font-size:14px;font-weight:600;">120mg total attribute (product-pack)</span>
                                        <span style="background:#16a34a;color:#fff;font-size:12px;padding:4px 10px;border-radius:12px;font-weight:700;box-shadow:0 2px 4px rgba(22,163,74,0.3);">MOST POPULAR</span>
                                    </div>
                                    <p style="margin:0;font-size:14px;color:#374151;line-height:1.5;">
                                        Our bestseller. Balanced 40mg per oz strength for experienced users. Great for retail and wholesale.
                                    </p>
                                </div>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- CTA Button -->
                    <tr>
                        <td align="center" style="padding:0 32px 32px;">
                            <a href="https://product.example/promo/product-sample/?agent={agent_id}" 
                               style="display:inline-block;background:#2E7D32;color:#ffffff;text-decoration:none;padding:16px 40px;border-radius:10px;font-weight:700;font-size:16px;box-shadow:0 4px 8px rgba(46,125,50,0.3);">
                                Request Your Free Sample
                            </a>
                            <p style="margin:12px 0 0;font-size:13px;color:#6b7280;">
                                No credit card required • Ships within 2 business days
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Why Product Section -->
                    <tr>
                        <td style="padding:0 32px 32px;">
                            <div style="background:#f8fafc;border-radius:10px;padding:20px;">
                                <h3 style="margin:0 0 12px;font-size:16px;color:#1f2937;font-weight:700;">Why Wholesale Partners Choose Product:</h3>
                                <ul style="margin:0;padding-left:20px;color:#374151;font-size:14px;line-height:1.8;">
                                    <li>Consistent quality across every batch</li>
                                    <li>Competitive wholesale pricing with volume discounts</li>
                                    <li>Fast fulfillment and reliable shipping</li>
                                    <li>Dedicated sales support team</li>
                                    <li>Lab reports available upon request</li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    
                    
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding:24px 32px;background:#f8fafc;border-radius:0 0 16px 16px;">
                            <p style="margin:0;font-size:12px;color:#6b7280;line-height:1.5;">
                                Product | Premium Wholesale Supplier<br>
                                Email: <a href="mailto:sample-003@example.com" style="color:#2E7D32;text-decoration:none;">sample-003@example.com</a><br>
                                <a href="https://product.example" style="color:#2E7D32;text-decoration:none;">product.example</a><br>
                                © 2025 Product. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>',
    'Try Product Risk-Free - Complimentary Sample

Hi {recipient_name},

I wanted to personally reach out and offer you a complimentary sample of our Product.

Why Our Customers Love Product:
- catalog-ready quality information: Lab-tested for consistent attribute content
- Precise Dosing: Know exactly what you''re getting every time
- Clean Formula: No additives, no guesswork, just pure quality
- Multiple Strengths: Choose from Blue 60 or Gold 120

Choose Your Strength:

BLUE 60 (60mg total attribute - product-pack)
Perfect for beginners and those new to product concentrates. Gentle 20mg per oz for precise control.

GOLD 120 (120mg total attribute - product-pack) - MOST POPULAR
Our bestseller. Balanced 40mg per oz strength for experienced users. Great for retail and wholesale.

Request your free sample: https://product.example/promo/product-sample/?agent={agent_id}

No credit card required • Ships within 2 business days

Why Wholesale Partners Choose Product:
- Consistent quality across every batch
- Competitive wholesale pricing with volume discounts
- Fast fulfillment and reliable shipping
- Dedicated sales support team
- Lab reports available upon request

---
Product | Premium Wholesale Supplier
Email: sample-003@example.com
Website: product.example
© 2025 Product. All rights reserved.',
    'sales',
    'sales_agent',
    1,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`),
    `is_active` = 1,
    `updated_at` = NOW();

-- ================================================================
-- VERIFICATION QUERIES
-- ================================================================

-- Check sample products
SELECT 
    id,
    sku,
    name,
    attribute_per_oz,
    size_oz,
    attribute_content AS total_attribute,
    is_sample,
    active,
    stock_quantity
FROM products
WHERE is_sample = 1
ORDER BY attribute_content ASC;

-- Check email template
SELECT 
    id,
    slug,
    name,
    scope,
    category,
    is_active
FROM email_templates
WHERE slug = 'sample_offer';

-- ================================================================
-- SUCCESS MESSAGE
-- ================================================================

SELECT 
    'Sample products and email template created successfully!' AS status,
    (SELECT COUNT(*) FROM products WHERE is_sample = 1 AND active = 1) AS active_samples,
    (SELECT COUNT(*) FROM email_templates WHERE slug = 'sample_offer') AS email_template;
