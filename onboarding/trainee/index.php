<?php 
$SEO = [
    'title' => 'Sales Agent Training Portal — Product',
    'description' => 'Complete your comprehensive Product sales certification and unlock unlimited earning potential.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/config.php';

require_login_or_redirect(path_with_base('/login.php'));
$uid = current_user_id();
$pdo = db();

// Check if user should see welcome walkthrough (only for first-time users)
$showWelcome = false;
try {
    // Check if user has seen welcome walkthrough (stored in session) AND hasn't started training
    if (!isset($_SESSION['welcome_seen_' . $uid])) {
        // Check if user has started any training OR has viewed welcome before
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_progress WHERE user_id = ? AND status != "not_started"');
        $stmt->execute([$uid]);
        $hasStartedTraining = $stmt->fetchColumn() > 0;
        
        // Check if user has ever viewed the welcome walkthrough
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM welcome_walkthrough_tracking WHERE user_id = ?');
        $stmt->execute([$uid]);
        $hasViewedWelcome = $stmt->fetchColumn() > 0;
        
        // Only show welcome if they're truly new (no training started AND never viewed welcome)
        if (!$hasStartedTraining && !$hasViewedWelcome && !isset($_GET['skip_welcome'])) {
            $showWelcome = true;
        }
    }
} catch (Throwable $e) {
    // If there's an error, don't show welcome to avoid blocking access
    $showWelcome = false;
}

// If should show welcome, redirect to welcome page (must occur before any output)
if ($showWelcome) {
    redirect(path_with_base('/onboarding/trainee/welcome.php'));
}

// Mark welcome as seen and track completion
if (isset($_GET['from_welcome']) || isset($_GET['skip_welcome'])) {
    $_SESSION['welcome_seen_' . $uid] = true;
    
    // Update welcome walkthrough tracking
    try {
        if (isset($_GET['from_welcome'])) {
            // User completed the walkthrough
            $stmt = $pdo->prepare('UPDATE welcome_walkthrough_tracking SET completed_at = NOW() WHERE user_id = ? AND completed_at IS NULL');
            $stmt->execute([$uid]);
        } elseif (isset($_GET['skip_welcome'])) {
            // User skipped the walkthrough
            $stmt = $pdo->prepare('UPDATE welcome_walkthrough_tracking SET skipped = TRUE WHERE user_id = ? AND completed_at IS NULL');
            $stmt->execute([$uid]);
        }
    } catch (Throwable $e) {
        // Log error but don't block the user
        error_log("Welcome tracking error: " . $e->getMessage());
    }
}

// Get user info
try {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    
    // Get only NEW simplified modules (Sales Fundamentals course)
    $modules = $pdo->query('SELECT m.*, 
        COALESCE(p.status, "not_started") AS progress_status,
        p.started_at, p.completed_at,
        trr.id as retest_id, trr.reason as retest_reason, trr.required_at as retest_required_at,
        (
            SELECT ROUND((tqr.score / NULLIF(tqr.total_questions,0)) * 100)
            FROM training_quiz_results tqr
            WHERE tqr.user_id = ' . intval($uid) . ' AND tqr.module_id = m.id
            ORDER BY tqr.completed_at DESC, tqr.attempt_number DESC
            LIMIT 1
        ) AS latest_quiz_percent
        FROM training_modules m
        LEFT JOIN training_progress p ON p.module_id = m.id AND p.user_id = ' . intval($uid) . '
        LEFT JOIN training_retest_requirements trr ON trr.module_id = m.id AND trr.user_id = ' . intval($uid) . ' AND trr.is_active = 1
        WHERE m.required = 1 
          AND m.course_id = 1
          AND m.sort_order < 100
        ORDER BY m.sort_order ASC')->fetchAll();
    
    // Calculate progress
    $totalModules = count($modules);
    $completedModules = 0;
    $totalMinutes = 0;
    $completedMinutes = 0;
    
    foreach ($modules as $module) {
        $totalMinutes += $module['duration_minutes'];
        if ($module['progress_status'] === 'completed') {
            $completedModules++;
            $completedMinutes += $module['duration_minutes'];
        }
    }
    
    $progressPercent = $totalModules > 0 ? round(($completedModules / $totalModules) * 100) : 0;
    $hoursRemaining = round(($totalMinutes - $completedMinutes) / 60, 1);
    
    $dbOk = true;
} catch (Throwable $e) {
    $modules = [];
    $dbOk = false;
    $progressPercent = 0;
    $hoursRemaining = 4;
}

// Check if training is complete
$isComplete = is_training_complete($uid);
require_once __DIR__ . '/../../required/header.php';
?>

<section class="hero" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); padding: 40px 0;">
    <div class="container">
        <h1 style="color: #fff; margin-bottom: 8px; font-size: 32px;">Welcome back, <?php echo sanitize($user['first_name'] ?? 'Agent'); ?>! 🎯</h1>
        <p style="color: rgba(255,255,255,0.95); font-size: 18px; margin-bottom: 8px;">
            Your journey to becoming a certified Product Sales Agent starts here.
        </p>
        
        <?php
        // Show course enrollment info
        try {
            $stmt = $pdo->prepare("
                SELECT c.name, ce.completion_percent, ce.status
                FROM course_enrollments ce
                JOIN courses c ON c.id = ce.course_id
                WHERE ce.user_id = ? AND c.active = 1
                ORDER BY c.sort_order
                LIMIT 1
            ");
            $stmt->execute([$uid]);
            $enrollment = $stmt->fetch();
            if ($enrollment):
        ?>
        <div style="background: rgba(255,255,255,0.15); backdrop-filter: blur(10px); padding: 12px 20px; border-radius: 8px; display: inline-block; margin-top: 8px;">
            <span style="color: white; font-size: 14px;">
                📚 Course: <strong><?php echo htmlspecialchars($enrollment['name']); ?></strong> • 
                Status: <strong><?php echo ucfirst($enrollment['status']); ?></strong> • 
                <?php echo round($enrollment['completion_percent']); ?>% Complete
            </span>
        </div>
        <?php 
            endif;
        } catch (Exception $e) {}
        ?>
        </p>
        
        <!-- Progress Overview -->
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; margin-top: 32px;">
            <div class="card" style="background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);">
                <div style="position: relative; width: 120px; height: 120px; margin: 0 auto;">
                    <svg class="progress-ring" viewBox="0 0 120 120">
                        <defs>
                            <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:#fbbf24;stop-opacity:1" />
                                <stop offset="100%" style="stop-color:#f59e0b;stop-opacity:1" />
                            </linearGradient>
                        </defs>
                        <circle class="progress-ring-circle" cx="60" cy="60" r="54" />
                        <circle class="progress-ring-progress" cx="60" cy="60" r="54"
                            style="stroke-dasharray: 339.292; stroke-dashoffset: <?php echo 339.292 * (1 - $progressPercent / 100); ?>;" />
                    </svg>
                    <div class="progress-percent"><?php echo $progressPercent; ?>%</div>
                </div>
                <h3 style="text-align: center; margin-top: 16px;">Overall Progress</h3>
                <p style="text-align: center; color: #6b7280;">
                    <?php echo $completedModules; ?> of <?php echo $totalModules; ?> modules completed
                </p>
            </div>
            
            <div class="card" style="background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);">
                <div style="text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 8px;">🏋️</div>
                    <h3 style="margin: 12px 0 8px 0;">Sales Workouts</h3>
                    <p style="color: #6b7280; margin: 0 0 16px 0;">
                        Reinforce your knowledge
                    </p>
                    <a href="<?php echo path_with_base('/onboarding/trainee/sales-workouts.php'); ?>" class="btn" style="background: #2563eb; color: white; text-decoration: none; display: inline-block;">
                        Practice Now
                    </a>
                </div>
            </div>
            
            <div class="card" style="background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);">
                <div style="text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 8px;">⏱️</div>
                    <h3>Time Investment</h3>
                    <p style="font-size: 32px; font-weight: 800; color: #0b2a4a; margin: 8px 0;">
                        <?php echo $hoursRemaining; ?> hrs
                    </p>
                    <p style="color: #6b7280;">remaining to complete</p>
                </div>
            </div>
            
            <div class="card" style="background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);">
                <div style="text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 8px;">🏆</div>
                    <h3>Your Status</h3>
                    <p style="font-size: 24px; font-weight: 800; margin: 8px 0;">
                        <?php if ($isComplete): ?>
                            <span style="color: #059669;">Certified Agent</span>
                        <?php elseif ($completedModules > 0): ?>
                            <span style="color: #f59e0b;">Trainee in Progress</span>
                        <?php else: ?>
                            <span style="color: #6b7280;">New Trainee</span>
                        <?php endif; ?>
                    </p>
                    <p style="color: #6b7280;">
                        <?php if ($isComplete): ?>
                            Ready to start selling!
                        <?php else: ?>
                            Keep going, you're doing great!
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if (!$dbOk): ?>
            <div class="card" style="border-color: #fca5a5; background: #fef2f2;">
                <p style="color: #991b1b;">⚠️ Training modules are not available yet. Please import the database schema and refresh.</p>
            </div>
        <?php endif; ?>
        
        <?php if (!$isComplete): ?>
        <!-- Earnings Preview -->
        <div class="earnings-preview">
            <h3>🚀 Your Earning Potential as a Certified Agent</h3>
            <div class="amount">$5,000 - $15,000+</div>
            <p style="font-size: 18px; margin-bottom: 0;">Monthly Commission Potential</p>
            <p style="font-size: 14px; opacity: 0.8; margin-top: 8px;">
                Top agents earn $180,000+ annually with our tiered commission structure
            </p>
        </div>
        
        <!-- Milestones -->
        <div style="text-align: center; margin: 32px 0;">
            <h2>Your Achievement Milestones</h2>
            <div style="margin-top: 16px;">
                <div class="milestone-badge <?php echo $completedModules >= 1 ? 'active' : ''; ?>">
                    <span class="icon">🌱</span>
                    <span>First Steps (1 module)</span>
                </div>
                <div class="milestone-badge <?php echo $completedModules >= 3 ? 'active' : ''; ?>">
                    <span class="icon">📚</span>
                    <span>Knowledge Seeker (3 modules)</span>
                </div>
                <div class="milestone-badge <?php echo $completedModules >= 5 ? 'active' : ''; ?>">
                    <span class="icon">🎓</span>
                    <span>Certified Expert (All modules)</span>
                </div>
                <div class="milestone-badge <?php echo $isComplete ? 'active' : ''; ?>">
                    <span class="icon">💎</span>
                    <span>Sales Agent Elite</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Training Modules -->
        <div style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); padding: 20px; border-radius: 12px; margin-top: 48px; margin-bottom: 24px; border: 2px solid #0369a1;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 style="margin: 0 0 8px 0; color: #0c4a6e;">
                        <?php if ($isComplete): ?>
                            ✅ Training Complete - Review Your Modules
                        <?php else: ?>
                            📚 Sales Fundamentals Course
                        <?php endif; ?>
                    </h2>
                    <p style="margin: 0; color: #075985; font-size: 14px;">
                        <strong>Simplified Training:</strong> 5 focused modules • 2 hours total • Essential sales skills only
                    </p>
                </div>
                <a href="welcome.php?tour=1" class="btn secondary" style="font-size: 14px; padding: 8px 16px;">
                    🎯 Take Tour
                </a>
            </div>
        </div>
        
        <div class="grid" style="grid-template-columns: 1fr; gap: 0;">
            <?php 
            $moduleIcons = [
                'intro-product' => '🌿',
                'three-stage-filter' => '🔬',
                'product-mastery' => '💎',
                'objection-essentials' => '💬',
                'final-assessment' => '🎓'
            ];
            
            $moduleDescriptions = [
                'intro-product' => 'Learn Product history, traditional uses, market landscape, and why Product\'s catalog-ready quality information eliminates the need for product categories classifications. Understand our dual competitive advantage: superior quality + competitive pricing.',
                'three-stage-filter' => 'Master our 3-stage product filtration process that makes us the cleanest, most tasteless, and most powerful Product on the market. Learn how to use this competitive advantage in your sales conversations.',
                'product-mastery' => 'Master Blue 10, Product B, and Black 80 specifications, use cases, and profit potential. Learn to match the right product to the right customer based on their operation size and experience level.',
                'objection-essentials' => 'Handle the 5 most common sales objections with proven rebuttals. Learn the 4-step framework: Acknowledge, Clarify, Educate, Close. Focus on reframing price objections as value conversations.',
                'final-assessment' => 'Comprehensive 8-question certification exam covering all training modules. Pass with 80% or higher to become a certified Product Sales Agent and unlock the CRM portal.'
            ];
            
            foreach ($modules as $index => $module): 
                $icon = $moduleIcons[$module['slug']] ?? '📖';
                $description = $moduleDescriptions[$module['slug']] ?? $module['content'];
                $isLocked = $index > 0 && $modules[$index - 1]['progress_status'] !== 'completed' && !$isComplete;
                $hasRetest = !empty($module['retest_id']);
            ?>
            <div class="module-card <?php echo $module['progress_status']; ?> <?php echo $isLocked ? 'locked' : ''; ?>" 
                 style="<?php echo $isLocked ? 'opacity: 0.6; pointer-events: none;' : ''; ?>">
                
                <?php if ($module['progress_status'] === 'completed'): ?>
                    <span class="module-status completed">Completed</span>
                <?php elseif ($module['progress_status'] === 'in_progress'): ?>
                    <span class="module-status in-progress">In Progress</span>
                <?php elseif ($isLocked): ?>
                    <span class="module-status locked">Locked</span>
                <?php endif; ?>
                
                <div style="display: flex; align-items: flex-start; gap: 20px;">
                    <div style="font-size: 48px; line-height: 1;"><?php echo $icon; ?></div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px; color: #0b2a4a;">
                            Module <?php echo $index + 1; ?>: <?php echo sanitize($module['title']); ?>
                        </h3>
                        <p style="color: #6b7280; margin: 0 0 12px;">
                            <?php echo sanitize($description); ?>
                        </p>
                        <div style="display: flex; align-items: center; gap: 24px; margin-top: 16px;">
                            <span style="color: #9ca3af; font-size: 14px;">
                                ⏱️ <?php echo intval($module['duration_minutes']); ?> minutes
                            </span>
                            <?php if ($module['required']): ?>
                                <span style="color: #9ca3af; font-size: 14px;">
                                    ⭐ Required
                                </span>
                            <?php endif; ?>
                            <?php if ($module['completed_at']): ?>
                                <span style="color: #059669; font-size: 14px;">
                                    ✅ Completed <?php echo date('M j', strtotime($module['completed_at'])); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($hasRetest): ?>
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px; margin-top: 12px;">
                            <p style="color: #dc2626; font-weight: 600; margin: 0 0 4px;">🔄 Retest Required</p>
                            <p style="color: #7f1d1d; font-size: 14px; margin: 0 0 4px;">
                                <?php echo htmlspecialchars($module['retest_reason']); ?>
                            </p>
                            <small style="color: #991b1b;">
                                Required on: <?php echo date('M j, Y g:i A', strtotime($module['retest_required_at'])); ?>
                            </small>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($module['latest_quiz_percent'])): ?>
                        <div style="margin-top: 8px;">
                            <?php 
                            $qp = intval($module['latest_quiz_percent']);
                            $color = $qp >= 80 ? '#059669' : '#dc2626';
                            ?>
                            <span style="font-size: 14px; color: <?php echo $color; ?>; font-weight: 700;">Latest Quiz: <?php echo $qp; ?>%</span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!$isLocked): ?>
                            <a href="module.php?slug=<?php echo urlencode($module['slug']); ?>" 
                               class="btn <?php echo $module['progress_status'] === 'completed' ? 'secondary' : ''; ?>" 
                               style="margin-top: 16px; display: inline-block;">
                                <?php if ($module['progress_status'] === 'completed'): ?>
                                    Review Module
                                <?php elseif ($module['progress_status'] === 'in_progress'): ?>
                                    Continue Learning
                                <?php else: ?>
                                    Start Module
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <button class="btn" disabled style="margin-top: 16px; opacity: 0.5; cursor: not-allowed;">
                                🔒 Complete Previous Module First
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($isComplete): ?>
        <div class="card" style="background: linear-gradient(135deg, #059669, #10b981); color: white; text-align: center; margin-top: 32px; padding: 48px;">
            <h2 style="color: white; margin-bottom: 16px;">🎉 Congratulations! You're a Certified Sales Agent!</h2>
            <p style="font-size: 18px; margin-bottom: 24px;">
                You've completed all training modules and are ready to start selling. Access your Sales Portal to begin managing customers and earning commissions.
            </p>
            <a href="<?php echo outbound_path('/onboarding/sales-agent/'); ?>" class="btn" style="background: white; color: #059669; font-size: 18px; padding: 16px 32px;">
                Enter Sales Agent Portal →
            </a>
        </div>
        <?php elseif ($completedModules === $totalModules - 1 && $modules[count($modules) - 1]['progress_status'] !== 'completed'): ?>
        <div class="card" style="background: linear-gradient(135deg, #f59e0b, #fbbf24); color: white; text-align: center; margin-top: 32px; padding: 48px;">
            <h2 style="color: white; margin-bottom: 16px;">🏁 Almost There! One Module to Go!</h2>
            <p style="font-size: 18px; margin-bottom: 24px;">
                Complete the final assessment to earn your certification and unlock the Sales Agent Portal with unlimited earning potential!
            </p>
            <a href="module.php?slug=final-assessment" class="btn" style="background: white; color: #f59e0b; font-size: 18px; padding: 16px 32px;">
                Take Final Assessment →
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Quick Stats -->
        <div class="training-stats">
            <div class="stat-card">
                <div class="value"><?php echo $completedMinutes; ?></div>
                <div class="label">Minutes Completed</div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo $totalMinutes - $completedMinutes; ?></div>
                <div class="label">Minutes Remaining</div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo $progressPercent; ?>%</div>
                <div class="label">Overall Progress</div>
            </div>
            <div class="stat-card">
                <div class="value"><?php echo $completedModules; ?>/<?php echo $totalModules; ?></div>
                <div class="label">Modules Done</div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>