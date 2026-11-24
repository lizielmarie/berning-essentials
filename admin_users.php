<?php
/**
 * BERNING STORE — API: USER & CASHIER MANAGEMENT (CRUD & AUTH)
 * Table: `users` in `berning_store`
 * Roles: 'Owner', 'Cashier', 'Store Attendant'
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'POST' ? 'save' : 'list');

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $role   = trim($_GET['role'] ?? '');
            $status = trim($_GET['status'] ?? '');

            $sql = "
                SELECT 
                    u.user_id,
                    u.full_name,
                    u.username,
                    u.email,
                    u.phone,
                    u.role,
                    u.status,
                    u.last_login,
                    u.created_at,
                    (SELECT COUNT(*) FROM sales s WHERE s.cashier_id = u.user_id) AS sales_processed_count,
                    (SELECT COUNT(*) FROM stock_in si WHERE si.received_by = u.user_id) AS stockin_processed_count
                FROM users u
                WHERE 1=1
            ";

            $params = [];
            if ($search !== '') {
                $sql .= " AND (u.full_name LIKE :s_name OR u.username LIKE :s_user)";
                $params[':s_name'] = "%$search%";
                $params[':s_user'] = "%$search%";
            }

            if ($role !== '' && in_array($role, ['Owner', 'Cashier', 'Store Attendant'])) {
                $sql .= " AND u.role = :role";
                $params[':role'] = $role;
            }

            if ($status !== '' && in_array($status, ['active', 'inactive'])) {
                $sql .= " AND u.status = :status";
                $params[':status'] = $status;
            }

            $sql .= " ORDER BY u.user_id ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll();

            $formatted = array_map(function($u) {
                return [
                    'user_id'                 => (int)$u['user_id'],
                    'full_name'               => $u['full_name'],
                    'username'                => $u['username'],
                    'email'                   => $u['email'] ?? '',
                    'phone'                   => $u['phone'] ?? '',
                    'role'                    => $u['role'],
                    'status'                  => $u['status'] ?? 'active',
                    'last_login'              => $u['last_login'],
                    'created_at'              => $u['created_at'],
                    'sales_processed_count'   => (int)$u['sales_processed_count'],
                    'stockin_processed_count' => (int)$u['stockin_processed_count']
                ];
            }, $users);

            echo json_encode([
                'success' => true,
                'count'   => count($formatted),
                'users'   => $formatted
            ]);
            break;

        case 'create':
        case 'add':
            $input    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $fullName = trim($input['full_name'] ?? '');
            $username = trim($input['username'] ?? '');
            $email    = trim($input['email'] ?? '');
            $phone    = trim($input['phone'] ?? ($input['contact_number'] ?? ''));
            $password = trim($input['password'] ?? '');
            $role     = trim($input['role'] ?? 'Cashier');
            $status   = trim($input['status'] ?? 'active');

            if ($fullName === '' || $username === '' || $password === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Full Name, Username, and Password are required.']);
                exit;
            }

            $allowedRoles = ['Owner', 'Cashier', 'Store Attendant'];
            if (!in_array($role, $allowedRoles)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid role selected. Allowed: Owner, Cashier, Store Attendant.']);
                exit;
            }

            // Security guard: If assigning Owner role, must be an authenticated Owner!
            if ($role === 'Owner') {
                $sessionUser = bs_validate_session($pdo);
                if (!$sessionUser || $sessionUser['role'] !== 'Owner') {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Only an authenticated Store Owner can create another Owner account.']);
                    exit;
                }
            }

            // Check duplicate username
            $check = $pdo->prepare("SELECT user_id FROM users WHERE username = :user");
            $check->execute([':user' => $username]);
            if ($check->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Username '$username' is already taken."]);
                exit;
            }

            // Check duplicate email if provided
            if ($email !== '') {
                $eCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = :email");
                $eCheck->execute([':email' => $email]);
                if ($eCheck->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Email '$email' is already registered."]);
                    exit;
                }
            }

            // Secure bcrypt password hash
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, username, email, phone, password, role, status, created_at)
                VALUES (:name, :user, :email, :phone, :pass, :role, :status, NOW())
            ");
            $stmt->execute([
                ':name'   => $fullName,
                ':user'   => $username,
                ':email'  => $email ?: null,
                ':phone'  => $phone ?: null,
                ':pass'   => $hashedPassword,
                ':role'   => $role,
                ':status' => in_array($status, ['active', 'inactive']) ? $status : 'active'
            ]);

            $newId = (int)$pdo->lastInsertId();
            bs_log_audit($pdo, null, 'USER_CREATED', 'USERS', $newId, "Created user $username ($role)");

            echo json_encode([
                'success' => true,
                'user_id' => $newId,
                'message' => "User '{$fullName}' added successfully as {$role}."
            ]);
            break;

        case 'update':
        case 'edit':
            $input    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $userId   = (int)($input['user_id'] ?? 0);
            $fullName = trim($input['full_name'] ?? '');
            $username = trim($input['username'] ?? '');
            $email    = trim($input['email'] ?? '');
            $phone    = trim($input['phone'] ?? ($input['contact_number'] ?? ''));
            $password = trim($input['password'] ?? '');
            $role     = trim($input['role'] ?? '');
            $status   = trim($input['status'] ?? '');

            if ($userId <= 0 || $fullName === '' || $username === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid User ID, Full Name, and Username are required.']);
                exit;
            }

            $allowedRoles = ['Owner', 'Cashier', 'Store Attendant'];
            if (!in_array($role, $allowedRoles)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid role selected.']);
                exit;
            }

            // Check unique username
            $check = $pdo->prepare("SELECT user_id FROM users WHERE username = :user AND user_id != :id");
            $check->execute([':user' => $username, ':id' => $userId]);
            if ($check->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Username '$username' is already in use by another staff member."]);
                exit;
            }

            if ($email !== '') {
                $eCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :id");
                $eCheck->execute([':email' => $email, ':id' => $userId]);
                if ($eCheck->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Email '$email' is already in use by another staff member."]);
                    exit;
                }
            }

            $statusVal = in_array($status, ['active', 'inactive']) ? $status : 'active';

            if ($password !== '') {
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = :name, username = :user, email = :email, phone = :phone, password = :pass, role = :role, status = :status 
                    WHERE user_id = :id
                ");
                $stmt->execute([
                    ':name'   => $fullName,
                    ':user'   => $username,
                    ':email'  => $email ?: null,
                    ':phone'  => $phone ?: null,
                    ':pass'   => $hashedPassword,
                    ':role'   => $role,
                    ':status' => $statusVal,
                    ':id'     => $userId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = :name, username = :user, email = :email, phone = :phone, role = :role, status = :status 
                    WHERE user_id = :id
                ");
                $stmt->execute([
                    ':name'   => $fullName,
                    ':user'   => $username,
                    ':email'  => $email ?: null,
                    ':phone'  => $phone ?: null,
                    ':role'   => $role,
                    ':status' => $statusVal,
                    ':id'     => $userId
                ]);
            }

            bs_log_audit($pdo, null, 'USER_UPDATED', 'USERS', $userId, "Updated user $username ($role)");

            echo json_encode([
                'success' => true,
                'message' => "User '{$fullName}' updated successfully."
            ]);
            break;

        case 'toggle_status':
            $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $userId = (int)($input['user_id'] ?? 0);

            if ($userId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid User ID is required.']);
                exit;
            }

            // Don't allow deactivating the last remaining Owner
            $ownerCheck = $pdo->prepare("SELECT role FROM users WHERE user_id = :id");
            $ownerCheck->execute([':id' => $userId]);
            $targetUser = $ownerCheck->fetch();
            if ($targetUser && $targetUser['role'] === 'Owner') {
                $ownerCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Owner' AND status = 'active'")->fetchColumn();
                if ($ownerCount <= 1) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Cannot deactivate the only active Owner account.']);
                    exit;
                }
            }

            $current = $pdo->prepare("SELECT status, full_name, username FROM users WHERE user_id = :id");
            $current->execute([':id' => $userId]);
            $u = $current->fetch();
            if (!$u) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit;
            }

            $newStatus = ($u['status'] === 'active') ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE users SET status = :status WHERE user_id = :id");
            $upd->execute([':status' => $newStatus, ':id' => $userId]);

            bs_log_audit($pdo, null, 'USER_STATUS_CHANGE', 'USERS', $userId, "Status toggled to $newStatus for {$u['username']}");

            echo json_encode([
                'success'    => true,
                'new_status' => $newStatus,
                'message'    => "User '{$u['full_name']}' is now {$newStatus}."
            ]);
            break;

        case 'change_role':
            $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $userId = (int)($input['user_id'] ?? 0);
            $role   = trim($input['role'] ?? '');

            $allowedRoles = ['Owner', 'Cashier', 'Store Attendant'];
            if ($userId <= 0 || !in_array($role, $allowedRoles)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid User ID and Role are required.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE user_id = :id");
            $stmt->execute([':role' => $role, ':id' => $userId]);

            bs_log_audit($pdo, null, 'USER_ROLE_CHANGE', 'USERS', $userId, "Role updated to $role");

            echo json_encode([
                'success' => true,
                'message' => "Role updated to '{$role}'."
            ]);
            break;

        case 'reset_password':
            $sessionUser = bs_require_auth($pdo, ['Owner']);
            $input       = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $userId      = (int)($input['user_id'] ?? 0);
            $newPassword = trim($input['password'] ?? ($input['new_password'] ?? ''));

            if ($userId <= 0 || strlen($newPassword) < 5) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid User ID and new password (min 5 characters) are required.']);
                exit;
            }

            $uCheck = $pdo->prepare("SELECT user_id, full_name, username FROM users WHERE user_id = :id");
            $uCheck->execute([':id' => $userId]);
            $targetUser = $uCheck->fetch();

            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Staff user not found.']);
                exit;
            }

            $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = :p WHERE user_id = :id")->execute([':p' => $hashed, ':id' => $userId]);

            bs_log_audit($pdo, (int)$sessionUser['user_id'], 'USER_PASSWORD_RESET', 'USERS', $userId, "Owner reset password for {$targetUser['username']}");

            echo json_encode([
                'success' => true,
                'message' => "Password for '{$targetUser['full_name']}' has been reset successfully."
            ]);
            break;

        case 'delete':
            $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $userId = (int)($input['user_id'] ?? ($_GET['id'] ?? 0));

            if ($userId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid User ID is required.']);
                exit;
            }

            // Check if target user is an Owner
            $roleCheck = $pdo->prepare("SELECT role, username FROM users WHERE user_id = :id");
            $roleCheck->execute([':id' => $userId]);
            $targetU = $roleCheck->fetch();

            if ($targetU && $targetU['role'] === 'Owner') {
                $ownerCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Owner'")->fetchColumn();
                if ($ownerCount <= 1) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Cannot delete the only remaining store Owner account.']);
                    exit;
                }
            }

            // Check if user has sales
            $sCheck = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE cashier_id = :id");
            $sCheck->execute([':id' => $userId]);
            $sCount = (int)$sCheck->fetchColumn();

            // Check if user has stock_in records
            $stCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_in WHERE received_by = :id");
            $stCheck->execute([':id' => $userId]);
            $stCount = (int)$stCheck->fetchColumn();

            if ($sCount > 0 || $stCount > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete user: Associated with {$sCount} sale(s) and {$stCount} stock-in receipt(s). Retained for accountability. You may deactivate the account instead."
                ]);
                exit;
            }

            $del = $pdo->prepare("DELETE FROM users WHERE user_id = :id");
            $del->execute([':id' => $userId]);

            bs_log_audit($pdo, null, 'USER_DELETED', 'USERS', $userId, "Deleted user ID $userId");

            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully.'
            ]);
            break;

        case 'login':
            $input    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $username = trim($input['username'] ?? ($input['login'] ?? ($input['email'] ?? '')));
            $password = trim($input['password'] ?? '');

            if ($username === '' || $password === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please enter username or email and password.']);
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

            // Compare password (supports bcrypt or plain text)
            $valid = password_verify($password, $user['password']) || ($user['password'] === $password);
            if (!$valid) {
                bs_log_audit($pdo, (int)$user['user_id'], 'LOGIN_FAILED', 'USERS', (int)$user['user_id'], "Failed login attempt for username/email: $username");
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Invalid username/email or password.']);
                exit;
            }

            // Strict role authorization: Only Owner, Cashier, and Store Attendant roles are permitted
            $userRoleLower = strtolower(trim($user['role'] ?? ''));
            if (!in_array($userRoleLower, ['owner', 'cashier', 'store attendant'], true)) {
                bs_log_audit($pdo, (int)$user['user_id'], 'LOGIN_DENIED_UNAUTHORIZED_ROLE', 'USERS', (int)$user['user_id'], "Denied login via admin_users: role '{$user['role']}' is unauthorized");
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => "Access denied: Account '{$user['username']}' has an unauthorized role. Only Owner and Cashier roles are permitted to access the system."
                ]);
                exit;
            }

            // If plain text, upgrade to bcrypt
            if (strpos($user['password'], '$2y$') !== 0) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET password = :p WHERE user_id = :id")->execute([':p' => $hash, ':id' => $user['user_id']]);
            }

            // Update last_login
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = :id")->execute([':id' => $user['user_id']]);

            // Create database session token
            $token = bs_create_session($pdo, (int)$user['user_id'], $user['role']);
            bs_log_audit($pdo, (int)$user['user_id'], 'USER_LOGIN', 'USERS', (int)$user['user_id'], "User logged in via admin_users with role: {$user['role']}");

            echo json_encode([
                'success' => true,
                'token'   => $token,
                'message' => 'Login successful. Welcome back, ' . $user['full_name'] . '!',
                'user'    => [
                    'user_id'   => (int)$user['user_id'],
                    'full_name' => $user['full_name'],
                    'username'  => $user['username'],
                    'email'     => $user['email'] ?? '',
                    'phone'     => $user['phone'] ?? '',
                    'role'      => $user['role'],
                    'status'    => $user['status']
                ]
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown user action.']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'User API error: ' . $e->getMessage()
    ]);
}
