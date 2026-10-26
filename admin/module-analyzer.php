<?php
$SEO = [
    'title' => 'Module Content Analyzer — Admin LMS',
    'description' => 'Analyze and manage training module content structure.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();

// Analyze existing modules
$modules = $pdo->query('SELECT * FROM training_modules ORDER BY sort_order')->fetchAll();
$moduleAnalysis = [];

foreach ($modules as $module) {
    $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $module['slug'] . '.php';
    $analysis = [
        'file_exists' => file_exists($moduleFile),
        'file_size' => file_exists($moduleFile) ? filesize($moduleFile) : 0,
        'content_length' => 0,
        'quiz_questions' => 0,
        'components' => [],
        'last_modified' => file_exists($moduleFile) ? date('Y-m-d H:i:s', filemtime($moduleFile)) : 'N/A'
    ];
    
    if ($analysis['file_exists']) {
        $content = file_get_contents($moduleFile);
        $analysis['content_length'] = strlen($content);
        
        // Count quiz questions
        preg_match_all('/<div class="quiz-question">/', $content, $matches);
        $analysis['quiz_questions'] = count($matches[0]);
        
        // Identify components
        if (strpos($content, 'key-highlight') !== false) $analysis['components'][] = 'Highlight Boxes';
        if (strpos($content, 'special-note') !== false) $analysis['components'][] = 'Special Notes';
        if (strpos($content, 'quiz-question') !== false) $analysis['components'][] = 'Quiz Questions';
        if (strpos($content, 'grid-template-columns') !== false) $analysis['components'][] = 'Grid Layouts';
        if (strpos($content, '<table') !== false) $analysis['components'][] = 'Tables';
        if (strpos($content, '<script') !== false) $analysis['components'][] = 'JavaScript';
    }
    
    $moduleAnalysis[$module['slug']] = $analysis;
}

// Get module performance data
$performanceData = [];
$stmt = $pdo->query('
    SELECT 
        tm.slug,
        tm.title,
        COUNT(DISTINCT tp.user_id) as total_attempts,
        COUNT(CASE WHEN tp.status = "completed" THEN 1 END) as completions,
        AVG(tp.quiz_score) as avg_quiz_score,
        AVG(tp.total_time_minutes) as avg_time,
        COUNT(CASE WHEN tp.quiz_score < 80 THEN 1 END) as quiz_failures
    FROM training_modules tm
    LEFT JOIN training_progress tp ON tp.module_id = tm.id
    GROUP BY tm.id
    ORDER BY tm.sort_order
');
$performanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
$performanceBySlug = [];
foreach ($performanceData as $data) {
    $performanceBySlug[$data['slug']] = $data;
}
?>

<style>
.analyzer-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
}

.analyzer-header {
    background: linear-gradient(135deg, #059669, #10b981);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}

.module-analysis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 24px;
}

.module-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    overflow: hidden;
}

.module-header {
    padding: 20px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

.module-body {
    padding: 20px;
}

.analysis-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.analysis-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #f3f4f6;
}

.status-indicator {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 8px;
}

.status-good { background: #10b981; }
.status-warning { background: #f59e0b; }
.status-error { background: #ef4444; }

.component-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}

.component-tag {
    background: #dbeafe;
    color: #1e40af;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
}

.action-buttons {
    display: flex;
    gap: 8px;
    margin-top: 16px;
}

.btn-small {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}
</style>

<div class="analyzer-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">🔍 Training Module Analyzer</h1>
        <p style="font-size: 18px; opacity: 0.9;">Comprehensive analysis of module content, structure, and performance</p>
    </div>
</div>

<div class="analyzer-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Overview Stats -->
    <div style="background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 24px; margin-bottom: 24px;">
        <h2 style="color: #1f2937; margin-bottom: 20px;">📊 System Overview</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
            <div style="text-align: center; padding: 16px; background: #f9fafb; border-radius: 8px;">
                <div style="font-size: 24px; font-weight: bold; color: #059669;"><?php echo count($modules); ?></div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Total Modules</div>
            </div>
            <div style="text-align: center; padding: 16px; background: #f9fafb; border-radius: 8px;">
                <div style="font-size: 24px; font-weight: bold; color: #3b82f6;"><?php echo count(array_filter($moduleAnalysis, fn($a) => $a['file_exists'])); ?></div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Files Exist</div>
            </div>
            <div style="text-align: center; padding: 16px; background: #f9fafb; border-radius: 8px;">
                <div style="font-size: 24px; font-weight: bold; color: #f59e0b;"><?php echo array_sum(array_column($moduleAnalysis, 'quiz_questions')); ?></div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Quiz Questions</div>
            </div>
            <div style="text-align: center; padding: 16px; background: #f9fafb; border-radius: 8px;">
                <div style="font-size: 24px; font-weight: bold; color: #dc2626;"><?php echo number_format(array_sum(array_column($moduleAnalysis, 'file_size')) / 1024, 1); ?>KB</div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Total Content</div>
            </div>
        </div>
    </div>
    
    <!-- Module Analysis -->
    <div class="module-analysis-grid">
        <?php foreach ($modules as $module): ?>
            <?php 
            $analysis = $moduleAnalysis[$module['slug']];
            $performance = $performanceBySlug[$module['slug']] ?? [];
            
            // Determine status
            $status = 'good';
            $statusText = 'Good';
            if (!$analysis['file_exists']) {
                $status = 'error';
                $statusText = 'Missing File';
            } elseif ($analysis['quiz_questions'] === 0) {
                $status = 'warning';  
                $statusText = 'No Quizzes';
            } elseif ($analysis['content_length'] < 1000) {
                $status = 'warning';
                $statusText = 'Short Content';
            }
            ?>
            <div class="module-card">
                <div class="module-header">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <h3 style="margin: 0 0 4px 0; color: #1f2937;">
                                <span class="status-indicator status-<?php echo $status; ?>"></span>
                                <?php echo htmlspecialchars($module['title']); ?>
                            </h3>
                            <p style="margin: 0; color: #6b7280; font-size: 14px;">
                                <?php echo $module['slug']; ?> • <?php echo $module['duration_minutes']; ?> min • 
                                <?php echo $module['required'] ? 'Required' : 'Optional'; ?>
                            </p>
                        </div>
                        <span style="background: <?php echo $status === 'good' ? '#dcfce7' : ($status === 'warning' ? '#fef3c7' : '#fee2e2'); ?>; 
                                     color: <?php echo $status === 'good' ? '#14532d' : ($status === 'warning' ? '#92400e' : '#991b1b'); ?>; 
                                     padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                            <?php echo $statusText; ?>
                        </span>
                    </div>
                </div>
                
                <div class="module-body">
                    <!-- Technical Analysis -->
                    <div class="analysis-grid">
                        <div class="analysis-item">
                            <span>File Size:</span>
                            <span><?php echo $analysis['file_exists'] ? number_format($analysis['file_size'] / 1024, 1) . 'KB' : 'N/A'; ?></span>
                        </div>
                        <div class="analysis-item">
                            <span>Quiz Questions:</span>
                            <span><?php echo $analysis['quiz_questions']; ?></span>
                        </div>
                        <div class="analysis-item">
                            <span>Last Modified:</span>
                            <span><?php echo $analysis['last_modified']; ?></span>
                        </div>
                        <div class="analysis-item">
                            <span>Content Length:</span>
                            <span><?php echo number_format($analysis['content_length']); ?> chars</span>
                        </div>
                    </div>
                    
                    <!-- Performance Metrics -->
                    <?php if (!empty($performance)): ?>
                    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #f3f4f6;">
                        <h4 style="margin: 0 0 12px 0; color: #374151;">📈 Performance Metrics</h4>
                        <div class="analysis-grid">
                            <div class="analysis-item">
                                <span>Completions:</span>
                                <span><?php echo $performance['completions'] ?? 0; ?></span>
                            </div>
                            <div class="analysis-item">
                                <span>Avg Score:</span>
                                <span><?php echo $performance['avg_quiz_score'] ? round($performance['avg_quiz_score']) . '%' : 'N/A'; ?></span>
                            </div>
                            <div class="analysis-item">
                                <span>Avg Time:</span>
                                <span><?php echo $performance['avg_time'] ? round($performance['avg_time']) . ' min' : 'N/A'; ?></span>
                            </div>
                            <div class="analysis-item">
                                <span>Quiz Failures:</span>
                                <span style="color: <?php echo ($performance['quiz_failures'] ?? 0) > 0 ? '#dc2626' : '#059669'; ?>">
                                    <?php echo $performance['quiz_failures'] ?? 0; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Components -->
                    <?php if (!empty($analysis['components'])): ?>
                    <div style="margin-top: 16px;">
                        <h4 style="margin: 0 0 8px 0; color: #374151;">🧩 Components Used</h4>
                        <div class="component-list">
                            <?php foreach ($analysis['components'] as $component): ?>
                                <span class="component-tag"><?php echo $component; ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="<?php echo path_with_base('/admin/lms-content-manager.php'); ?>?action=edit&module=<?php echo $module['slug']; ?>" 
                           class="btn-small" style="background: #059669; color: white;">
                            ✏️ Edit Content
                        </a>
                        <a href="/onboarding/trainee/module.php?slug=<?php echo $module['slug']; ?>" 
                           target="_blank" class="btn-small" style="background: #3b82f6; color: white;">
                            👁️ View Live
                        </a>
                        <a href="<?php echo path_with_base('/admin/quiz-manager.php'); ?>?module=<?php echo $module['slug']; ?>" 
                           class="btn-small" style="background: #f59e0b; color: white;">
                            ❓ Manage Quiz
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Content Management Tips -->
    <div style="background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 24px; margin-top: 24px;">
        <h2 style="color: #1f2937; margin-bottom: 20px;">💡 Content Management Best Practices</h2>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <div>
                <h4 style="color: #059669;">✅ Good Module Structure</h4>
                <ul style="color: #374151; font-size: 14px;">
                    <li>Clear learning objectives at the start</li>
                    <li>2-4 quiz questions for knowledge checks</li>
                    <li>Key takeaways section at the end</li>
                    <li>Proper HTML structure and validation</li>
                    <li>Consistent styling with class names</li>
                    <li>Interactive elements (quizzes, highlights)</li>
                </ul>
            </div>
            <div>
                <h4 style="color: #dc2626;">⚠️ Content Issues to Fix</h4>
                <ul style="color: #374151; font-size: 14px;">
                    <li>Missing quiz questions (low engagement)</li>
                    <li>Very short content (under 1KB)</li>
                    <li>No interactive components</li>
                    <li>Missing key-highlight boxes</li>
                    <li>Outdated product references</li>
                    <li>HTML validation errors</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
