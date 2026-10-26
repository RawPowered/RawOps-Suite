-- Migration: Update Training Module Content for Line-Item Commissions
-- Date: 2025-09-11
-- Purpose: Update training module content to reflect correct line-item commission calculations

-- Update the CRM Mastery module content
UPDATE `training_modules` 
SET `content` = 'Master the Product CRM system and understand the LINE-ITEM commission structure. Each product line on an invoice has its own commission rate based on quantity: 1-4 items (20%), 5-9 items (20%), 10-14 items (15%), 15-19 items (12%), 20+ items (10%). Developer commission is always 5% of total invoice.' 
WHERE `slug` = 'crm-mastery';

-- Update the Ten-Step Close module content
UPDATE `training_modules` 
SET `content` = 'Master the proven ten-step sales methodology with correct commission understanding. Commissions are calculated PER LINE ITEM, not per invoice. Each product line uses its own quantity to determine the rate. Developer gets 5% of total invoice value.' 
WHERE `slug` = 'ten-step-close';

-- Update the AI-Assisted Sales module content
UPDATE `training_modules` 
SET `content` = 'Leverage AI tools to multiply sales effectiveness while understanding line-item commission calculations. Each order line has its own commission rate based on its quantity, not the total invoice quantity.' 
WHERE `slug` = 'ai-assisted-sales';

-- Update the Final Assessment module content
UPDATE `training_modules` 
SET `content` = 'Comprehensive review of key concepts including the critical understanding that commissions are calculated per line item, with each product line having its own rate based on quantity. Developer commission is 5% of total invoice.' 
WHERE `slug` = 'final-assessment';

-- Add a new training note about commission structure
INSERT IGNORE INTO `training_modules` (`slug`, `title`, `content`, `duration_minutes`, `required`, `sort_order`) VALUES
('commission-structure', 'Commission Structure Deep Dive', 'CRITICAL: Understand that commissions are calculated PER LINE ITEM. Invoice Example: Line 1: 5x Product A = 20% commission rate, Line 2: 15x Product B = 12% commission rate. Developer always gets 5% of total invoice value. This is different from many other commission systems!', 15, 1, 10);
