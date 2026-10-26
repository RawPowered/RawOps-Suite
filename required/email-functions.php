<?php
/**
 * Email Helper Functions
 * Provides utility functions for email operations
 */

/**
 * Legacy function - no longer adds AI disclaimer
 * Kept for compatibility
 * 
 * @param string $emailHtml The HTML content of the email
 * @return string Email HTML unchanged
 */
function add_ai_disclaimer($emailHtml) {
    // No longer adding AI disclaimers to avoid spam flags
    return $emailHtml;
}

/**
 * Check if email content should have AI disclaimer
 * (For future filtering if needed)
 * 
 * @param string $emailType Type of email being sent
 * @return bool Whether to include AI disclaimer
 */
function should_include_ai_disclaimer($emailType = 'general') {
    // Currently all emails get the disclaimer
    // Future: could exclude certain system emails
    $excludedTypes = []; // e.g., ['password_reset', 'verification']
    
    return !in_array($emailType, $excludedTypes);
}




