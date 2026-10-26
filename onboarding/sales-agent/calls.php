<?php
$SEO = [
    'title' => 'Call Log — Sales Agent CRM — Product',
    'description' => 'Track and manage your communication history with leads and accounts.',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';

start_session();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Check if this is admin simulation or real sales agent
$isAdminSimulation = isset($_SESSION['admin_simulation']);
if (!$isAdminSimulation) {
    // For real users, check role
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try { 
        $stmt->execute([$uid]); 
        $hasRole = (bool)$stmt->fetch(); 
    } catch (Throwable $e) { 
        $hasRole = false; 
    }
    if (!$hasRole && !is_training_complete($uid)) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

$pdo = db();

// Modal/AJAX helpers
$isAjaxFetch = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos($_SERVER['HTTP_X_REQUESTED_WITH'], 'fetch') !== false);
$isModal = isset($_GET['modal']) || isset($_POST['modal']);

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add_call') {
            $leadId = !empty($_POST['lead_id']) ? intval($_POST['lead_id']) : null;
            $accountId = !empty($_POST['account_id']) ? intval($_POST['account_id']) : null;
            $demoFollowupId = !empty($_POST['demo_followup_id']) ? intval($_POST['demo_followup_id']) : null;
            
            // Verify access to lead/account if not admin simulation
            if (!$isAdminSimulation && $leadId) {
                $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND assigned_agent_id = ?');
                $stmt->execute([$leadId, $uid]);
                if (!$stmt->fetch()) {
                    flash_set('error', 'Access denied to this lead.');
                    header('Location: calls.php');
                    exit;
                }
            }
            
            $stmt = $pdo->prepare('
                INSERT INTO crm_call_logs (
                    agent_id, lead_id, account_id, contact_type, direction, call_outcome,
                    duration_minutes, subject, summary, next_action, call_date,
                    follow_up_required, follow_up_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $callDate = !empty($_POST['call_date']) ? $_POST['call_date'] : date('Y-m-d H:i:s');
            $followUpDate = (!empty($_POST['follow_up_required']) && !empty($_POST['follow_up_date'])) ? $_POST['follow_up_date'] : null;
            
            $stmt->execute([
                $uid,
                $leadId,
                $accountId,
                $_POST['contact_type'] ?: 'phone_call',
                $_POST['direction'] ?: 'outbound',
                $_POST['call_outcome'] ?: null,
                intval($_POST['duration_minutes'] ?: 0),
                $_POST['subject'] ?: null,
                $_POST['summary'] ?: null,
                $_POST['next_action'] ?: null,
                $callDate,
                !empty($_POST['follow_up_required']) ? 1 : 0,
                $followUpDate
            ]);
            $newCallId = $pdo->lastInsertId();
            
            // If linked to a demo follow-up, mark completed and tie to the call (one-time)
            if ($demoFollowupId) {
                try {
                    $pdo->prepare('UPDATE crm_demo_dropoffs SET follow_up_status = "completed", follow_up_completed_call_log_id = ? WHERE id = ?')->execute([$newCallId, $demoFollowupId]);
                } catch (Throwable $e) { /* ignore */ }
            }
            
            // Update lead's last contact date if this is for a lead
            if ($leadId) {
                $stmt = $pdo->prepare('UPDATE crm_leads SET last_contact_date = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$callDate, $leadId]);
            }
            
            if ($isModal || $isAjaxFetch) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'id' => intval($newCallId)]);
                exit;
            } else {
                flash_set('success', 'Call logged successfully!');
                header('Location: calls.php');
                exit;
            }
            
        } elseif ($action === 'update_call') {
            $callId = intval($_POST['call_id']);
            $undoFollowup = !empty($_POST['undo_followup']) ? 1 : 0;
            
            // Verify this call belongs to current agent (or admin simulation)
            if (!$isAdminSimulation) {
                $stmt = $pdo->prepare('SELECT 1 FROM crm_call_logs WHERE id = ? AND agent_id = ?');
                $stmt->execute([$callId, $uid]);
                if (!$stmt->fetch()) {
                    flash_set('error', 'Access denied to this call log.');
                    header('Location: calls.php');
                    exit;
                }
            }
            
            $stmt = $pdo->prepare('
                UPDATE crm_call_logs SET 
                    contact_type = ?, direction = ?, call_outcome = ?, duration_minutes = ?,
                    subject = ?, summary = ?, next_action = ?, call_date = ?,
                    follow_up_required = ?, follow_up_date = ?, updated_at = NOW()
                WHERE id = ?
            ');
            
            $callDate = !empty($_POST['call_date']) ? $_POST['call_date'] : date('Y-m-d H:i:s');
            $followUpDate = (!empty($_POST['follow_up_required']) && !empty($_POST['follow_up_date'])) ? $_POST['follow_up_date'] : null;
            
            $stmt->execute([
                $_POST['contact_type'] ?: 'phone_call',
                $_POST['direction'] ?: 'outbound',
                $_POST['call_outcome'] ?: null,
                intval($_POST['duration_minutes'] ?: 0),
                $_POST['subject'] ?: null,
                $_POST['summary'] ?: null,
                $_POST['next_action'] ?: null,
                $callDate,
                !empty($_POST['follow_up_required']) ? 1 : 0,
                $followUpDate,
                $callId
            ]);

            // Undo follow-up completion linkage if requested
            if ($undoFollowup) {
                try {
                    $pdo->prepare('UPDATE crm_demo_dropoffs SET follow_up_status = "pending", follow_up_completed_call_log_id = NULL WHERE follow_up_completed_call_log_id = ?')->execute([$callId]);
                } catch (Throwable $e) { /* ignore */ }
            }
            
            if ($isModal || $isAjaxFetch) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'id' => intval($callId)]);
                exit;
            } else {
                flash_set('success', 'Call log updated successfully!');
                header('Location: calls.php?id=' . $callId);
                exit;
            }
        }
    } catch (Exception $e) {
        if ($isModal || $isAjaxFetch) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            exit;
        } else {
            flash_set('error', 'Error: ' . $e->getMessage());
        }
    }
}

// Get data
$calls = [];
$callDetail = null;
$leads = [];
$accounts = [];

$callId = intval($_GET['id'] ?? 0);
$preselectedLeadId = intval($_GET['lead_id'] ?? 0);
// Prefill support for streamlined follow-up from demo notifications
$prefillContactType = $_GET['contact_type'] ?? 'phone_call';
$prefillDirection = $_GET['direction'] ?? 'outbound';
$prefillOutcome = $_GET['call_outcome'] ?? '';
$prefillSubject = $_GET['subject'] ?? '';
$prefillSummary = $_GET['summary'] ?? '';
$prefillNextAction = $_GET['next_action'] ?? '';
$prefillFollowReq = isset($_GET['follow_up_required']) ? (int)$_GET['follow_up_required'] : 0;
$prefillFollowDate = $_GET['follow_up_date'] ?? '';

try {
    // Get leads for dropdown
    $query = 'SELECT id, CONCAT(first_name, " ", last_name) as name, company_name FROM crm_leads';
    if (!$isAdminSimulation) {
        $query .= ' WHERE assigned_agent_id = ?';
        $stmt = $pdo->prepare($query . ' ORDER BY first_name, last_name');
        $stmt->execute([$uid]);
    } else {
        $stmt = $pdo->prepare($query . ' ORDER BY first_name, last_name');
        $stmt->execute();
    }
    $leads = $stmt->fetchAll();
    
    // Get wholesale accounts for dropdown
    $stmt = $pdo->prepare('SELECT wa.id, wa.business_name, u.first_name, u.last_name FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.approved = 1 ORDER BY wa.business_name');
    $stmt->execute();
    $accounts = $stmt->fetchAll();
    
    if ($callId > 0) {
        // Get specific call detail
        $query = '
            SELECT cl.*, 
                   CONCAT(l.first_name, " ", l.last_name) as lead_name,
                   l.company_name as lead_company,
                   wa.business_name as account_name
            FROM crm_call_logs cl 
            LEFT JOIN crm_leads l ON l.id = cl.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = cl.account_id
            WHERE cl.id = ?';
        
        if (!$isAdminSimulation) {
            $query .= ' AND cl.agent_id = ?';
            $stmt = $pdo->prepare($query);
            $stmt->execute([$callId, $uid]);
        } else {
            $stmt = $pdo->prepare($query);
            $stmt->execute([$callId]);
        }
        
        $callDetail = $stmt->fetch();
        
        if (!$callDetail) {
            flash_set('error', 'Call log not found or access denied.');
            header('Location: calls.php');
            exit;
        }
    } else {
        // Get all calls for this agent
        $query = '
            SELECT cl.*, 
                   CONCAT(l.first_name, " ", l.last_name) as lead_name,
                   l.company_name as lead_company,
                   wa.business_name as account_name
            FROM crm_call_logs cl 
            LEFT JOIN crm_leads l ON l.id = cl.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = cl.account_id';
        
        if (!$isAdminSimulation) {
            $query .= ' WHERE cl.agent_id = ?';
            $stmt = $pdo->prepare($query . ' ORDER BY cl.call_date DESC');
            $stmt->execute([$uid]);
        } else {
            $stmt = $pdo->prepare($query . ' ORDER BY cl.call_date DESC');
            $stmt->execute();
        }
        
        $calls = $stmt->fetchAll();
    }
} catch (Exception $e) {
    flash_set('error', 'Database error: ' . $e->getMessage());
}

// If requested as modal fragment for Add Call, render only the form
if ($isModal && ($_GET['action'] ?? '') === 'add') {
    ?>
    <div style="padding:20px">
        <h3 style="margin: 0 0 16px 0;">Log New Call</h3>
        <?php $prefillTel = preg_replace('/[^0-9+]/', '', (string)($_GET['tel'] ?? '')); ?>
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
            <a class="btn" style="background:#dc2626;" href="<?php echo $prefillTel ? ('tel:' . htmlspecialchars($prefillTel)) : '#'; ?>" onclick="startAcpTimer()">
                <?php echo $prefillTel ? ('📞 Tap to Call ' . htmlspecialchars($prefillTel)) : '📞 Tap to Call'; ?>
            </a>
            <span id="acpCallTimer" style="font-weight:700; color:#111827;">00:00</span>
            <button id="acpStartBtn" type="button" class="btn small" onclick="startAcpTimer()">Start</button>
            <button id="acpStopBtn" type="button" class="btn small secondary" onclick="stopAcpTimer()">Stop</button>
        </div>
        <form method="post" style="display: grid; gap: 16px;" data-ajax>
            <input type="hidden" name="modal" value="1">
            <input type="hidden" name="action" value="add_call">
            <input type="hidden" name="duration_minutes" value="0">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Contact Type *</label>
                    <select name="contact_type" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <?php foreach (['phone_call','email','meeting','text_message','video_call','other'] as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo ($prefillContactType === $type ? 'selected' : ''); ?>><?php echo ucwords(str_replace('_',' ', $type)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Direction *</label>
                    <select name="direction" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="outbound" <?php echo ($prefillDirection === 'outbound' ? 'selected' : ''); ?>>Outbound</option>
                        <option value="inbound" <?php echo ($prefillDirection === 'inbound' ? 'selected' : ''); ?>>Inbound</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Lead</label>
                    <select name="lead_id" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Lead (Optional)</option>
                        <?php foreach ($leads as $lead): ?>
                            <option value="<?php echo $lead['id']; ?>" <?php echo $preselectedLeadId == $lead['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lead['name']); ?>
                                <?php if ($lead['company_name']): ?> - <?php echo htmlspecialchars($lead['company_name']); ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Account</label>
                    <select name="account_id" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Account (Optional)</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>"><?php echo htmlspecialchars($account['business_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label>Subject</label>
                <input type="text" name="subject" value="<?php echo htmlspecialchars($prefillSubject); ?>" placeholder="Brief subject or topic..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                <div>
                    <label>Call Outcome</label>
                    <select name="call_outcome" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Outcome</option>
                        <?php foreach (['answered','no_answer','busy','voicemail','meeting_scheduled','sale_made','not_interested'] as $outcome): ?>
                        <option value="<?php echo $outcome; ?>" <?php echo ($prefillOutcome === $outcome ? 'selected' : ''); ?>><?php echo ucwords(str_replace('_',' ', $outcome)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Duration (minutes)</label>
                    <input type="number" name="duration_minutes" min="0" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label>Call Date & Time</label>
                    <input type="datetime-local" name="call_date" value="<?php echo date('Y-m-d\TH:i'); ?>" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            <div>
                <label>Call Summary</label>
                <textarea name="summary" rows="4" placeholder="What was discussed? Key points, concerns, interests..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"><?php echo htmlspecialchars($prefillSummary); ?></textarea>
            </div>
            <div>
                <label>Next Action</label>
                <textarea name="next_action" rows="2" placeholder="What needs to happen next? Follow-up actions..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"><?php echo htmlspecialchars($prefillNextAction); ?></textarea>
            </div>
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: end;">
                <div>
                    <label style="display: flex; align-items: center; gap: 6px;">
                        <input type="checkbox" name="follow_up_required" value="1" <?php echo $prefillFollowReq ? 'checked' : ''; ?>>
                        Follow-up Required
                    </label>
                </div>
                <div>
                    <input type="datetime-local" name="follow_up_date" <?php echo $prefillFollowReq ? '' : 'disabled'; ?> value="<?php echo $prefillFollowDate ? date('Y-m-d\TH:i', strtotime($prefillFollowDate)) : ''; ?>" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" id="follow_up_date_modal">
                </div>
            </div>
            <div style="display: flex; gap: 12px; justify-content: end; margin-top: 16px;">
                <button type="button" class="btn secondary" onclick="window.SalesACPModals && SalesACPModals.close()">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626;">Log Call</button>
            </div>
        </form>
        <script>
        (function(){
          var cb = document.querySelector('input[name="follow_up_required"]');
          if (cb) { var f = document.getElementById('follow_up_date_modal'); cb.addEventListener('change', function(){ if (f) { f.disabled = !cb.checked; if(!cb.checked){ f.value=''; } } }); }
        })();
        (function(){
          if (typeof window.renderAcpTimer !== 'function') {
            window.renderAcpTimer = function(){
              var el = document.getElementById('acpCallTimer');
              if (!el) return;
              var s = (window.__acpTimerSecs||0)|0; var m = Math.floor(s/60); var r = s%60;
              el.textContent = String(m).padStart(2,'0') + ':' + String(r).padStart(2,'0');
            };
          }
          if (typeof window.startAcpTimer !== 'function') {
            window.startAcpTimer = function(){
              if (window.__acpTimer) return;
              window.__acpTimerStart = Date.now();
              window.__acpTimerTick = function(){ window.__acpTimerSecs = Math.round((Date.now() - window.__acpTimerStart)/1000); window.renderAcpTimer(); };
              window.__acpTimer = setInterval(window.__acpTimerTick, 1000);
              window.__acpTimerTick();
              try { var s=document.getElementById('acpStartBtn'); var t=document.getElementById('acpStopBtn'); if(s) s.disabled=true; if(t) t.disabled=false; } catch(e){}
            };
          }
          if (typeof window.stopAcpTimer !== 'function') {
            window.stopAcpTimer = function(){
              if (window.__acpTimer){ clearInterval(window.__acpTimer); window.__acpTimer = null; }
              window.renderAcpTimer();
              try {
                var secs = window.__acpTimerSecs || 0; var mins = secs > 0 ? Math.max(1, Math.round(secs/60)) : 0;
                var wrap = document.getElementById('acpCallContent') || document;
                var durInputs = wrap.querySelectorAll('input[name="duration_minutes"]');
                durInputs.forEach(function(inp){ inp.value = String(mins); });
              } catch(e) {}
              try { var s=document.getElementById('acpStartBtn'); var t=document.getElementById('acpStopBtn'); if(s) s.disabled=false; if(t) t.disabled=true; } catch(e){}
            };
          }
          if (typeof window.__acpTimerSecs === 'undefined') { window.__acpTimerSecs = 0; }
          // Hook duration on submit as a safety
          try {
            var wrap = document.getElementById('acpCallContent') || document;
            var form = wrap.querySelector('form[data-ajax]');
            if (form) {
              form.addEventListener('submit', function(){
                var secs = window.__acpTimerSecs || 0; var mins = Math.max(0, Math.round(secs/60));
                var dur = form.querySelector('input[name="duration_minutes"]');
                if (!dur) { dur = document.createElement('input'); dur.type='hidden'; dur.name='duration_minutes'; form.appendChild(dur); }
                dur.value = String(mins);
              });
            }
          } catch(e){}
        })();
        </script>
    </div>
    <?php
    exit;
}

?>

<div class="container">
    <?php 
    // Include the enhanced Paladin navigation
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    ?>
    
    <?php
    // Include simple breadcrumb navigation
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
    
    <?php if ($callDetail): ?>
        <!-- Call Detail View -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1 style="margin: 0; color: #1f2937;">
                    📞 <?php echo ucwords(str_replace('_', ' ', $callDetail['contact_type'])); ?>
                    <?php if ($callDetail['lead_name'] || $callDetail['account_name']): ?>
                        with <?php echo htmlspecialchars($callDetail['lead_name'] ?: $callDetail['account_name']); ?>
                    <?php endif; ?>
                </h1>
                <p style="margin: 4px 0 0 0; color: #6b7280;">
                    <?php echo date('M j, Y g:i A', strtotime($callDetail['call_date'])); ?>
                    • <?php echo ucwords($callDetail['direction']); ?>
                    <?php if ($callDetail['duration_minutes']): ?>
                        • <?php echo $callDetail['duration_minutes']; ?> minutes
                    <?php endif; ?>
                </p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="calls.php" class="btn secondary">← Back to All Calls</a>
                <button onclick="showEditModal()" class="btn" style="background: #059669;">✏️ Edit Call</button>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
            <!-- Call Information -->
            <div class="card">
                <h3 style="margin: 0 0 16px 0;">📋 Call Details</h3>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <strong>Contact:</strong><br>
                        <?php if ($callDetail['lead_name']): ?>
                            🎯 Lead: <?php echo htmlspecialchars($callDetail['lead_name']); ?>
                            <?php if ($callDetail['lead_company']): ?>
                                <br>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($callDetail['lead_company']); ?>
                            <?php endif; ?>
                        <?php elseif ($callDetail['account_name']): ?>
                            🏢 Account: <?php echo htmlspecialchars($callDetail['account_name']); ?>
                        <?php else: ?>
                            📞 General Call
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong>Outcome:</strong><br>
                        <span style="
                            background: <?php 
                            echo match($callDetail['call_outcome']) {
                                'answered' => '#dcfce7',
                                'meeting_scheduled' => '#dbeafe', 
                                'sale_made' => '#d1fae5',
                                'not_interested' => '#fee2e2',
                                'no_answer', 'busy', 'voicemail' => '#fef3c7',
                                default => '#f3f4f6'
                            }; 
                            ?>;
                            color: <?php 
                            echo match($callDetail['call_outcome']) {
                                'answered' => '#166534',
                                'meeting_scheduled' => '#1e40af', 
                                'sale_made' => '#059669',
                                'not_interested' => '#dc2626',
                                'no_answer', 'busy', 'voicemail' => '#d97706',
                                default => '#6b7280'
                            }; 
                            ?>;
                            padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                            <?php echo $callDetail['call_outcome'] ? ucwords(str_replace('_', ' ', $callDetail['call_outcome'])) : 'Not Set'; ?>
                        </span>
                    </div>
                </div>

                <?php if ($callDetail['subject']): ?>
                <div style="margin-bottom: 16px;">
                    <strong>Subject:</strong><br>
                    <?php echo htmlspecialchars($callDetail['subject']); ?>
                </div>
                <?php endif; ?>

                <?php if ($callDetail['summary']): ?>
                <div style="margin-bottom: 16px;">
                    <strong>Summary:</strong><br>
                    <div style="background: #f9fafb; padding: 12px; border-radius: 6px; white-space: pre-line;">
                        <?php echo htmlspecialchars($callDetail['summary']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($callDetail['next_action']): ?>
                <div style="margin-bottom: 16px;">
                    <strong>Next Action:</strong><br>
                    <div style="background: #fef3c7; padding: 12px; border-radius: 6px; border-left: 4px solid #f59e0b;">
                        <?php echo htmlspecialchars($callDetail['next_action']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($callDetail['follow_up_required'] && $callDetail['follow_up_date']): ?>
                <div style="background: #dbeafe; padding: 12px; border-radius: 6px; border-left: 4px solid #3b82f6;">
                    <strong>⏰ Follow-up Required:</strong><br>
                    <?php echo date('M j, Y g:i A', strtotime($callDetail['follow_up_date'])); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div>
                <div class="card">
                    <h4 style="margin: 0 0 12px 0;">🚀 Quick Actions</h4>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php if ($callDetail['lead_name'] && $callDetail['lead_id']): ?>
                            <a href="leads.php?id=<?php echo $callDetail['lead_id']; ?>" class="btn" style="background: #059669; text-align: center;">👁️ View Lead</a>
                            <a href="calls.php?lead_id=<?php echo $callDetail['lead_id']; ?>&action=add" class="btn" style="background: #dc2626; text-align: center;">📞 Log Another Call</a>
                            <a href="calls.php?lead_id=<?php echo $callDetail['lead_id']; ?>&action=add&prefill=note" class="btn" style="background: #7c3aed; text-align: center;">📝 Add Note</a>
                        <?php else: ?>
                            <a href="calls.php?action=add" class="btn" style="background: #dc2626; text-align: center;">📞 Log New Call</a>
                        <?php endif; ?>
                        <a href="tasks.php?action=add" class="btn" style="background: #0369a1; text-align: center;">✓ Create Task</a>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Calls List View -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1 style="margin: 0; color: #1f2937;">📞 Call Log</h1>
                <p style="margin: 4px 0 0 0; color: #6b7280;"><?php echo count($calls); ?> calls logged</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="index.php" class="btn secondary">← Back to CRM</a>
                <button onclick="showAddModal()" class="btn" style="background: #dc2626;">+ Log New Call</button>
            </div>
        </div>

        <?php if (empty($calls)): ?>
            <div class="card" style="text-align: center; padding: 48px;">
                <div style="font-size: 64px; margin-bottom: 16px;">📞</div>
                <h2 style="color: #6b7280;">No Calls Logged Yet</h2>
                <p style="color: #9ca3af; margin-bottom: 24px;">Start tracking your communication by logging your first call.</p>
                <button onclick="showAddModal()" class="btn" style="background: #dc2626;">Log Your First Call</button>
            </div>
        <?php else: ?>
            <div class="card">
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Contact</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Type</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Outcome</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Duration</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Date</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calls as $call): ?>
                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                <td style="padding: 12px 8px;">
                                    <div style="font-weight: 600; color: #1f2937;">
                                        <?php if ($call['lead_name']): ?>
                                            🎯 <?php echo htmlspecialchars($call['lead_name']); ?>
                                            <?php if ($call['lead_company']): ?>
                                                <div style="font-size: 12px; color: #6b7280; font-weight: normal;">
                                                    <?php echo htmlspecialchars($call['lead_company']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($call['account_name']): ?>
                                            🏢 <?php echo htmlspecialchars($call['account_name']); ?>
                                        <?php else: ?>
                                            📞 General Call
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($call['subject']): ?>
                                        <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                            <?php echo htmlspecialchars(substr($call['subject'], 0, 50) . (strlen($call['subject']) > 50 ? '...' : '')); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <div style="font-size: 13px;">
                                        <?php echo ucwords(str_replace('_', ' ', $call['contact_type'])); ?>
                                    </div>
                                    <div style="font-size: 11px; color: #6b7280;">
                                        <?php echo ucfirst($call['direction']); ?>
                                    </div>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <?php if ($call['call_outcome']): ?>
                                        <span style="
                                            background: <?php 
                                            echo match($call['call_outcome']) {
                                                'answered' => '#dcfce7',
                                                'meeting_scheduled' => '#dbeafe', 
                                                'sale_made' => '#d1fae5',
                                                'not_interested' => '#fee2e2',
                                                'no_answer', 'busy', 'voicemail' => '#fef3c7',
                                                default => '#f3f4f6'
                                            }; 
                                            ?>;
                                            color: <?php 
                                            echo match($call['call_outcome']) {
                                                'answered' => '#166534',
                                                'meeting_scheduled' => '#1e40af', 
                                                'sale_made' => '#059669',
                                                'not_interested' => '#dc2626',
                                                'no_answer', 'busy', 'voicemail' => '#d97706',
                                                default => '#6b7280'
                                            }; 
                                            ?>;
                                            padding: 2px 6px; border-radius: 8px; font-size: 11px; font-weight: 600;">
                                            <?php echo ucwords(str_replace('_', ' ', $call['call_outcome'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af; font-size: 12px;">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px; color: #6b7280; font-size: 14px;">
                                    <?php if ($call['duration_minutes']): ?>
                                        <?php echo $call['duration_minutes']; ?> min
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px; color: #6b7280; font-size: 14px;">
                                    <div><?php echo date('M j', strtotime($call['call_date'])); ?></div>
                                    <div style="font-size: 11px;"><?php echo date('g:i A', strtotime($call['call_date'])); ?></div>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <div style="display: flex; gap: 4px;">
                                        <a href="calls.php?id=<?php echo $call['id']; ?>" class="btn small">View</a>
                                        <?php if ($call['lead_name'] && $call['lead_id']): ?>
                                            <a href="leads.php?id=<?php echo $call['lead_id']; ?>" class="btn small" style="background: #059669;">Lead</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Add Call Modal -->
<div id="addCallModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 600px; max-width: 90vw; max-height: 80vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">Log New Call</h3>
        <form method="post" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="add_call">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Contact Type *</label>
                    <select name="contact_type" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <?php foreach (['phone_call','email','meeting','text_message','video_call','other'] as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo ($prefillContactType === $type ? 'selected' : ''); ?>><?php echo ucwords(str_replace('_',' ', $type)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Direction *</label>
                    <select name="direction" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="outbound" <?php echo ($prefillDirection === 'outbound' ? 'selected' : ''); ?>>Outbound</option>
                        <option value="inbound" <?php echo ($prefillDirection === 'inbound' ? 'selected' : ''); ?>>Inbound</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Lead</label>
                    <select name="lead_id" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Lead (Optional)</option>
                        <?php foreach ($leads as $lead): ?>
                            <option value="<?php echo $lead['id']; ?>" <?php echo $preselectedLeadId == $lead['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lead['name']); ?>
                                <?php if ($lead['company_name']): ?>
                                    - <?php echo htmlspecialchars($lead['company_name']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Account</label>
                    <select name="account_id" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Account (Optional)</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>">
                                <?php echo htmlspecialchars($account['business_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div>
                <label>Subject</label>
                <input type="text" name="subject" value="<?php echo htmlspecialchars($prefillSubject); ?>" placeholder="Brief subject or topic..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                <div>
                    <label>Call Outcome</label>
                    <select name="call_outcome" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Outcome</option>
                        <?php foreach (['answered','no_answer','busy','voicemail','meeting_scheduled','sale_made','not_interested'] as $outcome): ?>
                        <option value="<?php echo $outcome; ?>" <?php echo ($prefillOutcome === $outcome ? 'selected' : ''); ?>><?php echo ucwords(str_replace('_',' ', $outcome)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Duration (minutes)</label>
                    <input type="number" name="duration_minutes" min="0" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label>Call Date & Time</label>
                    <input type="datetime-local" name="call_date" value="<?php echo date('Y-m-d\TH:i'); ?>" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            
            <div>
                <label>Call Summary</label>
                <textarea name="summary" rows="4" placeholder="What was discussed? Key points, concerns, interests..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"><?php echo htmlspecialchars($prefillSummary); ?></textarea>
            </div>
            
            <div>
                <label>Next Action</label>
                <textarea name="next_action" rows="2" placeholder="What needs to happen next? Follow-up actions..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"><?php echo htmlspecialchars($prefillNextAction); ?></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: end;">
                <div>
                    <label style="display: flex; align-items: center; gap: 6px;">
                        <input type="checkbox" name="follow_up_required" value="1" <?php echo $prefillFollowReq ? 'checked' : ''; ?> onchange="toggleFollowUp(this)">
                        Follow-up Required
                    </label>
                </div>
                <div>
                    <input type="datetime-local" name="follow_up_date" <?php echo $prefillFollowReq ? '' : 'disabled'; ?> value="<?php echo $prefillFollowDate ? date('Y-m-d\TH:i', strtotime($prefillFollowDate)) : ''; ?>" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" id="follow_up_date">
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end; margin-top: 16px;">
                <button type="button" onclick="hideAddModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626;">Log Call</button>
            </div>
        </form>
    </div>
</div>

<?php if ($callDetail): ?>
<!-- Edit Call Modal -->
<div id="editCallModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 600px; max-width: 90vw; max-height: 80vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">Edit Call Log</h3>
        <form method="post" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="update_call">
            <input type="hidden" name="call_id" value="<?php echo $callDetail['id']; ?>">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Contact Type *</label>
                    <select name="contact_type" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <?php foreach (['phone_call', 'email', 'meeting', 'text_message', 'video_call', 'other'] as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $callDetail['contact_type'] === $type ? 'selected' : ''; ?>>
                                <?php echo ucwords(str_replace('_', ' ', $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Direction *</label>
                    <select name="direction" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="outbound" <?php echo $callDetail['direction'] === 'outbound' ? 'selected' : ''; ?>>Outbound</option>
                        <option value="inbound" <?php echo $callDetail['direction'] === 'inbound' ? 'selected' : ''; ?>>Inbound</option>
                    </select>
                </div>
            </div>
            
            <div>
                <label>Subject</label>
                <input type="text" name="subject" value="<?php echo htmlspecialchars($callDetail['subject'] ?: ''); ?>" placeholder="Brief subject or topic..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                <div>
                    <label>Call Outcome</label>
                    <select name="call_outcome" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Outcome</option>
                        <?php foreach (['answered', 'no_answer', 'busy', 'voicemail', 'meeting_scheduled', 'sale_made', 'not_interested'] as $outcome): ?>
                            <option value="<?php echo $outcome; ?>" <?php echo $callDetail['call_outcome'] === $outcome ? 'selected' : ''; ?>>
                                <?php echo ucwords(str_replace('_', ' ', $outcome)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Duration (minutes)</label>
                    <input type="number" name="duration_minutes" value="<?php echo $callDetail['duration_minutes']; ?>" min="0" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label>Call Date & Time</label>
                    <input type="datetime-local" name="call_date" value="<?php echo date('Y-m-d\TH:i', strtotime($callDetail['call_date'])); ?>" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            
            <div>
                <label>Call Summary</label>
                <textarea name="summary" rows="4" placeholder="What was discussed? Key points, concerns, interests..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"><?php echo htmlspecialchars($callDetail['summary'] ?: ''); ?></textarea>
            </div>
            
            <div>
                <label>Next Action</label>
                <textarea name="next_action" rows="2" placeholder="What needs to happen next? Follow-up actions..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"><?php echo htmlspecialchars($callDetail['next_action'] ?: ''); ?></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: end;">
                <div>
                    <label style="display: flex; align-items: center; gap: 6px;">
                        <input type="checkbox" name="follow_up_required" value="1" <?php echo $callDetail['follow_up_required'] ? 'checked' : ''; ?> onchange="toggleFollowUpEdit(this)">
                        Follow-up Required
                    </label>
                </div>
                <div>
                    <input type="datetime-local" name="follow_up_date" value="<?php echo $callDetail['follow_up_date'] ? date('Y-m-d\TH:i', strtotime($callDetail['follow_up_date'])) : ''; ?>" <?php echo !$callDetail['follow_up_required'] ? 'disabled' : ''; ?> style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" id="follow_up_date_edit">
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end; margin-top: 16px;">
                <button type="button" onclick="hideEditModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626;">Update Call</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.btn.small {
    padding: 4px 8px;
    font-size: 12px;
}

label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
    color: #374151;
}
</style>

<script>
function showAddModal() {
    document.getElementById('addCallModal').style.display = 'block';
}

function hideAddModal() {
    document.getElementById('addCallModal').style.display = 'none';
}

function toggleFollowUp(checkbox) {
    const followUpDate = document.getElementById('follow_up_date');
    followUpDate.disabled = !checkbox.checked;
    if (!checkbox.checked) {
        followUpDate.value = '';
    }
}

<?php if ($callDetail): ?>
function showEditModal() {
    document.getElementById('editCallModal').style.display = 'block';
}

function hideEditModal() {
    document.getElementById('editCallModal').style.display = 'none';
}

function toggleFollowUpEdit(checkbox) {
    const followUpDate = document.getElementById('follow_up_date_edit');
    followUpDate.disabled = !checkbox.checked;
    if (!checkbox.checked) {
        followUpDate.value = '';
    }
}
<?php endif; ?>

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.style.position === 'fixed' && e.target.style.background.includes('rgba')) {
        e.target.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
