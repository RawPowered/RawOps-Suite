-- ============================================================================
-- MASTER MIGRATION RUNNER: LMS Overhaul
-- Date: 2025-10-13
-- Executes all LMS overhaul migrations in correct sequence
-- ============================================================================
--
-- BACKUP DATABASE BEFORE RUNNING THIS FILE!
--
-- This file combines all 6 migration files in the correct order:
-- 1. Multi-course architecture
-- 2. Quiz database system  
-- 3. Sales workouts
-- 4. Product specification updates
-- 5. Module cleanup
-- 6. Seed workout decks
--
-- Run this file once to execute complete LMS overhaul
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=0;
SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT;
SET AUTOCOMMIT=0;

START TRANSACTION;

-- ============================================================================
-- MIGRATION 1: MULTI-COURSE ARCHITECTURE
-- ============================================================================

SOURCE migrations/2025-10-13-multi-course-architecture.sql;

-- ============================================================================
-- MIGRATION 2: QUIZ DATABASE SYSTEM
-- ============================================================================

SOURCE migrations/2025-10-13-quiz-database-system.sql;

-- ============================================================================
-- MIGRATION 3: SALES WORKOUTS
-- ============================================================================

SOURCE migrations/2025-10-13-sales-workouts.sql;

-- ============================================================================
-- MIGRATION 4: Product specification UPDATES
-- ============================================================================

SOURCE migrations/2025-10-13-product-product specification-updates.sql;

-- ============================================================================
-- MIGRATION 5: MODULE CLEANUP
-- ============================================================================

SOURCE migrations/2025-10-13-module-cleanup.sql;

-- ============================================================================
-- MIGRATION 6: SEED WORKOUT DECKS
-- ============================================================================

SOURCE migrations/2025-10-13-seed-workout-decks.sql;

-- ============================================================================
-- COMMIT ALL CHANGES
-- ============================================================================

COMMIT;

SET FOREIGN_KEY_CHECKS=1;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
-- Next steps:
-- 1. Navigate to /admin/migrate-quizzes-to-db.php to extract quizzes from PHP files
-- 2. Test trainee portal at /onboarding/trainee/
-- 3. Test Sales Workouts at /onboarding/trainee/sales-workouts.php
-- 4. Verify products updated: GOLD-40-32 and BLACK-80-32
-- ============================================================================



