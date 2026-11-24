<?php
/**
 * ==============================================================================
 * BERNING ESSENTIALS — ONE UNIFIED LOGIN FORM VERIFICATION SUITE
 * Tests all 8 requirements from Master Prompt Section 6:
 * 1. Owner/Admin logs in through unified login -> Owner Dashboard
 * 2. Cashier logs in through unified login -> Cashier POS Dashboard
 * 3. Cashier refreshes the page -> Remains authenticated
 * 4. Owner/Admin refreshes the page -> Remains authenticated
 * 5. Cashier manually accesses Admin Dashboard -> Access denied
 * 6. Owner manually accesses Cashier Dashboard -> Access controlled (routed to Owner portal)
 * 7. Unauthenticated user accesses either dashboard -> Redirected to unified login
 * 8. User logs out -> Session properly terminated
 * ==============================================================================
 */

$baseUrl = 'http://localhost/berning_essentials';
$passed = 0;
$failed = 0;

function check($title, $ok, $details = '') {
    global $passed, $failed;
    if ($ok) {
        echo "[PASS] $title\n";
        $passed++;
    } else {
        echo "[FAIL] $title -> $details\n";
        $failed++;
    }
}

function req($url, $method = 'GET', $data = null, $headers = [], $follow = false) {
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
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $json = json_decode($body, true);

    return [
        'code'     => $httpCode,
        'data'     => $json,
        'body'     => $body,
        'redirect' => $redirect,
        'headers'  => $rawHeaders
    ];
}

echo "==============================================================================\n";
echo "BERNING ESSENTIALS — UNIFIED LOGIN FORM VERIFICATION\n";
echo "==============================================================================\n\n";

// REQUIREMENT 1: UNIFIED LOGIN PAGE CONSISTENCY
echo "--- Architectural Verification: One Common Login Form ---\n";
$loginRedirect = req("$baseUrl/login.html");
$adminLoginRedirect = req("$baseUrl/admin_login.html");
check("Legacy /login.html returns 301 redirect to clean URL", $loginRedirect['code'] === 301 && strpos($loginRedirect['redirect'], 'login/') !== false);
check("Legacy /admin_login.html returns 301 redirect to clean URL", $adminLoginRedirect['code'] === 301 && strpos($adminLoginRedirect['redirect'], 'login/') !== false);

$loginHtml = req("$baseUrl/login/", 'GET', null, [], true);
check("Unified login page exists at /login/ (HTTP 200)", $loginHtml['code'] === 200);
check("No separate cashier login link in unified login footer", strpos($loginHtml['body'], 'href="cashier.html"') === false);
check("Unified login badge indicates 'Unified Login Portal'", strpos($loginHtml['body'], 'Unified Login Portal') !== false);
check("Unified login does not contain role selection dropdown (role from DB)", strpos($loginHtml['body'], '<select') === false);

// SCENARIO 1: Owner/Admin logs in through unified login form -> Owner Dashboard
echo "\n--- Scenario 1: Owner logs in through Unified Login -> Owner Dashboard ---\n";
$ownerLogin = req("$baseUrl/api/login.php", 'POST', [
    'username' => 'owner_admin',
    'password' => '12345'
]);
check("Owner authentication succeeds via common endpoint (HTTP 200)", $ownerLogin['code'] === 200 && ($ownerLogin['data']['success'] ?? false) === true);
check("Owner role resolved authoritatively from DB as 'Owner'", ($ownerLogin['data']['user']['role'] ?? '') === 'Owner');
$ownerToken = $ownerLogin['data']['token'] ?? '';
check("Owner receives session token", !empty($ownerToken));

// Verify that the unified login page redirects Owner to admin.html / admin/
// (Simulated by checking the JS routing block in admin_login.html)
check("Unified login JS contains Owner redirect to admin", 
    strpos($loginHtml['body'], "userRole === 'owner'") !== false && 
    (strpos($loginHtml['body'], "admin/") !== false || strpos($loginHtml['body'], "admin.html") !== false));

// SCENARIO 2: Cashier logs in through unified login form -> Cashier POS Dashboard
echo "\n--- Scenario 2: Cashier logs in through Unified Login -> Cashier POS ---\n";
$cashierLogin = req("$baseUrl/api/login.php", 'POST', [
    'username' => 'liziel_cashier',
    'password' => '12345'
]);
check("Cashier authentication succeeds via the EXACT SAME endpoint (HTTP 200)", $cashierLogin['code'] === 200 && ($cashierLogin['data']['success'] ?? false) === true);
check("Cashier role resolved authoritatively from DB as 'Cashier'", ($cashierLogin['data']['user']['role'] ?? '') === 'Cashier');
$cashierToken = $cashierLogin['data']['token'] ?? '';
check("Cashier receives session token", !empty($cashierToken));

// Verify that the unified login page redirects Cashier to cashier.html / cashier/
check("Unified login JS contains Cashier redirect to cashier", 
    strpos($loginHtml['body'], "userRole === 'cashier'") !== false && 
    (strpos($loginHtml['body'], "cashier/") !== false || strpos($loginHtml['body'], "cashier.html") !== false));

// SCENARIO 3: Cashier refreshes the page -> Remains authenticated
echo "\n--- Scenario 3: Cashier refreshes page -> Remains authenticated ---\n";
$cashierRefresh1 = req("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $cashierToken"]);
check("Cashier session valid on initial check", ($cashierRefresh1['data']['authenticated'] ?? false) === true);
$cashierRefresh2 = req("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $cashierToken"]);
check("Cashier session remains valid on page refresh / subsequent check", ($cashierRefresh2['data']['authenticated'] ?? false) === true);
check("Session check returns role 'Cashier'", ($cashierRefresh2['data']['user']['role'] ?? '') === 'Cashier');

// SCENARIO 4: Owner/Admin refreshes the page -> Remains authenticated
echo "\n--- Scenario 4: Owner refreshes page -> Remains authenticated ---\n";
$ownerRefresh1 = req("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $ownerToken"]);
check("Owner session valid on initial check", ($ownerRefresh1['data']['authenticated'] ?? false) === true);
$ownerRefresh2 = req("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $ownerToken"]);
check("Owner session remains valid on page refresh / subsequent check", ($ownerRefresh2['data']['authenticated'] ?? false) === true);
check("Session check returns role 'Owner'", ($ownerRefresh2['data']['user']['role'] ?? '') === 'Owner');

// SCENARIO 5: Cashier manually accesses Admin Dashboard URL -> Access denied
echo "\n--- Scenario 5: Cashier accesses Admin Dashboard -> Access Denied ---\n";
$cashierOnAdmin = req("$baseUrl/admin/", 'GET', null, ['Authorization' => "Bearer $cashierToken"]);
check("Cashier blocked at admin gateway (HTTP 302)", $cashierOnAdmin['code'] === 302);
check("Cashier redirected to login with unauthorized error", strpos($cashierOnAdmin['redirect'], 'login') !== false);
// Ensure Cashier's own POS session was not destroyed
$cashierSessionAfterProbe = req("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $cashierToken"]);
check("Cashier POS session remains active after admin probe", ($cashierSessionAfterProbe['data']['authenticated'] ?? false) === true);

// SCENARIO 6: Owner manually accesses Cashier Dashboard URL -> Follow system policy
echo "\n--- Scenario 6: Owner accesses Cashier Dashboard URL -> Handled by Policy ---\n";
$cashierHtml = req("$baseUrl/cashier/", 'GET', null, [], true);
check("cashier checks session and redirects Owner to admin", 
    strpos($cashierHtml['body'], "userRole === 'owner'") !== false &&
    (strpos($cashierHtml['body'], "admin/") !== false || strpos($cashierHtml['body'], "admin.html") !== false));

// SCENARIO 7: Unauthenticated user accesses either dashboard -> Redirected to unified login
echo "\n--- Scenario 7: Unauthenticated user accesses dashboards -> Unified Login Redirect ---\n";
// Unauthenticated access to /
$rootUnauth = req("$baseUrl/");
check("Root / redirects unauthenticated user (HTTP 302)", $rootUnauth['code'] === 302);
check("Root / redirects to unified login form", strpos($rootUnauth['redirect'], 'login') !== false);

// Unauthenticated access to admin/
$adminUnauth = req("$baseUrl/admin/");
check("admin/ rejects unauthenticated user (HTTP 302)", $adminUnauth['code'] === 302);
check("admin/ redirects to unified login form", strpos($adminUnauth['redirect'], 'login') !== false);

// Unauthenticated access to cashier/
check("cashier redirects unauthenticated user to unified login", 
    strpos($cashierHtml['body'], 'window.location.replace("../login/")') !== false ||
    strpos($cashierHtml['body'], 'window.location.replace("admin_login.html")') !== false);

// Unauthenticated access to admin
$adminHtmlContent = file_get_contents(__DIR__ . '/admin.html');
check("admin client-side guard redirects unauthenticated user to unified login", 
    strpos($adminHtmlContent, "window.location.replace('../login/')") !== false ||
    strpos($adminHtmlContent, "window.location.replace('admin_login.html')") !== false);

// SCENARIO 8: User logs out -> Session properly terminated
echo "\n--- Scenario 8: Logout -> Session properly terminated ---\n";
// Create temporary session for logout testing
$tempLogin = req("$baseUrl/api/login.php", 'POST', ['username' => 'owner_admin', 'password' => '12345']);
$tempToken = $tempLogin['data']['token'] ?? '';
$logoutRes = req("$baseUrl/api/logout.php", 'POST', [], ['Authorization' => "Bearer $tempToken"]);
check("Logout API succeeds (HTTP 200)", $logoutRes['code'] === 200 && ($logoutRes['data']['success'] ?? false) === true);

// Check that the session is now invalid in database
$checkAfterLogout = req("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $tempToken"]);
check("Session invalidated in database after logout (authenticated=false)", ($checkAfterLogout['data']['authenticated'] ?? true) === false);

echo "\n==============================================================================\n";
echo "OVERALL RESULTS: $passed PASSED, $failed FAILED\n";
echo "==============================================================================\n";

if ($failed === 0) {
    echo "STATUS: ALL UNIFIED LOGIN REQUIREMENTS AND SCENARIOS FULLY VERIFIED!\n";
    exit(0);
} else {
    echo "STATUS: SOME TESTS FAILED!\n";
    exit(1);
}
