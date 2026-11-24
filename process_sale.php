<?php
/**
 * BERNING STORE — API: PROCESS SALE & UPDATE INVENTORY IN DATABASE
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Read raw JSON input body
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data || empty($data['items'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid transaction data provided.'
    ]);
    exit;
}

// Determine cashier: prefer validated session, fallback to payload cashier_id, then 2
$sessionUser = bs_validate_session($pdo);
if ($sessionUser && !empty($sessionUser['user_id'])) {
    $cashierId = (int)$sessionUser['user_id'];
} elseif (!empty($data['cashier_id'])) {
    $cashierId = (int)$data['cashier_id'];
} else {
    $cashierId = 2;
}
$customerId     = !empty($data['customer_id']) ? (int)$data['customer_id'] : 1; // Default to 1 (Walk-in Customer)
$totalAmount    = (float)($data['total_amount'] ?? 0.00);
$items          = $data['items'];
$subtotalAmount = (float)($data['subtotal_amount'] ?? $totalAmount);
$discountAmount = (float)($data['discount_amount'] ?? 0.00);
$taxAmount      = (float)($data['tax_amount'] ?? 0.00);
$cashReceived   = (float)($data['cash_received'] ?? $totalAmount);
$changeAmount   = (float)($data['change_amount'] ?? 0.00);
$paymentMethod  = trim((string)($data['payment_method'] ?? 'cash'));

try {
    $pdo->beginTransaction();

    // Verify cashier exists
    $cashierStmt = $pdo->prepare("SELECT user_id, full_name FROM users WHERE user_id = :cashier_id");
    $cashierStmt->execute([':cashier_id' => $cashierId]);
    $cashierUser = $cashierStmt->fetch();
    if (!$cashierUser) {
        $cashierId = 2; // Liziel Cashier default
    }

    // Verify customer exists or fallback to 1
    $custStmt = $pdo->prepare("SELECT customer_id, customer_name FROM customers WHERE customer_id = :cid");
    $custStmt->execute([':cid' => $customerId]);
    $custRecord = $custStmt->fetch();
    if (!$custRecord) {
        $customerId = 1;
        $custStmt->execute([':cid' => 1]);
        $custRecord = $custStmt->fetch();
    }

    // 1. Check stock availability for all items with row lock (FOR UPDATE)
    foreach ($items as $item) {
        $pid = (int)$item['id'];
        $requestedQty = (int)$item['qty'];

        if ($requestedQty <= 0) {
            throw new Exception("Invalid quantity for product ID {$pid}.");
        }

        $checkStmt = $pdo->prepare("SELECT stock_quantity, product_name FROM products WHERE product_id = :id FOR UPDATE");
        $checkStmt->execute([':id' => $pid]);
        $prod = $checkStmt->fetch();

        if (!$prod) {
            throw new Exception("Product ID {$pid} no longer exists in database.");
        }

        if ($prod['stock_quantity'] < $requestedQty) {
            throw new Exception("Insufficient stock for {$prod['product_name']}. Available: {$prod['stock_quantity']}, Requested: {$requestedQty}.");
        }
    }

    // 2. Insert into `sales` table with complete breakdown
    $saleStmt = $pdo->prepare("
        INSERT INTO sales (
            cashier_id, customer_id, sale_date, total_amount, 
            payment_method, cash_received, change_amount, 
            discount_amount, tax_amount, subtotal_amount, status
        ) VALUES (
            :cashier_id, :customer_id, NOW(), :total_amount,
            :payment_method, :cash_received, :change_amount,
            :discount_amount, :tax_amount, :subtotal_amount, 'completed'
        )
    ");
    $saleStmt->execute([
        ':cashier_id'       => $cashierId,
        ':customer_id'      => $customerId,
        ':total_amount'     => $totalAmount,
        ':payment_method'   => $paymentMethod ?: 'cash',
        ':cash_received'    => $cashReceived,
        ':change_amount'    => $changeAmount,
        ':discount_amount'  => $discountAmount,
        ':tax_amount'       => $taxAmount,
        ':subtotal_amount'  => $subtotalAmount
    ]);
    $saleId = (int)$pdo->lastInsertId();

    // 3. Insert into `sale_items` table & deduct stock from `products`
    $itemStmt = $pdo->prepare("
        INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (:sale_id, :product_id, :quantity, :unit_price, :subtotal)
    ");

    $updateStockStmt = $pdo->prepare("
        UPDATE products 
        SET stock_quantity = stock_quantity - :qty 
        WHERE product_id = :product_id
    ");

    foreach ($items as $item) {
        $pid      = (int)$item['id'];
        $qty      = (int)$item['qty'];
        $price    = (float)$item['price'];
        $subtotal = $price * $qty;

        $itemStmt->execute([
            ':sale_id'    => $saleId,
            ':product_id' => $pid,
            ':quantity'   => $qty,
            ':unit_price' => $price,
            ':subtotal'   => $subtotal
        ]);

        $updateStockStmt->execute([
            ':qty'        => $qty,
            ':product_id' => $pid
        ]);
    }

    $pdo->commit();

    // Log audit
    bs_log_audit($pdo, $cashierId, 'SALE_COMPLETED', 'SALES', $saleId, "Processed sale #$saleId. Total: ₱" . number_format($totalAmount, 2) . " via $paymentMethod");

    // Fetch confirmed sale record for receipt
    $saleStmt = $pdo->prepare("
        SELECT 
            s.sale_id, s.sale_date, s.total_amount, s.payment_method, 
            s.cash_received, s.change_amount, s.discount_amount, s.tax_amount, s.subtotal_amount,
            u.full_name AS cashier_name, u.username AS cashier_username,
            c.customer_id, c.customer_name, c.phone AS customer_phone
        FROM sales s
        LEFT JOIN users u ON u.user_id = s.cashier_id
        LEFT JOIN customers c ON c.customer_id = s.customer_id
        WHERE s.sale_id = :sale_id
    ");
    $saleStmt->execute([':sale_id' => $saleId]);
    $sale = $saleStmt->fetch();

    $itemsStmt = $pdo->prepare("
        SELECT si.product_id, p.product_name, p.barcode, si.quantity, si.unit_price, si.subtotal
        FROM sale_items si
        JOIN products p ON p.product_id = si.product_id
        WHERE si.sale_id = :sale_id
        ORDER BY si.sale_item_id ASC
    ");
    $itemsStmt->execute([':sale_id' => $saleId]);
    $receiptItems = array_map(function ($row) {
        return [
            'product_id' => (int)$row['product_id'],
            'name'       => $row['product_name'],
            'barcode'    => $row['barcode'] ?? '',
            'quantity'   => (int)$row['quantity'],
            'unit_price' => (float)$row['unit_price'],
            'subtotal'   => (float)$row['subtotal']
        ];
    }, $itemsStmt->fetchAll());

    echo json_encode([
        'success'  => true,
        'sale_id'  => $saleId,
        'order_no' => 'BS-' . str_pad($saleId, 5, '0', STR_PAD_LEFT),
        'receipt'  => [
            'store_name'      => 'Berning Store',
            'store_address'   => 'Poblacion, Grocery St., Philippines',
            'store_contact'   => '+63 912 345 6789',
            'sale_id'         => $saleId,
            'order_no'        => 'BS-' . str_pad($saleId, 5, '0', STR_PAD_LEFT),
            'sale_date'       => $sale['sale_date'],
            'cashier_name'    => $sale['cashier_name'] ?: 'Cashier',
            'cashier_username'=> $sale['cashier_username'] ?: '',
            'customer_id'     => (int)($sale['customer_id'] ?? 1),
            'customer_name'   => $sale['customer_name'] ?: 'Walk-in Customer',
            'customer_phone'  => $sale['customer_phone'] ?? '',
            'subtotal'        => (float)$sale['subtotal_amount'],
            'discount'        => (float)$sale['discount_amount'],
            'tax'             => (float)$sale['tax_amount'],
            'total_amount'    => (float)$sale['total_amount'],
            'payment_method'  => strtolower($sale['payment_method'] ?? 'cash'),
            'cash_received'   => (float)$sale['cash_received'],
            'change_amount'   => (float)$sale['change_amount'],
            'items'           => $receiptItems
        ],
        'message'  => 'Transaction completed and database inventory updated successfully.'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
