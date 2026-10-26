<?php
/**
 * Set user as retail customer
 * Assigns retail_customer role and redirects to shop
 */
require_once __DIR__ . '/../required/config.php';

start_session();

$uid = current_user_id();
if (!$uid) {
    flash_set('error', 'Please log in first.');
    redirect(path_with_base('/login.php'));
}

try {
    $pdo = db();
    
    // Check if retail_customer role exists
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = "retail_customer" LIMIT 1');
    $stmt->execute();
    $role = $stmt->fetch();
    
    if (!$role) {
        // Create retail_customer role if it doesn't exist
        $stmt = $pdo->prepare('INSERT INTO roles (name, description, created_at) VALUES ("retail_customer", "Retail customer with MSRP pricing", NOW())');
        $stmt->execute();
        $roleId = $pdo->lastInsertId();
    } else {
        $roleId = $role['id'];
    }
    
    // Check if user already has this role
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ? LIMIT 1');
    $stmt->execute([$uid, $roleId]);
    
    if (!$stmt->fetch()) {
        // Assign retail_customer role
        $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?, ?, NOW())');
        $stmt->execute([$uid, $roleId]);
    }
    
    flash_set('success', 'Welcome to our retail shop! You\'ll see MSRP pricing.');
    redirect(path_with_base('/shop/'));
    
} catch (Exception $e) {
    error_log('Error setting retail customer: ' . $e->getMessage());
    flash_set('error', 'An error occurred. Please try again.');
    redirect(path_with_base('/shop/'));
}




