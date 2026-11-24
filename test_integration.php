<?php
/**
 * BERNING STORE — FULL SYSTEM INTEGRATION TEST SUITE
 * Tests all backend APIs, database CRUD operations, transactions,
 * constraints, POS sales processing, and inventory synchronization.
 */

$baseUrl = 'http://localhost/berning_essentials';
$passCount = 0;
$failCount = 0;
$testResults = [];

function runTest($testName, $callback) {
    global $passCount, $failCount, $testResults;
    echo "RUNNING: $testName ... ";
    try {
        $result = $callback();
        if ($result === true) {
            echo "PASSED\n";
            $passCount++;
            $testResults[] = ['name' => $testName, 'status' => 'PASS', 'error' => null];
        } else {
            echo "FAILED: $result\n";
            $failCount++;
            $testResults[] = ['name' => $testName, 'status' => 'FAIL', 'error' => $result];
        }
    } catch (\Throwable $t) {
        echo "FAILED WITH EXCEPTION: " . $t->getMessage() . "\n";
        $failCount++;
        $testResults[] = ['name' => $testName, 'status' => 'FAIL', 'error' => $t->getMessage()];
    }
}

function httpGet($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

function httpPost($url, $payload = []) {
    $ch = curl_init($url);
    $jsonData = json_encode($payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

echo "=========================================================\n";
echo "BERNING STORE INTEGRATION & VERIFICATION TEST SUITE\n";
echo "=========================================================\n\n";

// 1. DATABASE CONNECTIVITY & CONFIG
runTest("1. Database Connection (api/db.php)", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/api/admin_stats.php");
    if ($res['code'] !== 200) return "HTTP code {$res['code']}";
    if (!isset($res['data']['success']) || !$res['data']['success']) return "API returned success=false";
    return true;
});

// 2. DASHBOARD STATS
runTest("2. Dashboard Stats Calculation (api/admin_stats.php)", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/api/admin_stats.php");
    $data = $res['data'];
    if (!isset($data['summary']['total_products']) || $data['summary']['total_products'] < 10) return "Incorrect total_products: " . ($data['summary']['total_products'] ?? 'null');
    if (!isset($data['summary']['total_categories']) || $data['summary']['total_categories'] < 10) return "Incorrect total_categories";
    if (!isset($data['summary']['total_sales_count']) || $data['summary']['total_sales_count'] < 16) return "Incorrect total_sales_count";
    if (!isset($data['summary']['total_revenue']) || $data['summary']['total_revenue'] <= 0) return "Incorrect total_revenue";
    if (!isset($data['sales_overview']['daily']) || !is_array($data['sales_overview']['daily'])) return "Missing daily sales overview";
    if (!isset($data['recent_sales']) || count($data['recent_sales']) === 0) return "Missing recent_sales";
    return true;
});

// 3. PRODUCT CATALOG CRUD & SEARCH
$testBarcode = "480009999";
$createdProductId = null;

runTest("3. Product List Retrieval (api/admin_products.php?action=list)", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/api/admin_products.php?action=list");
    if ($res['code'] !== 200 || !$res['data']['success']) return "Failed to list products";
    if (count($res['data']['products']) < 10) return "Expected at least 10 products";
    return true;
});

runTest("4. Product Search & Filter (api/admin_products.php?action=list&search=Coca)", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/api/admin_products.php?action=list&search=Coca");
    if (!$res['data']['success']) return "Search failed";
    if (count($res['data']['products']) !== 1) return "Expected exactly 1 product matching 'Coca'";
    if ($res['data']['products'][0]['product_name'] !== "Coca-Cola 1.5L") return "Wrong product returned";
    return true;
});

runTest("5. Add New Product (api/admin_products.php?action=add)", function() use ($baseUrl, $testBarcode, &$createdProductId) {
    $payload = [
        'barcode' => $testBarcode,
        'product_name' => "Verification Test Biscuits",
        'category_id' => 10, // Biscuits
        'selling_price' => 25.50,
        'stock_quantity' => 100,
        'reorder_level' => 15,
        'expiry_date' => "2027-12-31"
    ];
    $res = httpPost("$baseUrl/api/admin_products.php?action=add", $payload);
    if ($res['code'] !== 200 || !$res['data']['success']) return "Add product failed: " . ($res['data']['message'] ?? $res['raw']);
    $createdProductId = (int)$res['data']['product_id'];
    if ($createdProductId <= 0) return "Invalid product_id returned";
    return true;
});

runTest("6. Verify Newly Added Product in DB (api/admin_products.php?action=get)", function() use ($baseUrl, &$createdProductId, $testBarcode) {
    $res = httpGet("$baseUrl/api/admin_products.php?action=get&id=$createdProductId");
    if ($res['code'] !== 200 || !$res['data']['success']) return "Failed to fetch created product";
    $p = $res['data']['product'];
    if ($p['barcode'] !== $testBarcode) return "Barcode mismatch";
    if ($p['product_name'] !== "Verification Test Biscuits") return "Name mismatch";
    if ((float)$p['selling_price'] !== 25.50) return "Price mismatch: " . $p['selling_price'];
    if ((int)$p['stock_quantity'] !== 100) return "Stock mismatch: " . $p['stock_quantity'];
    return true;
});

runTest("7. Edit Product (api/admin_products.php?action=edit)", function() use ($baseUrl, &$createdProductId, $testBarcode) {
    $payload = [
        'product_id' => $createdProductId,
        'barcode' => $testBarcode,
        'product_name' => "Verification Test Biscuits (Updated)",
        'category_id' => 10,
        'selling_price' => 29.75,
        'stock_quantity' => 95,
        'reorder_level' => 20,
        'expiry_date' => "2028-01-15"
    ];
    $res = httpPost("$baseUrl/api/admin_products.php?action=edit", $payload);
    if ($res['code'] !== 200 || !$res['data']['success']) return "Edit product failed: " . ($res['data']['message'] ?? $res['raw']);
    
    // Verify update persisted
    $verify = httpGet("$baseUrl/api/admin_products.php?action=get&id=$createdProductId");
    $p = $verify['data']['product'];
    if ($p['product_name'] !== "Verification Test Biscuits (Updated)") return "Updated name not persisted";
    if ((float)$p['selling_price'] !== 29.75) return "Updated price not persisted";
    if ((int)$p['stock_quantity'] !== 95) return "Updated stock not persisted";
    return true;
});

runTest("8. Duplicate Barcode Validation (api/admin_products.php?action=add)", function() use ($baseUrl, $testBarcode) {
    $payload = [
        'barcode' => $testBarcode, // Existing barcode
        'product_name' => "Duplicate Item",
        'category_id' => 1,
        'selling_price' => 10.00,
        'stock_quantity' => 10,
        'reorder_level' => 5,
        'expiry_date' => null
    ];
    $res = httpPost("$baseUrl/api/admin_products.php?action=add", $payload);
    if ($res['data']['success'] === true) return "Allowed duplicate barcode unexpectedly";
    return true;
});

runTest("9. Delete Product (api/admin_products.php?action=delete)", function() use ($baseUrl, &$createdProductId) {
    $res = httpPost("$baseUrl/api/admin_products.php?action=delete", ['product_id' => $createdProductId]);
    if ($res['code'] !== 200 || !$res['data']['success']) return "Delete failed: " . ($res['data']['message'] ?? $res['raw']);
    
    // Verify it is gone
    $check = httpGet("$baseUrl/api/admin_products.php?action=get&id=$createdProductId");
    if ($check['data']['success'] === true) return "Product still exists after deletion";
    return true;
});

// 4. CATEGORIES CRUD
$createdCatId = null;
runTest("10. Category CRUD (Add, List, Edit, Delete)", function() use ($baseUrl, &$createdCatId) {
    // Add
    $addRes = httpPost("$baseUrl/api/admin_categories.php?action=add", ['category_name' => 'Organic Fresh Produce']);
    if (!$addRes['data']['success']) return "Add category failed: " . ($addRes['data']['message'] ?? $addRes['raw']);
    $createdCatId = (int)$addRes['data']['category_id'];
    
    // List & verify
    $listRes = httpGet("$baseUrl/api/admin_categories.php?action=list&search=" . urlencode('Organic Fresh Produce'));
    if (count($listRes['data']['categories']) !== 1) return "New category not found in search";
    
    // Edit
    $editRes = httpPost("$baseUrl/api/admin_categories.php?action=edit", [
        'category_id' => $createdCatId,
        'category_name' => 'Organic Gourmet Produce'
    ]);
    if (!$editRes['data']['success']) return "Edit category failed: " . ($editRes['data']['message'] ?? $editRes['raw']);
    
    // Delete
    $delRes = httpPost("$baseUrl/api/admin_categories.php?action=delete", ['category_id' => $createdCatId]);
    if (!$delRes['data']['success']) return "Delete category failed: " . ($delRes['data']['message'] ?? $delRes['raw']);
    
    return true;
});

// 5. SALES & ITEM DETAILS
runTest("11. Sales Ledger & Drilldown (api/admin_sales.php)", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/api/admin_sales.php?action=list");
    if (!$res['data']['success']) return "Failed to list sales";
    if (empty($res['data']['sales'])) return "Sales array is empty";
    
    $firstSaleId = $res['data']['sales'][0]['sale_id'];
    $detailRes = httpGet("$baseUrl/api/admin_sales.php?action=details&id=$firstSaleId");
    if (!$detailRes['data']['success']) return "Failed to get sale details for ID $firstSaleId";
    $sale = $detailRes['data']['sale'];
    if (!isset($sale['cashier_name']) || empty($sale['cashier_name'])) return "Missing cashier_name in details";
    if (!isset($sale['items']) || !is_array($sale['items']) || count($sale['items']) === 0) return "Sale items missing or empty";
    return true;
});

// 6. INVENTORY & STOCK-IN
$createdStockInId = null;
runTest("12. Inventory Stock-In Transaction (api/admin_inventory.php?action=add_stock_in)", function() use ($baseUrl, &$createdStockInId) {
    // Check initial stock of product 4 (Lucky Me Pancit Canton)
    $prodBefore = httpGet("$baseUrl/api/admin_products.php?action=get&id=4");
    $stockBefore = (int)$prodBefore['data']['product']['stock_quantity'];
    
    // Add stock-in of 15 units
    $payload = [
        'product_id' => 4,
        'supplier_id' => 1,
        'quantity' => 15,
        'date_received' => date('Y-m-d'),
        'received_by' => 2 // Liziel Cashier
    ];
    $res = httpPost("$baseUrl/api/admin_inventory.php?action=add_stock_in", $payload);
    if (!$res['data']['success']) return "Add stock-in failed: " . ($res['data']['message'] ?? $res['raw']);
    $createdStockInId = $res['data']['stockin_id'];
    
    // Check stock increased by exactly 15
    $prodAfter = httpGet("$baseUrl/api/admin_products.php?action=get&id=4");
    $stockAfter = (int)$prodAfter['data']['product']['stock_quantity'];
    if ($stockAfter !== ($stockBefore + 15)) {
        return "Stock quantity did not increase correctly: before=$stockBefore, after=$stockAfter, expected=" . ($stockBefore + 15);
    }
    
    return true;
});

// 7. SUPPLIER CRUD
$createdSupplierId = null;
runTest("13. Supplier Directory CRUD (api/admin_suppliers.php)", function() use ($baseUrl, &$createdSupplierId) {
    // Add supplier
    $addRes = httpPost("$baseUrl/api/admin_suppliers.php?action=add", [
        'supplier_name' => "Island Fresh Logistics",
        'contact_number' => "09209876543",
        'address' => "Panglao Island, Bohol"
    ]);
    if (!$addRes['data']['success']) return "Add supplier failed: " . ($addRes['data']['message'] ?? $addRes['raw']);
    $createdSupplierId = (int)$addRes['data']['supplier_id'];
    
    // Details
    $detailRes = httpGet("$baseUrl/api/admin_suppliers.php?action=get&id=$createdSupplierId");
    if (!$detailRes['data']['success'] || $detailRes['data']['supplier']['supplier_name'] !== "Island Fresh Logistics") {
        return "Failed to fetch created supplier details";
    }
    
    // Edit
    $editRes = httpPost("$baseUrl/api/admin_suppliers.php?action=edit", [
        'supplier_id' => $createdSupplierId,
        'supplier_name' => "Island Fresh Logistics (Updated)",
        'contact_number' => "09209876544",
        'address' => "Dauis, Bohol"
    ]);
    if (!$editRes['data']['success']) return "Edit supplier failed";
    
    // Delete
    $delRes = httpPost("$baseUrl/api/admin_suppliers.php?action=delete", ['supplier_id' => $createdSupplierId]);
    if (!$delRes['data']['success']) return "Delete supplier failed: " . ($delRes['data']['message'] ?? $delRes['raw']);
    
    return true;
});

// 8. USERS CRUD & AUTHENTICATION
$createdUserId = null;
runTest("14. Staff User Authentication & Management (api/admin_users.php)", function() use ($baseUrl, &$createdUserId) {
    // Test valid cashier login
    $loginRes = httpPost("$baseUrl/api/admin_users.php?action=login", [
        'username' => 'liziel_cashier',
        'password' => '12345'
    ]);
    if (!$loginRes['data']['success'] || $loginRes['data']['user']['role'] !== 'Cashier') {
        return "Valid login failed: " . ($loginRes['data']['message'] ?? $loginRes['raw']);
    }
    
    // Test invalid login
    $badLogin = httpPost("$baseUrl/api/admin_users.php?action=login", [
        'username' => 'liziel_cashier',
        'password' => 'wrong_pass'
    ]);
    if ($badLogin['data']['success'] === true) return "Invalid login should have failed";
    
    // Add new user
    $testUser = "test_staff_" . time();
    $addUser = httpPost("$baseUrl/api/admin_users.php?action=add", [
        'full_name' => "Test Staff Member",
        'username' => $testUser,
        'password' => "staff123",
        'role' => "Store Attendant"
    ]);
    if (!$addUser['data']['success']) return "Add user failed: " . ($addUser['data']['message'] ?? $addUser['raw']);
    $createdUserId = (int)$addUser['data']['user_id'];
    
    // Test login for newly added user
    $newLogin = httpPost("$baseUrl/api/admin_users.php?action=login", [
        'username' => $testUser,
        'password' => 'staff123'
    ]);
    if (!$newLogin['data']['success']) return "Login with newly registered user failed";
    
    // Change role
    $roleRes = httpPost("$baseUrl/api/admin_users.php?action=change_role", [
        'user_id' => $createdUserId,
        'role' => 'Cashier'
    ]);
    if (!$roleRes['data']['success']) return "Change role failed";
    
    // Delete user
    $delUser = httpPost("$baseUrl/api/admin_users.php?action=delete", ['user_id' => $createdUserId]);
    if (!$delUser['data']['success']) return "Delete user failed";
    
    return true;
});

// 9. REPORTS GENERATION (JSON & CSV)
runTest("15. Reports & Analytics (api/admin_reports.php)", function() use ($baseUrl) {
    $types = ['sales', 'products', 'inventory', 'stock_in'];
    foreach ($types as $type) {
        $jsonRes = httpGet("$baseUrl/api/admin_reports.php?type=$type");
        if (!$jsonRes['data']['success'] || !isset($jsonRes['data']['records'])) {
            return "Report $type JSON failed";
        }
        
        $csvRes = httpGet("$baseUrl/api/admin_reports.php?type=$type&format=csv");
        if ($csvRes['code'] !== 200 || empty($csvRes['raw'])) {
            return "Report $type CSV export failed";
        }
    }
    return true;
});

// 10. CASHIER POS SALE TRANSACTION & INVENTORY DECREMENT
runTest("16. Cashier POS: Live Sale Processing & Inventory Synchronization", function() use ($baseUrl) {
    // 1. Check stock of product 1 (Coca-Cola 1.5L) before sale
    $prodBefore = httpGet("$baseUrl/api/admin_products.php?action=get&id=1");
    $stockBefore = (int)$prodBefore['data']['product']['stock_quantity'];
    if ($stockBefore < 2) return "Insufficient starting stock for test";
    
    // 2. Barcode scan lookup
    $barcodeRes = httpGet("$baseUrl/api/get_product_by_barcode.php?barcode=480001001");
    if (!$barcodeRes['data']['success'] || $barcodeRes['data']['product']['name'] !== "Coca-Cola 1.5L") {
        return "Barcode lookup failed for 480001001";
    }
    
    // 3. Process sale for 2 bottles of Coca-Cola 1.5L (Price: 75.00 each = 150.00 subtotal)
    $unitPrice = 75.00;
    $qty = 2;
    $subtotal = $unitPrice * $qty; // 150.00
    $tax = $subtotal * 0.12;       // 18.00
    $total = $subtotal + $tax;     // 168.00
    $cashTendered = 200.00;
    $change = $cashTendered - $total; // 32.00
    
    $salePayload = [
        'cashier_id' => 2, // Liziel Marie Jenizan
        'cashier_name' => 'Liziel Marie Jenizan',
        'subtotal_amount' => $subtotal,
        'discount_amount' => 0.00,
        'tax_amount' => $tax,
        'total_amount' => $total,
        'payment_method' => 'cash',
        'cash_received' => $cashTendered,
        'change_amount' => $change,
        'items' => [
            [
                'id' => 1,
                'qty' => $qty,
                'price' => $unitPrice
            ]
        ]
    ];
    
    $saleRes = httpPost("$baseUrl/api/process_sale.php", $salePayload);
    if (!$saleRes['data']['success']) return "Sale failed: " . ($saleRes['data']['message'] ?? $saleRes['raw']);
    
    $saleId = $saleRes['data']['sale_id'];
    $receipt = $saleRes['data']['receipt'];
    if (!isset($receipt['order_no']) || empty($receipt['order_no'])) return "Receipt order_no missing";
    if (count($receipt['items']) !== 1) return "Receipt items count mismatch";
    if ((float)$receipt['total_amount'] !== $total) return "Receipt total mismatch";
    
    // 4. Verify inventory was decremented in database
    $prodAfter = httpGet("$baseUrl/api/admin_products.php?action=get&id=1");
    $stockAfter = (int)$prodAfter['data']['product']['stock_quantity'];
    if ($stockAfter !== ($stockBefore - $qty)) {
        return "Stock not decremented accurately: before=$stockBefore, after=$stockAfter, expected=" . ($stockBefore - $qty);
    }
    
    // 5. Verify the new sale appears in the sales ledger
    $verifySale = httpGet("$baseUrl/api/admin_sales.php?action=details&id=$saleId");
    if (!$verifySale['data']['success']) return "New sale #$saleId not found in sales ledger";
    
    return true;
});

// 11. INSUFFICIENT STOCK TRANSACTION ROLLBACK TEST
runTest("17. Transaction Rollback on Over-order / Insufficient Stock", function() use ($baseUrl) {
    // Attempt to order 99999 units of product 1
    $salePayload = [
        'cashier_id' => 2,
        'cashier_name' => 'Liziel Marie Jenizan',
        'subtotal_amount' => 99999 * 75,
        'discount_amount' => 0.00,
        'tax_amount' => 0.00,
        'total_amount' => 99999 * 75,
        'payment_method' => 'cash',
        'cash_received' => 9999999,
        'change_amount' => 0,
        'items' => [
            ['id' => 1, 'qty' => 99999, 'price' => 75.00]
        ]
    ];
    $res = httpPost("$baseUrl/api/process_sale.php", $salePayload);
    if ($res['data']['success'] === true) return "Expected transaction rollback for insufficient stock, but transaction succeeded";
    if (strpos($res['data']['message'], 'Insufficient stock') === false) return "Unexpected message: " . $res['data']['message'];
    return true;
});

// 12. POS CATALOG ENDPOINT TEST
runTest("18. POS Catalog API (api/get_products.php)", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/api/get_products.php");
    if (!$res['data']['success']) return "get_products.php failed";
    if (!is_array($res['data']['products']) || count($res['data']['products']) < 10) return "Expected products list for POS";
    return true;
});

// 13. AUTHENTICATION & BCRYPT SESSION VERIFICATION
$sessionToken = null;
runTest("19. User Authentication & Bcrypt Session Token (api/login.php)", function() use ($baseUrl, &$sessionToken) {
    // 1. Attempt login with valid cashier credentials (liziel_cashier / 12345)
    $loginRes = httpPost("$baseUrl/api/login.php", [
        'username' => 'liziel_cashier',
        'password' => '12345'
    ]);
    if (!$loginRes['data']['success']) return "Login failed: " . ($loginRes['data']['message'] ?? 'Unknown error');
    $token = $loginRes['data']['token'] ?? null;
    if (empty($token)) return "Missing session token in login response";
    $sessionToken = $token;

    // 2. Verify session via check action
    $checkRes = httpGet("$baseUrl/api/login.php?action=check&token=" . urlencode($token));
    if (!$checkRes['data']['success']) return "Session check failed: " . ($checkRes['data']['message'] ?? '');
    if ($checkRes['data']['user']['username'] !== 'liziel_cashier') return "Session returned wrong user";

    // 3. Verify invalid credentials fail gracefully
    $badRes = httpPost("$baseUrl/api/login.php", [
        'username' => 'liziel_cashier',
        'password' => 'wrong_password_999'
    ]);
    if ($badRes['data']['success'] === true) return "Invalid password should fail";

    return true;
});

// 14. CUSTOMER MANAGEMENT CRUD
$testCustomerId = null;
runTest("20. Customer Management CRUD (api/admin_customers.php)", function() use ($baseUrl, &$testCustomerId) {
    // 1. Create a customer
    $custName = 'Test Customer ' . time();
    $createRes = httpPost("$baseUrl/api/admin_customers.php?action=create", [
        'customer_name' => $custName,
        'phone' => '0917-1234567',
        'email' => 'testcust' . time() . '@example.com',
        'address' => '123 Green Ave, Quezon City'
    ]);
    if (!$createRes['data']['success']) return "Customer creation failed: " . ($createRes['data']['message'] ?? '');
    $testCustomerId = $createRes['data']['customer_id'];

    // 2. Retrieve customer details
    $getRes = httpGet("$baseUrl/api/admin_customers.php?action=get&id=$testCustomerId");
    if (!$getRes['data']['success']) return "Customer get failed";
    if ($getRes['data']['customer']['customer_name'] !== $custName) return "Customer name mismatch";

    // 3. Update customer details
    $updateRes = httpPost("$baseUrl/api/admin_customers.php?action=update", [
        'customer_id' => $testCustomerId,
        'customer_name' => 'Updated Customer ' . time(),
        'phone' => '0918-9876543',
        'email' => 'updated' . time() . '@example.com',
        'address' => '456 Modern Blvd, Makati City',
        'status' => 'active'
    ]);
    if (!$updateRes['data']['success']) return "Customer update failed: " . ($updateRes['data']['message'] ?? '');

    // 4. List customers with search
    $listRes = httpGet("$baseUrl/api/admin_customers.php?action=list&search=Updated");
    if (!$listRes['data']['success'] || count($listRes['data']['customers']) === 0) return "Customer list search failed";

    return true;
});

// 15. STOCK ADJUSTMENTS & RECOUNT REASONING
runTest("21. Stock Adjustments & Transaction Integrity (api/admin_stock_adjustments.php)", function() use ($baseUrl) {
    // Check stock for product 2 (Gardenia Classic White Bread)
    $prodBefore = httpGet("$baseUrl/api/admin_products.php?action=get&id=2");
    $stockBefore = (int)$prodBefore['data']['product']['stock_quantity'];

    // Add 10 units via stock adjustment ('in')
    $adjRes = httpPost("$baseUrl/api/admin_stock_adjustments.php?action=create", [
        'product_id' => 2,
        'adjustment_type' => 'in',
        'quantity' => 10,
        'reason' => 'Inventory Count Correction',
        'notes' => 'Automated test stock adjustment verification',
        'user_id' => 2
    ]);
    if (!$adjRes['data']['success']) return "Stock adjustment failed: " . ($adjRes['data']['message'] ?? '');

    // Verify stock is now stockBefore + 10
    $prodAfter = httpGet("$baseUrl/api/admin_products.php?action=get&id=2");
    $stockAfter = (int)$prodAfter['data']['product']['stock_quantity'];
    if ($stockAfter !== ($stockBefore + 10)) {
        return "Stock not updated correctly: expected " . ($stockBefore + 10) . ", got $stockAfter";
    }

    // Verify stock adjustment history
    $histRes = httpGet("$baseUrl/api/admin_stock_adjustments.php?action=list&product_id=2");
    if (!$histRes['data']['success'] || count($histRes['data']['adjustments']) === 0) return "Stock adjustment log missing";

    // Deduct the 10 units back via 'out' to restore original state
    $restoreRes = httpPost("$baseUrl/api/admin_stock_adjustments.php?action=create", [
        'product_id' => 2,
        'adjustment_type' => 'out',
        'quantity' => 10,
        'reason' => 'Test teardown restoration',
        'notes' => 'Restoring balance after test',
        'user_id' => 2
    ]);
    if (!$restoreRes['data']['success']) return "Teardown adjustment failed";

    return true;
});

// 16. SALE PROCESSING WITH SPECIFIC CUSTOMER
$saleWithCustomerRecordId = null;
runTest("22. POS Sale with Customer Linkage & Payment Breakdown (api/process_sale.php)", function() use ($baseUrl, &$testCustomerId, &$saleWithCustomerRecordId) {
    $salePayload = [
        'cashier_id' => 2,
        'cashier_name' => 'Liziel Marie Jenizan',
        'customer_id' => $testCustomerId ?: 1,
        'subtotal_amount' => 100.00,
        'discount_amount' => 10.00,
        'tax_amount' => 10.80,
        'total_amount' => 100.80,
        'payment_method' => 'gcash',
        'cash_received' => 100.80,
        'change_amount' => 0.00,
        'items' => [
            [
                'id' => 3, // Magnolia Fresh Milk 1L
                'qty' => 1,
                'price' => 100.00
            ]
        ]
    ];

    $res = httpPost("$baseUrl/api/process_sale.php", $salePayload);
    if (!$res['data']['success']) return "Sale with customer failed: " . ($res['data']['message'] ?? '');
    $saleWithCustomerRecordId = $res['data']['sale_id'];

    $receipt = $res['data']['receipt'];
    if ((int)$receipt['customer_id'] !== ($testCustomerId ?: 1)) return "Receipt customer_id mismatch";
    if ($receipt['payment_method'] !== 'gcash') return "Receipt payment_method mismatch";

    return true;
});

// 17. VOID SALE & AUTOMATED STOCK RESTORATION
runTest("23. Void Sale Transaction & Automatic Stock Restoration (api/admin_sales.php?action=void)", function() use ($baseUrl, &$saleWithCustomerRecordId) {
    if (!$saleWithCustomerRecordId) return "No sale ID available for void test";

    // 1. Get stock of product 3 before void
    $prodBefore = httpGet("$baseUrl/api/admin_products.php?action=get&id=3");
    $stockBefore = (int)$prodBefore['data']['product']['stock_quantity'];

    // 2. Void the sale
    $voidRes = httpPost("$baseUrl/api/admin_sales.php?action=void", [
        'sale_id' => $saleWithCustomerRecordId,
        'reason' => 'Customer changed mind (Integration Test)'
    ]);
    if (!$voidRes['data']['success']) return "Void sale failed: " . ($voidRes['data']['message'] ?? '');

    // 3. Verify stock was restored (+1)
    $prodAfter = httpGet("$baseUrl/api/admin_products.php?action=get&id=3");
    $stockAfter = (int)$prodAfter['data']['product']['stock_quantity'];
    if ($stockAfter !== ($stockBefore + 1)) {
        return "Stock not restored after void: before=$stockBefore, after=$stockAfter, expected=" . ($stockBefore + 1);
    }

    // 4. Verify sale status is now 'voided'
    $saleRes = httpGet("$baseUrl/api/admin_sales.php?action=details&id=$saleWithCustomerRecordId");
    if ($saleRes['data']['sale']['status'] !== 'voided') return "Sale status is not 'voided': " . ($saleRes['data']['sale']['status'] ?? 'null');

    return true;
});

// 18. ENHANCED REPORTING SUITE & CSV EXPORT
runTest("24. Enhanced Reporting Suite & CSV Exports (api/admin_reports.php)", function() use ($baseUrl) {
    $reports = ['sales', 'products', 'inventory', 'stock_in', 'best_selling', 'cashier_performance', 'expiration'];
    foreach ($reports as $rep) {
        $res = httpGet("$baseUrl/api/admin_reports.php?report=$rep");
        if (!$res['data']['success']) return "Report '$rep' failed: " . ($res['data']['message'] ?? '');
    }

    // Test CSV export for sales report
    $csvRes = httpGet("$baseUrl/api/admin_reports.php?report=sales&export=csv");
    if ($csvRes['code'] !== 200) return "CSV export returned HTTP code {$csvRes['code']}";
    if (strpos($csvRes['raw'], 'Sale ID') === false && strpos($csvRes['raw'], 'Order') === false && strpos($csvRes['raw'], 'Date') === false) {
        return "CSV export missing header keywords";
    }

    return true;
});

// 19. ENHANCED DASHBOARD ANALYTICS
runTest("25. Real-Time Analytics & Top Trends (api/admin_stats.php)", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/api/admin_stats.php");
    $data = $res['data'];
    if (!isset($data['summary']['total_inventory_units']) || $data['summary']['total_inventory_units'] <= 0) return "Missing total_inventory_units";
    if (!isset($data['summary']['month_revenue'])) return "Missing month_revenue";
    if (!isset($data['best_selling_products']) || !is_array($data['best_selling_products'])) return "Missing best_selling_products";
    if (!isset($data['top_categories']) || !is_array($data['top_categories'])) return "Missing top_categories";

    return true;
});

// 20. AUDIT TRAIL LOGGING
runTest("26. Audit Trail Logging (api/admin_audit.php?action=list)", function() use ($baseUrl) {
    $res = httpGet("$baseUrl/api/admin_audit.php?action=list");
    if (!$res['data']['success']) return "Audit trail list failed: " . ($res['data']['message'] ?? '');
    if (!is_array($res['data']['logs']) || count($res['data']['logs']) === 0) return "Expected audit trail records";

    return true;
});

// Teardown: delete test customer
if ($testCustomerId) {
    httpPost("$baseUrl/api/admin_customers.php?action=delete", ['customer_id' => $testCustomerId]);
}

echo "\n=========================================================\n";
echo "TEST RESULTS SUMMARY: $passCount PASSED, $failCount FAILED\n";
echo "=========================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
