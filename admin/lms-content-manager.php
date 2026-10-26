<?php
$SEO = [
    'title' => 'LMS Content Manager — Admin',
    'description' => 'Complete Learning Management System for training content editing.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Get the module to edit
$moduleSlug = $_GET['module'] ?? '';
$action = $_GET['action'] ?? 'list';

// Handle content updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    
    try {
        if ($postAction === 'save_content') {
            $slug = $_POST['module_slug'];
            $content = $_POST['content'];
            $title = $_POST['title'];
            $duration = intval($_POST['duration']);
            
            // Update database
            $stmt = $pdo->prepare('UPDATE training_modules SET title = ?, duration_minutes = ? WHERE slug = ?');
            $stmt->execute([$title, $duration, $slug]);
            
            // Save content to file
            $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $slug . '.php';
            if (file_put_contents($moduleFile, $content)) {
                flash_set('success', 'Module content saved successfully!');
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
            
            // Insert into database
            $stmt = $pdo->prepare('INSERT INTO training_modules (slug, title, duration_minutes, sort_order, required) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$slug, $title, $duration, $sortOrder, $required]);
            
            // Create module file with template
            $template = generateModuleTemplate($title);
            $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $slug . '.php';
            file_put_contents($moduleFile, $template);
            
            flash_set('success', 'New module created successfully!');
        }
        
    } catch (Exception $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get all modules
$modules = $pdo->query('SELECT * FROM training_modules ORDER BY sort_order')->fetchAll();

// Get specific module if editing
$currentModule = null;
$moduleContent = '';
$fileError = '';
if ($moduleSlug && $action === 'edit') {
    $stmt = $pdo->prepare('SELECT * FROM training_modules WHERE slug = ?');
    $stmt->execute([$moduleSlug]);
    $currentModule = $stmt->fetch();
    
    if ($currentModule) {
        $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $moduleSlug . '.php';
        
        // Debug information
        $debugInfo = [
            'slug' => $moduleSlug,
            'expected_file' => $moduleFile,
            'file_exists' => file_exists($moduleFile),
            'is_readable' => is_readable($moduleFile),
            'file_size' => file_exists($moduleFile) ? filesize($moduleFile) : 0
        ];
        
        if (file_exists($moduleFile) && is_readable($moduleFile)) {
            $moduleContent = file_get_contents($moduleFile);
            if ($moduleContent === false) {
                $fileError = 'Failed to read module file (permission issue?)';
                $moduleContent = '';
            } elseif (empty(trim($moduleContent))) {
                $fileError = 'Module file is empty';
            }
        } else {
            $fileError = 'Module file issue: ' . json_encode($debugInfo);
        }
    } else {
        $fileError = 'Module not found in database for slug: ' . $moduleSlug;
    }
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
}

.module-grid {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 24px;
}

.module-list {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 20px;
    height: fit-content;
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

.content-editor {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
}

.editor-toolbar {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
    flex-wrap: wrap;
}

.editor-btn {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    background: white;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.2s;
}

.editor-btn:hover {
    background: #f3f4f6;
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

.preview-pane {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 24px;
    margin-top: 16px;
    min-height: 400px;
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

.form-group input, .form-group select {
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
</style>

<div class="lms-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">📚 Learning Management System</h1>
        <p style="font-size: 18px; opacity: 0.9;">Complete content management for training modules, quizzes, and materials</p>
    </div>
</div>

<div class="lms-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div class="module-grid">
        <!-- Module List Sidebar -->
        <div class="module-list">
            <h3 style="margin: 0 0 20px 0; color: #1f2937;">📋 Training Modules</h3>
            
            <?php foreach ($modules as $module): ?>
                <div class="module-item <?php echo $moduleSlug === $module['slug'] ? 'active' : ''; ?>" 
                     onclick="window.location.href='?action=edit&module=<?php echo $module['slug']; ?>'">
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
        <div class="content-editor">
            <?php if ($action === 'edit' && $currentModule): ?>
                <!-- Edit Module -->
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
                        
                        <!-- Component Library -->
                        <div class="component-library">
                            <h4 style="margin: 0 0 12px 0; color: #374151;">🧩 Content Components</h4>
                            <button type="button" class="component-btn" onclick="insertComponent('header')">📝 Header</button>
                            <button type="button" class="component-btn" onclick="insertComponent('paragraph')">📄 Paragraph</button>
                            <button type="button" class="component-btn" onclick="insertComponent('highlight')">💡 Highlight Box</button>
                            <button type="button" class="component-btn" onclick="insertComponent('quiz')">❓ Quiz Question</button>
                            <button type="button" class="component-btn" onclick="insertComponent('grid')">📊 Two-Column Grid</button>
                            <button type="button" class="component-btn" onclick="insertComponent('table')">📋 Table</button>
                            <button type="button" class="component-btn" onclick="insertComponent('script')">🔧 JavaScript</button>
                            <button type="button" class="component-btn" onclick="insertComponent('takeaways')">✅ Key Takeaways</button>
                        </div>
                        
                        <!-- Rich Text Editor -->
                        <div class="editor-toolbar">
                            <button type="button" class="editor-btn" onclick="formatText('bold')"><strong>B</strong></button>
                            <button type="button" class="editor-btn" onclick="formatText('italic')"><em>I</em></button>
                            <button type="button" class="editor-btn" onclick="formatText('code')"><code>&lt;/&gt;</code></button>
                            <button type="button" class="editor-btn" onclick="insertText('### ')">H3</button>
                            <button type="button" class="editor-btn" onclick="insertText('- ')">• List</button>
                            <button type="button" class="editor-btn" onclick="insertText('> ')">Quote</button>
                            <button type="button" class="editor-btn" onclick="insertText('**IMPORTANT:** ')">⚠️ Important</button>
                            <button type="button" class="editor-btn" onclick="validateHTML()">✅ Validate</button>
                        </div>
                        
                        <?php if (!empty($fileError)): ?>
                            <div style="background: #fee2e2; color: #991b1b; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                                <strong>⚠️ File Loading Error:</strong> <?php echo htmlspecialchars($fileError); ?>
                                
                                <?php if (strpos($fileError, 'Module file issue:') === 0): ?>
                                    <div style="margin-top: 12px;">
                                        <button type="button" onclick="tryAlternativeFiles()" class="btn secondary" style="font-size: 12px;">
                                            🔧 Try Alternative File Names
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <textarea name="content" class="content-textarea" id="content-editor" placeholder="Enter module content..."><?php echo htmlspecialchars($moduleContent); ?></textarea>
                        
                        <?php if (!empty($moduleContent)): ?>
                            <div style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                                ✅ Loaded <?php echo number_format(strlen($moduleContent)); ?> characters from module file
                            </div>
                        <?php endif; ?>
                        
                        <div style="margin-top: 16px; display: flex; gap: 12px;">
                            <button type="submit" class="btn">💾 Save Module</button>
                            <button type="button" onclick="showPreview()" class="btn secondary">👁️ Preview</button>
                            <button type="button" onclick="autoSave()" class="btn secondary">💾 Auto-Save</button>
                        </div>
                    </div>
                    
                    <!-- Preview Tab -->
                    <div id="preview-tab" class="tab-content" style="display: none;">
                        <h3>👁️ Live Preview</h3>
                        <div class="preview-pane" id="preview-content">
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
                    <h2 style="color: #1f2937; margin-bottom: 16px;">Learning Management System</h2>
                    <p style="color: #6b7280; margin-bottom: 32px;">
                        Select a module from the sidebar to edit content, or create a new module to expand your training program.
                    </p>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; max-width: 600px; margin: 0 auto;">
                        <div style="background: #f0f9ff; padding: 20px; border-radius: 8px;">
                            <h4 style="color: #1e40af; margin: 0 0 8px 0;">📝 Edit Content</h4>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">Modify text, add components, update quizzes</p>
                        </div>
                        <div style="background: #f0fdf4; padding: 20px; border-radius: 8px;">
                            <h4 style="color: #059669; margin: 0 0 8px 0;">👁️ Live Preview</h4>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">See changes as trainees will see them</p>
                        </div>
                        <div style="background: #fef3c7; padding: 20px; border-radius: 8px;">
                            <h4 style="color: #92400e; margin: 0 0 8px 0;">📊 Analytics</h4>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">Track completion rates and performance</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
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

function showTab(tab) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    // Show selected tab
    document.getElementById(tab + '-tab').style.display = 'block';
    document.querySelector(`[onclick="showTab('${tab}')"]`).classList.add('active');
    
    currentTab = tab;
    
    if (tab === 'preview') {
        showPreview();
    }
}

function showPreview() {
    const content = document.getElementById('content-editor').value;
    const previewPane = document.getElementById('preview-content');
    
    // Basic HTML rendering (in production, you'd want server-side rendering)
    previewPane.innerHTML = content
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'");
}

function insertComponent(type) {
    const editor = document.getElementById('content-editor');
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
        case 'quiz':
            component = `\n<div class="quiz-question">
    <p><strong>Question:</strong> Your question here?</p>
    <div class="quiz-options">
        <label><input type="radio" name="q1" value="a"> Option A</label>
        <label><input type="radio" name="q1" value="b"> Option B</label>
        <label><input type="radio" name="q1" value="c"> Option C</label>
    </div>
    <p style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;" class="answer">
        <strong>Correct Answer: B</strong> - Explanation here.
    </p>
</div>\n`;
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
    
    insertTextAtCursor(editor, component);
}

function insertTextAtCursor(textarea, text) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    
    textarea.value = textarea.value.substring(0, start) + text + textarea.value.substring(end);
    textarea.focus();
    textarea.setSelectionRange(start + text.length, start + text.length);
}

function formatText(command) {
    const editor = document.getElementById('content-editor');
    const start = editor.selectionStart;
    const end = editor.selectionEnd;
    const selectedText = editor.value.substring(start, end);
    
    let formattedText = '';
    switch(command) {
        case 'bold':
            formattedText = `<strong>${selectedText}</strong>`;
            break;
        case 'italic':
            formattedText = `<em>${selectedText}</em>`;
            break;
        case 'code':
            formattedText = `<code>${selectedText}</code>`;
            break;
    }
    
    editor.value = editor.value.substring(0, start) + formattedText + editor.value.substring(end);
    editor.focus();
    editor.setSelectionRange(start + formattedText.length, start + formattedText.length);
}

function insertText(text) {
    insertTextAtCursor(document.getElementById('content-editor'), text);
}

function validateHTML() {
    const content = document.getElementById('content-editor').value;
    
    // Basic HTML validation
    const unclosedTags = [];
    const tagRegex = /<(\/?)([\w-]+)[^>]*>/g;
    const stack = [];
    let match;
    
    while ((match = tagRegex.exec(content)) !== null) {
        const isClosing = match[1] === '/';
        const tagName = match[2].toLowerCase();
        
        // Skip self-closing tags
        if (['img', 'br', 'hr', 'input', 'meta', 'link'].includes(tagName)) continue;
        
        if (isClosing) {
            if (stack.length === 0 || stack.pop() !== tagName) {
                unclosedTags.push(tagName);
            }
        } else {
            stack.push(tagName);
        }
    }
    
    if (stack.length > 0 || unclosedTags.length > 0) {
        alert('HTML Validation Issues:\n' + 
              (stack.length > 0 ? 'Unclosed tags: ' + stack.join(', ') + '\n' : '') +
              (unclosedTags.length > 0 ? 'Unmatched closing tags: ' + unclosedTags.join(', ') : ''));
    } else {
        alert('✅ HTML is valid!');
    }
}

function autoSave() {
    const formData = new FormData(document.getElementById('module-form'));
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            // Show auto-save indicator
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

// Auto-save every 30 seconds
setInterval(autoSave, 30000);

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

function tryAlternativeFiles() {
    const currentSlug = '<?php echo $moduleSlug; ?>';
    const alternatives = [
        currentSlug.replace(/-and-/g, '-'),
        currentSlug.replace(/-/g, ''),
        currentSlug.replace(/and/g, ''),
        'products-product categories' // Known alternative
    ];
    
    // Try to load content from alternative file names
    Promise.all(alternatives.map(slug => 
        fetch(`../onboarding/trainee/modules/${slug}.php`)
            .then(response => response.ok ? {slug, content: response.text()} : null)
            .catch(() => null)
    )).then(results => {
        const found = results.find(r => r !== null);
        if (found) {
            found.content.then(content => {
                document.getElementById('content-editor').value = content;
                alert(`Found content in ${found.slug}.php! Content loaded successfully.`);
            });
        } else {
            alert('No alternative files found. You may need to create the content manually.');
        }
    });
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
