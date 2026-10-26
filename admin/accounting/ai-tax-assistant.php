<?php
$SEO = [
    'title' => 'AI Tax Assistant — Accounting',
    'description' => 'Get AI-powered tax guidance and recommendations',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';

// Ensure user has accountant or admin role
$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'accountant') && !user_has_role($userId, 'admin'))) {
    flash_set('error', 'Access denied. Accountant or Admin role required.');
    redirect('/login.php');
}
?>

<div class="container-fluid mt-4">
    <h1>AI Tax Assistant</h1>
    <p class="lead">Specialized AI guidance for tax planning and compliance</p>
    
    <div class="alert alert-warning">
        <strong>Disclaimer:</strong> This AI assistant provides educational information only. Always consult a licensed CPA or tax professional for tax advice specific to your situation.
    </div>
    
    <div class="alert alert-info">
        <strong>Coming Soon:</strong> Specialized Gemini AI agent tuned for tax questions with context from your financial data.
    </div>
    
    <div class="text-center py-5">
        <p>This feature is under development. In the meantime, you can use the general AI chat in the admin panel.</p>
        <a href="index.php" class="btn btn-primary">Back to Dashboard</a>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

