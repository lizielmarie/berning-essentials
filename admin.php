<?php
/**
 * BERNING STORE — OWNER MANAGEMENT PORTAL (SERVER-SIDE GATEWAY)
 * Strict server-side verification: Only authenticated accounts with role 'Owner'
 * are served the dashboard. All others are redirected appropriately.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = bs_validate_session($pdo);

if (!$user) {
    header('Location: ../admin_login.html');
    exit;
}

$userRole = strtolower(trim($user['role'] ?? ''));
if ($userRole !== 'owner') {
    header('Location: ../admin_login.html?error=unauthorized');
    exit;
}

// Serve the dashboard HTML
$adminHtmlPath = __DIR__ . '/../admin.html';
if (!file_exists($adminHtmlPath)) {
    $adminHtmlPath = __DIR__ . '/admin.html';
}
if (!file_exists($adminHtmlPath)) {
    $adminHtmlPath = __DIR__ . '/../frontend/admin.html';
}

if (file_exists($adminHtmlPath)) {
    readfile($adminHtmlPath);
    exit;
} else {
    http_response_code(404);
    echo "<h1>Berning Store: Admin Dashboard file (admin.html) not found.</h1>";
    exit;
}
