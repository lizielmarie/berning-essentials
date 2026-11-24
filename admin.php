<?php
/**
 * BERNING STORE — ROOT GATEWAY FOR OWNER MANAGEMENT PORTAL
 */
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';

$user = bs_validate_session($pdo);

if (!$user) {
    header('Location: login/');
    exit;
}

$userRole = strtolower(trim($user['role'] ?? ''));
if ($userRole !== 'owner') {
    // Access denied for non-owner: preserve session and redirect with error parameter
    header('Location: login/?error=unauthorized');
    exit;
}

$adminHtml = file_exists(__DIR__ . '/admin.html') ? __DIR__ . '/admin.html' : __DIR__ . '/frontend/admin.html';
if (file_exists($adminHtml)) {
    readfile($adminHtml);
    exit;
} else {
    http_response_code(404);
    echo "<h1>Admin Dashboard (admin.html) not found.</h1>";
    exit;
}
