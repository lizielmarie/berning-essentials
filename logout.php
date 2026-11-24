<?php
/**
 * BERNING STORE — API: USER LOGOUT
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

try {
    $user = bs_validate_session($pdo);
    if ($user) {
        bs_log_audit($pdo, (int)$user['user_id'], 'USER_LOGOUT', 'USERS', (int)$user['user_id'], "User logged out");
    }

    bs_destroy_session($pdo);

    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully.'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Logout error: ' . $e->getMessage()
    ]);
}
