<?php
$SEO = [
    'title' => 'Case Details — Legal Portal',
    'description' => 'View case details and collaborate with your legal team.',
    'type' => 'article'
];

require_once __DIR__ . '/../../../required/header.php';
require_once __DIR__ . '/../../../required/legal-permissions.php';
require_once __DIR__ . '/../../../required/legal-case-service.php';

start_session();
$currentUser = current_user_id();

if (!$currentUser || !user_has_role($currentUser, 'legal')) {
    if (user_has_role($currentUser, 'admin')) {
        // Redirect admin to main case view
        $caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        redirect(path_with_base('/admin/legal/case-view.php?id=' . $caseId));
    }
    redirect(path_with_base('/admin/'));
}

$pdo = db();
$service = new LegalCaseService($pdo);

$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($caseId <= 0) {
    redirect(path_with_base('/admin/legal/portal/'));
}

// Permission check
if (!legal_user_can_access_case($currentUser, $caseId)) {
    redirect(path_with_base('/admin/legal/portal/'));
}

// Fetch case
$stmt = $pdo->prepare('SELECT * FROM legal_cases WHERE id = ? LIMIT 1');
$stmt->execute([$caseId]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    redirect(path_with_base('/admin/legal/portal/'));
}

$documents = $service->listDocuments($caseId);
$teamMembers = $pdo->prepare('SELECT t.*, u.first_name, u.last_name, u.email FROM legal_case_team t JOIN users u ON u.id = t.user_id WHERE t.case_id = ? ORDER BY t.created_at ASC');
$teamMembers->execute([$caseId]);
$team = $teamMembers->fetchAll(PDO::FETCH_ASSOC);

$notes = $pdo->prepare('SELECT n.*, u.first_name, u.last_name FROM legal_case_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.case_id = ? AND n.visibility IN ("team","all") ORDER BY n.pinned DESC, n.created_at DESC LIMIT 50');
$notes->execute([$caseId]);
$caseNotes = $notes->fetchAll(PDO::FETCH_ASSOC);

$activeTab = $_GET['tab'] ?? 'summary';

$breadcrumbs = [
    ['label' => 'Legal Portal', 'url' => path_with_base('/admin/legal/portal/')],
    ['label' => $case['title'], 'url' => null]
];
?>
<?php include __DIR__ . '/../partials/breadcrumb.php'; ?>

<style>
.portal-case-view {
    max-width: 1280px;
    margin: 40px auto 80px auto;
    padding: 0 24px;
}
.case-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: #fff;
    border-radius: 14px;
    padding: 28px 32px;
    margin-bottom: 24px;
    box-shadow: 0 12px 28px rgba(30,58,138,0.22);
}
.case-header h1 {
    margin: 0 0 8px 0;
    font-size: 2rem;
}
.case-header .meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    font-size: 0.95rem;
    opacity: 0.9;
}
.case-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 28px;
    flex-wrap: wrap;
}
.case-tab {
    padding: 12px 20px;
    border: none;
    background: transparent;
    color: #475569;
    font-weight: 600;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    text-decoration: none;
}
.case-tab.active {
    color: #1d4ed8;
    border-bottom-color: #1d4ed8;
}
.case-tab:hover {
    color: #1d4ed8;
}
.case-content {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 28px;
}
.tab-pane {
    display: none;
}
.tab-pane.active {
    display: block;
}
.evidence-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 18px;
}
.evidence-item {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    background: #f8fafc;
}
.evidence-item h3 {
    margin: 0 0 8px 0;
    font-size: 1rem;
    color: #0f172a;
}
.team-list {
    display: grid;
    gap: 14px;
}
.team-member {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.note-item {
    border-left: 3px solid #3b82f6;
    background: #f8fafc;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 14px;
}
.note-item.pinned {
    background: #fef3c7;
    border-left-color: #fbbf24;
}
.empty-state {
    text-align: center;
    padding: 40px;
    color: #64748b;
}
</style>

<div class="portal-case-view">
    <div class="case-header">
        <h1><?php echo sanitize($case['title']); ?></h1>
        <div class="meta">
            <span><strong>Status:</strong> <?php echo strtoupper($case['status']); ?></span>
            <span><strong>Jurisdiction:</strong> <?php echo sanitize($case['jurisdiction'] ?: 'Not specified'); ?></span>
            <span><strong>Updated:</strong> <?php echo date('M j, Y', strtotime($case['updated_at'])); ?></span>
        </div>
    </div>
    
    <nav class="case-tabs">
        <a class="case-tab <?php echo $activeTab === 'summary' ? 'active' : ''; ?>" href="?id=<?php echo $caseId; ?>&tab=summary">Summary</a>
        <a class="case-tab <?php echo $activeTab === 'evidence' ? 'active' : ''; ?>" href="?id=<?php echo $caseId; ?>&tab=evidence">Evidence (<?php echo count($documents); ?>)</a>
        <a class="case-tab <?php echo $activeTab === 'team' ? 'active' : ''; ?>" href="?id=<?php echo $caseId; ?>&tab=team">Team (<?php echo count($team); ?>)</a>
        <a class="case-tab <?php echo $activeTab === 'notes' ? 'active' : ''; ?>" href="?id=<?php echo $caseId; ?>&tab=notes">Notes (<?php echo count($caseNotes); ?>)</a>
    </nav>
    
    <div class="case-content">
        <div class="tab-pane <?php echo $activeTab === 'summary' ? 'active' : ''; ?>">
            <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;">
                <div>
                    <h2 style="margin-top:0;">Case Summary</h2>
                    <?php if ($case['summary']): ?>
                        <p style="line-height:1.8;color:#1f2937;"><?php echo nl2br(sanitize($case['summary'])); ?></p>
                    <?php else: ?>
                        <p style="color:#64748b;font-style:italic;">No summary provided.</p>
                    <?php endif; ?>
                    
                    <h3 style="margin-top:32px;">Regulatory Information</h3>
                    <ul style="color:#0f172a;">
                        <li><strong>Retention Policy:</strong> <?php echo sanitize($case['retention_policy']); ?></li>
                        <li><strong>Retention Expires:</strong> <?php echo $case['retention_expires_at'] ? date('M j, Y', strtotime($case['retention_expires_at'])) : 'Never'; ?></li>
                        <li><strong>Regulatory Hold:</strong> <?php echo $case['regulatory_hold'] ? 'Yes' : 'No'; ?></li>
                    </ul>
                </div>
                
                <!-- Case Shoutbox (same as admin) -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;height:fit-content;max-height:600px;display:flex;flex-direction:column;">
                    <h3 style="margin:0 0 16px 0;font-size:1.1rem;color:#0f172a;">Team Shoutbox</h3>
                    <div id="shoutbox-messages" style="flex:1;overflow-y:auto;max-height:400px;margin-bottom:16px;display:flex;flex-direction:column;gap:12px;">
                        <!-- Messages load here -->
                    </div>
                    <form id="shoutbox-form" style="display:flex;flex-direction:column;gap:10px;">
                        <textarea id="shoutbox-input" placeholder="Type message... Use @username or @filename to mention" style="width:100%;min-height:80px;padding:10px;border:1px solid #cbd5e1;border-radius:6px;font-size:0.9rem;resize:vertical;"></textarea>
                        <button type="submit" style="padding:10px 16px;background:#0d47a1;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Send</button>
                    </form>
                    <p style="margin:12px 0 0 0;font-size:0.8rem;color:#94a3b8;">Tip: @mention users or @evidence files</p>
                </div>
            </div>
        </div>
        
        <div class="tab-pane <?php echo $activeTab === 'evidence' ? 'active' : ''; ?>">
            <h2 style="margin-top:0;">Evidence & Documents</h2>
            <p><a href="<?php echo path_with_base('/admin/legal/evidence-locker/index.php?case_id=' . $caseId); ?>" style="color:#1d4ed8;font-weight:600;">🗃️ Open Evidence Locker</a></p>
            
            <?php if (empty($documents)): ?>
                <div class="empty-state"><strong>No documents uploaded yet.</strong></div>
            <?php else: ?>
                <div class="evidence-grid">
                    <?php foreach ($documents as $doc): ?>
                        <article class="evidence-item">
                            <h3><?php echo sanitize($doc['display_name']); ?></h3>
                            <p style="margin:6px 0;font-size:0.9rem;color:#475569;"><?php echo sanitize($doc['document_type']); ?> • <?php echo number_format($doc['download_count']); ?> downloads</p>
                            <?php if ($doc['description']): ?>
                                <p style="margin:10px 0 0 0;font-size:0.9rem;color:#64748b;"><?php echo sanitize($doc['description']); ?></p>
                            <?php endif; ?>
                            <a href="<?php echo path_with_base($doc['storage_path']); ?>" download style="color:#1d4ed8;font-size:0.9rem;font-weight:600;">Download</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="tab-pane <?php echo $activeTab === 'team' ? 'active' : ''; ?>">
            <h2 style="margin-top:0;">Legal Team</h2>
            
            <?php if (empty($team)): ?>
                <div class="empty-state"><strong>No team members assigned.</strong></div>
            <?php else: ?>
                <div class="team-list">
                    <?php foreach ($team as $member): ?>
                        <div class="team-member">
                            <div>
                                <strong style="color:#0f172a;"><?php echo sanitize($member['first_name'] . ' ' . $member['last_name']); ?></strong>
                                <div style="font-size:0.9rem;color:#64748b;margin-top:4px;"><?php echo sanitize($member['email']); ?> • <?php echo sanitize($member['role']); ?></div>
                            </div>
                            <span style="font-size:0.85rem;color:#94a3b8;">Joined <?php echo date('M j, Y', strtotime($member['created_at'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="tab-pane <?php echo $activeTab === 'notes' ? 'active' : ''; ?>">
            <h2 style="margin-top:0;">Case Notes</h2>
            
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:24px;">
                <h3 style="margin:0 0 12px 0;font-size:1.1rem;">Add Note</h3>
                <form id="add-note-form">
                    <textarea id="note-body" placeholder="Enter case note..." style="width:100%;min-height:100px;padding:12px;border:1px solid #cbd5e1;border-radius:8px;margin-bottom:12px;"></textarea>
                    <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px;">
                        <select id="note-visibility" style="padding:10px;border:1px solid #cbd5e1;border-radius:6px;">
                            <option value="team">Team Only</option>
                            <option value="all">All (Public)</option>
                        </select>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" id="note-pinned">
                            <span>Pin to top</span>
                        </label>
                    </div>
                    <button type="submit" style="padding:10px 20px;background:#0d47a1;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Post Note</button>
                </form>
            </div>
            
            <?php if (empty($caseNotes)): ?>
                <div class="empty-state"><strong>No notes recorded.</strong></div>
            <?php else: ?>
                <?php foreach ($caseNotes as $note): ?>
                    <div class="note-item <?php echo $note['pinned'] ? 'pinned' : ''; ?>">
                        <?php if ($note['pinned']): ?><span style="font-size:0.85rem;color:#92400e;font-weight:600;">📌 PINNED</span><?php endif; ?>
                        <p style="margin:0 0 8px 0;color:#0f172a;line-height:1.7;"><?php echo nl2br(sanitize($note['note_body'])); ?></p>
                        <div style="font-size:0.85rem;color:#64748b;">
                            <strong><?php echo sanitize(($note['first_name'] ?: 'System') . ' ' . ($note['last_name'] ?: '')); ?></strong> • <?php echo date('M j, Y g:ia', strtotime($note['created_at'])); ?> • Visibility: <?php echo strtoupper($note['visibility']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const caseId = <?php echo $caseId; ?>;

// Define path_with_base helper for JavaScript
function path_with_base(path) {
    const base = window.__BASE_PREFIX || '';
    if (path.startsWith('/')) {
        return base + path;
    }
    return base + '/' + path;
}

// Shoutbox (same implementation as admin)
let lastNoteId = 0;
const shoutboxMessages = document.getElementById('shoutbox-messages');
const shoutboxForm = document.getElementById('shoutbox-form');
const shoutboxInput = document.getElementById('shoutbox-input');
let mentionDropdown = null;
let mentionResults = [];
let selectedMentionIndex = -1;

function renderMessage(note) {
    const div = document.createElement('div');
    div.style.cssText = 'background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;';
    
    const author = (note.first_name || 'System') + ' ' + (note.last_name || '');
    const time = new Date(note.created_at).toLocaleString();
    
    let body = note.note_body;
    body = body.replace(/@([a-zA-Z0-9_\-\.]+)/g, '<span style="color:#1d4ed8;font-weight:600;">@$1</span>');
    
    div.innerHTML = `
        <div style="font-size:0.85rem;color:#64748b;margin-bottom:6px;"><strong>${author}</strong> • ${time}</div>
        <div style="color:#0f172a;line-height:1.6;">${body}</div>
    `;
    return div;
}

function createMentionDropdown() {
    if (mentionDropdown) return mentionDropdown;
    const dropdown = document.createElement('div');
    dropdown.style.cssText = 'position:absolute;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.15);max-height:240px;overflow-y:auto;z-index:1000;display:none;min-width:280px;';
    document.body.appendChild(dropdown);
    mentionDropdown = dropdown;
    return dropdown;
}

function showMentionDropdown(results, inputEl) {
    const dropdown = createMentionDropdown();
    dropdown.innerHTML = '';
    mentionResults = results;
    selectedMentionIndex = -1;
    
    if (results.length === 0) {
        dropdown.style.display = 'none';
        return;
    }
    
    results.forEach((item, idx) => {
        const div = document.createElement('div');
        div.style.cssText = 'padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;';
        div.innerHTML = `
            <span style="font-size:1.2rem;">${item.type === 'user' ? '👤' : '📄'}</span>
            <div>
                <div style="font-weight:600;color:#0f172a;">${item.display}</div>
                <div style="font-size:0.8rem;color:#64748b;">@${item.mention}</div>
            </div>
        `;
        
        div.addEventListener('mouseenter', () => {
            selectedMentionIndex = idx;
            updateDropdownSelection();
        });
        
        div.addEventListener('click', () => {
            insertMention(item.mention, inputEl);
        });
        
        dropdown.appendChild(div);
    });
    
    const rect = inputEl.getBoundingClientRect();
    dropdown.style.left = rect.left + 'px';
    dropdown.style.top = (rect.bottom + 4) + 'px';
    dropdown.style.display = 'block';
    updateDropdownSelection();
}

function updateDropdownSelection() {
    if (!mentionDropdown) return;
    const items = mentionDropdown.querySelectorAll('div[style*="padding"]');
    items.forEach((item, idx) => {
        if (idx === selectedMentionIndex) {
            item.style.background = '#eff6ff';
        } else {
            item.style.background = '#fff';
        }
    });
}

function hideMentionDropdown() {
    if (mentionDropdown) {
        mentionDropdown.style.display = 'none';
    }
}

function insertMention(mention, inputEl) {
    const val = inputEl.value;
    const cursorPos = inputEl.selectionStart;
    const beforeCursor = val.substring(0, cursorPos);
    const lastAtIndex = beforeCursor.lastIndexOf('@');
    
    if (lastAtIndex !== -1) {
        const before = val.substring(0, lastAtIndex);
        const after = val.substring(cursorPos);
        inputEl.value = before + '@' + mention + ' ' + after;
        inputEl.selectionStart = inputEl.selectionEnd = before.length + mention.length + 2;
    }
    
    hideMentionDropdown();
    inputEl.focus();
}

async function fetchMentions(query) {
    try {
        const res = await fetch(path_with_base(`/api/legal/get-mentions.php?case_id=${caseId}&query=${encodeURIComponent(query)}`));
        const json = await res.json();
        return json.ok ? json.results : [];
    } catch (err) {
        console.error('Mention fetch error:', err);
        return [];
    }
}

async function loadShoutbox() {
    try {
        const res = await fetch(path_with_base(`/api/legal/shoutbox-fetch.php?case_id=${caseId}&since=${lastNoteId}`));
        const json = await res.json();
        if (json.ok && json.notes.length > 0) {
            json.notes.forEach(note => {
                shoutboxMessages.appendChild(renderMessage(note));
                lastNoteId = Math.max(lastNoteId, note.id);
            });
            shoutboxMessages.scrollTop = shoutboxMessages.scrollHeight;
        }
    } catch (err) {
        console.error('Shoutbox load error:', err);
    }
}

shoutboxInput?.addEventListener('input', async (e) => {
    const val = e.target.value;
    const cursorPos = e.target.selectionStart;
    const beforeCursor = val.substring(0, cursorPos);
    const lastAtIndex = beforeCursor.lastIndexOf('@');
    
    if (lastAtIndex !== -1 && lastAtIndex === cursorPos - 1) {
        const results = await fetchMentions('');
        showMentionDropdown(results, e.target);
    } else if (lastAtIndex !== -1) {
        const query = beforeCursor.substring(lastAtIndex + 1);
        if (/^[a-zA-Z0-9_\-\.]*$/.test(query)) {
            const results = await fetchMentions(query);
            showMentionDropdown(results, e.target);
        } else {
            hideMentionDropdown();
        }
    } else {
        hideMentionDropdown();
    }
});

shoutboxInput?.addEventListener('keydown', (e) => {
    if (mentionDropdown && mentionDropdown.style.display === 'block') {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedMentionIndex = Math.min(selectedMentionIndex + 1, mentionResults.length - 1);
            updateDropdownSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedMentionIndex = Math.max(selectedMentionIndex - 1, 0);
            updateDropdownSelection();
        } else if (e.key === 'Enter' && selectedMentionIndex >= 0) {
            e.preventDefault();
            insertMention(mentionResults[selectedMentionIndex].mention, e.target);
        } else if (e.key === 'Escape') {
            hideMentionDropdown();
        }
    }
});

shoutboxInput?.addEventListener('blur', () => {
    setTimeout(hideMentionDropdown, 200);
});

shoutboxForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = shoutboxInput?.value.trim();
    if (!message) return;
    
    try {
        const res = await fetch(path_with_base('/api/legal/shoutbox-post.php'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ case_id: caseId, message })
        });
        const json = await res.json();
        if (json.ok) {
            shoutboxInput.value = '';
            shoutboxMessages.appendChild(renderMessage(json.note));
            lastNoteId = json.note.id;
            shoutboxMessages.scrollTop = shoutboxMessages.scrollHeight;
        } else {
            alert(json.message || 'Failed to send');
        }
    } catch (err) {
        console.error(err);
        alert('Unable to send message');
    }
});

if (shoutboxMessages) {
    loadShoutbox();
    setInterval(loadShoutbox, 5000);
}

// Add note handler
const addNoteForm = document.getElementById('add-note-form');
addNoteForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const noteBody = document.getElementById('note-body')?.value.trim();
    const visibility = document.getElementById('note-visibility')?.value;
    const pinned = document.getElementById('note-pinned')?.checked;
    
    if (!noteBody) {
        alert('Please enter a note.');
        return;
    }
    
    try {
        const res = await fetch(path_with_base('/api/legal/add-note.php'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ case_id: caseId, note_body: noteBody, visibility, pinned })
        });
        const json = await res.json();
        if (json.ok) {
            window.location.reload();
        } else {
            alert(json.message || 'Failed to add note');
        }
    } catch (err) {
        console.error(err);
        alert('Unable to add note');
    }
});
</script>

<?php require_once __DIR__ . '/../../../required/footer.php';
