-- 2025-11-15 — Sample Suite v2 Enhancements
-- Purpose: extend enrollment tracking, add engagement logging, seed new templates + workflows
-- Notes: Binary-safe, idempotent via IF NOT EXISTS + ON DUPLICATE KEY

START TRANSACTION;

-- ============================================
-- Extend sample_suite_enrollments with attempt + behavior tracking
-- ============================================
ALTER TABLE `sample_suite_enrollments`
ADD COLUMN IF NOT EXISTS `attempt_count` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1 AFTER `current_step_number`,
ADD COLUMN IF NOT EXISTS `max_attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 10 AFTER `attempt_count`,
ADD COLUMN IF NOT EXISTS `rotation_cursor` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 AFTER `max_attempts`,
ADD COLUMN IF NOT EXISTS `last_behavior` VARCHAR(50) DEFAULT NULL AFTER `next_step_scheduled_for`,
ADD COLUMN IF NOT EXISTS `last_engagement` JSON NULL AFTER `last_behavior`,
ADD COLUMN IF NOT EXISTS `last_engaged_at` DATETIME NULL AFTER `last_engagement`,
ADD COLUMN IF NOT EXISTS `sale_detected_at` DATETIME NULL AFTER `last_engaged_at`,
ADD COLUMN IF NOT EXISTS `unsubscribe_at` DATETIME NULL AFTER `sale_detected_at`,
ADD COLUMN IF NOT EXISTS `behavior_state` JSON NULL AFTER `metadata`,
ADD COLUMN IF NOT EXISTS `finalized_reason` VARCHAR(120) DEFAULT NULL AFTER `behavior_state`,
ADD COLUMN IF NOT EXISTS `final_attempt_at` DATETIME NULL AFTER `finalized_reason`;

-- ============================================
-- Extend step logs for richer analytics
-- ============================================
ALTER TABLE `sample_suite_step_logs`
ADD COLUMN IF NOT EXISTS `attempt_number` TINYINT(3) UNSIGNED DEFAULT NULL AFTER `step_number`,
ADD COLUMN IF NOT EXISTS `sent_email_id` INT(11) UNSIGNED DEFAULT NULL AFTER `agent_email_id`,
ADD COLUMN IF NOT EXISTS `event_context` JSON NULL AFTER `notes`,
ADD KEY IF NOT EXISTS `idx_enrollment_status` (`enrollment_id`, `status`);

-- ============================================
-- Engagement history table
-- ============================================
CREATE TABLE IF NOT EXISTS `sample_suite_engagements` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `enrollment_id` BIGINT(20) UNSIGNED NOT NULL,
  `sample_request_id` BIGINT(20) UNSIGNED NOT NULL,
  `agent_email_id` INT(11) UNSIGNED DEFAULT NULL,
  `event_type` ENUM('sent','open','click','reply','unsubscribe','sale','bounce') COLLATE utf8mb4_bin NOT NULL,
  `event_payload` JSON NULL,
  `event_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_engagement_enrollment` (`enrollment_id`, `event_type`),
  KEY `idx_engagement_request` (`sample_request_id`, `event_type`),
  CONSTRAINT `fk_sample_suite_engagement_enrollment`
    FOREIGN KEY (`enrollment_id`) REFERENCES `sample_suite_enrollments`(`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_sample_suite_engagement_request`
    FOREIGN KEY (`sample_request_id`) REFERENCES `sample_requests`(`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Seed 1g Premium product format sample product + inventory
-- ============================================
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
    3,
    'SAMPLE-product format-1G',
    'Premium product format Dash (1g Sample)',
    'Complimentary 1g premium Product format dash for tea bars + smoothie shops. Clean sourcing, lab-tested, and MAP protected.',
    60.00,
    1.00,
    0.00,
    0.00,
    200,
    25,
    1,
    0.035,
    120.00,
    0.00,
    0.00,
    0,
    1,
    120.00,
    0.50,
    0.20,
    0.15,
    0.25,
    0.10,
    1.20,
    1,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `product_group_id` = VALUES(`product_group_id`),
  `active` = 1,
  `updated_at` = NOW();

INSERT INTO `sample_inventory` (
    `product_id`, `quantity_available`, `quantity_reserved`,
    `quantity_shipped`, `low_stock_threshold`, `last_restocked_at`
)
SELECT 
    p.id,
    200,
    0,
    0,
    25,
    NOW()
FROM products p
WHERE p.sku = 'SAMPLE-product format-1G'
ON DUPLICATE KEY UPDATE
    `quantity_available` = VALUES(`quantity_available`),
    `last_restocked_at` = VALUES(`last_restocked_at`);

-- ============================================
-- Refresh kickoff template content + seed new template slugs
-- ============================================
INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES
('sample-suite-kickoff',
 'Sample Suite · Kickoff',
 'Want to claim your Product sample kit?',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hi {{recipient_name}},</p><p style="margin:0 0 16px;">I run the wholesale program at Product and spotted {{company_name}} while mapping independents in your area. We''re helping shops replace gas-station product with something you can actually vouch for — consistent product specification, COA-backed, and MAP enforced.</p><div style="margin:0 0 16px;padding:18px;border-radius:12px;border:1px solid #e2e8f0;background:#f8fafc;"><p style="margin:0 0 8px;font-weight:600;color:#0f172a;">Free kit waiting for you:</p><ul style="margin:0;padding-left:20px;color:#475569;"><li>Blue 60 blend — clean daily wellness baseline.</li><li>Gold 120 blend — premium ritual for regulars moving up from counter jars.</li><li>1g premium Product format dash — perfect for tea or smoothie bars.</li><li>No credit card required — just tell me where to ship it.</li></ul></div><p style="margin:0 0 16px;">Reply “YES” with the best shipping contact (or loop in the buyer) and I''ll drop it in the mail today. If it''s not a fit, just give me a thumbs down and I''ll stop the reminders.</p><p style="margin:24px 0 0;">Cheers,<br><strong>{{agent_name}}</strong><br><span style="color:#475569;">{{agent_email}} · {{agent_phone}}</span></p></div>',
 'Hi {{recipient_name}},\n\nI have a complimentary Product kit (Blue 60, Gold 120, plus a 1g premium product format dash) ready for {{company_name}}. Reply “YES” with the ship-to contact and I''ll send it — no card needed.\n\nThanks,\n{{agent_name}}\n{{agent_email}} · {{agent_phone}}'),
('sample-suite-offer-reminder-1',
 'Sample Suite · Offer Reminder 1',
 'Your free Product sample is still open',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hi {{recipient_name}},</p><p style="margin:0 0 16px;">Quick nudge that the Blue 60 + Gold 120 kit (plus the 1 gram premium product format dash) is still parked on my desk for {{company_name}}. Every pouch is batch-tested, barcoded, and MAP protected so you are not fighting discount bins.</p><p style="margin:0 0 16px;">Shoot back the right shipping contact and I''ll get it moving today — no payment info needed.</p><p style="margin:24px 0 0;">Talk soon,<br><strong>{{agent_name}}</strong></p></div>',
 'Still holding the Blue 60 / Gold 120 / 1g premium product format sample kit for {{company_name}}. Send the ship-to contact and I''ll mail it (no card needed). - {{agent_name}}'),
('sample-suite-offer-reminder-2',
 'Sample Suite · Offer Reminder 2',
 'Still want the Product sample kit?',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hey {{recipient_name}},</p><p style="margin:0 0 16px;">Before I reassign the kit, double checking if you still want the Blue 60 / Gold 120 blends and the 1g premium product format dash. Most stores use it to benchmark how clean product can feel on their shelves.</p><p style="margin:0 0 16px;">Reply with “YES” (or the right buyer) and I''ll send tracking. Otherwise just say the word and I''ll close the file.</p><p style="margin:24px 0 0;">Thanks,<br><strong>{{agent_name}}</strong></p></div>',
 'Need me to ship that Blue 60 / Gold 120 / 1g premium product format kit? Reply YES with the best ship-to contact. - {{agent_name}}'),
('sample-suite-offer-day5',
 'Sample Suite · Offer Reminder Day 5',
 'Want me to reserve the Product kit?',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hi {{recipient_name}},</p><p style="margin:0 0 16px;">Five days in and I still have a Blue 60 + Gold 120 + 1g premium product format kit tagged for {{company_name}}. I can even include shelf talkers and QR reorder cards if you want to test sell-through fast.</p><p style="margin:0 0 16px;">Should I keep it on hold or move it to the next store on the list?</p><p style="margin:24px 0 0;">Appreciate it,<br><strong>{{agent_name}}</strong></p></div>',
 'Still holding your Product kit (Blue 60 / Gold 120 / 1g product format). Want me to keep it reserved or pass it along?'),
('sample-suite-rotation-a',
 'Sample Suite · Rotation A',
 '90 seconds to restock your product shelf?',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hi {{recipient_name}},</p><p style="margin:0 0 16px;">Stores that run the Blue 60 + Gold 120 combo usually pair it with our branded dosing cards and a simple reorder QR so staff can close the sale in under a minute. Happy to send that playbook plus the free kit if you still need to feel it first.</p><p style="margin:0 0 16px;">Want me to email the quick shelf build?</p><p style="margin:24px 0 0;">- {{agent_name}}</p></div>',
 'Want the Product shelf playbook (Blue 60 + Gold 120 + 1g dash) plus the free kit? Happy to send — just say go.'),
('sample-suite-rotation-b',
 'Sample Suite · Rotation B',
 'Wholesale margins with proof-of-product specification?',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hi {{recipient_name}},</p><p style="margin:0 0 16px;">Our intro bundle (Blue 60, Gold 120, premium product format dash) nets most shops 62–68% blended margin while keeping MAP intact. Every lot ships with matching COAs so your staff can defend pricing without breaking a sweat.</p><p style="margin:0 0 16px;">Want me to send the sample and margin breakdown?</p><p style="margin:24px 0 0;">Thanks,<br><strong>{{agent_name}}</strong></p></div>',
 'Blue 60 / Gold 120 kit + 1g product format = 62–68% blended margin with MAP protection. Need the sample + numbers?'),
('sample-suite-rotation-c',
 'Sample Suite · Rotation C',
 'Need dosing cards or staff scripts?',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hey {{recipient_name}},</p><p style="margin:0 0 16px;">Happy to bundle the Product sample with laminated dosing cards, staff cheat sheets, and a short video you can loop in-store. That way the team knows exactly how to position Blue vs Gold vs the premium product format dash.</p><p style="margin:0 0 16px;">Want me to drop all of that in the mail?</p><p style="margin:24px 0 0;">{{agent_name}}</p></div>',
 'I can ship the sample plus dosing cards + staff scripts so the team can sell Blue 60 / Gold 120 confidently. Want me to send it?'),
('sample-suite-click-probe',
 'Sample Suite · Clicked Inquiry',
 'Saw you checking out the kit — need anything?',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hi {{recipient_name}},</p><p style="margin:0 0 16px;">Noticed you peeked at the sample details. Was there anything missing (compliance info, serving size, MAP rules) that kept you from grabbing the free Blue 60 / Gold 120 kit?</p><p style="margin:0 0 16px;">Reply with the sticking point and I''ll fix it — or drop the right ship-to contact and I''ll send it now.</p><p style="margin:24px 0 0;">{{agent_name}}</p></div>',
 'Saw you check out the Product sample info. Anything holding you back from grabbing the Blue 60 / Gold 120 kit + 1g product format?'),
('sample-suite-feedback',
 'Sample Suite · Post-Sample Feedback',
 'How did the Product sample perform?',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hi {{recipient_name}},</p><p style="margin:0 0 16px;">Curious how the Blue 60 / Gold 120 kit and the premium product format dash landed with your team and VIP shoppers. Any feedback on taste, shelf pull, or how it compares to what''s currently selling?</p><p style="margin:0 0 16px;">If you want to keep it rolling, I can send intro pricing + suggested reorder mix. If it missed the mark, let me know so I can tweak the next shipment.</p><p style="margin:24px 0 0;">Thanks,<br><strong>{{agent_name}}</strong></p></div>',
 'How did the Product sample (Blue 60 / Gold 120 / 1g product format) perform? Need tweaks or ready for pricing?'),
('sample-suite-human-escalation',
 'Sample Suite · Human Follow-up',
 'I’ll pause emails and have a teammate call you',
 '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p style="margin:0 0 16px;">Hi {{recipient_name}},</p><p style="margin:0 0 16px;">Looks like we haven''t been able to connect about the Product sample. I''m going to pause the emails and have someone on our wholesale team reach out directly to see what we can do better.</p><p style="margin:0 0 16px;">If you''d rather keep the convo here, just reply and I''ll jump back in.</p><p style="margin:24px 0 0;">Appreciate you,<br><strong>{{agent_name}}</strong></p></div>',
 'We haven’t connected on the Product sample yet, so I’ll pause the automation and have a teammate reach out personally. Reply if you prefer to handle it here. - {{agent_name}}')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `subject` = VALUES(`subject`),
  `html_body` = VALUES(`html_body`),
  `text_body` = VALUES(`text_body`);

-- ============================================
-- Seed new workflow steps for Sample Suite chain
-- ============================================
INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 101 — Offer Reminder 1',
  'First automated reminder to accept the free sample kit.',
  'custom',
  48,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-offer-reminder-1' LIMIT 1),
  0,
  95,
  1,
  0,
  c.id,
  101
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 101 — Offer Reminder 1'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 102 — Offer Reminder 2',
  'Second reminder emphasizing urgency.',
  'custom',
  48,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-offer-reminder-2' LIMIT 1),
  0,
  94,
  1,
  0,
  c.id,
  102
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 102 — Offer Reminder 2'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 103 — Day 5 Follow-up',
  'Five-day check to keep the offer fresh.',
  'custom',
  120,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-offer-day5' LIMIT 1),
  0,
  92,
  1,
  0,
  c.id,
  103
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 103 — Day 5 Follow-up'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 104 — Rotation A',
  'Ongoing nurture rotation (playbook).',
  'custom',
  168,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-rotation-a' LIMIT 1),
  0,
  88,
  1,
  0,
  c.id,
  104
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 104 — Rotation A'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 105 — Rotation B',
  'Ongoing nurture rotation (margin).',
  'custom',
  168,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-rotation-b' LIMIT 1),
  0,
  87,
  1,
  0,
  c.id,
  105
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 105 — Rotation B'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 106 — Rotation C',
  'Ongoing nurture rotation (training assets).',
  'custom',
  168,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-rotation-c' LIMIT 1),
  0,
  86,
  1,
  0,
  c.id,
  106
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 106 — Rotation C'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 107 — Click Probe',
  'Triggered when prospect clicks but does not claim sample.',
  'custom',
  24,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-click-probe' LIMIT 1),
  0,
  85,
  1,
  0,
  c.id,
  107
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 107 — Click Probe'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 108 — Sample Feedback',
  'Ask how the delivered sample performed.',
  'custom',
  120,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-feedback' LIMIT 1),
  0,
  75,
  1,
  0,
  c.id,
  108
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 108 — Sample Feedback'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 109 — Human Escalation',
  'Let the prospect know a teammate will reach out personally.',
  'custom',
  168,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-human-escalation' LIMIT 1),
  0,
  70,
  1,
  0,
  c.id,
  109
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 109 — Human Escalation'
  );

COMMIT;


