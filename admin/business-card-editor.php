<?php
/**
 * Admin Business Card Editor
 * Create and edit business cards for sales agents
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/business-card-functions.php';

start_session();
$pdo = db();

// Auth check
$uid = $_SESSION['uid'] ?? 0;
if (!$uid) {
    header('Location: ' . path_with_base('/login.php'));
    exit;
}

$role_stmt = $pdo->prepare('SELECT role_id FROM user_roles WHERE user_id = ?');
$role_stmt->execute([$uid]);
$roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);
$is_admin = in_array(3, $roles);

if (!$is_admin) {
    header('Location: ' . path_with_base('/'));
    exit;
}

// Get card ID if editing
$card_id = isset($_GET['card_id']) ? intval($_GET['card_id']) : 0;
$agent_id = isset($_GET['agent_id']) ? intval($_GET['agent_id']) : 0;

$card = null;
$social_links = [];

if ($card_id > 0) {
    // Load existing card
    $stmt = $pdo->prepare('
        SELECT sabc.*, u.first_name, u.last_name, u.email, u.phone
        FROM sales_agent_business_cards sabc
        JOIN users u ON u.id = sabc.agent_id
        WHERE sabc.id = ?
    ');
    $stmt->execute([$card_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($card) {
        $agent_id = $card['agent_id'];
        $social_links = get_card_social_links($card_id);
    }
} elseif ($agent_id > 0) {
    // Load agent info for new card
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$agent_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all sales agents for dropdown
$agents_stmt = $pdo->query('
    SELECT u.id, u.first_name, u.last_name, u.email
    FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    WHERE ur.role_id = 2
    ORDER BY u.last_name, u.first_name
');
$all_agents = $agents_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = $card_id > 0 ? 'Edit Business Card' : 'Create Business Card';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Product Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 28px;
            color: #0369a1;
        }
        
        .editor-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
        }
        
        .form-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        
        .form-section h3 {
            font-size: 18px;
            margin-bottom: 16px;
            color: #0369a1;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0369a1;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        
        .photo-upload {
            border: 2px dashed #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .photo-upload:hover {
            border-color: #0369a1;
            background: #f8fafc;
        }
        
        .photo-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 16px;
            background: #e2e8f0;
        }
        
        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .social-links-manager {
            margin-top: 20px;
        }
        
        .social-link-item {
            display: grid;
            grid-template-columns: 150px 1fr auto;
            gap: 12px;
            align-items: center;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #0369a1;
            color: white;
        }
        
        .btn-primary:hover {
            background: #075985;
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #0369a1;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        .preview-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            overflow: hidden;
            position: sticky;
            top: 20px;
        }
        
        .preview-header {
            background: linear-gradient(135deg, #0369a1 0%, #065f8c 100%);
            padding: 30px 20px;
            text-align: center;
            color: white;
        }
        
        .preview-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            margin: 0 auto 16px;
            overflow: hidden;
            background: rgba(255,255,255,0.1);
        }
        
        .preview-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .preview-body {
            padding: 20px;
        }
        
        .qr-section {
            text-align: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            margin-top: 16px;
        }
        
        .qr-section img {
            max-width: 200px;
            border: 4px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <?php require_once __DIR__ . '/admin-menu.php'; ?>
        
        <div class="header">
            <h1><i class="fas fa-edit"></i> <?php echo htmlspecialchars($page_title); ?></h1>
            <a href="<?php echo path_with_base('/admin/business-cards.php'); ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
        
        <div class="editor-grid">
            <div class="editor-form">
                <form id="cardForm" enctype="multipart/form-data">
                    <input type="hidden" name="card_id" value="<?php echo $card_id; ?>">
                    <?php if ($card_id > 0 && $agent_id): ?>
                    <input type="hidden" name="agent_id" value="<?php echo $agent_id; ?>">
                    <?php endif; ?>
                    
                    <div class="form-section">
                        <h3>Agent Selection</h3>
                        <div class="form-group">
                            <label>Sales Agent</label>
                            <select name="<?php echo $card_id > 0 ? 'agent_id_display' : 'agent_id'; ?>" id="agentSelect" required <?php echo $card_id > 0 ? 'disabled' : ''; ?>>
                                <option value="">Select an agent...</option>
                                <?php foreach ($all_agents as $agent): ?>
                                    <option value="<?php echo $agent['id']; ?>" <?php echo ($agent_id == $agent['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Profile Photo</h3>
                        <div class="photo-upload" onclick="document.getElementById('photoInput').click()">
                            <div class="photo-preview" id="photoPreview">
                                <?php if (!empty($card['profile_photo_path'])): ?>
                                    <img src="<?php echo htmlspecialchars($card['profile_photo_path']); ?>" id="previewImg">
                                <?php else: ?>
                                    <img src="" id="previewImg" style="display:none;">
                                <?php endif; ?>
                            </div>
                            <p><strong>Click to upload photo</strong></p>
                            <p style="font-size: 13px; color: #64748b;">JPG, PNG, or WebP (max 5MB)</p>
                        </div>
                        <input type="file" id="photoInput" name="photo" accept="image/jpeg,image/png,image/webp" style="display:none;">
                    </div>
                    
                    <div class="form-section">
                        <h3>Card Information</h3>
                        <div class="form-group">
                            <label>Job Title</label>
                            <input type="text" name="job_title" value="<?php echo htmlspecialchars($card['job_title'] ?? 'Sales Agent'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Bio / Tagline</label>
                            <textarea name="bio" placeholder="Tell customers about yourself..."><?php echo htmlspecialchars($card['bio'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Custom Message (optional)</label>
                            <textarea name="custom_message" placeholder="A special message for your customers..."><?php echo htmlspecialchars($card['custom_message'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Theme Color</label>
                            <input type="color" name="theme_color" value="<?php echo htmlspecialchars($card['theme_color'] ?? '#0369a1'); ?>">
                        </div>
                        <div class="form-group checkbox-group">
                            <input type="checkbox" name="show_email" id="showEmail" <?php echo empty($card) || $card['show_email'] ? 'checked' : ''; ?>>
                            <label for="showEmail">Show email address</label>
                        </div>
                        <div class="form-group checkbox-group">
                            <input type="checkbox" name="show_phone" id="showPhone" <?php echo empty($card) || $card['show_phone'] ? 'checked' : ''; ?>>
                            <label for="showPhone">Show phone number</label>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Social Links</h3>
                        <div id="socialLinksContainer">
                            <?php foreach ($social_links as $link): ?>
                                <div class="social-link-item" data-id="<?php echo $link['id']; ?>">
                                    <select class="platform-select">
                                        <option value="facebook" <?php echo $link['platform'] === 'facebook' ? 'selected' : ''; ?>>Facebook</option>
                                        <option value="instagram" <?php echo $link['platform'] === 'instagram' ? 'selected' : ''; ?>>Instagram</option>
                                        <option value="linkedin" <?php echo $link['platform'] === 'linkedin' ? 'selected' : ''; ?>>LinkedIn</option>
                                        <option value="x" <?php echo $link['platform'] === 'x' ? 'selected' : ''; ?>>X (Twitter)</option>
                                        <option value="tiktok" <?php echo $link['platform'] === 'tiktok' ? 'selected' : ''; ?>>TikTok</option>
                                        <option value="youtube" <?php echo $link['platform'] === 'youtube' ? 'selected' : ''; ?>>YouTube</option>
                                        <option value="website" <?php echo $link['platform'] === 'website' ? 'selected' : ''; ?>>Website</option>
                                        <option value="custom" <?php echo $link['platform'] === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                    </select>
                                    <input type="url" class="url-input" placeholder="https://..." value="<?php echo htmlspecialchars($link['url']); ?>">
                                    <button type="button" class="btn btn-danger btn-small" onclick="removeSocialLink(this, <?php echo $link['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="addSocialLink()">
                            <i class="fas fa-plus"></i> Add Social Link
                        </button>
                    </div>
                    
                    <div style="display: flex; gap: 12px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Business Card
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="preview-sidebar">
                <div class="preview-card">
                    <div class="preview-header" id="previewHeaderStyle">
                        <div class="logo">PRODUCT™</div>
                        <div class="preview-avatar">
                            <img src="" id="sidePreviewImg" style="display:none;">
                        </div>
                        <h2 id="previewName">Agent Name</h2>
                        <p id="previewTitle">Sales Agent</p>
                    </div>
                    <div class="preview-body">
                        <p id="previewBio" style="color: #64748b; font-size: 14px; margin-bottom: 16px;"></p>
                        <div id="previewMessage" style="background: #f1f5f9; padding: 12px; border-radius: 8px; display: none; font-size: 13px;"></div>
                    </div>
                </div>
                
                <?php if ($card_id > 0): ?>
                <div class="qr-section">
                    <h4 style="margin-bottom: 12px;">QR Code</h4>
                    <img src="/api/business-card-qr-generate.php?card_id=<?php echo $card_id; ?>&return=image" alt="QR Code">
                    <p style="margin-top: 12px; font-size: 13px; color: #64748b;">
                        <a href="/api/business-card-qr-generate.php?card_id=<?php echo $card_id; ?>&return=image" download class="btn btn-secondary btn-small">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        let cardId = <?php echo $card_id; ?>;
        
        // Photo preview
        document.getElementById('photoInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('previewImg').style.display = 'block';
                    document.getElementById('sidePreviewImg').src = e.target.result;
                    document.getElementById('sidePreviewImg').style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Live preview updates
        document.querySelector('[name="job_title"]').addEventListener('input', function() {
            document.getElementById('previewTitle').textContent = this.value || 'Sales Agent';
        });
        
        document.querySelector('[name="bio"]').addEventListener('input', function() {
            const preview = document.getElementById('previewBio');
            if (this.value) {
                preview.textContent = this.value;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
        });
        
        document.querySelector('[name="custom_message"]').addEventListener('input', function() {
            const preview = document.getElementById('previewMessage');
            if (this.value) {
                preview.textContent = this.value;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
        });
        
        document.querySelector('[name="theme_color"]').addEventListener('input', function() {
            document.getElementById('previewHeaderStyle').style.background = 
                `linear-gradient(135deg, ${this.value} 0%, ${adjustColor(this.value, -20)} 100%)`;
        });
        
        // Agent selection
        document.getElementById('agentSelect').addEventListener('change', function() {
            if (this.value && !cardId) {
                const selectedOption = this.options[this.selectedIndex];
                const name = selectedOption.textContent.trim();
                document.getElementById('previewName').textContent = name;
            }
        });
        
        // Social links management
        function addSocialLink() {
            const container = document.getElementById('socialLinksContainer');
            const item = document.createElement('div');
            item.className = 'social-link-item';
            item.innerHTML = `
                <select class="platform-select">
                    <option value="facebook">Facebook</option>
                    <option value="instagram">Instagram</option>
                    <option value="linkedin">LinkedIn</option>
                    <option value="x">X (Twitter)</option>
                    <option value="tiktok">TikTok</option>
                    <option value="youtube">YouTube</option>
                    <option value="website">Website</option>
                    <option value="custom">Custom</option>
                </select>
                <input type="url" class="url-input" placeholder="https://...">
                <button type="button" class="btn btn-danger btn-small" onclick="this.parentElement.remove()">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(item);
        }
        
        function removeSocialLink(btn, linkId) {
            if (linkId && !confirm('Delete this social link?')) return;
            
            if (linkId) {
                fetch('/api/business-card-api.php?action=delete_social_link', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'link_id=' + linkId
                });
            }
            
            btn.parentElement.remove();
        }
        
        // Form submission
        document.getElementById('cardForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const agentId = formData.get('agent_id');
            
            // Upload photo first if changed
            const photoFile = formData.get('photo');
            if (photoFile && photoFile.size > 0) {
                const photoData = new FormData();
                photoData.append('photo', photoFile);
                photoData.append('agent_id', agentId);
                photoData.append('action', 'upload_photo');
                
                const photoResp = await fetch('/api/business-card-api.php', {
                    method: 'POST',
                    body: photoData
                });
                const photoResult = await photoResp.json();
                
                if (!photoResult.success) {
                    alert('Photo upload failed: ' + photoResult.error);
                    return;
                }
                
                formData.set('profile_photo_path', photoResult.path);
                formData.set('profile_photo_updated', '1');
            }
            
            // Save card data
            const cardData = new FormData();
            cardData.append('action', 'save');
            cardData.append('agent_id', agentId);
            cardData.append('job_title', formData.get('job_title'));
            cardData.append('bio', formData.get('bio'));
            cardData.append('custom_message', formData.get('custom_message'));
            cardData.append('theme_color', formData.get('theme_color'));
            if (formData.get('show_email')) cardData.append('show_email', '1');
            if (formData.get('show_phone')) cardData.append('show_phone', '1');
            if (formData.get('profile_photo_path')) cardData.append('profile_photo_path', formData.get('profile_photo_path'));
            if (formData.get('profile_photo_updated')) cardData.append('profile_photo_updated', '1');
            
            const cardResp = await fetch('/api/business-card-api.php', {
                method: 'POST',
                body: cardData
            });
            const cardResult = await cardResp.json();
            
            if (!cardResult.success) {
                alert('Error saving card: ' + cardResult.error);
                return;
            }
            
            const savedCardId = cardResult.card_id;
            
            // Save social links
            const socialItems = document.querySelectorAll('.social-link-item');
            for (const item of socialItems) {
                const platform = item.querySelector('.platform-select').value;
                const url = item.querySelector('.url-input').value;
                const linkId = item.dataset.id;
                
                if (!url) continue;
                
                const linkData = new FormData();
                linkData.append('card_id', savedCardId);
                linkData.append('platform', platform);
                linkData.append('url', url);
                linkData.append('icon_class', ''); // Will use default
                
                if (linkId) {
                    linkData.append('action', 'update_social_link');
                    linkData.append('link_id', linkId);
                } else {
                    linkData.append('action', 'add_social_link');
                }
                
                await fetch('/api/business-card-api.php', {
                    method: 'POST',
                    body: linkData
                });
            }
            
            alert('Business card saved successfully!');
            window.location.href = '/admin/business-cards.php';
        });
        
        function adjustColor(color, amount) {
            return '#' + color.replace(/^#/, '').replace(/../g, color => ('0'+Math.min(255, Math.max(0, parseInt(color, 16) + amount)).toString(16)).substr(-2));
        }
        
        // Initialize preview
        <?php if ($card): ?>
        document.getElementById('previewName').textContent = <?php echo json_encode($card['first_name'] . ' ' . $card['last_name']); ?>;
        document.getElementById('previewTitle').textContent = <?php echo json_encode($card['job_title'] ?? 'Sales Agent'); ?>;
        <?php if (!empty($card['bio'])): ?>
        document.getElementById('previewBio').textContent = <?php echo json_encode($card['bio']); ?>;
        document.getElementById('previewBio').style.display = 'block';
        <?php endif; ?>
        <?php if (!empty($card['custom_message'])): ?>
        document.getElementById('previewMessage').textContent = <?php echo json_encode($card['custom_message']); ?>;
        document.getElementById('previewMessage').style.display = 'block';
        <?php endif; ?>
        <?php if (!empty($card['profile_photo_path'])): ?>
        document.getElementById('sidePreviewImg').src = <?php echo json_encode($card['profile_photo_path']); ?>;
        document.getElementById('sidePreviewImg').style.display = 'block';
        <?php endif; ?>
        <?php endif; ?>
    </script>
</body>
</html>

