-- 2025-11-15 — Sample Suite Automation Chain
-- Purpose: Track sample email nurture enrollments and seed workflow templates

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `sample_suite_enrollments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sample_request_id` bigint(20) UNSIGNED NOT NULL,
  `chain_id` int(11) UNSIGNED NOT NULL,
  `lead_id` int(11) UNSIGNED DEFAULT NULL,
  `account_id` int(11) UNSIGNED DEFAULT NULL,
  `agent_id` int(11) UNSIGNED NOT NULL,
  `kickoff_email_id` int(11) UNSIGNED DEFAULT NULL,
  `status` enum('pending','active','paused','completed','cancelled') NOT NULL DEFAULT 'pending',
  `current_step_key` varchar(50) DEFAULT NULL,
  `current_step_number` int(11) DEFAULT 0,
  `last_step_sent_at` datetime DEFAULT NULL,
  `next_step_scheduled_for` datetime DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sample_enrollment` (`sample_request_id`),
  KEY `idx_chain_status` (`chain_id`,`status`),
  KEY `idx_agent_status` (`agent_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sample_suite_step_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `enrollment_id` bigint(20) UNSIGNED NOT NULL,
  `step_key` varchar(50) NOT NULL,
  `step_number` int(11) NOT NULL DEFAULT 0,
  `workflow_id` int(11) UNSIGNED DEFAULT NULL,
  `schedule_id` int(11) UNSIGNED DEFAULT NULL,
  `agent_email_id` int(11) UNSIGNED DEFAULT NULL,
  `status` enum('scheduled','sent','skipped','cancelled') NOT NULL DEFAULT 'scheduled',
  `scheduled_for` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_enrollment_step` (`enrollment_id`,`step_key`),
  KEY `idx_schedule_lookup` (`schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `sample_requests`
ADD COLUMN IF NOT EXISTS `suite_enrollment_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `expense_id`,
ADD COLUMN IF NOT EXISTS `suite_status` enum('not_started','pending','in_transit','post_delivery','completed','cancelled') NOT NULL DEFAULT 'not_started' AFTER `suite_enrollment_id`,
ADD COLUMN IF NOT EXISTS `suite_started_at` datetime DEFAULT NULL AFTER `suite_status`,
ADD COLUMN IF NOT EXISTS `suite_completed_at` datetime DEFAULT NULL AFTER `suite_started_at`;

ALTER TABLE `email_followup_schedule`
ADD COLUMN IF NOT EXISTS `payload_json` json DEFAULT NULL COMMENT 'Context payload for template rendering' AFTER `scheduled_via`;

INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
VALUES
('sample-suite-kickoff', 'Sample Suite · Kickoff', 'Your Product sample is underway', '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p>Hi {{recipient_name}},</p><p>Great news — I''m prepping your Product sample kit right now so you can see how clean sourcing + consistent product specification feels on your shelves.</p><p>Here''s what happens next:</p><ul style="padding-left:20px;margin:12px 0;"><li><strong>Today:</strong> I confirm the address and final details.</li><li><strong>Next:</strong> You''ll get a tracking update as soon as it leaves our fulfillment table.</li><li><strong>Delivery:</strong> Most shops receive the kit in 3-5 business days.</li></ul><p>While we get everything boxed up, let me know if you want merch hooks, shelf talkers, or a quick video you can play for staff.</p><p style="margin-top:24px;">Talk soon,<br><strong>{{agent_name}}</strong><br>{{agent_email}} · {{agent_phone}}</p><hr style="margin:32px 0;border:none;border-top:1px solid #e5e7eb;"><p style="font-size:13px;color:#6b7280;">P.S. If this isn''t the right contact, just reply with the correct buyer and I''ll loop them in.</p></div>', 'Hi {{recipient_name}}, your Product sample is being prepared now. I''ll confirm tracking and delivery next. Let me know if you need shelf assets. - {{agent_name}}'),
('sample-suite-shipped', 'Sample Suite · Shipped', 'Your Product sample is out the door', '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p>Hi {{recipient_name}},</p><p>Your Product sample kit just shipped. Keep an eye out for a compact branded box — everything inside is barcoded, dated, and batch-tested so you can see exactly what you''d be stocking.</p><div style="margin:16px 0;padding:16px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb;"><p style="margin:0 0 8px 0;"><strong>Quick facts</strong></p><ul style="padding-left:18px;margin:0;"><li>Carrier: <strong>{{sample_carrier}}</strong></li><li>Estimated arrival: <strong>{{sample_eta}}</strong></li><li>Tracking #: <strong>{{sample_tracking}}</strong> (reply if you want the live link)</li></ul></div><p>If you need to change who receives it or want me to include merchandising cheat sheets, reply and I''ll adjust before it lands.</p><p style="margin-top:24px;">Thanks again,<br><strong>{{agent_name}}</strong></p></div>', 'Your Product sample kit shipped via {{sample_carrier}}. ETA: {{sample_eta}}. Tracking: {{sample_tracking}}. Reply if you need anything else.'),
('sample-suite-delivered-checkin', 'Sample Suite · Delivered Check-in', 'Did the Product sample show up?', '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p>Hi {{recipient_name}},</p><p>Looks like the Product sample landed — just making sure it got to the right counter and wasn''t tucked behind the register.</p><p>Most retailers test it three ways:</p><ol style="padding-left:18px;margin:12px 0;"><li><strong>Team tasting:</strong> lets staff explain the feel.</li><li><strong>VIP list:</strong> send it with your top customers.</li><li><strong>Front counter placement:</strong> capture impulse buys.</li></ol><p>If you want a 10-minute huddle to review mix ratios or dosing cards, pick a time and I''ll hop on.</p><p style="margin-top:24px;">Cheering you on,<br><strong>{{agent_name}}</strong></p></div>', 'Just confirming the Product sample arrived and is on the counter. Need help with staff training or placement ideas? I''m here.'),
('sample-suite-day5-nurture', 'Sample Suite · Post-Delivery Nurture', 'Ready for a full Product shelf?', '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p>Hi {{recipient_name}},</p><p>By now a few customers have probably run through the sample. When stores reorder they usually pick a bundle that covers three shopper personas:</p><ul style="padding-left:18px;margin:12px 0;"><li><strong>Daily wellness:</strong> low/no caffeine users</li><li><strong>Performance:</strong> gym + jiu-jitsu crowd</li><li><strong>Premium ritual:</strong> customers trading up from gas-station product</li></ul><p>I can send a plug-and-play order builder with best sellers + MAP pricing if you want to see what that margin looks like on your shelves.</p><p>Should I email that over?</p><p style="margin-top:24px;">Thanks,<br><strong>{{agent_name}}</strong></p></div>', 'Checking in on the Product sample feedback. Want me to send the suggested opening order with MAP pricing and shelf talkers?'),
('sample-suite-last-call', 'Sample Suite · Final Nudge', 'Need anything before we recycle this sample thread?', '<div style="font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;color:#111827;line-height:1.6;"><p>Hi {{recipient_name}},</p><p>Before I close the loop on this sample request I wanted to make sure nothing slipped — sometimes the kit lands during inventory week and never makes it to the front.</p><p>If you''re still evaluating, I can extend the wholesale intro pricing through the end of the week. Otherwise let me know and I''ll pause everything on my side.</p><p style="margin-top:24px;">Appreciate the update either way,<br><strong>{{agent_name}}</strong></p></div>', 'Just checking once more on the Product sample. Want me to reserve intro pricing or should I close this out?')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `subject` = VALUES(`subject`),
  `html_body` = VALUES(`html_body`),
  `text_body` = VALUES(`text_body`);

INSERT INTO `email_workflow_chains` (`chain_name`, `description`, `owner_agent_id`, `root_workflow_id`, `is_active`)
SELECT 'Sample Suite v1', 'Five-step nurture built specifically for sample requests.', NULL, 0, 1
WHERE NOT EXISTS (
  SELECT 1 FROM `email_workflow_chains` WHERE `chain_name` = 'Sample Suite v1'
);

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 2 — Shipped',
  'Send shipment confirmation with expectations.',
  'custom',
  2,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-shipped' LIMIT 1),
  0,
  90,
  1,
  0,
  c.id,
  2
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 2 — Shipped'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 3 — Delivery Check-in',
  'Confirm the sample hit the counter and offer coaching.',
  'custom',
  24,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-delivered-checkin' LIMIT 1),
  0,
  80,
  1,
  0,
  c.id,
  3
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 3 — Delivery Check-in'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 4 — Merchandising Nurture',
  'Share reorder structure and merchandising tips.',
  'custom',
  120,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-day5-nurture' LIMIT 1),
  0,
  70,
  1,
  0,
  c.id,
  4
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 4 — Merchandising Nurture'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 5 — Final Nudge',
  'Close the loop with last-call CTA.',
  'custom',
  168,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-last-call' LIMIT 1),
  0,
  60,
  1,
  0,
  c.id,
  5
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 5 — Final Nudge'
  );

UPDATE `email_workflow_chains` c
SET c.root_workflow_id = (
    SELECT id FROM email_followup_workflows wf
    WHERE wf.chain_id = c.id
    ORDER BY wf.chain_step ASC
    LIMIT 1
)
WHERE c.chain_name = 'Sample Suite v1'
  AND (c.root_workflow_id IS NULL OR c.root_workflow_id = 0);

COMMIT;




