<?php
/**
 * BERNING STORE — API: ADMIN DASHBOARD STATS & ANALYTICS
 * Source of truth: MySQL database `berning_store`
 */
require_once __DIR__ . '/db.php';

try {
    // 1. Total Products
    $totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();

    // 2. Total Categories
    $totalCategories = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();

    // 3. Total Inventory Units
    $totalInventoryUnits = (int)$pdo->query("SELECT COALESCE(SUM(stock_quantity), 0) FROM products")->fetchColumn();

    // 4. Low Stock Products (stock_quantity <= reorder_level AND stock_quantity > 0)
    $lowStockProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= reorder_level AND stock_quantity > 0")->fetchColumn();

    // 5. Out of Stock Products (stock_quantity = 0)
    $outOfStockProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity = 0")->fetchColumn();

    // 6. Expiring Products (within 60 days or already expired)
    $expiringProductsCount = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM products 
        WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ")->fetchColumn();

    // 7. Total Suppliers
    $totalSuppliers = (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'active'")->fetchColumn();

    // 8. Total Customers
    $totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'active'")->fetchColumn();

    // 9. Total Users / Staff
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();

    // 9b. Total Cashiers
    $totalCashiers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Cashier' AND status = 'active'")->fetchColumn();

    // 10. All-time Sales count & revenue (completed only)
    $salesSumStmt = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue FROM sales WHERE status = 'completed'");
    $salesSum = $salesSumStmt->fetch();
    $totalSalesCount = (int)$salesSum['count'];
    $totalRevenue = (float)$salesSum['revenue'];

    // 11. Today's Sales count & revenue
    $todayStmt = $pdo->query("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue 
        FROM sales 
        WHERE DATE(sale_date) = CURDATE() AND status = 'completed'
    ");
    $todaySum = $todayStmt->fetch();
    $todaySalesCount = (int)$todaySum['count'];
    $todayRevenue = (float)$todaySum['revenue'];

    // 12. Monthly Sales count & revenue (Current Month)
    $monthStmt = $pdo->query("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue 
        FROM sales 
        WHERE YEAR(sale_date) = YEAR(CURDATE()) 
          AND MONTH(sale_date) = MONTH(CURDATE())
          AND status = 'completed'
    ");
    $monthSum = $monthStmt->fetch();
    $monthSalesCount = (int)$monthSum['count'];
    $monthRevenue = (float)$monthSum['revenue'];

    // 13. Sales Overview: Daily Sales (last 30 days)
    $dailyStmt = $pdo->query("
        SELECT 
            DATE(sale_date) as sale_day,
            DATE_FORMAT(sale_date, '%b %d') as formatted_date,
            COUNT(*) as transaction_count,
            COALESCE(SUM(total_amount), 0) as daily_total
        FROM sales
        WHERE status = 'completed'
        GROUP BY DATE(sale_date)
        ORDER BY sale_day ASC
        LIMIT 30
    ");
    $dailySales = array_map(function($row) {
        return [
            'date'         => $row['sale_day'],
            'label'        => $row['formatted_date'],
            'transactions' => (int)$row['transaction_count'],
            'total'        => (float)$row['daily_total']
        ];
    }, $dailyStmt->fetchAll());

    // 14. Sales Overview: Weekly Sales (grouped by year-week)
    $weeklyStmt = $pdo->query("
        SELECT 
            YEARWEEK(sale_date, 1) as year_week,
            CONCAT('W', WEEK(sale_date, 1), ' (', DATE_FORMAT(MIN(sale_date), '%b %d'), ')') as formatted_week,
            COUNT(*) as transaction_count,
            COALESCE(SUM(total_amount), 0) as weekly_total
        FROM sales
        WHERE status = 'completed'
        GROUP BY YEARWEEK(sale_date, 1)
        ORDER BY year_week ASC
        LIMIT 12
    ");
    $weeklySales = array_map(function($row) {
        return [
            'week'         => $row['year_week'],
            'label'        => $row['formatted_week'],
            'transactions' => (int)$row['transaction_count'],
            'total'        => (float)$row['weekly_total']
        ];
    }, $weeklyStmt->fetchAll());

    // 15. Sales Overview: Monthly Sales
    $monthlyStmt = $pdo->query("
        SELECT 
            DATE_FORMAT(sale_date, '%Y-%m') as month_key,
            DATE_FORMAT(sale_date, '%b %Y') as formatted_month,
            COUNT(*) as transaction_count,
            COALESCE(SUM(total_amount), 0) as monthly_total
        FROM sales
        WHERE status = 'completed'
        GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
        ORDER BY month_key ASC
        LIMIT 12
    ");
    $monthlySales = array_map(function($row) {
        return [
            'month'        => $row['month_key'],
            'label'        => $row['formatted_month'],
            'transactions' => (int)$row['transaction_count'],
            'total'        => (float)$row['monthly_total']
        ];
    }, $monthlyStmt->fetchAll());

    // 16. Best Selling Products (Top 5)
    $bestStmt = $pdo->query("
        SELECT 
            p.product_id,
            p.product_name,
            p.barcode,
            c.category_name,
            COALESCE(SUM(si.quantity), 0) AS units_sold,
            COALESCE(SUM(si.subtotal), 0) AS total_revenue
        FROM sale_items si
        JOIN products p ON si.product_id = p.product_id
        LEFT JOIN categories c ON p.category_id = c.category_id
        JOIN sales s ON si.sale_id = s.sale_id
        WHERE s.status = 'completed'
        GROUP BY p.product_id, p.product_name, p.barcode, c.category_name
        ORDER BY units_sold DESC
        LIMIT 5
    ");
    $bestSellingProducts = array_map(function($row) {
        return [
            'product_id'    => (int)$row['product_id'],
            'product_name'  => $row['product_name'],
            'barcode'       => $row['barcode'],
            'category_name' => $row['category_name'] ?? 'General',
            'units_sold'    => (int)$row['units_sold'],
            'total_revenue' => (float)$row['total_revenue']
        ];
    }, $bestStmt->fetchAll());

    // 17. Top Product Categories by Revenue
    $topCatStmt = $pdo->query("
        SELECT 
            c.category_id,
            c.category_name,
            COALESCE(SUM(si.subtotal), 0) AS category_revenue,
            COALESCE(SUM(si.quantity), 0) AS units_sold
        FROM categories c
        LEFT JOIN products p ON c.category_id = p.category_id
        LEFT JOIN sale_items si ON p.product_id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.sale_id AND s.status = 'completed'
        GROUP BY c.category_id, c.category_name
        ORDER BY category_revenue DESC
        LIMIT 6
    ");
    $topCategories = array_map(function($row) {
        return [
            'category_id'      => (int)$row['category_id'],
            'category_name'    => $row['category_name'],
            'category_revenue' => (float)$row['category_revenue'],
            'units_sold'       => (int)$row['units_sold']
        ];
    }, $topCatStmt->fetchAll());

    // 18. Recent Sales (Latest 8 sales)
    $recentSalesStmt = $pdo->query("
        SELECT 
            s.sale_id,
            s.cashier_id,
            COALESCE(u.full_name, 'Cashier') as cashier_name,
            u.role as cashier_role,
            COALESCE(c.customer_name, 'Walk-in') as customer_name,
            s.sale_date,
            s.total_amount,
            s.payment_method,
            (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.sale_id) as item_count
        FROM sales s
        LEFT JOIN users u ON s.cashier_id = u.user_id
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        WHERE s.status = 'completed'
        ORDER BY s.sale_date DESC, s.sale_id DESC
        LIMIT 8
    ");
    $recentSales = array_map(function($row) {
        return [
            'sale_id'        => (int)$row['sale_id'],
            'cashier_id'     => (int)$row['cashier_id'],
            'cashier_name'   => $row['cashier_name'],
            'cashier_role'   => $row['cashier_role'] ?? 'Cashier',
            'customer_name'  => $row['customer_name'],
            'sale_date'      => $row['sale_date'],
            'total_amount'   => (float)$row['total_amount'],
            'payment_method' => strtoupper($row['payment_method'] ?? 'cash'),
            'item_count'     => (int)$row['item_count']
        ];
    }, $recentSalesStmt->fetchAll());

    // 19. Low Stock & Out of Stock Products List
    $lowStockStmt = $pdo->query("
        SELECT 
            p.product_id,
            p.barcode,
            p.product_name,
            c.category_name,
            p.selling_price,
            p.stock_quantity,
            p.reorder_level,
            p.expiry_date
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.stock_quantity <= p.reorder_level
        ORDER BY p.stock_quantity ASC, p.product_name ASC
    ");
    $lowStockList = array_map(function($row) {
        return [
            'product_id'      => (int)$row['product_id'],
            'barcode'         => $row['barcode'],
            'product_name'    => $row['product_name'],
            'category_name'   => $row['category_name'] ?? 'General',
            'selling_price'   => (float)$row['selling_price'],
            'stock_quantity'  => (int)$row['stock_quantity'],
            'reorder_level'   => (int)$row['reorder_level'],
            'expiry_date'     => $row['expiry_date'],
            'is_out_of_stock' => ((int)$row['stock_quantity'] === 0)
        ];
    }, $lowStockStmt->fetchAll());

    // 20. Category product & units distribution
    $catDistStmt = $pdo->query("
        SELECT 
            c.category_id,
            c.category_name,
            COUNT(p.product_id) as product_count,
            COALESCE(SUM(p.stock_quantity), 0) as total_units
        FROM categories c
        LEFT JOIN products p ON c.category_id = p.category_id
        GROUP BY c.category_id, c.category_name
        ORDER BY product_count DESC
    ");
    $categoryDistribution = array_map(function($row) {
        return [
            'category_id'   => (int)$row['category_id'],
            'category_name' => $row['category_name'],
            'product_count' => (int)$row['product_count'],
            'total_units'   => (int)$row['total_units']
        ];
    }, $catDistStmt->fetchAll());

    echo json_encode([
        'success'   => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'summary'   => [
            'total_products'          => $totalProducts,
            'total_categories'        => $totalCategories,
            'total_inventory_units'   => $totalInventoryUnits,
            'low_stock_products'      => $lowStockProducts,
            'out_of_stock_products'   => $outOfStockProducts,
            'expiring_products_count' => $expiringProductsCount,
            'total_suppliers'         => $totalSuppliers,
            'total_customers'         => $totalCustomers,
            'total_cashiers'          => $totalCashiers,
            'total_sales_count'       => $totalSalesCount,
            'total_revenue'           => $totalRevenue,
            'today_sales_count'       => $todaySalesCount,
            'today_revenue'           => $todayRevenue,
            'month_sales_count'       => $monthSalesCount,
            'month_revenue'           => $monthRevenue,
            'total_users'             => $totalUsers
        ],
        'sales_overview' => [
            'daily'   => $dailySales,
            'weekly'  => $weeklySales,
            'monthly' => $monthlySales
        ],
        'best_selling_products' => $bestSellingProducts,
        'top_categories'        => $topCategories,
        'recent_sales'          => $recentSales,
        'low_stock_products'    => $lowStockList,
        'category_distribution' => $categoryDistribution
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error querying dashboard stats: ' . $e->getMessage()
    ]);
}
