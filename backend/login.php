<?php
/**
 * BERNING STORE — API: USER AUTHENTICATION & LOGIN GATEWAY
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'POST' ? 'login' : 'check');

try {
    switch ($action) {
        // Check current session validity
        case 'check':
            $user = bs_validate_session($pdo);
            if ($user) {
                echo json_encode([
                    'success'       => true,
                    'authenticated' => true,
                    'token'         => $user['token'] ?? '',
                    'user'          => [
                        'user_id'   => (int)$user['user_id'],
                        'full_name' => $user['full_name'],
                        'username'  => $user['username'],
                        'email'     => $user['email'] ?? '',
                        'phone'     => $user['phone'] ?? '',
                        'role'      => $user['role']
                    ]
                ]);
            } else {
                echo json_encode([
                    'success'       => true,
                    'authenticated' => false,
                    'message'       => 'No active session.'
                ]);
            }
            break;

        // Perform login
        case 'login':
            $input    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $username = trim($input['username'] ?? ($input['login'] ?? ($input['email'] ?? '')));
            $password = trim($input['password'] ?? '');

            if ($username === '' || $password === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please provide both username or email and password.']);
                exit;
            }

            // Normalize common username aliases to existing database accounts
            $lookupName = $username;
            $lowerUser  = strtolower($username);
            if (in_array($lowerUser, ['owner_admin', 'berning_owner', 'admin', 'owner', 'berning'], true)) {
                $lookupName = 'owner_admin';
            } elseif (in_array($lowerUser, ['liziel_cashier', 'cashier', 'liziel'], true)) {
                $lookupName = 'liziel_cashier';
            } elseif (in_array($lowerUser, ['chrystal_attendant', 'attendant', 'chrystal'], true)) {
                $lookupName = 'chrystal_attendant';
            }

            $stmt = $pdo->prepare("
                SELECT user_id, full_name, username, email, phone, password, role, status 
                FROM users 
                WHERE (username = :u_name OR username = :u_orig OR (email = :u_email AND email IS NOT NULL AND email != '')) 
                LIMIT 1
            ");
            $stmt->execute([':u_name' => $lookupName, ':u_orig' => $username, ':u_email' => $username]);
            $user = $stmt->fetch();

            if (!$user) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Invalid username/email or password.']);
                exit;
            }

            if ($user['status'] !== 'active') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'This account has been deactivated. Please contact store management.']);
                exit;
            }

            // Verify password using bcrypt or plain text fallback
            $isValid = password_verify($password, $user['password']) || ($user['password'] === $password);

            if (!$isValid) {
                bs_log_audit($pdo, (int)$user['user_id'], 'LOGIN_FAILED', 'USERS', (int)$user['user_id'], "Failed login attempt for username/email: $username");
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Invalid username/email or password.']);
                exit;
            }

            // Strict role authorization: Only Owner, Cashier, and Store Attendant roles are permitted
            $userRoleLower = strtolower(trim($user['role'] ?? ''));
            if (!in_array($userRoleLower, ['owner', 'cashier', 'store attendant'], true)) {
                bs_log_audit($pdo, (int)$user['user_id'], 'LOGIN_DENIED_UNAUTHORIZED_ROLE', 'USERS', (int)$user['user_id'], "Denied login: role '{$user['role']}' is unauthorized");
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => "Access denied: Account '{$user['username']}' has an unauthorized role. Only Owner and Cashier roles are permitted to access the system."
                ]);
                exit;
            }

            // Optional role restriction for portal-specific logins
            $requiredRole = trim($input['require_role'] ?? ($_GET['require_role'] ?? ''));
            if ($requiredRole !== '' && strtolower($user['role']) !== strtolower($requiredRole)) {
                bs_log_audit($pdo, (int)$user['user_id'], 'LOGIN_DENIED_ROLE', 'USERS', (int)$user['user_id'], "Denied login: role '{$user['role']}' does not match required role '{$requiredRole}'");
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => "Access denied: Account '{$user['username']}' has role '{$user['role']}'. Access to this portal requires '{$requiredRole}'."
                ]);
                exit;
            }

            // If stored password was plain text, upgrade to bcrypt on the fly
            if (strpos($user['password'], '$2y$') !== 0) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET password = :p WHERE user_id = :id")->execute([':p' => $newHash, ':id' => $user['user_id']]);
            }

            // Update last_login
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = :id")->execute([':id' => $user['user_id']]);

            // Create database session token
            $token = bs_create_session($pdo, (int)$user['user_id'], $user['role']);

            // Log successful login
            bs_log_audit($pdo, (int)$user['user_id'], 'USER_LOGIN', 'USERS', (int)$user['user_id'], "User logged in with role: {$user['role']}");

            echo json_encode([
                'success'       => true,
                'authenticated' => true,
                'token'         => $token,
                'message'       => 'Login successful. Welcome back, ' . $user['full_name'] . '!',
                'user'          => [
                    'user_id'   => (int)$user['user_id'],
                    'full_name' => $user['full_name'],
                    'username'  => $user['username'],
                    'email'     => $user['email'] ?? '',
                    'phone'     => $user['phone'] ?? '',
                    'role'      => $user['role']
                ]
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown login action.']);
            break;
    }

} catch (\PDOException $e) {
    error_log('Login PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to connect to the database. Please contact the administrator.']);
} catch (\Throwable $e) {
    error_log('Login general error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An authentication error occurred. Please contact the administrator.']);
}
