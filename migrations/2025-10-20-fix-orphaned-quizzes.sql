-- ============================================================
-- Migration: Fix Orphaned Quiz Questions
-- Date: 2025-10-20
-- Purpose: Link existing quiz questions to correct products
-- 
-- Issue: Quizzes created from product pages weren't linking
-- because product_id dropdown wasn't being submitted
-- ============================================================

-- Update quiz questions 25, 26, 27, 28, 29 to link to product 25
-- (Based on error log showing these were created for product_id=25)
UPDATE training_quiz_questions 
SET product_id = 25,
    updated_at = NOW()
WHERE id IN (25, 26, 27, 28, 29)
  AND product_id IS NULL;

-- Log the fix
SELECT CONCAT('✓ Fixed ', ROW_COUNT(), ' orphaned quiz questions for product 25') AS status;

-- Verify the fix
SELECT 
    tqq.id,
    tqq.product_id,
    tqq.question_text,
    p.name as product_name
FROM training_quiz_questions tqq
LEFT JOIN products p ON p.id = tqq.product_id
WHERE tqq.id IN (25, 26, 27, 28, 29);

-- Check for any remaining orphaned quizzes
SELECT 
    COUNT(*) as orphaned_count
FROM training_quiz_questions
WHERE product_id IS NULL
  AND is_active = 1;

SELECT 'Orphaned Quiz Fix Complete' AS status;



