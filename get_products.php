<?php
/**
 * BERNING STORE — API: GET ALL PRODUCTS FROM DATABASE
 */
require_once __DIR__ . '/db.php';

try {
    $stmt = $pdo->query("
        SELECT 
            p.product_id,
            p.barcode,
            p.product_name,
            p.category_id,
            c.category_name,
            p.selling_price,
            p.stock_quantity,
            p.reorder_level,
            p.expiry_date,
            p.image_url,
            p.description,
            p.status
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.status = 'active' OR p.status IS NULL
        ORDER BY p.product_name ASC
    ");
    
    $products = $stmt->fetchAll();
    
    // Map to floats/ints for clean JSON response
    $formatted = array_map(function($p) {
        return [
            'id'             => (int)$p['product_id'],
            'barcode'        => $p['barcode'],
            'name'           => $p['product_name'],
            'category_id'    => (int)$p['category_id'],
            'category_name'  => $p['category_name'] ?? 'General',
            'price'          => (float)$p['selling_price'],
            'stock'          => (int)$p['stock_quantity'],
            'reorder_level'  => (int)$p['reorder_level'],
            'expiry_date'    => $p['expiry_date'],
            'image_url'      => $p['image_url'] ?? '',
            'description'    => $p['description'] ?? '',
            'status'         => $p['status'] ?? 'active'
        ];
    }, $products);

    echo json_encode([
        'success'  => true,
        'count'    => count($formatted),
        'products' => $formatted
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error querying products: ' . $e->getMessage()
    ]);
}
