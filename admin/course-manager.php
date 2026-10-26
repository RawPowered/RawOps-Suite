<?php
$SEO = [
    'title' => 'Course Material Manager — Admin',
    'description' => 'Manage training modules, quizzes, and course content.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_module') {
            $moduleId = intval($_POST['module_id']);
            $title = trim($_POST['title']);
            $duration = intval($_POST['duration_minutes']);
            $content = trim($_POST['content']);
            $required = isset($_POST['required']) ? 1 : 0;
            $sortOrder = intval($_POST['sort_order']);
            
            $stmt = $pdo->prepare('UPDATE training_modules SET title = ?, duration_minutes = ?, content = ?, required = ?, sort_order = ? WHERE id = ?');
            $stmt->execute([$title, $duration, $content, $required, $sortOrder, $moduleId]);
            
            flash_set('success', 'Training module updated successfully!');
        }
        
        elseif ($action === 'create_module') {
            $slug = trim($_POST['slug']);
            $title = trim($_POST['title']);
            $duration = intval($_POST['duration_minutes']);
            $content = trim($_POST['content']);
            $required = isset($_POST['required']) ? 1 : 0;
            $sortOrder = intval($_POST['sort_order']);
            
            $stmt = $pdo->prepare('INSERT INTO training_modules (slug, title, duration_minutes, content, required, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$slug, $title, $duration, $content, $required, $sortOrder]);
            
            flash_set('success', 'Training module created successfully!');
        }
        
        elseif ($action === 'update_objection') {
            $objectionId = intval($_POST['objection_id']);
            $objectionText = trim($_POST['objection_text']);
            $category = trim($_POST['category']);
            $rebuttalText = trim($_POST['rebuttal_text']);
            $followUpText = trim($_POST['follow_up_text']);
            
            $stmt = $pdo->prepare('UPDATE objection_library SET objection_text = ?, category = ?, rebuttal_text = ?, follow_up_text = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$objectionText, $category, $rebuttalText, $followUpText, $objectionId]);
            
            flash_set('success', 'Objection updated successfully!');
        }
        
        elseif ($action === 'create_objection') {
            $objectionText = trim($_POST['objection_text']);
            $category = trim($_POST['category']);
            $rebuttalText = trim($_POST['rebuttal_text']);
            $followUpText = trim($_POST['follow_up_text']);
            
            $stmt = $pdo->prepare('INSERT INTO objection_library (objection_text, category, rebuttal_text, follow_up_text, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$objectionText, $category, $rebuttalText, $followUpText, current_user_id()]);
            
            flash_set('success', 'Objection added successfully!');
        }
        
        elseif ($action === 'delete_objection') {
            $objectionId = intval($_POST['objection_id']);
            $stmt = $pdo->prepare('DELETE FROM objection_library WHERE id = ?');
            $stmt->execute([$objectionId]);
            
            flash_set('success', 'Objection deleted successfully!');
        }
        
    } catch (Exception $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get current tab
$tab = $_GET['tab'] ?? 'modules';

// Get data based on tab
if ($tab === 'modules') {
    $stmt = $pdo->query('SELECT * FROM training_modules ORDER BY sort_order ASC');
    $modules = $stmt->fetchAll();
    
    // Get module statistics
    $stmt = $pdo->query('
        SELECT 
            tm.id,
            tm.title,
            COUNT(DISTINCT tp.user_id) as completions,
            AVG(tp.quiz_score) as avg_score,
            AVG(tp.quiz_attempts) as avg_attempts,
            COUNT(DISTINCT CASE WHEN tp.quiz_score < 80 THEN tp.user_id END) as failures
        FROM training_modules tm
        LEFT JOIN training_progress tp ON tp.module_id = tm.id AND tp.status = "completed"
        GROUP BY tm.id
        ORDER BY tm.sort_order
    ');
    $moduleStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $statsById = [];
    foreach ($moduleStats as $stat) {
        $statsById[$stat['id']] = $stat;
    }
}

elseif ($tab === 'objections') {
    $stmt = $pdo->query('
        SELECT ol.*, u.first_name, u.last_name 
        FROM objection_library ol 
        LEFT JOIN users u ON u.id = ol.created_by 
        ORDER BY ol.category, ol.success_rate DESC
    ');
    $objections = $stmt->fetchAll();
    
    // Get categories
    $stmt = $pdo->query('SELECT DISTINCT category FROM objection_library ORDER BY category');
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

elseif ($tab === 'quiz_results') {
    $stmt = $pdo->query('
        SELECT 
            tqr.*,
            u.first_name, u.last_name, u.email,
            tm.title as module_title
        FROM training_quiz_results tqr
        JOIN users u ON u.id = tqr.user_id
        JOIN training_modules tm ON tm.id = tqr.module_id
        ORDER BY tqr.completed_at DESC
        LIMIT 100
    ');
    $quizResults = $stmt->fetchAll();
}
?>

<style>
.course-manager {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.tab-navigation {
    display: flex;
    gap: 4px;
    margin-bottom: 24px;
    border-bottom: 2px solid #e5e7eb;
}

.tab-button {
    padding: 12px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    color: #6b7280;
    transition: all 0.3s;
}

.tab-button.active {
    color: #059669;
    border-bottom-color: #059669;
}

.tab-button:hover {
    color: #10b981;
    background: #f0fdf4;
}

.module-card, .objection-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 20px;
}

.module-header {
    display: flex;
    justify-content: between;
    align-items: center;
    margin-bottom: 16px;
}

.module-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 16px;
    margin-top: 16px;
}

.stat-item {
    text-align: center;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
}

.stat-value {
    font-size: 24px;
    font-weight: bold;
    color: #059669;
}

.stat-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
}

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

.form-group input, .form-group textarea, .form-group select {
    width: 100%;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
}

.form-group textarea {
    min-height: 120px;
    resize: vertical;
}

.btn-group {
    display: flex;
    gap: 12px;
    margin-top: 16px;
}

.quiz-results-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
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
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.score-pass {
    background: #dcfce7;
    color: #166534;
}

.score-fail {
    background: #fef2f2;
    color: #dc2626;
}

.collapsible-content {
    display: none;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

.collapsible-content.active {
    display: block;
}

.toggle-button {
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}

.toggle-button:hover {
    background: #e5e7eb;
}

.editor-toolbar {
    display: flex;
    gap: 4px;
    margin-bottom: 8px;
    padding: 8px;
    background: #f9fafb;
    border: 1px solid #d1d5db;
    border-bottom: none;
    border-radius: 8px 8px 0 0;
}

.editor-btn {
    padding: 6px 10px;
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

.rich-editor {
    border-radius: 0 0 8px 8px !important;
    border-top: none !important;
    min-height: 150px;
    font-family: 'Courier New', monospace;
    font-size: 14px;
    line-height: 1.5;
}

.editor-help {
    margin-top: 8px;
    padding: 8px;
    background: #f0f9ff;
    border-radius: 6px;
    border-left: 3px solid #0ea5e9;
}
</style>

<div class="course-manager">
    <h1 style="color: #059669; margin-bottom: 8px;">📚 Course Material Manager</h1>
    <p style="color: #6b7280; margin-bottom: 32px;">Manage training modules, objection library, and quiz results</p>
    
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Tab Navigation -->
    <div class="tab-navigation">
        <a href="?tab=modules" class="tab-button <?php echo $tab === 'modules' ? 'active' : ''; ?>">Training Modules</a>
        <a href="?tab=objections" class="tab-button <?php echo $tab === 'objections' ? 'active' : ''; ?>">Objection Library</a>
        <a href="?tab=quiz_results" class="tab-button <?php echo $tab === 'quiz_results' ? 'active' : ''; ?>">Quiz Results</a>
    </div>
    
    <?php if ($tab === 'modules'): ?>
        <!-- Training Modules Tab -->
        <div class="tab-content">
            <?php foreach ($modules as $module): ?>
                <div class="module-card">
                    <div class="module-header">
                        <div>
                            <h3 style="margin: 0; color: #1f2937;"><?php echo htmlspecialchars($module['title']); ?></h3>
                            <p style="margin: 4px 0 0 0; color: #6b7280;">
                                Slug: <?php echo htmlspecialchars($module['slug']); ?> | 
                                Duration: <?php echo $module['duration_minutes']; ?> minutes |
                                <?php echo $module['required'] ? 'Required' : 'Optional'; ?>
                            </p>
                        </div>
                        <button class="toggle-button" onclick="toggleModule(<?php echo $module['id']; ?>)">
                            Edit Module
                        </button>
                    </div>
                    
                    <!-- Module Statistics -->
                    <?php if (isset($statsById[$module['id']])): ?>
                        <?php $stats = $statsById[$module['id']]; ?>
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo intval($stats['completions']); ?></div>
                                <div class="stat-label">Completions</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['avg_score'] ? round($stats['avg_score'], 1) . '%' : 'N/A'; ?></div>
                                <div class="stat-label">Avg Score</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['avg_attempts'] ? round($stats['avg_attempts'], 1) : 'N/A'; ?></div>
                                <div class="stat-label">Avg Attempts</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo intval($stats['failures']); ?></div>
                                <div class="stat-label">Failures</div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Edit Form (Collapsible) -->
                    <div class="collapsible-content" id="module-<?php echo $module['id']; ?>">
                        <form method="post">
                            <input type="hidden" name="action" value="update_module">
                            <input type="hidden" name="module_id" value="<?php echo $module['id']; ?>">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="title-<?php echo $module['id']; ?>">Title</label>
                                    <input type="text" id="title-<?php echo $module['id']; ?>" name="title" value="<?php echo htmlspecialchars($module['title']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="duration-<?php echo $module['id']; ?>">Duration (minutes)</label>
                                    <input type="number" id="duration-<?php echo $module['id']; ?>" name="duration_minutes" value="<?php echo $module['duration_minutes']; ?>" required>
                                </div>
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="sort-<?php echo $module['id']; ?>">Sort Order</label>
                                    <input type="number" id="sort-<?php echo $module['id']; ?>" name="sort_order" value="<?php echo $module['sort_order']; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="required" <?php echo $module['required'] ? 'checked' : ''; ?>>
                                        Required Module
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="content-<?php echo $module['id']; ?>">Content Description</label>
                                <div class="editor-toolbar">
                                    <button type="button" class="editor-btn" onclick="formatText('bold', 'content-<?php echo $module['id']; ?>')"><strong>B</strong></button>
                                    <button type="button" class="editor-btn" onclick="formatText('italic', 'content-<?php echo $module['id']; ?>')"><em>I</em></button>
                                    <button type="button" class="editor-btn" onclick="formatText('underline', 'content-<?php echo $module['id']; ?>')"><u>U</u></button>
                                    <button type="button" class="editor-btn" onclick="insertText('content-<?php echo $module['id']; ?>', '### ')">H3</button>
                                    <button type="button" class="editor-btn" onclick="insertText('content-<?php echo $module['id']; ?>', '- ')">List</button>
                                    <button type="button" class="editor-btn" onclick="insertText('content-<?php echo $module['id']; ?>', '**Key Point:** ')">💡 Key</button>
                                    <button type="button" class="editor-btn" onclick="insertText('content-<?php echo $module['id']; ?>', '> ')">Quote</button>
                                </div>
                                <textarea id="content-<?php echo $module['id']; ?>" name="content" class="rich-editor"><?php echo htmlspecialchars($module['content']); ?></textarea>
                                <div class="editor-help">
                                    <small style="color: #6b7280;">
                                        💡 <strong>Formatting Tips:</strong> Use **bold**, *italic*, ### for headings, - for bullet points, > for quotes
                                    </small>
                                </div>
                            </div>
                            
                            <div class="btn-group">
                                <button type="submit" class="btn">Update Module</button>
                                <button type="button" class="btn secondary" onclick="toggleModule(<?php echo $module['id']; ?>)">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Add New Module -->
            <div class="module-card">
                <div class="module-header">
                    <h3 style="margin: 0; color: #059669;">➕ Add New Module</h3>
                    <button class="toggle-button" onclick="toggleModule('new')">
                        Create Module
                    </button>
                </div>
                
                <div class="collapsible-content" id="module-new">
                    <form method="post">
                        <input type="hidden" name="action" value="create_module">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="new-slug">Slug (URL-friendly)</label>
                                <input type="text" id="new-slug" name="slug" placeholder="e.g., advanced-sales" required>
                            </div>
                            <div class="form-group">
                                <label for="new-title">Title</label>
                                <input type="text" id="new-title" name="title" placeholder="e.g., Advanced Sales Techniques" required>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="new-duration">Duration (minutes)</label>
                                <input type="number" id="new-duration" name="duration_minutes" value="60" required>
                            </div>
                            <div class="form-group">
                                <label for="new-sort">Sort Order</label>
                                <input type="number" id="new-sort" name="sort_order" value="<?php echo count($modules) + 1; ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="required" checked>
                                Required Module
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label for="new-content">Content Description</label>
                            <div class="editor-toolbar">
                                <button type="button" class="editor-btn" onclick="formatText('bold', 'new-content')"><strong>B</strong></button>
                                <button type="button" class="editor-btn" onclick="formatText('italic', 'new-content')"><em>I</em></button>
                                <button type="button" class="editor-btn" onclick="formatText('underline', 'new-content')"><u>U</u></button>
                                <button type="button" class="editor-btn" onclick="insertText('new-content', '### ')">H3</button>
                                <button type="button" class="editor-btn" onclick="insertText('new-content', '- ')">List</button>
                                <button type="button" class="editor-btn" onclick="insertText('new-content', '**Key Point:** ')">💡 Key</button>
                                <button type="button" class="editor-btn" onclick="insertText('new-content', '> ')">Quote</button>
                            </div>
                            <textarea id="new-content" name="content" class="rich-editor" placeholder="Brief description of module content..."></textarea>
                            <div class="editor-help">
                                <small style="color: #6b7280;">
                                    💡 <strong>Formatting Tips:</strong> Use **bold**, *italic*, ### for headings, - for bullet points, > for quotes
                                </small>
                            </div>
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" class="btn">Create Module</button>
                            <button type="button" class="btn secondary" onclick="toggleModule('new')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
    <?php elseif ($tab === 'objections'): ?>
        <!-- Objection Library Tab -->
        <div class="tab-content">
            <?php 
            $currentCategory = '';
            foreach ($objections as $objection): 
                if ($objection['category'] !== $currentCategory):
                    if ($currentCategory !== '') echo '</div>';
                    $currentCategory = $objection['category'];
                    echo '<h3 style="color: #059669; margin: 32px 0 16px 0; text-transform: capitalize;">' . htmlspecialchars($currentCategory) . ' Objections</h3>';
                    echo '<div class="category-group">';
                endif;
            ?>
                <div class="objection-card">
                    <div style="display: flex; justify-content: between; align-items: start; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 8px 0; color: #1f2937;">"<?php echo htmlspecialchars($objection['objection_text']); ?>"</h4>
                            <p style="margin: 0; color: #6b7280; font-size: 14px;">
                                Success Rate: <?php echo $objection['success_rate'] ?? 'N/A'; ?>% | 
                                Used: <?php echo $objection['times_used']; ?> times |
                                Created by: <?php echo htmlspecialchars($objection['first_name'] . ' ' . $objection['last_name']); ?>
                            </p>
                        </div>
                        <button class="toggle-button" onclick="toggleObjection(<?php echo $objection['id']; ?>)">
                            Edit
                        </button>
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <strong style="color: #059669;">Rebuttal:</strong>
                        <p style="margin: 8px 0; color: #374151;"><?php echo nl2br(htmlspecialchars($objection['rebuttal_text'])); ?></p>
                    </div>
                    
                    <?php if ($objection['follow_up_text']): ?>
                        <div style="margin-bottom: 16px;">
                            <strong style="color: #059669;">Follow-up:</strong>
                            <p style="margin: 8px 0; color: #374151;"><?php echo nl2br(htmlspecialchars($objection['follow_up_text'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Edit Form (Collapsible) -->
                    <div class="collapsible-content" id="objection-<?php echo $objection['id']; ?>">
                        <form method="post">
                            <input type="hidden" name="action" value="update_objection">
                            <input type="hidden" name="objection_id" value="<?php echo $objection['id']; ?>">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Objection Text</label>
                                    <textarea name="objection_text" required><?php echo htmlspecialchars($objection['objection_text']); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Category</label>
                                    <select name="category" required>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $cat === $objection['category'] ? 'selected' : ''; ?>>
                                                <?php echo ucfirst(htmlspecialchars($cat)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Rebuttal</label>
                                <textarea name="rebuttal_text" required><?php echo htmlspecialchars($objection['rebuttal_text']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Follow-up (Optional)</label>
                                <textarea name="follow_up_text"><?php echo htmlspecialchars($objection['follow_up_text']); ?></textarea>
                            </div>
                            
                            <div class="btn-group">
                                <button type="submit" class="btn">Update Objection</button>
                                <button type="button" class="btn secondary" onclick="toggleObjection(<?php echo $objection['id']; ?>)">Cancel</button>
                                <button type="submit" name="action" value="delete_objection" class="btn" style="background: #dc2626;" onclick="return confirm('Are you sure you want to delete this objection?')">Delete</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($currentCategory !== '') echo '</div>'; ?>
            
            <!-- Add New Objection -->
            <div class="objection-card" style="border: 2px dashed #d1d5db;">
                <div style="text-align: center; margin-bottom: 16px;">
                    <h3 style="margin: 0; color: #059669;">➕ Add New Objection</h3>
                    <button class="toggle-button" onclick="toggleObjection('new')" style="margin-top: 12px;">
                        Create Objection
                    </button>
                </div>
                
                <div class="collapsible-content" id="objection-new">
                    <form method="post">
                        <input type="hidden" name="action" value="create_objection">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Objection Text</label>
                                <textarea name="objection_text" placeholder="What the customer might say..." required></textarea>
                            </div>
                            <div class="form-group">
                                <label>Category</label>
                                <select name="category" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>">
                                            <?php echo ucfirst(htmlspecialchars($cat)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Rebuttal</label>
                            <textarea name="rebuttal_text" placeholder="Your response to overcome this objection..." required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>Follow-up (Optional)</label>
                            <textarea name="follow_up_text" placeholder="Additional follow-up question or statement..."></textarea>
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" class="btn">Create Objection</button>
                            <button type="button" class="btn secondary" onclick="toggleObjection('new')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
    <?php elseif ($tab === 'quiz_results'): ?>
        <!-- Quiz Results Tab -->
        <div class="tab-content">
            <div style="background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 24px;">
                <h3 style="margin: 0 0 20px 0; color: #1f2937;">Recent Quiz Results</h3>
                
                <table class="quiz-results-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Module</th>
                            <th>Score</th>
                            <th>Attempt</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quizResults as $result): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></strong><br>
                                    <small style="color: #6b7280;"><?php echo htmlspecialchars($result['email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($result['module_title']); ?></td>
                                <td>
                                    <?php echo $result['score']; ?>/<?php echo $result['total_questions']; ?>
                                    <small style="color: #6b7280;">
                                        (<?php echo $result['total_questions'] > 0 ? round(($result['score'] / $result['total_questions']) * 100) : 0; ?>%)
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
                    </tbody>
                </table>
                
                <?php if (empty($quizResults)): ?>
                    <div style="text-align: center; padding: 40px; color: #6b7280;">
                        <p>No quiz results found yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleModule(moduleId) {
    const content = document.getElementById('module-' + moduleId);
    const isActive = content.classList.contains('active');
    
    // Close all other modules
    document.querySelectorAll('.collapsible-content').forEach(el => {
        el.classList.remove('active');
    });
    
    // Toggle current module
    if (!isActive) {
        content.classList.add('active');
    }
}

function toggleObjection(objectionId) {
    const content = document.getElementById('objection-' + objectionId);
    const isActive = content.classList.contains('active');
    
    // Close all other objections
    document.querySelectorAll('.collapsible-content').forEach(el => {
        el.classList.remove('active');
    });
    
    // Toggle current objection
    if (!isActive) {
        content.classList.add('active');
    }
}

// Auto-generate slug from title for new modules
document.getElementById('new-title')?.addEventListener('input', function() {
    const title = this.value;
    const slug = title.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .trim('-');
    document.getElementById('new-slug').value = slug;
});

// Rich Text Editor Functions
function formatText(command, textareaId) {
    const textarea = document.getElementById(textareaId);
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selectedText = textarea.value.substring(start, end);
    
    let formattedText = '';
    switch(command) {
        case 'bold':
            formattedText = `**${selectedText}**`;
            break;
        case 'italic':
            formattedText = `*${selectedText}*`;
            break;
        case 'underline':
            formattedText = `<u>${selectedText}</u>`;
            break;
        default:
            formattedText = selectedText;
    }
    
    textarea.value = textarea.value.substring(0, start) + formattedText + textarea.value.substring(end);
    textarea.focus();
    textarea.setSelectionRange(start + formattedText.length, start + formattedText.length);
}

function insertText(textareaId, textToInsert) {
    const textarea = document.getElementById(textareaId);
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    
    textarea.value = textarea.value.substring(0, start) + textToInsert + textarea.value.substring(end);
    textarea.focus();
    textarea.setSelectionRange(start + textToInsert.length, start + textToInsert.length);
}

// Live preview functionality
document.querySelectorAll('.rich-editor').forEach(editor => {
    editor.addEventListener('input', function() {
        // Add live character count
        const charCount = this.value.length;
        let counter = this.parentNode.querySelector('.char-counter');
        if (!counter) {
            counter = document.createElement('div');
            counter.className = 'char-counter';
            counter.style.cssText = 'text-align: right; font-size: 12px; color: #6b7280; margin-top: 4px;';
            this.parentNode.appendChild(counter);
        }
        counter.textContent = `${charCount} characters`;
    });
});

// Auto-save functionality for content
let autoSaveTimeout;
document.querySelectorAll('.rich-editor').forEach(editor => {
    editor.addEventListener('input', function() {
        clearTimeout(autoSaveTimeout);
        autoSaveTimeout = setTimeout(() => {
            // Save to localStorage as draft
            const key = `course_content_draft_${this.id}`;
            localStorage.setItem(key, this.value);
            
            // Show saved indicator
            let indicator = document.querySelector('.auto-save-indicator');
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.className = 'auto-save-indicator';
                indicator.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 8px 16px; border-radius: 6px; font-size: 12px; z-index: 1000; opacity: 0; transition: opacity 0.3s;';
                document.body.appendChild(indicator);
            }
            
            indicator.textContent = '✓ Draft saved';
            indicator.style.opacity = '1';
            setTimeout(() => {
                indicator.style.opacity = '0';
            }, 2000);
        }, 2000); // Auto-save after 2 seconds of no typing
    });
});

// Restore drafts on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.rich-editor').forEach(editor => {
        const key = `course_content_draft_${editor.id}`;
        const draft = localStorage.getItem(key);
        if (draft && draft !== editor.value && confirm('Restore unsaved draft content?')) {
            editor.value = draft;
        }
    });
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
