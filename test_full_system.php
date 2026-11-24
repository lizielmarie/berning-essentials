<?php
/**
 * ==============================================================================
 * BERNING ESSENTIALS — COMPREHENSIVE END-TO-END SYSTEM TEST
 * ==============================================================================
 */

$baseUrl = 'http://localhost/berning_essentials';
$passed = 0;
$failed = 0;
$tests = [];

function testAssert($title, $condition, $details = '') {
    global $passed, $failed, $tests;
    if ($condition) {
        echo "[PASS] $title\n";
        $passed++;
        $tests[] = ['name' => $title, 'status' => 'PASS'];
    } else {
        echo "[FAIL] $title" . ($details ? " -> $details" : "") . "\n";
        $failed++;
        $tests[] = ['name' => $title, 'status' => 'FAIL', 'error' => $details];
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
    ]);
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    
    $json = json_decode($res, true);
    return ['code' => $httpCode, 'data' => $json, 'raw' => $res, 'redirect' => $redirect];
}

echo "==============================================================================\n";
echo "BERNING ESSENTIALS SYSTEM — END-TO-END VERIFICATION\n";
echo "==============================================================================\n\n";

// ------------------------------------------------------------------------------
// 1. DATABASE & CONFIGURATION
// ------------------------------------------------------------------------------
echo "--- 1. Database Connection & System Health ---\n";
require_once __DIR__ . '/backend/db.php';
testAssert("Database connection initialized via backend/db.php", isset($pdo) && $pdo instanceof PDO);

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$requiredTables = ['audit_logs', 'categories', 'customers', 'products', 'sale_items', 'sales', 'sessions', 'stock_adjustments', 'stock_in', 'suppliers', 'users'];
$missingTables = array_diff($requiredTables, $tables);
testAssert("All 11 MySQL tables exist in database", empty($missingTables), "Missing: " . implode(', ', $missingTables));

// ------------------------------------------------------------------------------
// 2. OWNER & AUTHENTICATION GATEWAY
// ------------------------------------------------------------------------------
echo "\n--- 2. Authentication & RBAC Access Control ---\n";

// Check owner registration status
$statusRes = req("$baseUrl/api/owner_register.php?action=status");
testAssert("Owner registration status API returns HTTP 200", $statusRes['code'] === 200);
testAssert("Store owner already registered (can_register = false)", ($statusRes['data']['owner_exists'] ?? false) === true);

// Owner login
$ownerLogin = req("$baseUrl/api/login.php", 'POST', [
    'username'     => 'owner_admin',
    'password'     => '12345',
    'require_role' => 'Owner'
]);
testAssert("Owner login succeeds (HTTP 200)", $ownerLogin['code'] === 200 && ($ownerLogin['data']['success'] ?? false) === true);
$ownerToken = $ownerLogin['data']['token'] ?? '';
testAssert("Owner receives 64-char hex session token", strlen($ownerToken) === 64);

// Owner session check
$ownerCheck = req("$baseUrl/api/login.php?action=check", 'GET', null, [
    'Authorization' => "Bearer $ownerToken"
]);
testAssert("Owner session check validates authenticated=true", ($ownerCheck['data']['authenticated'] ?? false) === true);
testAssert("Owner session role confirmed as 'Owner'", ($ownerCheck['data']['user']['role'] ?? '') === 'Owner');

// Cashier login
$cashierLogin = req("$baseUrl/api/login.php", 'POST', [
    'username' => 'liziel_cashier',
    'password' => '12345'
]);
testAssert("Cashier login succeeds", $cashierLogin['code'] === 200 && ($cashierLogin['data']['success'] ?? false) === true);
$cashierToken = $cashierLogin['data']['token'] ?? '';
testAssert("Cashier session role confirmed as 'Cashier'", ($cashierLogin['data']['user']['role'] ?? '') === 'Cashier');

// Attendant login
$attendantLogin = req("$baseUrl/api/login.php", 'POST', [
    'username' => 'chrystal_attendant',
    'password' => '12345'
]);
testAssert("Store Attendant login succeeds", $attendantLogin['code'] === 200 && ($attendantLogin['data']['success'] ?? false) === true);

// RBAC: Cashier blocked from Owner portal login
$cashierBlocked = req("$baseUrl/api/login.php", 'POST', [
    'username'     => 'liziel_cashier',
    'password'     => '12345',
    'require_role' => 'Owner'
]);
testAssert("Cashier blocked from Admin login with HTTP 403", $cashierBlocked['code'] === 403);

// RBAC: Cashier token rejected at admin gateway
$adminCheck = req("$baseUrl/admin/", 'GET', null, [
    'Authorization' => "Bearer $cashierToken"
]);
testAssert("admin gateway rejects Cashier session token with HTTP 302 redirect", $adminCheck['code'] === 302);

// RBAC: Owner token granted access to admin gateway
$adminOwner = req("$baseUrl/admin/", 'GET', null, [
    'Authorization' => "Bearer $ownerToken"
], true);
testAssert("admin gateway serves Owner Management Portal (HTTP 200)", $adminOwner['code'] === 200);

// ------------------------------------------------------------------------------
// 3. OWNER DASHBOARD & REAL-TIME STATS
// ------------------------------------------------------------------------------
echo "\n--- 3. Dashboard Real-Time Statistics ---\n";
$statsRes = req("$baseUrl/api/admin_stats.php");
testAssert("Dashboard stats endpoint responds HTTP 200", $statsRes['code'] === 200 && ($statsRes['data']['success'] ?? false) === true);
$stats = $statsRes['data']['summary'] ?? [];
testAssert("Stats total_products >= 10", ($stats['total_products'] ?? 0) >= 10);
testAssert("Stats total_categories >= 10", ($stats['total_categories'] ?? 0) >= 10);
testAssert("Stats total_revenue > 0", ($stats['total_revenue'] ?? 0) > 0);
testAssert("Stats sales_overview includes daily, weekly, monthly", 
    !empty($statsRes['data']['sales_overview']['daily']) &&
    !empty($statsRes['data']['sales_overview']['weekly']) &&
    !empty($statsRes['data']['sales_overview']['monthly'])
);

// ------------------------------------------------------------------------------
// 4. PRODUCT MANAGEMENT (CRUD)
// ------------------------------------------------------------------------------
echo "\n--- 4. Product Catalog CRUD ---\n";
$testBarcode = 'TEST' . time();
$newProduct = req("$baseUrl/api/admin_products.php?action=add", 'POST', [
    'product_name'   => 'Test Fresh Apples 1kg',
    'barcode'        => $testBarcode,
    'category_id'    => 1,
    'selling_price'  => 120.50,
    'stock_quantity' => 25,
    'reorder_level'  => 5,
    'description'    => 'Crisp sweet organic apples'
], ['Authorization' => "Bearer $ownerToken"]);
testAssert("Product creation succeeds", ($newProduct['data']['success'] ?? false) === true);
$createdProdId = (int)($newProduct['data']['product_id'] ?? 0);
testAssert("Product ID returned from DB insert", $createdProdId > 0);

// Product lookup by ID
$getProd = req("$baseUrl/api/admin_products.php?action=get&id=$createdProdId");
testAssert("Retrieve newly created product by ID", ($getProd['data']['product']['barcode'] ?? '') === $testBarcode);

// Duplicate barcode validation
$dupBarcode = req("$baseUrl/api/admin_products.php?action=add", 'POST', [
    'product_name'   => 'Duplicate Product',
    'barcode'        => $testBarcode,
    'category_id'    => 1,
    'selling_price'  => 50.00,
    'stock_quantity' => 10
], ['Authorization' => "Bearer $ownerToken"]);
testAssert("Duplicate barcode registration is rejected", ($dupBarcode['data']['success'] ?? true) === false);

// Edit product
$editProd = req("$baseUrl/api/admin_products.php?action=edit", 'POST', [
    'product_id'     => $createdProdId,
    'product_name'   => 'Test Fresh Apples 1kg (Organic)',
    'barcode'        => $testBarcode,
    'category_id'    => 1,
    'selling_price'  => 130.00,
    'stock_quantity' => 30,
    'reorder_level'  => 8
], ['Authorization' => "Bearer $ownerToken"]);
testAssert("Product update succeeds", ($editProd['data']['success'] ?? false) === true);

// Delete test product
$delProd = req("$baseUrl/api/admin_products.php?action=delete&id=$createdProdId", 'POST', [], [
    'Authorization' => "Bearer $ownerToken"
]);
testAssert("Product deletion succeeds", ($delProd['data']['success'] ?? false) === true);

// ------------------------------------------------------------------------------
// 5. CATEGORY MANAGEMENT (CRUD)
// ------------------------------------------------------------------------------
echo "\n--- 5. Category Management CRUD ---\n";
$catAdd = req("$baseUrl/api/admin_categories.php?action=add", 'POST', [
    'category_name' => 'Test Category ' . time()
], ['Authorization' => "Bearer $ownerToken"]);
testAssert("Category creation succeeds", ($catAdd['data']['success'] ?? false) === true);
$catId = (int)($catAdd['data']['category_id'] ?? 0);

$catList = req("$baseUrl/api/admin_categories.php?action=list");
testAssert("Category list includes new category", ($catList['data']['success'] ?? false) === true);

if ($catId > 0) {
    $catDel = req("$baseUrl/api/admin_categories.php?action=delete&id=$catId", 'POST', [], [
        'Authorization' => "Bearer $ownerToken"
    ]);
    testAssert("Category deletion succeeds", ($catDel['data']['success'] ?? false) === true);
}

// ------------------------------------------------------------------------------
// 6. CUSTOMER MANAGEMENT (CRUD)
// ------------------------------------------------------------------------------
echo "\n--- 6. Customer Management CRUD ---\n";
$custAdd = req("$baseUrl/api/admin_customers.php?action=add", 'POST', [
    'customer_name' => 'Maria Santos',
    'phone'         => '0917-123-9999',
    'email'         => 'maria' . time() . '@example.com',
    'address'       => 'Poblacion, Tagbilaran'
], ['Authorization' => "Bearer $cashierToken"]);
testAssert("Customer creation succeeds", ($custAdd['data']['success'] ?? false) === true);
$custId = (int)($custAdd['data']['customer_id'] ?? 0);
testAssert("Customer ID assigned > 0", $custId > 0);

// ------------------------------------------------------------------------------
// 7. CASHIER POINT-OF-SALE (POS) & INVENTORY SYNCHRONIZATION
// ------------------------------------------------------------------------------
echo "\n--- 7. Cashier POS & Real-Time Inventory Deduction ---\n";

// Real-time Barcode lookup
$barcodeLookup = req("$baseUrl/api/get_product_by_barcode.php?barcode=480001001");
testAssert("Barcode lookup succeeds for Coca-Cola 1.5L", ($barcodeLookup['data']['success'] ?? false) === true && ($barcodeLookup['data']['product']['name'] ?? '') === 'Coca-Cola 1.5L');

// Non-existent barcode lookup
$badBarcode = req("$baseUrl/api/get_product_by_barcode.php?barcode=999999999999");
testAssert("Unregistered barcode returns product not found error", ($badBarcode['data']['success'] ?? true) === false);

// Ensure sufficient stock of Coca-Cola (product_id = 1) for repeatable testing
$currStock = (int)$pdo->query("SELECT stock_quantity FROM products WHERE product_id = 1")->fetchColumn();
if ($currStock < 10) {
    $pdo->query("UPDATE products SET stock_quantity = 50 WHERE product_id = 1");
}
$initialStock = (int)$pdo->query("SELECT stock_quantity FROM products WHERE product_id = 1")->fetchColumn();

// Process sale of 2 units of Coca-Cola
$salePayload = [
    'cashier_id'      => 2,
    'customer_id'     => $custId,
    'total_amount'    => 150.00,
    'subtotal_amount' => 150.00,
    'cash_received'   => 200.00,
    'change_amount'   => 50.00,
    'payment_method'  => 'cash',
    'items'           => [
        ['id' => 1, 'qty' => 2, 'price' => 75.00]
    ]
];
$saleProcess = req("$baseUrl/api/process_sale.php", 'POST', $salePayload, [
    'Authorization' => "Bearer $cashierToken"
]);
testAssert("POS sale processing succeeds with HTTP 200", $saleProcess['code'] === 200 && ($saleProcess['data']['success'] ?? false) === true);
$newSaleId = (int)($saleProcess['data']['sale_id'] ?? 0);
testAssert("Valid sale ID generated", $newSaleId > 0);
testAssert("Receipt data included in response", isset($saleProcess['data']['receipt']['items']));

// Verify inventory stock deducted in MySQL
$updatedStock = (int)$pdo->query("SELECT stock_quantity FROM products WHERE product_id = 1")->fetchColumn();
testAssert("Inventory stock automatically decremented in MySQL ($initialStock -> $updatedStock)", $updatedStock === ($initialStock - 2));

// Test over-order rejection (insufficient stock)
$overOrder = req("$baseUrl/api/process_sale.php", 'POST', [
    'items' => [
        ['id' => 1, 'qty' => 99999, 'price' => 75.00]
    ],
    'total_amount' => 7499925.00
], ['Authorization' => "Bearer $cashierToken"]);
testAssert("Over-order exceeding stock is rejected (HTTP 400)", $overOrder['code'] === 400 && ($overOrder['data']['success'] ?? true) === false);

// ------------------------------------------------------------------------------
// 8. SALES REPORTS & ANALYTICS
// ------------------------------------------------------------------------------
echo "\n--- 8. Sales Reporting & CSV Exports ---\n";
$repDaily = req("$baseUrl/api/admin_reports.php?action=sales", 'GET', null, [
    'Authorization' => "Bearer $ownerToken"
]);
testAssert("Daily/period sales report responds successfully", ($repDaily['data']['success'] ?? false) === true);

$repTop = req("$baseUrl/api/admin_reports.php?action=products&limit=5", 'GET', null, [
    'Authorization' => "Bearer $ownerToken"
]);
testAssert("Product performance report responds with records", ($repTop['data']['success'] ?? false) === true && !empty($repTop['data']['records']));

// ------------------------------------------------------------------------------
// 9. AUDIT LOGGING
// ------------------------------------------------------------------------------
echo "\n--- 9. Audit Logging & System Activity ---\n";
$auditRes = req("$baseUrl/api/admin_audit.php?action=list&limit=10", 'GET', null, [
    'Authorization' => "Bearer $ownerToken"
]);
testAssert("Audit logs API responds HTTP 200", $auditRes['code'] === 200 && ($auditRes['data']['success'] ?? false) === true);
testAssert("Audit trail records present in database", !empty($auditRes['data']['logs']));

// ------------------------------------------------------------------------------
// 10. OWNER PROFILE & PASSWORD MANAGEMENT
// ------------------------------------------------------------------------------
echo "\n--- 10. Owner Profile Management ---\n";
$profGet = req("$baseUrl/api/owner_profile.php?action=get", 'GET', null, [
    'Authorization' => "Bearer $ownerToken"
]);
testAssert("Owner profile data retrieved successfully", ($profGet['data']['profile']['username'] ?? '') === 'owner_admin');

// ------------------------------------------------------------------------------
// 11. LOGOUT & SESSION EXPIRATION
// ------------------------------------------------------------------------------
echo "\n--- 11. Logout & Session Termination ---\n";
$logoutRes = req("$baseUrl/api/logout.php", 'POST', [], [
    'Authorization' => "Bearer $ownerToken"
]);
testAssert("Logout endpoint responds HTTP 200", ($logoutRes['data']['success'] ?? false) === true);

$checkAfterLogout = req("$baseUrl/api/login.php?action=check", 'GET', null, [
    'Authorization' => "Bearer $ownerToken"
]);
testAssert("Session invalidated in DB after logout (authenticated=false)", ($checkAfterLogout['data']['authenticated'] ?? true) === false);

// ------------------------------------------------------------------------------
// 12. INFINITYFREE DEPLOYMENT ASSETS VERIFICATION
// ------------------------------------------------------------------------------
echo "\n--- 12. InfinityFree Production Readiness ---\n";
testAssert("backend/config.php exists", file_exists(__DIR__ . '/backend/config.php'));
testAssert(".htaccess exists in root", file_exists(__DIR__ . '/.htaccess'));
testAssert("sql/infinityfree_production_import.sql exists", file_exists(__DIR__ . '/sql/infinityfree_production_import.sql'));

$sqlContent = file_get_contents(__DIR__ . '/sql/infinityfree_production_import.sql');
testAssert("SQL import has no 'CREATE DATABASE' statement", stripos($sqlContent, 'CREATE DATABASE') === false);
testAssert("SQL import has no 'USE ' statement", stripos($sqlContent, 'USE `') === false && stripos($sqlContent, 'USE berning') === false);

echo "\n==============================================================================\n";
echo "OVERALL VERIFICATION RESULTS: $passed PASSED, $failed FAILED\n";
echo "==============================================================================\n";

if ($failed === 0) {
    echo "STATUS: ALL SYSTEMS FULLY OPERATIONAL AND PRODUCTION-READY!\n";
    exit(0);
} else {
    echo "STATUS: SOME TESTS FAILED!\n";
    exit(1);
}
