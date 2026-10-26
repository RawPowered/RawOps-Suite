-- 20251022-investor-agreements.sql
-- Add investor-specific agreement templates to support NDA and platform access for investors

-- First, alter the enum to add new agreement types
ALTER TABLE `agreement_templates` 
  MODIFY COLUMN `agreement_type` enum(
    'nda',
    'non_compete',
    'ai_communications',
    'sms_consent',
    'email_consent',
    'phone_consent',
    'gmail_account',
    'investor_nda',
    'investor_platform_access'
  ) NOT NULL;

-- Insert investor NDA template
INSERT INTO `agreement_templates` (`agreement_type`, `title`, `content`, `version`, `is_active`, `created_at`)
VALUES (
  'investor_nda',
  'Investor Non-Disclosure Agreement',
  '<h2>Investor Non-Disclosure Agreement</h2>
<p>This Investor Non-Disclosure Agreement (\"Agreement\") is entered into as of the date of digital signature below by and between the Investor (hereinafter \"Recipient\") and Product LLC (hereinafter \"Company\").</p>

<h3>1. Purpose</h3>
<p>Company is providing Recipient with access to its proprietary All-In-One Industry Platform and confidential business information for the purpose of evaluating potential investment opportunities. In connection with this access, Recipient will receive and review confidential and proprietary information about the Company\'s business, technology, operations, and financial performance.</p>

<h3>2. Confidential Information</h3>
<p>Recipient acknowledges that access to the Platform will provide exposure to various trade secrets, proprietary information, and confidential information including but not limited to:</p>
<ul>
<li>Proprietary software architecture, code, and technical systems</li>
<li>Business strategies, financial projections, and operational metrics</li>
<li>Customer data, sales pipelines, and revenue information</li>
<li>Product formulations, manufacturing processes, and supplier relationships</li>
<li>Intellectual property, patents pending, and trade secrets</li>
<li>Employee information, compensation structures, and organizational details</li>
<li>Marketing strategies, pricing models, and competitive analyses</li>
<li>All other information marked as confidential or that would reasonably be considered confidential</li>
</ul>

<h3>3. Non-Disclosure Obligations</h3>
<p>Recipient agrees to:</p>
<ul>
<li>Maintain all Confidential Information in strict confidence</li>
<li>Not disclose Confidential Information to any third party without prior written consent from Company</li>
<li>Use Confidential Information solely for the purpose of evaluating investment opportunities with Company</li>
<li>Protect Confidential Information with the same degree of care used to protect their own confidential information, but in no event less than reasonable care</li>
<li>Limit access to Confidential Information to only those individuals with a legitimate need to know</li>
</ul>

<h3>4. Exceptions</h3>
<p>The obligations set forth in this Agreement do not apply to information that:</p>
<ul>
<li>Is or becomes publicly available through no breach of this Agreement by Recipient</li>
<li>Is rightfully received by Recipient from a third party without breach of any confidentiality obligation</li>
<li>Is independently developed by Recipient without use of or reference to the Confidential Information</li>
<li>Is required to be disclosed by law or court order, provided Recipient gives Company prompt written notice and cooperates in any effort to seek protective measures</li>
</ul>

<h3>5. Return of Information</h3>
<p>Upon Company\'s request or termination of discussions, Recipient agrees to promptly return or destroy all Confidential Information and certify in writing that such return or destruction has been completed.</p>

<h3>6. No Investment Commitment</h3>
<p>This Agreement does not create any obligation for either party to proceed with any investment transaction. Company retains the right to reject any investment proposal at its sole discretion.</p>

<h3>7. Duration</h3>
<p>The confidentiality obligations under this Agreement shall remain in effect for a period of five (5) years from the date of signature, or for so long as the information remains confidential and proprietary, whichever is longer.</p>

<h3>8. No License</h3>
<p>Nothing in this Agreement grants Recipient any license, right, or interest in any intellectual property, trade secrets, or confidential information of Company.</p>

<h3>9. Remedies</h3>
<p>Recipient acknowledges that breach of this Agreement may cause irreparable harm to Company for which monetary damages would be an inadequate remedy. Company shall be entitled to seek equitable relief, including injunction and specific performance, in addition to all other remedies available at law or in equity.</p>

<h3>10. Governing Law</h3>
<p>This Agreement shall be governed by and construed in accordance with the laws of the State of Delaware, without regard to its conflict of law provisions.</p>

<h3>11. Digital Acceptance</h3>
<p>By clicking \"Accept\" or providing digital signature, Recipient acknowledges that they have read, understood, and agree to be bound by all terms and conditions of this Agreement. Recipient confirms they have the authority to enter into this binding agreement.</p>

<p style="margin-top:24px;"><strong>By accepting this agreement, you acknowledge that you understand the confidential nature of the information you will access and agree to protect it accordingly.</strong></p>',
  1,
  1,
  NOW()
)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `content` = VALUES(`content`),
  `version` = VALUES(`version`),
  `is_active` = VALUES(`is_active`),
  `updated_at` = NOW();

-- Insert investor platform access agreement
INSERT INTO `agreement_templates` (`agreement_type`, `title`, `content`, `version`, `is_active`, `created_at`)
VALUES (
  'investor_platform_access',
  'Investor Platform Access Agreement',
  '<h2>Investor Platform Access Agreement</h2>
<p>This Investor Platform Access Agreement (\"Agreement\") is entered into as of the date of digital signature below by and between the Investor (hereinafter \"User\") and Product LLC (hereinafter \"Company\").</p>

<h3>1. Platform Access Grant</h3>
<p>Subject to the terms of this Agreement and the accompanying Non-Disclosure Agreement, Company grants User limited, non-exclusive, non-transferable access to its proprietary All-In-One Industry Platform (\"Platform\") for the purpose of evaluating the Company\'s technology, business operations, and investment opportunities.</p>

<h3>2. Scope of Access</h3>
<p>User\'s access to the Platform includes:</p>
<ul>
<li>Read-only access to platform documentation and technical specifications</li>
<li>Visibility into system architecture and feature demonstrations</li>
<li>Access to business metrics, operational dashboards, and performance analytics</li>
<li>Product and manufacturing process information</li>
<li>Financial and accounting system views (where applicable)</li>
</ul>

<h3>3. Restrictions and Prohibited Activities</h3>
<p>User expressly agrees NOT to:</p>
<ul>
<li>Copy, reproduce, or reverse engineer any aspect of the Platform</li>
<li>Attempt to access areas of the Platform beyond those explicitly granted</li>
<li>Download, scrape, or systematically extract data from the Platform</li>
<li>Share login credentials or access with any third party</li>
<li>Use the Platform for any purpose other than investment evaluation</li>
<li>Attempt to circumvent any security measures or access controls</li>
<li>Interfere with the normal operation of the Platform</li>
<li>Use information obtained from the Platform to compete with Company</li>
</ul>

<h3>4. Intellectual Property Rights</h3>
<p>User acknowledges and agrees that:</p>
<ul>
<li>The Platform and all related technology are the exclusive property of Company</li>
<li>All intellectual property rights in the Platform remain with Company</li>
<li>This Agreement does not convey any ownership rights or licenses beyond the limited access described herein</li>
<li>Any improvements, suggestions, or feedback provided by User become the property of Company</li>
</ul>

<h3>5. Data Privacy and Security</h3>
<p>User acknowledges that the Platform contains sensitive business and customer data. User agrees to:</p>
<ul>
<li>Respect the privacy of all individuals whose data is visible in the Platform</li>
<li>Not use customer data or personal information for any purpose</li>
<li>Not disclose personal or business data to third parties</li>
<li>Report any security vulnerabilities or data breaches immediately to Company</li>
</ul>

<h3>6. Investment Evaluation Only</h3>
<p>User\'s access to the Platform is provided solely for the purpose of conducting investment due diligence. This Agreement does not:</p>
<ul>
<li>Constitute an offer to sell securities or investment interests</li>
<li>Create any obligation for User to invest</li>
<li>Create any obligation for Company to accept investment</li>
<li>Grant User any rights as a shareholder, partner, or owner</li>
</ul>

<h3>7. Termination of Access</h3>
<p>Company reserves the right to:</p>
<ul>
<li>Terminate or suspend User\'s access at any time without notice</li>
<li>Modify the scope of access or features available to User</li>
<li>Monitor User\'s activity on the Platform for security and compliance purposes</li>
<li>Remove access if User violates any term of this Agreement or the NDA</li>
</ul>

<h3>8. No Warranties</h3>
<p>The Platform is provided on an \"as is\" basis. Company makes no warranties, express or implied, regarding:</p>
<ul>
<li>The accuracy, completeness, or currency of information in the Platform</li>
<li>The availability or uninterrupted operation of the Platform</li>
<li>The fitness of the Platform for any particular purpose</li>
<li>The results that may be obtained from investment in Company</li>
</ul>

<h3>9. Limitation of Liability</h3>
<p>To the maximum extent permitted by law, Company shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from User\'s access to or use of the Platform.</p>

<h3>10. Compliance with Laws</h3>
<p>User agrees to comply with all applicable federal, state, and local laws in connection with their access to and use of the Platform, including but not limited to securities laws, data privacy regulations, and export control laws.</p>

<h3>11. Account Security</h3>
<p>User is responsible for:</p>
<ul>
<li>Maintaining the confidentiality of login credentials</li>
<li>All activities that occur under their account</li>
<li>Notifying Company immediately of any unauthorized access or security breach</li>
</ul>

<h3>12. Updates and Modifications</h3>
<p>Company reserves the right to update or modify this Agreement at any time. Continued access to the Platform following such changes constitutes acceptance of the modified terms.</p>

<h3>13. Governing Law and Dispute Resolution</h3>
<p>This Agreement shall be governed by the laws of the State of Delaware. Any disputes arising from this Agreement shall be resolved through binding arbitration in accordance with the rules of the American Arbitration Association.</p>

<h3>14. Digital Acceptance</h3>
<p>By clicking \"Accept & Continue\" or providing digital signature, User acknowledges that they have read, understood, and agree to be bound by all terms and conditions of this Agreement. User confirms they have the authority to enter into this binding agreement.</p>

<p style="margin-top:24px;"><strong>This agreement works in conjunction with the Non-Disclosure Agreement to protect Company\'s proprietary information and intellectual property. Both agreements must be accepted to gain access to the Platform.</strong></p>',
  1,
  1,
  NOW()
)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `content` = VALUES(`content`),
  `version` = VALUES(`version`),
  `is_active` = VALUES(`is_active`),
  `updated_at` = NOW();



