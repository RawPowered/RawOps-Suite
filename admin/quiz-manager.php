<?php
$SEO = [
    'title' => 'Quiz Settings — Product Admin',
    'description' => 'Configure LMS quiz behavior.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect();

$pdo = db();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabled = isset($_POST['quiz_live_check_enabled']) ? '1' : '0';
    $stmt = $pdo->prepare('INSERT INTO lms_settings (setting_key, setting_value) VALUES ("quiz_live_check_enabled", ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$enabled]);
    $message = 'Settings updated.';
}

$stmt = $pdo->prepare('SELECT setting_value FROM lms_settings WHERE setting_key = ?');
$stmt->execute(['quiz_live_check_enabled']);
$enabled = $stmt->fetchColumn();
$enabled = $enabled === false ? '0' : $enabled;
?>

<section class="section">
    <div class="container">
        <div class="admin-header">
            <h1>Quiz Settings</h1>
            <div class="admin-nav">
                <a href="<?php echo path_with_base('/admin/'); ?>" class="btn secondary">← Admin Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="card" style="border-color:#86efac;background:#ecfdf5;color:#065f46;">
            <p><?php echo sanitize($message); ?></p>
        </div>
        <?php endif; ?>

        <div class="card" style="max-width:640px;">
            <form method="post">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="quiz_live_check_enabled" value="1" <?php echo $enabled === '1' ? 'checked' : ''; ?>>
                    Enable live-check of quiz answers (shows correctness immediately)
                </label>
                <p style="color:#6b7280;font-size:14px;margin-top:8px;">When disabled, trainees only see their final score on submission.</p>
                <button class="btn" type="submit" style="margin-top:12px;">Save Settings</button>
            </form>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

<?php
$SEO = [
    'title' => 'Quiz Manager — Admin LMS',
    'description' => 'Manage quiz questions, answers, and scoring for training modules.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();

// Handle quiz management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create_quiz') {
            $moduleId = intval($_POST['module_id']);
            $question = trim($_POST['question']);
            $options = $_POST['options'] ?? [];
            $correctAnswer = $_POST['correct_answer'];
            $explanation = trim($_POST['explanation']);
            
            // Create quiz question (stored in module file for now, but could be database)
            $quizData = [
                'question' => $question,
                'options' => $options,
                'correct' => $correctAnswer,
                'explanation' => $explanation
            ];
            
            flash_set('success', 'Quiz question created! Add to module content manually.');
        }
        
    } catch (Exception $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get all modules for quiz management
$modules = $pdo->query('SELECT * FROM training_modules ORDER BY sort_order')->fetchAll();

// Get quiz results for analysis
$stmt = $pdo->query('
    SELECT 
        tm.title as module_title,
        tqr.score,
        tqr.total_questions,
        tqr.passed,
        tqr.attempt_number,
        u.first_name,
        u.last_name,
        tqr.completed_at
    FROM training_quiz_results tqr
    JOIN training_modules tm ON tm.id = tqr.module_id
    JOIN users u ON u.id = tqr.user_id
    ORDER BY tqr.completed_at DESC
    LIMIT 50
');
$recentQuizResults = $stmt->fetchAll();
?>

<style>
.quiz-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.quiz-header {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}

.quiz-builder {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 24px;
}

.question-builder {
    background: #f9fafb;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.option-input {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.option-input input[type="text"] {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
}

.option-input input[type="radio"] {
    margin: 0;
}

.quiz-results-table {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    overflow: hidden;
}

.quiz-results-table table {
    width: 100%;
    border-collapse: collapse;
}

.quiz-results-table th,
.quiz-results-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.quiz-results-table th {
    background: #f9fafb;
    font-weight: 600;
}

.score-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.score-pass { background: #dcfce7; color: #14532d; }
.score-fail { background: #fee2e2; color: #991b1b; }

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
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
</style>

<div class="quiz-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">❓ Quiz Management System</h1>
        <p style="font-size: 18px; opacity: 0.9;">Create, edit, and analyze quiz questions and results</p>
    </div>
</div>

<div class="quiz-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Quiz Builder -->
    <div class="quiz-builder">
        <h2 style="color: #1f2937; margin-bottom: 20px;">🛠️ Quiz Question Builder</h2>
        
        <form method="post">
            <input type="hidden" name="action" value="create_quiz">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Module</label>
                    <select name="module_id" required>
                        <option value="">Select Module</option>
                        <?php foreach ($modules as $module): ?>
                            <option value="<?php echo $module['id']; ?>">
                                <?php echo htmlspecialchars($module['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Question Type</label>
                    <select id="question-type">
                        <option value="multiple_choice">Multiple Choice</option>
                        <option value="true_false">True/False</option>
                        <option value="scenario">Scenario-Based</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Question Text</label>
                <textarea name="question" rows="3" placeholder="Enter your quiz question..." required></textarea>
            </div>
            
            <div class="question-builder">
                <h4 style="margin: 0 0 16px 0; color: #374151;">Answer Options</h4>
                <div id="options-container">
                    <div class="option-input">
                        <input type="radio" name="correct_answer" value="a" required>
                        <label style="margin: 0; font-weight: normal;">A:</label>
                        <input type="text" name="options[a]" placeholder="Option A" required>
                    </div>
                    <div class="option-input">
                        <input type="radio" name="correct_answer" value="b" required>
                        <label style="margin: 0; font-weight: normal;">B:</label>
                        <input type="text" name="options[b]" placeholder="Option B" required>
                    </div>
                    <div class="option-input">
                        <input type="radio" name="correct_answer" value="c" required>
                        <label style="margin: 0; font-weight: normal;">C:</label>
                        <input type="text" name="options[c]" placeholder="Option C" required>
                    </div>
                    <div class="option-input">
                        <input type="radio" name="correct_answer" value="d">
                        <label style="margin: 0; font-weight: normal;">D:</label>
                        <input type="text" name="options[d]" placeholder="Option D (optional)">
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Explanation (shown after answer)</label>
                <textarea name="explanation" rows="2" placeholder="Explain why this answer is correct..."></textarea>
            </div>
            
            <button type="submit" class="btn">Create Quiz Question</button>
        </form>
    </div>
    
    <!-- Quiz Results Analysis -->
    <div class="quiz-results-table">
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
            <h2 style="margin: 0; color: #1f2937;">📊 Recent Quiz Results</h2>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Module</th>
                    <th>Score</th>
                    <th>Attempt</th>
                    <th>Result</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($recentQuizResults)): ?>
                    <?php foreach ($recentQuizResults as $result): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($result['module_title']); ?></td>
                            <td>
                                <?php echo $result['score']; ?>/<?php echo $result['total_questions']; ?>
                                <small style="color: #6b7280;">
                                    (<?php echo round(($result['score'] / $result['total_questions']) * 100); ?>%)
                                </small>
                            </td>
                            <td>#<?php echo $result['attempt_number']; ?></td>
                            <td>
                                <span class="score-badge <?php echo $result['passed'] ? 'score-pass' : 'score-fail'; ?>">
                                    <?php echo $result['passed'] ? 'PASSED' : 'FAILED'; ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y g:i A', strtotime($result['completed_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">
                            No quiz results found yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Dynamic option management
document.getElementById('question-type').addEventListener('change', function() {
    const container = document.getElementById('options-container');
    const type = this.value;
    
    if (type === 'true_false') {
        container.innerHTML = `
            <div class="option-input">
                <input type="radio" name="correct_answer" value="a" required>
                <label style="margin: 0; font-weight: normal;">A:</label>
                <input type="text" name="options[a]" value="True" required>
            </div>
            <div class="option-input">
                <input type="radio" name="correct_answer" value="b" required>
                <label style="margin: 0; font-weight: normal;">B:</label>
                <input type="text" name="options[b]" value="False" required>
            </div>
        `;
    } else {
        // Reset to multiple choice
        container.innerHTML = `
            <div class="option-input">
                <input type="radio" name="correct_answer" value="a" required>
                <label style="margin: 0; font-weight: normal;">A:</label>
                <input type="text" name="options[a]" placeholder="Option A" required>
            </div>
            <div class="option-input">
                <input type="radio" name="correct_answer" value="b" required>
                <label style="margin: 0; font-weight: normal;">B:</label>
                <input type="text" name="options[b]" placeholder="Option B" required>
            </div>
            <div class="option-input">
                <input type="radio" name="correct_answer" value="c" required>
                <label style="margin: 0; font-weight: normal;">C:</label>
                <input type="text" name="options[c]" placeholder="Option C" required>
            </div>
            <div class="option-input">
                <input type="radio" name="correct_answer" value="d">
                <label style="margin: 0; font-weight: normal;">D:</label>
                <input type="text" name="options[d]" placeholder="Option D (optional)">
            </div>
        `;
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
