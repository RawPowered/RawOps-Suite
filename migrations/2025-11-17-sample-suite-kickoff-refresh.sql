-- 2025-11-17 Refresh Sample Suite kickoff template
START TRANSACTION;

UPDATE email_templates
SET
    subject = 'Choose your Product sample kit',
    html_body = '<div style="font-family:&quot;Inter&quot;,&quot;Segoe UI&quot;,Helvetica,Arial,sans-serif;font-size:16px;color:#0f172a;line-height:1.6;background:#f1f5f9;padding:28px;"><div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(15,23,42,0.12);"><div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#e0f2fe;padding:32px 36px;"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;"><img src=&quot;https://product.example/assets/logo.svg&quot; alt=&quot;Product&quot; width=&quot;132&quot; style=&quot;display:block;&quot;><div style=&quot;text-transform:uppercase;font-size:12px;letter-spacing:0.2em;font-weight:600;color:#cbd5f5;&quot;>Sample Suite</div></div><h1 style=&quot;margin:0 0 12px 0;font-size:26px;color:#f8fafc;&quot;>Claim the Blue / Gold kit + premium product format dash</h1><p style=&quot;margin:0 0 20px 0;color:#cbd5f5;&quot;>Hi {{recipient_name}}, use the portal to pick the exact blends and shipping contact. It locks in compliance, updates my ops team in real time, and doesn’t require a card on file.</p><a href=&quot;{{promo_request_url}}&quot; style=&quot;display:inline-block;background:#facc15;color:#0f172a;font-weight:700;padding:12px 26px;border-radius:999px;text-decoration:none;&quot;>Open Portal &amp; Reserve Kit</a></div><div style=&quot;padding:28px 32px;&quot;><div style=&quot;display:flex;gap:12px;justify-content:space-between;flex-wrap:wrap;margin-bottom:16px;&quot;><div style=&quot;flex:1;min-width:140px;text-align:center;border:1px solid #e2e8f0;border-radius:16px;padding:16px;&quot;><img src=&quot;https://product.example/assets/img/product-placeholder.svg&quot; alt=&quot;Blue 60 product&quot; style=&quot;max-width:120px;width:100%;height:auto;margin-bottom:12px;&quot;><div style=&quot;font-weight:600;&quot;>Blue 60 Blend</div><div style=&quot;font-size:13px;color:#475569;&quot;>Clean daily wellness baseline.</div></div><div style=&quot;flex:1;min-width:140px;text-align:center;border:1px solid #e2e8f0;border-radius:16px;padding:16px;&quot;><img src=&quot;https://product.example/assets/img/product-placeholder.svg&quot; alt=&quot;Gold 120 product&quot; style=&quot;max-width:120px;width:100%;height:auto;margin-bottom:12px;&quot;><div style=&quot;font-weight:600;&quot;>Gold 120 Blend</div><div style=&quot;font-size:13px;color:#475569;&quot;>Premium ritual for counter upgrades.</div></div><div style=&quot;flex:1;min-width:140px;text-align:center;border:1px solid #e2e8f0;border-radius:16px;padding:16px;&quot;><img src=&quot;https://product.example/assets/img/product-placeholder.svg&quot; alt=&quot;Premium product format Dash&quot; style=&quot;max-width:120px;width:100%;height:auto;margin-bottom:12px;&quot;><div style=&quot;font-weight:600;&quot;>1g product format Dash</div><div style=&quot;font-size:13px;color:#475569;&quot;>Tea / smoothie bar friendly.</div></div></div><div style=&quot;border:1px solid #e2e8f0;border-radius:16px;padding:20px;margin-bottom:20px;&quot;><p style=&quot;margin:0 0 12px 0;font-weight:600;color:#0f172a;&quot;>Portal highlights:</p><div style=&quot;display:flex;flex-direction:column;gap:10px;color:#475569;&quot;><div>✔ Trackable—know when the kit ships and lands.</div><div>✔ Add delivery notes, POS asks, or CC the buyer.</div><div>✔ MAP-friendly and COA-backed. No credit card required.</div></div></div><div style=&quot;border:1px solid #e2e8f0;border-radius:16px;padding:20px;background:#f8fafc;margin-bottom:24px;&quot;><p style=&quot;margin:0 0 8px 0;font-weight:600;color:#0f172a;&quot;>How it works:</p><ul style=&quot;margin:0;padding-left:20px;color:#475569;&quot;><li>Tap the portal and choose Blue, Gold, or the product format dash.</li><li>Confirm the shipping contact (or loop in your buyer).</li><li>The system alerts me instantly so I can ship same day.</li></ul></div><p style=&quot;margin:0 0 18px 0;text-align:center;&quot;><a href=&quot;{{promo_request_url}}&quot; style=&quot;display:inline-block;background:#0f172a;color:#ffffff;padding:14px 30px;border-radius:12px;text-decoration:none;font-weight:600;&quot;>Launch Portal &amp; Choose My Kit</a></p><p style=&quot;margin:0;text-align:center;color:#64748b;font-size:13px;&quot;>Mobile-friendly link—feel free to forward directly to the decision maker.</p></div><div style=&quot;padding:20px 32px;border-top:1px solid #e2e8f0;background:#f8fafc;color:#475569;&quot;><p style=&quot;margin:0 0 6px 0;&quot;>Talk soon,</p><p style=&quot;margin:0;font-weight:600;color:#0f172a;&quot;>{{agent_name}}</p><p style=&quot;margin:0;font-size:14px;&quot;>{{agent_email}} · {{agent_phone}}</p><p style=&quot;margin:12px 0 0 0;font-size:12px;color:#94a3b8;&quot;>Product • Wholesale Sample Desk</p></div></div></div>',
    text_body = 'Hi {{recipient_name}},

Here''s what''s in the free Product kit:
- Blue 60 blend — clean daily wellness baseline
- Gold 120 blend — premium ritual for counter upgrades
- 1g premium product format dash — tea & smoothie bar friendly

Grab everything (no credit card required) inside the portal: {{promo_request_url}}

Talk soon,
{{agent_name}}
{{agent_email}} · {{agent_phone}}
Product • Wholesale Sample Desk'
WHERE slug = 'sample-suite-kickoff';

INSERT INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`)
SELECT
    'sample-suite-kickoff-reminder',
    'Sample Suite · Kickoff Reminder',
    'Making sure you got this!',
    et.html_body,
    et.text_body
FROM email_templates et
WHERE et.slug = 'sample-suite-kickoff'
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 110 — Kickoff Recheck 30m',
  'Resends the kickoff email within 30 minutes of the first open.',
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
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 110 — Kickoff Recheck 30m'
  );

INSERT INTO `email_followup_workflows`
(`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `owner_agent_id`, `template_id`, `allow_ai_generation`, `priority`, `is_active`, `has_conditions`, `chain_id`, `chain_step`)
SELECT
  'Sample Suite · Step 111 — Kickoff Recheck 4h',
  'Second resend of the kickoff email four hours after the open.',
  'custom',
  4,
  'global',
  NULL,
  (SELECT id FROM email_templates WHERE slug = 'sample-suite-kickoff-reminder' LIMIT 1),
  0,
  96,
  1,
  0,
  c.id,
  111
FROM email_workflow_chains c
WHERE c.chain_name = 'Sample Suite v1'
  AND NOT EXISTS (
    SELECT 1 FROM email_followup_workflows WHERE name = 'Sample Suite · Step 111 — Kickoff Recheck 4h'
  );

COMMIT;

