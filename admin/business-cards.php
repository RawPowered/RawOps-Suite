<?php
/**
 * Admin Business Cards Manager
 * List and manage all sales agent business cards
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/business-card-functions.php';

start_session();
$pdo = db();

// Auth check - must be admin
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

// Get all business cards
$stmt = $pdo->query('
    SELECT 
        sabc.*,
        u.first_name, u.last_name, u.email, u.phone,
        bcqr.scan_count, bcqr.vcard_download_count, 
        bcqr.signup_conversion_count, bcqr.sample_request_count,
        bcqr.last_scanned_at
    FROM sales_agent_business_cards sabc
    JOIN users u ON u.id = sabc.agent_id
    LEFT JOIN business_card_qr_codes bcqr ON bcqr.card_id = sabc.id
    ORDER BY u.last_name, u.first_name
');
$cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_scans = 0;
$total_conversions = 0;
$total_samples = 0;
foreach ($cards as $card) {
    $total_scans += intval($card['scan_count']);
    $total_conversions += intval($card['signup_conversion_count']);
    $total_samples += intval($card['sample_request_count']);
}

$page_title = 'Business Cards Manager';
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
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 8px;
            color: #0369a1;
        }
        
        .header p {
            color: #64748b;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card .label {
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #0369a1;
        }
        
        .actions {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .btn {
            padding: 12px 20px;
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
            background: white;
            color: #0369a1;
            border: 2px solid #0369a1;
        }
        
        .btn-secondary:hover {
            background: #0369a1;
            color: white;
        }
        
        .cards-grid {
            display: grid;
            gap: 20px;
        }
        
        .card-item {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 20px;
            display: grid;
            grid-template-columns: 80px 1fr auto;
            gap: 20px;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .card-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .card-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .card-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .card-avatar-placeholder {
            font-size: 24px;
            font-weight: 700;
            color: #64748b;
        }
        
        .card-info h3 {
            font-size: 18px;
            margin-bottom: 4px;
        }
        
        .card-info p {
            color: #64748b;
            font-size: 14px;
        }
        
        .card-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        
        .meta-item {
            font-size: 13px;
        }
        
        .meta-item .label {
            color: #64748b;
            display: block;
        }
        
        .meta-item .value {
            font-weight: 600;
            color: #0369a1;
        }
        
        .card-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .card-actions a,
        .card-actions button {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .action-view {
            background: #0369a1;
            color: white;
        }
        
        .action-view:hover {
            background: #075985;
        }
        
        .action-edit {
            background: #f1f5f9;
            color: #0369a1;
        }
        
        .action-edit:hover {
            background: #e2e8f0;
        }
        
        .action-qr {
            background: #f1f5f9;
            color: #059669;
        }
        
        .action-qr:hover {
            background: #d1fae5;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-active {
            background: #d1fae5;
            color: #059669;
        }
        
        .badge-inactive {
            background: #fee2e2;
            color: #dc2626;
        }
        
        @media (max-width: 768px) {
            .card-item {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .card-actions {
                flex-direction: row;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php require_once __DIR__ . '/admin-menu.php'; ?>
        
        <div class="header">
            <h1><i class="fas fa-address-card"></i> Business Cards Manager</h1>
            <p>Manage sales agent business cards, QR codes, and track performance</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="label">Total Cards</div>
                <div class="value"><?php echo count($cards); ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Total Views</div>
                <div class="value"><?php echo number_format($total_scans); ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Signups</div>
                <div class="value"><?php echo number_format($total_conversions); ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Sample Requests</div>
                <div class="value"><?php echo number_format($total_samples); ?></div>
            </div>
        </div>
        
        <div class="actions">
            <a href="<?php echo path_with_base('/admin/business-card-editor.php'); ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> Create New Card
            </a>
            <button onclick="generateAllQRCodes()" class="btn btn-secondary">
                <i class="fas fa-qrcode"></i> Generate All QR Codes
            </button>
        </div>
        
        <div class="cards-grid">
            <?php foreach ($cards as $card): ?>
                <div class="card-item">
                    <div class="card-avatar">
                        <?php if (!empty($card['profile_photo_path'])): ?>
                            <img src="<?php echo htmlspecialchars($card['profile_photo_path']); ?>" alt="">
                        <?php else: ?>
                            <div class="card-avatar-placeholder">
                                <?php echo strtoupper(substr($card['first_name'], 0, 1) . substr($card['last_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-info">
                        <h3>
                            <?php echo htmlspecialchars($card['first_name'] . ' ' . $card['last_name']); ?>
                            <span class="badge <?php echo $card['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                <?php echo $card['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </h3>
                        <p>
                            <?php echo htmlspecialchars($card['job_title'] ?? 'Sales Agent'); ?> • 
                            /agent/<?php echo htmlspecialchars($card['slug']); ?>/card
                        </p>
                        
                        <div class="card-meta">
                            <div class="meta-item">
                                <span class="label">Views</span>
                                <span class="value"><?php echo number_format($card['scan_count'] ?? 0); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="label">vCard DLs</span>
                                <span class="value"><?php echo number_format($card['vcard_download_count'] ?? 0); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="label">Signups</span>
                                <span class="value"><?php echo number_format($card['signup_conversion_count'] ?? 0); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="label">Samples</span>
                                <span class="value"><?php echo number_format($card['sample_request_count'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-actions">
                        <a href="/agent/<?php echo urlencode($card['slug']); ?>/card" target="_blank" class="action-view">
                            <i class="fas fa-eye"></i> View Card
                        </a>
                        <a href="<?php echo path_with_base('/admin/business-card-editor.php?card_id=' . $card['id']); ?>" class="action-edit">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <button onclick="downloadQR(<?php echo $card['id']; ?>)" class="action-qr">
                            <i class="fas fa-qrcode"></i> Download QR
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($cards)): ?>
                <div style="text-align: center; padding: 60px 20px; color: #64748b;">
                    <i class="fas fa-address-card" style="font-size: 64px; margin-bottom: 20px;"></i>
                    <h3>No business cards yet</h3>
                    <p>Create a business card for your sales agents to get started</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function downloadQR(cardId) {
            window.open('/api/business-card-qr-generate.php?card_id=' + cardId + '&return=image', '_blank');
        }
        
        function generateAllQRCodes() {
            if (!confirm('Generate QR codes for all business cards?')) return;
            
            const cardIds = <?php echo json_encode(array_column($cards, 'id')); ?>;
            let completed = 0;
            
            cardIds.forEach(cardId => {
                fetch('/api/business-card-qr-generate.php?card_id=' + cardId)
                    .then(r => r.json())
                    .then(data => {
                        completed++;
                        if (completed === cardIds.length) {
                            alert('All QR codes generated successfully!');
                            location.reload();
                        }
                    });
            });
        }
    </script>
</body>
</html>



