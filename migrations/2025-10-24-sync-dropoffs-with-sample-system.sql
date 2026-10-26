-- Migration: Sync Drop-offs with Sample System
-- Date: 2025-10-24
-- Description: Link demo_dropoff_types to sample products and migrate drop-off workflow to sample_requests

-- Step 1: Add demo_type_id reference to sample products (for mapping)
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `demo_type_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Legacy demo_dropoff_types mapping',
ADD INDEX `idx_demo_type` (`demo_type_id`);

-- Step 2: Map existing demo types to sample products
-- Update this mapping based on your actual product IDs
-- Example: If product ID 25 is the product-pack 32/20 demo sample
UPDATE `products` p
JOIN `demo_dropoff_types` dt ON dt.product_code = p.sku
SET p.demo_type_id = dt.id
WHERE p.is_sample = 1;

-- Step 3: Add sample_request_id to crm_tasks for better tracking
ALTER TABLE `crm_tasks`
ADD COLUMN IF NOT EXISTS `sample_request_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Link to sample_requests for dropoff followups',
ADD INDEX `idx_sample_request` (`sample_request_id`);

-- Step 4: Migrate existing crm_demo_dropoffs to sample_requests (for historical completeness)
INSERT INTO `sample_requests` (
    agent_id, 
    lead_id, 
    account_id, 
    sample_product_id, 
    request_type,
    quantity, 
    recipient_name,
    status,
    agent_notes,
    requested_at,
    approved_at,
    created_at
)
SELECT 
    dd.agent_id,
    dd.lead_id,
    NULL as account_id,
    COALESCE(
        (SELECT p.id FROM products p WHERE p.demo_type_id = dd.demo_type_id AND p.is_sample = 1 LIMIT 1),
        25
    ) as sample_product_id,
    'drop_off' as request_type,
    1 as quantity,
    COALESCE(
        CONCAT(l.first_name, ' ', l.last_name),
        l.company_name,
        'Legacy Drop-off'
    ) as recipient_name,
    CASE 
        WHEN dd.demo_status IN ('confirmed', 'used') THEN 'delivered'
        WHEN dd.demo_status = 'unused' THEN 'approved'
        ELSE 'approved'
    END as status,
    dd.notes as agent_notes,
    dd.created_at as requested_at,
    dd.created_at as approved_at,
    dd.created_at as created_at
FROM `crm_demo_dropoffs` dd
LEFT JOIN `crm_leads` l ON l.id = dd.lead_id
WHERE dd.sample_request_id IS NULL
ON DUPLICATE KEY UPDATE sample_request_id = sample_request_id;

-- Step 5: Update crm_demo_dropoffs with sample_request_id links
UPDATE `crm_demo_dropoffs` dd
JOIN `sample_requests` sr ON (
    sr.agent_id = dd.agent_id 
    AND sr.lead_id = dd.lead_id
    AND sr.request_type = 'drop_off'
    AND DATE(sr.created_at) = dd.dropoff_date
)
SET dd.sample_request_id = sr.id
WHERE dd.sample_request_id IS NULL;

-- Step 6: Update existing follow-up tasks to link to sample_requests
UPDATE `crm_tasks` t
JOIN `crm_demo_dropoffs` dd ON dd.follow_up_task_id = t.id
SET t.sample_request_id = dd.sample_request_id
WHERE dd.sample_request_id IS NOT NULL 
AND t.sample_request_id IS NULL;

-- Step 7: Create view for unified drop-off history (backwards compatibility)
CREATE OR REPLACE VIEW `crm_dropoffs_unified` AS
SELECT 
    sr.id,
    sr.agent_id,
    sr.lead_id,
    sr.account_id,
    p.name as product_name,
    p.sku as product_code,
    dt.blend_type,
    sr.quantity,
    sr.recipient_name,
    sr.status,
    sr.agent_notes as notes,
    sr.requested_at as dropoff_date,
    DATE_ADD(DATE(sr.requested_at), INTERVAL 3 DAY) as follow_up_date,
    CASE
        WHEN sr.status = 'delivered' THEN 'completed'
        WHEN sr.status = 'cancelled' THEN 'cancelled'
        ELSE 'pending'
    END as follow_up_status,
    sr.created_at,
    sr.updated_at,
    'new_system' as source_system
FROM `sample_requests` sr
JOIN `products` p ON p.id = sr.sample_product_id
LEFT JOIN `demo_dropoff_types` dt ON dt.id = p.demo_type_id
WHERE sr.request_type = 'drop_off'

UNION ALL

SELECT 
    dd.id,
    dd.agent_id,
    dd.lead_id,
    NULL as account_id,
    dt.product_name,
    dt.product_code,
    dt.blend_type,
    1 as quantity,
    CONCAT(l.first_name, ' ', l.last_name) as recipient_name,
    CASE
        WHEN dd.demo_status IN ('confirmed', 'used') THEN 'delivered'
        WHEN dd.demo_status = 'unused' THEN 'approved'
        ELSE 'approved'
    END as status,
    dd.notes,
    dd.dropoff_date,
    dd.follow_up_date,
    dd.follow_up_status,
    dd.created_at,
    dd.updated_at,
    'legacy_system' as source_system
FROM `crm_demo_dropoffs` dd
LEFT JOIN `demo_dropoff_types` dt ON dt.id = dd.demo_type_id
LEFT JOIN `crm_leads` l ON l.id = dd.lead_id
WHERE dd.sample_request_id IS NULL;

-- Migration complete
-- New drop-offs should be created via sample_requests API
-- Historical data preserved in crm_demo_dropoffs with links to sample_requests



