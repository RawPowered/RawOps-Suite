-- product-pack Trial Sample Request Integration Migration
-- Date: 2025-10-20
-- Purpose: Integrate product-pack trial promo system with Sample Request System
-- Binary-safe, ON DUPLICATE KEY UPDATE patterns, no duplicate errors

START TRANSACTION;

-- ============================================
-- EXTEND SAMPLE_REQUESTS TABLE
-- ============================================

-- Add promo_source field to track different promotional sources
ALTER TABLE `sample_requests`
ADD COLUMN IF NOT EXISTS `promo_source` VARCHAR(50) COLLATE utf8mb4_bin NULL COMMENT 'Promotional source: product-sample, manual, etc.',
ADD INDEX IF NOT EXISTS `idx_promo_source` (`promo_source`);

-- ============================================
-- LINK PROMO DEMO TABLES TO SAMPLE REQUESTS
-- ============================================

-- Add sample_request_id to promo_demo_leads
ALTER TABLE `promo_demo_leads`
ADD COLUMN IF NOT EXISTS `sample_request_id` BIGINT UNSIGNED NULL COMMENT 'Link to sample_requests table',
ADD INDEX IF NOT EXISTS `idx_sample_request_id` (`sample_request_id`),
ADD CONSTRAINT `fk_promo_demo_leads_sample_request` 
    FOREIGN KEY (`sample_request_id`) 
    REFERENCES `sample_requests`(`id`) 
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- Add sample_request_id to promo_demo_reviews
ALTER TABLE `promo_demo_reviews`
ADD COLUMN IF NOT EXISTS `sample_request_id` BIGINT UNSIGNED NULL COMMENT 'Link to sample_requests table',
ADD INDEX IF NOT EXISTS `idx_sample_request_id` (`sample_request_id`),
ADD CONSTRAINT `fk_promo_demo_reviews_sample_request` 
    FOREIGN KEY (`sample_request_id`) 
    REFERENCES `sample_requests`(`id`) 
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- ENSURE product-pack SAMPLE PRODUCTS EXIST
-- ============================================

-- Insert or update product-pack sample product (32/20 blend) - 80mg total attribute
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, 
    `attribute_content`, `weight_grams`, `wholesale_price`, `msrp`, 
    `stock_quantity`, `low_stock_threshold`, `active`, 
    `size_oz`, `attribute_per_oz`, `ml_per_10mg_serving`, 
    `oz_per_10mg_serving`, `drinks_per_oz`, `servings_10mg`, 
    `total_mg`, `material_cost`, `bottle_cost`, `label_cost`, 
    `labor_cost`, `mix_cost`, `total_production_cost`, 
    `is_sample`, `created_at`, `updated_at`
) VALUES (
    1, -- Products product group
    'SAMPLE-product-pack-32-20',
    'product-pack Demo Sample (32/20 Blend)',
    'Premium product-pack sample of our 32mg Green + 20mg Red blend. Perfect introduction to Product with 80mg total attribute. Ideal for demos and customer trials.',
    20.00, -- 20mg attribute per oz (80mg total / product-pack)
    120.00, -- ~product-pack in grams
    0.00, -- No wholesale price (promotional)
    0.00, -- No MSRP (promotional)
    100, -- Initial stock
    20, -- Low stock threshold
    1, -- Active
    4.00, -- product-pack size
    20.00, -- 20mg attribute per oz
    14.79, -- ml per 10mg serving (29.5735ml/oz * 0.5oz)
    0.5, -- oz per 10mg serving
    2, -- 2 servings per oz
    8, -- 8 total servings (product-pack * 2)
    80.00, -- Total 80mg attribute
    3.00, -- Material cost
    3.50, -- Bottle cost
    3.00, -- Label cost
    4.00, -- Labor cost
    1.50, -- Mix cost
    15.00, -- Total production cost
    1, -- Is sample
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `size_oz` = VALUES(`size_oz`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `servings_10mg` = VALUES(`servings_10mg`),
    `total_mg` = VALUES(`total_mg`),
    `is_sample` = 1,
    `active` = 1,
    `updated_at` = NOW();

-- Initialize sample inventory for product-pack product
INSERT INTO `sample_inventory` (`product_id`, `quantity_available`, `quantity_reserved`, `quantity_shipped`, `low_stock_threshold`, `last_restocked_at`)
SELECT 
    p.id,
    100, -- Initial quantity
    0, -- No reservations yet
    0, -- No shipments yet
    20, -- Low stock threshold
    NOW()
FROM `products` p
WHERE p.sku = 'SAMPLE-product-pack-32-20'
ON DUPLICATE KEY UPDATE
    `quantity_available` = VALUES(`quantity_available`),
    `last_restocked_at` = VALUES(`last_restocked_at`);

-- ============================================
-- EMAIL TEMPLATES FOR product-pack TRIAL WORKFLOW
-- ============================================

-- Template: Customer Account Created + Sample Coming
INSERT INTO `email_templates` (
    `slug`, `name`, `subject`, `html_body`, `text_body`,
    `category`, `scope`, `is_active`
) VALUES (
    'product-pack_trial_account_created',
    'product-pack Trial - Account Created',
    'Welcome to Product! Your Sample is Coming Soon 🎉',
    '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #059669; margin-bottom: 10px;">Welcome to Product, {recipient_name}!</h1>
            <p style="font-size: 18px; color: #666;">Your account has been created and your sample request is being processed! 🌟</p>
        </div>
        
        <div style="background: #f0fdf4; border-left: 4px solid #059669; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0; color: #047857;">What Happens Next?</h2>
            <ol style="line-height: 1.8;">
                <li>Your sales representative will review and approve your sample request</li>
                <li>We''ll prepare your premium product-pack Product sample for shipment</li>
                <li>You''ll receive tracking information when it ships</li>
                <li>Your sample should arrive within 3-5 business days</li>
            </ol>
        </div>
        
        <div style="background: #eff6ff; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #1e40af;">Your Sales Representative</h3>
            <p><strong>{agent_name}</strong> is here to help!</p>
            <p>📧 {agent_email}<br>📱 {agent_phone}</p>
        </div>
        
        <div style="background: #fef3c7; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #92400e;">Your Login Details</h3>
            <p>You can now log in to access your wholesale portal:</p>
            <p><strong>Email:</strong> {customer_email}<br>
            <strong>Portal:</strong> <a href="https://product.example/login.php">product.example/login.php</a></p>
        </div>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <p style="color: #666; font-size: 14px;">Thank you for choosing Product</p>
        </div>
    </div>
    </body></html>',
    'Welcome to Product, {recipient_name}!

Your account has been created and your sample request is being processed!

What Happens Next?
1. Your sales representative will review and approve your sample request
2. We''ll prepare your premium product-pack Product sample for shipment
3. You''ll receive tracking information when it ships
4. Your sample should arrive within 3-5 business days

Your Sales Representative:
{agent_name}
Email: {agent_email}
Phone: {agent_phone}

Your Login Details:
Email: {customer_email}
Portal: https://product.example/login.php

Thank you for choosing Product',
    'sample',
    'sales_agent',
    1
) ON DUPLICATE KEY UPDATE
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`),
    `is_active` = 1;

-- Template: Agent Notification - Customer Created Account
INSERT INTO `email_templates` (
    `slug`, `name`, `subject`, `html_body`, `text_body`,
    `category`, `scope`, `is_active`
) VALUES (
    'product-pack_trial_agent_account_created',
    'product-pack Trial - Agent Notification (Account Created)',
    'New Lead Activated: {customer_name} Created Account',
    '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #0369a1;">New Lead Activated! 🎯</h1>
        
        <div style="background: #f0f9ff; border-left: 4px solid #0369a1; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">Lead Details</h2>
            <p><strong>Name:</strong> {customer_name}</p>
            <p><strong>Company:</strong> {company_name}</p>
            <p><strong>Email:</strong> {customer_email}</p>
            <p><strong>Phone:</strong> {customer_phone}</p>
            <p><strong>Sample Requested:</strong> product-pack Demo (32/20 Blend)</p>
        </div>
        
        <div style="background: #fef3c7; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #92400e;">Action Required</h3>
            <p>A sample request has been created and is pending approval. Please review and approve in the operations dashboard.</p>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="https://product.example/operations/sample-fulfillment.php" style="display: inline-block; background: #0369a1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;">Review Sample Request</a>
        </div>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <p style="color: #666; font-size: 14px;">Product CRM Notification</p>
        </div>
    </div>
    </body></html>',
    'New Lead Activated! 

Lead Details:
Name: {customer_name}
Company: {company_name}
Email: {customer_email}
Phone: {customer_phone}
Sample Requested: product-pack Demo (32/20 Blend)

Action Required:
A sample request has been created and is pending approval. Please review and approve in the operations dashboard.

Review Sample Request: https://product.example/operations/sample-fulfillment.php

Product CRM Notification',
    'sample',
    'sales_agent',
    1
) ON DUPLICATE KEY UPDATE
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`),
    `is_active` = 1;

-- Template: Agent Notification - Review Submitted
INSERT INTO `email_templates` (
    `slug`, `name`, `subject`, `html_body`, `text_body`,
    `category`, `scope`, `is_active`
) VALUES (
    'product-pack_trial_agent_review_submitted',
    'product-pack Trial - Agent Notification (Review Submitted)',
    'Review Received: {customer_name} Rated {rating}/5',
    '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #059669;">Customer Review Received! ⭐</h1>
        
        <div style="background: #f0fdf4; border-left: 4px solid #059669; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">Review Details</h2>
            <p><strong>Customer:</strong> {customer_name}</p>
            <p><strong>Company:</strong> {company_name}</p>
            <p><strong>Rating:</strong> {rating}/5 ⭐</p>
            <p><strong>Review:</strong></p>
            <blockquote style="background: white; padding: 15px; border-left: 3px solid #059669; margin: 10px 0;">
                {review_text}
            </blockquote>
        </div>
        
        <div style="background: #fef3c7; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #92400e;">Follow-Up Action</h3>
            <p>A follow-up task has been created for 3 days from now to discuss ordering and the free 75mg sample.</p>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="https://product.example/onboarding/sales-agent/leads.php" style="display: inline-block; background: #059669; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;">View in CRM</a>
        </div>
    </div>
    </body></html>',
    'Customer Review Received!

Review Details:
Customer: {customer_name}
Company: {company_name}
Rating: {rating}/5
Review: {review_text}

Follow-Up Action:
A follow-up task has been created for 3 days from now to discuss ordering and the free 75mg sample.

View in CRM: https://product.example/onboarding/sales-agent/leads.php',
    'sample',
    'sales_agent',
    1
) ON DUPLICATE KEY UPDATE
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`),
    `is_active` = 1;

COMMIT;

-- ============================================
-- VERIFICATION QUERIES (for manual check)
-- ============================================

-- Check sample_requests table structure
-- DESCRIBE sample_requests;

-- Check promo tables have sample_request_id
-- DESCRIBE promo_demo_leads;
-- DESCRIBE promo_demo_reviews;

-- Check product-pack sample product exists
-- SELECT sku, name, size_oz, total_mg, is_sample FROM products WHERE sku = 'SAMPLE-product-pack-32-20';

-- Check sample inventory
-- SELECT p.sku, si.quantity_available, si.quantity_reserved 
-- FROM sample_inventory si 
-- JOIN products p ON p.id = si.product_id 
-- WHERE p.sku = 'SAMPLE-product-pack-32-20';

-- Check email templates
-- SELECT slug, name, is_active FROM email_templates WHERE slug LIKE 'product-pack_trial%';


