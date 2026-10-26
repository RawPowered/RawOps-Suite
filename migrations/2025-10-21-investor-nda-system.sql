-- Migration: Investor NDA and Profile System
-- Date: 2025-10-21
-- Description: Create investor profiles table and NDA agreement templates

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create investor_profiles table
CREATE TABLE IF NOT EXISTS `investor_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `investment_capacity` enum('angel','seed','series_a','growth','strategic') DEFAULT NULL,
  `investment_interest` text DEFAULT NULL COMMENT 'Areas of interest: product, technology, market expansion, etc.',
  `company_website` varchar(500) DEFAULT NULL,
  `linkedin_profile` varchar(500) DEFAULT NULL,
  `professional_background` text DEFAULT NULL,
  `nda_completed` tinyint(1) NOT NULL DEFAULT 0,
  `platform_access_granted` tinyint(1) NOT NULL DEFAULT 0,
  `access_granted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `nda_completed` (`nda_completed`),
  KEY `platform_access_granted` (`platform_access_granted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert investor-specific agreement templates
-- Note: agreement_templates table should exist from previous migrations

INSERT INTO `agreement_templates` (`agreement_type`, `title`, `content`, `version`, `is_active`, `created_at`)
VALUES 
(
  'investor_nda',
  'Investor Non-Disclosure Agreement',
  '<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>NON-DISCLOSURE AGREEMENT</h2>
    <p><strong>Effective Date:</strong> [DATE]</p>
    
    <h3>1. Purpose</h3>
    <p>This Non-Disclosure Agreement ("Agreement") is entered into between Product ("Company") and the undersigned investor ("Recipient") for the purpose of preventing the unauthorized disclosure of Confidential Information as defined below.</p>
    
    <h3>2. Definition of Confidential Information</h3>
    <p>For purposes of this Agreement, "Confidential Information" includes, but is not limited to:</p>
    <ul>
      <li>Proprietary technology, software architecture, and platform capabilities</li>
      <li>Business strategies, financial projections, and operational metrics</li>
      <li>Product formulations, manufacturing processes, and supply chain details</li>
      <li>Customer lists, sales data, and market intelligence</li>
      <li>Any other information marked as "Confidential" or reasonably understood to be confidential</li>
    </ul>
    
    <h3>3. Obligations of Recipient</h3>
    <p>Recipient agrees to:</p>
    <ul>
      <li>Hold and maintain Confidential Information in strict confidence</li>
      <li>Not disclose Confidential Information to third parties without prior written consent</li>
      <li>Not use Confidential Information for any purpose other than evaluating investment opportunities</li>
      <li>Protect Confidential Information with the same degree of care used to protect their own confidential information</li>
    </ul>
    
    <h3>4. Term</h3>
    <p>This Agreement shall remain in effect for a period of five (5) years from the Effective Date, or until Confidential Information becomes publicly available through no fault of Recipient.</p>
    
    <h3>5. Return of Materials</h3>
    <p>Upon request, Recipient agrees to promptly return or destroy all documents and materials containing Confidential Information.</p>
    
    <h3>6. No Rights Granted</h3>
    <p>This Agreement does not grant Recipient any rights to Confidential Information except as expressly stated herein. No license or rights are granted by implication.</p>
    
    <p><strong>By signing below, I acknowledge that I have read, understood, and agree to be bound by the terms of this Non-Disclosure Agreement.</strong></p>
  </div>',
  '1.0',
  1,
  NOW()
)
ON DUPLICATE KEY UPDATE 
  `content` = VALUES(`content`),
  `version` = VALUES(`version`),
  `is_active` = VALUES(`is_active`);

INSERT INTO `agreement_templates` (`agreement_type`, `title`, `content`, `version`, `is_active`, `created_at`)
VALUES 
(
  'investor_platform_access',
  'Investor Platform Access Agreement',
  '<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>PLATFORM ACCESS AGREEMENT</h2>
    <p><strong>Effective Date:</strong> [DATE]</p>
    
    <h3>1. Access Rights</h3>
    <p>By accepting this agreement, you are granted limited access to the Product proprietary platform for the sole purpose of evaluating investment opportunities.</p>
    
    <h3>2. Restrictions</h3>
    <p>You agree to:</p>
    <ul>
      <li>Not attempt to reverse engineer, decompile, or extract source code from the platform</li>
      <li>Not share login credentials or allow unauthorized access to the platform</li>
      <li>Not download, copy, or extract data beyond what is necessary for investment evaluation</li>
      <li>Not use any automated tools, scrapers, or bots to access the platform</li>
    </ul>
    
    <h3>3. Data Protection</h3>
    <p>All data accessed through the platform is considered Confidential Information and is subject to the terms of the Non-Disclosure Agreement.</p>
    
    <h3>4. Termination</h3>
    <p>Company reserves the right to terminate platform access at any time, with or without cause. Upon termination, you must cease all use of the platform immediately.</p>
    
    <h3>5. Acknowledgment</h3>
    <p>You acknowledge that the platform represents significant proprietary technology and trade secrets of the Company.</p>
    
    <p><strong>By signing below, I agree to comply with all terms of this Platform Access Agreement.</strong></p>
  </div>',
  '1.0',
  1,
  NOW()
)
ON DUPLICATE KEY UPDATE 
  `content` = VALUES(`content`),
  `version` = VALUES(`version`),
  `is_active` = VALUES(`is_active`);

