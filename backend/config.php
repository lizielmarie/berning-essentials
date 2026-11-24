<?php
/**
 * ==============================================================================
 * BERNING ESSENTIALS MANAGEMENT SYSTEM — CENTRALIZED CONFIGURATION
 * ==============================================================================
 * Production Target: Render Web Services + TiDB Cloud (MySQL-Compatible)
 * Local Environment: XAMPP / Localhost
 * 
 * SECURITY DIRECTIVE:
 * Never hardcode production database passwords, API keys, or private credentials
 * directly in this or any other source file.
 * All production credentials are strictly retrieved from Render Environment Variables.
 * ==============================================================================
 */

// Timezone (Store local timezone)
date_default_timezone_set('Asia/Manila');

// Application Details
defined('APP_NAME') || define('APP_NAME', 'Berning Essentials Store');

/**
 * Retrieve an environment variable with fallback to $_ENV, $_SERVER, or default value.
 * Supports Render environment variables and local development setups.
 */
function bs_env(string $key, $default = null) {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }
    return $default;
}

// Support optional local override file for development (strictly ignored by Git)
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

/**
 * Detect whether the application is running in a local development environment (XAMPP)
 * or in production (Render Web Service).
 */
function bs_is_local_environment(): bool {
    // 1. Explicit APP_ENV setting takes absolute precedence
    $appEnv = strtolower((string)bs_env('APP_ENV', defined('APP_ENV') ? APP_ENV : 'auto'));
    if ($appEnv === 'development' || $appEnv === 'local' || $appEnv === 'dev') {
        return true;
    }
    if ($appEnv === 'production' || $appEnv === 'prod') {
        return false;
    }

    // 2. Render platform detection (Render sets RENDER=true in all web and background services)
    if (bs_env('RENDER') !== null) {
        return false;
    }

    // 3. Remote DB_HOST detection: If DB_HOST is explicitly configured to a remote host (e.g. TiDB Cloud)
    $dbHost = strtolower((string)bs_env('DB_HOST', ''));
    if ($dbHost !== '' && $dbHost !== 'localhost' && $dbHost !== '127.0.0.1' && $dbHost !== '::1') {
        return false;
    }

    // 4. CLI mode: local development by default unless APP_ENV=production
    if (php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server') {
        return true;
    }

    // 5. Web request hostname & IP detection
    $httpHost   = strtolower($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    // Check for localhost, loopback, or local development domain suffixes
    if (
        strpos($httpHost, 'localhost') !== false ||
        strpos($httpHost, '127.0.0.1') !== false ||
        strpos($httpHost, '::1') !== false ||
        str_ends_with($httpHost, '.local') ||
        str_ends_with($httpHost, '.test') ||
        $remoteAddr === '127.0.0.1' ||
        $remoteAddr === '::1'
    ) {
        return true;
    }

    // Check for local XAMPP subdirectory installation in URL
    if (strpos($requestUri, '/berning_essentials') !== false) {
        return true;
    }

    // Check for private / LAN IP addresses (e.g. testing POS on mobile/tablet over Wi-Fi)
    $cleanHost = preg_replace('/:\d+$/', '', $httpHost);
    if (filter_var($cleanHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($cleanHost, FILTER_VALIDATE_IP) !== false) {
        return true;
    }

    return false;
}

/**
 * Locate the SSL CA certificate bundle for TiDB Cloud TLS connection.
 * Checks user-specified path (Render Secret Files) and standard system CA bundles.
 */
function bs_resolve_ssl_ca_path(?string $configuredPath = null): ?string {
    if (!empty($configuredPath)) {
        if (file_exists($configuredPath)) {
            return $configuredPath;
        }
        // Configured path was provided but file doesn't exist
        error_log("TiDB SSL Notice: Configured DB_SSL_CA_PATH file not found at: {$configuredPath}");
    }

    // Standard Render Secret File mount points and Linux system CA bundles
    $standardLocations = [
        '/etc/secrets/isrgrootx1.pem',       // Recommended Render Secret File location
        '/etc/secrets/tidb-ca.pem',          // Custom named Render Secret File
        '/etc/secrets/ca.pem',               // Generic Render Secret File
        '/etc/ssl/certs/ca-certificates.crt', // Debian/Ubuntu standard system bundle (includes ISRG Root X1)
        '/etc/pki/tls/certs/ca-bundle.crt',  // RHEL/CentOS system bundle
        '/etc/ssl/ca-bundle.pem',            // OpenSUSE system bundle
    ];

    foreach ($standardLocations as $location) {
        if (file_exists($location)) {
            return $location;
        }
    }

    // Check PHP OpenSSL default certificate file if defined
    $opensslFile = ini_get('openssl.cafile');
    if (!empty($opensslFile) && file_exists($opensslFile)) {
        return $opensslFile;
    }

    return null;
}

$isLocal = bs_is_local_environment();

if ($isLocal) {
    // --------------------------------------------------------------------------
    // 1. LOCAL DEVELOPMENT CONFIGURATION (XAMPP / LOCALHOST)
    // --------------------------------------------------------------------------
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);

    defined('APP_URL') || define('APP_URL', 'http://localhost/berning_essentials');

    $dbConfig = [
        'env'          => 'development',
        'host'         => (string)bs_env('DB_HOST', '127.0.0.1'),
        'port'         => (int)bs_env('DB_PORT', 3306),
        'name'         => (string)bs_env('DB_NAME', 'berning_store'),
        'user'         => (string)bs_env('DB_USER', 'root'),
        'pass'         => (string)bs_env('DB_PASS', ''),
        'ssl_ca'       => bs_env('DB_SSL_CA_PATH', null),
        'ssl_verify'   => false,
        'missing_vars' => [],
    ];
} else {
    // --------------------------------------------------------------------------
    // 2. PRODUCTION CONFIGURATION (RENDER + TiDB CLOUD)
    // --------------------------------------------------------------------------
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

    defined('APP_URL') || define('APP_URL', bs_env('RENDER_EXTERNAL_URL', 'https://berning-essentials.onrender.com'));

    // Retrieve production credentials strictly from environment variables
    $prodUser = bs_env('DB_USER');
    $prodPass = bs_env('DB_PASS');

    // Strict validation: In production, DB_USER and DB_PASS MUST be provided
    // Never fall back silently to localhost or default credentials!
    $missingVars = [];
    if (empty($prodUser)) {
        $missingVars[] = 'DB_USER';
    }
    if ($prodPass === null || $prodPass === '') {
        $missingVars[] = 'DB_PASS';
    }

    $configuredCaPath = bs_env('DB_SSL_CA_PATH');
    $resolvedCaPath   = bs_resolve_ssl_ca_path($configuredCaPath);

    $dbConfig = [
        'env'          => 'production',
        'host'         => (string)bs_env('DB_HOST', 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com'),
        'port'         => (int)bs_env('DB_PORT', 4000),
        'name'         => (string)bs_env('DB_NAME', 'berning_essentials'),
        'user'         => (string)$prodUser,
        'pass'         => (string)$prodPass,
        'ssl_ca'       => $resolvedCaPath,
        'ssl_verify'   => true, // Strictly verify server certificate and hostname for TiDB Cloud
        'missing_vars' => $missingVars,
    ];
}

// Return active configuration array for consumers
return $dbConfig;
