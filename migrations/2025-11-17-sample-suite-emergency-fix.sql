-- 2025-11-17 Sample Suite Emergency Fix
-- Purpose: Create/verify ALL Sample Suite workflows (steps 101-111) and templates
-- Safe to run multiple times (idempotent)
-- Instructions: Run this in cPanel phpMyAdmin on foggksfx_product database

START TRANSACTION;

-- ============================================
-- STEP 1: Verify Sample Suite v1 chain exists
-- ============================================
INSERT INTO `email_workflow_chains` (`chain_name`, `description`, `is_active`, `created_at`)
SELECT 'Sample Suite v1', 'Automated follow-up sequence for sample kit offers', 1, NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_workflow_chains WHERE chain_name = 'Sample Suite v1'
);

-- ============================================
-- STEP 2: Create/Update Email Templates
-- ============================================

-- Template: sample-suite-kickoff-reminder (Step 110/111)
INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES (
    'sample-suite-kickoff-reminder',
    'Sample Suite · Kickoff Reminder',
    'Making sure you got this!',
    '<div style="font-family: ''Inter'', ''Segoe UI'', Helvetica, Arial, sans-serif; font-size: 16px; color: #111827; line-height: 1.6;"><p style="margin: 0 0 16px;">Hi <strong>{{recipient_name}}</strong>,</p><p style="margin: 0 0 16px;">Just making sure you saw this sample offer for the Blue 60 + Gold 120 kit plus the 1 gram premium product format dash. It takes a minute to reserve and ships the same day.</p><p style="margin: 0 0 16px;">Still zero friction:<br>– Track shipping and delivery updates<br>– Drop in the right ship-to contact or loop in the buyer<br>– MAP-friendly, COA-backed, and no credit card required</p><p style="margin: 0 0 16px; text-align:center;"><a href="{{promo_request_url}}" style="display:inline-block;background:#0f172a;color:#ffffff;padding:10px 24px;border-radius:999px;text-decoration:none;font-weight:600;">Reopen the Portal</a></p><p style="margin: 24px 0 0;">Talk soon,<br><strong>{{agent_name}}</strong><br><span style="color:#475569;">{{agent_email}} · {{agent_phone}}</span></p></div>',
    'Hi {{recipient_name}}, just making sure you saw the Blue / Gold sample kit plus the premium product format dash. It takes a minute to reserve and ships the same day. Grab it here: {{promo_request_url}} Talk soon, {{agent_name}} {{agent_email}} | {{agent_phone}}'
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template: sample-suite-offer-reminder-1 (Step 101)
INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES (
    'sample-suite-offer-reminder-1',
    'Sample Suite · Offer Reminder 1',
    'Blue 60 + Gold 120 kit still available',
    '<div style="font-family: ''Inter'', ''Segoe UI'', Helvetica, Arial, sans-serif; font-size: 16px; color: #111827; line-height: 1.6;"><p style="margin: 0 0 16px;">Hi <strong>{{recipient_name}}</strong>,</p><p style="margin: 0 0 16px;">Quick nudge that the Blue 60 + Gold 120 kit (plus the 1 gram premium product format dash) is still sitting in the portal for {{company_name}}. Every pouch is batch-tested, barcoded, and MAP protected so you''re never fighting discount bins.</p><p style="margin: 0 0 16px;">If you still want it, grab the link below, choose the blend/product format combo, and the system will send me the ship-to details—no payment info needed.</p><p style="margin: 0 0 16px; text-align:center;"><a href="{{promo_request_url}}" style="display:inline-block;background:#0f172a;color:#ffffff;padding:10px 24px;border-radius:999px;text-decoration:none;font-weight:600;">Request the Sample Kit</a></p><p style="margin: 24px 0 0;">Talk soon,<br><strong>{{agent_name}}</strong><br><span style="color:#475569;">{{agent_email}} · {{agent_phone}}</span></p></div>',
    'Hi {{recipient_name}}, quick nudge that the Blue 60 + Gold 120 kit plus the 1g product format dash is still available. Grab it here: {{promo_request_url}} - {{agent_name}}'
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template: sample-suite-offer-reminder-2 (Step 102)
INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES (
    'sample-suite-offer-reminder-2',
    'Sample Suite · Offer Reminder 2',
    'Before I recycle this sample slot',
    '<div style="font-family: ''Inter'', ''Segoe UI'', Helvetica, Arial, sans-serif; font-size: 16px; color: #111827; line-height: 1.6;"><p style="margin: 0 0 16px;">Hey <strong>{{recipient_name}}</strong>,</p><p style="margin: 0 0 16px;">Before I recycle this sample slot, double checking if you still want the Blue 60 / Gold 120 blends and the 1 gram premium product format dash. The portal link below lets you choose the combo, confirm the ship-to, and it alerts me instantly.</p><p style="margin: 0 0 16px; text-align:center;"><a href="{{promo_request_url}}" style="display:inline-block;background:#0f172a;color:#ffffff;padding:10px 24px;border-radius:999px;text-decoration:none;font-weight:600;">Claim the Sample Kit</a></p><p style="margin: 24px 0 0;">Thanks,<br><strong>{{agent_name}}</strong><br><span style="color:#475569;">{{agent_email}} · {{agent_phone}}</span></p></div>',
    'Hey {{recipient_name}}, before I recycle this sample slot, double checking if you still want the kit. Claim it here: {{promo_request_url}} - {{agent_name}}'
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template: sample-suite-offer-day5 (Step 103)
INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES (
    'sample-suite-offer-day5',
    'Sample Suite · Day 5 Offer',
    'Five days in — still tagged for you',
    '<div style="font-family: ''Inter'', ''Segoe UI'', Helvetica, Arial, sans-serif; font-size: 16px; color: #111827; line-height: 1.6;"><p style="margin: 0 0 16px;">Hi <strong>{{recipient_name}}</strong>,</p><p style="margin: 0 0 16px;">Five days in and the Blue 60 + Gold 120 + 1g premium product format kit is still tagged for {{company_name}} in the portal. I can toss in shelf talkers, QR reorder cards, and dosing cheat sheets so you can test sell-through fast.</p><p style="margin: 0 0 16px;">If you still want it, hit the link below and it''ll reserve the kit (or swap the blend/product format combo) instantly.</p><p style="margin: 0 0 16px; text-align:center;"><a href="{{promo_request_url}}" style="display:inline-block;background:#0f172a;color:#ffffff;padding:10px 24px;border-radius:999px;text-decoration:none;font-weight:600;">Reserve the Sample Kit</a></p><p style="margin: 24px 0 0;">Appreciate it,<br><strong>{{agent_name}}</strong><br><span style="color:#475569;">{{agent_email}} · {{agent_phone}}</span></p></div>',
    'Hi {{recipient_name}}, five days in and the kit is still tagged for you. Reserve it here: {{promo_request_url}} - {{agent_name}}'
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template: sample-suite-rotation-a (Step 104)
INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES (
    'sample-suite-rotation-a',
    'Sample Suite · Rotation A',
    'The Blue / Gold playbook',
    '<div style="font-family: ''Inter'', ''Segoe UI'', Helvetica, Arial, sans-serif; font-size: 16px; color: #111827; line-height: 1.6;"><p style="margin: 0 0 16px;">Hi <strong>{{recipient_name}}</strong>,</p><p style="margin: 0 0 16px;">Stores that run the Blue 60 + Gold 120 combo usually pair it with our branded dosing cards and a simple reorder QR so staff can close the sale in under a minute. You can grab that playbook (and the free kit) straight from the portal if you still need to feel it first.</p><p style="margin: 0 0 16px; text-align:center;"><a href="{{promo_request_url}}" style="display:inline-block;background:#0f172a;color:#ffffff;padding:10px 24px;border-radius:999px;text-decoration:none;font-weight:600;">Build the Shelf + Request Kit</a></p><p style="margin: 24px 0 0;">– {{agent_name}}<br><span style="color:#475569;">{{agent_email}} · {{agent_phone}}</span></p></div>',
    'Hi {{recipient_name}}, grab the Blue/Gold playbook and the free kit here: {{promo_request_url}} - {{agent_name}}'
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template: sample-suite-rotation-b (Step 105)
INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES (
    'sample-suite-rotation-b',
    'Sample Suite · Rotation B',
    'Margin breakdown ready',
    '<div style="font-family: ''Inter'', ''Segoe UI'', Helvetica, Arial, sans-serif; font-size: 16px; color: #111827; line-height: 1.6;"><p style="margin: 0 0 16px;">Hi <strong>{{recipient_name}}</strong>,</p><p style="margin: 0 0 16px;">Our intro bundle (Blue 60, Gold 120, plus the 1g premium product format dash) nets most shops 62–68% blended margin while keeping MAP intact. Every lot ships with matching COAs so your staff can defend pricing without breaking a sweat.</p><p style="margin: 0 0 16px;">If you still need to see it, the portal link below lets you request the kit + pull the margin breakdown in one spot.</p><p style="margin: 0 0 16px; text-align:center;"><a href="{{promo_request_url}}" style="display:inline-block;background:#0f172a;color:#ffffff;padding:10px 24px;border-radius:999px;text-decoration:none;font-weight:600;">Get the Kit + Margin Sheet</a></p><p style="margin: 24px 0 0;">Thanks,<br><strong>{{agent_name}}</strong><br><span style="color:#475569;">{{agent_email}} · {{agent_phone}}</span></p></div>',
    'Hi {{recipient_name}}, get the kit + margin sheet: {{promo_request_url}} - {{agent_name}}'
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template: sample-suite-rotation-c (Step 106)
INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES (
    'sample-suite-rotation-c',
    'Sample Suite · Rotation C',
    'Staff training bundle available',
    '<div style="font-family: ''Inter'', ''Segoe UI'', Helvetica, Arial, sans-serif; font-size: 16px; color: #111827; line-height: 1.6;"><p style="margin: 0 0 16px;">Hey <strong>{{recipient_name}}</strong>,</p><p style="margin: 0 0 16px;">I can bundle the Product sample with laminated dosing cards, staff cheat sheets, and a short video you can loop in-store. That way the team knows exactly how to position Blue vs Gold vs the premium product format dash.</p><p style="margin: 0 0 16px;">Grab it all through the portal link below—it collects the merch notes and ships the kit once you pick Blue, Gold, or the product format dash.</p><p style="margin: 0 0 16px; text-align:center;"><a href="{{promo_request_url}}" style="display:inline-block;background:#0f172a;color:#ffffff;padding:10px 24px;border-radius:999px;text-decoration:none;font-weight:600;">Request Kit + Merch Pack</a></p><p style="margin: 24px 0 0;">{{agent_name}}<br><span style="color:#475569;">{{agent_email}} · {{agent_phone}}</span></p></div>',
    'Hey {{recipient_name}}, request the kit + merch pack: {{promo_request_url}} - {{agent_name}}'
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template: sample-suite-click-probe (Step 107)
INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES (
    'sample-suite-click-probe',
    'Sample Suite · Click Probe',
    'What''s holding you back?',
    '<div style="font-family: ''Inter'', ''Segoe UI'', Helvetica, Arial, sans-serif; font-size: 16px; color: #111827; line-height: 1.6;"><p style="margin: 0 0 16px;">Hi <strong>{{recipient_name}}</strong>,</p><p style="margin: 0 0 16px;">Noticed you peeked at the sample details. Was there anything missing (compliance info, serving size, MAP rules) that kept you from grabbing the free Blue 60 / Gold 120 kit plus the 1 gram premium product format dash?</p><p style="margin: 0 0 16px;">If you''re ready, the portal below lets you choose the blend/product format combo and logs the request automatically—otherwise just tell me what''s holding you back and I''ll fix it.</p><p style="margin: 0 0 16px; text-align:center;"><a href="{{promo_request_url}}" style="display:inline-block;background:#0f172a;color:#ffffff;padding:10px 24px;border-radius:999px;text-decoration:none;font-weight:600;">Finish the Sample Request</a></p><p style="margin: 24px 0 0;">{{agent_name}}<br><span style="color:#475569;">{{agent_email}} · {{agent_phone}}</span></p></div>',
    'Hi {{recipient_name}}, noticed you checked the details. Ready to grab the kit? {{promo_request_url}} - {{agent_name}}'
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- ============================================
-- STEP 3: Create Workflow Steps (101-111)
-- ============================================

-- Step 101: Offer Reminder 1
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
    SELECT 1 FROM email_followup_workflows WHERE chain_step = 101 AND chain_id = c.id
  );

-- Step 102: Offer Reminder 2
INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 102 — Offer Reminder 2',
  'Second automated reminder before recycling sample slot.',
  'custom',
  48,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-offer-reminder-2' LIMIT 1),
  0,
  95,
  1,
  0,
  c.id,
  102
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE chain_step = 102 AND chain_id = c.id
  );

-- Step 103: Day 5 Offer
INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 103 — Offer Day 5',
  'Five-day reminder with shelf assets mention.',
  'custom',
  120,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-offer-day5' LIMIT 1),
  0,
  95,
  1,
  0,
  c.id,
  103
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE chain_step = 103 AND chain_id = c.id
  );

-- Step 104: Rotation A
INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 104 — Rotation A',
  'Weekly rotation: Blue/Gold playbook focus.',
  'custom',
  168,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-rotation-a' LIMIT 1),
  0,
  94,
  1,
  0,
  c.id,
  104
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE chain_step = 104 AND chain_id = c.id
  );

-- Step 105: Rotation B
INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 105 — Rotation B',
  'Weekly rotation: Margin breakdown focus.',
  'custom',
  168,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-rotation-b' LIMIT 1),
  0,
  94,
  1,
  0,
  c.id,
  105
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE chain_step = 105 AND chain_id = c.id
  );

-- Step 106: Rotation C
INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 106 — Rotation C',
  'Weekly rotation: Staff training bundle focus.',
  'custom',
  168,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-rotation-c' LIMIT 1),
  0,
  94,
  1,
  0,
  c.id,
  106
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE chain_step = 106 AND chain_id = c.id
  );

-- Step 107: Click Probe
INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 107 — Click Probe',
  'Sent after recipient clicks but does not complete request.',
  'custom',
  24,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-click-probe' LIMIT 1),
  0,
  96,
  1,
  0,
  c.id,
  107
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE chain_step = 107 AND chain_id = c.id
  );

-- Step 110: Kickoff Recheck 30m (engagement-based)
INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 110 — Kickoff Recheck 30m',
  'Sends reminder 30 minutes after kickoff is opened without clicking.',
  'custom',
  0.5,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-kickoff-reminder' LIMIT 1),
  0,
  97,
  1,
  0,
  c.id,
  110
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE chain_step = 110 AND chain_id = c.id
  );

-- Step 111: Kickoff Recheck 2h (time-based unconditional)
INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 111 — Kickoff Recheck 2h',
  'Sends reminder 2 hours after kickoff if not opened or clicked.',
  'custom',
  2,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-kickoff-reminder' LIMIT 1),
  0,
  97,
  1,
  0,
  c.id,
  111
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE chain_step = 111 AND chain_id = c.id
  );

-- ============================================
-- VERIFICATION QUERIES
-- ============================================
-- Run these after migration to verify success:
--
-- SELECT id, chain_name FROM email_workflow_chains WHERE chain_name = 'Sample Suite v1';
-- SELECT id, name, chain_step FROM email_followup_workflows WHERE chain_step BETWEEN 101 AND 111 ORDER BY chain_step;
-- SELECT slug, name FROM email_templates WHERE slug LIKE 'sample-suite-%' ORDER BY slug;

COMMIT;

