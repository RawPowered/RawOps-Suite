-- CRITICAL FIX: Invoice Email Template Variable Rendering
-- The variables are showing as {{variable}} instead of actual values
-- Issue: Template uses {{variable}} but email system expects plain variable names

START TRANSACTION;

-- Update template to remove {{ }} from the stored HTML
-- The email rendering system will add them back during str_replace
UPDATE email_templates 
SET 
    subject = 'Invoice #[ORDER_NUMBER] - $[ORDER_TOTAL]',
    html_body = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff;">
    
    <!-- Header -->
    <div style="background: linear-gradient(135deg, #1e3a8a, #3b82f6); padding: 40px 20px; text-align: center;">
        <h1 style="color: white; margin: 0; font-size: 28px;">Invoice Ready</h1>
        <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0; font-size: 14px;">Your order is ready for payment</p>
    </div>
    
    <!-- Body -->
    <div style="background: #ffffff; padding: 32px 20px;">
        <p style="margin: 0 0 20px; font-size: 16px; color: #334155;">
            Hi <strong>[FIRST_NAME]</strong>,
        </p>
        
        <p style="margin: 0 0 24px; font-size: 16px; line-height: 1.6; color: #334155;">
            Your invoice has been created and is ready for payment. Please review the details below and proceed with payment at your convenience.
        </p>
        
        <!-- Invoice Summary -->
        <div style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border: 2px solid #3b82f6; border-radius: 12px; padding: 24px; margin: 0 0 24px;">
            <h2 style="margin: 0 0 16px; color: #1e40af; font-size: 20px;">Invoice #[ORDER_NUMBER]</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; color: #475569; font-size: 14px;">Subtotal:</td>
                    <td style="padding: 8px 0; text-align: right; color: #1e293b; font-weight: 600; font-size: 14px;">$[SUBTOTAL]</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #475569; font-size: 14px;">Shipping:</td>
                    <td style="padding: 8px 0; text-align: right; color: #1e293b; font-weight: 600; font-size: 14px;">$[SHIPPING]</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #475569; font-size: 14px;">Tax:</td>
                    <td style="padding: 8px 0; text-align: right; color: #1e293b; font-weight: 600; font-size: 14px;">$[TAX]</td>
                </tr>
                <tr style="border-top: 2px solid #3b82f6;">
                    <td style="padding: 12px 0 0; color: #1e293b; font-size: 18px; font-weight: 700;">Total Due:</td>
                    <td style="padding: 12px 0 0; text-align: right; color: #1e40af; font-size: 24px; font-weight: 700;">$[ORDER_TOTAL]</td>
                </tr>
            </table>
        </div>
        
        <!-- Payment Button -->
        <div style="text-align: center; margin: 0 0 24px;">
            <a href="[BASE_URL]/customer/order-details.php?id=[ORDER_ID]&pay=1" style="display: inline-block; background: #059669; color: white; padding: 16px 48px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px;">
                Pay Invoice Now
            </a>
        </div>
        
        <p style="margin: 0 0 20px; font-size: 14px; color: #64748b; text-align: center;">
            Or view your full invoice details at:<br>
            <a href="[BASE_URL]/customer/order-details.php?id=[ORDER_ID]" style="color: #3b82f6;">View Invoice</a>
        </p>
        
        <p style="margin: 24px 0 0; font-size: 14px; color: #64748b; border-top: 1px solid #e5e7eb; padding-top: 20px;">
            Questions about your invoice? Contact us at sample-015@example.com
        </p>
    </div>
    
    <!-- Footer -->
    <div style="background: #f8fafc; padding: 24px 20px; text-align: center; border-top: 1px solid #e5e7eb;">
        <p style="margin: 0; font-size: 12px; color: #94a3b8;">
            © 2025 Product. All rights reserved.
        </p>
    </div>
</div>',
    text_body = 'Hi [FIRST_NAME],

Your invoice #[ORDER_NUMBER] has been created and is ready for payment.

Invoice Summary:
- Subtotal: $[SUBTOTAL]
- Shipping: $[SHIPPING]
- Tax: $[TAX]
- Total Due: $[ORDER_TOTAL]

Pay your invoice here:
[BASE_URL]/customer/order-details.php?id=[ORDER_ID]&pay=1

Questions? Contact us at sample-015@example.com

© 2025 Product'
WHERE slug = 'new-invoice';

COMMIT;

-- Verify
SELECT id, slug, subject FROM email_templates WHERE slug = 'new-invoice';














