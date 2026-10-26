<?php
/**
 * Quiz Migration Utility
 * Extracts quiz questions from existing module PHP files and migrates to database
 */

$SEO = ['title' => 'Migrate Quizzes to Database', 'description' => 'Extract and migrate quiz questions', 'type' => 'website'];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$report = [];
$errors = [];

if (isset($_POST['migrate'])) {
    try {
        $pdo->beginTransaction();
        
        // Get only the NEW active modules with existing files
        $activeModuleSlugs = [
            'intro-product',
            'three-stage-filter',
            'product-mastery',
            'objection-essentials',
            'final-assessment'
        ];
        
        $placeholders = str_repeat('?,', count($activeModuleSlugs) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id, slug, title FROM training_modules WHERE slug IN ($placeholders) AND required = 1");
        $stmt->execute($activeModuleSlugs);
        $modules = $stmt->fetchAll();
        
        $totalQuestions = 0;
        $totalLinked = 0;
        
        foreach ($modules as $module) {
            $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $module['slug'] . '.php';
            
            if (!file_exists($moduleFile)) {
                $errors[] = "Module file not found: " . $module['slug'] . '.php';
                continue;
            }
            
            $content = file_get_contents($moduleFile);
            
            // Split content by quiz-question divs for easier parsing
            $quizBlocks = explode('<div class="quiz-question">', $content);
            array_shift($quizBlocks); // Remove content before first quiz
            
            if (!empty($quizBlocks)) {
                $questionNum = 1;
                
                foreach ($quizBlocks as $block) {
                    // Extract until we hit the closing div of quiz-question (before next major section)
                    if (preg_match('/^(.*?)<\/div>(?:\s*<script>.*?<\/script>)?\s*(?=<h3>|<div class="quiz-question"|$)/s', $block, $blockMatch)) {
                        $quizHtml = $blockMatch[1];
                    } else {
                        // Simple fallback - just take content up to script or next h3
                        if (preg_match('/^(.*?)(?=<script>|<h3>|$)/s', $block, $blockMatch)) {
                            $quizHtml = $blockMatch[1];
                        } else {
                            continue;
                        }
                    }
                    
                    // Extract question text - look for <p><strong>Question
                    $questionText = '';
                    if (preg_match('/<p><strong>Question[^:]*:<\/strong>\s*(.*?)<\/p>/s', $quizHtml, $qMatch)) {
                        $questionText = trim(strip_tags($qMatch[1]));
                    }
                    
                    // Extract options from quiz-options div
                    $options = [];
                    if (preg_match('/<div class="quiz-options">(.*?)<\/div>/s', $quizHtml, $optionsBlock)) {
                        if (preg_match_all('/<label><input[^>]*type="radio"[^>]*value="([a-d])"[^>]*>\s*(.*?)<\/label>/s', $optionsBlock[1], $optMatches, PREG_SET_ORDER)) {
                            foreach ($optMatches as $optMatch) {
                                $options[$optMatch[1]] = trim(strip_tags($optMatch[2]));
                            }
                        }
                    }
                    
                    // Extract correct answer from data-answer attribute or answer block
                    $correctAnswer = '';
                    if (preg_match('/data-answer="([a-d])"/i', $quizHtml, $ansMatch)) {
                        $correctAnswer = strtolower($ansMatch[1]);
                    } elseif (preg_match('/class="answer"[^>]*data-answer="([a-d])"/i', $quizHtml, $ansMatch)) {
                        $correctAnswer = strtolower($ansMatch[1]);
                    } elseif (preg_match('/<strong>Correct(?:\s+Answer)?:\s*([A-D])<\/strong>/i', $quizHtml, $ansMatch)) {
                        $correctAnswer = strtolower($ansMatch[1]);
                    }
                    
                    // Extract explanation from answer paragraph
                    $explanation = '';
                    if (preg_match('/<p[^>]*class="answer"[^>]*>.*?<strong>Correct(?:\s+Answer)?(?::\s*[A-D])?\s*<\/strong>\s*-?\s*(.*?)<\/p>/s', $quizHtml, $expMatch)) {
                        $explanation = trim(strip_tags($expMatch[1]));
                    } elseif (preg_match('/<strong>Correct[^<]*<\/strong>\s*-\s*(.*?)(?:<\/p>|<\/div>)/s', $quizHtml, $expMatch)) {
                        $explanation = trim(strip_tags($expMatch[1]));
                    }
                    
                    // Detect product references (current SKUs only)
                    $productId = null;
                    $productSkus = ['BLUE-10-32', 'GOLD-40-32', 'BLACK-80-32'];
                    foreach ($productSkus as $sku) {
                        if (stripos($quizHtml, $sku) !== false || 
                            stripos($questionText, $sku) !== false ||
                            stripos($questionText, str_replace('-32', '', $sku)) !== false) {
                            $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
                            $stmt->execute([$sku]);
                            $productId = $stmt->fetchColumn();
                            if ($productId) {
                                $totalLinked++;
                                break;
                            }
                        }
                    }
                    
                    // Also detect by product name references
                    $productNames = [
                        'Blue 10' => 'BLUE-10-32',
                        'Product B' => 'GOLD-40-32',
                        'Black 80' => 'BLACK-80-32'
                    ];
                    if (!$productId) {
                        foreach ($productNames as $name => $sku) {
                            if (stripos($questionText, $name) !== false || stripos($quizHtml, $name) !== false) {
                                $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
                                $stmt->execute([$sku]);
                                $productId = $stmt->fetchColumn();
                                if ($productId) {
                                    $totalLinked++;
                                    break;
                                }
                            }
                        }
                    }
                    
                    // Determine position based on question number
                    $quizPosition = 'mid';
                    if ($questionNum === 1) $quizPosition = 'intro';
                    elseif ($questionNum === 2) $quizPosition = 'early';
                    elseif ($questionNum >= count($quizBlocks) - 1) $quizPosition = 'final';
                    
                    // Check if question requires calculation
                    $requiresCalculation = (stripos($questionText, 'calculate') !== false || 
                                          stripos($questionText, 'commission') !== false ||
                                          stripos($questionText, 'profit') !== false);
                    
                    if (!empty($questionText) && !empty($options) && !empty($correctAnswer)) {
                        // Insert into database
                        $stmt = $pdo->prepare("
                            INSERT INTO training_quiz_questions 
                            (module_id, product_id, quiz_position, question_text, question_type, options, correct_answer, explanation, sort_order, rotation_enabled, requires_calculation, is_active, created_at)
                            VALUES (?, ?, ?, ?, 'multiple_choice', ?, ?, ?, ?, 0, ?, 1, NOW())
                        ");
                        $stmt->execute([
                            $module['id'],
                            $productId,
                            $quizPosition,
                            $questionText,
                            json_encode($options),
                            $correctAnswer,
                            $explanation,
                            $questionNum,
                            $requiresCalculation ? 1 : 0
                        ]);
                        
                        $totalQuestions++;
                        $questionNum++;
                    }
                }
                
                $report[] = "Module '{$module['title']}': Migrated " . ($questionNum - 1) . " questions";
            } else {
                $report[] = "Module '{$module['title']}': No questions found";
            }
        }
        
        $pdo->commit();
        $report[] = "<strong>MIGRATION COMPLETE</strong>";
        $report[] = "Total questions migrated: {$totalQuestions}";
        $report[] = "Questions linked to products: {$totalLinked}";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = "Migration error: " . $e->getMessage();
    }
}
?>

<style>
.migration-container { max-width: 1000px; margin: 0 auto; padding: 20px; }
.report-item { padding: 8px 12px; background: #f9fafb; border-left: 4px solid #3b82f6; margin-bottom: 8px; border-radius: 4px; }
.error-item { padding: 8px 12px; background: #fee2e2; border-left: 4px solid #dc2626; margin-bottom: 8px; border-radius: 4px; color: #991b1b; }
</style>

<div class="migration-container">
    <div class="card">
        <h1>Quiz Migration Utility</h1>
        <p style="color: #6b7280;">This tool extracts quiz questions from existing module PHP files and migrates them to the database.</p>
        
        <?php if (!empty($report)): ?>
            <div style="background: #dcfce7; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #166534; margin: 0 0 12px 0;">Migration Report</h3>
                <?php foreach ($report as $item): ?>
                    <div class="report-item"><?php echo $item; ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div style="background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #991b1b; margin: 0 0 12px 0;">Errors</h3>
                <?php foreach ($errors as $error): ?>
                    <div class="error-item"><?php echo $error; ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div style="background: #fffbeb; padding: 20px; border-radius: 8px; margin: 20px 0; border: 2px solid #f59e0b;">
            <h4 style="color: #92400e; margin: 0 0 12px 0;">⚠️ Important Notes</h4>
            <ul style="color: #78350f; margin: 0;">
                <li>This migration will parse PHP files and extract quiz questions</li>
                <li>Questions will be inserted into training_quiz_questions table</li>
                <li>Product references (BLUE-10-32, GOLD-40-32, etc.) will be auto-detected and linked</li>
                <li>Existing questions in database will NOT be affected (new insertions only)</li>
                <li>You can run this multiple times safely</li>
            </ul>
        </div>
        
        <form method="post">
            <button type="submit" name="migrate" class="btn" style="background: var(--green); color: white;">
                Start Migration
            </button>
            <a href="<?php echo path_with_base('/admin/lms-manager.php'); ?>" class="btn secondary">
                Back to LMS Manager
            </a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

