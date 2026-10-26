<?php
$SEO = ['title' => 'Edit Quiz Question', 'description' => 'Edit training quiz question', 'type' => 'website'];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$quizId = intval($_GET['id'] ?? 0);
$returnTo = $_GET['return'] ?? 'lms';
$productId = intval($_GET['product_id'] ?? 0);

// Handle save
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quiz'])) {
    try {
        // Validate required fields
        if (empty($_POST['module_id'])) {
            throw new Exception('Module is required');
        }
        if (empty($_POST['question'])) {
            throw new Exception('Question text is required');
        }
        if (empty($_POST['correct_answer'])) {
            throw new Exception('You must select a correct answer');
        }
        
        $moduleId = intval($_POST['module_id']);
        $question = trim($_POST['question']);
        $options = $_POST['options'] ?? [];
        $correctAnswer = $_POST['correct_answer'];
        $explanation = trim($_POST['explanation'] ?? '');
        $linkedProductId = !empty($_POST['product_id']) ? intval($_POST['product_id']) : null;
        $quizPosition = $_POST['quiz_position'] ?? 'mid';
        $rotationEnabled = isset($_POST['rotation_enabled']) ? 1 : 0;
        $questionType = $_POST['question_type'] ?? 'multiple_choice';
        
        $cleanedOptions = [];
        foreach ($options as $key => $value) {
            if (!empty(trim($value))) $cleanedOptions[$key] = trim($value);
        }
        
        // Validate at least 2 options
        if (count($cleanedOptions) < 2) {
            throw new Exception('You must provide at least 2 answer options');
        }
        
        // Log the save attempt
        error_log("Quiz Save Attempt - Quiz ID: $quizId, Module: $moduleId, Product: " . ($linkedProductId ?? 'none') . ", User: " . current_user_id());
        
        if ($quizId > 0) {
            // Update existing quiz
            $stmt = $pdo->prepare('UPDATE training_quiz_questions SET module_id=?, product_id=?, quiz_position=?, question_text=?, question_type=?, options=?, correct_answer=?, explanation=?, rotation_enabled=?, updated_at=NOW() WHERE id=?');
            $result = $stmt->execute([$moduleId, $linkedProductId, $quizPosition, $question, $questionType, json_encode($cleanedOptions), $correctAnswer, $explanation, $rotationEnabled, $quizId]);
            
            if (!$result) {
                throw new Exception('Failed to update quiz question');
            }
            
            $success = 'Quiz question updated successfully!';
            error_log("Quiz Updated Successfully - ID: $quizId");
        } else {
            // Create new quiz
            $stmt = $pdo->prepare('INSERT INTO training_quiz_questions (module_id, product_id, quiz_position, question_text, question_type, options, correct_answer, explanation, rotation_enabled, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())');
            $result = $stmt->execute([$moduleId, $linkedProductId, $quizPosition, $question, $questionType, json_encode($cleanedOptions), $correctAnswer, $explanation, $rotationEnabled, current_user_id()]);
            
            if (!$result) {
                throw new Exception('Failed to create quiz question');
            }
            
            $newQuizId = intval($pdo->lastInsertId());
            $success = 'Quiz question created successfully! (ID: ' . $newQuizId . ')';
            error_log("Quiz Created Successfully - New ID: $newQuizId, Product ID: " . ($linkedProductId ?? 'none'));
        }
        
        // Show success message
        flash_set('success', $quizId ? 'Quiz question updated!' : 'Quiz question created!');
        
        // Redirect back
        if ($returnTo === 'product' && $productId) {
            // Use fragment identifier to switch to quiz tab
            $redirectUrl = path_with_base('/admin/edit-product.php?id='.$productId) . '#tab-quizzes';
            error_log("Redirecting to: $redirectUrl");
            redirect($redirectUrl);
        } else {
            redirect(path_with_base('/admin/lms-manager.php?section=quizzes'));
        }
        
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Quiz Save Database Error: " . $e->getMessage());
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Quiz Save Error: " . $e->getMessage());
    }
}

// Load quiz
$quiz = null;
$options = ['a' => '', 'b' => '', 'c' => '', 'd' => ''];

if ($quizId > 0) {
    $stmt = $pdo->prepare("SELECT tqq.*, tm.title as module_title, p.name as product_name FROM training_quiz_questions tqq JOIN training_modules tm ON tm.id=tqq.module_id LEFT JOIN products p ON p.id=tqq.product_id WHERE tqq.id=?");
    $stmt->execute([$quizId]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quiz) {
        flash_set('error', 'Quiz question not found');
        redirect(path_with_base('/admin/lms-manager.php?section=quizzes'));
    }
    
    // Parse options JSON and merge with defaults
    if (!empty($quiz['options'])) {
        $parsedOptions = json_decode($quiz['options'], true);
        if (is_array($parsedOptions)) {
            $options = array_merge($options, $parsedOptions);
        }
    }
}

$modules = $pdo->query("SELECT * FROM training_modules WHERE sort_order < 100 ORDER BY sort_order")->fetchAll();
// Show ALL active products (not just training-enabled) so any product can be linked
$products = $pdo->query("SELECT id, sku, name, use_in_training FROM products WHERE active=1 ORDER BY name")->fetchAll();

// DEBUG: Log available products
error_log("Quiz Editor - Available products for linking: " . count($products));
if ($productId) {
    $foundProduct = false;
    foreach ($products as $p) {
        if ($p['id'] == $productId) {
            $foundProduct = true;
            error_log("Quiz Editor - Product ID $productId FOUND in dropdown: " . $p['name'] . " (use_in_training={$p['use_in_training']})");
            break;
        }
    }
    if (!$foundProduct) {
        error_log("Quiz Editor - WARNING: Product ID $productId NOT FOUND in dropdown list!");
    }
}
?>

<style>
.edit-container{max-width:900px;margin:0 auto;padding:20px}
.edit-header{background:linear-gradient(135deg,#7c3aed,#a855f7);color:white;padding:32px;border-radius:12px;margin-bottom:24px}
.form-section{background:white;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.05);margin-bottom:24px}
.form-group{margin-bottom:20px}
.form-group label{display:block;font-weight:600;margin-bottom:8px;color:#374151}
.form-group input[type="text"],.form-group select,.form-group textarea{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.option-row{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.option-row input[type="text"]{flex:1}
</style>

<div class="edit-container">
    <div class="edit-header">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
                <h1 style="margin:0 0 8px 0"><?php echo $quizId?'✏️ Edit':'➕ Create';?> Quiz Question</h1>
                <?php if($quiz):?><p style="margin:0;opacity:.9"><?php echo htmlspecialchars($quiz['module_title']);?> • <?php echo ucfirst($quiz['quiz_position']);?></p><?php endif;?>
            </div>
            <a href="<?php echo $returnTo==='product'&&$productId?path_with_base('/admin/edit-product.php?id='.$productId):path_with_base('/admin/lms-manager.php?section=quizzes');?>" class="btn secondary">← Back</a>
        </div>
    </div>
    
    <?php if(!empty($success)):?>
    <div style="background:#dcfce7;color:#14532d;padding:16px;border-radius:8px;margin-bottom:24px;border-left:4px solid #16a34a">
        ✅ <?php echo htmlspecialchars($success);?>
    </div>
    <?php endif;?>
    
    <?php if(!empty($error)):?>
    <div style="background:#fee2e2;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:24px;border-left:4px solid #dc2626">
        ❌ <strong>Error:</strong> <?php echo htmlspecialchars($error);?>
    </div>
    <?php endif;?>
    
    <form method="post" class="form-section">
        <input type="hidden" name="save_quiz" value="1">
        
        <div class="form-row" style="margin-bottom:24px">
            <div class="form-group">
                <label>Target Module *</label>
                <select name="module_id" required>
                    <option value="">Select Module</option>
                    <?php foreach($modules as $m):?>
                    <option value="<?php echo $m['id'];?>" <?php echo $quiz&&$quiz['module_id']==$m['id']?'selected':'';?>><?php echo htmlspecialchars($m['title']);?></option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group">
                <label>Quiz Position</label>
                <select name="quiz_position">
                    <option value="intro" <?php echo $quiz&&$quiz['quiz_position']==='intro'?'selected':'';?>>Intro</option>
                    <option value="early" <?php echo $quiz&&$quiz['quiz_position']==='early'?'selected':'';?>>Early</option>
                    <option value="mid" <?php echo !$quiz||$quiz['quiz_position']==='mid'?'selected':'';?>>Mid</option>
                    <option value="late" <?php echo $quiz&&$quiz['quiz_position']==='late'?'selected':'';?>>Late</option>
                    <option value="final" <?php echo $quiz&&$quiz['quiz_position']==='final'?'selected':'';?>>Final</option>
                </select>
            </div>
        </div>
        
        <div class="form-row" style="margin-bottom:24px">
            <div class="form-group">
                <label>Link to Product (Optional) 
                    <?php if($productId):?>
                    <span style="color:#059669;font-weight:bold;font-size:12px;">← Auto-selected from URL</span>
                    <?php endif;?>
                </label>
                <select name="product_id" id="product_id" style="<?php echo $productId ? 'border: 2px solid #059669; background: #dcfce7;' : ''; ?>">
                    <option value="">No product link</option>
                    <?php foreach($products as $p):?>
                    <option value="<?php echo $p['id'];?>" 
                            <?php echo ($quiz&&$quiz['product_id']==$p['id']) || (!$quiz&&$productId==$p['id'])?'selected':'';?>>
                        <?php echo htmlspecialchars($p['name']);?> (<?php echo $p['sku'];?>)
                        <?php if (!$p['use_in_training']):?> [Training Disabled]<?php endif;?>
                    </option>
                    <?php endforeach;?>
                </select>
                <?php if($productId):?>
                <div style="font-size:13px;color:#059669;margin-top:6px;padding:8px;background:#dcfce7;border-radius:4px;font-weight:600;">
                    ✓ This quiz will be linked to product ID <?php echo $productId; ?>
                </div>
                <script>
                // Lock the product selection from URL
                document.addEventListener('DOMContentLoaded', function() {
                    const productSelect = document.getElementById('product_id');
                    if (productSelect && productSelect.value === '<?php echo $productId; ?>') {
                        console.log('Product pre-selected from URL: <?php echo $productId; ?>');
                        console.log('Selected option:', productSelect.options[productSelect.selectedIndex].text);
                        
                        // Highlight it so user knows it's set
                        productSelect.style.background = '#dcfce7';
                    }
                });
                </script>
                <?php endif;?>
            </div>
            <div class="form-group">
                <label>Question Type</label>
                <select name="question_type" id="question_type" onchange="handleQuestionTypeChange()">
                    <option value="multiple_choice" <?php echo !$quiz||$quiz['question_type']==='multiple_choice'?'selected':'';?>>Multiple Choice</option>
                    <option value="true_false" <?php echo $quiz&&$quiz['question_type']==='true_false'?'selected':'';?>>True/False</option>
                    <option value="scenario" <?php echo $quiz&&$quiz['question_type']==='scenario'?'selected':'';?>>Scenario</option>
                </select>
            </div>
        </div>
        
        <div class="form-group" style="margin-bottom:24px">
            <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="rotation_enabled" <?php echo $quiz&&$quiz['rotation_enabled']?'checked':'';?>>
                <span>Enable Question Rotation</span>
            </label>
        </div>
        
        <div class="form-group" style="margin-bottom:24px">
            <label>Question Text *</label>
            <textarea name="question" rows="3" required><?php echo $quiz?htmlspecialchars($quiz['question_text']):'';?></textarea>
        </div>
        
        <div style="background:#f9fafb;padding:20px;border-radius:8px;margin-bottom:24px" id="answer-options-container">
            <h4 style="margin:0 0 16px 0">Answer Options</h4>
            <div class="option-row" id="option-a-row">
                <input type="radio" name="correct_answer" value="a" <?php echo $quiz&&$quiz['correct_answer']==='a'?'checked':'';?> required>
                <label style="margin:0;font-weight:600;min-width:20px">A:</label>
                <input type="text" name="options[a]" id="option-a" value="<?php echo htmlspecialchars($options['a']);?>" placeholder="Option A" required>
            </div>
            <div class="option-row" id="option-b-row">
                <input type="radio" name="correct_answer" value="b" <?php echo $quiz&&$quiz['correct_answer']==='b'?'checked':'';?> required>
                <label style="margin:0;font-weight:600;min-width:20px">B:</label>
                <input type="text" name="options[b]" id="option-b" value="<?php echo htmlspecialchars($options['b']);?>" placeholder="Option B" required>
            </div>
            <div class="option-row" id="option-c-row">
                <input type="radio" name="correct_answer" value="c" <?php echo $quiz&&$quiz['correct_answer']==='c'?'checked':'';?> required>
                <label style="margin:0;font-weight:600;min-width:20px">C:</label>
                <input type="text" name="options[c]" id="option-c" value="<?php echo htmlspecialchars($options['c']);?>" placeholder="Option C" required>
            </div>
            <div class="option-row" id="option-d-row">
                <input type="radio" name="correct_answer" value="d" <?php echo $quiz&&$quiz['correct_answer']==='d'?'checked':'';?>>
                <label style="margin:0;font-weight:600;min-width:20px">D:</label>
                <input type="text" name="options[d]" id="option-d" value="<?php echo htmlspecialchars($options['d']);?>" placeholder="Option D (optional)">
            </div>
        </div>
        
        <div class="form-group" style="margin-bottom:24px">
            <label>Explanation</label>
            <textarea name="explanation" rows="3"><?php echo $quiz?htmlspecialchars($quiz['explanation']??''):'';?></textarea>
        </div>
        
        <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:24px;border-top:2px solid #e5e7eb">
            <a href="<?php echo $returnTo==='product'&&$productId?path_with_base('/admin/edit-product.php?id='.$productId):path_with_base('/admin/lms-manager.php?section=quizzes');?>" class="btn secondary">Cancel</a>
            <button type="submit" class="btn" style="background:#7c3aed;color:white"><?php echo $quizId?'💾 Update':'➕ Create';?> Quiz</button>
        </div>
    </form>
</div>

<script>
// Handle question type changes
function handleQuestionTypeChange() {
    const questionType = document.getElementById('question_type').value;
    const optionARow = document.getElementById('option-a-row');
    const optionBRow = document.getElementById('option-b-row');
    const optionCRow = document.getElementById('option-c-row');
    const optionDRow = document.getElementById('option-d-row');
    
    const optionA = document.getElementById('option-a');
    const optionB = document.getElementById('option-b');
    const optionC = document.getElementById('option-c');
    const optionD = document.getElementById('option-d');
    
    console.log('Question type changed to:', questionType);
    
    if (questionType === 'true_false') {
        // True/False: Only show A and B, pre-fill with True/False
        optionARow.style.display = 'flex';
        optionBRow.style.display = 'flex';
        optionCRow.style.display = 'none';
        optionDRow.style.display = 'none';
        
        // Pre-fill True/False if empty
        if (!optionA.value || optionA.value === 'Option A') {
            optionA.value = 'True';
        }
        if (!optionB.value || optionB.value === 'Option B') {
            optionB.value = 'False';
        }
        
        // Make A and B required, C and D optional
        optionA.required = true;
        optionB.required = true;
        optionC.required = false;
        optionD.required = false;
        
        // Clear C and D values to avoid validation issues
        if (optionC) optionC.value = '';
        if (optionD) optionD.value = '';
        
    } else if (questionType === 'multiple_choice') {
        // Multiple Choice: Show all options
        optionARow.style.display = 'flex';
        optionBRow.style.display = 'flex';
        optionCRow.style.display = 'flex';
        optionDRow.style.display = 'flex';
        
        // Clear True/False if present
        if (optionA.value === 'True') optionA.value = '';
        if (optionB.value === 'False') optionB.value = '';
        
        // A, B, C required; D optional
        optionA.required = true;
        optionB.required = true;
        optionC.required = true;
        optionD.required = false;
        
    } else if (questionType === 'scenario') {
        // Scenario: Show all options (similar to multiple choice)
        optionARow.style.display = 'flex';
        optionBRow.style.display = 'flex';
        optionCRow.style.display = 'flex';
        optionDRow.style.display = 'flex';
        
        // A, B, C required; D optional
        optionA.required = true;
        optionB.required = true;
        optionC.required = true;
        optionD.required = false;
    }
}

// Form submission handler
document.addEventListener('DOMContentLoaded', function() {
    handleQuestionTypeChange();
    console.log('Quiz question editor initialized');
    
    // Add form submission debugging
    const form = document.querySelector('form.form-section');
    if (form) {
        console.log('Quiz form found and ready');
        
        form.addEventListener('submit', function(e) {
            console.log('Quiz form submitted!');
            
            const formData = new FormData(form);
            console.log('Module ID:', formData.get('module_id'));
            console.log('Product ID:', formData.get('product_id'));
            console.log('Question:', formData.get('question'));
            console.log('Correct Answer:', formData.get('correct_answer'));
            
            // Validate product_id is being sent
            const productSelect = document.getElementById('product_id');
            if (productSelect && productSelect.value) {
                console.log('Product selected:', productSelect.value, productSelect.options[productSelect.selectedIndex].text);
            } else {
                console.warn('No product selected - quiz will not be linked to any product!');
            }
            
            // Let the form submit normally (don't prevent default)
            // The page will reload and show success/error messages
        });
    } else {
        console.error('Quiz form not found!');
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

