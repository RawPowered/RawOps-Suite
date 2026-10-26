-- Sample Request Email Workflow Triggers and Templates
-- Date: 2025-10-12
-- Purpose: Automated email workflows for sample request lifecycle events
-- Binary-safe, ON DUPLICATE KEY UPDATE patterns

START TRANSACTION;

-- ============================================
-- EXTEND EMAIL_WORKFLOWS TABLE WITH SAMPLE TRIGGERS
-- ============================================

-- Add sample-specific trigger types to email_workflows
-- The table already exists from previous migration (2025-10-11-email-workflow-system.sql)
-- We're just adding new workflow definitions

-- ============================================
-- EMAIL TEMPLATES - Use existing table structure
-- ============================================
-- Table already exists from migration: 2025-09-25-google-oauth-email-calendar.sql
-- Structure: id, slug, name, subject, html_body, text_body, category, scope, is_active

-- ============================================
-- INSERT SAMPLE EMAIL TEMPLATES
-- ============================================

-- Template 1: Sample Approved Notification (to customer)
INSERT INTO `email_templates` (
    `slug`, `name`, `subject`, `html_body`, `text_body`,
    `category`, `scope`, `is_active`
) VALUES (
    'sample_approved_customer',
    'Sample Approved - Customer Notification',
    'Your Product Sample is On Its Way! 🎉',
    '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #059669; margin-bottom: 10px;">Great News, {recipient_name}!</h1>
            <p style="font-size: 18px; color: #666;">Your Product sample has been approved and is on its way! 📦</p>
        </div>
        
        <div style="background: #f0fdf4; border-left: 4px solid #059669; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0; color: #047857;">Sample Details</h2>
            <p><strong>Product:</strong> {product_name}</p>
            <p><strong>Requested by:</strong> {agent_name}</p>
            {tracking_info}
        </div>
        
        <div style="margin: 30px 0;">
            <h3 style="color: #333;">What to Expect</h3>
            <p>Your premium Product sample will arrive within {delivery_days} business days. Once it arrives, we recommend:</p>
            <ul>
                <li>Store in a cool, dry place</li>
                <li>Start with the recommended serving size</li>
                <li>Keep track of your experience</li>
            </ul>
        </div>
        
        <div style="background: #eff6ff; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #1e40af;">Questions?</h3>
            <p>Your sales representative <strong>{agent_name}</strong> is here to help!</p>
            <p>📧 {agent_email}<br>📱 {agent_phone}</p>
        </div>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <p style="color: #666; font-size: 14px;">Thank you for choosing Product</p>
        </div>
    </div>
    </body></html>',
    'Great News, {recipient_name}!

Your Product sample has been approved and is on its way!

Sample Details:
Product: {product_name}
Requested by: {agent_name}
{tracking_info}

What to Expect:
Your premium Product sample will arrive within {delivery_days} business days.

Questions? Contact your sales representative:
{agent_name}
Email: {agent_email}
Phone: {agent_phone}

Thank you for choosing Product',
    'sample',
    'sales_agent',
    1
) ON DUPLICATE KEY UPDATE
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template 2: Sample Delivered Notification
INSERT INTO `email_templates` (
    `slug`, `name`, `subject`, `html_body`, `text_body`,
    `category`, `scope`, `is_active`
) VALUES (
    'sample_delivered',
    'Sample Delivered - It''s Here!',
    'It''s Here! Your Product Sample Has Arrived 🎉',
    '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <div style="font-size: 64px; margin-bottom: 20px;">🎉</div>
            <h1 style="color: #059669; margin-bottom: 10px;">Your Sample Has Arrived!</h1>
            <p style="font-size: 18px; color: #666;">Time to experience the Product difference, {recipient_name}!</p>
        </div>
        
        <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); padding: 25px; border-radius: 12px; margin: 20px 0;">
            <h2 style="margin-top: 0; color: #92400e;">Getting Started</h2>
            <ol style="color: #78350f; line-height: 1.8;">
                <li><strong>Start Small:</strong> Begin with the recommended serving size on the label</li>
                <li><strong>Observe:</strong> Pay attention to how you feel over the next few hours</li>
                <li><strong>Adjust:</strong> You can increase or decrease based on your experience</li>
                <li><strong>Track:</strong> Keep notes on what works best for you</li>
            </ol>
        </div>
        
        <div style="background: #f0fdf4; border: 2px solid #059669; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #047857;">Ready to Talk Business?</h3>
            <p>Your sales representative {agent_name} will be reaching out in the next few days to:</p>
            <ul>
                <li>Answer any questions you have</li>
                <li>Discuss wholesale pricing options</li>
                <li>Help you place your first order</li>
            </ul>
            <p><strong>Or reach out anytime:</strong><br>
            📧 {agent_email}<br>
            📱 {agent_phone}</p>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <p style="color: #666; font-size: 14px;">We can''t wait to hear what you think!</p>
        </div>
    </div>
    </body></html>',
    'YOUR SAMPLE HAS ARRIVED! 🎉

Time to experience the Product difference, {recipient_name}!

Getting Started:
1. Start Small - Begin with the recommended serving size
2. Observe - Pay attention to how you feel
3. Adjust - Increase or decrease based on your experience
4. Track - Keep notes on what works best

Your sales representative {agent_name} will be reaching out soon to discuss:
- Answering questions
- Wholesale pricing options
- Placing your first order

Contact {agent_name}:
Email: {agent_email}
Phone: {agent_phone}

We can''t wait to hear what you think!',
    'sample',
    'sales_agent',
    1
) ON DUPLICATE KEY UPDATE
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template 3: Agent Sample Shipped Notification
INSERT INTO `email_templates` (
    `slug`, `name`, `subject`, `html_body`, `text_body`,
    `category`, `scope`, `is_active`
) VALUES (
    'sample_shipped_agent',
    'Sample Shipped - Agent Notification',
    'Sample Shipped to {company_name}',
    '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #0369a1;">Sample Shipment Update</h1>
        
        <div style="background: #f0f9ff; border-left: 4px solid #0369a1; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">Shipment Details</h2>
            <p><strong>Customer:</strong> {company_name}</p>
            <p><strong>Contact:</strong> {recipient_name}</p>
            <p><strong>Product:</strong> {product_name}</p>
            <p><strong>Carrier:</strong> {carrier} {service_type}</p>
            <p><strong>Tracking:</strong> {tracking_number}</p>
            <p><strong>Expected Delivery:</strong> {expected_delivery_date}</p>
        </div>
        
        <div style="background: #fef3c7; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #92400e;">Next Steps</h3>
            <ul>
                <li>Customer has been notified of shipment</li>
                <li>Follow-up task will be created 3 days after delivery</li>
                <li>Track delivery status in your CRM dashboard</li>
            </ul>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="{tracking_url}" style="display: inline-block; background: #0369a1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;">Track Shipment</a>
        </div>
    </div>
    </body></html>',
    'Sample Shipped to {company_name}

Shipment Details:
Customer: {company_name}
Contact: {recipient_name}
Product: {product_name}
Carrier: {carrier} {service_type}
Tracking: {tracking_number}
Expected Delivery: {expected_delivery_date}

Next Steps:
- Customer has been notified
- Follow-up task will be created 3 days after delivery
- Track delivery in your CRM dashboard

Track: {tracking_url}',
    'sample',
    'sales_agent',
    1
) ON DUPLICATE KEY UPDATE
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- Template 4: Sample Request Confirmation
INSERT INTO `email_templates` (
    `slug`, `name`, `subject`, `html_body`, `text_body`,
    `category`, `scope`, `is_active`
) VALUES (
    'sample_request_received',
    'Sample Request Received',
    'Sample Request Received - {product_name}',
    '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #059669;">Sample Request Received</h1>
        
        <p>Hi {recipient_name},</p>
        
        <p>We''ve received your request for a Product sample! Here''s what happens next:</p>
        
        <div style="background: #f9fafb; border: 1px solid #e5e7eb; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0;">Your Request</h3>
            <p><strong>Product:</strong> {product_name}</p>
            <p><strong>Requested by:</strong> {agent_name}</p>
            <p><strong>Request Date:</strong> {request_date}</p>
        </div>
        
        <div style="background: #eff6ff; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #1e40af;">What''s Next?</h3>
            <ol style="line-height: 1.8;">
                <li>Our team will review your request (usually within 1 business day)</li>
                <li>Once approved, we''ll prepare your sample for shipment</li>
                <li>You''ll receive tracking information when it ships</li>
                <li>Your sample should arrive within 3-5 business days</li>
            </ol>
        </div>
        
        <p>Questions? Your sales representative {agent_name} is here to help!</p>
        <p>📧 {agent_email}<br>📱 {agent_phone}</p>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <p style="color: #666; font-size: 14px;">Thank you for your interest in Product</p>
        </div>
    </div>
    </body></html>',
    'Sample Request Received

Hi {recipient_name},

We''ve received your request for a Product sample!

Your Request:
Product: {product_name}
Requested by: {agent_name}
Request Date: {request_date}

What''s Next?
1. Our team will review your request (usually within 1 business day)
2. Once approved, we''ll prepare your sample for shipment
3. You''ll receive tracking information when it ships
4. Your sample should arrive within 3-5 business days

Questions? Contact {agent_name}:
Email: {agent_email}
Phone: {agent_phone}

Thank you for your interest in Product',
    'sample',
    'sales_agent',
    1
) ON DUPLICATE KEY UPDATE
    `subject` = VALUES(`subject`),
    `html_body` = VALUES(`html_body`),
    `text_body` = VALUES(`text_body`);

-- ============================================
-- SAMPLE EMAIL EVENT TRACKING TABLE
-- ============================================

-- Create a simple event log for sample email triggers
-- This avoids complex workflow table dependencies and provides straightforward event tracking
CREATE TABLE IF NOT EXISTS `sample_email_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sample_request_id` BIGINT UNSIGNED NOT NULL,
    `event_type` ENUM('request_created','approved','shipped','delivered','followup_due') COLLATE utf8mb4_bin NOT NULL,
    `template_slug` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL COMMENT 'Email template used',
    `recipient_email` VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    `recipient_type` ENUM('customer','agent','both') COLLATE utf8mb4_bin NOT NULL DEFAULT 'customer',
    `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `sent_at` TIMESTAMP NULL,
    `sendgrid_message_id` VARCHAR(255) NULL,
    `metadata` JSON NULL COMMENT 'Template variables and additional data',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sample_request` (`sample_request_id`),
    KEY `idx_event_type` (`event_type`),
    KEY `idx_email_sent` (`email_sent`),
    KEY `idx_recipient` (`recipient_email`),
    CONSTRAINT `fk_sample_event_request` FOREIGN KEY (`sample_request_id`) REFERENCES `sample_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SAMPLE EMAIL WORKFLOW MAPPINGS
-- ============================================

-- Map sample events to email templates (simple configuration table)
CREATE TABLE IF NOT EXISTS `sample_email_workflow_config` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type` ENUM('request_created','approved','shipped','delivered','followup_due') COLLATE utf8mb4_bin NOT NULL,
    `recipient_type` ENUM('customer','agent','both') COLLATE utf8mb4_bin NOT NULL,
    `template_slug` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `delay_minutes` INT NOT NULL DEFAULT 0 COMMENT 'Delay before sending (0 = immediate)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_event_recipient` (`event_type`, `recipient_type`),
    KEY `idx_template` (`template_slug`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default workflow mappings
INSERT INTO `sample_email_workflow_config` (`event_type`, `recipient_type`, `template_slug`, `is_active`) VALUES
('approved', 'customer', 'sample_approved_customer', 1),
('shipped', 'customer', 'sample_approved_customer', 1),
('shipped', 'agent', 'sample_shipped_agent', 1),
('delivered', 'customer', 'sample_delivered', 1),
('request_created', 'customer', 'sample_request_received', 1)
ON DUPLICATE KEY UPDATE
    `template_slug` = VALUES(`template_slug`),
    `is_active` = VALUES(`is_active`);

COMMIT;

-- ============================================
-- VERIFICATION QUERIES (for manual check)
-- ============================================

-- View created templates
-- SELECT slug, name, category, scope, is_active 
-- FROM email_templates 
-- WHERE category = 'sample';

-- View sample email workflow config
-- SELECT event_type, recipient_type, template_slug, is_active 
-- FROM sample_email_workflow_config;

-- View sample email event tracking table
-- SHOW CREATE TABLE sample_email_events;

