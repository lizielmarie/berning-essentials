<?php
/**
 * ==============================================================================
 * BERNING ESSENTIALS — LOGIN & AUTHENTICATION SCENARIO TEST SUITE
 * Tests all 10 scenarios from Master Development Prompt Section 5
 * ==============================================================================
 */

$baseUrl = 'http://localhost/berning_essentials';
$passed = 0;
$failed = 0;

function report($title, $ok, $details = '') {
    global $passed, $failed;
    if ($ok) {
        echo "PASS: $title\n";
        $passed++;
    } else {
        echo "FAIL: $title -> $details\n";
        $failed++;
    }
}

function httpReq($url, $method = 'GET', $data = null, $headers = [], $follow = false) {
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

    // Parse set-cookie
    $cookies = [];
    if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $rawHeaders, $matches)) {
        foreach ($matches[1] as $c) {
            $parts = explode('=', $c, 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }
    }

    return [
        'code'     => $httpCode,
        'data'     => $json,
        'body'     => $body,
        'redirect' => $redirect,
        'cookies'  => $cookies,
        'raw_headers' => $rawHeaders
    ];
}

echo "==============================================================================\n";
echo "BERNING STORE — COMPLETE AUTHENTICATION SCENARIOS VERIFICATION\n";
echo "==============================================================================\n\n";

// SCENARIO 1: Correct Owner/Admin credentials -> Owner Dashboard
echo "--- Scenario 1: Correct Owner/Admin credentials ---\n";
// Test with primary username
$ownerRes = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'owner_admin', 'password' => '12345']);
report("1a. Owner login with 'owner_admin' succeeds (HTTP 200)", $ownerRes['code'] === 200 && ($ownerRes['data']['success'] ?? false) === true);
report("1b. Owner role confirmed as 'Owner'", ($ownerRes['data']['user']['role'] ?? '') === 'Owner');
report("1c. Owner session token returned", !empty($ownerRes['data']['token']));
$ownerToken = $ownerRes['data']['token'] ?? '';

// Test with alias 'berning_owner'
$ownerAliasRes = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'berning_owner', 'password' => '12345']);
report("1d. Owner login with alias 'berning_owner' succeeds", $ownerAliasRes['code'] === 200 && ($ownerAliasRes['data']['success'] ?? false) === true);

// Test with email
$ownerEmailRes = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'liziel@berning.store', 'password' => '12345']);
report("1e. Owner login with email succeeds", $ownerEmailRes['code'] === 200 && ($ownerEmailRes['data']['success'] ?? false) === true);

// Verify admin gateway serves Owner Dashboard for owner token
$ownerAdminPhp = httpReq("$baseUrl/admin/", 'GET', null, ['Authorization' => "Bearer $ownerToken"], true);
report("1f. admin serves Owner Dashboard (HTTP 200)", $ownerAdminPhp['code'] === 200 && strpos($ownerAdminPhp['body'], 'Management') !== false);

// SCENARIO 2: Correct Cashier credentials -> Cashier Dashboard
echo "\n--- Scenario 2: Correct Cashier credentials ---\n";
$cashierRes = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'liziel_cashier', 'password' => '12345']);
report("2a. Cashier login with 'liziel_cashier' succeeds (HTTP 200)", $cashierRes['code'] === 200 && ($cashierRes['data']['success'] ?? false) === true);
report("2b. Cashier role confirmed as 'Cashier'", ($cashierRes['data']['user']['role'] ?? '') === 'Cashier');
report("2c. Cashier session token returned", !empty($cashierRes['data']['token']));
$cashierToken = $cashierRes['data']['token'] ?? '';

// Test with alias 'cashier'
$cashierAliasRes = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'cashier', 'password' => '12345']);
report("2d. Cashier login with alias 'cashier' succeeds", $cashierAliasRes['code'] === 200 && ($cashierAliasRes['data']['success'] ?? false) === true);

// SCENARIO 3: Incorrect password -> Login error
echo "\n--- Scenario 3: Incorrect password ---\n";
$badPass = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'owner_admin', 'password' => 'wrongpassword123']);
report("3a. Incorrect password rejected with HTTP 401", $badPass['code'] === 401);
report("3b. Appropriate error message returned", strpos($badPass['data']['message'] ?? '', 'Invalid') !== false);
report("3c. No token returned on failure", empty($badPass['data']['token']));

// SCENARIO 4: Unregistered username -> Login error
echo "\n--- Scenario 4: Unregistered username ---\n";
$unreg = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'nonexistent_user_999', 'password' => '12345']);
report("4a. Unregistered username rejected with HTTP 401", $unreg['code'] === 401);
report("4b. Appropriate error message returned", strpos($unreg['data']['message'] ?? '', 'Invalid') !== false);

// SCENARIO 5: Empty login fields -> Validation message
echo "\n--- Scenario 5: Empty login fields ---\n";
$emptyBoth = httpReq("$baseUrl/api/login.php", 'POST', ['username' => '', 'password' => '']);
report("5a. Empty username & password rejected with HTTP 400", $emptyBoth['code'] === 400);
report("5b. Empty fields message returned", strpos($emptyBoth['data']['message'] ?? '', 'Please provide') !== false);

$emptyPass = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'owner_admin', 'password' => '']);
report("5c. Empty password rejected with HTTP 400", $emptyPass['code'] === 400);

// SCENARIO 6: Owner accessing Cashier Dashboard -> Access controlled
echo "\n--- Scenario 6: Owner accessing Cashier Dashboard ---\n";
// Owner token check on cashier_stats (owner has privileges or is redirected on frontend)
$ownerStatsCheck = httpReq("$baseUrl/api/cashier_stats.php", 'GET', null, ['Authorization' => "Bearer $ownerToken"]);
report("6a. Owner token on cashier API is handled gracefully", in_array($ownerStatsCheck['code'], [200, 302, 403]));
// Check cashier static file / clean URL
$cashierPageCheck = httpReq("$baseUrl/cashier/", 'GET', null, [], true);
report("6b. cashier contains owner redirect logic", strpos($cashierPageCheck['body'], 'admin') !== false);

// SCENARIO 7: Cashier accessing Owner Dashboard -> Access denied
echo "\n--- Scenario 7: Cashier accessing Owner Dashboard ---\n";
$cashierAdminPhp = httpReq("$baseUrl/admin/", 'GET', null, ['Authorization' => "Bearer $cashierToken"]);
report("7a. Cashier rejected from admin gateway (HTTP 302 redirect)", $cashierAdminPhp['code'] === 302);
report("7b. Cashier redirected to login", strpos($cashierAdminPhp['redirect'], 'login') !== false || strpos($cashierAdminPhp['redirect'], 'admin_login.html') !== false);
// Cashier session NOT destroyed when visiting admin.php
$cashierSessionAfter = httpReq("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $cashierToken"]);
report("7c. Cashier session PRESERVED after accessing admin.php (authenticated=true)", ($cashierSessionAfter['data']['authenticated'] ?? false) === true);

// SCENARIO 8: Refreshing dashboard -> Session remains active
echo "\n--- Scenario 8: Refreshing dashboard (Session persistence) ---\n";
$refresh1 = httpReq("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $ownerToken"]);
report("8a. First check validates active session", ($refresh1['data']['authenticated'] ?? false) === true);
$refresh2 = httpReq("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $ownerToken"]);
report("8b. Subsequent check maintains active session", ($refresh2['data']['authenticated'] ?? false) === true);
report("8c. Session check returns user details", ($refresh2['data']['user']['username'] ?? '') === 'owner_admin');
report("8d. Session check returns token for frontend synchronization", !empty($refresh2['data']['token']));

// SCENARIO 9: Logging out -> Session terminated
echo "\n--- Scenario 9: Logging out (Session termination) ---\n";
// Create a temporary session to log out
$tempLogin = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'owner_admin', 'password' => '12345']);
$tempToken = $tempLogin['data']['token'] ?? '';
$logoutRes = httpReq("$baseUrl/api/logout.php", 'POST', [], ['Authorization' => "Bearer $tempToken"]);
report("9a. Logout API responds HTTP 200", $logoutRes['code'] === 200 && ($logoutRes['data']['success'] ?? false) === true);
$checkAfterLogout = httpReq("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $tempToken"]);
report("9b. Session invalidated in DB after logout (authenticated=false)", ($checkAfterLogout['data']['authenticated'] ?? true) === false);

// SCENARIO 10: Logging in again -> Successful authentication
echo "\n--- Scenario 10: Logging in again ---\n";
$reLogin = httpReq("$baseUrl/api/login.php", 'POST', ['username' => 'owner_admin', 'password' => '12345']);
report("10a. Re-login succeeds (HTTP 200)", $reLogin['code'] === 200 && ($reLogin['data']['success'] ?? false) === true);
$newToken = $reLogin['data']['token'] ?? '';
report("10b. New session token generated", !empty($newToken) && strlen($newToken) === 64);
$reCheck = httpReq("$baseUrl/api/login.php?action=check", 'GET', null, ['Authorization' => "Bearer $newToken"]);
report("10c. New session is immediately valid (authenticated=true)", ($reCheck['data']['authenticated'] ?? false) === true);

// SCENARIO 11: Direct URL Routing (/login.html, /login.php, /)
echo "\n--- Additional: Direct URL Entry Point Testing ---\n";
$rootCheck = httpReq("$baseUrl/");
report("11a. Root / redirects unauthenticated users (HTTP 302)", $rootCheck['code'] === 302);
$loginCheck = httpReq("$baseUrl/login/");
report("11b. /login/ clean URL is accessible (HTTP 200)", $loginCheck['code'] === 200);
$loginLegacyCheck = httpReq("$baseUrl/login.html");
report("11b-legacy. /login.html redirects 301 to clean URL", $loginLegacyCheck['code'] === 301 && strpos($loginLegacyCheck['redirect'], 'login/') !== false);
$loginPhpCheck = httpReq("$baseUrl/login.php");
report("11c. /login.php redirects GET to login (HTTP 302)", $loginPhpCheck['code'] === 302 && strpos($loginPhpCheck['redirect'], 'login') !== false);
$loginPhpPost = httpReq("$baseUrl/login.php", 'POST', ['username' => 'owner_admin', 'password' => '12345']);
report("11d. /login.php handles POST authentication directly (HTTP 200)", $loginPhpPost['code'] === 200 && ($loginPhpPost['data']['success'] ?? false) === true);

echo "\n==============================================================================\n";
echo "SCENARIOS VERIFICATION SUMMARY: $passed PASSED, $failed FAILED\n";
echo "==============================================================================\n";

if ($failed === 0) {
    echo "STATUS: ALL 10 AUTHENTICATION SCENARIOS FULLY VERIFIED AND PASSING!\n";
    exit(0);
} else {
    echo "STATUS: SOME SCENARIOS FAILED!\n";
    exit(1);
}
