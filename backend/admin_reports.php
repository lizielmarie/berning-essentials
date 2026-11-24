<?php
/**
 * BERNING STORE — API: COMPREHENSIVE REPORTS (JSON & CSV EXPORTS)
 * Supported report types:
 * - sales: Transaction sales ledger with cashier and customer info
 * - products: Product sales summary
 * - inventory: Stock levels, status, valuation
 * - stock_in: Supplier delivery receipts
 * - best_selling: Top performing products by volume & revenue
 * - cashier_performance: Staff checkout performance and metrics
 * - expiration: Products near expiry or expired
 */
require_once __DIR__ . '/db.php';

$type      = $_GET['type'] ?? ($_GET['report'] ?? 'sales');
$startDate = trim($_GET['start_date'] ?? '');
$endDate   = trim($_GET['end_date'] ?? '');
$format    = strtolower(trim($_GET['format'] ?? ($_GET['export'] ?? 'json')));

try {
    switch ($type) {
        // ==========================================
        // 1. SALES REPORT
        // ==========================================
        case 'sales':
            $sql = "
                SELECT 
                    s.sale_id,
                    s.sale_date,
                    s.total_amount,
                    s.subtotal_amount,
                    s.discount_amount,
                    s.payment_method,
                    s.cashier_id,
                    COALESCE(u.full_name, 'Cashier') AS cashier_name,
                    u.role AS cashier_role,
                    COALESCE(c.customer_name, 'Walk-in Customer') AS customer_name,
                    COUNT(si.sale_item_id) AS items_count,
                    COALESCE(SUM(si.quantity), 0) AS total_units
                FROM sales s
                LEFT JOIN users u ON s.cashier_id = u.user_id
                LEFT JOIN customers c ON s.customer_id = c.customer_id
                LEFT JOIN sale_items si ON s.sale_id = si.sale_id
                WHERE s.status = 'completed'
            ";

            $params = [];
            if ($startDate !== '') {
                $sql .= " AND DATE(s.sale_date) >= :start_date";
                $params[':start_date'] = $startDate;
            }
            if ($endDate !== '') {
                $sql .= " AND DATE(s.sale_date) <= :end_date";
                $params[':end_date'] = $endDate;
            }

            $sql .= " GROUP BY s.sale_id, s.sale_date, s.total_amount, s.subtotal_amount, s.discount_amount, s.payment_method, s.cashier_id, u.full_name, u.role, c.customer_name
                      ORDER BY s.sale_date DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $totalRevenue = 0.0;
            $totalUnits = 0;
            $salesCount = count($rows);

            $data = array_map(function($r) use (&$totalRevenue, &$totalUnits) {
                $amt = (float)$r['total_amount'];
                $u = (int)$r['total_units'];
                $totalRevenue += $amt;
                $totalUnits += $u;
                return [
                    'sale_id'         => (int)$r['sale_id'],
                    'sale_date'       => $r['sale_date'],
                    'cashier_name'    => $r['cashier_name'],
                    'cashier_role'    => $r['cashier_role'] ?? 'Cashier',
                    'customer_name'   => $r['customer_name'],
                    'payment_method'  => strtoupper($r['payment_method'] ?? 'CASH'),
                    'items_count'     => (int)$r['items_count'],
                    'total_units'     => $u,
                    'subtotal_amount' => (float)$r['subtotal_amount'],
                    'discount_amount' => (float)$r['discount_amount'],
                    'total_amount'    => $amt
                ];
            }, $rows);

            $avgOrder = $salesCount > 0 ? ($totalRevenue / $salesCount) : 0.0;

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=berning_sales_report.csv');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Sale ID', 'Sale Date', 'Cashier', 'Customer', 'Payment Method', 'Line Items', 'Units Sold', 'Subtotal (PHP)', 'Discount (PHP)', 'Total (PHP)']);
                foreach ($data as $d) {
                    fputcsv($out, [$d['sale_id'], $d['sale_date'], $d['cashier_name'], $d['customer_name'], $d['payment_method'], $d['items_count'], $d['total_units'], number_format($d['subtotal_amount'], 2, '.', ''), number_format($d['discount_amount'], 2, '.', ''), number_format($d['total_amount'], 2, '.', '')]);
                }
                fclose($out);
                exit;
            }

            echo json_encode([
                'success' => true,
                'type'    => 'sales',
                'summary' => [
                    'total_sales_count' => $salesCount,
                    'total_revenue'     => $totalRevenue,
                    'average_order'     => round($avgOrder, 2),
                    'total_units_sold'  => $totalUnits
                ],
                'records' => $data
            ]);
            break;

        // ==========================================
        // 2. PRODUCT SALES REPORT
        // ==========================================
        case 'products':
            $sql = "
                SELECT 
                    p.product_id,
                    p.barcode,
                    p.product_name,
                    c.category_name,
                    p.selling_price,
                    COALESCE(SUM(si.quantity), 0) AS units_sold,
                    COALESCE(SUM(si.subtotal), 0) AS total_revenue,
                    COUNT(DISTINCT si.sale_id) AS orders_count
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN sale_items si ON p.product_id = si.product_id
                LEFT JOIN sales s ON si.sale_id = s.sale_id AND s.status = 'completed'
                WHERE 1=1
            ";

            $params = [];
            if ($startDate !== '') {
                $sql .= " AND (s.sale_date IS NULL OR DATE(s.sale_date) >= :start_date)";
                $params[':start_date'] = $startDate;
            }
            if ($endDate !== '') {
                $sql .= " AND (s.sale_date IS NULL OR DATE(s.sale_date) <= :end_date)";
                $params[':end_date'] = $endDate;
            }

            $sql .= " GROUP BY p.product_id, p.barcode, p.product_name, c.category_name, p.selling_price
                      ORDER BY units_sold DESC, total_revenue DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $totalSold = 0;
            $totalSalesRev = 0.0;
            $data = array_map(function($r) use (&$totalSold, &$totalSalesRev) {
                $sold = (int)$r['units_sold'];
                $rev  = (float)$r['total_revenue'];
                $totalSold += $sold;
                $totalSalesRev += $rev;
                return [
                    'product_id'    => (int)$r['product_id'],
                    'barcode'       => $r['barcode'],
                    'product_name'  => $r['product_name'],
                    'category_name' => $r['category_name'] ?? 'General',
                    'selling_price' => (float)$r['selling_price'],
                    'units_sold'    => $sold,
                    'total_revenue' => $rev,
                    'orders_count'  => (int)$r['orders_count']
                ];
            }, $rows);

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=berning_product_sales_report.csv');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Product ID', 'Barcode', 'Product Name', 'Category', 'Price (PHP)', 'Units Sold', 'Orders Count', 'Total Revenue (PHP)']);
                foreach ($data as $d) {
                    fputcsv($out, [$d['product_id'], $d['barcode'], $d['product_name'], $d['category_name'], $d['selling_price'], $d['units_sold'], $d['orders_count'], number_format($d['total_revenue'], 2, '.', '')]);
                }
                fclose($out);
                exit;
            }

            echo json_encode([
                'success' => true,
                'type'    => 'products',
                'summary' => [
                    'total_products'      => count($data),
                    'total_units_sold'    => $totalSold,
                    'total_sales_revenue' => $totalSalesRev
                ],
                'records' => $data
            ]);
            break;

        // ==========================================
        // 3. INVENTORY REPORT
        // ==========================================
        case 'inventory':
            $sql = "
                SELECT 
                    p.product_id,
                    p.barcode,
                    p.product_name,
                    c.category_name,
                    p.selling_price,
                    p.stock_quantity,
                    p.reorder_level,
                    p.expiry_date,
                    (p.stock_quantity * p.selling_price) AS valuation,
                    CASE 
                        WHEN p.stock_quantity = 0 THEN 'Out of Stock'
                        WHEN p.stock_quantity <= p.reorder_level THEN 'Low Stock'
                        ELSE 'In Stock'
                    END AS stock_status,
                    CASE 
                        WHEN p.expiry_date IS NOT NULL AND p.expiry_date < CURDATE() THEN 'Expired'
                        WHEN p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 'Expiring Soon'
                        ELSE 'Good'
                    END AS expiry_status
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                ORDER BY p.stock_quantity ASC, p.product_name ASC
            ";

            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();

            $totalUnits = 0;
            $totalValuation = 0.0;
            $lowStockCount = 0;
            $outOfStockCount = 0;

            $data = array_map(function($r) use (&$totalUnits, &$totalValuation, &$lowStockCount, &$outOfStockCount) {
                $qty = (int)$r['stock_quantity'];
                $val = (float)$r['valuation'];
                $totalUnits += $qty;
                $totalValuation += $val;
                if ($r['stock_status'] === 'Low Stock') $lowStockCount++;
                if ($r['stock_status'] === 'Out of Stock') $outOfStockCount++;

                return [
                    'product_id'     => (int)$r['product_id'],
                    'barcode'        => $r['barcode'],
                    'product_name'   => $r['product_name'],
                    'category_name'  => $r['category_name'] ?? 'General',
                    'selling_price'  => (float)$r['selling_price'],
                    'stock_quantity' => $qty,
                    'reorder_level'  => (int)$r['reorder_level'],
                    'expiry_date'    => $r['expiry_date'],
                    'valuation'      => $val,
                    'stock_status'   => $r['stock_status'],
                    'expiry_status'  => $r['expiry_status']
                ];
            }, $rows);

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=berning_inventory_report.csv');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Product ID', 'Barcode', 'Product Name', 'Category', 'Selling Price', 'Stock Qty', 'Reorder Level', 'Stock Status', 'Expiry Date', 'Valuation (PHP)']);
                foreach ($data as $d) {
                    fputcsv($out, [$d['product_id'], $d['barcode'], $d['product_name'], $d['category_name'], $d['selling_price'], $d['stock_quantity'], $d['reorder_level'], $d['stock_status'], $d['expiry_date'] ?? 'N/A', number_format($d['valuation'], 2, '.', '')]);
                }
                fclose($out);
                exit;
            }

            echo json_encode([
                'success' => true,
                'type'    => 'inventory',
                'summary' => [
                    'total_skus'          => count($data),
                    'total_units_on_hand' => $totalUnits,
                    'total_valuation'     => $totalValuation,
                    'low_stock_count'     => $lowStockCount,
                    'out_of_stock_count'  => $outOfStockCount
                ],
                'records' => $data
            ]);
            break;

        // ==========================================
        // 4. STOCK-IN REPORT
        // ==========================================
        case 'stock_in':
            $sql = "
                SELECT 
                    si.stockin_id,
                    si.date_received,
                    si.quantity,
                    p.product_id,
                    p.product_name,
                    p.barcode,
                    c.category_name,
                    sup.supplier_id,
                    sup.supplier_name,
                    u.full_name AS received_by_name
                FROM stock_in si
                LEFT JOIN products p ON si.product_id = p.product_id
                LEFT JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN suppliers sup ON si.supplier_id = sup.supplier_id
                LEFT JOIN users u ON si.received_by = u.user_id
                WHERE 1=1
            ";

            $params = [];
            if ($startDate !== '') {
                $sql .= " AND si.date_received >= :start_date";
                $params[':start_date'] = $startDate;
            }
            if ($endDate !== '') {
                $sql .= " AND si.date_received <= :end_date";
                $params[':end_date'] = $endDate;
            }

            $sql .= " ORDER BY si.date_received DESC, si.stockin_id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $totalQtyReceived = 0;
            $data = array_map(function($r) use (&$totalQtyReceived) {
                $q = (int)$r['quantity'];
                $totalQtyReceived += $q;
                return [
                    'stockin_id'       => (int)$r['stockin_id'],
                    'date_received'    => $r['date_received'],
                    'quantity'         => $q,
                    'product_id'       => (int)$r['product_id'],
                    'product_name'     => $r['product_name'] ?? 'Unknown',
                    'barcode'          => $r['barcode'] ?? '',
                    'category_name'    => $r['category_name'] ?? 'General',
                    'supplier_name'    => $r['supplier_name'] ?? 'Unknown',
                    'received_by_name' => $r['received_by_name'] ?? 'Staff'
                ];
            }, $rows);

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=berning_stock_in_report.csv');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Stock-In ID', 'Date Received', 'Product', 'Barcode', 'Category', 'Supplier', 'Quantity Received', 'Received By']);
                foreach ($data as $d) {
                    fputcsv($out, [$d['stockin_id'], $d['date_received'], $d['product_name'], $d['barcode'], $d['category_name'], $d['supplier_name'], $d['quantity'], $d['received_by_name']]);
                }
                fclose($out);
                exit;
            }

            echo json_encode([
                'success' => true,
                'type'    => 'stock_in',
                'summary' => [
                    'total_shipments'    => count($data),
                    'total_qty_received' => $totalQtyReceived
                ],
                'records' => $data
            ]);
            break;

        // ==========================================
        // 5. BEST SELLING PRODUCTS REPORT
        // ==========================================
        case 'best_selling':
            $sql = "
                SELECT 
                    p.product_id,
                    p.barcode,
                    p.product_name,
                    c.category_name,
                    p.selling_price,
                    COALESCE(SUM(si.quantity), 0) AS total_units_sold,
                    COALESCE(SUM(si.subtotal), 0) AS total_revenue_generated,
                    COUNT(DISTINCT si.sale_id) AS distinct_transactions
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                JOIN sale_items si ON p.product_id = si.product_id
                JOIN sales s ON si.sale_id = s.sale_id AND s.status = 'completed'
                WHERE 1=1
            ";

            $params = [];
            if ($startDate !== '') {
                $sql .= " AND DATE(s.sale_date) >= :start_date";
                $params[':start_date'] = $startDate;
            }
            if ($endDate !== '') {
                $sql .= " AND DATE(s.sale_date) <= :end_date";
                $params[':end_date'] = $endDate;
            }

            $sql .= " GROUP BY p.product_id, p.barcode, p.product_name, c.category_name, p.selling_price
                      ORDER BY total_units_sold DESC, total_revenue_generated DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $totalUnits = 0;
            $totalRev = 0.0;
            $data = array_map(function($r, $idx) use (&$totalUnits, &$totalRev) {
                $u = (int)$r['total_units_sold'];
                $rev = (float)$r['total_revenue_generated'];
                $totalUnits += $u;
                $totalRev += $rev;
                return [
                    'rank'               => $idx + 1,
                    'product_id'         => (int)$r['product_id'],
                    'barcode'            => $r['barcode'],
                    'product_name'       => $r['product_name'],
                    'category_name'      => $r['category_name'] ?? 'General',
                    'selling_price'      => (float)$r['selling_price'],
                    'total_units_sold'   => $u,
                    'total_revenue'      => $rev,
                    'transactions_count' => (int)$r['distinct_transactions']
                ];
            }, $rows, array_keys($rows));

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=berning_best_selling_report.csv');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Rank', 'Barcode', 'Product Name', 'Category', 'Price (PHP)', 'Units Sold', 'Transactions', 'Revenue (PHP)']);
                foreach ($data as $d) {
                    fputcsv($out, [$d['rank'], $d['barcode'], $d['product_name'], $d['category_name'], $d['selling_price'], $d['total_units_sold'], $d['transactions_count'], number_format($d['total_revenue'], 2, '.', '')]);
                }
                fclose($out);
                exit;
            }

            echo json_encode([
                'success' => true,
                'type'    => 'best_selling',
                'summary' => [
                    'total_ranked_products' => count($data),
                    'total_units_sold'      => $totalUnits,
                    'total_revenue'         => $totalRev
                ],
                'records' => $data
            ]);
            break;

        // ==========================================
        // 6. CASHIER PERFORMANCE REPORT
        // ==========================================
        case 'cashier_performance':
            $sql = "
                SELECT 
                    u.user_id,
                    u.full_name,
                    u.username,
                    u.role,
                    COUNT(DISTINCT s.sale_id) AS total_transactions,
                    COALESCE(SUM(si.quantity), 0) AS total_units_sold,
                    COALESCE(SUM(s.total_amount), 0) AS total_revenue
                FROM users u
                LEFT JOIN sales s ON u.user_id = s.cashier_id AND s.status = 'completed'
            ";

            $params = [];
            if ($startDate !== '') {
                $sql .= " AND DATE(s.sale_date) >= :start_date";
                $params[':start_date'] = $startDate;
            }
            if ($endDate !== '') {
                $sql .= " AND DATE(s.sale_date) <= :end_date";
                $params[':end_date'] = $endDate;
            }

            $sql .= " LEFT JOIN sale_items si ON s.sale_id = si.sale_id
                      GROUP BY u.user_id, u.full_name, u.username, u.role
                      ORDER BY total_revenue DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $data = array_map(function($r) {
                $tx = (int)$r['total_transactions'];
                $rev = (float)$r['total_revenue'];
                $avg = $tx > 0 ? ($rev / $tx) : 0.0;
                return [
                    'user_id'            => (int)$r['user_id'],
                    'full_name'          => $r['full_name'],
                    'username'           => $r['username'],
                    'role'               => $r['role'],
                    'total_transactions' => $tx,
                    'total_units_sold'   => (int)$r['total_units_sold'],
                    'total_revenue'      => $rev,
                    'average_sale_value' => round($avg, 2)
                ];
            }, $rows);

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=berning_cashier_performance.csv');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['User ID', 'Staff Name', 'Username', 'Role', 'Transactions', 'Units Sold', 'Total Revenue (PHP)', 'Avg Sale (PHP)']);
                foreach ($data as $d) {
                    fputcsv($out, [$d['user_id'], $d['full_name'], $d['username'], $d['role'], $d['total_transactions'], $d['total_units_sold'], number_format($d['total_revenue'], 2, '.', ''), number_format($d['average_sale_value'], 2, '.', '')]);
                }
                fclose($out);
                exit;
            }

            echo json_encode([
                'success' => true,
                'type'    => 'cashier_performance',
                'summary' => [
                    'total_staff' => count($data)
                ],
                'records' => $data
            ]);
            break;

        // ==========================================
        // 7. EXPIRATION MONITORING REPORT
        // ==========================================
        case 'expiration':
            $sql = "
                SELECT 
                    p.product_id,
                    p.barcode,
                    p.product_name,
                    c.category_name,
                    p.selling_price,
                    p.stock_quantity,
                    p.expiry_date,
                    DATEDIFF(p.expiry_date, CURDATE()) AS days_left,
                    (p.stock_quantity * p.selling_price) AS valuation_at_risk,
                    CASE 
                        WHEN p.expiry_date < CURDATE() THEN 'Expired'
                        WHEN p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Critical (<= 30 Days)'
                        WHEN p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 'Warning (<= 60 Days)'
                        WHEN p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 'Attention (<= 90 Days)'
                        ELSE 'Safe'
                    END AS alert_level
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                WHERE p.expiry_date IS NOT NULL
                ORDER BY p.expiry_date ASC, p.stock_quantity DESC
            ";

            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();

            $totalAtRisk = 0.0;
            $expiredUnits = 0;
            $data = array_map(function($r) use (&$totalAtRisk, &$expiredUnits) {
                $days = (int)$r['days_left'];
                $val = (float)$r['valuation_at_risk'];
                if ($days <= 60) {
                    $totalAtRisk += $val;
                }
                if ($days < 0) {
                    $expiredUnits += (int)$r['stock_quantity'];
                }
                return [
                    'product_id'        => (int)$r['product_id'],
                    'barcode'           => $r['barcode'],
                    'product_name'      => $r['product_name'],
                    'category_name'     => $r['category_name'] ?? 'General',
                    'selling_price'     => (float)$r['selling_price'],
                    'stock_quantity'    => (int)$r['stock_quantity'],
                    'expiry_date'       => $r['expiry_date'],
                    'days_left'         => $days,
                    'valuation_at_risk' => $val,
                    'alert_level'       => $r['alert_level']
                ];
            }, $rows);

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=berning_expiration_report.csv');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Product ID', 'Barcode', 'Product Name', 'Category', 'Price (PHP)', 'Stock Qty', 'Expiry Date', 'Days Left', 'Valuation at Risk (PHP)', 'Alert Level']);
                foreach ($data as $d) {
                    fputcsv($out, [$d['product_id'], $d['barcode'], $d['product_name'], $d['category_name'], $d['selling_price'], $d['stock_quantity'], $d['expiry_date'], $d['days_left'], number_format($d['valuation_at_risk'], 2, '.', ''), $d['alert_level']]);
                }
                fclose($out);
                exit;
            }

            echo json_encode([
                'success' => true,
                'type'    => 'expiration',
                'summary' => [
                    'total_tracked_products' => count($data),
                    'total_valuation_at_risk'=> $totalAtRisk,
                    'expired_stock_units'    => $expiredUnits
                ],
                'records' => $data
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid report type requested.']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Reports API error: ' . $e->getMessage()
    ]);
}
