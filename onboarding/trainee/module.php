<?php
$SEO = [
    'title' => 'Training Module — Product',
    'description' => 'Complete your comprehensive Product sales training module.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/config.php';
// Auth BEFORE any output
require_login_or_redirect(path_with_base('/login.php'));

$uid = current_user_id();
$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    flash_set('error', 'No module specified.');
    redirect(path_with_base('/onboarding/trainee/'));
}

$pdo = db();

try {
    // Get module details
    $stmt = $pdo->prepare('SELECT * FROM training_modules WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $module = $stmt->fetch();
    
    if (!$module) {
        flash_set('error', 'Module not found.');
        redirect(path_with_base('/onboarding/trainee/'));
    }
    
    // Determine next required module by sort order
    $nextModuleSlug = null;
    try {
        $stmt = $pdo->prepare('SELECT slug FROM training_modules WHERE sort_order > ? AND required = 1 ORDER BY sort_order ASC LIMIT 1');
        $stmt->execute([intval($module['sort_order'] ?? 0)]);
        $nextModuleSlug = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $nextModuleSlug = null;
    }
    // Explicit mapping overrides where applicable
    if (($module['slug'] ?? '') === 'ten-step-close') {
        $nextModuleSlug = 'ai-assisted-sales';
    }
    // Ensure Module 1 (intro-product) proceeds to Module 2 (products & product categories)
    if (($module['slug'] ?? '') === 'intro-product') {
        // Use canonical slug that maps to products-product categories-new.php via the switch include
        $nextModuleSlug = 'products-and-product categories';
    }
    
    // Check for retest requirements
    $stmt = $pdo->prepare('SELECT * FROM training_retest_requirements WHERE user_id = ? AND module_id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$uid, $module['id']]);
    $retestRequired = $stmt->fetch();
    
    // Check/create progress record
    $stmt = $pdo->prepare('SELECT * FROM training_progress WHERE user_id = ? AND module_id = ? LIMIT 1');
    $stmt->execute([$uid, $module['id']]);
    $progress = $stmt->fetch();
    
    // Log admin training access if admin is reviewing
    if (current_admin_id() && in_array('admin', user_roles($uid) ?? [])) {
        try {
            $adminId = current_admin_id();
            $accessType = isset($_SESSION['admin_simulation']) ? 'simulation' : 'review';
            $simulatedRole = $_SESSION['admin_simulation']['simulated_role'] ?? null;
            $stmt = $pdo->prepare('INSERT INTO admin_training_access (admin_user_id, accessed_module_id, access_type, simulated_role, notes) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$adminId, $module['id'], $accessType, $simulatedRole, 'Admin accessed module for review']);
        } catch (Exception $e) {
            // Continue even if logging fails
            error_log("Admin training access logging failed: " . $e->getMessage());
        }
    }
    
    if (!$progress) {
        // Create progress record
        $stmt = $pdo->prepare('INSERT INTO training_progress (user_id, module_id, status, started_at) VALUES (?, ?, "in_progress", NOW())');
        $stmt->execute([$uid, $module['id']]);
        $progress = ['status' => 'in_progress'];
    } elseif ($progress['status'] === 'not_started') {
        // Update to in_progress
        $stmt = $pdo->prepare('UPDATE training_progress SET status = "in_progress", started_at = NOW() WHERE user_id = ? AND module_id = ?');
        $stmt->execute([$uid, $module['id']]);
        $progress['status'] = 'in_progress';
    }
    
    // Handle completion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete'])) {
        $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') || (!empty($_POST['ajax']));
        $finish = function(string $url) use ($isAjax) {
            $final = outbound_path($url);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'next_url' => $final]);
                exit;
            }
            redirect($url);
        };
        $quizScore = null;
        $quizAttempts = 1;
        $totalQuestions = 0;
        $correctAnswers = 0;
        
        // Store user's quiz answers in session for results display
        $_SESSION['quiz_results_' . $module['id']] = [
            'user_answers' => $_POST,
            'timestamp' => time()
        ];
        
        // Handle quiz scoring for modules with quizzes
        if (isset($_POST['quiz_score'])) {
            $quizScore = floatval($_POST['quiz_score']);
            $quizAttempts = intval($_POST['quiz_attempts'] ?? 1);
            $totalQuestions = intval($_POST['total_questions'] ?? 0);
            $correctAnswers = intval($_POST['correct_answers'] ?? 0);

            // For final assessment and final exam, require 80% minimum
            if (in_array($module['slug'], ['final-assessment', 'final-exam']) && $quizScore < 80) {
                // Upsert failed quiz attempt to prevent duplicate entry for same attempt
                $stmt = $pdo->prepare('UPDATE training_quiz_results
                    SET score = ?, total_questions = ?, passed = 0, completed_at = NOW()
                    WHERE user_id = ? AND module_id = ? AND attempt_number = ?');
                $stmt->execute([$correctAnswers, $totalQuestions, $uid, $module['id'], $quizAttempts]);
                if ($stmt->rowCount() === 0) {
                    $stmt = $pdo->prepare('INSERT INTO training_quiz_results (user_id, module_id, score, total_questions, passed, attempt_number)
                                            VALUES (?, ?, ?, ?, 0, ?)');
                    $stmt->execute([$uid, $module['id'], $correctAnswers, $totalQuestions, $quizAttempts]);
                }

                flash_set('error', "You scored {$quizScore}% ({$correctAnswers}/{$totalQuestions} correct). You need 80% to pass. Please review the material and try again.");
                $finish(parse_url($_SERVER['REQUEST_URI'] ?? path_with_base('/onboarding/trainee/'), PHP_URL_PATH) . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
            }

            // Upsert successful quiz attempt
            $stmt = $pdo->prepare('UPDATE training_quiz_results
                SET score = ?, total_questions = ?, passed = 1, completed_at = NOW()
                WHERE user_id = ? AND module_id = ? AND attempt_number = ?');
            $stmt->execute([$correctAnswers, $totalQuestions, $uid, $module['id'], $quizAttempts]);
            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare('INSERT INTO training_quiz_results (user_id, module_id, score, total_questions, passed, attempt_number)
                                        VALUES (?, ?, ?, ?, 1, ?)');
                $stmt->execute([$uid, $module['id'], $correctAnswers, $totalQuestions, $quizAttempts]);
            }
        }
        
        // Update training progress with quiz data
        $stmt = $pdo->prepare('UPDATE training_progress SET 
            status = "completed", 
            completed_at = NOW(), 
            quiz_score = ?, 
            quiz_attempts = ? 
            WHERE user_id = ? AND module_id = ?');
        $stmt->execute([$quizScore, $quizAttempts, $uid, $module['id']]);
        
        // If there was a retest requirement, mark it as completed
        if ($retestRequired) {
            $stmt = $pdo->prepare('UPDATE training_retest_requirements SET is_active = 0, completed_at = NOW() WHERE id = ?');
            $stmt->execute([$retestRequired['id']]);
        }
        
        // Redirect to show quiz results instead of going directly to next module
        $currentUrl = '/onboarding/trainee/module.php?slug=' . urlencode($slug) . '&show_results=1';
        $finish($currentUrl);
    }
    
} catch (Throwable $e) {
    flash_set('error', 'Unable to load module. Please try again.');
    redirect(path_with_base('/onboarding/trainee/'));
}

// Module icons
$moduleIcons = [
    'intro-product' => '🌿',
    'products-and-product categories' => '🧪',
    'compliance-sales' => '⚖️',
    'crm-mastery' => '💼',
    'final-assessment' => '📚',
    'ten-step-close' => '🎯',
    'ai-assisted-sales' => '🤖',
    'objection-mastery' => '🛡️',
    'final-exam' => '🎓'
];
$icon = $moduleIcons[$module['slug']] ?? '📖';
// Safe to start output now
require_once __DIR__ . '/../../required/header.php';
?>

<section class="section">
    <div class="container">
        <div style="max-width: 900px; margin: 0 auto;">
            <a href="<?php echo outbound_path('/onboarding/trainee/'); ?>" class="btn secondary" style="margin-bottom: 24px;">← Back to Training Portal</a>
            
            <div class="card" style="border-width: 2px; padding: 32px;">
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 24px;">
                    <span style="font-size: 48px;"><?php echo $icon; ?></span>
                    <div>
                        <h1 style="margin: 0;"><?php echo sanitize($module['title']); ?></h1>
                        <p style="color: #6b7280; margin: 4px 0 0;">
                            Duration: <?php echo intval($module['duration_minutes']); ?> minutes
                            <?php if ($progress['status'] === 'completed'): ?>
                                <span style="color: #059669; margin-left: 16px;">✅ Completed</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <?php
                // Admin Answer View toggle (rendered for admins; shown only if quiz exists)
                $isAdminViewer = false;
                try {
                    $isAdminViewer = (bool) current_admin_id();
                    if (!$isAdminViewer) {
                        $isAdminViewer = in_array('admin', user_roles($uid) ?? []);
                    }
                } catch (Throwable $e) {
                    $isAdminViewer = false;
                }
                ?>
                <?php if ($isAdminViewer): ?>
                <div id="admin-answer-toggle" class="card" style="background:#ecfeff;border:2px solid #06b6d4;padding:12px;border-radius:8px;margin:16px 0;display:none;align-items:center;gap:12px;">
                    <strong style="color:#0e7490;">Admin Preview Mode:</strong>
                    <label style="display:flex;align-items:center;gap:8px;color:#0e7490;cursor:pointer;">
                        <input type="checkbox" id="admin-show-answers-toggle">
                        <span id="admin-show-answers-label">Enable instant answer preview (check to see answers on click)</span>
                    </label>
                    <span style="margin-left:auto;color:#0e7490;font-size:12px;">Default: Submit first (trainee view)</span>
                </div>
                <?php endif; ?>
                
                <div class="module-content">
                    <?php
                    // Set global module ID for quiz submissions
                    $GLOBALS['current_module_id'] = $module['id'];
                    
                    // Load shortcode engine for dynamic content
                    require_once __DIR__ . '/../../required/shortcode-engine.php';
                    
                    // Display module content - try to include file by slug
                    $moduleFile = __DIR__ . '/modules/' . $module['slug'] . '.php';
                    
                    if (file_exists($moduleFile)) {
                        // Capture output and process shortcodes
                        ob_start();
                        include $moduleFile;
                        $moduleContent = ob_get_clean();
                        
                        // Process any shortcodes in the content
                        $processedContent = process_shortcodes($moduleContent, [
                            'module_id' => $module['id'],
                            'user_id' => $userId,
                            'module_slug' => $module['slug']
                        ]);
                        
                        echo $processedContent;
                    } else {
                        // Fallback: Check for old module names
                        switch ($module['slug']) {
                            case 'products-and-product categories':
                            case 'products-product categories':
                                if (file_exists(__DIR__ . '/modules/product-mastery.php')) {
                                    include __DIR__ . '/modules/product-mastery.php';
                                } else {
                                    echo '<div style="background: #fef3c7; padding: 24px; border-radius: 12px; text-align: center;">';
                                    echo '<p style="color: #92400e;">This module has been updated. Content is being prepared.</p>';
                                    echo '</div>';
                                }
                                break;
                                
                            default:
                                echo '<div style="background: #f0f9ff; padding: 40px; border-radius: 12px; text-align: center;">';
                                echo '<div style="font-size: 48px; margin-bottom: 16px;">📚</div>';
                                echo '<h3 style="color: #1e40af;">Module Content Coming Soon</h3>';
                                echo '<p style="color: #6b7280;">This module is being prepared for you. Check back soon!</p>';
                                echo '</div>';
                        }
                    }
                    ?>
                </div>
                
                <?php
                // Display Quiz Results if show_results parameter is present
                if (isset($_GET['show_results']) && isset($_SESSION['quiz_results_' . $module['id']])):
                    $resultsData = $_SESSION['quiz_results_' . $module['id']];
                    $userAnswers = $resultsData['user_answers'];
                    
                    // Get quiz score from session/database
                    $stmt = $pdo->prepare('SELECT score, total_questions, passed FROM training_quiz_results 
                                          WHERE user_id = ? AND module_id = ? 
                                          ORDER BY completed_at DESC LIMIT 1');
                    $stmt->execute([$uid, $module['id']]);
                    $quizResult = $stmt->fetch();
                    
                    if ($quizResult):
                        $passed = $quizResult['passed'];
                        $score = $quizResult['score'];
                        $total = $quizResult['total_questions'];
                        $percent = $total > 0 ? round(($score / $total) * 100) : 0;
                ?>
                <div class="quiz-results">
                    <div class="results-header <?php echo $passed ? 'passed' : 'failed'; ?>">
                        <div style="font-size: 48px; margin-bottom: 16px;">
                            <?php echo $passed ? '🎉' : '📚'; ?>
                        </div>
                        <h2 style="margin: 0 0 16px 0;">
                            <?php echo $passed ? 'Great Job! You Passed!' : 'Keep Going - Review and Try Again'; ?>
                        </h2>
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 8px;">
                            <?php echo $percent; ?>% (<?php echo $score; ?>/<?php echo $total; ?>)
                        </div>
                        <?php if (!$passed): ?>
                        <p style="margin: 0;">You need <?php echo in_array($module['slug'], ['final-assessment', 'final-exam']) ? '80%' : '70%'; ?> to pass. Review the incorrect answers below.</p>
                        <?php endif; ?>
                    </div>
                    
                    <?php
                    // Re-render questions with results
                    // We need to get the correct answers from the module content
                    // This is a simplified version - in production, you'd parse the module content
                    ?>
                    <div id="results-details">
                        <h3 style="margin-top: 24px; margin-bottom: 16px;">Review Your Answers</h3>
                        <!-- Results will be populated by JavaScript -->
                    </div>
                    
                    <div style="text-align: center; margin-top: 32px; padding-top: 24px; border-top: 2px solid #e5e7eb;">
                        <?php if ($passed || is_training_complete($uid)): ?>
                            <a href="<?php echo !empty($nextModuleSlug) ? '/onboarding/trainee/module.php?slug=' . urlencode($nextModuleSlug) : '/onboarding/trainee/'; ?>" 
                               class="btn" style="font-size: 18px; padding: 16px 32px;">
                                <?php echo is_training_complete($uid) ? 'Return to Training Portal' : 'Continue to Next Module →'; ?>
                            </a>
                        <?php else: ?>
                            <button onclick="location.href='<?php echo '/onboarding/trainee/module.php?slug=' . urlencode($slug); ?>'" 
                                    class="btn" style="font-size: 18px; padding: 16px 32px;">
                                Review Material & Retake Quiz
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <script>
                // Populate results details from the quiz questions on the page
                document.addEventListener('DOMContentLoaded', function() {
                    const resultsContainer = document.getElementById('results-details');
                    if (!resultsContainer) return;
                    
                    const questions = document.querySelectorAll('.quiz-question');
                    const userAnswers = <?php echo json_encode($userAnswers); ?>;
                    
                    let resultsHTML = '';
                    questions.forEach((question, index) => {
                        const questionNum = index + 1;
                        const answerDiv = question.querySelector('.answer');
                        if (!answerDiv) return;
                        
                        const correctAnswer = answerDiv.getAttribute('data-answer');
                        const userAnswer = userAnswers['q' + questionNum];
                        const isCorrect = userAnswer && correctAnswer && userAnswer.toLowerCase() === correctAnswer.toLowerCase();
                        
                        const questionText = question.querySelector('p strong')?.parentElement?.textContent || `Question ${questionNum}`;
                        const explanation = answerDiv.innerHTML;
                        
                        // Get all answer choices
                        const choices = question.querySelectorAll('.quiz-options label');
                        let choicesHTML = '';
                        choices.forEach(choice => {
                            const input = choice.querySelector('input');
                            if (!input) return;
                            
                            const value = input.value.toLowerCase();
                            const isUserChoice = userAnswer && value === userAnswer.toLowerCase();
                            const isCorrectChoice = correctAnswer && value === correctAnswer.toLowerCase();
                            
                            let choiceClass = 'answer-choice';
                            if (isCorrectChoice) choiceClass += ' correct-answer';
                            else if (isUserChoice && !isCorrect) choiceClass += ' wrong-answer';
                            else if (isUserChoice) choiceClass += ' user-selected';
                            
                            const choiceText = choice.textContent.trim();
                            choicesHTML += `<div class="${choiceClass}">${isCorrectChoice ? '✅ ' : (isUserChoice && !isCorrect ? '❌ ' : '')}${choiceText}</div>`;
                        });
                        
                        resultsHTML += `
                            <div class="result-question ${isCorrect ? 'correct' : 'incorrect'}">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                    <div><strong>${questionText}</strong></div>
                                    <div style="font-weight: 700; font-size: 20px;">${isCorrect ? '✅' : '❌'}</div>
                                </div>
                                ${choicesHTML}
                                <div class="explanation" style="${isCorrect ? 'display: none;' : ''}">
                                    ${explanation}
                                </div>
                                ${isCorrect ? `<div class="explanation-toggle" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'; this.textContent = this.textContent.includes('Show') ? '▲ Hide Explanation' : '▼ Show Explanation';">▼ Show Explanation</div><div class="explanation" style="display: none;">${explanation}</div>` : ''}
                            </div>
                        `;
                    });
                    
                    if (resultsHTML) {
                        resultsContainer.innerHTML += resultsHTML;
                    }
                });
                </script>
                
                <?php
                        // Clear the session data after displaying
                        unset($_SESSION['quiz_results_' . $module['id']]);
                    endif;
                endif;
                ?>
                
                <?php if ($progress['status'] !== 'completed' && $module['slug'] !== 'final-exam' && !isset($_GET['show_results'])): ?>
                <form method="post" style="margin-top: 48px; padding-top: 24px; border-top: 2px solid #e5e7eb;">
                    <div style="text-align: center;">
                        <p style="margin-bottom: 16px; color: #6b7280;">
                            Have you completed reviewing all the material in this module?
                        </p>
                        <input type="hidden" name="quiz_score" value="">
                        <input type="hidden" name="quiz_attempts" value="">
                        <input type="hidden" name="total_questions" value="">
                        <input type="hidden" name="correct_answers" value="">
                        <button type="submit" name="complete" value="1" class="btn" style="font-size: 18px; padding: 16px 32px;">
                            ✅ Mark Module as Complete
                        </button>
                    </div>
                </form>
                <?php elseif ($progress['status'] === 'completed'): ?>
                <!-- Single completion card with Retake and Continue -->
                <div class="card" style="margin-top: 24px; padding: 16px; border: 1px solid #e5e7eb;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                        <div style="color:#059669;font-weight:600;">✅ You have successfully completed this module!</div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <a href="<?php echo !empty($nextModuleSlug) ? '/onboarding/trainee/module.php?slug=' . urlencode($nextModuleSlug) : '/onboarding/trainee/'; ?>" class="btn secondary">Continue to Next Module</a>
                            <button id="retake-submit-quiz" type="button" class="btn" style="background:#2563eb;">Retake Quiz</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Hidden quiz submit form to allow re-testing even after completion -->
                <form id="inline-quiz-form" method="post" style="display: none;">
                    <input type="hidden" name="quiz_score" value="">
                    <input type="hidden" name="quiz_attempts" value="">
                    <input type="hidden" name="total_questions" value="">
                    <input type="hidden" name="correct_answers" value="">
                    <input type="hidden" name="complete" value="1">
                </form>

                <?php
                // Attempt History (applies to all modules)
                try {
                    $stmt = $pdo->prepare('SELECT score, total_questions, passed, attempt_number, completed_at
                                            FROM training_quiz_results
                                            WHERE user_id = ? AND module_id = ?
                                            ORDER BY completed_at DESC, attempt_number DESC
                                            LIMIT 10');
                    $stmt->execute([$uid, $module['id']]);
                    $quizAttempts = $stmt->fetchAll();
                } catch (Exception $e) {
                    $quizAttempts = [];
                }
                // Unified Quiz Controls: Submit button and last 3 attempts expander
                $lastThree = array_slice($quizAttempts, 0, 3);
                $latestAttempt = $lastThree[0] ?? null;
                ?>
                <div id="quiz-controls" style="display: none; margin-top: 16px;">
                    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
                        <button id="submit-quiz-unified" type="button" class="btn" style="font-size:16px;">Submit Quiz</button>
                        <?php if (!empty($lastThree)): ?>
                        <div id="last-attempts-wrap" style="flex:1;min-width:260px;">
                            <div id="last-attempts-toggle" style="cursor:pointer;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;">
                                <div style="display:flex;gap:10px;align-items:center;">
                                    <strong>Latest:</strong>
                                    <span>
                                        <?php if ($latestAttempt): ?>
                                            <?php echo intval($latestAttempt['score']); ?><?php echo $latestAttempt['total_questions'] ? ' / ' . intval($latestAttempt['total_questions']) : ''; ?>
                                            (<?php echo $latestAttempt['passed'] ? 'Passed ✅' : 'Failed ❌'; ?>)
                                            • <?php echo date('M j, g:i a', strtotime($latestAttempt['completed_at'])); ?>
                                        <?php else: ?>
                                            No attempts yet
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <span style="color:#6b7280;">Show last 3 ▾</span>
                            </div>
                            <div id="last-attempts-panel" style="display:none;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:12px;">
                                <div style="display:grid;grid-template-columns:1fr auto auto auto;gap:8px;font-size:14px;color:#374151;">
                                    <div style="font-weight:600;">Date</div>
                                    <div style="font-weight:600;">Score</div>
                                    <div style="font-weight:600;">Result</div>
                                    <div style="font-weight:600;">Attempt</div>
                                    <?php foreach ($lastThree as $qa): ?>
                                        <div><?php echo date('M j, Y g:i a', strtotime($qa['completed_at'])); ?></div>
                                        <div><?php echo intval($qa['score']); ?><?php echo $qa['total_questions'] ? ' / ' . intval($qa['total_questions']) : ''; ?></div>
                                        <div><?php echo $qa['passed'] ? '✅ Passed' : '❌ Failed'; ?></div>
                                        <div>#<?php echo intval($qa['attempt_number']); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($quizAttempts)): ?>
                <div class="card" style="margin-top: 32px; padding: 16px; border: 1px solid #e5e7eb;">
                    <h3 style="margin: 0 0 12px; color: #1f2937;">Recent Quiz Attempts</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 8px; font-size: 14px; color: #374151;">
                        <div style="font-weight:600;">Date</div>
                        <div style="font-weight:600;">Score</div>
                        <div style="font-weight:600;">Result</div>
                        <div style="font-weight:600;">Attempt</div>
                        <?php foreach ($quizAttempts as $qa): ?>
                            <div><?php echo date('M j, Y g:i a', strtotime($qa['completed_at'])); ?></div>
                            <div><?php echo intval($qa['score']); ?><?php echo $qa['total_questions'] ? ' / ' . intval($qa['total_questions']) : ''; ?></div>
                            <div><?php echo $qa['passed'] ? '✅ Passed' : '❌ Failed'; ?></div>
                            <div>#<?php echo intval($qa['attempt_number']); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php
                    // Summary: best and last scores
                    $best = max(array_map(function($r){ return intval($r['score']); }, $quizAttempts));
                    $last = intval($quizAttempts[0]['score']);
                    ?>
                    <div style="margin-top: 12px; font-size: 13px; color:#6b7280;">
                        Last score: <strong><?php echo $last; ?></strong> • Best score: <strong><?php echo $best; ?></strong>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php 
                // Module Review System - Show for completed modules
                if ($progress['status'] === 'completed') {
                    // Check if user has already reviewed this module
                    try {
                        $stmt = $pdo->prepare('SELECT * FROM training_module_reviews WHERE user_id = ? AND module_id = ? LIMIT 1');
                        $stmt->execute([$uid, $module['id']]);
                        $existingReview = $stmt->fetch();
                    } catch (Exception $e) {
                        $existingReview = null;
                        // Table might not exist yet - that's ok
                    }
                    
                    // Handle review submission
                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
                        try {
                            $rating = max(0, min(5, intval($_POST['rating'] ?? 0)));
                            $review_comments = trim($_POST['review_comments'] ?? '');
                            // Map minimal review to required fields
                            $difficulty_rating = $rating > 0 ? $rating : 1;
                            $content_quality_rating = $rating > 0 ? $rating : 1;
                            $usefulness_rating = $rating > 0 ? $rating : 1;
                            $review_title = '';
                            $suggestions = '';
                            $questions = '';
                            $would_recommend = 1;
                            $anonymous = 0;
                            $completion_time = intval($progress['total_time_minutes'] ?? 0);
                            
                            if ($rating >= 0 && $rating <= 5) {
                                $rating_to_store = max(1, $rating); // satisfy DB constraint if 0 selected
                                
                                if ($existingReview) {
                                    // Update existing review
                                    $stmt = $pdo->prepare('UPDATE training_module_reviews SET 
                                        rating = ?, difficulty_rating = ?, content_quality_rating = ?, usefulness_rating = ?,
                                        review_title = ?, review_comments = ?, suggestions = ?, questions = ?,
                                        would_recommend = ?, anonymous = ?, completion_time_minutes = ?, updated_at = NOW()
                                        WHERE user_id = ? AND module_id = ?');
                                    $stmt->execute([$rating_to_store, $difficulty_rating, $content_quality_rating, $usefulness_rating,
                                                  $review_title, $review_comments, $suggestions, $questions, 
                                                  $would_recommend, $anonymous, $completion_time, $uid, $module['id']]);
                                    flash_set('success', 'Your review has been updated successfully!');
                                } else {
                                    // Insert new review
                                    $stmt = $pdo->prepare('INSERT INTO training_module_reviews 
                                        (user_id, module_id, rating, difficulty_rating, content_quality_rating, usefulness_rating,
                                         review_title, review_comments, suggestions, questions, would_recommend, anonymous, completion_time_minutes)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                                    $stmt->execute([$uid, $module['id'], $rating_to_store, $difficulty_rating, $content_quality_rating, $usefulness_rating,
                                                  $review_title, $review_comments, $suggestions, $questions, 
                                                  $would_recommend, $anonymous, $completion_time]);
                                    flash_set('success', 'Thank you for your review! Your feedback helps us improve.');
                                }
                                
                                // Refresh the page to show updated review
                                redirect($_SERVER['REQUEST_URI']);
                            } else {
                                flash_set('error', 'Please provide a rating from 0–5 stars.');
                            }
                        } catch (Exception $e) {
                            flash_set('error', 'Unable to save your review. Please try again.');
                            error_log("Review submission error: " . $e->getMessage());
                        }
                    }
                ?>
                
                <!-- Module Review Section -->
                <div id="module-review" style="margin-top: 48px; padding-top: 24px; border-top: 2px solid #e5e7eb;">
                    <div style="text-align: center; margin-bottom: 32px;">
                        <h3 style="color: var(--blue); margin: 0 0 8px;">How was this module?</h3>
                        <p style="color: #6b7280; margin: 0;">Your feedback helps us improve the training experience for everyone.</p>
                    </div>
                    
                    <?php if ($existingReview): ?>
                    <!-- Show existing review with edit option -->
                    <div class="card" style="background: #f0fdf4; border-color: #16a34a; margin-bottom: 24px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                            <span style="font-size: 20px;">✅</span>
                            <h4 style="margin: 0; color: #166534;">Your Review</h4>
                            <div style="margin-left: auto; display: flex; gap: 4px;">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span style="color: <?php echo $i <= $existingReview['rating'] ? '#fbbf24' : '#d1d5db'; ?>; font-size: 16px;">★</span>
                                <?php endfor; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($existingReview['review_comments'])): ?>
                        <p style="color: #166534; margin: 0 0 12px;"><?php echo sanitize($existingReview['review_comments']); ?></p>
                        <?php else: ?>
                        <p style="color: #6b7280; margin: 0 0 12px;">No comments provided.</p>
                        <?php endif; ?>
                        
                        <div style="text-align: center; margin-top: 16px;">
                            <button onclick="toggleReviewEdit()" class="btn secondary" style="font-size: 14px; padding: 8px 16px;">
                                Edit Review
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Review Form (minimal) -->
                    <div id="review-form" class="card" style="<?php echo $existingReview ? 'display: none;' : ''; ?>">
                        <form method="post">
                            <!-- Overall Rating (0–5) -->
                            <div style="margin-bottom: 24px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                    Rate this module (0–5)
                                </label>
                                <div class="star-rating" data-rating="<?php echo $existingReview['rating'] ?? 0; ?>">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="star" data-value="<?php echo $i; ?>" style="font-size: 24px; cursor: pointer; color: <?php echo $existingReview && $i <= $existingReview['rating'] ? '#fbbf24' : '#d1d5db'; ?>;">★</span>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="rating" value="<?php echo $existingReview['rating'] ?? 0; ?>">
                                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">Tip: 0 stars = skip rating and just leave a comment.</div>
                            </div>
                            
                            
                            <!-- Comments only -->
                            <div style="margin-bottom: 24px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                    Comments & Feedback
                                </label>
                                <textarea name="review_comments" rows="4" placeholder="What did you think of this module? What did you like or dislike?"
                                          style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"><?php echo sanitize($existingReview['review_comments'] ?? ''); ?></textarea>
                            </div>

                            <input type="hidden" name="suggestions" value="">
                            <input type="hidden" name="questions" value="">
                            
                            <!-- Submit Button -->
                            <div style="text-align: center;">
                                <button type="submit" name="submit_review" class="btn" style="font-size: 16px; padding: 12px 32px;">
                                    <?php echo $existingReview ? 'Update Review' : 'Submit Review'; ?>
                                </button>
                                <?php if ($existingReview): ?>
                                <button type="button" onclick="toggleReviewEdit()" class="btn secondary" style="font-size: 16px; padding: 12px 32px; margin-left: 16px;">
                                    Cancel
                                </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
</section>

<!-- Enhanced Time Tracking Integration -->
<script src="<?php echo path_with_base('/assets/training-time-tracker.js'); ?>?v=<?php echo time(); ?>"></script>

<script>
// Initialize tracking data for this module
window.trainingTrackingData = {
    userId: <?php echo json_encode($uid); ?>,
    moduleId: <?php echo json_encode($module['id']); ?>,
    sessionId: '<?php echo session_id(); ?>_' + Date.now()
};

// Enhanced quiz integration
document.addEventListener('DOMContentLoaded', function() {
    const MODULE_COMPLETED = <?php echo json_encode(($progress['status'] ?? '') === 'completed'); ?>;
    // Track quiz starts
    document.querySelectorAll('.quiz-question').forEach((quiz, index) => {
        const inputs = quiz.querySelectorAll('input[type="radio"]');
        let quizStarted = false;
        
        inputs.forEach(input => {
            input.addEventListener('focus', () => {
                if (!quizStarted) {
                    window.TrainingTracker.onQuizStart();
                    quizStarted = true;
                }
            });
        });
    });
    
    // Track form submissions
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Calculate quiz score if this form submission relates to module completion
            const allQuestions = Array.from(document.querySelectorAll('.quiz-question'));
            const totalQuestions = allQuestions.length;
            let correct = 0;
            let resolvableCount = 0;
            if (totalQuestions > 0) {
                allQuestions.forEach(q => {
                    const answerEl = q.querySelector('.answer');
                    let correctLetter = answerEl && answerEl.getAttribute('data-answer')
                        ? answerEl.getAttribute('data-answer').toLowerCase()
                        : null;
                    if (!correctLetter && answerEl) {
                        const m = answerEl.textContent.match(/Correct Answer:\s*([A-D])/i);
                        if (m) correctLetter = m[1].toLowerCase();
                    }
                    if (correctLetter) resolvableCount++;
                    const selected = q.querySelector('input[type="radio"]:checked');
                    if (selected && correctLetter && selected.value.toLowerCase() === correctLetter) correct++;
                });

                // If we cannot resolve any correct answers from content, defer to module-specific logic
                if (resolvableCount === 0) return;

                const percent = Math.round((correct/Math.max(1,totalQuestions))*100);
                const quizAttempts = parseInt(localStorage.getItem('quiz_attempts_<?php echo $module['id']; ?>') || '0') + 1;
                localStorage.setItem('quiz_attempts_<?php echo $module['id']; ?>', quizAttempts.toString());

                // Populate hidden inputs for server processing (only if not already set)
                const setHidden = (name, val) => {
                    const el = form.querySelector(`input[name="${name}"]`);
                    if (el && (el.value === '' || el.value === null)) el.value = String(val);
                };
                setHidden('quiz_score', percent);
                setHidden('quiz_attempts', quizAttempts);
                setHidden('total_questions', totalQuestions);
                setHidden('correct_answers', correct);

                // Expose score for any other script hooks
                let scoreEl = document.querySelector('[data-quiz-score]');
                if (!scoreEl) {
                    scoreEl = document.createElement('div');
                    scoreEl.style.display = 'none';
                    document.body.appendChild(scoreEl);
                }
                scoreEl.setAttribute('data-quiz-score', String(percent));

                window.TrainingTracker.onQuizSubmit(percent, quizAttempts);
            }
        });
    });
    
    // Track module completion
    const completeButtons = document.querySelectorAll('button[type="submit"], .btn[href*="complete"]');
    completeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const finalScore = getFinalQuizScore();
            window.TrainingTracker.onModuleComplete(finalScore);
        });
    });
    
    <?php if (in_array('admin', user_roles($uid) ?? [])): ?>
    // Add admin stats overlay
    addAdminStatsDisplay();
    <?php endif; ?>

    // Show unified quiz controls if quiz questions exist
    const hasQuiz = document.querySelectorAll('.quiz-question').length > 0;
    const quizControls = document.getElementById('quiz-controls');
    if (hasQuiz && quizControls) quizControls.style.display = 'block';

    // Expand/collapse last 3 attempts
    const attemptsToggle = document.getElementById('last-attempts-toggle');
    const attemptsPanel = document.getElementById('last-attempts-panel');
    if (attemptsToggle && attemptsPanel) {
        attemptsToggle.addEventListener('click', function(){
            attemptsPanel.style.display = attemptsPanel.style.display === 'none' ? 'block' : 'none';
        });
    }

    // Unified submit handling (works even after completion)
    const doSubmitQuiz = () => {
        const completeInput = document.querySelector('form input[name="complete"]');
        const completionForm = completeInput ? completeInput.form : null;
        const payloadForm = completionForm || document.getElementById('inline-quiz-form') || document.querySelector('form');
        if (!payloadForm) return;
        const formData = new FormData(payloadForm);
        formData.set('ajax', '1');
        fetch(location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(data => { if (data && data.next_url) { location.replace(data.next_url); } else { location.reload(); } })
            .catch(() => { payloadForm.requestSubmit ? payloadForm.requestSubmit() : payloadForm.submit(); });
    };
    const unifiedBtn = document.getElementById('submit-quiz-unified');
    if (unifiedBtn) {
        if (MODULE_COMPLETED) unifiedBtn.style.display = 'none';
        unifiedBtn.addEventListener('click', doSubmitQuiz);
    }
    const retakeBtn = document.getElementById('retake-submit-quiz');
    if (retakeBtn) retakeBtn.addEventListener('click', doSubmitQuiz);
    // Legacy module buttons (e.g., ten-step-close.php)
    const legacyBtn = document.getElementById('submit-quiz-btn');
    if (legacyBtn) legacyBtn.addEventListener('click', doSubmitQuiz);

    // Admin Answer View toggle - defaults to OFF (trainee experience)
    const adminToggleBar = document.getElementById('admin-answer-toggle');
    const adminToggle = document.getElementById('admin-show-answers-toggle');
    const adminLabel = document.getElementById('admin-show-answers-label');
    const IS_ADMIN = !!adminToggleBar;
    if (IS_ADMIN) {
        if (hasQuiz && adminToggleBar) adminToggleBar.style.display = 'flex';
        const storageKey = 'module_' + String(<?php echo json_encode($module['id']); ?>) + '_show_answers';
        let showAnswers = localStorage.getItem(storageKey) === 'true';
        if (adminToggle) adminToggle.checked = showAnswers;
        const updateLabel = () => { 
            if (adminLabel) {
                adminLabel.textContent = adminToggle && adminToggle.checked ? 
                    'Instant preview enabled (answers shown on click)' : 
                    'Enable instant answer preview (check to see answers on click)';
            }
        };
        updateLabel();
        const clearAnswerStates = () => {
            document.querySelectorAll('.quiz-question').forEach(q => {
                q.querySelectorAll('.quiz-options label').forEach(l => l.classList.remove('correct','incorrect'));
                const ans = q.querySelector('.answer');
                if (ans) { ans.style.display = 'none'; ans.classList.remove('correct','incorrect'); }
            });
        };
        if (adminToggle) {
            adminToggle.addEventListener('change', () => {
                showAnswers = !!adminToggle.checked;
                localStorage.setItem(storageKey, showAnswers ? 'true' : 'false');
                updateLabel();
                if (!showAnswers) clearAnswerStates();
            });
        }
        document.querySelectorAll('.quiz-options input').forEach(input => {
            if (input.__kr_admin_handler) return;
            input.__kr_admin_handler = true;
            input.addEventListener('change', function(){
                if (!showAnswers) return;
                const questionDiv = this.closest('.quiz-question');
                const answerDiv = questionDiv ? questionDiv.querySelector('.answer') : null;
                let correctValue = questionDiv ? questionDiv.getAttribute('data-correct') : null;
                if (!correctValue && answerDiv) {
                    const m = answerDiv.textContent.match(/Correct Answer:\s*([A-D])/i);
                    if (m) correctValue = m[1].toLowerCase();
                }
                questionDiv.querySelectorAll('.quiz-options label').forEach(label => label.classList.remove('correct','incorrect'));
                if (answerDiv) answerDiv.classList.remove('correct','incorrect');
                const selectedLabel = this.closest('label');
                if (selectedLabel && correctValue) {
                    if (this.value.toLowerCase() === String(correctValue).toLowerCase()) {
                        selectedLabel.classList.add('correct');
                        if (answerDiv) { answerDiv.style.display = 'block'; answerDiv.classList.add('correct'); }
                    } else {
                        selectedLabel.classList.add('incorrect');
                        const correctInput = questionDiv.querySelector(`input[value="${String(correctValue)}"]`);
                        if (correctInput) {
                            const correctLabel = correctInput.closest('label');
                            if (correctLabel) correctLabel.classList.add('correct');
                        }
                        if (answerDiv) { answerDiv.style.display = 'block'; answerDiv.classList.add('incorrect'); }
                    }
                }
            });
        });
    }
});

function getFinalQuizScore() {
    const scoreElement = document.querySelector('[data-quiz-score]');
    if (scoreElement) {
        return parseFloat(scoreElement.dataset.quizScore);
    }
    return null;
}

// Module Review System JavaScript
function toggleReviewEdit() {
    const reviewForm = document.getElementById('review-form');
    if (reviewForm.style.display === 'none') {
        reviewForm.style.display = 'block';
    } else {
        reviewForm.style.display = 'none';
    }
}

// Star Rating Functionality
document.addEventListener('DOMContentLoaded', function() {
    const starRatings = document.querySelectorAll('.star-rating');
    
    starRatings.forEach(rating => {
        const stars = rating.querySelectorAll('.star');
        const inputName = rating.dataset.name || 'rating';
        const hiddenInput = rating.parentNode.querySelector(`input[name="${inputName}"]`);
        
        stars.forEach((star, index) => {
            star.addEventListener('click', function() {
                const value = parseInt(this.dataset.value);
                hiddenInput.value = value;
                
                // Update visual state
                stars.forEach((s, i) => {
                    if (i < value) {
                        s.style.color = '#fbbf24';
                    } else {
                        s.style.color = '#d1d5db';
                    }
                });
            });
            
            star.addEventListener('mouseover', function() {
                const value = parseInt(this.dataset.value);
                stars.forEach((s, i) => {
                    if (i < value) {
                        s.style.color = '#fbbf24';
                    } else {
                        s.style.color = '#d1d5db';
                    }
                });
            });
        });
        
        rating.addEventListener('mouseleave', function() {
            const currentRating = parseInt(hiddenInput.value);
            stars.forEach((s, i) => {
                if (i < currentRating) {
                    s.style.color = '#fbbf24';
                } else {
                    s.style.color = '#d1d5db';
                }
            });
        });
    });
});

<?php if (in_array('admin', user_roles($uid) ?? [])): ?>
function addAdminStatsDisplay() {
    const statsDiv = document.createElement('div');
    statsDiv.id = 'admin-stats-overlay';
    statsDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: rgba(0,0,0,0.9);
        color: white;
        padding: 16px;
        border-radius: 8px;
        font-family: monospace;
        font-size: 12px;
        z-index: 9999;
        min-width: 200px;
    `;
    
    document.body.appendChild(statsDiv);
    
    setInterval(() => {
        if (window.TrainingTracker?.isActive()) {
            const stats = window.TrainingTracker.getCurrentStats();
            statsDiv.innerHTML = `
                <strong>ADMIN: Live Stats</strong><br>
                Total: ${Math.floor(stats.totalTime / 60)}:${String(stats.totalTime % 60).padStart(2, '0')}<br>
                Active: ${Math.floor(stats.activeTime / 60)}:${String(stats.activeTime % 60).padStart(2, '0')}<br>
                Idle: ${Math.floor(stats.idleTime / 60)}:${String(stats.idleTime % 60).padStart(2, '0')}<br>
                Views: ${stats.pageViews}<br>
                Scroll: ${Math.round(stats.scrollPercentage)}%<br>
                Engagement: ${Math.round(stats.engagementScore)}%<br>
                Status: ${stats.isIdle ? '🟡 IDLE' : '🟢 ACTIVE'}
            `;
        }
    }, 5000);
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>