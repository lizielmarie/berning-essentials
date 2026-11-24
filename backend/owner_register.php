<?php
/**
 * BERNING STORE — API: OWNER REGISTRATION GATEWAY
 * Allows public registration ONLY when no owner account exists in the system.
 * Once the first owner is registered, public registration is locked permanently.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'POST' ? 'register' : 'status');

try {
    switch ($action) {
        // -------------------------------------------------------------
        // Check if an owner already exists
        // -------------------------------------------------------------
        case 'status':
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Owner'");
            $ownerCount = (int)$stmt->fetchColumn();

            echo json_encode([
                'success'      => true,
                'owner_exists' => $ownerCount > 0,
                'can_register' => $ownerCount === 0,
                'owner_count'  => $ownerCount,
                'message'      => $ownerCount > 0 
                    ? 'Store owner registration is already completed.' 
                    : 'Initial store owner registration is available.'
            ]);
            break;

        // -------------------------------------------------------------
        // Register the primary store owner
        // -------------------------------------------------------------
        case 'register':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
                exit;
            }

            // Check if an owner already exists (Double-check guard)
            $checkStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Owner'");
            $existingOwners = (int)$checkStmt->fetchColumn();
            if ($existingOwners > 0) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'An authorized store owner account already exists. Public owner registration is permanently disabled.'
                ]);
                exit;
            }

            // Decode input
            $rawInput = file_get_contents('php://input');
            $data     = json_decode($rawInput, true) ?? $_POST;

            $fullName        = trim($data['full_name'] ?? '');
            $username        = trim($data['username'] ?? '');
            $email           = trim($data['email'] ?? '');
            $phone           = trim($data['phone'] ?? ($data['contact_number'] ?? ''));
            $password        = (string)($data['password'] ?? '');
            $confirmPassword = (string)($data['confirm_password'] ?? '');

            // 1. Full name validation
            if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please enter a valid full name (2 to 100 characters).']);
                exit;
            }

            // 2. Username validation
            if (mb_strlen($username) < 3 || mb_strlen($username) > 50 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Username must be 3-50 characters and contain only letters, numbers, hyphens, periods, or underscores.']);
                exit;
            }

            // 3. Email validation
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
                exit;
            }

            // 4. Contact number validation
            if (mb_strlen($phone) < 7 || mb_strlen($phone) > 30) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please provide a valid contact number (7 to 30 characters).']);
                exit;
            }

            // 5. Password validation
            if (strlen($password) < 5) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Password must be at least 5 characters long.']);
                exit;
            }

            // 6. Confirm password match
            if ($password !== $confirmPassword) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Passwords do not match. Please re-enter your password.']);
                exit;
            }

            // 7. Check if username is already taken by any staff/user
            $userCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = :u LIMIT 1");
            $userCheck->execute([':u' => $username]);
            if ($userCheck->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "The username '$username' is already taken. Please choose another."]);
                exit;
            }

            // 8. Check if email is already taken
            $emailCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = :e LIMIT 1");
            $emailCheck->execute([':e' => $email]);
            if ($emailCheck->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "The email '$email' is already registered. Please use another email."]);
                exit;
            }

            // 9. Hash password securely using bcrypt
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // 10. Insert owner account
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    full_name, username, email, phone, password, role, status, created_at
                ) VALUES (
                    :full_name, :username, :email, :phone, :password, 'Owner', 'active', NOW()
                )
            ");
            $stmt->execute([
                ':full_name' => $fullName,
                ':username'  => $username,
                ':email'     => $email,
                ':phone'     => $phone,
                ':password'  => $hashedPassword
            ]);

            $newOwnerId = (int)$pdo->lastInsertId();

            // Log security audit trail
            bs_log_audit($pdo, $newOwnerId, 'OWNER_REGISTERED', 'USERS', $newOwnerId, "Registered primary store owner: $fullName ($username)");

            echo json_encode([
                'success'  => true,
                'message'  => "Store Owner account '$username' has been successfully created! You may now log in.",
                'user_id'  => $newOwnerId,
                'username' => $username,
                'email'    => $email
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
            break;
    }
} catch (\PDOException $e) {
    error_log('Owner registration PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to complete registration due to a database error. Please contact the administrator.'
    ]);
} catch (\Throwable $e) {
    error_log('Owner registration error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred during registration. Please try again.'
    ]);
}
