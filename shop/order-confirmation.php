<?php
// Order Confirmation - Redirect to Order Success
require_once __DIR__ . '/../required/config.php';

// Redirect to the actual order success page with query parameters
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirectUrl = '/shop/order-success.php' . ($queryString ? '?' . $queryString : '');

redirect($redirectUrl);
?>
