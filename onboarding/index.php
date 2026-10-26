<?php
// Onboarding Directory Handler
require_once __DIR__ . '/../required/config.php';

// Check if user is logged in
$uid = current_user_id();
if (!$uid) {
    // Not logged in - redirect to login
    redirect('/login.php');
}

// Determine where to send based on user role
if (user_has_role($uid, 'admin')) {
    redirect('/admin/');
} elseif (user_has_role($uid, 'sales_agent')) {
    redirect('/onboarding/sales-agent/');
} elseif (user_has_role($uid, 'operations')) {
    redirect('/operations/');
} elseif (user_has_role($uid, 'customer')) {
    redirect('/customer/');
} else {
    // Default to trainee portal
    redirect('/onboarding/trainee/');
}
?>
