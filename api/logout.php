<?php
require_once __DIR__ . '/required/config.php';
logout_user();
flash_set('success', 'You have been logged out successfully.');
redirect('/index.php');
?>