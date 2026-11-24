<?php
/**
 * ==============================================================================
 * BERNING ESSENTIALS — CENTRALIZED DATABASE CONNECTION (PDO)
 * ==============================================================================
 * Architecture:
 * - Production: Render Web Services + TiDB Cloud (TLS/SSL Enforced)
 * - Local Development: XAMPP / Localhost MySQL (127.0.0.1:3306)
 * 
 * SECURITY DIRECTIVE:
 * Never expose raw connection strings, database passwords, or unhandled exceptions
 * to HTTP client responses or public logs.
 * ==============================================================================
 */

// Load centralized configuration
$dbConfig = require __DIR__ . '/config.php';

$isLocal = ($dbConfig['env'] ?? 'development') === 'development';
$host    = $dbConfig['host'] ?? '127.0.0.1';
$port    = (int)($dbConfig['port'] ?? 3306);
$db      = $dbConfig['name'] ?? 'berning_store';
$user    = $dbConfig['user'] ?? '';
$pass    = $dbConfig['pass'] ?? '';
$charset = 'utf8mb4';

// Helper to send safe HTTP 500 error responses without credential leakage
$handleConnectionFailure = function(string $diagnosticMessage, ?string $clientHint = null) use ($isLocal) {
    error_log("Database Connection Error: " . $diagnosticMessage);

    if (!headers_sent()) {
        http_response_code(500);
    }

    $isApi = (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'api/') !== false) 
             || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
             || (php_sapi_name() === 'cli');

    if ($isApi) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => $isLocal 
                ? 'Local Database Connection Failed: ' . $diagnosticMessage 
                : 'Database service is currently unavailable. Please verify Render environment configuration.',
            'hint'    => $isLocal 
                ? ($clientHint ?? 'Ensure MySQL is running in XAMPP and the database "berning_store" exists.')
                : 'Verify DB_USER, DB_PASS, and TiDB Cloud cluster status in Render Dashboard.'
        ]);
    } else {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        $safeTitle = $isLocal ? "Local Database Connection Required" : "Database Service Unavailable";
        $safeDesc  = $isLocal 
            ? "<p>Could not connect to the local MySQL server. Verify that <strong>MySQL</strong> is started in the XAMPP Control Panel and database <code>berning_store</code> exists.</p><p><small>" . htmlspecialchars($diagnosticMessage) . "</small></p>"
            : "<p>The system is unable to connect to the production database cluster (TiDB Cloud). Please ensure all required <strong>Render Environment Variables</strong> (<code>DB_USER</code>, <code>DB_PASS</code>, <code>DB_HOST</code>) are configured in the Render Dashboard.</p>";

        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>{$safeTitle} - Berning Essentials</title><style>body{font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box}.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:32px;max-width:560px;width:100%;box-shadow:0 10px 25px rgba(0,0,0,0.5)}h1{color:#ef4444;margin-top:0;font-size:20px}p{color:#94a3b8;line-height:1.6;font-size:14px}code{background:#0f172a;color:#38bdf8;padding:2px 6px;border-radius:4px;font-size:13px}</style></head><body><div class='card'><h1>{$safeTitle}</h1>{$safeDesc}</div></body></html>";
    }
    exit(1);
};

// ------------------------------------------------------------------------------
// Production Environment Pre-flight Validation
// ------------------------------------------------------------------------------
if (!$isLocal) {
    if (!empty($dbConfig['missing_vars'])) {
        $missingList = implode(', ', $dbConfig['missing_vars']);
        $handleConnectionFailure("Missing required Render production environment variable(s): {$missingList}");
    }

    if (!empty($dbConfig['ssl_verify']) && empty($dbConfig['ssl_ca'])) {
        error_log("TiDB Cloud SSL Warning: No CA certificate bundle found. Secure TLS verification requires a CA certificate.");
    }
}

// ------------------------------------------------------------------------------
// PDO Options & SSL/TLS Configuration
// ------------------------------------------------------------------------------
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Configure TLS/SSL certificate verification for TiDB Cloud
if (!empty($dbConfig['ssl_ca']) && file_exists($dbConfig['ssl_ca'])) {
    if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $dbConfig['ssl_ca'];
    }
}

if (!empty($dbConfig['ssl_verify'])) {
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
}

// ------------------------------------------------------------------------------
// Database Connection Initialization
// ------------------------------------------------------------------------------
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Synchronize character set to utf8mb4 and session timezone to Philippine Time (UTC+08:00)
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (\Throwable $tzException) {
        // Silently continue if host restricts time_zone modifications
    }
} catch (\PDOException $e) {
    // Sanitize message: never expose raw DSN or password in log or output
    $safeError = "PDO error [Host: $host, Port: $port, Database: $db]: " . $e->getMessage();
    $handleConnectionFailure($safeError);
}
