<?php
/**
 * Email Composer Tab - Reusable component for leads.php and accounts.php
 * Provides AI-assisted email composition with send/schedule capabilities
 * 
 * Required variables to be set before including this file:
 * - $emailComposerContext: array with 'type' (lead/account), 'id', 'recipient_email', 'recipient_name', 'company_name'
 */

if (!isset($emailComposerContext) || !is_array($emailComposerContext)) {
    echo '<div class="error">Email composer context not provided</div>';
    return;
}

$ctx = $emailComposerContext;
$entityType = $ctx['type'] ?? 'lead'; // 'lead' or 'account'
$entityId = intval($ctx['id'] ?? 0);
$recipientEmail = $ctx['recipient_email'] ?? '';
$recipientName = $ctx['recipient_name'] ?? '';
$companyName = $ctx['company_name'] ?? '';

// Load agent info for signature
$agentId = current_user_id();
$agentInfo = ['name' => 'Sales Representative', 'email' => 'sample-003@example.com', 'phone' => ''];
try {
    $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone FROM users WHERE id = ?');
    $stmt->execute([$agentId]);
    $agent = $stmt->fetch();
    if ($agent) {
        $agentInfo['name'] = trim($agent['first_name'] . ' ' . $agent['last_name']);
        $agentInfo['email'] = $agent['email'];
        $agentInfo['phone'] = $agent['phone'] ?? '';
    }
} catch (Throwable $e) {}

// Load drafts and sent emails (including automated workflow emails)
$drafts = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, recipient_email, subject, status, is_ai_assisted, scheduled_for, created_at, sent_at,
               open_count, click_count, last_opened_at, last_clicked_at, followup_parent_id, sendgrid_message_id
        FROM agent_emails 
        WHERE agent_id = ? AND {$entityType}_id = ? 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([$agentId, $entityId]);
    $drafts = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log("Email composer: Failed to load emails - " . $e->getMessage());
}

// Load templates
$templates = [];
try {
    $stmt = $pdo->query("SELECT id, slug, name, subject FROM email_templates WHERE (scope = 'sales_agent' OR category = 'sales') AND is_active = 1 ORDER BY name");
    $templates = $stmt->fetchAll();
} catch (Throwable $e) {}
?>

<style>
.email-composer-container {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-top: 20px;
}

.email-composer-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #f1f5f9;
}

.email-toolbar {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    padding: 12px;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-radius: 8px;
    flex-wrap: wrap;
}

.email-field {
    margin-bottom: 16px;
}

.email-field label {
    display: block;
    font-weight: 600;
    color: #374151;
    margin-bottom: 6px;
    font-size: 14px;
}

.email-field input,
.email-field textarea,
.email-field select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
}

.email-field textarea {
    min-height: 250px;
    resize: vertical;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
}

.editor-mode-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 12px;
    border-bottom: 2px solid #e5e7eb;
}

.editor-mode-tab {
    padding: 10px 20px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-weight: 500;
    color: #64748b;
    transition: all 0.2s;
}

.editor-mode-tab.active {
    color: #0369a1;
    border-bottom-color: #0369a1;
}

.editor-mode-tab:hover:not(.active) {
    color: #334155;
    background: #f8fafc;
}

.editor-container {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
}

.editor-panel {
    display: none;
    min-height: 350px;
}

.editor-panel.active {
    display: block;
}

.visual-editor {
    padding: 20px;
    background: #ffffff;
    min-height: 350px;
    overflow-y: auto;
    outline: none;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
}

.visual-editor:focus {
    background: #fafbfc;
}

.code-editor {
    width: 100%;
    min-height: 350px;
    padding: 16px;
    border: none;
    font-family: 'Courier New', Consolas, Monaco, monospace;
    font-size: 13px;
    line-height: 1.5;
    background: #1e293b;
    color: #e2e8f0;
    resize: vertical;
}

.visual-toolbar {
    display: flex;
    gap: 4px;
    padding: 8px;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
    flex-wrap: wrap;
}

.visual-toolbar button {
    padding: 6px 12px;
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    color: #374151;
    transition: all 0.2s;
}

.visual-toolbar button:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.visual-toolbar button.active {
    background: #0369a1;
    color: #ffffff;
    border-color: #0369a1;
}

.email-preview {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 20px;
    background: #ffffff;
    min-height: 300px;
    overflow-y: auto;
}

.email-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.ai-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.draft-list {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f9fafb;
}

.draft-item {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
    cursor: pointer;
    transition: all 0.2s;
}

.draft-item:hover {
    background: #f3f4f6;
}

.draft-item:last-child {
    border-bottom: none;
}

.email-status {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: none;
}

.email-status.success {
    background: #dcfce7;
    color: #14532d;
    border: 1px solid #bbf7d0;
}

.email-status.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.email-status.info {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

.ai-generating {
    display: none;
    text-align: center;
    padding: 40px;
}

.ai-generating.active {
    display: block;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid #e5e7eb;
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

/* AI Writing Assistant Card Styles */
.ai-writing-card {
    background: #ffffff;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.ai-writing-card.expanded {
    border-color: #93c5fd;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.ai-writing-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
    cursor: pointer;
    border-bottom: 1px solid transparent;
    transition: all 0.3s ease;
}

.ai-writing-card.expanded .ai-writing-header {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    border-bottom-color: #93c5fd;
}

.ai-writing-header:hover {
    background: linear-gradient(135deg, #e0f2fe, #bfdbfe);
}

.ai-writing-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ai-writing-header-left h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #1e40af;
}

.ai-icon {
    color: #3b82f6;
    flex-shrink: 0;
}

.ai-optional-badge {
    background: #f1f5f9;
    color: #64748b;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    margin-left: 8px;
    flex-shrink: 0;
}

.ai-chevron {
    transition: transform 0.3s ease;
    color: #3b82f6;
}

.ai-writing-card.expanded .ai-chevron {
    transform: rotate(180deg);
}

.ai-writing-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.4s ease-out;
}

.ai-writing-card.expanded .ai-writing-body {
    max-height: 2000px;
    padding: 20px;
}

.btn-generate-ai {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 14px 24px;
    background: linear-gradient(135deg, #3b82f6, #60a5fa);
    color: #ffffff;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.btn-generate-ai:hover {
    background: linear-gradient(135deg, #2563eb, #3b82f6);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    transform: translateY(-1px);
}

.btn-generate-ai:active {
    transform: translateY(0);
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3);
}

.btn-generate-ai:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

@media (max-width: 768px) {
    .ai-writing-header {
        padding: 14px 16px;
    }
    
    .ai-writing-header-left h4 {
        font-size: 15px;
    }
    
    .ai-writing-body {
        padding: 16px;
    }
    
    .btn-generate-ai {
        padding: 12px 20px;
        font-size: 14px;
    }
}
</style>

<div class="email-composer-container" id="emailComposer">
    <div class="email-composer-header">
        <h3 style="margin: 0; display: flex; align-items: center; gap: 10px;">
            <span>📧</span> Email Composer
            <span class="ai-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
                AI Powered
            </span>
        </h3>
        <button type="button" class="btn small secondary" onclick="toggleEmailComposer()">
            <span id="emailComposerToggleText">Hide</span>
        </button>
    </div>
    
    <div id="emailComposerContent">
        <!-- Status Messages -->
        <div id="emailStatus" class="email-status"></div>
        
        <!-- AI Generation Loading State -->
        <div id="aiGenerating" class="ai-generating">
            <div class="spinner"></div>
            <p style="margin: 16px 0 0; color: #64748b; font-size: 14px;">AI is writing your email...</p>
        </div>
        
        <!-- Email Form -->
        <div id="emailForm">
            <!-- Toolbar -->
            <div class="email-toolbar">
                <select id="templateSelector" onchange="handleTemplateSelect(this.value)" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-width: 200px;">
                    <option value="">📄 Load Template...</option>
                    <?php foreach ($templates as $tpl): ?>
                    <option value="<?php echo $tpl['id']; ?>" data-slug="<?php echo htmlspecialchars($tpl['slug']); ?>"><?php echo htmlspecialchars($tpl['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn small secondary" onclick="clearEmailForm()">🗑️ Clear</button>
            </div>
            
            <!-- AI Writing Assistant Card (New Design) -->
            <div id="aiWritingAssistantCard" class="ai-writing-card" style="margin-bottom: 20px;">
                <div class="ai-writing-header" onclick="toggleAiWritingCard()">
                    <div class="ai-writing-header-left">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="ai-icon">
                            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                            <path d="M2 17l10 5 10-5"/>
                            <path d="M2 12l10 5 10-5"/>
                        </svg>
                        <h4>✨ AI Writing Assistant</h4>
                        <span class="ai-optional-badge">Optional</span>
                    </div>
                    <svg class="ai-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
                
                <div class="ai-writing-body">
                    <div style="background: #dbeafe; border: 1px solid #93c5fd; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                        <div style="display: flex; align-items: start; gap: 10px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="#1e40af" style="flex-shrink: 0; margin-top: 2px;">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="16" x2="12" y2="12" stroke="white" stroke-width="2"/>
                                <line x1="12" y1="8" x2="12.01" y2="8" stroke="white" stroke-width="2"/>
                            </svg>
                            <div style="flex: 1;">
                                <strong style="color: #1e40af; font-size: 13px;">How This Works:</strong>
                                <p style="margin: 6px 0 0; font-size: 13px; color: #1e40af; line-height: 1.5;">
                                    Configure your preferences below, then click "Generate Email with AI" to have AI write a personalized email based on your settings.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- AI Customization Accordion -->
                    <div id="aiCustomOptions"></div>
                    
                    <!-- Generate Button -->
                    <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                        <button type="button" class="btn-generate-ai" onclick="generateEmailWithAI()" id="btnGenerateAI">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="margin-right: 8px;">
                                <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                                <path d="M2 17l10 5 10-5"/>
                                <path d="M2 12l10 5 10-5"/>
                            </svg>
                            Generate Email with AI
                        </button>
                        <p style="margin: 10px 0 0; font-size: 12px; color: #64748b; text-align: center;">
                            This will replace any existing content in the editor
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Recipient -->
            <div class="email-field">
                <label>To</label>
                <input type="email" id="emailRecipient" value="<?php echo htmlspecialchars($recipientEmail); ?>" placeholder="sample-030@example.com" required>
            </div>
            
            <div class="email-field">
                <label>Recipient Name (optional)</label>
                <input type="text" id="emailRecipientName" value="<?php echo htmlspecialchars($recipientName); ?>" placeholder="John Doe">
            </div>
            
            <!-- Subject -->
            <div class="email-field">
                <label>Subject</label>
                <input type="text" id="emailSubject" placeholder="Following up on our conversation" required>
            </div>
            
            <!-- Body with Dual-Mode Editor -->
            <div class="email-field">
                <label style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Message</span>
                    <span style="font-size: 12px; color: #6b7280; font-weight: 400;">
                        💡 Header & footer automatically included | Edit content only
                    </span>
                </label>
                
                <!-- Editor Mode Tabs -->
                <div class="editor-mode-tabs">
                    <button type="button" class="editor-mode-tab active" onclick="switchEditorMode('visual')" id="visualTab">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        Visual Editor
                    </button>
                    <button type="button" class="editor-mode-tab" onclick="switchEditorMode('code')" id="codeTab">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                            <polyline points="16 18 22 12 16 6"/>
                            <polyline points="8 6 2 12 8 18"/>
                        </svg>
                        Code Editor
                    </button>
                    <button type="button" class="editor-mode-tab" onclick="switchEditorMode('preview')" id="previewTab">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        Preview Only
                    </button>
                </div>
                
                <!-- Editor Container -->
                <div class="editor-container">
                    <!-- Visual Editor Panel -->
                    <div id="visualEditorPanel" class="editor-panel active">
                        <!-- Visual Formatting Toolbar -->
                        <div class="visual-toolbar">
                            <button type="button" onclick="formatText('bold')" title="Bold">
                                <strong>B</strong>
                            </button>
                            <button type="button" onclick="formatText('italic')" title="Italic">
                                <em>I</em>
                            </button>
                            <button type="button" onclick="formatText('underline')" title="Underline">
                                <u>U</u>
                            </button>
                            <span style="width: 1px; height: 20px; background: #d1d5db; margin: 0 4px;"></span>
                            <button type="button" onclick="insertLink()" title="Insert Link">
                                🔗 Link
                            </button>
                            <button type="button" onclick="insertList('ul')" title="Bullet List">
                                • List
                            </button>
                            <button type="button" onclick="insertList('ol')" title="Numbered List">
                                1. List
                            </button>
                            <span style="width: 1px; height: 20px; background: #d1d5db; margin: 0 4px;"></span>
                            <button type="button" onclick="insertButton()" title="Insert CTA Button">
                                🔘 Button
                            </button>
                        </div>
                        
                        <!-- Locked Header Preview -->
                        <div style="padding: 12px; background: linear-gradient(135deg, #065f46, #059669); border-bottom: 2px solid #047857;">
                            <div style="text-align: center;">
                                <div style="font-size: 20px; font-weight: 700; color: #ffffff; margin-bottom: 4px;">Product</div>
                                <div style="font-size: 12px; color: rgba(255,255,255,0.9);">Premium Wholesale Product</div>
                            </div>
                            <div style="margin-top: 8px; padding: 6px 10px; background: rgba(255,255,255,0.15); border-radius: 6px; text-align: center;">
                                <span style="font-size: 11px; color: rgba(255,255,255,0.8);">🔒 Standard header - Always included</span>
                            </div>
                        </div>
                        
                        <!-- ContentEditable Visual Editor (Content Area Only) -->
                        <div id="visualEditor" class="visual-editor" contenteditable="true" 
                             placeholder="Start typing your email here..."
                             onblur="syncVisualToCode()"
                             oninput="handleVisualInput()"
                             style="border-top: 2px dashed #e5e7eb; border-bottom: 2px dashed #e5e7eb; min-height: 280px;">
                        </div>
                        
                        <!-- Locked Footer Preview -->
                        <div style="padding: 16px 12px; background: #f8fafc; border-top: 1px solid #e5e7eb;">
                            <div style="text-align: center;">
                                <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Product | Premium Wholesale Supplier</div>
                                <div style="font-size: 11px; color: #94a3b8; margin-bottom: 6px;">Email: sample-003@example.com</div>
                                <div style="font-size: 10px; color: #cbd5e1;">© 2025 Product. All rights reserved.</div>
                            </div>
                            <div style="margin-top: 8px; padding: 6px 10px; background: rgba(148,163,184,0.1); border-radius: 6px; text-align: center;">
                                <span style="font-size: 11px; color: #64748b;">🔒 Standard footer - Always included</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Code Editor Panel -->
                    <div id="codeEditorPanel" class="editor-panel">
                        <textarea id="emailBody" class="code-editor" placeholder="<!-- HTML code will appear here -->"></textarea>
                    </div>
                    
                    <!-- Preview Only Panel -->
                    <div id="previewEditorPanel" class="editor-panel">
                        <div id="emailPreviewContent" class="email-preview" style="border: none; min-height: 350px;"></div>
                    </div>
                </div>
            </div>
            
            <!-- Workflow Selector -->
            <div id="workflowSelectorContainer" style="margin-top: 20px;"></div>
            
            <!-- Actions -->
            <div class="email-actions">
                <button type="button" class="btn" style="background: linear-gradient(135deg, #059669, #10b981);" onclick="sendEmailNow()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                    Send Now
                </button>
                <button type="button" class="btn" style="background: linear-gradient(135deg, #0369a1, #0ea5e9);" onclick="scheduleEmail()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 6v6l4 2"/>
                    </svg>
                    Schedule Send
                </button>
                <button type="button" class="btn secondary" onclick="saveDraft()">Save Draft</button>
            </div>
            
            <!-- Agent Email Stats -->
            <div style="margin-top: 24px; padding: 16px; background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border: 1px solid #bae6fd; border-radius: 12px;">
                <h4 style="margin: 0 0 12px; font-size: 15px; color: #0369a1; font-weight: 600;">📊 Your Email Performance</h4>
                <div id="agentEmailStats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 12px;">
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #0369a1;" id="statSent">-</div>
                        <div style="font-size: 11px; color: #64748b;">Sent</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #10b981;" id="statOpened">-</div>
                        <div style="font-size: 11px; color: #64748b;">Opened</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #f59e0b;" id="statClicks">-</div>
                        <div style="font-size: 11px; color: #64748b;">Clicks</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #ef4444;" id="statBounced">-</div>
                        <div style="font-size: 11px; color: #64748b;">Bounced</div>
                    </div>
                </div>
                <div style="margin-top: 8px; font-size: 11px; color: #0c4a6e; text-align: center;">
                    Updates in real-time from SendGrid
                </div>
            </div>
            
            <!-- Saved Drafts & Sent Emails -->
            <?php if (!empty($drafts)): ?>
            <div style="margin-top: 24px;">
                <h4 style="margin: 0 0 12px; font-size: 15px; color: #374151; font-weight: 600;">
                    Recent Emails 
                    <span style="font-size: 11px; color: #64748b; font-weight: 400;">(Showing last 20)</span>
                </h4>
                <div class="draft-list" id="recentEmailsList">
                    <?php foreach ($drafts as $draft): ?>
                    <div class="draft-item" onclick="loadDraft(<?php echo $draft['id']; ?>)">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #1f2937; font-size: 14px;">
                                    <?php if (!empty($draft['followup_parent_id'])): ?>
                                        <span style="color: #f59e0b;" title="Automated follow-up email">🔄</span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($draft['subject']); ?>
                                    <?php if ($draft['is_ai_assisted']): ?>
                                        <span class="ai-badge" style="margin-left: 6px; font-size: 10px;">AI</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                    To: <?php echo htmlspecialchars($draft['recipient_email']); ?>
                                </div>
                                <?php if ($draft['status'] === 'sent' || $draft['status'] === 'delivered'): ?>
                                <div style="font-size: 11px; margin-top: 6px; display: flex; gap: 12px; flex-wrap: wrap;">
                                    <?php 
                                    $openCount = intval($draft['open_count'] ?? 0);
                                    $clickCount = intval($draft['click_count'] ?? 0);
                                    ?>
                                    <?php if ($openCount > 0): ?>
                                        <span style="color: #059669; font-weight: 600;">
                                            👁️ Opened <?php echo $openCount; ?>x
                                            <?php if (!empty($draft['last_opened_at'])): ?>
                                                <span style="font-weight: 400; color: #6b7280;">
                                                    • Last: <?php echo date('M j, g:iA', strtotime($draft['last_opened_at'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">📭 Not opened yet</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($clickCount > 0): ?>
                                        <span style="color: #0ea5e9; font-weight: 600;">
                                            🖱️ <?php echo $clickCount; ?> click<?php echo $clickCount > 1 ? 's' : ''; ?>
                                            <?php if (!empty($draft['last_clicked_at'])): ?>
                                                <span style="font-weight: 400; color: #6b7280;">
                                                    • <?php echo date('M j, g:iA', strtotime($draft['last_clicked_at'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div style="text-align: right;">
                                <span class="chip chip-status" style="font-size: 11px; padding: 4px 8px; border-radius: 12px; font-weight: 600;
                                    <?php if ($draft['status'] === 'bounced'): ?>background:#fee2e2;color:#991b1b;
                                    <?php elseif ($draft['status'] === 'delivered'): ?>background:#dcfce7;color:#166534;
                                    <?php elseif ($draft['status'] === 'sent'): ?>background:#dbeafe;color:#1e40af;
                                    <?php elseif ($draft['status'] === 'draft'): ?>background:#f3f4f6;color:#6b7280;
                                    <?php endif; ?>">
                                    <?php 
                                    $statusDisplay = ucfirst($draft['status']);
                                    if ($draft['status'] === 'sent' && intval($draft['open_count'] ?? 0) > 0) {
                                        $statusDisplay = '✅ Opened';
                                    } elseif ($draft['status'] === 'bounced') {
                                        $statusDisplay = '❌ Bounced';
                                    } elseif ($draft['status'] === 'delivered') {
                                        $statusDisplay = '✓ Delivered';
                                    }
                                    echo $statusDisplay;
                                    ?>
                                </span>
                                <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">
                                    <?php 
                                    $displayDate = !empty($draft['sent_at']) ? $draft['sent_at'] : $draft['created_at'];
                                    echo date('M j, g:iA', strtotime($displayDate)); 
                                    ?>
                                </div>
                                <?php if (!empty($draft['sendgrid_message_id'])): ?>
                                <div style="font-size: 10px; color: #10b981; margin-top: 2px;" title="Tracking active">
                                    📊
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Include Workflow Selector Script -->
<script src="<?=asset_url('/assets/email-workflow-selector.js')?>"></script>

<!-- AI Assistant Modal -->
<div id="aiAssistantModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 8000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #ffffff; border-radius: 16px; width: 600px; max-width: 92vw; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.2);">
        <div style="background: linear-gradient(135deg, #3b82f6, #60a5fa); padding: 24px; border-radius: 16px 16px 0 0;">
            <h3 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
                AI Email Assistant
            </h3>
            <p style="margin: 8px 0 0; color: rgba(255,255,255,0.9); font-size: 14px;">
                Describe what you want to write, and AI will draft it for you
            </p>
        </div>
        
        <div style="padding: 24px;">
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; color: #374151; margin-bottom: 8px;">
                    What would you like to write?
                </label>
                <textarea id="aiPrompt" rows="4" placeholder="Example: Write a follow-up email thanking them for the demo and asking if they're ready to place their first order. Mention our bulk discount pricing." style="width: 100%; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-family: inherit;"></textarea>
            </div>
            
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                <p style="margin: 0; font-size: 13px; color: #0369a1; line-height: 1.5;">
                    💡 <strong>Tips:</strong> Be specific about the context and tone. Mention any key details you want included (pricing, products, next steps, etc.)
                </p>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" class="btn secondary" onclick="closeAIAssistant()">Cancel</button>
                <button type="button" class="btn" style="background: linear-gradient(135deg, #3b82f6, #60a5fa);" onclick="generateWithAI()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="margin-right: 4px;">
                        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                    </svg>
                    Generate Email
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Schedule Modal -->
<div id="scheduleModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 8000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #ffffff; border-radius: 16px; width: 480px; max-width: 92vw; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.2);">
        <h3 style="margin: 0 0 16px; font-size: 20px; font-weight: 700; color: #1f2937;">
            Schedule Email Delivery
        </h3>
        
        <div style="margin-bottom: 16px;">
            <label style="display: block; font-weight: 600; color: #374151; margin-bottom: 8px;">
                Send Date & Time
            </label>
            <input type="datetime-local" id="scheduleDateTime" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
        </div>
        
        <div style="background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
            <p style="margin: 0; font-size: 13px; color: #92400e;">
                ⏰ Emails are processed hourly. Your email will be sent within 60 minutes of the scheduled time.
            </p>
        </div>
        
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <button type="button" class="btn secondary" onclick="closeScheduleModal()">Cancel</button>
            <button type="button" class="btn" style="background: #0369a1;" onclick="confirmSchedule()">Schedule Email</button>
        </div>
    </div>
</div>

<script>
// Email Composer State
const EmailComposer = {
    currentEmailId: null,
    currentGenerationId: null,
    isAiAssisted: false,
    currentMode: 'visual',
    
    init() {
        // Initialize visual editor with default content (header/footer shown separately)
        const visualEditor = document.getElementById('visualEditor');
        if (visualEditor && !visualEditor.innerHTML.trim()) {
            visualEditor.innerHTML = `
                <p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">
                    Hi <strong><?php echo htmlspecialchars($recipientName); ?></strong>,
                </p>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">
                    Start typing your message here...
                </p>
                <p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">
                    Best regards,<br>
                    <strong><?php echo htmlspecialchars($agentInfo['name']); ?></strong><br>
                    <span style="color:#64748b;font-size:14px;">Sales Representative, Product</span>
                </p>
            `;
        }
        // Always update preview to show complete email with header/footer
        this.updatePreview();
    },
    
    showStatus(message, type = 'info') {
        const status = document.getElementById('emailStatus');
        status.className = 'email-status ' + type;
        status.textContent = message;
        status.style.display = 'block';
        setTimeout(() => {
            status.style.display = 'none';
        }, 5000);
    },
    
    updatePreview() {
        const subject = document.getElementById('emailSubject').value;
        const codeEditor = document.getElementById('emailBody');
        const preview = document.getElementById('emailPreviewContent');
        
        let body = codeEditor ? codeEditor.value : '';
        
        // Always get from visual editor in visual/preview mode and wrap in template
        if (this.currentMode === 'visual' || this.currentMode === 'preview') {
            const visualEditor = document.getElementById('visualEditor');
            if (visualEditor) {
                body = this.wrapInTemplate(visualEditor.innerHTML);
            }
        }
        
        if (preview) {
            preview.innerHTML = `
                <div style="font-size: 14px; color: #6b7280; margin-bottom: 12px; padding: 8px; background: #f9fafb; border-radius: 4px;">
                    <strong>Subject:</strong> ${escapeHtml(subject)}
                </div>
                <div style="margin-top: 12px;">
                    ${body || '<p style="color:#9ca3af;text-align:center;padding:40px;">No content yet</p>'}
                </div>
            `;
        }
    },
    
    wrapInTemplate(content) {
        // Wrap content in Product branded template
        return `<table role="presentation" style="width:100%;border-collapse:collapse;background:#ffffff;max-width:600px;margin:0 auto;">
<tr>
<td style="background:linear-gradient(135deg,#065f46,#059669);padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">
<h1 style="margin:0;color:#ffffff;font-size:28px;font-weight:700;letter-spacing:-0.5px;">Product</h1>
<p style="margin:8px 0 0;color:rgba(255,255,255,0.9);font-size:14px;font-weight:500;">Premium Wholesale Product</p>
</td>
</tr>
<tr>
<td style="padding:40px;">
${content}
</td>
</tr>
<tr>
<td style="background:#f8fafc;padding:24px 40px;border-radius:0 0 12px 12px;border-top:1px solid #e5e7eb;">
<p style="margin:0 0 12px;font-size:13px;color:#64748b;text-align:center;">
<strong>Product</strong> | Premium Wholesale Supplier
</p>
<p style="margin:0 0 12px;font-size:12px;color:#94a3b8;text-align:center;">
Email: <a href="mailto:sample-003@example.com" style="color:#059669;text-decoration:none;">sample-003@example.com</a>
</p>
<p style="margin:0;font-size:11px;color:#cbd5e1;text-align:center;">
© 2025 Product. All rights reserved.
</p>
</td>
</tr>
</table>`;
    }
};

// Editor Mode Switching
function switchEditorMode(mode) {
    EmailComposer.currentMode = mode;
    
    // Sync content before switching
    if (mode === 'code') {
        syncVisualToCode();
    } else if (mode === 'visual') {
        syncCodeToVisual();
    }
    
    // Update tabs
    document.querySelectorAll('.editor-mode-tab').forEach(tab => tab.classList.remove('active'));
    document.getElementById(mode + 'Tab').classList.add('active');
    
    // Update panels
    document.querySelectorAll('.editor-panel').forEach(panel => panel.classList.remove('active'));
    document.getElementById(mode + 'EditorPanel').classList.add('active');
    
    // Update preview
    EmailComposer.updatePreview();
}

function syncVisualToCode() {
    const visualEditor = document.getElementById('visualEditor');
    const codeEditor = document.getElementById('emailBody');
    
    if (visualEditor && codeEditor) {
        const content = visualEditor.innerHTML.trim();
        codeEditor.value = EmailComposer.wrapInTemplate(content);
    }
}

function syncCodeToVisual() {
    const codeEditor = document.getElementById('emailBody');
    const visualEditor = document.getElementById('visualEditor');
    
    if (codeEditor && visualEditor) {
        let html = codeEditor.value;
        
        // Extract just the content from template if present
        const match = html.match(/<td style="padding:40px;">\s*([\s\S]*?)\s*<\/td>\s*<\/tr>\s*<tr>\s*<td style="background:#f8fafc/);
        if (match && match[1]) {
            visualEditor.innerHTML = match[1].trim();
        } else {
            // No template wrapper, use as-is
            visualEditor.innerHTML = html;
        }
    }
}

function handleVisualInput() {
    // Debounced sync to code
    if (EmailComposer.syncTimer) clearTimeout(EmailComposer.syncTimer);
    EmailComposer.syncTimer = setTimeout(() => {
        syncVisualToCode();
        EmailComposer.updatePreview();
    }, 500);
}

// Visual Editor Formatting Functions
function formatText(command) {
    document.execCommand(command, false, null);
    document.getElementById('visualEditor').focus();
}

function insertLink() {
    const url = prompt('Enter URL:', 'https://');
    if (url && url !== 'https://') {
        document.execCommand('createLink', false, url);
    }
    document.getElementById('visualEditor').focus();
}

function insertList(type) {
    if (type === 'ul') {
        document.execCommand('insertUnorderedList', false, null);
    } else {
        document.execCommand('insertOrderedList', false, null);
    }
    document.getElementById('visualEditor').focus();
}

function insertButton() {
    const text = prompt('Button text:', 'Click Here');
    const url = prompt('Button URL:', '<?php echo absolute_url('/shop/'); ?>');
    
    if (text && url) {
        const buttonHtml = `<table role="presentation" style="margin:20px 0;"><tr><td align="center"><a href="${escapeHtml(url)}" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#065f46,#059669);color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:16px;box-shadow:0 2px 8px rgba(5,150,105,0.3);">${escapeHtml(text)}</a></td></tr></table>`;
        
        document.execCommand('insertHTML', false, buttonHtml);
    }
    document.getElementById('visualEditor').focus();
}

function toggleEmailComposer() {
    const content = document.getElementById('emailComposerContent');
    const toggle = document.getElementById('emailComposerToggleText');
    if (content.style.display === 'none') {
        content.style.display = 'block';
        toggle.textContent = 'Hide';
    } else {
        content.style.display = 'none';
        toggle.textContent = 'Show';
    }
}

function openAIAssistant() {
    document.getElementById('aiAssistantModal').style.display = 'block';
    document.getElementById('aiPrompt').focus();
}

function closeAIAssistant() {
    document.getElementById('aiAssistantModal').style.display = 'none';
    document.getElementById('aiPrompt').value = '';
}

async function generateWithAI() {
    const prompt = document.getElementById('aiPrompt').value.trim();
    if (!prompt) {
        alert('Please describe what you want to write');
        return;
    }
    
    closeAIAssistant();
    
    // Switch to code mode for AI generation
    switchEditorMode('code');
    
    // Show loading state
    document.getElementById('emailForm').style.display = 'none';
    document.getElementById('aiGenerating').classList.add('active');
    
    try {
        const response = await fetch('<?php echo api_url('ai-email-composer.php?action=generate'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'fetch'
            },
            body: JSON.stringify({
                prompt: prompt,
                context: {
                    <?php echo $entityType; ?>_id: <?php echo $entityId; ?>,
                    recipient_name: '<?php echo addslashes($recipientName); ?>',
                    company_name: '<?php echo addslashes($companyName); ?>'
                }
            })
        });
        
        const data = await response.json();
        
        if (!data.ok) {
            throw new Error(data.error || 'AI generation failed');
        }
        
        // Fill in generated content
        if (data.subject) {
            document.getElementById('emailSubject').value = data.subject;
        }
        
        // AI body contains ONLY content paragraphs (no header/footer)
        // Load directly into Visual editor
        const visualEditor = document.getElementById('visualEditor');
        if (visualEditor) {
            visualEditor.innerHTML = data.body;
        }
        
        // Also update code editor with wrapped version
        syncVisualToCode();
        
        EmailComposer.isAiAssisted = true;
        EmailComposer.currentGenerationId = data.generation_id;
        
        // Switch to visual mode for easy editing
        setTimeout(() => {
            switchEditorMode('visual');
            EmailComposer.showStatus('✨ AI generated your email! Edit in Visual mode (header/footer auto-included).', 'success');
        }, 500);
        
    } catch (error) {
        EmailComposer.showStatus('AI generation failed: ' + error.message, 'error');
    } finally {
        document.getElementById('aiGenerating').classList.remove('active');
        document.getElementById('emailForm').style.display = 'block';
    }
}

async function saveDraft() {
    // Sync visual to code first
    if (EmailComposer.currentMode === 'visual') {
        syncVisualToCode();
    }
    
    const recipientEmail = document.getElementById('emailRecipient').value.trim();
    const recipientName = document.getElementById('emailRecipientName').value.trim();
    const subject = document.getElementById('emailSubject').value.trim();
    const body = document.getElementById('emailBody').value.trim();
    
    if (!recipientEmail || !subject || !body) {
        EmailComposer.showStatus('Please fill in recipient, subject, and message', 'error');
        return false;
    }
    
    try {
        const response = await fetch('<?php echo api_url('ai-email-composer.php?action=save_draft'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'fetch'
            },
            body: JSON.stringify({
                <?php echo $entityType; ?>_id: <?php echo $entityId; ?>,
                recipient_email: recipientEmail,
                recipient_name: recipientName,
                subject: subject,
                body_html: body,
                is_ai_assisted: EmailComposer.isAiAssisted,
                ai_contribution_level: EmailComposer.isAiAssisted ? 'full' : 'none',
                generation_id: EmailComposer.currentGenerationId,
                workflow_selection: window.EmailWorkflowSelector ? EmailWorkflowSelector.getSelectionPayload(true) : null
            })
        });
        
        const data = await response.json();
        
        if (!data.ok) {
            throw new Error(data.error || 'Failed to save draft');
        }
        
        EmailComposer.currentEmailId = data.email_id;
        EmailComposer.showStatus('✅ Draft saved successfully', 'success');
        
        return true;
        
    } catch (error) {
        EmailComposer.showStatus('Failed to save draft: ' + error.message, 'error');
        return false;
    }
}

async function sendEmailNow() {
    if (!confirm('Send this email now?')) {
        return;
    }
    
    // Save draft first if not already saved
    if (!EmailComposer.currentEmailId) {
        await saveDraft();
        if (!EmailComposer.currentEmailId) {
            return; // saveDraft failed
        }
    }
    
    EmailComposer.showStatus('Sending email...', 'info');
    
    try {
        // Get selected workflow ID if any
        const workflowId = window.EmailWorkflowSelector ? EmailWorkflowSelector.getSelectedWorkflowId() : null;
        
        const response = await fetch('<?php echo api_url('ai-email-composer.php?action=send'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'fetch'
            },
            body: JSON.stringify({
                email_id: EmailComposer.currentEmailId,
                workflow_id: workflowId,
                workflow_selection: window.EmailWorkflowSelector ? EmailWorkflowSelector.getSelectionPayload(true) : null
            })
        });
        
        const data = await response.json();
        
        if (!data.ok) {
            throw new Error(data.error || 'Failed to send email');
        }
        
        // Show success message with workflow info
        let successMsg = '✅ Email sent successfully!';
        if (data.workflow_scheduled) {
            successMsg += ' Follow-up workflow activated!';
        }
        EmailComposer.showStatus(successMsg, 'success');
        
        // Clear form and reload after 2 seconds
        setTimeout(() => {
            clearEmailForm();
            if (window.EmailWorkflowSelector) {
                EmailWorkflowSelector.clear();
            }
            location.reload();
        }, 2000);
        
    } catch (error) {
        EmailComposer.showStatus('Failed to send: ' + error.message, 'error');
    }
}

async function scheduleEmail() {
    // Sync visual to code first
    if (EmailComposer.currentMode === 'visual') {
        syncVisualToCode();
    }
    
    // Validate fields
    const recipientEmail = document.getElementById('emailRecipient').value.trim();
    const subject = document.getElementById('emailSubject').value.trim();
    const body = document.getElementById('emailBody').value.trim();
    
    if (!recipientEmail || !subject || !body) {
        EmailComposer.showStatus('Please fill in recipient, subject, and message before scheduling', 'error');
        return;
    }
    
    // Save draft first if not already saved
    if (!EmailComposer.currentEmailId) {
        const saved = await saveDraft();
        if (!saved || !EmailComposer.currentEmailId) {
            EmailComposer.showStatus('Please save draft first before scheduling', 'error');
            return;
        }
        // Wait a moment for save to complete
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    
    // Set default to tomorrow at 9 AM
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(9, 0, 0, 0);
    document.getElementById('scheduleDateTime').value = tomorrow.toISOString().slice(0, 16);
    
    document.getElementById('scheduleModal').style.display = 'block';
}

function closeScheduleModal() {
    document.getElementById('scheduleModal').style.display = 'none';
}

async function confirmSchedule() {
    const scheduledFor = document.getElementById('scheduleDateTime').value;
    if (!scheduledFor) {
        alert('Please select a date and time');
        return;
    }
    
    try {
        const response = await fetch('<?php echo api_url('ai-email-composer.php?action=schedule'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'fetch'
            },
            body: JSON.stringify({
                email_id: EmailComposer.currentEmailId,
                scheduled_for: scheduledFor,
                workflow_selection: window.EmailWorkflowSelector ? EmailWorkflowSelector.getSelectionPayload(true) : null
            })
        });
        
        const data = await response.json();
        
        if (!data.ok) {
            throw new Error(data.error || 'Failed to schedule email');
        }
        
        closeScheduleModal();
        EmailComposer.showStatus('✅ Email scheduled for ' + new Date(scheduledFor).toLocaleString(), 'success');
        
        setTimeout(() => location.reload(), 2000);
        
    } catch (error) {
        EmailComposer.showStatus('Failed to schedule: ' + error.message, 'error');
    }
}

async function handleTemplateSelect(templateId) {
    if (!templateId) return;
    
    // Simply load template as-is
    await loadTemplate(templateId);
}

async function loadTemplate(templateId) {
    if (!templateId) return;
    
    EmailComposer.showStatus('Loading template...', 'info');
    
    try {
        const response = await fetch('<?php echo api_url('ai-email-composer.php'); ?>?action=load_template&template_id=' + encodeURIComponent(templateId), {
            method: 'GET',
            headers: { 
                'X-Requested-With': 'fetch',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error('Server returned ' + response.status);
        }
        
        const data = await response.json();
        
        if (!data.ok || !data.template) {
            throw new Error(data.error || 'Failed to load template');
        }
        
        const tpl = data.template;
        document.getElementById('emailSubject').value = tpl.subject || '';
        
        // Load content directly into visual editor (templates are content-only now)
        const visualEditor = document.getElementById('visualEditor');
        if (visualEditor) {
            visualEditor.innerHTML = tpl.html_body || '';
        }
        
        // Sync to code editor
        syncVisualToCode();
        
        EmailComposer.isAiAssisted = false;
        EmailComposer.currentEmailId = null;
        
        // Switch to visual mode for editing
        switchEditorMode('visual');
        EmailComposer.showStatus('✅ Template loaded - edit and customize as needed', 'success');
        
    } catch (error) {
        console.error('Template load error:', error);
        EmailComposer.showStatus('Failed to load template: ' + error.message, 'error');
    } finally {
        // Reset selector
        setTimeout(() => {
            document.getElementById('templateSelector').value = '';
        }, 100);
    }
}

function insertHeader() {
    // Header is always included - this is now a no-op with helpful message
    EmailComposer.showStatus('ℹ️ Header is automatically included in all emails', 'info');
}

function insertFooter() {
    // Footer is always included - this is now a no-op with helpful message
    EmailComposer.showStatus('ℹ️ Footer is automatically included in all emails', 'info');
}

async function loadDraft(draftId) {
    try {
        const response = await fetch('<?php echo api_url('ai-email-composer.php?action=get_email'); ?>&id=' + draftId, {
            headers: { 'X-Requested-With': 'fetch' }
        });
        
        const data = await response.json();
        
        if (!data.ok || !data.email) {
            throw new Error('Failed to load draft');
        }
        
        const email = data.email;
        document.getElementById('emailRecipient').value = email.recipient_email || '';
        document.getElementById('emailRecipientName').value = email.recipient_name || '';
        document.getElementById('emailSubject').value = email.subject || '';
        document.getElementById('emailBody').value = email.body_html || '';
        
        // Sync to visual editor and switch to visual mode
        syncCodeToVisual();
        switchEditorMode('visual');
        
        EmailComposer.currentEmailId = email.id;
        EmailComposer.isAiAssisted = email.is_ai_assisted == 1;
        EmailComposer.updatePreview();
        
        EmailComposer.showStatus('Draft loaded - Edit in Visual mode', 'info');
        
    } catch (error) {
        EmailComposer.showStatus('Failed to load draft: ' + error.message, 'error');
    }
}

function clearEmailForm() {
    document.getElementById('emailRecipient').value = '<?php echo addslashes($recipientEmail); ?>';
    document.getElementById('emailRecipientName').value = '<?php echo addslashes($recipientName); ?>';
    document.getElementById('emailSubject').value = '';
    document.getElementById('emailBody').value = '';
    document.getElementById('templateSelector').value = '';
    
    // Reset visual editor to default content (just the body, header/footer always shown separately)
    const visualEditor = document.getElementById('visualEditor');
    if (visualEditor) {
        visualEditor.innerHTML = `
            <p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">
                Hi <strong><?php echo htmlspecialchars($recipientName); ?></strong>,
            </p>
            <p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">
                Start typing your message here...
            </p>
            <p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">
                Best regards,<br>
                <strong><?php echo htmlspecialchars($agentInfo['name']); ?></strong><br>
                <span style="color:#64748b;font-size:14px;">Sales Representative, Product</span>
            </p>
        `;
    }
    
    EmailComposer.currentEmailId = null;
    EmailComposer.currentGenerationId = null;
    EmailComposer.isAiAssisted = false;
    
    // Return to visual mode
    switchEditorMode('visual');
    EmailComposer.updatePreview();
}

// Toggle AI Writing Assistant Card
function toggleAiWritingCard() {
    const card = document.getElementById('aiWritingAssistantCard');
    if (card) {
        const isExpanded = card.classList.toggle('expanded');
        
        // Initialize accordion if not already done
        const container = document.getElementById('aiCustomOptions');
        if (isExpanded && container && !container.hasAttribute('data-accordion-initialized')) {
            createAiCustomizationAccordion();
            container.setAttribute('data-accordion-initialized', 'true');
        }
    }
}

// Generate Email with AI (new button-driven approach)
async function generateEmailWithAI() {
    // Build AI prompt based on customization settings
    let prompt = 'Write a professional sales email about our product concentrates. Reference ACTUAL products from our database when relevant. Use real SKUs and accurate pricing. Remember we sell concentrates/extracts, NOT product format varieties. Be friendly, helpful, and include a clear call-to-action.';
    
    // Collect customizations
    const customizations = [];
    
    // Sales Special
    if (document.getElementById('aiEnableSpecial') && document.getElementById('aiEnableSpecial').checked) {
        const percent = document.getElementById('aiSpecialPercent').value || '15';
        const desc = document.getElementById('aiSpecialDesc').value || 'limited time offer';
        customizations.push(`Include a sales special: ${percent}% discount (${desc}). Highlight this in a colorful gold/yellow box with emphasis.`);
    }
    
    // Re-theme
    if (document.getElementById('aiEnableRetheme') && document.getElementById('aiEnableRetheme').checked) {
        customizations.push('Be creative with colors and layout! Use gradients, colored sections, and visual interest. Change background colors and text colors creatively while maintaining the Product brand feel.');
    }
    
    // Tone
    if (document.getElementById('aiEnableTone') && document.getElementById('aiEnableTone').checked) {
        const tone = document.getElementById('aiTonePreset').value;
        const toneMap = {
            'professional': 'Use a professional and polished tone',
            'friendly': 'Use a friendly, casual, and warm tone',
            'formal': 'Use a formal and traditional business tone',
            'enthusiastic': 'Use an enthusiastic, energetic, and exciting tone',
            'consultative': 'Use a consultative, expert, and advisory tone',
            'urgent': 'Use an urgent, time-sensitive tone with clear deadlines'
        };
        customizations.push(toneMap[tone] || 'Use a professional tone');
    }
    
    // Custom Recipe
    if (document.getElementById('aiEnableRecipe') && document.getElementById('aiEnableRecipe').checked) {
        const recipeName = document.getElementById('aiRecipeName').value || 'Product Energy product';
        const recipeDesc = document.getElementById('aiRecipeDesc').value || '';
        customizations.push(`Include a Product drink recipe. Recipe name: "${recipeName}". ${recipeDesc ? 'Ingredients/flavor: ' + recipeDesc : 'Create a delicious recipe with tropical flavors.'} Format as a beautiful recipe card with a colorful background, ingredient list, and simple preparation instructions. Make it visually appealing with emoji and styled sections.`);
    }
    
    // Add customizations to prompt
    if (customizations.length > 0) {
        prompt += '\n\nCUSTOMIZATIONS:\n' + customizations.map((c, i) => `${i + 1}. ${c}`).join('\n');
    }
    
    // Use the existing AI generation function
    document.getElementById('aiPrompt').value = prompt;
    await generateWithAI();
    
    // Collapse the AI card after generation
    setTimeout(() => {
        const card = document.getElementById('aiWritingAssistantCard');
        if (card) card.classList.remove('expanded');
    }, 1000);
}

function createAiCustomizationAccordion() {
    const container = document.getElementById('aiCustomOptions');
    if (!container) return;
    
    // Load saved preferences
    const savedPrefs = JSON.parse(localStorage.getItem('ai_customization_prefs') || '{}');
    
    const sections = [
        {
            id: 'ai_discounts',
            title: 'Discounts & Offers',
            icon: '💰',
            badge: savedPrefs.specialEnabled ? 'ON' : '',
            expanded: savedPrefs.specialEnabled || false,
            theme: 'blue',
            content: () => `
                <div style="padding: 4px;">
                    <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; cursor: pointer; margin-bottom: 12px;">
                        <input type="checkbox" id="aiEnableSpecial" onchange="toggleAiOption('special', this.checked)" ${savedPrefs.specialEnabled ? 'checked' : ''} style="width: 18px; height: 18px; flex-shrink: 0;">
                        <span style="font-weight: 600; color: #92400e; flex: 1; min-width: 0;">Include Sales Special / Discount</span>
                    </label>
                    
                    <div id="specialDetails" style="display: ${savedPrefs.specialEnabled ? 'block' : 'none'}; padding: 16px; background: #ffffff; border-radius: 8px; border: 1px solid #fde68a;">
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; color: #78350f; margin-bottom: 6px;">Discount Percentage</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="number" id="aiSpecialPercent" placeholder="%" min="5" max="50" step="5" value="${savedPrefs.specialPercent || 15}" style="flex: 1; padding: 10px; border: 1px solid #fbbf24; border-radius: 6px; font-size: 14px; font-weight: 600;">
                                <div style="display: flex; gap: 4px;">
                                    <button onclick="document.getElementById('aiSpecialPercent').value=10" type="button" style="padding: 8px 12px; background: #fff; border: 1px solid #fbbf24; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #92400e;">10%</button>
                                    <button onclick="document.getElementById('aiSpecialPercent').value=15" type="button" style="padding: 8px 12px; background: #fff; border: 1px solid #fbbf24; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #92400e;">15%</button>
                                    <button onclick="document.getElementById('aiSpecialPercent').value=20" type="button" style="padding: 8px 12px; background: #fff; border: 1px solid #fbbf24; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #92400e;">20%</button>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 600; color: #78350f; margin-bottom: 6px;">Offer Description</label>
                            <input type="text" id="aiSpecialDesc" placeholder="e.g., 'New customer special' or 'Limited time offer'" value="${savedPrefs.specialDesc || ''}" style="width: 100%; padding: 10px; border: 1px solid #fbbf24; border-radius: 6px; font-size: 14px;">
                            <div style="font-size: 11px; color: #92400e; margin-top: 6px;">
                                💡 This will be highlighted in a gold box with emphasis
                            </div>
                        </div>
                    </div>
                </div>
            `
        },
        {
            id: 'ai_styling',
            title: 'Visual Styling & Colors',
            icon: '🎨',
            badge: savedPrefs.rethemeEnabled ? 'ON' : '',
            expanded: savedPrefs.rethemeEnabled || false,
            theme: 'purple',
            content: () => `
                <div style="padding: 4px;">
                    <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: #f3e8ff; border: 1px solid #e9d5ff; border-radius: 8px; cursor: pointer; margin-bottom: 12px;">
                        <input type="checkbox" id="aiEnableRetheme" onchange="toggleAiOption('retheme', this.checked)" ${savedPrefs.rethemeEnabled ? 'checked' : ''} style="width: 18px; height: 18px; flex-shrink: 0;">
                        <span style="font-weight: 600; color: #6b21a8; flex: 1; min-width: 0;">Enable Creative Re-theming</span>
                    </label>
                    
                    <div style="background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 8px; padding: 16px;">
                        <h5 style="margin: 0 0 12px; font-size: 13px; font-weight: 600; color: #6b21a8;">What this does:</h5>
                        <ul style="margin: 0; padding-left: 20px; color: #7c3aed; font-size: 13px; line-height: 1.8;">
                            <li>AI can use vibrant color gradients and backgrounds</li>
                            <li>Creative layout with highlight boxes and sections</li>
                            <li>Custom font sizes and emphasis styling</li>
                            <li>Colored dividers and accent elements</li>
                        </ul>
                        <div style="margin-top: 12px; padding: 10px; background: rgba(168, 85, 247, 0.1); border-radius: 6px; font-size: 12px; color: #6b21a8;">
                            ⚠️ Ensures readability - light backgrounds get dark text, dark backgrounds get light text
                        </div>
                    </div>
                </div>
            `
        },
        {
            id: 'ai_tone',
            title: 'Tone & Voice',
            icon: '🎭',
            badge: savedPrefs.toneEnabled ? savedPrefs.tonePreset || 'Custom' : '',
            expanded: savedPrefs.toneEnabled || false,
            theme: 'green',
            content: () => `
                <div style="padding: 4px;">
                    <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: #dcfce7; border: 1px solid #bbf7d0; border-radius: 8px; cursor: pointer; margin-bottom: 12px;">
                        <input type="checkbox" id="aiEnableTone" onchange="toggleAiOption('tone', this.checked)" ${savedPrefs.toneEnabled ? 'checked' : ''} style="width: 18px; height: 18px; flex-shrink: 0;">
                        <span style="font-weight: 600; color: #166534; flex: 1; min-width: 0;">Customize Writing Tone</span>
                    </label>
                    
                    <div id="toneOptions" style="display: ${savedPrefs.toneEnabled ? 'block' : 'none'};">
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #166534; margin-bottom: 8px;">Select Tone Style</label>
                        <select id="aiTonePreset" onchange="saveAiPreference('tonePreset', this.value)" style="width: 100%; padding: 12px; border: 1px solid #86efac; border-radius: 8px; background: #ffffff; font-size: 14px; font-weight: 500; color: #166534;">
                            <option value="professional" ${savedPrefs.tonePreset === 'professional' ? 'selected' : ''}>💼 Professional & Polished</option>
                            <option value="friendly" ${savedPrefs.tonePreset === 'friendly' ? 'selected' : ''}>😊 Friendly & Casual</option>
                            <option value="formal" ${savedPrefs.tonePreset === 'formal' ? 'selected' : ''}>🎩 Formal & Traditional</option>
                            <option value="enthusiastic" ${savedPrefs.tonePreset === 'enthusiastic' ? 'selected' : ''}>🎉 Enthusiastic & Energetic</option>
                            <option value="consultative" ${savedPrefs.tonePreset === 'consultative' ? 'selected' : ''}>🧠 Consultative & Expert</option>
                            <option value="urgent" ${savedPrefs.tonePreset === 'urgent' ? 'selected' : ''}>⏰ Urgent & Time-Sensitive</option>
                        </select>
                        
                        <div style="margin-top: 12px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; font-size: 11px;">
                            <div style="padding: 8px; background: #f0fdf4; border-radius: 6px; border: 1px solid #bbf7d0;">
                                <strong style="color: #166534;">Professional:</strong>
                                <div style="color: #15803d;">"We're pleased to offer..."</div>
                            </div>
                            <div style="padding: 8px; background: #f0fdf4; border-radius: 6px; border: 1px solid #bbf7d0;">
                                <strong style="color: #166534;">Friendly:</strong>
                                <div style="color: #15803d;">"Hey! Check this out..."</div>
                            </div>
                        </div>
                    </div>
                </div>
            `
        },
        {
            id: 'ai_recipe',
            title: 'Content Additions',
            icon: '🍹',
            badge: savedPrefs.recipeEnabled ? 'Recipe' : '',
            expanded: savedPrefs.recipeEnabled || false,
            theme: 'blue',
            content: () => `
                <div style="padding: 4px;">
                    <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: #dbeafe; border: 1px solid #93c5fd; border-radius: 8px; cursor: pointer; margin-bottom: 12px;">
                        <input type="checkbox" id="aiEnableRecipe" onchange="toggleAiOption('recipe', this.checked)" ${savedPrefs.recipeEnabled ? 'checked' : ''} style="width: 18px; height: 18px; flex-shrink: 0;">
                        <span style="font-weight: 600; color: #1e40af; flex: 1; min-width: 0;">Include Product Drink Recipe</span>
                    </label>
                    
                    <div id="recipeOptions" style="display: ${savedPrefs.recipeEnabled ? 'block' : 'none'}; padding: 16px; background: #ffffff; border-radius: 8px; border: 1px solid #93c5fd;">
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; color: #1e40af; margin-bottom: 6px;">Recipe Name</label>
                            <input type="text" id="aiRecipeName" placeholder="e.g., 'Tropical Energy Boost' or 'Morning Focus Blend'" value="${savedPrefs.recipeName || ''}" style="width: 100%; padding: 10px; border: 1px solid #93c5fd; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 600; color: #1e40af; margin-bottom: 6px;">Ingredients / Flavor Profile (Optional)</label>
                            <textarea id="aiRecipeDesc" rows="2" placeholder="e.g., 'with mango, coconut water, and lime'" style="width: 100%; padding: 10px; border: 1px solid #93c5fd; border-radius: 6px; font-size: 13px; resize: vertical;">${savedPrefs.recipeDesc || ''}</textarea>
                            <div style="font-size: 11px; color: #1e40af; margin-top: 6px; display: flex; align-items: start; gap: 6px;">
                                <span>💡</span>
                                <span>AI will create a beautiful recipe card with a colorful background, ingredient list, and step-by-step instructions</span>
                            </div>
                        </div>
                    </div>
                </div>
            `
        }
    ];
    
    // Load accordion CSS/JS if not loaded
    if (!document.getElementById('product-accordion-css')) {
        const link = document.createElement('link');
        link.id = 'product-accordion-css';
        link.rel = 'stylesheet';
        link.href = '<?=asset_url('/assets/product-accordion.css')?>';
        document.head.appendChild(link);
    }
    
    if (!document.getElementById('product-accordion-js')) {
        const script = document.createElement('script');
        script.id = 'product-accordion-js';
        script.src = '<?=asset_url('/assets/product-accordion.js')?>';
        document.head.appendChild(script);
        
        // Wait for script to load
        script.onload = () => {
            if (typeof ProductAccordion !== 'undefined') {
                ProductAccordion.create('aiCustomOptions', sections, {
                    persist: true,
                    storageKey: 'email_composer_ai_customizations',
                    allowMultiple: true,
                    showExpandAll: false
                });
            }
        };
    } else {
        // Script already loaded
        if (typeof ProductAccordion !== 'undefined') {
            ProductAccordion.create('aiCustomOptions', sections, {
                persist: true,
                storageKey: 'email_composer_ai_customizations',
                allowMultiple: true,
                showExpandAll: false
            });
        }
    }
}

function toggleAiOption(option, enabled) {
    // Save preference
    saveAiPreference(option + 'Enabled', enabled);
    
    // Show/hide details
    if (option === 'special') {
        const details = document.getElementById('specialDetails');
        if (details) details.style.display = enabled ? 'block' : 'none';
    } else if (option === 'tone') {
        const options = document.getElementById('toneOptions');
        if (options) options.style.display = enabled ? 'block' : 'none';
    } else if (option === 'recipe') {
        const options = document.getElementById('recipeOptions');
        if (options) options.style.display = enabled ? 'block' : 'none';
    }
    
    // Update badge
    updateAccordionBadge(option, enabled);
}

function saveAiPreference(key, value) {
    const prefs = JSON.parse(localStorage.getItem('ai_customization_prefs') || '{}');
    prefs[key] = value;
    localStorage.setItem('ai_customization_prefs', JSON.stringify(prefs));
}

function updateAccordionBadge(option, enabled) {
    const sectionMap = {
        'special': 'ai_discounts',
        'retheme': 'ai_styling',
        'tone': 'ai_tone',
        'recipe': 'ai_recipe'
    };
    
    const sectionId = sectionMap[option];
    if (!sectionId) return;
    
    const section = document.querySelector(`[data-section-id="${sectionId}"]`);
    if (!section) return;
    
    const badge = section.querySelector('.product-accordion-badge');
    if (badge) {
        if (enabled) {
            badge.textContent = 'ON';
            badge.style.display = 'inline-block';
        } else {
            badge.textContent = '';
            badge.style.display = 'none';
        }
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load agent email statistics
async function loadAgentStats() {
    try {
        const response = await fetch('<?php echo api_url('ai-email-composer.php?action=get_agent_stats'); ?>', {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.ok && data.stats) {
            const stats = data.stats;
            document.getElementById('statSent').textContent = stats.total_sent || 0;
            document.getElementById('statOpened').textContent = stats.opened || 0;
            document.getElementById('statClicks').textContent = stats.total_clicks || 0;
            document.getElementById('statBounced').textContent = stats.bounced || 0;
        }
    } catch (error) {
        console.error('Failed to load agent stats:', error);
    }
}

// Listen for input changes to update preview
document.addEventListener('DOMContentLoaded', () => {
    EmailComposer.init();
    
    // Initialize workflow selector
    if (window.EmailWorkflowSelector) {
        EmailWorkflowSelector.render('workflowSelectorContainer', function(workflowId) {
            console.log('Workflow selected:', workflowId);
        });
    }
    
    // Load agent stats on page load
    loadAgentStats();
    
    // Refresh stats every 30 seconds
    setInterval(loadAgentStats, 30000);
    
    const subjectInput = document.getElementById('emailSubject');
    const codeEditor = document.getElementById('emailBody');
    
    if (subjectInput) {
        subjectInput.addEventListener('input', () => EmailComposer.updatePreview());
    }
    if (codeEditor) {
        codeEditor.addEventListener('input', () => {
            if (EmailComposer.currentMode === 'code') {
                EmailComposer.updatePreview();
            }
        });
        codeEditor.addEventListener('blur', () => {
            if (EmailComposer.currentMode === 'code') {
                syncCodeToVisual();
            }
        });
    }
    
    // Add placeholder styling for visual editor
    const style = document.createElement('style');
    style.textContent = `
        .visual-editor:empty:before {
            content: attr(placeholder);
            color: #9ca3af;
            font-style: italic;
        }
    `;
    document.head.appendChild(style);
});

// Close modals on background click
document.addEventListener('click', (e) => {
    if (e.target.id === 'aiAssistantModal') {
        closeAIAssistant();
    }
    if (e.target.id === 'scheduleModal') {
        closeScheduleModal();
    }
});
</script>

