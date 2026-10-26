-- Ensure every HTML template includes the agent signature block and tracking pixel placeholder
SET @agent_signature := '\n{{#if agent_name}}\n<div style="max-width:600px;margin:24px auto 0 auto;padding:24px 16px;font-family:-apple-system,BlinkMacSystemFont,''Segoe UI'',Roboto,Helvetica,Arial,sans-serif;color:#111827;line-height:1.6;">\n  <p style="margin:0 0 6px;font-size:14px;color:#374151;">Need help? Contact your Product sales rep directly:</p>\n  <p style="margin:0;font-size:14px;color:#1f2937;">\n    <strong>{{agent_name}}</strong><br>\n    <span style="color:#64748b;">Sales Representative, Product</span><br>\n    <span style="color:#64748b;">📧 {{agent_email}}</span>{{#if agent_phone}}<br><span style="color:#64748b;">📱 {{agent_phone}}</span>{{/if}}\n  </p>\n</div>\n{{/if}}';

UPDATE email_templates
SET html_body = CONCAT(html_body, @agent_signature)
WHERE html_body NOT LIKE '%{{#if agent_name}}%';

UPDATE email_templates
SET html_body = CONCAT(html_body, '\n{{tracking_pixel}}')
WHERE html_body NOT LIKE '%{{tracking_pixel}}%';




