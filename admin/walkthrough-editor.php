<?php
/**
 * Walkthrough WYSIWYG Editor
 * Visual editor for creating and editing walkthroughs with shortcode support
 * 
 * @author Product Development Team
 * @date 2025-10-20
 */

$SEO = [
    'title' => 'Walkthrough Editor — Admin',
    'description' => 'Create and edit walkthroughs with visual editor',
    'type' => 'website'
];

require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));
require_once __DIR__ . '/../required/shortcode-engine.php';

$pdo = db();
$adminId = current_admin_id();

// Get walkthrough ID if editing
$walkthroughId = intval($_GET['id'] ?? 0);
$isEditing = $walkthroughId > 0;

// Load existing walkthrough if editing
$walkthrough = null;
$steps = [];

if ($isEditing) {
    $stmt = $pdo->prepare('SELECT * FROM sales_walkthroughs WHERE id = ?');
    $stmt->execute([$walkthroughId]);
    $walkthrough = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$walkthrough) {
        flash_set('error', 'Walkthrough not found');
        redirect('/admin/walkthrough-manager.php');
    }
    
    // Load steps if multi-step
    if ($walkthrough['is_multi_step']) {
        $stmt = $pdo->prepare('SELECT * FROM walkthrough_steps WHERE walkthrough_id = ? ORDER BY step_number ASC');
        $stmt->execute([$walkthroughId]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_walkthrough') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $slug = trim($_POST['slug'] ?? '');
            $targetPage = trim($_POST['target_page'] ?? '') ?: null;
            $position = $_POST['position'] ?? 'modal';
            $showForRoles = $_POST['show_for_roles'] ?? [];
            $isMultiStep = isset($_POST['is_multi_step']) && $_POST['is_multi_step'] === '1';
            $content = $_POST['content'] ?? '';
            $sortOrder = intval($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1';
            
            if (empty($title) || empty($slug)) {
                throw new Exception('Title and slug are required');
            }
            
            $pdo->beginTransaction();
            
            if ($isEditing) {
                // Update existing
                $stmt = $pdo->prepare('
                    UPDATE sales_walkthroughs SET
                        title = ?, description = ?, slug = ?, target_page = ?, position = ?,
                        content = ?, show_for_roles = ?, is_multi_step = ?, sort_order = ?,
                        is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([
                    $title, $description, $slug, $targetPage, $position,
                    $content, json_encode($showForRoles), $isMultiStep, $sortOrder,
                    $isActive, $walkthroughId
                ]);
                
                $savedId = $walkthroughId;
                
            } else {
                // Create new
                $stmt = $pdo->prepare('
                    INSERT INTO sales_walkthroughs 
                        (title, description, slug, target_page, position, content, show_for_roles, 
                         is_multi_step, sort_order, is_active, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ');
                $stmt->execute([
                    $title, $description, $slug, $targetPage, $position, $content,
                    json_encode($showForRoles), $isMultiStep, $sortOrder, $isActive, $adminId
                ]);
                
                $savedId = $pdo->lastInsertId();
            }
            
            // Handle multi-step content
            if ($isMultiStep) {
                // Delete existing steps
                $stmt = $pdo->prepare('DELETE FROM walkthrough_steps WHERE walkthrough_id = ?');
                $stmt->execute([$savedId]);
                
                // Insert new steps
                $stepTitles = $_POST['step_title'] ?? [];
                $stepContents = $_POST['step_content'] ?? [];
                $stepPositions = $_POST['step_position'] ?? [];
                $stepSelectors = $_POST['step_selector'] ?? [];
                
                foreach ($stepTitles as $index => $stepTitle) {
                    if (empty($stepTitle)) continue;
                    
                    $stmt = $pdo->prepare('
                        INSERT INTO walkthrough_steps 
                            (walkthrough_id, step_number, title, content, position, target_element_selector)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $savedId,
                        $index + 1,
                        $stepTitle,
                        $stepContents[$index] ?? '',
                        $stepPositions[$index] ?? 'modal',
                        $stepSelectors[$index] ?? ''
                    ]);
                }
            }
            
            $pdo->commit();
            
            flash_set('success', 'Walkthrough saved successfully!');
            redirect('/admin/walkthrough-editor.php?id=' . $savedId);
            
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Get products for shortcode builder
$products = $pdo->query("SELECT id, sku, name FROM products WHERE active = 1 ORDER BY name LIMIT 100")->fetchAll();

// Get available roles
$roles = $pdo->query("SELECT name FROM roles ORDER BY name")->fetchAll();

// Get available pages (common targets)
$availablePages = [
    'sales_dashboard' => 'Sales Dashboard',
    'leads' => 'Leads Page',
    'accounts' => 'Accounts Page',
    'invoice' => 'Invoice Creator',
    'request_sample' => 'Sample Request',
    'my_financials' => 'My Financials',
    'tasks' => 'Tasks Page',
    'calls' => 'Calls Log',
    'contacts' => 'Contacts',
    'dropoffs' => 'Demo Dropoffs',
    'training_module' => 'Training Modules',
    'lms_dashboard' => 'LMS Dashboard'
];
?>

<style>
.editor-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
}

.editor-header {
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    color: white;
    padding: 32px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.editor-layout {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 20px;
}

.editor-sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.editor-panel {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.editor-main {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    font-size: 14px;
}

.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-textarea {
    font-family: inherit;
    resize: vertical;
    min-height: 100px;
}

.visual-editor {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    min-height: 300px;
    padding: 16px;
    background: white;
    line-height: 1.6;
}

.visual-editor:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.visual-editor:empty:before {
    content: 'Start typing or use shortcode buttons...';
    color: #9ca3af;
}

.editor-toolbar {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
    flex-wrap: wrap;
}

.toolbar-btn {
    padding: 6px 12px;
    background: white;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
}

.toolbar-btn:hover {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.shortcode-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
    max-height: 400px;
    overflow-y: auto;
}

.shortcode-item {
    padding: 10px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.shortcode-item:hover {
    background: #eff6ff;
    border-color: #3b82f6;
}

.shortcode-name {
    font-weight: 600;
    color: #1f2937;
    font-size: 13px;
}

.shortcode-desc {
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
}

.preview-panel {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 20px;
    background: #f9fafb;
    min-height: 200px;
}

.step-editor {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
    background: #fafafa;
}

.step-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover {
    background: #059669;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

.btn-small {
    padding: 6px 12px;
    font-size: 12px;
}

.checkbox-group {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 8px;
    margin-top: 8px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px;
    background: #f9fafb;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.2s;
}

.checkbox-label:hover {
    background: #f3f4f6;
}

.checkbox-label input {
    cursor: pointer;
}

.tab-buttons {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}

.tab-btn {
    padding: 10px 20px;
    background: #f3f4f6;
    border: none;
    border-radius: 8px 8px 0 0;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.tab-btn.active {
    background: white;
    box-shadow: 0 -2px 4px rgba(0,0,0,0.05);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

@media (max-width: 1200px) {
    .editor-layout {
        grid-template-columns: 1fr;
    }
    
    .editor-sidebar {
        order: 2;
    }
}
</style>

<div class="editor-container">
    <div class="editor-header">
        <div>
            <h1 style="margin: 0 0 8px 0;">
                <?php echo $isEditing ? '✏️ Edit Walkthrough' : '➕ Create New Walkthrough'; ?>
            </h1>
            <p style="margin: 0; opacity: 0.9;">Visual editor with shortcode support</p>
        </div>
        <a href="<?php echo path_with_base('/admin/walkthrough-manager.php'); ?>" class="btn btn-secondary">
            ← Back to List
        </a>
    </div>
    
    <form method="post" id="walkthroughForm">
        <input type="hidden" name="action" value="save_walkthrough">
        
        <div class="editor-layout">
            <!-- Sidebar: Configuration & Shortcodes -->
            <div class="editor-sidebar">
                <!-- Basic Settings -->
                <div class="editor-panel">
                    <h3 style="margin: 0 0 16px 0;">⚙️ Settings</h3>
                    
                    <div class="form-group">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-input" required 
                               value="<?php echo htmlspecialchars($walkthrough['title'] ?? ''); ?>"
                               placeholder="e.g., Welcome to Sales Dashboard">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Slug * (URL-friendly ID)</label>
                        <input type="text" name="slug" class="form-input" required 
                               value="<?php echo htmlspecialchars($walkthrough['slug'] ?? ''); ?>"
                               placeholder="e.g., sales-dashboard-welcome">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="3"
                                  placeholder="Brief description of this walkthrough"><?php echo htmlspecialchars($walkthrough['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Target Page</label>
                        <select name="target_page" class="form-select">
                            <option value="">Global (All Pages)</option>
                            <?php foreach ($availablePages as $value => $label): ?>
                                <option value="<?php echo $value; ?>" 
                                        <?php echo ($walkthrough['target_page'] ?? '') === $value ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <select name="position" class="form-select">
                            <option value="modal" <?php echo ($walkthrough['position'] ?? 'modal') === 'modal' ? 'selected' : ''; ?>>Center Modal</option>
                            <option value="top" <?php echo ($walkthrough['position'] ?? '') === 'top' ? 'selected' : ''; ?>>Top</option>
                            <option value="bottom" <?php echo ($walkthrough['position'] ?? '') === 'bottom' ? 'selected' : ''; ?>>Bottom</option>
                            <option value="left" <?php echo ($walkthrough['position'] ?? '') === 'left' ? 'selected' : ''; ?>>Left</option>
                            <option value="right" <?php echo ($walkthrough['position'] ?? '') === 'right' ? 'selected' : ''; ?>>Right</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-input" min="0" step="1"
                               value="<?php echo $walkthrough['sort_order'] ?? 0; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="is_active" value="1" 
                                   <?php echo ($walkthrough['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            <span style="font-weight: 600;">Active</span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="is_multi_step" value="1" id="isMultiStepCheckbox"
                                   <?php echo ($walkthrough['is_multi_step'] ?? 0) ? 'checked' : ''; ?>
                                   onchange="toggleMultiStepMode()">
                            <span style="font-weight: 600;">Multi-Step Tutorial</span>
                        </label>
                    </div>
                </div>
                
                <!-- Role Visibility -->
                <div class="editor-panel">
                    <h3 style="margin: 0 0 16px 0;">👥 Show For Roles</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 0 0 12px 0;">
                        Leave all unchecked to show for all roles
                    </p>
                    
                    <div class="checkbox-group">
                        <?php 
                        $selectedRoles = $walkthrough ? json_decode($walkthrough['show_for_roles'], true) ?? [] : [];
                        foreach ($roles as $role): 
                        ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="show_for_roles[]" value="<?php echo htmlspecialchars($role['name']); ?>"
                                       <?php echo in_array($role['name'], $selectedRoles) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $role['name']))); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Shortcode Library -->
                <div class="editor-panel">
                    <h3 style="margin: 0 0 16px 0;">⚡ Shortcodes</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 0 0 12px 0;">
                        Click to insert at cursor position
                    </p>
                    
                    <div class="tab-buttons">
                        <button type="button" class="tab-btn active" onclick="switchShortcodeTab('products')">Products</button>
                        <button type="button" class="tab-btn" onclick="switchShortcodeTab('calcs')">Calcs</button>
                        <button type="button" class="tab-btn" onclick="switchShortcodeTab('user')">User</button>
                    </div>
                    
                    <!-- Products Shortcodes -->
                    <div id="shortcode-tab-products" class="tab-content active">
                        <div class="shortcode-grid">
                            <div class="shortcode-item" onclick="insertShortcode('[product_price sku=&quot;BLEND-10-32&quot;]')">
                                <div class="shortcode-name">product_price</div>
                                <div class="shortcode-desc">Product price</div>
                            </div>
                            <div class="shortcode-item" onclick="insertShortcode('[product_name sku=&quot;BLEND-10-32&quot;]')">
                                <div class="shortcode-name">product_name</div>
                                <div class="shortcode-desc">Product name</div>
                            </div>
                            <div class="shortcode-item" onclick="insertShortcode('[product_servings sku=&quot;BLEND-10-32&quot;]')">
                                <div class="shortcode-name">product_servings</div>
                                <div class="shortcode-desc">10mg servings count</div>
                            </div>
                            <div class="shortcode-item" onclick="insertShortcode('[product_attribute sku=&quot;BLEND-10-32&quot; type=&quot;per_oz&quot;]')">
                                <div class="shortcode-name">product_attribute</div>
                                <div class="shortcode-desc">attribute content (per_oz/total/per_serving)</div>
                            </div>
                            <div class="shortcode-item" onclick="insertShortcode('[product_cost_per_serving sku=&quot;BLEND-10-32&quot;]')">
                                <div class="shortcode-name">product_cost_per_serving</div>
                                <div class="shortcode-desc">Cost per 10mg serving</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Calculation Shortcodes -->
                    <div id="shortcode-tab-calcs" class="tab-content">
                        <div class="shortcode-grid">
                            <div class="shortcode-item" onclick="insertShortcode('[profit_calc sku=&quot;BLEND-10-32&quot; drink_price=&quot;12&quot;]')">
                                <div class="shortcode-name">profit_calc</div>
                                <div class="shortcode-desc">Full profit calculation table</div>
                            </div>
                            <div class="shortcode-item" onclick="insertShortcode('[commission_calc sku=&quot;BLEND-10-32&quot; quantity=&quot;10&quot;]')">
                                <div class="shortcode-name">commission_calc</div>
                                <div class="shortcode-desc">Commission calculator</div>
                            </div>
                            <div class="shortcode-item" onclick="insertShortcode('[revenue_projection bottles=&quot;50&quot; price=&quot;12&quot;]')">
                                <div class="shortcode-name">revenue_projection</div>
                                <div class="shortcode-desc">Revenue projection chart</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Shortcodes -->
                    <div id="shortcode-tab-user" class="tab-content">
                        <div class="shortcode-grid">
                            <div class="shortcode-item" onclick="insertShortcode('[user_first_name]')">
                                <div class="shortcode-name">user_first_name</div>
                                <div class="shortcode-desc">Current user first name</div>
                            </div>
                            <div class="shortcode-item" onclick="insertShortcode('[user_full_name]')">
                                <div class="shortcode-name">user_full_name</div>
                                <div class="shortcode-desc">Current user full name</div>
                            </div>
                            <div class="shortcode-item" onclick="insertShortcode('[user_email]')">
                                <div class="shortcode-name">user_email</div>
                                <div class="shortcode-desc">Current user email</div>
                            </div>
                            <div class="shortcode-item" onclick="insertShortcode('[user_role]')">
                                <div class="shortcode-name">user_role</div>
                                <div class="shortcode-desc">Current user primary role</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main Content Editor -->
            <div class="editor-main">
                <!-- Single Step Content -->
                <div id="single-step-editor" style="display: <?php echo ($walkthrough['is_multi_step'] ?? 0) ? 'none' : 'block'; ?>;">
                    <div class="editor-panel">
                        <h3 style="margin: 0 0 16px 0;">📝 Content</h3>
                        
                        <div class="editor-toolbar">
                            <button type="button" class="toolbar-btn" onclick="formatText('bold')" title="Bold">
                                <strong>B</strong>
                            </button>
                            <button type="button" class="toolbar-btn" onclick="formatText('italic')" title="Italic">
                                <em>I</em>
                            </button>
                            <button type="button" class="toolbar-btn" onclick="formatText('underline')" title="Underline">
                                <u>U</u>
                            </button>
                            <button type="button" class="toolbar-btn" onclick="formatText('insertUnorderedList')" title="Bullet List">
                                • List
                            </button>
                            <button type="button" class="toolbar-btn" onclick="formatText('insertOrderedList')" title="Numbered List">
                                1. List
                            </button>
                            <button type="button" class="toolbar-btn" onclick="insertHTML('<strong style=&quot;color: #ef4444;&quot;>Important</strong>')" title="Highlight">
                                🔴 Highlight
                            </button>
                            <button type="button" class="toolbar-btn" onclick="insertHTML('<code style=&quot;background: #f3f4f6; padding: 2px 6px; border-radius: 3px;&quot;>code</code>')" title="Code">
                                &lt;/&gt; Code
                            </button>
                        </div>
                        
                        <div id="content-editor" 
                             class="visual-editor" 
                             contenteditable="true"
                             data-placeholder="Start typing or use shortcode buttons..."><?php echo $walkthrough['content'] ?? ''; ?></div>
                        
                        <textarea name="content" id="content-hidden" style="display: none;"><?php echo htmlspecialchars($walkthrough['content'] ?? ''); ?></textarea>
                        
                        <div style="margin-top: 16px;">
                            <button type="button" class="btn btn-secondary btn-small" onclick="togglePreview()">
                                👁️ Toggle Preview
                            </button>
                            <button type="button" class="btn btn-secondary btn-small" onclick="viewSource()">
                                &lt;/&gt; View HTML Source
                            </button>
                        </div>
                    </div>
                    
                    <!-- Preview -->
                    <div class="editor-panel" id="preview-panel" style="display: none;">
                        <h3 style="margin: 0 0 16px 0;">👁️ Live Preview</h3>
                        <div class="preview-panel" id="preview-content"></div>
                    </div>
                </div>
                
                <!-- Multi-Step Editor -->
                <div id="multi-step-editor" style="display: <?php echo ($walkthrough['is_multi_step'] ?? 0) ? 'block' : 'none'; ?>;">
                    <div class="editor-panel">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 style="margin: 0;">📑 Tutorial Steps</h3>
                            <button type="button" class="btn btn-success btn-small" onclick="addStep()">
                                ➕ Add Step
                            </button>
                        </div>
                        
                        <div id="steps-container">
                            <?php if (!empty($steps)): ?>
                                <?php foreach ($steps as $index => $step): ?>
                                    <?php renderStepEditor($index, $step); ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; padding: 40px; color: #6b7280;">
                                    <p>No steps yet. Click "Add Step" to get started.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Save Buttons -->
                <div class="editor-panel" style="background: #f9fafb;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <p style="margin: 0; font-size: 13px; color: #6b7280;">
                                💾 Remember to save your changes
                            </p>
                        </div>
                        <div style="display: flex; gap: 12px;">
                            <a href="<?php echo path_with_base('/admin/walkthrough-manager.php'); ?>" class="btn btn-secondary">
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                💾 Save Walkthrough
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<?php
function renderStepEditor($index, $step = null) {
    ?>
    <div class="step-editor" data-step-index="<?php echo $index; ?>">
        <div class="step-header">
            <h4 style="margin: 0;">Step <?php echo $index + 1; ?></h4>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary btn-small" onclick="moveStepUp(<?php echo $index; ?>)">↑</button>
                <button type="button" class="btn btn-secondary btn-small" onclick="moveStepDown(<?php echo $index; ?>)">↓</button>
                <button type="button" class="btn btn-danger btn-small" onclick="removeStep(<?php echo $index; ?>)">🗑️</button>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Step Title</label>
            <input type="text" name="step_title[]" class="form-input" 
                   value="<?php echo htmlspecialchars($step['title'] ?? ''); ?>"
                   placeholder="e.g., Find the Lead Button">
        </div>
        
        <div class="form-group">
            <label class="form-label">Content</label>
            <textarea name="step_content[]" class="form-textarea" rows="4"
                      placeholder="Step content with shortcode support"><?php echo htmlspecialchars($step['content'] ?? ''); ?></textarea>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Position</label>
                <select name="step_position[]" class="form-select">
                    <option value="modal" <?php echo ($step['position'] ?? 'modal') === 'modal' ? 'selected' : ''; ?>>Modal</option>
                    <option value="top" <?php echo ($step['position'] ?? '') === 'top' ? 'selected' : ''; ?>>Top</option>
                    <option value="bottom" <?php echo ($step['position'] ?? '') === 'bottom' ? 'selected' : ''; ?>>Bottom</option>
                    <option value="left" <?php echo ($step['position'] ?? '') === 'left' ? 'selected' : ''; ?>>Left</option>
                    <option value="right" <?php echo ($step['position'] ?? '') === 'right' ? 'selected' : ''; ?>>Right</option>
                </select>
            </div>
            
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Target Element (optional)</label>
                <input type="text" name="step_selector[]" class="form-input" 
                       value="<?php echo htmlspecialchars($step['target_element_selector'] ?? ''); ?>"
                       placeholder=".btn-primary, #myButton">
            </div>
        </div>
    </div>
    <?php
}
?>

<script>
let stepCounter = <?php echo count($steps); ?>;
let currentEditor = null;

// Toggle multi-step mode
function toggleMultiStepMode() {
    const checked = document.getElementById('isMultiStepCheckbox').checked;
    document.getElementById('single-step-editor').style.display = checked ? 'none' : 'block';
    document.getElementById('multi-step-editor').style.display = checked ? 'block' : 'none';
}

// Sync contenteditable to hidden textarea
const contentEditor = document.getElementById('content-editor');
const contentHidden = document.getElementById('content-hidden');

if (contentEditor) {
    contentEditor.addEventListener('input', function() {
        contentHidden.value = this.innerHTML;
        updatePreview();
    });
    
    // Set current editor for shortcode insertion
    contentEditor.addEventListener('focus', function() {
        currentEditor = this;
    });
}

// Format text
function formatText(command) {
    document.execCommand(command, false, null);
    contentEditor.focus();
}

// Insert HTML
function insertHTML(html) {
    document.execCommand('insertHTML', false, html);
    contentEditor.focus();
}

// Insert shortcode
function insertShortcode(shortcode) {
    if (currentEditor && currentEditor.contentEditable === 'true') {
        // Insert into contenteditable
        document.execCommand('insertHTML', false, shortcode);
        currentEditor.focus();
        
        // Update hidden field
        contentHidden.value = contentEditor.innerHTML;
        updatePreview();
    } else {
        // Fallback: insert at end of content editor
        if (contentEditor) {
            const currentHTML = contentEditor.innerHTML;
            contentEditor.innerHTML = currentHTML + ' ' + shortcode;
            contentHidden.value = contentEditor.innerHTML;
            updatePreview();
        }
    }
}

// Preview
function togglePreview() {
    const panel = document.getElementById('preview-panel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    updatePreview();
}

async function updatePreview() {
    const previewContent = document.getElementById('preview-content');
    if (!previewContent) return;
    
    const html = contentEditor.innerHTML;
    
    // Process shortcodes via API
    try {
        const response = await fetch('/required/shortcode-engine.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ content: html })
        });
        
        if (response.ok) {
            const processed = await response.text();
            previewContent.innerHTML = processed;
        } else {
            previewContent.innerHTML = html;
        }
    } catch (error) {
        // Fallback: show raw HTML
        previewContent.innerHTML = html;
    }
}

function viewSource() {
    const html = contentEditor.innerHTML;
    const formatted = html
        .replace(/></g, '>\n<')
        .replace(/\[/g, '\n[')
        .replace(/\]/g, ']\n');
    
    alert('HTML Source:\n\n' + formatted);
}

// Multi-step functions
function addStep() {
    const container = document.getElementById('steps-container');
    
    // Clear empty state if present
    if (container.querySelector('.step-editor') === null) {
        container.innerHTML = '';
    }
    
    const stepHTML = `
        <div class="step-editor" data-step-index="${stepCounter}">
            <div class="step-header">
                <h4 style="margin: 0;">Step ${stepCounter + 1}</h4>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-secondary btn-small" onclick="moveStepUp(${stepCounter})">↑</button>
                    <button type="button" class="btn btn-secondary btn-small" onclick="moveStepDown(${stepCounter})">↓</button>
                    <button type="button" class="btn btn-danger btn-small" onclick="removeStep(${stepCounter})">🗑️</button>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Step Title</label>
                <input type="text" name="step_title[]" class="form-input" placeholder="e.g., Find the Lead Button">
            </div>
            
            <div class="form-group">
                <label class="form-label">Content</label>
                <textarea name="step_content[]" class="form-textarea" rows="4" placeholder="Step content with shortcode support"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Position</label>
                    <select name="step_position[]" class="form-select">
                        <option value="modal">Modal</option>
                        <option value="top">Top</option>
                        <option value="bottom">Bottom</option>
                        <option value="left">Left</option>
                        <option value="right">Right</option>
                    </select>
                </div>
                
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Target Element (optional)</label>
                    <input type="text" name="step_selector[]" class="form-input" placeholder=".btn-primary, #myButton">
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', stepHTML);
    stepCounter++;
    updateStepNumbers();
}

function removeStep(index) {
    const step = document.querySelector(`.step-editor[data-step-index="${index}"]`);
    if (step && confirm('Remove this step?')) {
        step.remove();
        updateStepNumbers();
    }
}

function moveStepUp(index) {
    const step = document.querySelector(`.step-editor[data-step-index="${index}"]`);
    const prev = step?.previousElementSibling;
    
    if (step && prev && prev.classList.contains('step-editor')) {
        step.parentNode.insertBefore(step, prev);
        updateStepNumbers();
    }
}

function moveStepDown(index) {
    const step = document.querySelector(`.step-editor[data-step-index="${index}"]`);
    const next = step?.nextElementSibling;
    
    if (step && next && next.classList.contains('step-editor')) {
        step.parentNode.insertBefore(next, step);
        updateStepNumbers();
    }
}

function updateStepNumbers() {
    const steps = document.querySelectorAll('.step-editor');
    steps.forEach((step, index) => {
        const header = step.querySelector('h4');
        if (header) {
            header.textContent = `Step ${index + 1}`;
        }
    });
}

// Shortcode tabs
function switchShortcodeTab(tabName) {
    // Update buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    // Update content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById(`shortcode-tab-${tabName}`).classList.add('active');
}

// Form submission handler
document.getElementById('walkthroughForm').addEventListener('submit', function(e) {
    // Sync content editor to hidden field
    if (contentEditor) {
        contentHidden.value = contentEditor.innerHTML;
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

