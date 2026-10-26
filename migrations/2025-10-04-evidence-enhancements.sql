-- Evidence Locker Enhancements
-- Date: 2025-10-04
-- Purpose: Add chronological tracking, exhibit numbers, and enhanced metadata for evidence

START TRANSACTION;

-- Add chronological and organizational fields to legal_case_documents
ALTER TABLE `legal_case_documents`
ADD COLUMN `evidence_date` DATE DEFAULT NULL COMMENT 'Date evidence was created/captured' AFTER `document_type`,
ADD COLUMN `timeline_date` DATE DEFAULT NULL COMMENT 'Date for case chronological timeline' AFTER `evidence_date`,
ADD COLUMN `exhibit_number` VARCHAR(40) DEFAULT NULL COMMENT 'Formal exhibit number (e.g., Exhibit A, Plaintiff-001)' AFTER `timeline_date`,
ADD COLUMN `sort_order` INT DEFAULT NULL COMMENT 'Manual sort order' AFTER `exhibit_number`,
ADD COLUMN `tags` JSON DEFAULT NULL COMMENT 'Searchable tags' AFTER `sort_order`,
ADD COLUMN `is_key_evidence` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag as critical evidence' AFTER `tags`,
ADD COLUMN `notes` TEXT DEFAULT NULL COMMENT 'Internal notes about evidence' AFTER `is_key_evidence`;

-- Add indexes for sorting and filtering
ALTER TABLE `legal_case_documents`
ADD KEY `idx_documents_evidence_date` (`case_id`, `evidence_date`),
ADD KEY `idx_documents_timeline_date` (`case_id`, `timeline_date`),
ADD KEY `idx_documents_exhibit` (`case_id`, `exhibit_number`),
ADD KEY `idx_documents_sort` (`case_id`, `sort_order`),
ADD KEY `idx_documents_key_evidence` (`case_id`, `is_key_evidence`);

COMMIT;






