<?php
require_once __DIR__ . '/../required/config.php';
logout_admin();
flash_set('success', 'You have been logged out from admin portal.');
redirect('/admin/');
?>


