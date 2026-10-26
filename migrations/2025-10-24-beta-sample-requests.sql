-- ============================================
-- BETA SAMPLE REQUEST SYSTEM
-- Date: 2025-10-24
-- Purpose: Public beta testing program for retail customers
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================
-- 1. Add Beta Sample Flags to sample_requests
-- ============================================

-- Add is_beta_sample column if it doesn't exist
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'sample_requests' 
    AND COLUMN_NAME = 'is_beta_sample');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `sample_requests` ADD COLUMN `is_beta_sample` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Flag for public beta sample requests'' AFTER `agent_notes`',
    'SELECT ''Column is_beta_sample already exists'' AS msg');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add beta_waiver_signed_at column if it doesn't exist
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'sample_requests' 
    AND COLUMN_NAME = 'beta_waiver_signed_at');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `sample_requests` ADD COLUMN `beta_waiver_signed_at` TIMESTAMP NULL COMMENT ''When user signed beta waiver'' AFTER `is_beta_sample`',
    'SELECT ''Column beta_waiver_signed_at already exists'' AS msg');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add beta_waiver_ip column if it doesn't exist
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'sample_requests' 
    AND COLUMN_NAME = 'beta_waiver_ip');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `sample_requests` ADD COLUMN `beta_waiver_ip` VARCHAR(45) COLLATE utf8mb4_bin NULL COMMENT ''IP address when waiver signed'' AFTER `beta_waiver_signed_at`',
    'SELECT ''Column beta_waiver_ip already exists'' AS msg');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index if it doesn't exist
SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'sample_requests' 
    AND INDEX_NAME = 'idx_is_beta_sample');

SET @sql = IF(@index_exists = 0,
    'ALTER TABLE `sample_requests` ADD INDEX `idx_is_beta_sample` (`is_beta_sample`, `status`)',
    'SELECT ''Index idx_is_beta_sample already exists'' AS msg');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 2. Create Beta Sample Products (if needed)
-- ============================================

-- Insert sample products for Product A (product-pack sample) if not exists
INSERT INTO `products` (
    `sku`, `name`, `description`, `attribute_per_oz`, `size_oz`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`,
    `active`, `is_sample`, `sample_parent_product_id`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `created_at`, `updated_at`
) VALUES (
    'BETA-Product A-product-pack',
    'Beta Sample - Product A (product-pack)',
    'Public beta test sample - Product A formula. 20mg attribute per fl oz. product-pack bottle for testing. NOT lab tested for final quality information. Subject to formulation changes.',
    20.00,
    4.00,
    0.00,
    0.00,
    1000,
    50,
    1,
    1,
    11,
    0.50,
    0.25,
    1.00,
    0.25,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE 
    `description` = VALUES(`description`),
    `active` = 1,
    `updated_at` = NOW();

-- Insert sample products for Product B (product-pack sample) if not exists
INSERT INTO `products` (
    `sku`, `name`, `description`, `attribute_per_oz`, `size_oz`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`,
    `active`, `is_sample`, `sample_parent_product_id`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `created_at`, `updated_at`
) VALUES (
    'BETA-Product B-product-pack',
    'Beta Sample - Product B (product-pack)',
    'Public beta test sample - Product B formula. 40mg attribute per fl oz. product-pack bottle for testing. NOT lab tested for final quality information. Subject to formulation changes.',
    40.00,
    4.00,
    0.00,
    0.00,
    1000,
    50,
    1,
    1,
    12,
    0.50,
    0.25,
    1.00,
    0.25,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE 
    `description` = VALUES(`description`),
    `active` = 1,
    `updated_at` = NOW();

-- ============================================
-- 3. Create Beta Sample Waiver Agreement Template
-- ============================================

-- Note: agreement_type field is ENUM, leaving it empty to avoid ALTER TABLE
INSERT INTO `agreement_templates` (
    `agreement_type`,
    `title`,
    `version`,
    `content`,
    `is_active`,
    `created_at`,
    `updated_at`
) VALUES (
    '',
    'Public Beta Sample Testing Waiver & Agreement',
    1,
    '<div style="max-width:800px;margin:0 auto;font-family:system-ui,-apple-system,sans-serif;line-height:1.6;color:#1f2937;">
    
    <div style="background:#fef2f2;border:2px solid #dc2626;border-radius:12px;padding:24px;margin-bottom:24px;">
        <div style="display:flex;align-items:start;gap:16px;">
            <div style="font-size:40px;">⚠️</div>
            <div>
                <h2 style="margin:0 0 12px;color:#991b1b;font-size:24px;font-weight:800;">PUBLIC BETA TESTING NOTICE</h2>
                <p style="margin:0;font-size:16px;color:#7f1d1d;font-weight:600;">
                    This product is currently in PUBLIC BETA TESTING phase and has NOT been lab tested for final quality information verification.
                </p>
            </div>
        </div>
    </div>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">Critical Information</h3>
    
    <div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:16px;margin-bottom:20px;">
        <p style="margin:0 0 10px;font-weight:600;color:#78350f;">By requesting this free beta sample, you acknowledge and agree to the following:</p>
        <ul style="margin:0;padding-left:20px;color:#78350f;">
            <li><strong>Testing Phase:</strong> This product is in active development and public beta testing.</li>
            <li><strong>Not Lab Tested:</strong> This batch has NOT undergone final third-party laboratory testing for quality information, contaminants, or product attribute content verification.</li>
            <li><strong>Subject to Change:</strong> The formulation, ingredients, and specifications are subject to change without notice.</li>
            <li><strong>Inherent Risks:</strong> There are inherent risks associated with consuming any botanical product, especially one in testing phase.</li>
        </ul>
    </div>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">This is NOT a Sale</h3>
    <p style="margin:0 0 16px;color:#374151;">
        This is a <strong>FREE SAMPLE</strong> provided solely for product testing and review purposes. This is NOT a commercial sale or transaction. 
        No payment is required or accepted. You are receiving this sample to evaluate the product and provide honest feedback.
    </p>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">No Warranties or Guarantees</h3>
    <p style="margin:0 0 16px;color:#374151;">
        This beta sample is provided <strong>"AS IS"</strong> and <strong>"AS AVAILABLE"</strong> without any warranties of any kind, either express or implied, including but not limited to:
    </p>
    <ul style="margin:0 0 16px;padding-left:20px;color:#374151;">
        <li>Merchantability</li>
        <li>Fitness for a particular purpose</li>
        <li>Non-infringement</li>
        <li>Quality, quality information, or safety</li>
        <li>Accuracy of labeling or dosing information</li>
    </ul>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">Assumption of Risk</h3>
    <p style="margin:0 0 16px;color:#374151;">
        You expressly acknowledge and agree that use of this beta sample is <strong>AT YOUR OWN RISK</strong>. You assume full responsibility for any and all risks associated with consuming this product, including but not limited to:
    </p>
    <ul style="margin:0 0 16px;padding-left:20px;color:#374151;">
        <li>Adverse reactions or side effects</li>
        <li>Allergic reactions</li>
        <li>product interactions</li>
        <li>Health complications</li>
        <li>Any other physical, mental, or emotional effects</li>
    </ul>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">Hold Harmless & Indemnification</h3>
    <p style="margin:0 0 16px;color:#374151;">
        You agree to <strong>RELEASE, HOLD HARMLESS, AND INDEMNIFY</strong> Product, its owners, employees, contractors, agents, and affiliates from any and all claims, damages, losses, liabilities, costs, and expenses (including reasonable attorneys\' fees) arising out of or related to:
    </p>
    <ul style="margin:0 0 16px;padding-left:20px;color:#374151;">
        <li>Your receipt, possession, or use of this beta sample</li>
        <li>Any injury, illness, or adverse effect resulting from consumption</li>
        <li>Any property damage or loss</li>
        <li>Any violation of law or regulation</li>
        <li>Any breach of this agreement</li>
    </ul>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">Review & Feedback Request</h3>
    <p style="margin:0 0 16px;color:#374151;">
        In exchange for receiving this free sample, you agree to provide honest feedback about your experience with the product. 
        This may include email surveys, follow-up questions, or review requests. Your feedback helps us improve the product before final release.
    </p>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">Age Certification</h3>
    <p style="margin:0 0 16px;color:#374151;">
        You certify that you are <strong>at least 18 years of age</strong> (or the age of majority in your jurisdiction, whichever is greater). 
        Products are not intended for minors.
    </p>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">Manufacturing Compliance</h3>
    <p style="margin:0 0 16px;color:#374151;">
        While this product is in beta testing, it is manufactured in an <strong>manufacturer compliance details configured by the operator</strong> in compliance with 
        <strong>Florida Statute 500.92 (Florida Product Consumer Protection Act)</strong>. The facility maintains current Good Manufacturing Practices (quality records).
    </p>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">Communication Consent</h3>
    <p style="margin:0 0 16px;color:#374151;">
        By requesting this sample, you consent to receive email communications from Product regarding your sample shipment, 
        tracking information, feedback requests, and product updates related to this beta testing program.
    </p>

    <h3 style="margin:24px 0 12px;color:#0f172a;font-size:20px;font-weight:700;">Governing Law</h3>
    <p style="margin:0 0 16px;color:#374151;">
        This agreement shall be governed by and construed in accordance with the laws of the State of Florida, without regard to its conflict of law provisions.
    </p>

    <div style="background:#f8fafc;border:2px solid #cbd5e1;border-radius:8px;padding:20px;margin-top:24px;">
        <p style="margin:0;font-weight:700;color:#0f172a;">
            BY CHECKING THE AGREEMENT BOX AND SUBMITTING THIS REQUEST, YOU ACKNOWLEDGE THAT:
        </p>
        <ul style="margin:12px 0 0;padding-left:20px;color:#334155;">
            <li>You have read and understood this entire agreement</li>
            <li>You voluntarily accept all terms and conditions</li>
            <li>You assume all risks associated with this beta product</li>
            <li>You release Product from all liability</li>
            <li>You are at least 18 years of age</li>
            <li>You understand this is NOT a sale or commercial transaction</li>
        </ul>
    </div>
</div>',
    1,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE 
    `content` = VALUES(`content`),
    `is_active` = 1,
    `updated_at` = NOW();

-- ============================================
-- 4. Create Beta Sample Review System
-- ============================================

CREATE TABLE IF NOT EXISTS `beta_sample_reviews` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `sample_request_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to sample_requests table',
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Customer who submitted review',
    `product_id` BIGINT UNSIGNED NOT NULL COMMENT 'Beta product reviewed',
    `rating` TINYINT UNSIGNED NOT NULL COMMENT '1-5 star rating',
    `title` VARCHAR(255) COLLATE utf8mb4_bin NULL COMMENT 'Review title/headline',
    `review_text` TEXT COLLATE utf8mb4_bin NOT NULL COMMENT 'Full review content',
    `taste_rating` TINYINT UNSIGNED NULL COMMENT '1-5 rating for taste',
    `clarity_rating` TINYINT UNSIGNED NULL COMMENT '1-5 rating for clarity',
    `effects_rating` TINYINT UNSIGNED NULL COMMENT '1-5 rating for effects',
    `would_purchase` TINYINT(1) NULL COMMENT 'Would they buy when released?',
    `status` ENUM('pending','approved','rejected') COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
    `admin_notes` TEXT COLLATE utf8mb4_bin NULL COMMENT 'Admin notes for approval/rejection',
    `approved_by` BIGINT UNSIGNED NULL COMMENT 'Admin who approved/rejected',
    `approved_at` TIMESTAMP NULL,
    `ip_address` VARCHAR(45) COLLATE utf8mb4_bin NULL,
    `user_agent` VARCHAR(500) COLLATE utf8mb4_bin NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sample_request` (`sample_request_id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_product` (`product_id`),
    INDEX `idx_status` (`status`, `approved_at`),
    INDEX `idx_rating` (`rating`, `status`),
    FOREIGN KEY (`sample_request_id`) REFERENCES `sample_requests` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

COMMIT;

