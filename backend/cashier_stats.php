<?php
/**
 * BERNING STORE — API: CASHIER DASHBOARD STATS & REAL-TIME DATA
 * Source of truth: MySQL database `berning_store`
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

try {
    // Validate session
    $sessionUser = bs_validate_session($pdo);
    $cashierId = $sessionUser ? (int)$sessionUser['user_id'] : 0;

    // 1. Today's Overall Store Sales & Count
    $todayStmt = $pdo->query("
        SELECT 
            COUNT(*) AS tx_count,
            COALESCE(SUM(total_amount), 0) AS total_revenue
        FROM sales 
        WHERE DATE(sale_date) = CURDATE() AND status = 'completed'
    ");
    $todayData = $todayStmt->fetch(PDO::FETCH_ASSOC);
    $todayRevenue = (float)$todayData['total_revenue'];
    $todayTxCount = (int)$todayData['tx_count'];

    // 2. Today's Sales Specific to This Cashier
    $cashierTodayRevenue = 0.0;
    $cashierTodayTxCount = 0;
    if ($cashierId > 0) {
        $cStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS tx_count,
                COALESCE(SUM(total_amount), 0) AS total_revenue
            FROM sales 
            WHERE cashier_id = :cid AND DATE(sale_date) = CURDATE() AND status = 'completed'
        ");
        $cStmt->execute([':cid' => $cashierId]);
        $cData = $cStmt->fetch(PDO::FETCH_ASSOC);
        $cashierTodayRevenue = (float)$cData['total_revenue'];
        $cashierTodayTxCount = (int)$cData['tx_count'];
    }

    // 3. Product Inventory Counts
    $availCount = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM products 
        WHERE status = 'active' AND stock_quantity > 0
    ")->fetchColumn();

    $lowStockCount = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM products 
        WHERE status = 'active' AND stock_quantity <= reorder_level AND stock_quantity > 0
    ")->fetchColumn();

    $outOfStockCount = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM products 
        WHERE status = 'active' AND stock_quantity = 0
    ")->fetchColumn();

    $totalProducts = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM products 
        WHERE status = 'active'
    ")->fetchColumn();

    // 4. Low-Stock Products List (Top 10)
    $lowStockStmt = $pdo->query("
        SELECT 
            p.product_id,
            p.barcode,
            p.product_name,
            c.category_name,
            p.selling_price,
            p.stock_quantity,
            p.reorder_level
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.status = 'active' AND p.stock_quantity <= p.reorder_level
        ORDER BY p.stock_quantity ASC, p.product_name ASC
        LIMIT 10
    ");
    $lowStockItems = array_map(function($r) {
        return [
            'id'            => (int)$r['product_id'],
            'barcode'       => $r['barcode'],
            'name'          => $r['product_name'],
            'category'      => $r['category_name'] ?? 'General',
            'price'         => (float)$r['selling_price'],
            'stock'         => (int)$r['stock_quantity'],
            'reorder_level' => (int)$r['reorder_level'],
            'is_out_of_stock' => ((int)$r['stock_quantity'] === 0)
        ];
    }, $lowStockStmt->fetchAll(PDO::FETCH_ASSOC));

    // 5. Recent Completed Transactions (Top 10)
    $recentStmt = $pdo->query("
        SELECT 
            s.sale_id,
            s.sale_date,
            s.total_amount,
            s.payment_method,
            s.status,
            COALESCE(u.full_name, 'Cashier') AS cashier_name,
            COALESCE(c.customer_name, 'Walk-in Customer') AS customer_name,
            COUNT(si.sale_item_id) AS items_count,
            COALESCE(SUM(si.quantity), 0) AS units_count
        FROM sales s
        LEFT JOIN users u ON s.cashier_id = u.user_id
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        LEFT JOIN sale_items si ON s.sale_id = si.sale_id
        WHERE s.status = 'completed'
        GROUP BY s.sale_id, s.sale_date, s.total_amount, s.payment_method, s.status, u.full_name, c.customer_name
        ORDER BY s.sale_date DESC, s.sale_id DESC
        LIMIT 10
    ");
    $recentTransactions = array_map(function($r) {
        return [
            'sale_id'        => (int)$r['sale_id'],
            'order_no'       => 'BS-' . str_pad($r['sale_id'], 5, '0', STR_PAD_LEFT),
            'sale_date'      => $r['sale_date'],
            'cashier_name'   => $r['cashier_name'],
            'customer_name'  => $r['customer_name'],
            'total_amount'   => (float)$r['total_amount'],
            'payment_method' => strtoupper($r['payment_method'] ?? 'CASH'),
            'status'         => $r['status'],
            'items_count'    => (int)$r['items_count'],
            'units_count'    => (int)$r['units_count']
        ];
    }, $recentStmt->fetchAll(PDO::FETCH_ASSOC));

    // 6. Return structured response
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'cashier' => $sessionUser ? [
            'user_id'   => (int)$sessionUser['user_id'],
            'full_name' => $sessionUser['full_name'],
            'username'  => $sessionUser['username'],
            'role'      => $sessionUser['role']
        ] : null,
        'stats' => [
            'today_sales'               => $todayRevenue,
            'total_revenue_today'       => $todayRevenue,
            'today_transactions'        => $todayTxCount,
            'total_transactions_today'  => $todayTxCount,
            'my_today_sales'            => $cashierTodayRevenue,
            'cashier_revenue_today'     => $cashierTodayRevenue,
            'my_today_transactions'     => $cashierTodayTxCount,
            'cashier_transactions_today'=> $cashierTodayTxCount,
            'available_products'        => $availCount,
            'low_stock_products'        => $lowStockCount,
            'low_stock_count'           => $lowStockCount,
            'out_of_stock_products'     => $outOfStockCount,
            'out_of_stock_count'        => $outOfStockCount,
            'total_products'            => $totalProducts
        ],
        'low_stock_items'     => $lowStockItems,
        'recent_transactions' => $recentTransactions,
        'recent_sales'        => $recentTransactions
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error querying cashier dashboard stats: ' . $e->getMessage()
    ]);
}
