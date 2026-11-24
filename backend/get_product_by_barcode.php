<?php
/**
 * BERNING STORE — API: REAL-TIME BARCODE LOOKUP IN DATABASE
 */
require_once __DIR__ . '/db.php';

$barcode = trim($_GET['barcode'] ?? '');

if (empty($barcode)) {
    echo json_encode([
        'success' => false,
        'message' => 'Barcode parameter is required.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            p.product_id,
            p.barcode,
            p.product_name,
            p.category_id,
            c.category_name,
            p.selling_price,
            p.stock_quantity,
            p.reorder_level,
            p.expiry_date
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.barcode = :barcode
        LIMIT 1
    ");

    $stmt->execute([':barcode' => $barcode]);
    $p = $stmt->fetch();

    if ($p) {
        echo json_encode([
            'success' => true,
            'product' => [
                'id'             => (int)$p['product_id'],
                'barcode'        => $p['barcode'],
                'name'           => $p['product_name'],
                'category_id'    => (int)$p['category_id'],
                'category_name'  => $p['category_name'] ?? 'General',
                'price'          => (float)$p['selling_price'],
                'stock'          => (int)$p['stock_quantity'],
                'reorder_level'  => (int)$p['reorder_level'],
                'expiry_date'    => $p['expiry_date']
            ]
        ]);
    } else {
        // Requirement 4: Exact error message when barcode is not in database
        echo json_encode([
            'success' => false,
            'message' => 'Product not found. This barcode is not registered in the database.'
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database Query Error: ' . $e->getMessage()
    ]);
}
