<?php
/**
 * Get Agreement Content for Iframe Embedding
 * Returns properly formatted HTML with styling
 */

require_once __DIR__ . '/../required/config.php';

$agreementType = $_GET['type'] ?? '';

if (empty($agreementType)) {
    http_response_code(400);
    echo '<p style="color:#991b1b;padding:20px;">Agreement type required</p>';
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT title, content 
        FROM agreement_templates 
        WHERE agreement_type = ? AND is_active = 1 
        LIMIT 1
    ');
    $stmt->execute([$agreementType]);
    $agreement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$agreement) {
        http_response_code(404);
        echo '<p style="color:#991b1b;padding:20px;">Agreement not found</p>';
        exit;
    }
    
    // Output properly formatted HTML
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($agreement['title']); ?></title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                line-height: 1.6;
                color: #1e293b;
                padding: 20px;
                margin: 0;
                background: #ffffff;
            }
            h2 {
                color: #0f172a;
                font-size: 24px;
                font-weight: 800;
                margin: 0 0 16px 0;
                padding-bottom: 12px;
                border-bottom: 2px solid #e2e8f0;
            }
            h3 {
                color: #334155;
                font-size: 18px;
                font-weight: 700;
                margin: 24px 0 12px 0;
            }
            p {
                margin: 0 0 16px 0;
                color: #475569;
                font-size: 15px;
            }
            ul {
                margin: 12px 0;
                padding-left: 24px;
                color: #475569;
            }
            li {
                margin-bottom: 8px;
                font-size: 14px;
                line-height: 1.6;
            }
            strong {
                color: #0f172a;
                font-weight: 700;
            }
            /* Styling for the final acknowledgment paragraph */
            p[style*="margin-top:24px"] {
                background: #f8fafc;
                border-left: 4px solid #0ea5e9;
                padding: 16px;
                margin-top: 32px;
                border-radius: 4px;
            }
        </style>
    </head>
    <body>
        <?php echo $agreement['content']; ?>
    </body>
    </html>
    <?php
    
} catch (Throwable $e) {
    error_log('Get agreement embed error: ' . $e->getMessage());
    http_response_code(500);
    echo '<p style="color:#991b1b;padding:20px;">Error loading agreement. Please try again.</p>';
}
?>



