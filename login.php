<?php
/**
 * BERNING STORE — ROOT LOGIN ROUTER & AUTHENTICATION ENDPOINT
 * Directly serves API requests and redirects browser navigation to admin_login.html.
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && empty($_GET['action'])) {
    header('Location: login/');
    exit;
}

require_once __DIR__ . '/backend/login.php';
