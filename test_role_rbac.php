<?php
/**
 * BERNING STORE — MULTI-ROLE ACCESS CONTROL & SESSION VERIFICATION
 */
require_once __DIR__ . '/api/db.php';

echo "=========================================================\n";
echo "BERNING STORE MULTI-ROLE & RBAC VERIFICATION SUITE\n";
echo "=========================================================\n\n";

$baseUrl = 'http://localhost/berning_essentials';
$passed = 0;
$failed = 0;

function postJson($url, $data, $headers = []) {
    $ch = curl_init($url);
    $reqHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $reqHeaders[] = "$k: $v";
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => $reqHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($res, true), 'raw' => $res];
}

function getJson($url, $headers = []) {
    $ch = curl_init($url);
    $reqHeaders = [];
    foreach ($headers as $k => $v) {
        $reqHeaders[] = "$k: $v";
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $reqHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($res, true), 'raw' => $res];
}

function assertTest($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "PASS: $name\n";
        $passed++;
    } else {
        echo "FAIL: $name ($details)\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// 1. Verify Database Roles exist
// -------------------------------------------------------------
$stmt = $pdo->query("SELECT user_id, full_name, username, email, role, status FROM users ORDER BY user_id");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ownerUser = null;
$cashierUser = null;
$attendantUser = null;

foreach ($users as $u) {
    if ($u['role'] === 'Owner') $ownerUser = $u;
    if ($u['role'] === 'Cashier' && $u['username'] === 'liziel_cashier') $cashierUser = $u;
    if ($u['role'] === 'Store Attendant') $attendantUser = $u;
}

assertTest("1. Verify Owner account exists in DB with role 'Owner'", $ownerUser !== null && $ownerUser['role'] === 'Owner', "Owner role mismatch");
assertTest("2. Verify Cashier account exists in DB with role 'Cashier'", $cashierUser !== null && $cashierUser['role'] === 'Cashier', "Cashier role mismatch");
assertTest("3. Verify Attendant account exists in DB with role 'Store Attendant'", $attendantUser !== null && $attendantUser['role'] === 'Store Attendant', "Attendant role mismatch");

// -------------------------------------------------------------
// 2. Test Cashier Login via api/login.php
// -------------------------------------------------------------
$cashierLogin = postJson("$baseUrl/api/login.php", [
    'username' => 'liziel_cashier',
    'password' => '12345'
]);

assertTest("4. Cashier login succeeds", ($cashierLogin['code'] === 200 && ($cashierLogin['data']['success'] ?? false) === true), "Code: {$cashierLogin['code']}");
assertTest("5. Cashier session correctly returns role 'Cashier'", ($cashierLogin['data']['user']['role'] ?? '') === 'Cashier', "Got role: " . ($cashierLogin['data']['user']['role'] ?? 'none'));

$cashierToken = $cashierLogin['data']['token'] ?? '';
assertTest("6. Cashier receives 64-char hex session token", strlen($cashierToken) === 64, "Token len: " . strlen($cashierToken));

// -------------------------------------------------------------
// 3. Test Cashier Attempting Owner Portal Login (require_role = Owner)
// -------------------------------------------------------------
$cashierBlocked = postJson("$baseUrl/api/login.php", [
    'username'     => 'liziel_cashier',
    'password'     => '12345',
    'require_role' => 'Owner'
]);

assertTest("7. Cashier login blocked on Admin login (HTTP 403)", $cashierBlocked['code'] === 403, "Code: {$cashierBlocked['code']}");
assertTest("8. Access denied message specifies role requirement", strpos($cashierBlocked['data']['message'] ?? '', 'requires \'Owner\'') !== false, "Message: " . ($cashierBlocked['data']['message'] ?? ''));

// -------------------------------------------------------------
// 4. Test Cashier Attempting to access admin Gateway
// -------------------------------------------------------------
$ch = curl_init("$baseUrl/admin/");
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer $cashierToken"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false, // Do not auto-follow redirects
    CURLOPT_TIMEOUT        => 10,
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);

assertTest("9. admin gateway rejects Cashier session token with HTTP 302", $httpCode === 302, "HTTP code: $httpCode");
assertTest("10. admin gateway redirects to login", (strpos($redirectUrl, 'login') !== false || strpos($redirectUrl, 'admin_login.html') !== false), "Redirect URL: $redirectUrl");

// -------------------------------------------------------------
// 5. Test Attendant Login via api/admin_users.php?action=login
// -------------------------------------------------------------
$attendantLogin = postJson("$baseUrl/api/admin_users.php?action=login", [
    'username' => 'chrystal_attendant',
    'password' => '12345'
]);

assertTest("11. Store Attendant login succeeds", ($attendantLogin['code'] === 200 && ($attendantLogin['data']['success'] ?? false) === true), "Code: {$attendantLogin['code']}");
assertTest("12. Store Attendant session returns role 'Store Attendant'", ($attendantLogin['data']['user']['role'] ?? '') === 'Store Attendant', "Got: " . ($attendantLogin['data']['user']['role'] ?? 'none'));

// -------------------------------------------------------------
// 6. Test Owner Session Validation
// -------------------------------------------------------------
// Create owner session directly to verify access
require_once __DIR__ . '/api/auth.php';
$ownerToken = bs_create_session($pdo, (int)$ownerUser['user_id'], 'Owner');

$checkRes = getJson("$baseUrl/api/login.php?action=check", [
    'Authorization' => "Bearer $ownerToken"
]);

assertTest("13. Owner session check returns authenticated=true", ($checkRes['data']['authenticated'] ?? false) === true, "Auth: " . var_export($checkRes['data']['authenticated'] ?? null, true));
assertTest("14. Owner session check returns authoritative role 'Owner'", ($checkRes['data']['user']['role'] ?? '') === 'Owner', "Role: " . ($checkRes['data']['user']['role'] ?? 'none'));
assertTest("15. Owner session user_id matches database owner ID", (int)($checkRes['data']['user']['user_id'] ?? 0) === (int)$ownerUser['user_id'], "ID: " . ($checkRes['data']['user']['user_id'] ?? 0));

// Test admin gateway with Owner session (clean URL)
$ch = curl_init("$baseUrl/admin/");
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer $ownerToken"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
]);
$htmlRes = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertTest("16. admin gateway serves Owner Management Portal (HTTP 200)", $httpCode === 200, "HTTP code: $httpCode");
assertTest("17. admin gateway response contains Owner Dashboard content", strpos($htmlRes, 'Berning Store') !== false && strpos($htmlRes, 'Management Portal') !== false, "Response does not contain expected dashboard HTML");

// -------------------------------------------------------------
// 7. Test Logout and Session Invalidation
// -------------------------------------------------------------
$logoutRes = postJson("$baseUrl/api/logout.php", [], [
    'Authorization' => "Bearer $ownerToken"
]);
assertTest("18. Owner logout endpoint succeeds", ($logoutRes['data']['success'] ?? false) === true, "Logout failed");

$checkAfterLogout = getJson("$baseUrl/api/login.php?action=check", [
    'Authorization' => "Bearer $ownerToken"
]);
assertTest("19. Session is invalid after logout (authenticated=false)", ($checkAfterLogout['data']['authenticated'] ?? true) === false, "Session was not invalidated");

echo "\n=========================================================\n";
echo "RBAC TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "=========================================================\n";
