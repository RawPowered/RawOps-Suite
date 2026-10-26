-- Migration: Add investor and retail_customer roles
-- Date: 2025-10-21
-- Description: Add new roles for investor platform access and retail customer purchases at MSRP

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Insert investor role
INSERT INTO `roles` (`id`, `name`) VALUES (9, 'investor')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Insert retail_customer role
INSERT INTO `roles` (`id`, `name`) VALUES (10, 'retail_customer')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Note: Trainee and sales_agent roles should already exist from previous migrations
-- This migration is idempotent and safe to run multiple times

