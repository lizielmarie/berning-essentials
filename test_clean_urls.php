<?php
/**
 * ==============================================================================
 * BERNING ESSENTIALS — CLEAN EXTENSIONLESS URLS VERIFICATION SUITE
 * ==============================================================================
 * Comprehensive tests for:
 * 1. Clean directory URLs (/cashier/, /admin/, /login/, /register/)
 * 2. 301 Redirects from legacy file URLs (*.html, *.php)
 * 3. Trailing slash normalization (/cashier -> /cashier/)
 * 4. Sub-resource transparent rewrites (api/, assets/ from clean URLs)
 * 5. POST body preservation during AJAX calls from clean directory URLs
 * 6. Cross-directory relative link normalization
 * 7. Authentication, RBAC, and session persistence under clean URLs
 * ==============================================================================
 */

$baseUrl = 'http://localhost/berning_essentials';
$passed = 0;
$failed = 0;

function checkUrl($title, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $title\n";
        $passed++;
    } else {
        echo "[FAIL] $title -> $details\n";
        $failed++;
    }
}

function fetchUrl($url, $method = 'GET', $data = null, $headers = [], $follow = false) {
    $ch = curl_init($url);
    $reqHeaders = [];
    foreach ($headers as $k => $v) {
        $reqHeaders[] = "$k: $v";
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            $jsonData = is_string($data) ? $data : json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $reqHeaders[] = 'Content-Type: application/json';
        }
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $reqHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HEADER         => true,
    ]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $json = json_decode($body, true);

    return [
        'code'         => $httpCode,
        'data'         => $json,
        'body'         => $body,
        'redirect'     => $redirect,
        'content_type' => $contentType,
        'headers'      => $rawHeaders
    ];
}

echo "==============================================================================\n";
echo "BERNING ESSENTIALS — CLEAN EXTENSIONLESS URL VERIFICATION\n";
echo "==============================================================================\n\n";

// ------------------------------------------------------------------------------
// TEST 1: Cashier POS Clean URL & Legacy Redirects
// ------------------------------------------------------------------------------
echo "--- 1. Cashier POS URLs ---\n";
$cashierClean = fetchUrl("$baseUrl/cashier/");
checkUrl("GET /cashier/ returns HTTP 200", $cashierClean['code'] === 200, "Code: {$cashierClean['code']}");
checkUrl("GET /cashier/ loads Cashier POS Terminal HTML", strpos($cashierClean['body'], 'Cashier POS') !== false || strpos($cashierClean['body'], 'cashier') !== false);

$cashierLegacy = fetchUrl("$baseUrl/cashier.html");
checkUrl("GET /cashier.html returns HTTP 301 Moved Permanently", $cashierLegacy['code'] === 301, "Code: {$cashierLegacy['code']}");
checkUrl("GET /cashier.html redirects to clean URL /cashier/", strpos($cashierLegacy['redirect'], '/cashier/') !== false, "Redirect: {$cashierLegacy['redirect']}");

$cashierNoSlash = fetchUrl("$baseUrl/cashier");
checkUrl("GET /cashier (no slash) returns HTTP 301 redirect", $cashierNoSlash['code'] === 301, "Code: {$cashierNoSlash['code']}");
checkUrl("GET /cashier enforces trailing slash -> /cashier/", strpos($cashierNoSlash['redirect'], '/cashier/') !== false, "Redirect: {$cashierNoSlash['redirect']}");

// ------------------------------------------------------------------------------
// TEST 2: Admin / Owner Clean URL & Legacy Redirects
// ------------------------------------------------------------------------------
echo "\n--- 2. Admin / Owner URLs ---\n";
$adminUnauth = fetchUrl("$baseUrl/admin/");
checkUrl("GET /admin/ (unauthenticated) returns HTTP 302 redirect", $adminUnauth['code'] === 302, "Code: {$adminUnauth['code']}");
checkUrl("GET /admin/ redirects unauthenticated user to login", strpos($adminUnauth['redirect'], 'login') !== false, "Redirect: {$adminUnauth['redirect']}");

$adminHtmlLegacy = fetchUrl("$baseUrl/admin.html");
checkUrl("GET /admin.html returns HTTP 301 Moved Permanently", $adminHtmlLegacy['code'] === 301, "Code: {$adminHtmlLegacy['code']}");
checkUrl("GET /admin.html redirects to clean URL /admin/", strpos($adminHtmlLegacy['redirect'], '/admin/') !== false, "Redirect: {$adminHtmlLegacy['redirect']}");

$adminPhpLegacy = fetchUrl("$baseUrl/admin.php");
checkUrl("GET /admin.php returns HTTP 301 Moved Permanently", $adminPhpLegacy['code'] === 301, "Code: {$adminPhpLegacy['code']}");
checkUrl("GET /admin.php redirects to clean URL /admin/", strpos($adminPhpLegacy['redirect'], '/admin/') !== false, "Redirect: {$adminPhpLegacy['redirect']}");

$adminNoSlash = fetchUrl("$baseUrl/admin");
checkUrl("GET /admin (no slash) returns HTTP 301 redirect", $adminNoSlash['code'] === 301, "Code: {$adminNoSlash['code']}");
checkUrl("GET /admin enforces trailing slash -> /admin/", strpos($adminNoSlash['redirect'], '/admin/') !== false, "Redirect: {$adminNoSlash['redirect']}");

// ------------------------------------------------------------------------------
// TEST 3: Unified Login Clean URL & Legacy Redirects
// ------------------------------------------------------------------------------
echo "\n--- 3. Unified Login URLs ---\n";
$loginClean = fetchUrl("$baseUrl/login/");
checkUrl("GET /login/ returns HTTP 200", $loginClean['code'] === 200, "Code: {$loginClean['code']}");
checkUrl("GET /login/ loads Unified Login Portal HTML", strpos($loginClean['body'], 'Unified Login Portal') !== false);

$adminLoginLegacy = fetchUrl("$baseUrl/admin_login.html");
checkUrl("GET /admin_login.html returns HTTP 301 Moved Permanently", $adminLoginLegacy['code'] === 301, "Code: {$adminLoginLegacy['code']}");
checkUrl("GET /admin_login.html redirects to clean URL /login/", strpos($adminLoginLegacy['redirect'], '/login/') !== false, "Redirect: {$adminLoginLegacy['redirect']}");

$loginHtmlLegacy = fetchUrl("$baseUrl/login.html");
checkUrl("GET /login.html returns HTTP 301 Moved Permanently", $loginHtmlLegacy['code'] === 301, "Code: {$loginHtmlLegacy['code']}");
checkUrl("GET /login.html redirects to clean URL /login/", strpos($loginHtmlLegacy['redirect'], '/login/') !== false, "Redirect: {$loginHtmlLegacy['redirect']}");

$loginNoSlash = fetchUrl("$baseUrl/login");
checkUrl("GET /login (no slash) returns HTTP 301 redirect", $loginNoSlash['code'] === 301, "Code: {$loginNoSlash['code']}");
checkUrl("GET /login enforces trailing slash -> /login/", strpos($loginNoSlash['redirect'], '/login/') !== false, "Redirect: {$loginNoSlash['redirect']}");

// ------------------------------------------------------------------------------
// TEST 4: Owner Registration Clean URL & Legacy Redirects
// ------------------------------------------------------------------------------
echo "\n--- 4. Owner Registration URLs ---\n";
$registerClean = fetchUrl("$baseUrl/register/");
checkUrl("GET /register/ returns HTTP 200", $registerClean['code'] === 200, "Code: {$registerClean['code']}");
checkUrl("GET /register/ loads Owner Registration HTML", strpos($registerClean['body'], 'Register Owner Account') !== false || strpos($registerClean['body'], 'Owner Registration') !== false);

$registerLegacy = fetchUrl("$baseUrl/owner_register.html");
checkUrl("GET /owner_register.html returns HTTP 301 Moved Permanently", $registerLegacy['code'] === 301, "Code: {$registerLegacy['code']}");
checkUrl("GET /owner_register.html redirects to clean URL /register/", strpos($registerLegacy['redirect'], '/register/') !== false, "Redirect: {$registerLegacy['redirect']}");

$registerNoSlash = fetchUrl("$baseUrl/register");
checkUrl("GET /register (no slash) returns HTTP 301 redirect", $registerNoSlash['code'] === 301, "Code: {$registerNoSlash['code']}");
checkUrl("GET /register enforces trailing slash -> /register/", strpos($registerNoSlash['redirect'], '/register/') !== false, "Redirect: {$registerNoSlash['redirect']}");

// ------------------------------------------------------------------------------
// TEST 5: Sub-Resource Rewrites (Relative URLs from Directory-Style Pages)
// ------------------------------------------------------------------------------
echo "\n--- 5. Sub-Resource Rewrites (Assets & APIs from Clean Directory URLs) ---\n";
$cashierApiProducts = fetchUrl("$baseUrl/cashier/api/get_products.php");
checkUrl("Sub-resource: GET /cashier/api/get_products.php returns HTTP 200 without redirect", $cashierApiProducts['code'] === 200, "Code: {$cashierApiProducts['code']}");
checkUrl("Sub-resource: Product data is returned as JSON", ($cashierApiProducts['data']['success'] ?? false) === true && !empty($cashierApiProducts['data']['products']));

$cashierCss = fetchUrl("$baseUrl/cashier/assets/css/cashier.css");
checkUrl("Sub-resource: GET /cashier/assets/css/cashier.css returns HTTP 200 without redirect", $cashierCss['code'] === 200, "Code: {$cashierCss['code']}");
checkUrl("Sub-resource: CSS stylesheet contains POS classes", strpos($cashierCss['body'], 'pos-') !== false);

$cashierJs = fetchUrl("$baseUrl/cashier/assets/js/cashier.js");
checkUrl("Sub-resource: GET /cashier/assets/js/cashier.js returns HTTP 200 without redirect", $cashierJs['code'] === 200, "Code: {$cashierJs['code']}");

$loginApiStatus = fetchUrl("$baseUrl/login/api/owner_register.php?action=status");
checkUrl("Sub-resource: GET /login/api/owner_register.php returns HTTP 200", $loginApiStatus['code'] === 200, "Code: {$loginApiStatus['code']}");
checkUrl("Sub-resource: Status API returns JSON with owner_exists boolean", isset($loginApiStatus['data']['owner_exists']));

// ------------------------------------------------------------------------------
// TEST 6: POST Request Body Preservation through Sub-Resource Rewrites
// ------------------------------------------------------------------------------
echo "\n--- 6. POST Body Preservation & Authentication via Clean Sub-Resources ---\n";
$loginPost = fetchUrl("$baseUrl/login/api/login.php", 'POST', [
    'username' => 'owner_admin',
    'password' => '12345'
]);
checkUrl("POST to /login/api/login.php responds with HTTP 200 (no 301 redirect stripping body)", $loginPost['code'] === 200, "Code: {$loginPost['code']}");
checkUrl("Authentication succeeded through clean subresource path", ($loginPost['data']['success'] ?? false) === true);
$ownerToken = $loginPost['data']['token'] ?? '';
checkUrl("Owner session token issued", !empty($ownerToken));

$cashierPost = fetchUrl("$baseUrl/cashier/api/login.php", 'POST', [
    'username' => 'liziel_cashier',
    'password' => '12345'
]);
checkUrl("POST to /cashier/api/login.php responds with HTTP 200", $cashierPost['code'] === 200, "Code: {$cashierPost['code']}");
checkUrl("Cashier authentication succeeded through clean cashier subresource path", ($cashierPost['data']['success'] ?? false) === true);
$cashierToken = $cashierPost['data']['token'] ?? '';

// ------------------------------------------------------------------------------
// TEST 7: Cross-Directory Navigation Normalization
// ------------------------------------------------------------------------------
echo "\n--- 7. Cross-Directory Navigation Normalization ---\n";
$cross1 = fetchUrl("$baseUrl/cashier/login/");
checkUrl("Cross-navigation: /cashier/login/ redirects 301 to /login/", $cross1['code'] === 301 && strpos($cross1['redirect'], '/login/') !== false, "Redirect: {$cross1['redirect']}");

$cross2 = fetchUrl("$baseUrl/cashier/admin/");
checkUrl("Cross-navigation: /cashier/admin/ redirects 301 to /admin/", $cross2['code'] === 301 && strpos($cross2['redirect'], '/admin/') !== false, "Redirect: {$cross2['redirect']}");

$cross3 = fetchUrl("$baseUrl/login/cashier/");
checkUrl("Cross-navigation: /login/cashier/ redirects 301 to /cashier/", $cross3['code'] === 301 && strpos($cross3['redirect'], '/cashier/') !== false, "Redirect: {$cross3['redirect']}");

$cross4 = fetchUrl("$baseUrl/cashier/admin_login.html");
checkUrl("Cross-navigation legacy: /cashier/admin_login.html redirects 301 to /login/", $cross4['code'] === 301 && strpos($cross4['redirect'], '/login/') !== false, "Redirect: {$cross4['redirect']}");

$cross5 = fetchUrl("$baseUrl/cashier/admin.html");
checkUrl("Cross-navigation legacy: /cashier/admin.html redirects 301 to /admin/", $cross5['code'] === 301 && strpos($cross5['redirect'], '/admin/') !== false, "Redirect: {$cross5['redirect']}");

// ------------------------------------------------------------------------------
// TEST 8: Full Role-Based Access Control under Clean URLs
// ------------------------------------------------------------------------------
echo "\n--- 8. RBAC and Protected Gateways under Clean URLs ---\n";
$adminWithOwner = fetchUrl("$baseUrl/admin/", 'GET', null, ['Authorization' => "Bearer $ownerToken"], true);
checkUrl("Authenticated Owner accesses /admin/ -> HTTP 200 Owner Portal", $adminWithOwner['code'] === 200, "Code: {$adminWithOwner['code']}");
checkUrl("Owner Portal HTML served successfully", strpos($adminWithOwner['body'], 'Management Portal') !== false || strpos($adminWithOwner['body'], 'Berning Store') !== false);

$adminWithCashier = fetchUrl("$baseUrl/admin/", 'GET', null, ['Authorization' => "Bearer $cashierToken"]);
checkUrl("Cashier accessing /admin/ is blocked with HTTP 302 redirect", $adminWithCashier['code'] === 302, "Code: {$adminWithCashier['code']}");
checkUrl("Cashier redirected to login/error page", strpos($adminWithCashier['redirect'], 'login') !== false || strpos($adminWithCashier['redirect'], 'cashier') !== false);

// Root entry point
$root = fetchUrl("$baseUrl/");
checkUrl("Root / redirects unauthenticated user with HTTP 302", $root['code'] === 302, "Code: {$root['code']}");
checkUrl("Root / redirects to clean /login/ URL", strpos($root['redirect'], '/login/') !== false, "Redirect: {$root['redirect']}");

// Clean up: logout
$logout = fetchUrl("$baseUrl/api/logout.php", 'POST', null, ['Authorization' => "Bearer $ownerToken"]);
checkUrl("Owner logout succeeds", ($logout['data']['success'] ?? false) === true);

echo "\n==============================================================================\n";
echo "CLEAN URL VERIFICATION RESULTS: $passed PASSED, $failed FAILED\n";
echo "==============================================================================\n";

if ($failed === 0) {
    echo "STATUS: ALL CLEAN EXTENSIONLESS URL REQUIREMENTS FULLY VERIFIED!\n";
    exit(0);
} else {
    echo "STATUS: SOME CLEAN URL TESTS FAILED!\n";
    exit(1);
}
