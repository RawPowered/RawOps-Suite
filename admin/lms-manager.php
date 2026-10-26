<?php
$SEO = [
    'title' => 'LMS Manager — Complete Learning Management System',
    'description' => 'Comprehensive learning management system for content, quizzes, analytics, and trainee management.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Get current section and action
$section = $_GET['section'] ?? 'dashboard';
$action = $_GET['action'] ?? 'list';
$moduleSlug = $_GET['module'] ?? '';
$userId = $_GET['user'] ?? '';

// Handle AJAX requests for loading quizzes
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_quizzes') {
    $moduleSlug = $_GET['module'] ?? '';
    if ($moduleSlug) {
        $quizzes = extractQuizzesFromModule($moduleSlug);
        header('Content-Type: application/json');
        echo json_encode($quizzes);
        exit;
    }
}

// Handle all LMS actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    
    try {
        // Content Management Actions
        if ($postAction === 'save_content') {
            $slug = $_POST['module_slug'];
            $content = $_POST['content'];
            $title = $_POST['title'];
            $duration = intval($_POST['duration']);
            
            $stmt = $pdo->prepare('UPDATE training_modules SET title = ?, duration_minutes = ? WHERE slug = ?');
            $stmt->execute([$title, $duration, $slug]);
            
            $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $slug . '.php';
            if (file_put_contents($moduleFile, $content)) {
                flash_set('success', 'Module content saved successfully! Shortcodes will render dynamically.');
            } else {
                throw new Exception('Failed to save module file.');
            }
        }
        
        elseif ($postAction === 'create_module') {
            $slug = trim($_POST['slug']);
            $title = trim($_POST['title']);
            $duration = intval($_POST['duration']);
            $sortOrder = intval($_POST['sort_order']);
            $required = isset($_POST['required']) ? 1 : 0;
            
            $stmt = $pdo->prepare('INSERT INTO training_modules (slug, title, duration_minutes, sort_order, required) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$slug, $title, $duration, $sortOrder, $required]);
            
            $template = generateModuleTemplate($title);
            $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $slug . '.php';
            file_put_contents($moduleFile, $template);
            
            flash_set('success', 'New module created successfully!');
        }
        
        // Quiz Management Actions
        elseif ($postAction === 'create_quiz') {
            // Quiz creation now handled by quiz-question-edit.php
            // This is legacy fallback
            flash_set('info', 'Please use the dedicated quiz editor for creating questions.');
        }
        
        elseif ($postAction === 'delete_quiz') {
            $quizId = intval($_POST['quiz_id'] ?? 0);
            if ($quizId > 0) {
                $pdo->prepare('UPDATE training_quiz_questions SET is_active = 0 WHERE id = ?')->execute([$quizId]);
                flash_set('success', 'Quiz question deleted successfully!');
            }
        }
        
        elseif ($postAction === 'insert_quiz_into_module') {
            $moduleSlug = $_POST['target_module_slug'];
            $quizHtml = $_POST['quiz_html'];
            
            $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $moduleSlug . '.php';
            if (file_exists($moduleFile)) {
                $currentContent = file_get_contents($moduleFile);
                
                // Find insertion point (before takeaways section or at end)
                $insertionPoint = strpos($currentContent, '<h3>💡 Key Takeaways</h3>');
                if ($insertionPoint === false) {
                    // Insert at end before closing tags
                    $insertionPoint = strrpos($currentContent, '</div>');
                    if ($insertionPoint === false) {
                        $insertionPoint = strlen($currentContent);
                    }
                }
                
                $newContent = substr($currentContent, 0, $insertionPoint) . 
                             "\n<h3>Knowledge Check</h3>\n\n" . $quizHtml . "\n\n" . 
                             substr($currentContent, $insertionPoint);
                
                if (file_put_contents($moduleFile, $newContent)) {
                    flash_set('success', 'Quiz successfully inserted into module!');
                } else {
                    flash_set('error', 'Failed to write quiz to module file.');
                }
            } else {
                flash_set('error', 'Module file not found.');
            }
        }
        
        // Trainee Management Actions
        elseif ($postAction === 'bulk_action') {
            $bulkAction = $_POST['bulk_action'];
            $userIds = $_POST['user_ids'] ?? [];
            
            if ($bulkAction === 'approve_trainees' && !empty($userIds)) {
                $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id IN ($placeholders)");
                $stmt->execute($userIds);
                flash_set('success', count($userIds) . ' trainee(s) approved successfully.');
            }
            
            elseif ($bulkAction === 'promote_to_agent' && !empty($userIds)) {
                $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id IN ($placeholders) AND role_id = 1");
                $stmt->execute($userIds);
                
                foreach ($userIds as $userId) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, 2)");
                    $stmt->execute([$userId]);
                }
                flash_set('success', count($userIds) . ' trainee(s) promoted to Sales Agent.');
            }
            
            elseif ($bulkAction === 'reset_training' && !empty($userIds)) {
                $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM training_progress WHERE user_id IN ($placeholders)");
                $stmt->execute($userIds);
                $stmt = $pdo->prepare("DELETE FROM training_quiz_results WHERE user_id IN ($placeholders)");
                $stmt->execute($userIds);
                flash_set('success', 'Training reset for ' . count($userIds) . ' trainee(s).');
            }
        }
        
        elseif ($postAction === 'send_message') {
            $userIds = $_POST['user_ids'] ?? [];
            $message = trim($_POST['message'] ?? '');
            if (!empty($message) && !empty($userIds)) {
                foreach ($userIds as $userId) {
                    $stmt = $pdo->prepare("INSERT INTO training_admin_alerts (user_id, alert_type, alert_message, severity) VALUES (?, 'admin_message', ?, 'medium')");
                    $stmt->execute([$userId, $message]);
                }
                flash_set('success', 'Message sent to ' . count($userIds) . ' trainee(s).');
            }
        }
        
    } catch (Exception $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get only active modules for Sales Fundamentals course (course_id = 1, sort_order < 100)
$modules = $pdo->query('
    SELECT * FROM training_modules 
    WHERE course_id = 1 
      AND sort_order < 100 
      AND required = 1
    ORDER BY sort_order
')->fetchAll();

// Get LMS statistics
$stats = getLMSStatistics($pdo);

function getLMSStatistics($pdo) {
    $stats = [];
    
    // Total modules
    $stmt = $pdo->query('SELECT COUNT(*) FROM training_modules');
    $stats['total_modules'] = $stmt->fetchColumn();
    
    // Total trainees
    $stmt = $pdo->query('SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name = "trainee"');
    $stats['total_trainees'] = $stmt->fetchColumn();
    
    // Active trainees (started but not completed)
    $stmt = $pdo->query('
        SELECT COUNT(DISTINCT u.id) 
        FROM users u 
        JOIN user_roles ur ON ur.user_id = u.id 
        JOIN roles r ON r.id = ur.role_id 
        JOIN training_progress tp ON tp.user_id = u.id
        WHERE r.name = "trainee" 
        AND tp.started_at IS NOT NULL 
        AND tp.status != "completed"
    ');
    $stats['active_trainees'] = $stmt->fetchColumn();
    
    // Completed training (sales agents)
    $stmt = $pdo->query('SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name = "sales_agent"');
    $stats['completed_trainees'] = $stmt->fetchColumn();
    
    // Recent quiz attempts
    $stmt = $pdo->query('SELECT COUNT(*) FROM training_quiz_results WHERE completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $stats['recent_quiz_attempts'] = $stmt->fetchColumn();
    
    // Average completion rate
    $stmt = $pdo->query('
        SELECT AVG(completion_percentage) FROM (
            SELECT (COUNT(DISTINCT tp.module_id) / (SELECT COUNT(*) FROM training_modules WHERE required = 1)) * 100 as completion_percentage
            FROM training_progress tp
            JOIN users u ON u.id = tp.user_id
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            WHERE r.name IN ("trainee", "sales_agent")
            GROUP BY u.id
        ) as completion_rates
    ');
    $stats['avg_completion_rate'] = round(floatval($stmt->fetchColumn()), 1);
    
    return $stats;
}

function generateModuleTemplate($title) {
    return <<<HTML
<h2>📚 {$title}</h2>

<div class="key-highlight">
    <h3>Learning Objectives</h3>
    <ul>
        <li>Objective 1</li>
        <li>Objective 2</li>
        <li>Objective 3</li>
    </ul>
</div>

<h3>Part 1: Introduction</h3>

<p>Module content goes here...</p>

<div class="special-note">
    <strong>Key Point:</strong> Important information for trainees.
</div>

<h3>Knowledge Check</h3>

<div class="quiz-question">
    <p><strong>Question 1:</strong> Sample question?</p>
    <div class="quiz-options">
        <label><input type="radio" name="q1" value="a"> Option A</label>
        <label><input type="radio" name="q1" value="b"> Option B</label>
        <label><input type="radio" name="q1" value="c"> Option C</label>
    </div>
    <p style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;" class="answer">
        <strong>Correct Answer: B</strong> - Explanation here.
    </p>
</div>

<script>
document.querySelectorAll('.quiz-options input').forEach(input => {
    input.addEventListener('change', function() {
        this.closest('.quiz-question').querySelector('.answer').style.display = 'block';
    });
});
</script>

<h3>💡 Key Takeaways</h3>

<div style="background: #f0f9ff; padding: 24px; border-radius: 12px; margin-top: 32px;">
    <ul style="margin: 0;">
        <li>Key point 1</li>
        <li>Key point 2</li>
        <li>Key point 3</li>
    </ul>
</div>
HTML;
}

function generateQuizComponent($question, $options, $correctAnswer, $explanation) {
    $questionId = 'q' . uniqid();
    $optionLabels = ['a', 'b', 'c', 'd'];
    $correctLabel = '';
    $optionsHtml = '';
    
    foreach ($optionLabels as $label) {
        if (isset($options[$label]) && !empty(trim($options[$label]))) {
            $optionsHtml .= "        <label><input type=\"radio\" name=\"{$questionId}\" value=\"{$label}\"> " . htmlspecialchars($options[$label]) . "</label>\n";
            if ($label === $correctAnswer) {
                $correctLabel = strtoupper($label);
            }
        }
    }
    
    // Properly escape all variables for safe HTML output
    $safeQuestion = htmlspecialchars($question);
    $safeExplanation = htmlspecialchars($explanation);
    
    $html = '<div class="quiz-question">' . "\n";
    $html .= '    <p><strong>Question:</strong> ' . $safeQuestion . '</p>' . "\n";
    $html .= '    <div class="quiz-options">' . "\n";
    $html .= $optionsHtml;
    $html .= '    </div>' . "\n";
    $html .= '    <p style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;" class="answer">' . "\n";
    $html .= '        <strong>Correct Answer: ' . $correctLabel . '</strong> - ' . $safeExplanation . "\n";
    $html .= '    </p>' . "\n";
    $html .= '</div>' . "\n\n";
    $html .= '<script>' . "\n";
    $html .= 'document.querySelectorAll(\'input[name="' . $questionId . '"]\').forEach(input => {' . "\n";
    $html .= '    input.addEventListener(\'change\', function() {' . "\n";
    $html .= '        this.closest(\'.quiz-question\').querySelector(\'.answer\').style.display = \'block\';' . "\n";
    $html .= '    });' . "\n";
    $html .= '});' . "\n";
    $html .= '</script>';
    
    return $html;
}

function extractQuizzesFromModule($moduleSlug) {
    $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $moduleSlug . '.php';
    if (!file_exists($moduleFile)) {
        return [];
    }
    
    $content = file_get_contents($moduleFile);
    $quizzes = [];
    
    // Extract quiz questions using regex
    $pattern = '/<div class="quiz-question">(.*?)<\/div>/s';
    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $index => $match) {
            $quizContent = $match[1];
            
            // Extract question
            if (preg_match('/<p><strong>Question.*?:<\/strong>\s*(.*?)<\/p>/s', $quizContent, $questionMatch)) {
                $question = strip_tags($questionMatch[1]);
            } else {
                $question = 'Question ' . ($index + 1);
            }
            
            // Extract options
            $options = [];
            if (preg_match_all('/<label><input[^>]*value="([a-d])"[^>]*>\s*(.*?)<\/label>/s', $quizContent, $optionMatches, PREG_SET_ORDER)) {
                foreach ($optionMatches as $optionMatch) {
                    $options[$optionMatch[1]] = strip_tags($optionMatch[2]);
                }
            }
            
            // Extract correct answer and explanation
            $correctAnswer = '';
            $explanation = '';
            if (preg_match('/<strong>Correct Answer:\s*([A-D])<\/strong>\s*-\s*(.*?)(?:<\/p>|$)/s', $quizContent, $answerMatch)) {
                $correctAnswer = strtolower($answerMatch[1]);
                $explanation = strip_tags($answerMatch[2]);
            }
            
            $quizzes[] = [
                'index' => $index,
                'question' => trim($question),
                'options' => $options,
                'correct_answer' => $correctAnswer,
                'explanation' => trim($explanation),
                'full_html' => $match[0]
            ];
        }
    }
    
    return $quizzes;
}
?>
<?php
// Lightweight LMS Resync Tool
// Path: /admin/lms-manager.php (appended section)
if (isset($_GET['action']) && $_GET['action'] === 'resync_modules') {
    require_once __DIR__ . '/../required/header.php';
    require_login_or_redirect('/login.php');
    if (!current_admin_id()) { echo '<div class="card" style="border-color:#fecaca;background:#fef2f2;">Admin only.</div>'; require_once __DIR__ . '/../required/footer.php'; exit; }

    $pdo = db();
    $report = [];

    try {
        $pdo->beginTransaction();

        // Detect ids
        $keepId = (int)($pdo->query("SELECT id FROM training_modules WHERE slug='products-product categories' LIMIT 1")->fetchColumn() ?: 0);
        $dupeId = (int)($pdo->query("SELECT id FROM training_modules WHERE slug='products-and-product categories' LIMIT 1")->fetchColumn() ?: 0);

        if ($keepId && $dupeId) {
            // Pre-delete duplicates that would violate unique constraints
            $stmt = $pdo->prepare("DELETE tp FROM training_progress tp JOIN training_progress tkeep ON tkeep.user_id = tp.user_id AND tkeep.module_id = ? WHERE tp.module_id = ?");
            $stmt->execute([$keepId, $dupeId]);

            $stmt = $pdo->prepare("DELETE tmr FROM training_module_reviews tmr JOIN training_module_reviews tkeep ON tkeep.user_id = tmr.user_id AND tkeep.module_id = ? WHERE tmr.module_id = ?");
            $stmt->execute([$keepId, $dupeId]);

            // Remap
            foreach ([
                'training_progress','training_quiz_results','training_module_reviews','training_module_analytics'
            ] as $table) {
                $stmt = $pdo->prepare("UPDATE {$table} SET module_id = ? WHERE module_id = ?");
                $stmt->execute([$keepId, $dupeId]);
            }
            $pdo->prepare("UPDATE admin_training_access SET accessed_module_id = ? WHERE accessed_module_id = ?")->execute([$keepId, $dupeId]);

            // Delete duplicate module row
            $pdo->prepare("DELETE FROM training_modules WHERE id = ?")->execute([$dupeId]);
            $report[] = "Merged duplicate 'products-and-product categories' into 'products-product categories' (kept ID {$keepId}).";
        }

        // Ensure unique slug index exists
        $hasIdx = (int)$pdo->query("SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'training_modules' AND index_name = 'uq_training_modules_slug'")->fetchColumn();
        if (!$hasIdx) {
            $pdo->exec("ALTER TABLE training_modules ADD UNIQUE KEY `uq_training_modules_slug` (`slug`)");
            $report[] = 'Added unique index on training_modules.slug';
        }

        // Normalize titles and sort orders
        $updates = [
            ['intro-product',        1, 'Module 1: Introduction to Product'],
            ['products-product categories',    2, 'Module 2: Product & Commission Mastery'],
            ['compliance-sales',    3, 'Module 3: Compliance & Ethical Sales'],
            ['crm-mastery',         4, 'Module 4: CRM Mastery'],
            ['final-assessment',    5, 'Module 5: Knowledge Review & Preparation'],
            ['ten-step-close',      6, 'Module 6: Advanced Sales Techniques'],
            ['ai-assisted-sales',   7, 'Module 7: AI-Powered Sales Mastery'],
            ['objection-mastery',   8, 'Module 8: Advanced Objection Handling'],
            ['final-exam',          9, 'Module 9: Sales Agent Certification'],
            ['commission-structure',11,'Module 11: Commission Structure Deep Dive']
        ];
        $stmt = $pdo->prepare("UPDATE training_modules SET sort_order = ?, title = ? WHERE slug = ?");
        foreach ($updates as [$slug,$order,$title]) { $stmt->execute([$order,$title,$slug]); }

        // Make commission-structure optional
        $pdo->exec("UPDATE training_modules SET required = 0 WHERE slug = 'commission-structure'");

        $pdo->commit();
        $report[] = 'Resync completed successfully.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $report[] = 'Error: ' . $e->getMessage();
    }

    echo '<div class="card" style="margin:16px;">';
    echo '<h3>LMS Resync Report</h3><ul>';
    foreach ($report as $line) { echo '<li>' . htmlspecialchars($line) . '</li>'; }
    echo '</ul><a class="btn" href="' . path_with_base('/admin/index.php') . '">Back to Admin</a></div>';
    require_once __DIR__ . '/../required/footer.php';
    exit;
}
?>

<style>
.lms-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
}

.lms-header {
    background: linear-gradient(135deg, #7c3aed, #a855f7);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
    text-align: center;
}

.lms-nav {
    display: flex;
    gap: 4px;
    margin-bottom: 24px;
    background: white;
    padding: 8px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    overflow-x: auto;
}

.lms-nav-item {
    padding: 12px 20px;
    background: transparent;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    color: #6b7280;
    text-decoration: none;
    transition: all 0.3s;
    white-space: nowrap;
}

.lms-nav-item:hover {
    background: #f3f4f6;
    color: #374151;
}

.lms-nav-item.active {
    background: #7c3aed;
    color: white;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    text-align: center;
}

.stat-value {
    font-size: 32px;
    font-weight: bold;
    margin: 8px 0;
}

.stat-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.content-grid {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 24px;
}

.sidebar {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 20px;
    height: fit-content;
}

.main-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
}

.module-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.3s;
}

.module-item:hover {
    background: #f3f4f6;
}

.module-item.active {
    background: #dbeafe;
    border: 2px solid #3b82f6;
}

.tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e5e7eb;
}

.tab-btn {
    padding: 12px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-weight: 600;
    color: #6b7280;
    transition: all 0.3s;
}

.tab-btn.active {
    color: #7c3aed;
    border-bottom-color: #7c3aed;
}

.tab-btn:hover {
    color: #a855f7;
}

.content-textarea {
    width: 100%;
    min-height: 600px;
    padding: 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: 14px;
    line-height: 1.6;
    resize: vertical;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #374151;
}

.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
}

.component-library {
    background: #f9fafb;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.component-btn {
    display: inline-block;
    padding: 6px 12px;
    margin: 4px;
    background: #3b82f6;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.component-btn:hover {
    background: #2563eb;
}

.analytics-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 24px;
}

.progress-bar {
    width: 200px;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(to right, #3b82f6, #10b981);
    transition: width 0.3s;
}

.module-indicators {
    display: flex;
    gap: 4px;
}

.module-dot {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: bold;
    color: white;
}

.module-not-started {
    background: #e5e7eb;
    color: #6b7280;
}

.module-in-progress {
    background: #fbbf24;
}

.module-completed {
    background: #10b981;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.status-active {
    background: #dbeafe;
    color: #1e40af;
}

.status-inactive {
    background: #f3f4f6;
    color: #6b7280;
}

.status-complete {
    background: #dcfce7;
    color: #14532d;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
}

.data-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.alert-item {
    padding: 12px;
    border-left: 4px solid #f59e0b;
    background: #fffbeb;
    margin-bottom: 8px;
    border-radius: 0 8px 8px 0;
}

.alert-critical { border-left-color: #dc2626; background: #fef2f2; }
.alert-high { border-left-color: #ea580c; background: #fff7ed; }
.alert-medium { border-left-color: #f59e0b; background: #fffbeb; }
.alert-low { border-left-color: #10b981; background: #f0fdf4; }

@media (max-width: 768px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .lms-nav {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<div class="lms-header">
    <div class="container">
        <h1 style="font-size: 42px; margin-bottom: 12px;">📚 Learning Management System</h1>
        <p style="font-size: 20px; opacity: 0.9;">Complete educational content management, analytics, and trainee oversight</p>
    </div>
</div>

<div class="lms-container">
    <!-- LMS Navigation -->
    <nav class="lms-nav">
        <a href="?section=dashboard" class="lms-nav-item <?php echo $section === 'dashboard' ? 'active' : ''; ?>">
            📊 Dashboard
        </a>
        <a href="?section=content" class="lms-nav-item <?php echo $section === 'content' ? 'active' : ''; ?>">
            📝 Content Manager
        </a>
        <a href="?section=quizzes" class="lms-nav-item <?php echo $section === 'quizzes' ? 'active' : ''; ?>">
            ❓ Quiz Builder
        </a>
        <a href="?section=trainees" class="lms-nav-item <?php echo $section === 'trainees' ? 'active' : ''; ?>">
            👥 Trainee Monitor
        </a>
        <a href="?section=analytics" class="lms-nav-item <?php echo $section === 'analytics' ? 'active' : ''; ?>">
            📈 Advanced Analytics
        </a>
        <a href="?section=alerts" class="lms-nav-item <?php echo $section === 'alerts' ? 'active' : ''; ?>">
            🚨 Alerts & Issues
        </a>
    </nav>

    <?php if ($section === 'dashboard'): ?>
        <!-- Dashboard Section -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Training Modules</div>
                <div class="stat-value" style="color: #7c3aed;"><?php echo $stats['total_modules']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Trainees</div>
                <div class="stat-value" style="color: #3b82f6;"><?php echo $stats['total_trainees']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Training</div>
                <div class="stat-value" style="color: #f59e0b;"><?php echo $stats['active_trainees']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Certified Agents</div>
                <div class="stat-value" style="color: #10b981;"><?php echo $stats['completed_trainees']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Recent Quiz Attempts</div>
                <div class="stat-value" style="color: #dc2626;"><?php echo $stats['recent_quiz_attempts']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg Completion Rate</div>
                <div class="stat-value" style="color: #059669;"><?php echo $stats['avg_completion_rate']; ?>%</div>
            </div>
        </div>

        <div class="analytics-card">
            <h2 style="color: #374151; margin-bottom: 20px;">📋 Quick Actions</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;">
                <div style="background: #f0f9ff; padding: 20px; border-radius: 8px;">
                    <h4 style="color: #1e40af; margin: 0 0 12px 0;">📝 Content Management</h4>
                    <p style="margin: 0 0 16px 0; font-size: 14px; color: #6b7280;">Create and edit training modules</p>
                    <a href="?section=content" class="btn">Manage Content</a>
                </div>
                <div style="background: #f0fdf4; padding: 20px; border-radius: 8px;">
                    <h4 style="color: #059669; margin: 0 0 12px 0;">👥 Trainee Oversight</h4>
                    <p style="margin: 0 0 16px 0; font-size: 14px; color: #6b7280;">Monitor progress and performance</p>
                    <a href="?section=trainees" class="btn" style="background: #059669;">View Trainees</a>
                </div>
                <div style="background: #fef3c7; padding: 20px; border-radius: 8px;">
                    <h4 style="color: #92400e; margin: 0 0 12px 0;">❓ Quiz Builder</h4>
                    <p style="margin: 0 0 16px 0; font-size: 14px; color: #6b7280;">Create and manage assessments</p>
                    <a href="?section=quizzes" class="btn" style="background: #f59e0b;">Build Quizzes</a>
                </div>
                <div style="background: #fef2f2; padding: 20px; border-radius: 8px;">
                    <h4 style="color: #991b1b; margin: 0 0 12px 0;">📈 Analytics</h4>
                    <p style="margin: 0 0 16px 0; font-size: 14px; color: #6b7280;">Deep dive into training data</p>
                    <a href="?section=analytics" class="btn" style="background: #dc2626;">View Analytics</a>
                </div>
            </div>
        </div>

    <?php elseif ($section === 'content'): ?>
        <!-- Content Management Section -->
        <div class="content-grid">
            <!-- Module List Sidebar -->
            <div class="sidebar">
                <h3 style="margin: 0 0 20px 0; color: #1f2937;">📋 Training Modules</h3>
                
                <?php foreach ($modules as $module): ?>
                    <div class="module-item <?php echo $moduleSlug === $module['slug'] ? 'active' : ''; ?>" 
                         onclick="window.location.href='?section=content&action=edit&module=<?php echo $module['slug']; ?>'">
                        <div>
                            <div style="font-weight: 600; color: #1f2937;">
                                <?php echo htmlspecialchars($module['title']); ?>
                            </div>
                            <div style="font-size: 12px; color: #6b7280;">
                                <?php echo $module['duration_minutes']; ?> min • 
                                <?php echo $module['required'] ? 'Required' : 'Optional'; ?>
                            </div>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">
                            #<?php echo $module['sort_order']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <button onclick="showCreateModal()" class="btn" style="width: 100%; margin-top: 16px;">
                    ➕ Create New Module
                </button>
            </div>
            
            <!-- Content Editor -->
            <div class="main-content">
                <?php if ($action === 'edit' && $moduleSlug): ?>
                    <?php 
                    $stmt = $pdo->prepare('SELECT * FROM training_modules WHERE slug = ?');
                    $stmt->execute([$moduleSlug]);
                    $currentModule = $stmt->fetch();
                    
                    $moduleContent = '';
                    $fileError = '';
                    if ($currentModule) {
                        $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $moduleSlug . '.php';
                        if (file_exists($moduleFile) && is_readable($moduleFile)) {
                            $moduleContent = file_get_contents($moduleFile);
                        } else {
                            $fileError = 'Module file not found or not readable';
                        }
                    }
                    ?>
                    
                    <div class="tabs">
                        <button class="tab-btn active" onclick="showTab('content')">📝 Content Editor</button>
                        <button class="tab-btn" onclick="showTab('preview')">👁️ Preview</button>
                        <button class="tab-btn" onclick="showTab('settings')">⚙️ Settings</button>
                        <button class="tab-btn" onclick="showTab('analytics')">📊 Analytics</button>
                    </div>
                    
                    <form method="post" id="module-form">
                        <input type="hidden" name="action" value="save_content">
                        <input type="hidden" name="module_slug" value="<?php echo htmlspecialchars($currentModule['slug']); ?>">
                        
                        <!-- Content Tab -->
                        <div id="content-tab" class="tab-content">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Module Title</label>
                                    <input type="text" name="title" value="<?php echo htmlspecialchars($currentModule['title']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Duration (minutes)</label>
                                    <input type="number" name="duration" value="<?php echo $currentModule['duration_minutes']; ?>" required>
                                </div>
                            </div>
                            
                            <?php if (!empty($fileError)): ?>
                                <div style="background: #fee2e2; color: #991b1b; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                                    <strong>⚠️ File Loading Error:</strong> <?php echo htmlspecialchars($fileError); ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Editor Mode Switcher -->
                            <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; margin-bottom: 16px; border: 2px solid #3b82f6;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong style="color: #1e40af;">Content Editor Mode:</strong>
                                        <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">
                                            Visual: Click and edit like Word • Code: Edit HTML source
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 8px;">
                                        <button type="button" id="visual-mode-btn" onclick="switchEditorMode('visual')" class="btn small" style="background: #7c3aed; color: white;">
                                            👁️ Visual Editor
                                        </button>
                                        <button type="button" id="code-mode-btn" onclick="switchEditorMode('code')" class="btn small secondary">
                                            💻 Code Editor
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Component Library (always visible) -->
                            <div class="component-library" style="margin-bottom: 16px;">
                                <h4 style="margin: 0 0 12px 0; color: #374151;">🧩 Quick Insert</h4>
                                <button type="button" class="component-btn" onclick="insertComponent('header')">📝 Header</button>
                                <button type="button" class="component-btn" onclick="insertComponent('paragraph')">📄 Paragraph</button>
                                <button type="button" class="component-btn" onclick="insertComponent('highlight')">💡 Highlight Box</button>
                                <button type="button" class="component-btn" onclick="insertComponent('takeaways')">✅ Key Takeaways</button>
                            </div>
                            
                            <!-- SHORTCODE Library (NEW) -->
                            <div class="component-library" style="margin-bottom: 16px; background: #fef3c7; border: 2px solid #f59e0b;">
                                <h4 style="margin: 0 0 12px 0; color: #92400e;">⚡ Dynamic Shortcodes (Database-Synced)</h4>
                                <p style="margin: 0 0 12px 0; font-size: 12px; color: #78350f;">
                                    Click to insert, then change SKU to match your product. All values pull from database!
                                </p>
                                
                                <?php
                                // Get first active product as example
                                $exampleProduct = $pdo->query("SELECT sku FROM products WHERE active = 1 AND use_in_training = 1 ORDER BY id LIMIT 1")->fetch();
                                $exampleSku = $exampleProduct ? $exampleProduct['sku'] : 'BLEND-10-32';
                                ?>
                                
                                <button type="button" class="component-btn" style="background: #fff7ed;" onclick="insertShortcode('[product_price sku=&quot;<?php echo $exampleSku; ?>&quot;]')">💰 Product Price</button>
                                <button type="button" class="component-btn" style="background: #fff7ed;" onclick="insertShortcode('[product_name sku=&quot;<?php echo $exampleSku; ?>&quot;]')">📦 Product Name</button>
                                <button type="button" class="component-btn" style="background: #fff7ed;" onclick="insertShortcode('[product_servings sku=&quot;<?php echo $exampleSku; ?>&quot;]')">📊 Product Servings</button>
                                <button type="button" class="component-btn" style="background: #fff7ed;" onclick="insertShortcode('[product_attribute sku=&quot;<?php echo $exampleSku; ?>&quot; type=&quot;per_oz&quot;]')">🌿 attribute Content</button>
                                <button type="button" class="component-btn" style="background: #fff7ed;" onclick="insertShortcode('[quiz sku=&quot;<?php echo $exampleSku; ?>&quot; position=&quot;mid&quot;]')">❓ Dynamic Quiz</button>
                                <button type="button" class="component-btn" style="background: #fff7ed;" onclick="insertShortcode('[profit_calc sku=&quot;<?php echo $exampleSku; ?>&quot; drink_price=&quot;12&quot;]')">📈 Profit Calculator</button>
                                <button type="button" class="component-btn" style="background: #fff7ed;" onclick="insertShortcode('[product_comparison sku1=&quot;<?php echo $exampleSku; ?>&quot; sku2=&quot;<?php echo $exampleSku; ?>&quot;]')">⚖️ Product Comparison</button>
                                <button type="button" class="component-btn" style="background: #fff7ed;" onclick="insertShortcode('[product_table skus=&quot;<?php echo $exampleSku; ?>&quot;]')">📋 Product Table</button>
                                <a href="<?php echo path_with_base('/admin/shortcode-builder.php'); ?>" target="_blank" class="component-btn" style="background: #f59e0b; color: white; text-align: center; text-decoration: none; display: block; font-weight: 600;">
                                    🛠️ Open Full Shortcode Builder
                                </a>
                            </div>
                            
                            <!-- Hidden textarea for form submission -->
                            <textarea name="content" id="content-source" style="display: none;"><?php echo htmlspecialchars($moduleContent); ?></textarea>
                            
                            <!-- Visual Editor (WYSIWYG - Default) -->
                            <div id="visual-editor" contenteditable="true" style="
                                min-height: 600px;
                                padding: 40px;
                                background: white;
                                border: 2px solid #e5e7eb;
                                border-radius: 12px;
                                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                                line-height: 1.7;
                                font-size: 15px;
                                overflow-y: auto;
                                max-height: 800px;
                                box-shadow: 0 4px 16px rgba(0,0,0,0.05);
                                cursor: text;
                            ">
                                <?php echo $moduleContent; ?>
                            </div>
                            
                            <!-- Code Editor (Hidden by default) -->
                            <textarea id="code-editor" class="content-textarea" style="
                                display: none;
                                min-height: 600px;
                                font-family: 'Monaco', 'Courier New', monospace;
                                font-size: 13px;
                                line-height: 1.6;
                            "><?php echo htmlspecialchars($moduleContent); ?></textarea>
                            
                            <div style="margin-top: 16px; display: flex; gap: 12px;">
                                <button type="submit" class="btn" onclick="syncContentBeforeSave()">💾 Save Module</button>
                                <button type="button" onclick="autoSave()" class="btn secondary">💾 Auto-Save</button>
                                <button type="button" onclick="formatContent()" class="btn secondary">✨ Clean Formatting</button>
                            </div>
                        </div>
                        
                        <!-- Preview Tab -->
                        <div id="preview-tab" class="tab-content" style="display: none;">
                            <h3>👁️ Live Preview</h3>
                            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-top: 16px; min-height: 400px;" id="preview-content">
                                <!-- Preview will be populated by JavaScript -->
                            </div>
                        </div>
                        
                        <!-- Settings Tab -->
                        <div id="settings-tab" class="tab-content" style="display: none;">
                            <h3>⚙️ Module Settings</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Sort Order</label>
                                    <input type="number" value="<?php echo $currentModule['sort_order']; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Required Module</label>
                                    <select>
                                        <option value="1" <?php echo $currentModule['required'] ? 'selected' : ''; ?>>Yes</option>
                                        <option value="0" <?php echo !$currentModule['required'] ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Analytics Tab -->
                        <div id="analytics-tab" class="tab-content" style="display: none;">
                            <h3>📊 Module Analytics</h3>
                            <?php
                            $stmt = $pdo->prepare('
                                SELECT 
                                    COUNT(DISTINCT tp.user_id) as completions,
                                    AVG(tp.quiz_score) as avg_score,
                                    AVG(tp.total_time_minutes) as avg_time,
                                    COUNT(CASE WHEN tp.quiz_score < 80 THEN 1 END) as failures
                                FROM training_progress tp
                                WHERE tp.module_id = ? AND tp.status = "completed"
                            ');
                            $stmt->execute([$currentModule['id']]);
                            $analytics = $stmt->fetch();
                            ?>
                            
                            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px;">
                                <div style="background: #f9fafb; padding: 16px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 24px; font-weight: bold; color: #059669;"><?php echo $analytics['completions'] ?? 0; ?></div>
                                    <div style="font-size: 12px; color: #6b7280;">Completions</div>
                                </div>
                                <div style="background: #f9fafb; padding: 16px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 24px; font-weight: bold; color: #3b82f6;"><?php echo round($analytics['avg_score'] ?? 0); ?>%</div>
                                    <div style="font-size: 12px; color: #6b7280;">Avg Score</div>
                                </div>
                                <div style="background: #f9fafb; padding: 16px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 24px; font-weight: bold; color: #f59e0b;"><?php echo round($analytics['avg_time'] ?? 0); ?> min</div>
                                    <div style="font-size: 12px; color: #6b7280;">Avg Time</div>
                                </div>
                                <div style="background: #f9fafb; padding: 16px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 24px; font-weight: bold; color: #dc2626;"><?php echo $analytics['failures'] ?? 0; ?></div>
                                    <div style="font-size: 12px; color: #6b7280;">Failures</div>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                <?php else: ?>
                    <!-- Module Overview -->
                    <div style="text-align: center; padding: 60px;">
                        <div style="font-size: 64px; margin-bottom: 20px;">📚</div>
                        <h2 style="color: #1f2937; margin-bottom: 16px;">Content Management System</h2>
                        <p style="color: #6b7280; margin-bottom: 32px;">
                            Select a module from the sidebar to edit content, or create a new module to expand your training program.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($section === 'quizzes'): ?>
        <!-- Quiz Management Section -->
        <div class="content-grid">
            <!-- Quiz Builder -->
            <div class="sidebar">
                <h3 style="margin: 0 0 20px 0; color: #1f2937;">🎯 Quick Actions</h3>
                
                <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                    <h4 style="margin: 0 0 8px 0; color: #1e40af;">📝 Create Quiz</h4>
                    <p style="margin: 0; font-size: 12px; color: #6b7280;">Build interactive questions</p>
                </div>
                
                <div style="background: #f0fdf4; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                    <h4 style="margin: 0 0 8px 0; color: #059669;">🔗 Direct Integration</h4>
                    <p style="margin: 0; font-size: 12px; color: #6b7280;">Insert directly into modules</p>
                </div>
                
                <div style="background: #fef3c7; padding: 16px; border-radius: 8px;">
                    <h4 style="margin: 0 0 8px 0; color: #92400e;">📊 Analytics</h4>
                    <p style="margin: 0; font-size: 12px; color: #6b7280;">Track quiz performance</p>
                </div>
            </div>
            
            <div class="main-content">
                <div class="tabs">
                    <button class="tab-btn active" onclick="showTab('existing')">📋 Existing Quizzes</button>
                    <button class="tab-btn" onclick="showTab('builder')">🛠️ Quiz Builder</button>
                    <button class="tab-btn" onclick="showTab('preview')">👁️ Preview</button>
                    <button class="tab-btn" onclick="showTab('insert')">📥 Insert into Module</button>
                </div>
                
                <!-- Existing Quizzes Tab -->
                <div id="existing-tab" class="tab-content">
                    <h3>📋 Database Quiz Questions</h3>
                    
                    <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                        <strong>💡 Tip:</strong> Quiz questions are stored in the database. Use the filter below to view by module, or click "Edit" to modify any question.
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
                        <div class="form-group">
                            <label>Filter by Module</label>
                            <select id="quiz-module-filter" onchange="filterQuizzesByModule()">
                                <option value="">All Modules</option>
                                <?php foreach ($modules as $module): ?>
                                    <option value="<?php echo $module['id']; ?>">
                                        <?php echo htmlspecialchars($module['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Filter by Product</label>
                            <select id="quiz-product-filter" onchange="filterQuizzesByProduct()">
                                <option value="">All Questions</option>
                                <option value="no_product">No product link</option>
                                <option value="has_product">Has product link</option>
                                <?php 
                                $linkedProducts = $pdo->query("SELECT DISTINCT p.id, p.name, p.sku FROM products p JOIN training_quiz_questions tqq ON tqq.product_id = p.id WHERE tqq.is_active = 1 ORDER BY p.name")->fetchAll();
                                foreach ($linkedProducts as $prod): ?>
                                    <option value="<?php echo $prod['id']; ?>">
                                        <?php echo htmlspecialchars($prod['name']); ?> (<?php echo $prod['sku']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div style="background:#f0f9ff;padding:16px;border-radius:8px;margin-bottom:16px;border-left:4px solid #3b82f6">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <div>
                                <strong style="color:#1e40af">Total Quiz Questions:</strong> 
                                <span id="quiz-count"><?php echo count($dbQuizzes); ?></span>
                            </div>
                            <a href="<?php echo path_with_base('/admin/quiz-question-edit.php?return=lms'); ?>" class="btn" style="background:#7c3aed;color:white;text-decoration:none;padding:8px 16px;font-size:14px">
                                ➕ Create New Question
                            </a>
                        </div>
                    </div>
                    
                    <div id="database-quizzes-container" style="margin-top: 24px;">
                        <?php
                        // Load ALL database quiz questions
                        $dbQuizzes = $pdo->query("
                            SELECT 
                                tqq.*,
                                tm.title as module_title,
                                tm.slug as module_slug,
                                p.name as product_name,
                                p.sku as product_sku
                            FROM training_quiz_questions tqq
                            JOIN training_modules tm ON tm.id = tqq.module_id
                            LEFT JOIN products p ON p.id = tqq.product_id
                            WHERE tqq.is_active = 1
                            ORDER BY tm.sort_order, tqq.quiz_position, tqq.sort_order
                        ")->fetchAll();
                        
                        if (!empty($dbQuizzes)): 
                            foreach ($dbQuizzes as $quiz):
                                $options = json_decode($quiz['options'], true);
                                $positionColors = [
                                    'intro' => '#3b82f6',
                                    'early' => '#059669',
                                    'mid' => '#f59e0b',
                                    'late' => '#dc2626',
                                    'final' => '#7c3aed'
                                ];
                                $positionColor = $positionColors[$quiz['quiz_position']] ?? '#6b7280';
                        ?>
                            <div class="quiz-item" 
                                 data-module-id="<?php echo $quiz['module_id']; ?>" 
                                 data-product-id="<?php echo $quiz['product_id'] ?? ''; ?>" 
                                 style="background: white; border: 2px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 16px;">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                    <div>
                                        <h4 style="margin: 0 0 8px 0; color: #1f2937;"><?php echo htmlspecialchars($quiz['module_title']); ?></h4>
                                        <div style="font-size: 13px; color: #6b7280;">
                                            ID: <?php echo $quiz['id']; ?> • 
                                            Position: <span style="background: <?php echo $positionColor; ?>; color: white; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                                                <?php echo ucfirst($quiz['quiz_position']); ?>
                                            </span>
                                            <?php if ($quiz['rotation_enabled']): ?>
                                                • <span style="color: #7c3aed;">🔄 Rotation</span>
                                            <?php endif; ?>
                                            <?php if ($quiz['product_name']): ?>
                                                • <span style="color: #059669;">🔗 <?php echo htmlspecialchars($quiz['product_name']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 8px;">
                                        <a href="<?php echo path_with_base('/admin/quiz-question-edit.php?id=' . $quiz['id'] . '&return=lms'); ?>" class="btn small secondary" style="text-decoration: none;">Edit</a>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Delete this quiz question?');">
                                            <input type="hidden" name="action" value="delete_quiz">
                                            <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                            <button type="submit" class="btn small" style="background: #dc2626; color: white;">Delete</button>
                                        </form>
                                    </div>
                                </div>
                                
                                <div style="background: #f9fafb; padding: 16px; border-radius: 6px; margin-bottom: 12px;">
                                    <p style="margin: 0; font-weight: 600; color: #374151;"><?php echo htmlspecialchars($quiz['question_text']); ?></p>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                                    <?php foreach ($options as $key => $option): ?>
                                        <?php if (!empty($option)): ?>
                                            <?php 
                                            $isCorrect = $key === $quiz['correct_answer'];
                                            $bgColor = $isCorrect ? '#dcfce7' : '#f3f4f6';
                                            $textColor = $isCorrect ? '#14532d' : '#374151';
                                            ?>
                                            <div style="padding: 8px 12px; background: <?php echo $bgColor; ?>; color: <?php echo $textColor; ?>; border-radius: 4px; font-size: 14px;">
                                                <strong><?php echo strtoupper($key); ?>:</strong> <?php echo htmlspecialchars($option); ?><?php if($isCorrect):?> ✓<?php endif;?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                
                                <?php if (!empty($quiz['explanation'])): ?>
                                    <div style="background: #fef3c7; padding: 12px; border-radius: 6px; border-left: 4px solid #f59e0b;">
                                        <strong style="color: #92400e;">Explanation:</strong> 
                                        <span style="color: #6b7280;"><?php echo htmlspecialchars($quiz['explanation']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; color: #6b7280;">
                                <p>No quiz questions in database yet.</p>
                                <p style="font-size: 14px; margin-top: 12px;">
                                    Run the <a href="<?php echo path_with_base('/admin/migrate-quizzes-to-db.php'); ?>" style="color: #0369a1; text-decoration: underline;">Quiz Migration Tool</a> to import quizzes from module files.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quiz Builder Tab -->
                <div id="builder-tab" class="tab-content" style="display: none;">
                    <div style="background: #f0f9ff; padding: 32px; border-radius: 12px; text-align: center; border: 2px solid #3b82f6;">
                        <div style="font-size: 64px; margin-bottom: 16px;">❓</div>
                        <h3 style="color: #1e40af; margin-bottom: 16px;">Create New Quiz Question</h3>
                        <p style="color: #6b7280; margin-bottom: 24px;">
                            Use the dedicated quiz editor for a better experience with all options available.
                        </p>
                        <a href="<?php echo path_with_base('/admin/quiz-question-edit.php?return=lms'); ?>" class="btn" style="background: #7c3aed; color: white; text-decoration: none; display: inline-block; padding: 12px 32px; font-size: 16px;">
                            ➕ Open Quiz Editor
                        </a>
                    </div>
                    
                    <!-- Legacy inline form (hidden) -->
                    <form method="post" id="quiz-form" style="display: none;">
                        <input type="hidden" name="action" value="create_quiz">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Target Module</label>
                                <select name="module_id" id="target-module" required>
                                    <option value="">Select Module</option>
                                    <?php foreach ($modules as $module): ?>
                                        <option value="<?php echo $module['id']; ?>" data-slug="<?php echo $module['slug']; ?>">
                                            <?php echo htmlspecialchars($module['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Question Type</label>
                                <select id="question-type" onchange="updateQuestionType()">
                                    <option value="multiple_choice">Multiple Choice</option>
                                    <option value="true_false">True/False</option>
                                    <option value="scenario">Scenario-Based</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Question Text</label>
                            <textarea name="question" id="quiz-question" rows="3" placeholder="Enter your quiz question..." required></textarea>
                        </div>
                        
                        <div style="background: #f9fafb; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                            <h4 style="margin: 0 0 16px 0; color: #374151;">Answer Options</h4>
                            <div id="options-container">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <input type="radio" name="correct_answer" value="a" required>
                                    <label style="margin: 0; font-weight: normal;">A:</label>
                                    <input type="text" name="options[a]" id="option-a" placeholder="Option A" required style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                                </div>
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <input type="radio" name="correct_answer" value="b" required>
                                    <label style="margin: 0; font-weight: normal;">B:</label>
                                    <input type="text" name="options[b]" id="option-b" placeholder="Option B" required style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                                </div>
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <input type="radio" name="correct_answer" value="c" required>
                                    <label style="margin: 0; font-weight: normal;">C:</label>
                                    <input type="text" name="options[c]" id="option-c" placeholder="Option C" required style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                                </div>
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <input type="radio" name="correct_answer" value="d">
                                    <label style="margin: 0; font-weight: normal;">D:</label>
                                    <input type="text" name="options[d]" id="option-d" placeholder="Option D (optional)" style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Explanation (shown after answer)</label>
                            <textarea name="explanation" id="quiz-explanation" rows="2" placeholder="Explain why this answer is correct..."></textarea>
                        </div>
                        
                        <div style="display: flex; gap: 12px;">
                            <button type="submit" class="btn">🎯 Create Quiz Question</button>
                            <button type="button" onclick="previewQuiz()" class="btn secondary">👁️ Preview Quiz</button>
                            <button type="button" onclick="generateQuizHtml()" class="btn secondary">📄 Generate HTML</button>
                        </div>
                    </form>
                </div>
                
                <!-- Preview Tab -->
                <div id="preview-tab" class="tab-content" style="display: none;">
                    <h3>👁️ Quiz Preview</h3>
                    <div id="quiz-preview" style="background: #f9fafb; padding: 24px; border-radius: 8px; min-height: 200px;">
                        <p style="color: #6b7280; text-align: center;">Create a quiz question to see the preview</p>
                    </div>
                </div>
                
                <!-- Insert Tab -->
                <div id="insert-tab" class="tab-content" style="display: none;">
                    <h3>📥 Insert Quiz into Module</h3>
                    
                    <form method="post" id="insert-form">
                        <input type="hidden" name="action" value="insert_quiz_into_module">
                        
                        <div class="form-group">
                            <label>Target Module</label>
                            <select name="target_module_slug" required>
                                <option value="">Select Module</option>
                                <?php foreach ($modules as $module): ?>
                                    <option value="<?php echo $module['slug']; ?>">
                                        <?php echo htmlspecialchars($module['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Quiz HTML Code</label>
                            <textarea name="quiz_html" id="generated-quiz-html" rows="10" placeholder="Generate quiz HTML first..." required style="font-family: monospace; font-size: 12px;"></textarea>
                        </div>
                        
                        <div style="background: #fef3c7; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                            <h4 style="margin: 0 0 8px 0; color: #92400e;">⚠️ Important Notes:</h4>
                            <ul style="margin: 0; color: #6b7280; font-size: 14px;">
                                <li>This will insert the quiz before the "Key Takeaways" section</li>
                                <li>Make sure to generate the HTML first using the Quiz Builder</li>
                                <li>You can manually edit the HTML before inserting</li>
                            </ul>
                        </div>
                        
                        <button type="submit" class="btn">📥 Insert Quiz into Module</button>
                    </form>
                </div>
            </div>
        </div>

    <?php elseif ($section === 'trainees'): ?>
        <!-- Trainee Management Section -->
        <?php 
        // Get trainee data
        $stmt = $pdo->query('
            SELECT 
                u.id,
                u.email,
                u.first_name,
                u.last_name,
                u.created_at as joined_date,
                u.status,
                COUNT(DISTINCT tp.module_id) as modules_completed,
                (SELECT COUNT(*) FROM training_modules WHERE required = 1) as total_modules,
                MAX(tp.completed_at) as last_activity,
                MIN(tp.started_at) as training_started,
                CASE 
                    WHEN COUNT(DISTINCT tp.module_id) >= (SELECT COUNT(*) FROM training_modules WHERE required = 1) THEN 1
                    ELSE 0
                END as is_complete,
                GROUP_CONCAT(
                    CONCAT(tp.module_id, ":", tp.status)
                    ORDER BY tp.module_id
                ) as module_statuses
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            LEFT JOIN training_progress tp ON tp.user_id = u.id
            WHERE r.name = "trainee"
            GROUP BY u.id ORDER BY last_activity DESC
        ');
        $trainees = $stmt->fetchAll();
        
        function parseModuleStatuses($statusString) {
            if (empty($statusString)) return [];
            $statuses = [];
            $pairs = explode(',', $statusString);
            foreach ($pairs as $pair) {
                list($moduleId, $status) = explode(':', $pair);
                $statuses[$moduleId] = $status;
            }
            return $statuses;
        }
        ?>
        
        <form method="post" id="bulk-form">
            <input type="hidden" name="action" value="bulk_action">
            
            <div class="analytics-card">
                <h3 style="margin: 0 0 16px 0; color: #1f2937;">🔧 Bulk Actions</h3>
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <input type="checkbox" id="select-all" onchange="toggleAllTrainees(this)">
                    <label for="select-all" style="margin-right: 16px;">Select All</label>
                    
                    <button type="submit" name="bulk_action" value="approve_trainees" class="btn secondary">✅ Approve Selected</button>
                    <button type="submit" name="bulk_action" value="promote_to_agent" class="btn" style="background: #059669;" onclick="return confirm('Promote selected trainees to Sales Agents?')">⬆️ Promote to Agent</button>
                    <button type="submit" name="bulk_action" value="reset_training" class="btn" style="background: #f59e0b;" onclick="return confirm('Reset training progress for selected trainees?')">🔄 Reset Training</button>
                    <button type="button" class="btn secondary" onclick="showMessageModal()">💬 Send Message</button>
                </div>
            </div>
            
            <!-- Message Modal -->
            <div id="message-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 32px; border-radius: 12px; max-width: 500px; width: 90%;">
                    <h3 style="margin: 0 0 16px 0;">Send Message to Selected Trainees</h3>
                    <textarea name="message" placeholder="Enter your message..." style="width: 100%; height: 120px; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; resize: vertical;" required></textarea>
                    <div style="margin-top: 16px; display: flex; gap: 12px; justify-content: flex-end;">
                        <button type="button" class="btn secondary" onclick="hideMessageModal()">Cancel</button>
                        <button type="submit" name="action" value="send_message" class="btn">Send Message</button>
                    </div>
                </div>
            </div>
        
            <div class="analytics-card">
                <h2 style="color: #374151; margin-bottom: 20px;">👥 Trainee Management</h2>
                
                <?php if (empty($trainees)): ?>
                    <div style="padding: 60px; text-align: center;">
                        <p style="color: #6b7280;">No trainees found</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"><input type="checkbox" id="header-select-all" onchange="toggleAllTrainees(this)"></th>
                                    <th>Trainee</th>
                                    <th>Progress</th>
                                    <th>Module Status</th>
                                    <th>Started</th>
                                    <th>Last Activity</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trainees as $trainee): 
                                    $moduleStatuses = parseModuleStatuses($trainee['module_statuses']);
                                    $progressPercent = $trainee['total_modules'] > 0 ? 
                                        round(($trainee['modules_completed'] / $trainee['total_modules']) * 100) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="user_ids[]" value="<?php echo $trainee['id']; ?>" class="trainee-checkbox">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($trainee['first_name'] . ' ' . $trainee['last_name']); ?></strong>
                                        <br><small style="color: #6b7280;"><?php echo htmlspecialchars($trainee['email']); ?></small>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $progressPercent; ?>%"></div>
                                            </div>
                                            <span style="font-size: 14px; color: #6b7280;">
                                                <?php echo $progressPercent; ?>%
                                            </span>
                                        </div>
                                        <small style="color: #6b7280;">
                                            <?php echo $trainee['modules_completed']; ?>/<?php echo $trainee['total_modules']; ?> modules
                                        </small>
                                    </td>
                                    <td>
                                        <div class="module-indicators">
                                            <?php foreach ($modules as $index => $module): 
                                                $status = $moduleStatuses[$module['id']] ?? 'not_started';
                                                $statusClass = 'module-not-started';
                                                if ($status === 'completed') $statusClass = 'module-completed';
                                                elseif ($status === 'in_progress') $statusClass = 'module-in-progress';
                                            ?>
                                            <div class="module-dot <?php echo $statusClass; ?>" 
                                                 title="<?php echo htmlspecialchars($module['title']); ?>">
                                                <?php echo $index + 1; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($trainee['training_started']): ?>
                                            <?php echo date('M d, Y', strtotime($trainee['training_started'])); ?>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">Not started</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trainee['last_activity']): ?>
                                            <?php 
                                            $daysAgo = floor((time() - strtotime($trainee['last_activity'])) / 86400);
                                            if ($daysAgo == 0) echo 'Today';
                                            elseif ($daysAgo == 1) echo 'Yesterday';
                                            else echo $daysAgo . ' days ago';
                                            ?>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trainee['is_complete']): ?>
                                            <span class="status-badge status-complete">Complete</span>
                                        <?php elseif ($trainee['last_activity'] && (time() - strtotime($trainee['last_activity'])) < 604800): ?>
                                            <span class="status-badge status-active">Active</span>
                                        <?php else: ?>
                                            <span class="status-badge status-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo path_with_base('/admin/trainee-detail.php'); ?>?id=<?php echo $trainee['id']; ?>" class="btn secondary" style="font-size: 12px; padding: 6px 12px;">
                                            View Details
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </form>

    <?php elseif ($section === 'analytics'): ?>
        <!-- Analytics Section -->
        <?php 
        // Get comprehensive analytics data
        $stmt = $pdo->query("
            SELECT 
                tm.id,
                tm.title,
                tm.duration_minutes as expected_duration,
                COUNT(DISTINCT tp.user_id) as total_completions,
                AVG(tp.total_time_minutes) as avg_completion_time,
                AVG(tp.active_time_minutes) as avg_active_time,
                AVG(tp.idle_time_minutes) as avg_idle_time,
                AVG(tp.engagement_score) as avg_engagement,
                AVG(tp.quiz_score) as avg_quiz_score,
                AVG(tp.quiz_attempts) as avg_quiz_attempts,
                COUNT(CASE WHEN tp.total_time_minutes > tm.duration_minutes * 2 THEN 1 END) as slow_learners,
                COUNT(CASE WHEN tp.quiz_attempts > 2 THEN 1 END) as struggling_users
            FROM training_modules tm
            LEFT JOIN training_progress tp ON tp.module_id = tm.id AND tp.status = 'completed'
            GROUP BY tm.id, tm.title, tm.duration_minutes
            ORDER BY tm.sort_order
        ");
        $moduleStats = $stmt->fetchAll();
        ?>
        
        <div class="analytics-card">
            <h2 style="color: #374151; margin-bottom: 20px;">📈 Module Performance Analytics</h2>
            
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th style="text-align: center;">Completions</th>
                            <th style="text-align: center;">Avg Time</th>
                            <th style="text-align: center;">Avg Engagement</th>
                            <th style="text-align: center;">Avg Quiz Score</th>
                            <th style="text-align: center;">Issues</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($moduleStats as $module): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($module['title']); ?></strong>
                                <br><small style="color: #6b7280;">Expected: <?php echo $module['expected_duration']; ?> min</small>
                            </td>
                            <td style="text-align: center;">
                                <?php echo $module['total_completions'] ?? 0; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php 
                                $avgTime = round($module['avg_completion_time'] ?? 0);
                                $expectedTime = $module['expected_duration'];
                                $timeColor = $avgTime > $expectedTime * 1.5 ? '#dc2626' : ($avgTime > $expectedTime ? '#f59e0b' : '#059669');
                                ?>
                                <span style="color: <?php echo $timeColor; ?>; font-weight: 600;">
                                    <?php echo $avgTime; ?> min
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php 
                                $engagement = round($module['avg_engagement'] ?? 0);
                                $engagementColor = $engagement < 40 ? '#dc2626' : ($engagement < 70 ? '#f59e0b' : '#059669');
                                ?>
                                <span style="color: <?php echo $engagementColor; ?>; font-weight: 600;">
                                    <?php echo $engagement; ?>%
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php 
                                $quizScore = round($module['avg_quiz_score'] ?? 0);
                                $scoreColor = $quizScore < 70 ? '#dc2626' : ($quizScore < 85 ? '#f59e0b' : '#059669');
                                ?>
                                <span style="color: <?php echo $scoreColor; ?>; font-weight: 600;">
                                    <?php echo $quizScore; ?>%
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php 
                                $issues = ($module['slow_learners'] ?? 0) + ($module['struggling_users'] ?? 0);
                                if ($issues > 0) {
                                    echo "<span style='color: #dc2626; font-weight: 600;'>{$issues}</span>";
                                } else {
                                    echo "<span style='color: #059669;'>✅</span>";
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($section === 'alerts'): ?>
        <!-- Alerts Section -->
        <?php 
        $stmt = $pdo->query("
            SELECT 
                taa.*,
                u.first_name, u.last_name, u.email,
                tm.title as module_title
            FROM training_admin_alerts taa
            JOIN users u ON u.id = taa.user_id
            LEFT JOIN training_modules tm ON tm.id = taa.module_id
            WHERE taa.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY taa.created_at DESC
            LIMIT 50
        ");
        $recentAlerts = $stmt->fetchAll();
        ?>
        
        <div class="analytics-card">
            <h2 style="color: #374151; margin-bottom: 20px;">🚨 Recent Admin Alerts</h2>
            
            <?php if (empty($recentAlerts)): ?>
                <p style="color: #6b7280; text-align: center; padding: 40px;">No recent alerts</p>
            <?php else: ?>
                <div style="max-height: 600px; overflow-y: auto;">
                    <?php foreach ($recentAlerts as $alert): ?>
                    <div class="alert-item alert-<?php echo $alert['severity']; ?>">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div style="flex: 1;">
                                <strong><?php echo htmlspecialchars($alert['first_name'] . ' ' . $alert['last_name']); ?></strong>
                                <?php if ($alert['module_title']): ?>
                                    - <?php echo htmlspecialchars($alert['module_title']); ?>
                                <?php endif; ?>
                                <br>
                                <span style="color: #374151;"><?php echo htmlspecialchars($alert['alert_message']); ?></span>
                            </div>
                            <div style="text-align: right; font-size: 12px; color: #6b7280;">
                                <?php echo date('M d, H:i', strtotime($alert['created_at'])); ?>
                                <br>
                                <span style="text-transform: uppercase; font-weight: 600; color: 
                                    <?php 
                                    echo $alert['severity'] === 'critical' ? '#dc2626' : 
                                        ($alert['severity'] === 'high' ? '#ea580c' : 
                                        ($alert['severity'] === 'medium' ? '#f59e0b' : '#10b981'));
                                    ?>
                                ">
                                    <?php echo $alert['severity']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<!-- Create Module Modal -->
<div id="create-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 32px; border-radius: 12px; max-width: 600px; width: 90%;">
        <h3 style="margin: 0 0 20px 0;">➕ Create New Training Module</h3>
        <form method="post">
            <input type="hidden" name="action" value="create_module">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Module Slug (URL-friendly)</label>
                    <input type="text" name="slug" placeholder="e.g., advanced-sales" required>
                </div>
                <div class="form-group">
                    <label>Module Title</label>
                    <input type="text" name="title" placeholder="e.g., Advanced Sales Techniques" required>
                </div>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Duration (minutes)</label>
                    <input type="number" name="duration" value="60" required>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" value="<?php echo count($modules) + 1; ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="required" checked>
                    <span>Required Module</span>
                </label>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="hideCreateModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn">Create Module</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentTab = 'content';

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tab + '-tab').style.display = 'block';
    document.querySelector('[onclick="showTab(\'' + tab + '\')"]').classList.add('active');
    
    currentTab = tab;
    
    if (tab === 'preview') {
        showPreview();
    }
}

let currentEditorMode = 'visual';

function switchEditorMode(mode) {
    const visualEditor = document.getElementById('visual-editor');
    const codeEditor = document.getElementById('code-editor');
    const visualBtn = document.getElementById('visual-mode-btn');
    const codeBtn = document.getElementById('code-mode-btn');
    const contentSource = document.getElementById('content-source');
    
    if (mode === 'visual') {
        // Switching TO visual mode - update visual from code
        if (currentEditorMode === 'code') {
            visualEditor.innerHTML = codeEditor.value;
            contentSource.value = codeEditor.value;
        }
        
        visualEditor.style.display = 'block';
        codeEditor.style.display = 'none';
        visualBtn.style.background = '#7c3aed';
        visualBtn.style.color = 'white';
        visualBtn.classList.remove('secondary');
        codeBtn.style.background = '';
        codeBtn.classList.add('secondary');
        currentEditorMode = 'visual';
    } else {
        // Switching TO code mode - update code from visual
        if (currentEditorMode === 'visual') {
            const htmlContent = visualEditor.innerHTML;
            codeEditor.value = htmlContent;
            contentSource.value = htmlContent;
        }
        
        visualEditor.style.display = 'none';
        codeEditor.style.display = 'block';
        codeBtn.style.background = '#7c3aed';
        codeBtn.style.color = 'white';
        codeBtn.classList.remove('secondary');
        visualBtn.style.background = '';
        visualBtn.classList.add('secondary');
        currentEditorMode = 'code';
    }
}

function syncContentBeforeSave() {
    const visualEditor = document.getElementById('visual-editor');
    const codeEditor = document.getElementById('code-editor');
    const contentSource = document.getElementById('content-source');
    
    if (currentEditorMode === 'visual') {
        contentSource.value = visualEditor.innerHTML;
    } else {
        contentSource.value = codeEditor.value;
    }
}

// Sync content on any input (for auto-save)
document.getElementById('visual-editor')?.addEventListener('input', function() {
    document.getElementById('content-source').value = this.innerHTML;
});

document.getElementById('code-editor')?.addEventListener('input', function() {
    document.getElementById('content-source').value = this.value;
});

function showPreview() {
    // Not needed anymore - visual editor IS the preview
    alert('You are already in visual preview mode! Toggle to Code Editor to see HTML source.');
}

// Insert shortcode into editor
function insertShortcode(shortcode) {
    const visualEditor = document.getElementById('visual-editor');
    const codeEditor = document.getElementById('code-editor');
    
    if (currentEditorMode === 'visual') {
        // Insert at cursor in visual editor
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            const textNode = document.createTextNode(shortcode);
            range.deleteContents();
            range.insertNode(textNode);
        } else {
            visualEditor.innerHTML += shortcode;
        }
        // Update hidden field
        document.getElementById('content-source').value = visualEditor.innerHTML;
    } else {
        // Insert in code editor
        insertTextAtCursor(codeEditor, shortcode);
    }
    
    // Show notification
    const toast = document.createElement('div');
    toast.innerHTML = '✅ Shortcode inserted!';
    toast.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 12px 20px; border-radius: 6px; z-index: 10000;';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
}

function insertComponent(type) {
    const visualEditor = document.getElementById('visual-editor');
    const codeEditor = document.getElementById('code-editor');
    let component = '';
    
    switch(type) {
        case 'header':
            component = '\n<h3>New Section Title</h3>\n\n<p>Section content goes here...</p>\n';
            break;
        case 'paragraph':
            component = '\n<p>New paragraph content...</p>\n';
            break;
        case 'highlight':
            component = '\n<div class="key-highlight">\n    <h3>Important Information</h3>\n    <p>Key information that trainees must remember.</p>\n</div>\n';
            break;
        case 'grid':
            component = `\n<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 24px 0;">
    <div style="background: #f9fafb; padding: 20px; border-radius: 12px;">
        <h4>Left Column</h4>
        <p>Content for left side...</p>
    </div>
    <div style="background: #f9fafb; padding: 20px; border-radius: 12px;">
        <h4>Right Column</h4>
        <p>Content for right side...</p>
    </div>
</div>\n`;
            break;
        case 'takeaways':
            component = `\n<h3>💡 Key Takeaways</h3>

<div style="background: #f0f9ff; padding: 24px; border-radius: 12px; margin-top: 32px;">
    <ul style="margin: 0;">
        <li>Key point 1</li>
        <li>Key point 2</li>
        <li>Key point 3</li>
    </ul>
</div>\n`;
            break;
    }
    
    if (currentEditorMode === 'visual') {
        // Insert at cursor in visual editor
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = component;
            range.deleteContents();
            range.insertNode(tempDiv.firstChild);
        } else {
            visualEditor.innerHTML += component;
        }
        // Update hidden field
        document.getElementById('content-source').value = visualEditor.innerHTML;
    } else {
        // Insert in code editor
        insertTextAtCursor(codeEditor, component);
    }
}

function insertTextAtCursor(textarea, text) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    
    textarea.value = textarea.value.substring(0, start) + text + textarea.value.substring(end);
    textarea.focus();
    textarea.setSelectionRange(start + text.length, start + text.length);
    
    // Update hidden field
    document.getElementById('content-source').value = textarea.value;
}

function formatContent() {
    // Clean up any messy formatting in the visual editor
    const visualEditor = document.getElementById('visual-editor');
    if (currentEditorMode === 'visual' && visualEditor) {
        // Basic cleanup - remove empty paragraphs, normalize whitespace
        let content = visualEditor.innerHTML;
        content = content.replace(/<p>\s*<\/p>/g, '');
        content = content.replace(/<br>\s*<br>\s*<br>/g, '<br><br>');
        visualEditor.innerHTML = content;
        document.getElementById('content-source').value = content;
        
        alert('✨ Formatting cleaned up!');
    } else if (currentEditorMode === 'code') {
        alert('Switch to Visual Editor to use formatting cleanup.');
    }
}

function autoSave() {
    // Sync content first
    syncContentBeforeSave();
    
    const formData = new FormData(document.getElementById('module-form'));
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            const indicator = document.createElement('div');
            indicator.innerHTML = '✅ Auto-saved';
            indicator.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 8px 16px; border-radius: 6px; z-index: 1000;';
            document.body.appendChild(indicator);
            setTimeout(() => indicator.remove(), 2000);
        }
    });
}

function showCreateModal() {
    document.getElementById('create-modal').style.display = 'block';
}

function hideCreateModal() {
    document.getElementById('create-modal').style.display = 'none';
}

function toggleAllTrainees(checkbox) {
    const traineeCheckboxes = document.querySelectorAll('.trainee-checkbox');
    const headerCheckbox = document.getElementById('header-select-all');
    const selectAllCheckbox = document.getElementById('select-all');
    
    traineeCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    
    if (checkbox.id === 'select-all') {
        headerCheckbox.checked = checkbox.checked;
    } else if (checkbox.id === 'header-select-all') {
        selectAllCheckbox.checked = checkbox.checked;
    }
}

function showMessageModal() {
    const selectedCheckboxes = document.querySelectorAll('.trainee-checkbox:checked');
    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one trainee to send a message.');
        return;
    }
    document.getElementById('message-modal').style.display = 'block';
}

function hideMessageModal() {
    document.getElementById('message-modal').style.display = 'none';
    document.querySelector('textarea[name="message"]').value = '';
}

// Auto-save every 30 seconds for content editing
if (document.getElementById('module-form')) {
    setInterval(autoSave, 30000);
}

// Auto-generate slug from title
document.querySelector('input[name="title"]')?.addEventListener('input', function() {
    const slug = this.value.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .trim('-');
    const slugInput = document.querySelector('input[name="slug"]');
    if (slugInput && !slugInput.value) {
        slugInput.value = slug;
    }
});

// Filter quiz questions by module
function filterQuizzesByModule() {
    const moduleId = document.getElementById('quiz-module-filter').value;
    const productFilter = document.getElementById('quiz-product-filter').value;
    const quizItems = document.querySelectorAll('.quiz-item[data-module-id]');
    
    let visibleCount = 0;
    quizItems.forEach(item => {
        const itemModuleId = item.getAttribute('data-module-id');
        const itemProductId = item.getAttribute('data-product-id');
        
        let showModule = !moduleId || itemModuleId === moduleId;
        let showProduct = applyProductFilter(itemProductId, productFilter);
        
        if (showModule && showProduct) {
            item.style.display = 'block';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    document.getElementById('quiz-count').textContent = visibleCount;
    console.log('Filtered quizzes - showing ' + visibleCount + ' questions');
}

// Filter quiz questions by product
function filterQuizzesByProduct() {
    filterQuizzesByModule(); // Use same logic
}

// Helper function to apply product filter
function applyProductFilter(itemProductId, productFilter) {
    if (!productFilter) return true; // No filter
    
    if (productFilter === 'no_product') {
        return !itemProductId || itemProductId === '';
    } else if (productFilter === 'has_product') {
        return itemProductId && itemProductId !== '';
    } else {
        return itemProductId === productFilter;
    }
}

// Quiz Builder Functions
function updateQuestionType() {
    const type = document.getElementById('question-type').value;
    const container = document.getElementById('options-container');
    
    if (type === 'true_false') {
        container.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <input type="radio" name="correct_answer" value="a" required>
                <label style="margin: 0; font-weight: normal;">A:</label>
                <input type="text" name="options[a]" id="option-a" value="True" required style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <input type="radio" name="correct_answer" value="b" required>
                <label style="margin: 0; font-weight: normal;">B:</label>
                <input type="text" name="options[b]" id="option-b" value="False" required style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
        `;
    } else {
        // Reset to multiple choice
        container.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <input type="radio" name="correct_answer" value="a" required>
                <label style="margin: 0; font-weight: normal;">A:</label>
                <input type="text" name="options[a]" id="option-a" placeholder="Option A" required style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <input type="radio" name="correct_answer" value="b" required>
                <label style="margin: 0; font-weight: normal;">B:</label>
                <input type="text" name="options[b]" id="option-b" placeholder="Option B" required style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <input type="radio" name="correct_answer" value="c" required>
                <label style="margin: 0; font-weight: normal;">C:</label>
                <input type="text" name="options[c]" id="option-c" placeholder="Option C" required style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <input type="radio" name="correct_answer" value="d">
                <label style="margin: 0; font-weight: normal;">D:</label>
                <input type="text" name="options[d]" id="option-d" placeholder="Option D (optional)" style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
        `;
    }
}

function previewQuiz() {
    const question = document.getElementById('quiz-question').value;
    const optionA = document.getElementById('option-a').value;
    const optionB = document.getElementById('option-b').value;
    const optionC = document.getElementById('option-c').value;
    const optionD = document.getElementById('option-d').value;
    const explanation = document.getElementById('quiz-explanation').value;
    const correctAnswer = document.querySelector('input[name="correct_answer"]:checked')?.value;
    
    if (!question || !optionA || !optionB) {
        alert('Please fill in at least the question and first two options.');
        return;
    }
    
    let previewHtml = '<div class="quiz-question">';
    previewHtml += '<p><strong>Question:</strong> ' + escapeHtml(question) + '</p>';
    previewHtml += '<div class="quiz-options">';
    previewHtml += '<label><input type="radio" name="preview_q" value="a"> ' + escapeHtml(optionA) + '</label>';
    previewHtml += '<label><input type="radio" name="preview_q" value="b"> ' + escapeHtml(optionB) + '</label>';
    
    if (optionC) {
        previewHtml += '<label><input type="radio" name="preview_q" value="c"> ' + escapeHtml(optionC) + '</label>';
    }
    if (optionD) {
        previewHtml += '<label><input type="radio" name="preview_q" value="d"> ' + escapeHtml(optionD) + '</label>';
    }
    
    const correctLabel = correctAnswer ? correctAnswer.toUpperCase() : 'A';
    previewHtml += '</div>';
    previewHtml += '<p style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;" class="answer">';
    previewHtml += '<strong>Correct Answer: ' + correctLabel + '</strong> - ' + escapeHtml(explanation || 'No explanation provided.');
    previewHtml += '</p>';
    previewHtml += '</div>';
    
    document.getElementById('quiz-preview').innerHTML = previewHtml;
    
    // Add interactivity to preview
    document.querySelectorAll('input[name="preview_q"]').forEach(input => {
        input.addEventListener('change', function() {
            this.closest('.quiz-question').querySelector('.answer').style.display = 'block';
        });
    });
    
    // Switch to preview tab
    showTab('preview');
}

function generateQuizHtml() {
    const question = document.getElementById('quiz-question').value;
    const optionA = document.getElementById('option-a').value;
    const optionB = document.getElementById('option-b').value;
    const optionC = document.getElementById('option-c').value;
    const optionD = document.getElementById('option-d').value;
    const explanation = document.getElementById('quiz-explanation').value;
    const correctAnswer = document.querySelector('input[name="correct_answer"]:checked')?.value;
    
    if (!question || !optionA || !optionB) {
        alert('Please fill in at least the question and first two options.');
        return;
    }
    
    const questionId = 'q' + Date.now();
    const correctLabel = correctAnswer ? correctAnswer.toUpperCase() : 'A';
    
    let quizHtml = '<div class="quiz-question">\n';
    quizHtml += '    <p><strong>Question:</strong> ' + escapeHtml(question) + '</p>\n';
    quizHtml += '    <div class="quiz-options">\n';
    quizHtml += '        <label><input type="radio" name="' + questionId + '" value="a"> ' + escapeHtml(optionA) + '</label>\n';
    quizHtml += '        <label><input type="radio" name="' + questionId + '" value="b"> ' + escapeHtml(optionB) + '</label>\n';
    
    if (optionC) {
        quizHtml += '        <label><input type="radio" name="' + questionId + '" value="c"> ' + escapeHtml(optionC) + '</label>\n';
    }
    if (optionD) {
        quizHtml += '        <label><input type="radio" name="' + questionId + '" value="d"> ' + escapeHtml(optionD) + '</label>\n';
    }
    
    quizHtml += '    </div>\n';
    quizHtml += '    <p style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;" class="answer">\n';
    quizHtml += '        <strong>Correct Answer: ' + correctLabel + '</strong> - ' + escapeHtml(explanation || 'No explanation provided.') + '\n';
    quizHtml += '    </p>\n';
    quizHtml += '</div>\n\n';
    quizHtml += '<script>\n';
    quizHtml += 'document.querySelectorAll(\'input[name="' + questionId + '"]\').forEach(input => {\n';
    quizHtml += '    input.addEventListener(\'change\', function() {\n';
    quizHtml += '        this.closest(\'.quiz-question\').querySelector(\'.answer\').style.display = \'block\';\n';
    quizHtml += '    });\n';
    quizHtml += '});\n';
    quizHtml += '<' + '/script>';
    
    document.getElementById('generated-quiz-html').value = quizHtml;
    
    // Show success message
    const indicator = document.createElement('div');
    indicator.innerHTML = '✅ Quiz HTML Generated!';
    indicator.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 8px 16px; border-radius: 6px; z-index: 1000;';
    document.body.appendChild(indicator);
    setTimeout(() => indicator.remove(), 3000);
    
    // Switch to insert tab
    showTab('insert');
}

// Close modals when clicking outside
document.getElementById('message-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        hideMessageModal();
    }
});

document.getElementById('create-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        hideCreateModal();
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
