<?php
/**
 * BERNING STORE — AUTHENTICATION & SECURITY HELPER
 * Session token management, role-based access control, CSRF, and audit logging.
 */
require_once __DIR__ . '/db.php';

/**
 * Generate a cryptographically secure random token string.
 */
function bs_generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Create a new user session in the database.
 */
function bs_create_session(PDO $pdo, int $userId, string $role, int $lifetimeHours = 24): string {
    $token = bs_generate_token(32);
    $expiresAt = date('Y-m-d H:i:s', time() + ($lifetimeHours * 3600));

    // Remove expired sessions for clean housekeeping
    $pdo->exec("DELETE FROM sessions WHERE expires_at < NOW()");

    $stmt = $pdo->prepare("
        INSERT INTO sessions (user_id, token, role, expires_at)
        VALUES (:user_id, :token, :role, :expires_at)
    ");
    $stmt->execute([
        ':user_id'    => $userId,
        ':token'      => $token,
        ':role'       => $role,
        ':expires_at' => $expiresAt
    ]);

    // Set secure HTTP-only cookie (secure flag set conditionally when HTTPS is active)
    if (!headers_sent()) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                   || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) 
                   || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie('bs_session_token', $token, [
            'expires'  => time() + ($lifetimeHours * 3600),
            'path'     => '/',
            'httponly' => true,
            'secure'   => $isHttps,
            'samesite' => 'Lax'
        ]);
    }

    return $token;
}

/**
 * Validate an active session from Bearer token, Cookie, or Parameter.
 * Returns user array or null.
 */
function bs_validate_session(PDO $pdo, ?string $token = null): ?array {
    if (!$token) {
        // 1. Check Authorization header from getallheaders() (case-insensitive)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $val) {
            if (strtolower((string)$key) === 'authorization' && preg_match('/Bearer\s+(\S+)/i', (string)$val, $matches)) {
                $token = $matches[1];
                break;
            }
        }

        // 2. Check Apache environment variables (standard, mod_rewrite, and CGI)
        if (!$token && !empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $token = $matches[1];
        }
        if (!$token && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(\S+)/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $matches)) {
            $token = $matches[1];
        }
    }

    if (!$token && isset($_COOKIE['bs_session_token'])) {
        $token = trim((string)$_COOKIE['bs_session_token']);
    }

    if (!$token && isset($_REQUEST['session_token'])) {
        $token = trim($_REQUEST['session_token']);
    }
    if (!$token && isset($_REQUEST['token'])) {
        $token = trim($_REQUEST['token']);
    }

    if (!$token) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT 
            s.session_id,
            s.token,
            s.expires_at,
            u.user_id,
            u.full_name,
            u.username,
            u.email,
            u.phone,
            u.role,
            u.status,
            u.created_at,
            u.last_login
        FROM sessions s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.token = :token 
          AND s.expires_at > NOW()
          AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        // Expired or invalid session: clean up stale cookie immediately
        if (isset($_COOKIE['bs_session_token']) && !headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                       || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) 
                       || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            setcookie('bs_session_token', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'secure'   => $isHttps,
                'samesite' => 'Lax'
            ]);
        }
        return null;
    }

    return $user;
}

/**
 * Require valid session authentication and optional role authorization.
 */
function bs_require_auth(PDO $pdo, array $allowedRoles = []): array {
    $user = bs_validate_session($pdo);

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success'      => false,
            'authenticated'=> false,
            'message'      => 'Authentication required. Please log in.'
        ]);
        exit;
    }

    if (!empty($allowedRoles) && !in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Your role (' . $user['role'] . ') does not have permission to access this resource.'
        ]);
        exit;
    }

    return $user;
}

/**
 * Destroy a session by token.
 */
function bs_destroy_session(PDO $pdo, ?string $token = null): bool {
    if (!$token) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    if (!$token && isset($_COOKIE['bs_session_token'])) {
        $token = $_COOKIE['bs_session_token'];
    }
    if (!$token && isset($_REQUEST['session_token'])) {
        $token = trim($_REQUEST['session_token']);
    }
    if (!$token && isset($_REQUEST['token'])) {
        $token = trim($_REQUEST['token']);
    }

    if ($token) {
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE token = :token");
        $stmt->execute([':token' => $token]);
    }

    if (!headers_sent()) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                   || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) 
                   || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie('bs_session_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $isHttps,
            'samesite' => 'Lax'
        ]);
    }

    return true;
}

/**
 * Helper to write an action to the audit_logs table.
 */
function bs_log_audit(PDO $pdo, ?int $userId, string $action, string $entityType, ?int $entityId, ?string $details = null): bool {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
            VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip)
        ");
        return $stmt->execute([
            ':user_id'     => $userId,
            ':action'      => strtoupper($action),
            ':entity_type' => strtoupper($entityType),
            ':entity_id'   => $entityId,
            ':details'     => $details,
            ':ip'          => $ip
        ]);
    } catch (Exception $e) {
        // Silently prevent audit logging failures from breaking transaction
        return false;
    }
}
