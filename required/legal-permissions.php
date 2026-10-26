<?php

require_once __DIR__ . '/config.php';

if (!function_exists('require_legal_or_admin')) {
    function require_legal_or_admin(?string $redirectTo = null): void {
        $userId = current_user_id();
        if (!$userId) {
            // Not logged in - redirect to legal auth gate
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
            redirect(path_with_base('/admin/legal/auth-gate.php?return=' . urlencode($currentUrl)));
        }
        if (user_has_role($userId, 'admin') || user_has_role($userId, 'legal')) {
            return;
        }
        // Logged in but no legal access - redirect to auth gate
        redirect(path_with_base('/admin/legal/auth-gate.php'));
    }
}

function legal_user_can_access_case(int $userId, int $caseId): bool {
    if (user_has_role($userId, 'admin')) {
        return true;
    }
    if (!user_has_role($userId, 'legal')) {
        return false;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM legal_case_team WHERE case_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$caseId, $userId]);
    return (bool)$stmt->fetchColumn();
}


