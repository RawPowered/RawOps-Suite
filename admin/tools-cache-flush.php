<?php
require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect('/login.php');

// Rotate cache-bust token and send headers to discourage intermediary caches
$token = bump_cache_bust_token();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

flash_set('success', 'Asset cache flushed. New token: ' . sanitize($token));
redirect('/admin/');
?>

