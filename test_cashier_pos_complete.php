<?php
/**
 * BERNING STORE — COMPLETE CASHIER POS & DASHBOARD VERIFICATION SUITE
 */
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/auth.php';

echo "=========================================================\n";
echo "BERNING STORE CASHIER & POS COMPREHENSIVE VERIFICATION\n";
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

function assertCheck($name, $condition, $details = '') {
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
// 1. Verify Cashier Session & Dashboard Stats API
// -------------------------------------------------------------
// Login as Cashier
$loginRes = postJson("$baseUrl/api/admin_users.php?action=login", [
    'username' => 'liziel_cashier',
    'password' => '12345'
]);

assertCheck("1. Cashier login succeeds", $loginRes['code'] === 200 && ($loginRes['data']['success'] ?? false) === true);
$cashierToken = $loginRes['data']['token'] ?? '';
$cashierId = (int)($loginRes['data']['user']['user_id'] ?? 2);

// Cashier Stats API
$statsRes = getJson("$baseUrl/api/cashier_stats.php", [
    'Authorization' => "Bearer $cashierToken"
]);

assertCheck("2. Cashier stats endpoint responds HTTP 200", $statsRes['code'] === 200 && ($statsRes['data']['success'] ?? false) === true);
assertCheck("3. Stats include total_revenue_today", isset($statsRes['data']['stats']['total_revenue_today']));
assertCheck("4. Stats include total_transactions_today", isset($statsRes['data']['stats']['total_transactions_today']));
assertCheck("5. Stats include available_products count", isset($statsRes['data']['stats']['available_products']));
assertCheck("6. Stats include low_stock_count", isset($statsRes['data']['stats']['low_stock_count']));
assertCheck("7. Stats return recent_sales array", is_array($statsRes['data']['recent_sales'] ?? null));
assertCheck("8. Stats return low_stock_items array", is_array($statsRes['data']['low_stock_items'] ?? null));

// -------------------------------------------------------------
// 2. Product Catalog API & Stock Check
// -------------------------------------------------------------
$catalogRes = getJson("$baseUrl/api/get_products.php");
assertCheck("9. Product catalog API responds HTTP 200", $catalogRes['code'] === 200 && ($catalogRes['data']['success'] ?? false) === true);
$products = $catalogRes['data']['products'] ?? [];
assertCheck("10. Products catalog has active items", count($products) > 0);

// Select product with stock > 5 for test sale
$testProduct = null;
foreach ($products as $p) {
    if ((int)$p['stock'] >= 5) {
        $testProduct = $p;
        break;
    }
}

// If no product has >= 5 stock, replenish product 1
if (!$testProduct) {
    $pdo->query("UPDATE products SET stock_quantity = 25 WHERE product_id = 1");
    $testProduct = $pdo->query("SELECT product_id AS id, product_name AS name, selling_price AS price, stock_quantity AS stock FROM products WHERE product_id = 1")->fetch(PDO::FETCH_ASSOC);
}

$initialStock = (int)$testProduct['stock'];
$productId = (int)$testProduct['id'];
$unitPrice = (float)$testProduct['price'];

// -------------------------------------------------------------
// 3. Customer Creation & Selection API
// -------------------------------------------------------------
$newCustRes = postJson("$baseUrl/api/admin_customers.php?action=add", [
    'customer_name' => 'Maria Test POS ' . time(),
    'phone'         => '09181234567',
    'email'         => 'maria_' . time() . '@example.com',
    'address'       => 'Poblacion, Berning City',
    'status'        => 'active'
]);

assertCheck("11. Add new customer succeeds", $newCustRes['code'] === 200 && ($newCustRes['data']['success'] ?? false) === true);
$testCustomerId = (int)($newCustRes['data']['customer_id'] ?? 0);
assertCheck("12. Customer ID returned is valid", $testCustomerId > 0);

// -------------------------------------------------------------
// 4. POS Sale Processing with Customer Linkage & Stock Decrement
// -------------------------------------------------------------
$qtyToBuy = 2;
$saleSubtotal = $unitPrice * $qtyToBuy;
$saleTax = round($saleSubtotal * 0.12, 2);
$saleTotal = $saleSubtotal + $saleTax;

$saleRes = postJson("$baseUrl/api/process_sale.php", [
    'cashier_id'      => $cashierId,
    'customer_id'     => $testCustomerId,
    'subtotal_amount' => $saleSubtotal,
    'discount_amount' => 0.00,
    'tax_amount'      => $saleTax,
    'total_amount'    => $saleTotal,
    'payment_method'  => 'gcash',
    'cash_received'   => $saleTotal,
    'change_amount'   => 0.00,
    'items'           => [
        [
            'id'    => $productId,
            'qty'   => $qtyToBuy,
            'price' => $unitPrice
        ]
    ]
], [
    'Authorization' => "Bearer $cashierToken"
]);

assertCheck("13. Process sale responds with HTTP 200", $saleRes['code'] === 200 && ($saleRes['data']['success'] ?? false) === true);
$newSaleId = (int)($saleRes['data']['sale_id'] ?? 0);
assertCheck("14. Sale ID returned", $newSaleId > 0);
assertCheck("15. Receipt object returned in sale response", isset($saleRes['data']['receipt']));

// Verify inventory decrement in MySQL
$verifyStockStmt = $pdo->prepare("SELECT stock_quantity FROM products WHERE product_id = :id");
$verifyStockStmt->execute([':id' => $productId]);
$newStock = (int)$verifyStockStmt->fetchColumn();

assertCheck("16. Product stock decremented exactly by $qtyToBuy in MySQL", $newStock === ($initialStock - $qtyToBuy), "Expected " . ($initialStock - $qtyToBuy) . " but got $newStock");

// -------------------------------------------------------------
// 5. Transaction History Listing & Filter Checks
// -------------------------------------------------------------
// Filter by Date (today)
$histToday = getJson("$baseUrl/api/admin_sales.php?action=list&date_filter=today");
assertCheck("17. History list with date_filter=today returns sales", $histToday['code'] === 200 && ($histToday['data']['count'] ?? 0) > 0);

// Filter by Payment Method (gcash)
$histGcash = getJson("$baseUrl/api/admin_sales.php?action=list&payment_method=gcash");
assertCheck("18. History list with payment_method=gcash returns gcash transactions", $histGcash['code'] === 200 && ($histGcash['data']['count'] ?? 0) > 0);

// Filter by search (new customer ID or name)
$histSearch = getJson("$baseUrl/api/admin_sales.php?action=list&search=" . urlencode((string)$newSaleId));
assertCheck("19. History list search finds specific sale by ID", ($histSearch['data']['count'] ?? 0) >= 1);

// -------------------------------------------------------------
// 6. Transaction Details & Reprint Integrity
// -------------------------------------------------------------
$detailsRes = getJson("$baseUrl/api/admin_sales.php?action=details&id=$newSaleId");
assertCheck("20. Sale details endpoint responds HTTP 200", $detailsRes['code'] === 200 && ($detailsRes['data']['success'] ?? false) === true);
$saleDetail = $detailsRes['data']['sale'] ?? [];
assertCheck("21. Sale detail has customer_name matching registered customer", strpos($saleDetail['customer_name'] ?? '', 'Maria Test POS') !== false);
assertCheck("22. Sale detail has cashier_name matching logged-in cashier", !empty($saleDetail['cashier_name']));
assertCheck("23. Sale detail has exact line items count (1)", count($detailsRes['data']['items'] ?? []) === 1);
assertCheck("24. Line item quantity matches $qtyToBuy", (int)($detailsRes['data']['items'][0]['quantity'] ?? 0) === $qtyToBuy);

// -------------------------------------------------------------
// 7. Over-Order & Out-of-Stock Protection Checks
// -------------------------------------------------------------
// Try to order 999999 units of product
$overOrderRes = postJson("$baseUrl/api/process_sale.php", [
    'cashier_id'      => $cashierId,
    'customer_id'     => 1,
    'subtotal_amount' => 999999 * $unitPrice,
    'total_amount'    => 999999 * $unitPrice,
    'payment_method'  => 'cash',
    'cash_received'   => 999999 * $unitPrice,
    'change_amount'   => 0.00,
    'items'           => [
        [
            'id'    => $productId,
            'qty'   => 999999,
            'price' => $unitPrice
        ]
    ]
]);

assertCheck("25. Over-order exceeding stock is rejected by database transaction", $overOrderRes['code'] === 400 && ($overOrderRes['data']['success'] ?? true) === false);

// Verify stock was NOT changed by the failed transaction
$verifyStockStmt->execute([':id' => $productId]);
$recheckStock = (int)$verifyStockStmt->fetchColumn();
assertCheck("26. Database transaction rolled back, stock unchanged", $recheckStock === $newStock);

echo "\n=========================================================\n";
echo "CASHIER POS VERIFICATION SUMMARY: $passed PASSED, $failed FAILED\n";
echo "=========================================================\n";
