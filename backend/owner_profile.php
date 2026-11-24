<?php
/**
 * BERNING STORE — API: OWNER PROFILE & ACCOUNT SETTINGS
 * Protected endpoint: Requires authenticated user with role 'Owner'
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Strictly enforce Owner authentication
$currentUser = bs_require_auth($pdo, ['Owner']);
$userId      = (int)$currentUser['user_id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'POST' ? 'update_profile' : 'get');

try {
    switch ($action) {
        // -------------------------------------------------------------
        // Get current owner profile
        // -------------------------------------------------------------
        case 'get':
            $stmt = $pdo->prepare("
                SELECT user_id, full_name, username, email, phone, role, status, created_at, last_login
                FROM users
                WHERE user_id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $profile = $stmt->fetch();

            if (!$profile) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Owner account not found.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'profile' => [
                    'user_id'    => (int)$profile['user_id'],
                    'full_name'  => $profile['full_name'],
                    'username'   => $profile['username'],
                    'email'      => $profile['email'] ?? '',
                    'phone'      => $profile['phone'] ?? '',
                    'role'       => $profile['role'],
                    'status'     => $profile['status'],
                    'created_at' => $profile['created_at'],
                    'last_login' => $profile['last_login']
                ]
            ]);
            break;

        // -------------------------------------------------------------
        // Update owner profile details (Full name, username, email, phone)
        // -------------------------------------------------------------
        case 'update_profile':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
                exit;
            }

            $rawInput = file_get_contents('php://input');
            $data     = json_decode($rawInput, true) ?? $_POST;

            $fullName = trim($data['full_name'] ?? '');
            $username = trim($data['username'] ?? '');
            $email    = trim($data['email'] ?? '');
            $phone    = trim($data['phone'] ?? ($data['contact_number'] ?? ''));

            if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please enter a valid full name (2 to 100 characters).']);
                exit;
            }

            if (mb_strlen($username) < 3 || mb_strlen($username) > 50 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Username must be 3-50 characters with alphanumeric, dot, hyphen, or underscore.']);
                exit;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
                exit;
            }

            if (mb_strlen($phone) < 7 || mb_strlen($phone) > 30) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please provide a valid contact number (7 to 30 characters).']);
                exit;
            }

            // Check if username is taken by another account
            $uCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = :u AND user_id != :id LIMIT 1");
            $uCheck->execute([':u' => $username, ':id' => $userId]);
            if ($uCheck->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "The username '$username' is already in use by another user."]);
                exit;
            }

            // Check if email is taken by another account
            $eCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = :e AND user_id != :id LIMIT 1");
            $eCheck->execute([':e' => $email, ':id' => $userId]);
            if ($eCheck->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "The email '$email' is already in use by another user."]);
                exit;
            }

            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET full_name = :fn, username = :u, email = :e, phone = :p 
                WHERE user_id = :id
            ");
            $updateStmt->execute([
                ':fn' => $fullName,
                ':u'  => $username,
                ':e'  => $email,
                ':p'  => $phone,
                ':id' => $userId
            ]);

            bs_log_audit($pdo, $userId, 'OWNER_PROFILE_UPDATED', 'USERS', $userId, "Owner updated profile: $fullName ($username)");

            echo json_encode([
                'success' => true,
                'message' => 'Owner profile information updated successfully.',
                'profile' => [
                    'user_id'   => $userId,
                    'full_name' => $fullName,
                    'username'  => $username,
                    'email'     => $email,
                    'phone'     => $phone,
                    'role'      => 'Owner'
                ]
            ]);
            break;

        // -------------------------------------------------------------
        // Change owner password
        // -------------------------------------------------------------
        case 'change_password':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
                exit;
            }

            $rawInput = file_get_contents('php://input');
            $data     = json_decode($rawInput, true) ?? $_POST;

            $currentPassword = (string)($data['current_password'] ?? '');
            $newPassword     = (string)($data['new_password'] ?? '');
            $confirmPassword = (string)($data['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please fill in all password fields.']);
                exit;
            }

            if (strlen($newPassword) < 5) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'New password must be at least 5 characters long.']);
                exit;
            }

            if ($newPassword !== $confirmPassword) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'New password and confirm password do not match.']);
                exit;
            }

            // Fetch stored password hash
            $pwdStmt = $pdo->prepare("SELECT password FROM users WHERE user_id = :id");
            $pwdStmt->execute([':id' => $userId]);
            $storedHash = $pwdStmt->fetchColumn();

            // Verify current password
            if (!password_verify($currentPassword, $storedHash) && $storedHash !== $currentPassword) {
                bs_log_audit($pdo, $userId, 'PASSWORD_CHANGE_FAILED', 'USERS', $userId, "Failed password change: Incorrect current password");
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Current password entered is incorrect.']);
                exit;
            }

            // Hash new password using bcrypt
            $hashedNewPassword = password_hash($newPassword, PASSWORD_BCRYPT);

            $updatePwdStmt = $pdo->prepare("UPDATE users SET password = :p WHERE user_id = :id");
            $updatePwdStmt->execute([':p' => $hashedNewPassword, ':id' => $userId]);

            bs_log_audit($pdo, $userId, 'PASSWORD_CHANGED', 'USERS', $userId, "Owner successfully updated account password");

            echo json_encode([
                'success' => true,
                'message' => 'Your password has been changed successfully.'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
            break;
    }
} catch (\PDOException $e) {
    error_log('Owner profile PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred while processing profile.']);
} catch (\Throwable $e) {
    error_log('Owner profile general error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing profile request.']);
}
