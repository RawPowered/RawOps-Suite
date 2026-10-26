-- Create Payment Info Submitted Admin Email Template
-- Date: 2025-10-29
-- Purpose: Notify admins when a customer submits payment information for an order

START TRANSACTION;

-- Ensure payment_info_submitted event exists
INSERT INTO email_events (event_key, name, description, default_template_slug, scope, is_active, created_at)
VALUES ('payment_info_submitted', 'Payment Info Submitted', 'Sent to admins when customer submits payment information', 'payment-info-submitted', 'admin', 1, NOW())
ON DUPLICATE KEY UPDATE 
    name = VALUES(name),
    description = VALUES(description),
    default_template_slug = VALUES(default_template_slug),
    is_active = 1,
    updated_at = NOW();

-- Create payment-info-submitted email template
INSERT INTO email_templates (slug, name, subject, html_body, text_body, category, scope, is_active, created_at)
VALUES (
    'payment-info-submitted',
    'Payment Info Submitted (Admin Alert)',
    'Payment Info Received - Order #[ORDER_NUMBER]',
    '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff;">
    
    <!-- Header -->
    <div style="background: linear-gradient(135deg, #dc2626, #f59e0b); padding: 40px 20px; text-align: center;">
        <h1 style="color: white; margin: 0; font-size: 28px;">💳 Payment Information Received</h1>
        <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0; font-size: 14px;">Action Required - Process Payment</p>
    </div>
    
    <!-- Body -->
    <div style="background: #ffffff; padding: 32px 20px;">
        <p style="margin: 0 0 20px; font-size: 16px; color: #334155;">
            Hi <strong>[FIRST_NAME]</strong>,
        </p>
        
        <p style="margin: 0 0 24px; font-size: 16px; line-height: 1.6; color: #334155;">
            A customer has submitted payment information for their order. Please review and process the payment manually.
        </p>
        
        <!-- Order Summary -->
        <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #f59e0b; border-radius: 12px; padding: 24px; margin: 0 0 24px;">
            <h2 style="margin: 0 0 16px; color: #92400e; font-size: 20px;">Order #[ORDER_NUMBER]</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; color: #78350f; font-size: 14px;">Customer:</td>
                    <td style="padding: 8px 0; text-align: right; color: #78350f; font-weight: 600; font-size: 14px;">[CUSTOMER_NAME]</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #78350f; font-size: 14px;">Email:</td>
                    <td style="padding: 8px 0; text-align: right; color: #78350f; font-weight: 600; font-size: 14px;">[CUSTOMER_EMAIL]</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #78350f; font-size: 14px;">Payment Method:</td>
                    <td style="padding: 8px 0; text-align: right; color: #78350f; font-weight: 600; font-size: 14px;">[PAYMENT_METHOD_TYPE]</td>
                </tr>
                <tr style="border-top: 2px solid #f59e0b;">
                    <td style="padding: 12px 0 0; color: #78350f; font-size: 18px; font-weight: 700;">Total Amount:</td>
                    <td style="padding: 12px 0 0; text-align: right; color: #92400e; font-size: 24px; font-weight: 700;">$[ORDER_TOTAL]</td>
                </tr>
            </table>
        </div>
        
        <!-- Action Button -->
        <div style="text-align: center; margin: 0 0 24px;">
            <a href="[ADMIN_ORDER_URL]" style="display: inline-block; background: #dc2626; color: white; padding: 16px 48px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px;">
                View Order & Process Payment
            </a>
        </div>
        
        <div style="background: #fee2e2; border: 1px solid #dc2626; border-radius: 8px; padding: 16px; margin: 24px 0;">
            <p style="margin: 0; color: #991b1b; font-size: 14px; font-weight: 600;">
                ⚠️ SECURITY REMINDER
            </p>
            <p style="margin: 8px 0 0; color: #991b1b; font-size: 13px; line-height: 1.6;">
                Payment details are stored securely for manual processing. Process this payment as soon as possible and update the order status to "Paid" once complete.
            </p>
        </div>
        
        <p style="margin: 24px 0 0; font-size: 14px; color: #64748b; border-top: 1px solid #e5e7eb; padding-top: 20px;">
            This is an automated notification. Do not reply to this email.
        </p>
    </div>
    
    <!-- Footer -->
    <div style="background: #f8fafc; padding: 24px 20px; text-align: center; border-top: 1px solid #e5e7eb;">
        <p style="margin: 0; font-size: 12px; color: #94a3b8;">
            © 2025 Product Admin Portal
        </p>
    </div>
</div>',
    'Hi [FIRST_NAME],

A customer has submitted payment information for their order. Please review and process the payment manually.

Order #[ORDER_NUMBER]
- Customer: [CUSTOMER_NAME]
- Email: [CUSTOMER_EMAIL]
- Payment Method: [PAYMENT_METHOD_TYPE]
- Total Amount: $[ORDER_TOTAL]

View and process the payment here:
[ADMIN_ORDER_URL]

⚠️ SECURITY REMINDER: Payment details are stored securely for manual processing. Process this payment as soon as possible and update the order status to "Paid" once complete.

This is an automated notification.

© 2025 Product Admin Portal',
    'transactional',
    'admin',
    1,
    NOW()
)
ON DUPLICATE KEY UPDATE
    subject = VALUES(subject),
    html_body = VALUES(html_body),
    text_body = VALUES(text_body),
    is_active = 1,
    updated_at = NOW();

-- Wire event to template via notification rule
INSERT INTO email_notification_rules (event_key, template_id, is_active, created_at)
SELECT 'payment_info_submitted', t.id, 1, NOW()
FROM email_templates t
WHERE t.slug = 'payment-info-submitted'
LIMIT 1
ON DUPLICATE KEY UPDATE 
    template_id = VALUES(template_id),
    is_active = 1,
    updated_at = NOW();

COMMIT;

-- Verification
SELECT 'Payment info submitted admin email template created successfully' as status;
SELECT id, slug, name, is_active FROM email_templates WHERE slug = 'payment-info-submitted';
SELECT r.id, r.event_key, r.is_active, t.slug as template_slug 
FROM email_notification_rules r
JOIN email_templates t ON t.id = r.template_id
WHERE r.event_key = 'payment_info_submitted';














