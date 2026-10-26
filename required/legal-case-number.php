<?php

require_once __DIR__ . '/config.php';

/**
 * Generate next case number in format RAWOPS-MMYYYY-######
 * @return string Case number
 */
function generate_case_number(): string {
    $pdo = db();
    $monthYear = date('mY'); // e.g., "102025" for October 2025
    
    // Insert or update sequence
    $stmt = $pdo->prepare('
        INSERT INTO legal_case_number_sequence (month_year, last_sequence) 
        VALUES (?, 1)
        ON DUPLICATE KEY UPDATE last_sequence = last_sequence + 1
    ');
    $stmt->execute([$monthYear]);
    
    // Get the current sequence
    $stmt = $pdo->prepare('SELECT last_sequence FROM legal_case_number_sequence WHERE month_year = ? LIMIT 1');
    $stmt->execute([$monthYear]);
    $sequence = (int)$stmt->fetchColumn();
    
    // Format: RAWOPS-MMYYYY-######
    return sprintf('RAWOPS-%s-%06d', $monthYear, $sequence);
}

/**
 * Censor name for privacy
 * Shows first name and last 3 letters of last name
 * Example: "Example Party A" -> "Example ***ty A"
 */
function censor_name(string $firstName, string $lastName): string {
    $first = trim($firstName);
    $last = trim($lastName);
    
    if (strlen($last) <= 3) {
        return $first . ' ***';
    }
    
    $lastThree = substr($last, -3);
    $censored = str_repeat('*', strlen($last) - 3) . $lastThree;
    
    return $first . ' ' . $censored;
}

/**
 * Censor case title for privacy in public views
 * Example: "Example Party A vs. Example Party B" -> "Example ***ty A vs. Example ***ty B"
 */
function censor_case_title(string $title): string {
    // Match pattern: FirstName LastName vs. FirstName LastName
    $pattern = '/([A-Z][a-z]+)\s+([A-Z][a-z]+)/';
    
    return preg_replace_callback($pattern, function($matches) {
        return censor_name($matches[1], $matches[2]);
    }, $title);
}






