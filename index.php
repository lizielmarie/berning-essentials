<?php
/**
 * ==============================================================================
 * BERNING ESSENTIALS — CENTRAL APPLICATION ROUTER
 * ==============================================================================
 * Directs traffic based on authenticated session and user role.
 * Only two interfaces are supported:
 * 1. Owner/Admin Portal (admin.html via admin.php)
 * 2. Cashier POS (cashier.html)
 *
 * Customer interface has been permanently removed.
 * ==============================================================================
 */
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';

// Validate active user session via database token or cookie
$user = bs_validate_session($pdo);

if ($user) {
    $role = strtolower(trim($user['role'] ?? ''));
    if ($role === 'owner') {
        // Authenticated Owner -> Owner Management Portal
        header('Location: admin/');
        exit;
    } elseif ($role === 'cashier' || $role === 'store attendant') {
        // Authenticated Staff/Cashier -> Cashier Point of Sale
        header('Location: cashier/');
        exit;
    } else {
        // Unauthorized or customer role -> Destroy session and deny access
        bs_destroy_session($pdo);
        header('Location: login/?error=access_denied');
        exit;
    }
}

// Unauthenticated user -> Staff & Management Login Portal
header('Location: login/');
exit;
