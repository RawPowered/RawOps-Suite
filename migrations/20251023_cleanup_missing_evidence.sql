-- Clean up missing evidence files from database
-- This removes database references to files that don't physically exist

-- List of missing files that need to be removed or re-uploaded
-- These files are in the database but not in the evidence-locker directory:
-- 1759606806_1000078698__1_.jpg (Document ID 33)
-- 1759606829_1000078697.jpg (Document ID 34)  
-- 1759606873_Legal_Analysis_...pdf (Document ID 35)
-- 1759606950_Screenshot_20251003_184533_Regions.jpg (Document ID 36)
-- 1759607020_Screenshot_20251003_184435_Regions.jpg (Document ID 38)

-- Option 1: DELETE these records (recommended if files are permanently lost)
DELETE FROM legal_case_documents WHERE id IN (33, 34, 35, 36, 38);

-- Also clean up related activity logs
DELETE FROM legal_case_activity WHERE activity_payload LIKE '%1759606806%'
    OR activity_payload LIKE '%1759606829%'
    OR activity_payload LIKE '%1759606873%'
    OR activity_payload LIKE '%1759606950%'
    OR activity_payload LIKE '%1759607020%';

-- Option 2: If you want to keep the records but update them to point to existing files
-- You would need to first upload the files again, then update the storage_path




