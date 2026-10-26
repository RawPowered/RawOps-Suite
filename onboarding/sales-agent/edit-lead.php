<?php
/**
 * Dedicated Lead Edit Page
 * Full-page edit experience for lead updates
 * Replaces modal editing with better UX
 * 
 * IMPORTANT: POST handler must run BEFORE header.php to allow redirects
 */

// Load config and functions FIRST (before any output)
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/demo-functions.php';
require_once __DIR__ . '/../../required/lead-chaining-functions.php';
require_once __DIR__ . '/../../required/social-links-helpers.php';

start_session();
$pdo = db();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Access control
$isAdminSimulation = isset($_SESSION['admin_simulation']);
$isRealAdmin = current_admin_id() !== null;
$adminMode = !empty($_SESSION['sales_acp_admin_mode']);
$hasFullAccess = $isAdminSimulation || ($isRealAdmin && $adminMode);

// Get lead ID
$leadId = intval($_GET['id'] ?? 0);
if (!$leadId) {
    flash_set('error', 'No lead specified.');
    redirect('leads.php');
}

// Get lead details
try {
    if (!$hasFullAccess) {
        $stmt = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ? AND assigned_agent_id = ? LIMIT 1');
        $stmt->execute([$leadId, $uid]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ? LIMIT 1');
        $stmt->execute([$leadId]);
    }
    
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lead) {
        flash_set('error', 'Lead not found or access denied.');
        redirect('leads.php');
    }
} catch (Exception $e) {
    flash_set('error', 'Database error: ' . $e->getMessage());
    redirect('leads.php');
}

// Handle form submission (BEFORE any HTML output)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['submit_action'] ?? 'save_and_view';
        
        // Verify access
        if (!$hasFullAccess) {
            $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND assigned_agent_id = ?');
            $stmt->execute([$leadId, $uid]);
            if (!$stmt->fetch()) {
                flash_set('error', 'Access denied to this lead.');
                redirect('leads.php');
            }
        }
        
        // Handle email/phone "not available" flags
        $emailNotAvailable = !empty($_POST['email_not_available']);
        $phoneNotAvailable = !empty($_POST['phone_not_available']);
        
        // Get checkbox flags
        $isSocialMediaLead = !empty($_POST['is_social_media_lead']) ? 1 : 0;
        
        // Update basic lead info
        $stmt = $pdo->prepare('
            UPDATE crm_leads SET 
                first_name = ?, last_name = ?, company_name = ?,
                website = ?,
                address_line1 = ?, address_line2 = ?, city = ?, state = ?, zip_code = ?, country = ?,
                address = ?, postal_code = ?,
                lead_source = ?, lead_status = ?, lead_priority = ?, 
                estimated_monthly_volume = ?, estimated_deal_value = ?, interest_level = ?, 
                next_follow_up = ?, is_social_media_lead = ?, updated_at = NOW()
            WHERE id = ?
        ');
        
        $nextFollowUp = !empty($_POST['next_follow_up']) ? $_POST['next_follow_up'] : null;
        
        $stmt->execute([
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['company_name'] ?: null,
            $_POST['website'] ?: null,
            $_POST['address_line1'] ?: null,
            $_POST['address_line2'] ?: null,
            $_POST['city'] ?: null,
            $_POST['state'] ?: null,
            $_POST['zip_code'] ?: null,
            $_POST['country'] ?: 'USA',
            $_POST['address_full'] ?: null,
            $_POST['postal_code'] ?: null,
            $_POST['lead_source'] ?: 'website',
            $_POST['lead_status'] ?: 'new',
            $_POST['lead_priority'] ?: 'medium',
            floatval($_POST['estimated_monthly_volume'] ?: 0) ?: null,
            floatval($_POST['estimated_deal_value'] ?: 0) ?: null,
            intval($_POST['interest_level'] ?: 5),
            $nextFollowUp,
            $isSocialMediaLead,
            $leadId
        ]);
        
        // Handle email updates with chaining
        try {
            $email = $_POST['email'] ?: null;
            update_lead_email_with_chaining($pdo, $leadId, $email, $emailNotAvailable);
        } catch (Throwable $emailEx) {
            // Log but don't fail the whole update
            error_log("Email chaining error for lead {$leadId}: " . $emailEx->getMessage());
        }
        
        // Handle phone updates with chaining  
        try {
            $phone = $_POST['phone'] ?: null;
            update_lead_phone_with_chaining($pdo, $leadId, $phone, $phoneNotAvailable);
        } catch (Throwable $phoneEx) {
            // Log but don't fail the whole update
            error_log("Phone chaining error for lead {$leadId}: " . $phoneEx->getMessage());
        }
        
        flash_set('success', 'Lead updated successfully!');
        
        // Redirect based on user's choice - use header/exit for absolute certainty
        if ($action === 'save_and_continue') {
            header('Location: edit-lead.php?id=' . $leadId);
            exit;
        } else {
            header('Location: leads.php?id=' . $leadId);
            exit;
        }
        
    } catch (Exception $e) {
        flash_set('error', 'Error updating lead: ' . $e->getMessage());
        // Redirect back to edit page to show error - use header/exit for certainty
        header('Location: edit-lead.php?id=' . $leadId);
        exit;
    }
}

// If we got here via POST but somehow didn't redirect, force redirect (safety net)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // This should never execute if redirects work properly
    error_log("CRITICAL: edit-lead.php POST reached end without redirect for lead {$leadId}");
    header('Location: edit-lead.php?id=' . $leadId);
    exit;
}

// NOW load header.php (after all POST handling is complete)
$SEO = [
    'title' => 'Edit Lead — Sales Agent CRM — Product',
    'description' => 'Update lead information',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
?>

<div class="container">
    <?php 
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
    
    <div class="page-header" style="margin-bottom:24px;">
        <div>
            <h1 class="page-title">✏️ Edit Lead</h1>
            <div class="page-subtitle muted"><?php echo htmlspecialchars($lead['company_name'] ?: ($lead['first_name'] . ' ' . $lead['last_name'])); ?></div>
        </div>
        <div class="header-actions">
            <a href="leads.php?id=<?php echo $leadId; ?>" class="btn secondary">← Back to Lead</a>
        </div>
    </div>

    <form method="post" class="edit-lead-form">
        <div class="card" style="max-width:800px; margin:0 auto;">
            <!-- Contact Information -->
            <div style="margin-bottom:32px;">
                <h3 style="margin:0 0 16px 0; color:#1f2937; border-bottom:2px solid #e5e7eb; padding-bottom:8px;">👤 Contact Information</h3>
                
                <div style="display:grid; gap:16px;">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label>First Name *</label>
                            <input type="text" name="first_name" value="<?php echo htmlspecialchars($lead['first_name']); ?>" required class="input">
                        </div>
                        <div>
                            <label>Last Name *</label>
                            <input type="text" name="last_name" value="<?php echo htmlspecialchars($lead['last_name']); ?>" required class="input">
                        </div>
                    </div>
                    
                    <div>
                        <label>Email</label>
                        <div style="display:flex; gap:12px; align-items:center;">
                            <input type="email" name="email" value="<?php echo htmlspecialchars($lead['email'] ?? ''); ?>" class="input" style="flex:1;">
                            <label style="display:flex; align-items:center; gap:6px; margin:0; white-space:nowrap;">
                                <input type="checkbox" name="email_not_available" value="1" <?php echo !empty($lead['email_not_available']) ? 'checked' : ''; ?>>
                                <span style="font-size:13px;">Not Available</span>
                            </label>
                        </div>
                    </div>
                    
                    <div>
                        <label>Phone</label>
                        <div style="display:flex; gap:12px; align-items:center;">
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($lead['phone'] ?? ''); ?>" class="input" style="flex:1;">
                            <label style="display:flex; align-items:center; gap:6px; margin:0; white-space:nowrap;">
                                <input type="checkbox" name="phone_not_available" value="1" <?php echo !empty($lead['phone_not_available']) ? 'checked' : ''; ?>>
                                <span style="font-size:13px;">Not Available</span>
                            </label>
                        </div>
                    </div>
                    
                    <div style="background:#e0f2fe; border:1px solid #0369a1; padding:12px; border-radius:8px;">
                        <label style="display:flex; align-items:center; gap:8px; margin:0; cursor:pointer;">
                            <input type="checkbox" name="is_social_media_lead" value="1" <?php echo !empty($lead['is_social_media_lead']) ? 'checked' : ''; ?>>
                            <div>
                                <span style="font-weight:600; color:#0369a1;">📱 Social Media Exclusive Lead</span>
                                <div style="font-size:12px; color:#075985; margin-top:2px;">
                                    Check if this lead is primarily contacted via social media (Facebook, Instagram, LinkedIn). 
                                    Social-only leads are considered detailed when they have a major platform link.
                                </div>
                            </div>
                        </label>
                    </div>
                    
                    <div>
                        <label>Company Name</label>
                        <input type="text" name="company_name" value="<?php echo htmlspecialchars($lead['company_name'] ?? ''); ?>" class="input">
                    </div>
                    
                    <div>
                        <label>Website</label>
                        <input type="url" name="website" value="<?php echo htmlspecialchars($lead['website'] ?? ''); ?>" class="input">
                    </div>
                </div>
            </div>

            <!-- Address Information -->
            <div style="margin-bottom:32px;">
                <h3 style="margin:0 0 16px 0; color:#1f2937; border-bottom:2px solid #e5e7eb; padding-bottom:8px;">📍 Address Information</h3>
                
                <div style="display:grid; gap:16px;">
                    <div>
                        <label>Address Line 1</label>
                        <input type="text" name="address_line1" value="<?php echo htmlspecialchars($lead['address_line1'] ?? ''); ?>" class="input">
                    </div>
                    
                    <div>
                        <label>Address Line 2</label>
                        <input type="text" name="address_line2" value="<?php echo htmlspecialchars($lead['address_line2'] ?? ''); ?>" class="input">
                    </div>
                    
                    <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:12px;">
                        <div>
                            <label>City</label>
                            <input type="text" name="city" value="<?php echo htmlspecialchars($lead['city'] ?? ''); ?>" class="input">
                        </div>
                        <div>
                            <label>State</label>
                            <input type="text" name="state" value="<?php echo htmlspecialchars($lead['state'] ?? ''); ?>" class="input">
                        </div>
                        <div>
                            <label>ZIP Code</label>
                            <input type="text" name="zip_code" value="<?php echo htmlspecialchars($lead['zip_code'] ?? ''); ?>" class="input">
                        </div>
                    </div>
                    
                    <div>
                        <label>Country</label>
                        <input type="text" name="country" value="<?php echo htmlspecialchars($lead['country'] ?? 'USA'); ?>" class="input">
                    </div>
                    
                    <div>
                        <label>Full Address (if different format)</label>
                        <input type="text" name="address_full" value="<?php echo htmlspecialchars($lead['address'] ?? ''); ?>" class="input">
                    </div>
                    
                    <div>
                        <label>Postal Code (international)</label>
                        <input type="text" name="postal_code" value="<?php echo htmlspecialchars($lead['postal_code'] ?? ''); ?>" class="input">
                    </div>
                </div>
            </div>

            <!-- Lead Details -->
            <div style="margin-bottom:32px;">
                <h3 style="margin:0 0 16px 0; color:#1f2937; border-bottom:2px solid #e5e7eb; padding-bottom:8px;">📊 Lead Details</h3>
                
                <div style="display:grid; gap:16px;">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label>Lead Source</label>
                            <select name="lead_source" class="input">
                                <?php foreach (['website', 'referral', 'cold_call', 'email', 'social_media', 'event', 'other'] as $source): ?>
                                <option value="<?php echo $source; ?>" <?php echo ($lead['lead_source'] ?? 'website') === $source ? 'selected' : ''; ?>>
                                    <?php echo ucwords(str_replace('_', ' ', $source)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Lead Status</label>
                            <select name="lead_status" class="input">
                                <?php foreach (['new', 'contacted', 'qualified', 'negotiating', 'won', 'lost', 'customer_follow_up'] as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo ($lead['lead_status'] ?? 'new') === $status ? 'selected' : ''; ?>>
                                    <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label>Priority</label>
                            <select name="lead_priority" class="input">
                                <option value="low" <?php echo ($lead['lead_priority'] ?? 'medium') === 'low' ? 'selected' : ''; ?>>Low</option>
                                <option value="medium" <?php echo ($lead['lead_priority'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="high" <?php echo ($lead['lead_priority'] ?? 'medium') === 'high' ? 'selected' : ''; ?>>High</option>
                            </select>
                        </div>
                        <div>
                            <label>Interest Level (1-10)</label>
                            <input type="number" name="interest_level" value="<?php echo $lead['interest_level'] ?? 5; ?>" min="1" max="10" class="input">
                        </div>
                    </div>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label>Estimated Monthly Volume (lbs)</label>
                            <input type="number" name="estimated_monthly_volume" value="<?php echo $lead['estimated_monthly_volume'] ?? ''; ?>" step="0.01" class="input">
                        </div>
                        <div>
                            <label>Estimated Deal Value ($)</label>
                            <input type="number" name="estimated_deal_value" value="<?php echo $lead['estimated_deal_value'] ?? ''; ?>" step="0.01" class="input">
                        </div>
                    </div>
                    
                    <div>
                        <label>Next Follow-up</label>
                        <input type="datetime-local" name="next_follow_up" value="<?php echo $lead['next_follow_up'] ? date('Y-m-d\TH:i', strtotime($lead['next_follow_up'])) : ''; ?>" class="input">
                    </div>
                </div>
            </div>

            <!-- Social Media Links -->
            <div style="margin-bottom:32px;">
                <h3 style="margin:0 0 16px 0; color:#1f2937; border-bottom:2px solid #e5e7eb; padding-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                    <span>🔗 Social Media & Links</span>
                    <button type="button" onclick="addSocialLink()" class="btn small" style="background:#059669;">
                        <i class="fa-solid fa-plus"></i> Add Link
                    </button>
                </h3>
                
                <?php 
                $socialLinks = get_lead_social_links($pdo, $leadId);
                $linkCount = count($socialLinks);
                ?>
                
                <?php if (empty($lead['email']) && empty($lead['phone']) && $linkCount === 0): ?>
                <div style="background:#fef3c7; border:1px solid #f59e0b; padding:12px; border-radius:8px; margin-bottom:16px;">
                    <strong>⚠️ Notice:</strong> When email and phone are unavailable, at least one social media link is required for lead completion.
                </div>
                <?php endif; ?>
                
                <div id="socialLinksContainer" style="display:grid; gap:12px;">
                    <?php if (empty($socialLinks)): ?>
                    <div class="muted" style="text-align:center; padding:24px; background:#f9fafb; border-radius:8px;">
                        No social media links added yet. Click "Add Link" to get started.
                    </div>
                    <?php else: ?>
                        <?php foreach ($socialLinks as $link): 
                            $config = get_social_platform_config($link['platform']);
                        ?>
                        <div class="social-link-item" data-link-id="<?php echo $link['id']; ?>" style="display:flex; gap:12px; align-items:center; padding:12px; background:#f9fafb; border-radius:8px; border:1px solid #e5e7eb;">
                            <div style="width:40px; height:40px; display:flex; align-items:center; justify-content:center; background:white; border-radius:8px; color:<?php echo $config['color']; ?>; font-size:20px;">
                                <i class="<?php echo $config['icon']; ?>"></i>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-weight:600; color:#1f2937; font-size:13px;"><?php echo htmlspecialchars($link['display_label'] ?: $config['label']); ?></div>
                                <div style="font-size:12px; color:#6b7280; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener" style="color:#2563eb;">
                                        <?php echo htmlspecialchars($link['url']); ?>
                                    </a>
                                </div>
                            </div>
                            <button type="button" onclick="deleteSocialLink(<?php echo $link['id']; ?>)" class="btn small secondary" style="color:#dc2626;">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <?php if ($linkCount >= 12): ?>
                <div style="background:#fef3c7; border:1px solid #f59e0b; padding:10px; border-radius:6px; margin-top:12px; font-size:13px; color:#92400e;">
                    <i class="fa-solid fa-info-circle"></i> Maximum 12 social links reached
                </div>
                <?php endif; ?>
            </div>

            <!-- Submit Buttons -->
            <div style="display:flex; gap:12px; justify-content:flex-end; padding-top:24px; border-top:2px solid #e5e7eb;">
                <a href="leads.php?id=<?php echo $leadId; ?>" class="btn secondary">Cancel</a>
                <button type="submit" name="submit_action" value="save_and_continue" class="btn" style="background:#7c3aed;">
                    💾 Save & Continue Editing
                </button>
                <button type="submit" name="submit_action" value="save_and_view" class="btn" style="background:#059669;">
                    ✓ Save & View Lead
                </button>
            </div>
        </div>
    </form>
</div>

<style>
.edit-lead-form .input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.2s;
}

.edit-lead-form .input:focus {
    outline: none;
    border-color: #0066FF;
    box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
}

.edit-lead-form label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    color: #374151;
    font-size: 13px;
}

.edit-lead-form h3 {
    display: flex;
    align-items: center;
    gap: 8px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
}

.page-title {
    font-size: 28px;
    margin: 0;
    color: #1f2937;
}

.page-subtitle {
    font-size: 14px;
    margin-top: 4px;
}

.header-actions {
    display: flex;
    gap: 12px;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 16px;
    }
    
    .edit-lead-form .card {
        padding: 16px;
    }
    
    .edit-lead-form div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<!-- Add Social Link Modal -->
<div id="addSocialLinkModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; padding:24px; max-width:500px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <h3 style="margin:0 0 20px 0;">Add Social Media Link</h3>
        
        <div style="display:grid; gap:16px;">
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:600;">Platform</label>
                <select id="socialPlatform" onchange="updatePlatformPreview()" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;">
                    <?php foreach (get_social_platform_options() as $value => $label): ?>
                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:600;">URL *</label>
                <input type="url" id="socialUrl" placeholder="https://..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;" required>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:600;">Custom Label (optional)</label>
                <input type="text" id="socialLabel" placeholder="Leave blank for default" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            
            <div id="platformPreview" style="display:flex; gap:12px; align-items:center; padding:12px; background:#f9fafb; border-radius:8px; border:1px solid #e5e7eb;">
                <div id="previewIcon" style="width:40px; height:40px; display:flex; align-items:center; justify-content:center; background:white; border-radius:8px; font-size:20px;">
                    <i class="fa-brands fa-facebook" style="color:#1877F2;"></i>
                </div>
                <div>
                    <div id="previewLabel" style="font-weight:600; font-size:13px;">Facebook</div>
                    <div style="font-size:12px; color:#6b7280;">Preview</div>
                </div>
            </div>
        </div>
        
        <div style="display:flex; gap:12px; justify-content:end; margin-top:24px;">
            <button type="button" onclick="closeSocialModal()" class="btn secondary">Cancel</button>
            <button type="button" onclick="saveSocialLink()" class="btn" style="background:#059669;">
                <i class="fa-solid fa-save"></i> Save Link
            </button>
        </div>
    </div>
</div>

<script>
const leadId = <?php echo $leadId; ?>;
const apiUrl = '<?php echo api_url('/api/lead-social-links-api.php'); ?>';

const platformConfigs = {
    facebook: {icon: 'fa-brands fa-facebook', color: '#1877F2', label: 'Facebook'},
    instagram: {icon: 'fa-brands fa-instagram', color: '#E4405F', label: 'Instagram'},
    linkedin: {icon: 'fa-brands fa-linkedin', color: '#0A66C2', label: 'LinkedIn'},
    x: {icon: 'fa-brands fa-x-twitter', color: '#000000', label: 'X (Twitter)'},
    reddit: {icon: 'fa-brands fa-reddit', color: '#FF4500', label: 'Reddit'},
    youtube: {icon: 'fa-brands fa-youtube', color: '#FF0000', label: 'YouTube'},
    linktree: {icon: 'fa-solid fa-tree', color: '#43E55E', label: 'LinkTree'},
    beacons: {icon: 'fa-solid fa-link', color: '#7C3AED', label: 'Beacons'},
    twitch: {icon: 'fa-brands fa-twitch', color: '#9146FF', label: 'Twitch'},
    kick: {icon: 'fa-solid fa-k', color: '#53FC18', label: 'Kick'},
    tiktok: {icon: 'fa-brands fa-tiktok', color: '#000000', label: 'TikTok'},
    custom: {icon: 'fa-solid fa-link', color: '#6B7280', label: 'Custom Link'}
};

function addSocialLink() {
    const currentCount = document.querySelectorAll('.social-link-item').length;
    if (currentCount >= 12) {
        alert('Maximum 12 social links allowed');
        return;
    }
    
    document.getElementById('addSocialLinkModal').style.display = 'flex';
    document.getElementById('socialUrl').value = '';
    document.getElementById('socialLabel').value = '';
    document.getElementById('socialPlatform').value = 'facebook';
    updatePlatformPreview();
}

function closeSocialModal() {
    document.getElementById('addSocialLinkModal').style.display = 'none';
}

function updatePlatformPreview() {
    const platform = document.getElementById('socialPlatform').value;
    const config = platformConfigs[platform] || platformConfigs.custom;
    
    document.getElementById('previewIcon').innerHTML = `<i class="${config.icon}" style="color:${config.color};"></i>`;
    document.getElementById('previewLabel').textContent = config.label;
}

async function saveSocialLink() {
    const platform = document.getElementById('socialPlatform').value;
    const url = document.getElementById('socialUrl').value.trim();
    const label = document.getElementById('socialLabel').value.trim();
    
    if (!url) {
        alert('URL is required');
        return;
    }
    
    try {
        const response = await fetch(apiUrl + '?action=add', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                lead_id: leadId,
                platform: platform,
                url: url,
                display_label: label
            })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error || 'Failed to add link');
        }
        
        // Reload page to show new link
        window.location.reload();
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error adding social link: ' + error.message);
    }
}

async function deleteSocialLink(linkId) {
    if (!confirm('Delete this social media link?')) {
        return;
    }
    
    try {
        const response = await fetch(apiUrl + '?action=delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                link_id: linkId
            })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error || 'Failed to delete link');
        }
        
        // Remove from DOM
        const item = document.querySelector(`[data-link-id="${linkId}"]`);
        if (item) {
            item.style.opacity = '0';
            item.style.transform = 'scale(0.8)';
            setTimeout(() => item.remove(), 300);
        }
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error deleting social link: ' + error.message);
    }
}

// Close modal on backdrop click
document.getElementById('addSocialLinkModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeSocialModal();
    }
});

// Auto-detect platform from URL
document.getElementById('socialUrl')?.addEventListener('blur', function() {
    const url = this.value.trim().toLowerCase();
    if (!url) return;
    
    let detectedPlatform = 'custom';
    
    if (url.includes('facebook.com') || url.includes('fb.com')) detectedPlatform = 'facebook';
    else if (url.includes('instagram.com')) detectedPlatform = 'instagram';
    else if (url.includes('linkedin.com')) detectedPlatform = 'linkedin';
    else if (url.includes('twitter.com') || url.includes('x.com')) detectedPlatform = 'x';
    else if (url.includes('reddit.com')) detectedPlatform = 'reddit';
    else if (url.includes('youtube.com') || url.includes('youtu.be')) detectedPlatform = 'youtube';
    else if (url.includes('linktree') || url.includes('linktr.ee')) detectedPlatform = 'linktree';
    else if (url.includes('beacons')) detectedPlatform = 'beacons';
    else if (url.includes('twitch.tv')) detectedPlatform = 'twitch';
    else if (url.includes('kick.com')) detectedPlatform = 'kick';
    else if (url.includes('tiktok.com')) detectedPlatform = 'tiktok';
    
    document.getElementById('socialPlatform').value = detectedPlatform;
    updatePlatformPreview();
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

