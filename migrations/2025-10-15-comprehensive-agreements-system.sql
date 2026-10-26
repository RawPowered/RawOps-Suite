-- ============================================================================
-- Comprehensive Agreements & 1099 System
-- Version: 1.0.0
-- Date: 2025-10-15
-- Prefix: agreements_
-- ============================================================================
-- Binary-safe migration: Can be run multiple times safely
-- Uses INSERT...ON DUPLICATE KEY UPDATE pattern
-- Proper character encoding and foreign key handling
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- 1. AGREEMENT TEMPLATES TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `agreement_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agreement_type` ENUM('nda', 'non_compete', 'ai_communications', 'sms_consent', 'email_consent', 'phone_consent', 'gmail_account') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agreement_type_version` (`agreement_type`, `version`),
  KEY `idx_agreement_active` (`is_active`),
  KEY `idx_agreement_type` (`agreement_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Agreement Templates
INSERT INTO `agreement_templates` (`id`, `agreement_type`, `title`, `content`, `version`, `is_active`) VALUES
(1, 'nda', 'Non-Disclosure Agreement (NDA)', '<h2>Non-Disclosure Agreement</h2>
<p>This Non-Disclosure Agreement ("Agreement") is entered into as of the date of digital signature below by and between the Sales Agent (hereinafter "Recipient") and Product LLC (hereinafter "Company").</p>

<h3>1. Confidential Information</h3>
<p>Recipient acknowledges that during their employment or engagement with the Company, they will have access to and become acquainted with various trade secrets, proprietary information, and confidential information including but not limited to:</p>
<ul>
<li>Customer lists, contact information, and business relationships</li>
<li>Pricing structures, commission rates, and financial information</li>
<li>Sales strategies, marketing plans, and business methodologies</li>
<li>Product formulations, supplier information, and operational procedures</li>
<li>Proprietary software, databases, and technological systems</li>
</ul>

<h3>2. Non-Disclosure Obligations</h3>
<p>Recipient agrees to hold all Confidential Information in strict confidence and not to disclose such information to any third party without prior written consent from the Company. This obligation extends during and after the term of engagement.</p>

<h3>3. Use Restrictions</h3>
<p>Recipient agrees to use Confidential Information solely for the purpose of performing duties as a sales agent for the Company and not for any personal benefit or the benefit of any third party.</p>

<h3>4. Return of Materials</h3>
<p>Upon termination of engagement or upon Company request, Recipient agrees to immediately return all documents, records, and materials containing Confidential Information.</p>

<h3>5. Duration</h3>
<p>The obligations under this Agreement shall survive the termination of Recipient\'s engagement with the Company and shall continue indefinitely or until such information becomes publicly available through no fault of the Recipient.</p>

<p><strong>By signing below, I acknowledge that I have read, understood, and agree to be bound by the terms of this Non-Disclosure Agreement.</strong></p>', 1, 1),

(2, 'non_compete', 'Non-Compete Agreement', '<h2>Non-Compete Agreement</h2>
<p>This Non-Compete Agreement ("Agreement") is entered into as of the date of digital signature below by and between the Sales Agent (hereinafter "Agent") and Product LLC (hereinafter "Company").</p>

<h3>1. Non-Compete Covenant</h3>
<p>Agent agrees that during their engagement with the Company and for a period of <strong>two (2) years</strong> following the termination of such engagement, Agent shall not:</p>
<ul>
<li>Engage in, operate, or have any financial interest in any business that competes with the Company in the product, botanical, or similar wellness product industry</li>
<li>Create, develop, or participate in the creation of any competing sales platform or product of similar variety</li>
<li>Join or become employed by any company of similar capacity or production with the same role, level of access, or responsibility</li>
<li>Solicit or attempt to solicit any customers, clients, or accounts of the Company for competitive purposes</li>
</ul>

<h3>2. Geographic Scope</h3>
<p>This Agreement applies to all territories where the Company conducts business, including but not limited to all states within the United States.</p>

<h3>3. State Jurisdictional Variations</h3>
<p>Agent acknowledges that certain states may have specific regulations regarding non-compete agreements. The parties agree that if any provision of this Agreement is found to be unenforceable in a particular jurisdiction, such provision shall be modified to the minimum extent necessary to make it enforceable, or if not possible, severed from this Agreement without affecting the validity of the remaining provisions.</p>

<h3>4. Reasonableness</h3>
<p>Agent acknowledges that the restrictions contained in this Agreement are reasonable and necessary to protect the legitimate business interests of the Company, including its trade secrets, confidential information, customer relationships, and goodwill.</p>

<h3>5. Remedies</h3>
<p>Agent acknowledges that a breach of this Agreement would cause irreparable harm to the Company and that monetary damages would be inadequate. Therefore, the Company shall be entitled to seek injunctive relief in addition to any other remedies available at law or in equity.</p>

<p><strong>By signing below, I acknowledge that I have read, understood, and agree to be bound by the terms of this Non-Compete Agreement for a period of two (2) years following my separation from the Company.</strong></p>', 1, 1),

(3, 'ai_communications', 'AI-Powered Communications Agreement', '<h2>AI-Powered Communications Agreement</h2>
<p>This AI-Powered Communications Agreement ("Agreement") is entered into as of the date of digital signature below by and between the Sales Agent (hereinafter "Agent") and Product LLC (hereinafter "Company").</p>

<h3>1. Acknowledgment of AI Systems</h3>
<p>Agent acknowledges and agrees that the Company utilizes artificial intelligence (AI) powered and automated systems for various business communications, including but not limited to:</p>
<ul>
<li>Email composition and generation</li>
<li>Customer outreach and follow-up messages</li>
<li>Marketing content creation</li>
<li>Automated workflow communications</li>
<li>Response suggestions and templates</li>
</ul>

<h3>2. Agent Responsibility for Content</h3>
<p>Agent agrees and acknowledges that:</p>
<ul>
<li><strong>Agent is ultimately responsible</strong> for all content sent under their name or account, regardless of whether such content was generated or assisted by AI</li>
<li>Agent must review, verify, and approve all AI-generated content before sending or publishing</li>
<li>If AI makes any errors, inaccuracies, or inappropriate suggestions, it is the Agent\'s responsibility to correct such mistakes prior to sending</li>
<li>Agent will ensure all communications comply with applicable laws, regulations, and Company policies</li>
</ul>

<h3>3. Platform-Generated Automation Exception</h3>
<p>Agent is <strong>absolved of responsibility</strong> for content sent through automated systems that are:</p>
<ul>
<li>Programmatically created and deployed by the Company\'s administration or development team</li>
<li>Part of automated workflows configured by platform administrators</li>
<li>System-level automations beyond the Agent\'s control or configuration</li>
</ul>
<p>In such cases, the Company assumes responsibility for any errors or mistakes in the automated communications.</p>

<h3>4. AI Disclaimer Acknowledgment</h3>
<p>Agent acknowledges that all emails sent through the platform will include a standard AI disclaimer footer notifying recipients that the message may have been written with or entirely by AI, linking to a full disclosure page.</p>

<h3>5. Limitations and Risks</h3>
<p>Agent understands that AI systems, while sophisticated, may:</p>
<ul>
<li>Produce factual errors or inconsistencies</li>
<li>Generate inappropriate or unclear language</li>
<li>Misinterpret context or intent</li>
<li>Provide outdated information</li>
</ul>
<p>The Company makes regular efforts to maintain AI accuracy and reliability but cannot guarantee perfection.</p>

<h3>6. Training and Best Practices</h3>
<p>Agent agrees to follow Company training and best practices regarding AI tool usage, including reviewing all AI-generated content before sending.</p>

<p><strong>By signing below, I acknowledge that I have read, understood, and agree to take full responsibility for reviewing and verifying all AI-generated content before publishing or sending communications. I understand the limitations of AI systems and agree to use them responsibly.</strong></p>', 1, 1),

(4, 'sms_consent', 'SMS Communications Consent', '<h2>SMS Communications Consent</h2>
<p>This SMS Communications Consent Agreement ("Agreement") is entered into as of the date of digital signature below.</p>

<h3>Consent to SMS Communications</h3>
<p>By signing this agreement, I consent to receive SMS text messages from Product LLC for the following purposes:</p>
<ul>
<li>Business communications related to my role as a sales agent</li>
<li>Order updates and notifications</li>
<li>Training reminders and updates</li>
<li>Marketing and promotional messages</li>
<li>System alerts and important announcements</li>
</ul>

<h3>Message Frequency and Charges</h3>
<p>Message frequency may vary. Standard message and data rates may apply based on your mobile carrier\'s plan.</p>

<h3>Opt-Out</h3>
<p>You may opt out of SMS communications at any time by replying STOP to any message or updating your preferences in your profile settings.</p>

<h3>Privacy</h3>
<p>Your phone number and communication preferences will be kept confidential and used solely for the purposes outlined in this agreement and our Privacy Policy.</p>

<p><strong>By signing below, I consent to receive SMS text messages from Product LLC as described above.</strong></p>', 1, 1),

(5, 'email_consent', 'Email Communications Consent', '<h2>Email Communications Consent</h2>
<p>This Email Communications Consent Agreement ("Agreement") is entered into as of the date of digital signature below.</p>

<h3>Consent to Email Communications</h3>
<p>By signing this agreement, I consent to receive email communications from Product LLC for the following purposes:</p>
<ul>
<li>Business communications related to my role as a sales agent</li>
<li>Training materials and resources</li>
<li>Order notifications and updates</li>
<li>Marketing and promotional content</li>
<li>Company newsletters and announcements</li>
<li>System notifications and account updates</li>
</ul>

<h3>Email Frequency</h3>
<p>Email frequency may vary based on business needs and your role within the organization.</p>

<h3>Opt-Out</h3>
<p>You may opt out of marketing emails at any time by clicking the unsubscribe link in any email or updating your preferences in your profile settings. Note that certain transactional and business-critical emails cannot be opted out of.</p>

<h3>Privacy</h3>
<p>Your email address will be kept confidential and used solely for the purposes outlined in this agreement and our Privacy Policy.</p>

<p><strong>By signing below, I consent to receive email communications from Product LLC as described above.</strong></p>', 1, 1),

(6, 'phone_consent', 'Phone Call Communications Consent', '<h2>Phone Call Communications Consent</h2>
<p>This Phone Call Communications Consent Agreement ("Agreement") is entered into as of the date of digital signature below.</p>

<h3>Consent to Phone Communications</h3>
<p>By signing this agreement, I consent to receive phone calls from Product LLC for the following purposes:</p>
<ul>
<li>Business communications related to my role as a sales agent</li>
<li>Training and coaching sessions</li>
<li>Order-related discussions</li>
<li>Customer service and support</li>
<li>Performance reviews and feedback</li>
<li>Important business updates and announcements</li>
</ul>

<h3>Call Recording</h3>
<p>I acknowledge that calls may be recorded for quality assurance, training purposes, and compliance with applicable regulations.</p>

<h3>Opt-Out</h3>
<p>You may opt out of non-essential phone communications at any time by updating your preferences in your profile settings. Business-critical calls related to your role and responsibilities cannot be opted out of.</p>

<h3>Privacy</h3>
<p>Your phone number will be kept confidential and used solely for the purposes outlined in this agreement and our Privacy Policy.</p>

<p><strong>By signing below, I consent to receive phone calls from Product LLC as described above and acknowledge that calls may be recorded.</strong></p>', 1, 1),

(7, 'gmail_account', 'Company Gmail Account Agreement', '<h2>Company Gmail Account Agreement</h2>
<p>This Company Gmail Account Agreement ("Agreement") is entered into as of the date of digital signature below by and between the Sales Agent (hereinafter "Agent") and Product LLC (hereinafter "Company").</p>

<h3>1. Gmail Account Provision</h3>
<p>Agent agrees that the Company will provision an official Gmail account in the Agent\'s name to be used for conducting Company business. This account will be created and managed by Company administrators.</p>

<h3>2. Account Ownership</h3>
<p>Agent acknowledges and agrees that:</p>
<ul>
<li>The Gmail account is the property of the Company</li>
<li>The Company retains full ownership and administrative access to the account</li>
<li>The Company may access, monitor, or review the account contents at any time</li>
<li>The account may be suspended or terminated at the Company\'s discretion</li>
</ul>

<h3>3. Acceptable Use</h3>
<p>Agent agrees to use the Company Gmail account:</p>
<ul>
<li>Exclusively for Company business purposes</li>
<li>In accordance with all Company policies and procedures</li>
<li>In compliance with applicable laws and regulations</li>
<li>In a professional manner that reflects positively on the Company</li>
</ul>

<h3>4. Personal Information</h3>
<p>Agent agrees to provide accurate personal information required for account setup, including legal name, date of birth, and backup contact information.</p>

<h3>5. Security Responsibilities</h3>
<p>Agent agrees to:</p>
<ul>
<li>Maintain the confidentiality of account credentials</li>
<li>Use strong passwords and enable two-factor authentication if required</li>
<li>Immediately report any suspected security breaches or unauthorized access</li>
<li>Not share account access with unauthorized individuals</li>
</ul>

<h3>6. Data and Content</h3>
<p>Agent acknowledges that all emails, files, and data stored in or sent from the Company Gmail account are Company property and may be retained by the Company even after termination of engagement.</p>

<h3>7. Account Termination</h3>
<p>Upon termination of engagement with the Company, Agent agrees to:</p>
<ul>
<li>Immediately cease use of the Company Gmail account</li>
<li>Transfer any necessary business information as directed by the Company</li>
<li>Acknowledge that the Company may disable or delete the account</li>
</ul>

<h3>8. No Expectation of Privacy</h3>
<p>Agent acknowledges that there is no expectation of privacy regarding any communications or data stored in the Company Gmail account.</p>

<p><strong>By signing below, I agree to the Company provisioning an official Gmail account in my name for business purposes, and I acknowledge and accept all terms and conditions outlined in this agreement.</strong></p>', 1, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `content` = VALUES(`content`),
  `is_active` = VALUES(`is_active`),
  `updated_at` = CURRENT_TIMESTAMP;

-- ============================================================================
-- 2. USER AGREEMENTS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `user_agreements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `agreement_template_id` BIGINT UNSIGNED NOT NULL,
  `signature_type` ENUM('typed', 'canvas', 'checkbox') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature_data` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Name for typed, JSON for canvas, NULL for checkbox',
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `signed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `agreement_version` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_agreement_template` (`user_id`, `agreement_template_id`, `agreement_version`),
  KEY `idx_user_agreements_user` (`user_id`),
  KEY `idx_user_agreements_template` (`agreement_template_id`),
  KEY `idx_user_agreements_signed` (`signed_at`),
  CONSTRAINT `fk_user_agreements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_agreements_template` FOREIGN KEY (`agreement_template_id`) REFERENCES `agreement_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. FORM 1099 DOCUMENTS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `form_1099_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_year` YEAR NOT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` BIGINT UNSIGNED NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tax_year_active` (`tax_year`, `is_active`),
  KEY `idx_1099_tax_year` (`tax_year`),
  KEY `idx_1099_active` (`is_active`),
  CONSTRAINT `fk_1099_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. USER AGREEMENT REMINDERS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `user_agreement_reminders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `shown_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dismissed_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_agreement_reminders_user` (`user_id`),
  KEY `idx_agreement_reminders_completed` (`completed_at`),
  CONSTRAINT `fk_agreement_reminders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- COMMIT TRANSACTION
-- ============================================================================

COMMIT;




