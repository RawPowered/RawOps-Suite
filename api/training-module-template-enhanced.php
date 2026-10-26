<?php
/**
 * Enhanced Training Module Template with Time Tracking
 * Add this code to the end of each training module PHP file
 */
?>

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
            // Calculate quiz score if this is a quiz submission
            const radioInputs = form.querySelectorAll('input[type="radio"]:checked');
            const totalQuestions = new Set(Array.from(radioInputs).map(input => input.name)).size;
            
            if (totalQuestions > 0) {
                // This is a quiz submission
                const quizAttempts = parseInt(localStorage.getItem('quiz_attempts_<?php echo $module['id']; ?>') || '0') + 1;
                localStorage.setItem('quiz_attempts_<?php echo $module['id']; ?>', quizAttempts.toString());
                
                window.TrainingTracker.onQuizSubmit(null, quizAttempts); // Score will be calculated server-side
            }
        });
    });
    
    // Track module completion
    const completeButtons = document.querySelectorAll('button[type="submit"], .btn[href*="complete"]');
    completeButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Get final quiz score if available
            const finalScore = getFinalQuizScore();
            window.TrainingTracker.onModuleComplete(finalScore);
        });
    });
    
    // Add real-time stats display for admins
    <?php if (in_array('admin', user_roles($uid))): ?>
    addAdminStatsDisplay();
    <?php endif; ?>
});

function getFinalQuizScore() {
    // Try to get quiz score from various sources
    const scoreElement = document.querySelector('[data-quiz-score]');
    if (scoreElement) {
        return parseFloat(scoreElement.dataset.quizScore);
    }
    
    // Calculate from checked answers if answer key is available
    const checkedInputs = document.querySelectorAll('input[type="radio"]:checked');
    const totalQuestions = new Set(Array.from(checkedInputs).map(input => input.name)).size;
    
    if (totalQuestions > 0 && window.quizAnswerKey) {
        let correct = 0;
        checkedInputs.forEach(input => {
            if (window.quizAnswerKey[input.name] === input.value) {
                correct++;
            }
        });
        return Math.round((correct / totalQuestions) * 100);
    }
    
    return null;
}

<?php if (in_array('admin', user_roles($uid))): ?>
function addAdminStatsDisplay() {
    // Add real-time stats overlay for admins
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
    
    // Update stats every 5 seconds
    setInterval(() => {
        if (window.TrainingTracker.isActive()) {
            const stats = window.TrainingTracker.getCurrentStats();
            statsDiv.innerHTML = `
                <strong>ADMIN: Live Training Stats</strong><br>
                Total Time: ${Math.floor(stats.totalTime / 60)}:${String(stats.totalTime % 60).padStart(2, '0')}<br>
                Active Time: ${Math.floor(stats.activeTime / 60)}:${String(stats.activeTime % 60).padStart(2, '0')}<br>
                Idle Time: ${Math.floor(stats.idleTime / 60)}:${String(stats.idleTime % 60).padStart(2, '0')}<br>
                Page Views: ${stats.pageViews}<br>
                Scroll: ${Math.round(stats.scrollPercentage)}%<br>
                Engagement: ${Math.round(stats.engagementScore)}%<br>
                Status: ${stats.isIdle ? '🟡 IDLE' : '🟢 ACTIVE'}
            `;
        }
    }, 5000);
}
<?php endif; ?>

// Add visual progress indicators
function addProgressIndicators() {
    const progressBar = document.createElement('div');
    progressBar.style.cssText = `
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: #e5e7eb;
        z-index: 1000;
    `;
    
    const progressFill = document.createElement('div');
    progressFill.style.cssText = `
        height: 100%;
        background: linear-gradient(90deg, #059669, #10b981);
        width: 0%;
        transition: width 0.3s ease;
    `;
    
    progressBar.appendChild(progressFill);
    document.body.appendChild(progressBar);
    
    // Update progress based on scroll
    window.addEventListener('scroll', () => {
        const scrollTop = window.pageYOffset;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        progressFill.style.width = Math.min(100, Math.max(0, scrollPercent)) + '%';
    });
}

// Initialize progress indicators
addProgressIndicators();
</script>

<!-- Admin Quick Actions (only visible to admins) -->
<?php if (in_array('admin', user_roles($uid))): ?>
<div style="position: fixed; bottom: 20px; left: 20px; z-index: 9998;">
    <div style="background: rgba(5, 150, 105, 0.95); color: white; padding: 12px; border-radius: 8px; font-size: 12px;">
        <strong>ADMIN MODE</strong><br>
        <a href="/admin/training-analytics.php" style="color: white; text-decoration: underline;">
            📊 View Analytics
        </a><br>
        <a href="/admin/trainee-detail.php?id=<?php echo $uid; ?>" style="color: white; text-decoration: underline;">
            👤 User Details
        </a>
    </div>
</div>
<?php endif; ?>

<style>
/* Enhanced visual feedback for training progress */
.quiz-question {
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
    padding-left: 16px;
}

.quiz-question:hover {
    border-left-color: #10b981;
    background: #f0fdf4;
}

.quiz-question.answered {
    border-left-color: #059669;
    background: #f0fdf4;
}

/* Idle warning styles */
.idle-warning {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fef2f2;
    border: 2px solid #dc2626;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    z-index: 10000;
    text-align: center;
    max-width: 400px;
}

/* Engagement indicators */
.low-engagement-hint {
    position: fixed;
    top: 50px;
    right: 50px;
    background: #fffbeb;
    border: 1px solid #f59e0b;
    padding: 12px;
    border-radius: 8px;
    font-size: 14px;
    color: #92400e;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}
</style>
