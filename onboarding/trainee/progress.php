<?php
require_once __DIR__ . '/../../required/config.php';
start_session();
$uid = current_user_id();
if (!$uid) redirect('/index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $moduleId = intval($_POST['module_id'] ?? 0);
    if ($action === 'complete' && $moduleId > 0) {
        $pdo = db();
        try {
            // Check if training_progress table exists, if not use training_activity
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'training_progress'")->fetch();
            
            if ($tableCheck) {
                // Use training_progress table
                $pdo->prepare('INSERT INTO training_progress (user_id, module_id, status, started_at, completed_at)
                               VALUES (?, ?, "completed", NOW(), NOW())
                               ON DUPLICATE KEY UPDATE status = "completed", completed_at = NOW()')
                    ->execute([$uid, $moduleId]);
            } else {
                // Fallback to training_activity table
                $pdo->prepare('INSERT INTO training_activity (user_id, module_id, activity_type, activity_data, created_at)
                               VALUES (?, ?, "module_completion", JSON_OBJECT("status", "completed"), NOW())')
                    ->execute([$uid, $moduleId]);
            }
        } catch (Throwable $e) {
            // Log error but continue - don't break user flow
            error_log("Training progress error: " . $e->getMessage());
        }
    }
}
redirect('/onboarding/trainee/index.php');
?>


