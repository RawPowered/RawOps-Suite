-- 2025-09-25 — Google OAuth, Email Templates, Notification Rules, Meetings
-- Follows rules in 00-PLANNING/MDS/002-SQL-FORMATTING-RULES.md

START TRANSACTION;

-- Token store for Google OAuth (per-user)
CREATE TABLE IF NOT EXISTS `google_oauth_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `provider` varchar(40) NOT NULL DEFAULT 'google',
  `scopes` text DEFAULT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text DEFAULT NULL,
  `token_type` varchar(20) DEFAULT 'Bearer',
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_provider` (`user_id`,`provider`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_google_oauth_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email templates with HTML/CSS support and variables
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` varchar(120) NOT NULL,
  `name` varchar(200) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `html_body` longtext NOT NULL,
  `text_body` longtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification rules linking events to templates and audience
CREATE TABLE IF NOT EXISTS `email_notification_rules` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_key` varchar(120) NOT NULL,
  `template_id` bigint(20) UNSIGNED NOT NULL,
  `condition_sql` text DEFAULT NULL, -- optional SQL WHERE fragment evaluated server-side
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_active` (`event_key`,`is_active`),
  CONSTRAINT `fk_rule_template` FOREIGN KEY (`template_id`) REFERENCES `email_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Meeting records mapped to Google Calendar events
CREATE TABLE IF NOT EXISTS `crm_meetings` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `lead_id` bigint(20) UNSIGNED DEFAULT NULL,
  `account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `summary` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `event_id` varchar(128) DEFAULT NULL,
  `conference_id` varchar(128) DEFAULT NULL,
  `meet_link` varchar(255) DEFAULT NULL,
  `timezone` varchar(64) DEFAULT 'UTC',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_agent_time` (`agent_id`,`start_time`),
  KEY `idx_lead_time` (`lead_id`,`start_time`),
  KEY `idx_account_time` (`account_id`,`start_time`),
  CONSTRAINT `fk_meeting_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_meeting_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_meeting_account` FOREIGN KEY (`account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed basic templates (safe idempotent)
INSERT IGNORE INTO `email_templates` (`slug`, `name`, `subject`, `html_body`, `text_body`) VALUES
('first-signup','Welcome — First Signup','Welcome to Product','<h1>Welcome {{contact.first_name}}</h1><p>Thanks for signing up.</p>', 'Welcome {{contact.first_name}}'),
('first-purchase','Thank You — First Purchase','Thanks for your first purchase','<h1>Thank you!</h1><p>Your first order is confirmed.</p>', 'Thanks for your first order.'),
('new-invoice','New Invoice','You have a new invoice','<p>Invoice {{invoice.number}} for {{invoice.total}}</p>', 'Invoice available.'),
('meeting-reminder','Meeting Reminder','Reminder: {{meeting.summary}}','<p>This is a reminder for {{meeting.summary}} at {{meeting.start_time}}</p>', 'Meeting reminder.'),
('meeting-created','New Meeting Scheduled','New Meeting: {{meeting.summary}}','<p>Meeting scheduled for {{meeting.start_time}}</p>', 'Meeting scheduled.'),
('task-upcoming','Task Upcoming Reminder','Task due soon','<p>Your task "{{task.title}}" is due on {{task.due_date}}</p>', 'Task due soon.'),
('agent-oldest-leads','Top 3 Oldest Leads','Leads to follow up','<p>Please follow up the 3 oldest leads in your queue.</p>', 'Follow up your top 3 oldest leads.'),
('post-account-creation','Welcome — Account Created','Your wholesale account is ready','<p>Your Product wholesale account has been created.</p>', 'Your wholesale account is ready.'),
('signup-email-flow','Thanks for signing up','We received your request','<p>We received your signup request and are reviewing it.</p>', 'We received your request.'),
('blank-disabled-1','Custom Automation Placeholder','Subject','<p>Body</p>','Body'),
('blank-disabled-2','Custom Automation Placeholder 2','Subject','<p>Body</p>','Body');

COMMIT;


