-- Fix Open Transactions
-- Date: 2025-10-11
-- Purpose: Clean up any open transactions that may be blocking operations
-- Run this if you get "There is already an active transaction" errors

-- Rollback any open transactions
ROLLBACK;

-- Commit to ensure clean state
COMMIT;

-- Verify transaction status
SELECT @@autocommit AS autocommit_status;

-- Should return 1 (autocommit is ON)





