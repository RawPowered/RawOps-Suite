-- Migration: 2025-10-13 Sales Workouts Flash Card System
-- Standalone learning reinforcement system with spaced repetition
-- Helps sales agents memorize product specs, objections, and compliance

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=0;

-- ============================================================================
-- 1. SALES WORKOUT DECKS
-- ============================================================================
CREATE TABLE IF NOT EXISTS `sales_workout_decks` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('product_knowledge','objection_handling','profit_calculations','compliance','general') NOT NULL DEFAULT 'general',
  `difficulty` enum('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner',
  `icon` varchar(50) DEFAULT NULL COMMENT 'Emoji or icon identifier',
  `card_count` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. SALES WORKOUT CARDS
-- ============================================================================
CREATE TABLE IF NOT EXISTS `sales_workout_cards` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deck_id` int(10) UNSIGNED NOT NULL,
  `front_text` text NOT NULL COMMENT 'Question or prompt',
  `back_text` text NOT NULL COMMENT 'Answer or explanation',
  `hints` text DEFAULT NULL COMMENT 'Optional hints to show before revealing answer',
  `product_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Link to product if card is product-specific',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `difficulty_level` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1-5 difficulty scale',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deck_id` (`deck_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. SALES WORKOUT PROGRESS (Individual Card Tracking)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `sales_workout_progress` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `deck_id` int(10) UNSIGNED NOT NULL,
  `card_id` bigint(20) UNSIGNED NOT NULL,
  `confidence_level` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1-5 stars: how well user knows this card',
  `review_count` int(11) NOT NULL DEFAULT 0,
  `correct_count` int(11) NOT NULL DEFAULT 0,
  `last_reviewed_at` timestamp NULL DEFAULT NULL,
  `next_review_at` timestamp NULL DEFAULT NULL COMMENT 'Spaced repetition scheduling',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_card` (`user_id`, `card_id`),
  KEY `idx_user_deck` (`user_id`, `deck_id`),
  KEY `idx_next_review` (`next_review_at`),
  KEY `idx_confidence` (`confidence_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. SALES WORKOUT SESSIONS (Practice Session Tracking)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `sales_workout_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `deck_id` int(10) UNSIGNED NOT NULL,
  `session_type` enum('quick_review','full_deck','spaced_repetition','random') NOT NULL DEFAULT 'full_deck',
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cards_reviewed` int(11) NOT NULL DEFAULT 0,
  `cards_correct` int(11) NOT NULL DEFAULT 0,
  `avg_confidence_before` decimal(3,2) DEFAULT NULL,
  `avg_confidence_after` decimal(3,2) DEFAULT NULL,
  `total_time_seconds` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_deck_id` (`deck_id`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. UPDATE DECK CARD COUNTS (TRIGGER)
-- ============================================================================
DROP TRIGGER IF EXISTS `update_deck_card_count_insert`;
CREATE TRIGGER `update_deck_card_count_insert` AFTER INSERT ON `sales_workout_cards`
FOR EACH ROW
UPDATE `sales_workout_decks` SET `card_count` = (
  SELECT COUNT(*) FROM `sales_workout_cards` WHERE `deck_id` = NEW.deck_id AND `active` = 1
) WHERE `id` = NEW.deck_id;

DROP TRIGGER IF EXISTS `update_deck_card_count_update`;
CREATE TRIGGER `update_deck_card_count_update` AFTER UPDATE ON `sales_workout_cards`
FOR EACH ROW
UPDATE `sales_workout_decks` SET `card_count` = (
  SELECT COUNT(*) FROM `sales_workout_cards` WHERE `deck_id` = NEW.deck_id AND `active` = 1
) WHERE `id` = NEW.deck_id;

DROP TRIGGER IF EXISTS `update_deck_card_count_delete`;
CREATE TRIGGER `update_deck_card_count_delete` AFTER DELETE ON `sales_workout_cards`
FOR EACH ROW
UPDATE `sales_workout_decks` SET `card_count` = (
  SELECT COUNT(*) FROM `sales_workout_cards` WHERE `deck_id` = OLD.deck_id AND `active` = 1
) WHERE `id` = OLD.deck_id;

SET FOREIGN_KEY_CHECKS=1;

-- Migration complete: Sales Workouts flash card system ready



