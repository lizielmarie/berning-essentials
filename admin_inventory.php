<?php
/**
 * BERNING STORE — API: INVENTORY & STOCK-IN MANAGEMENT
 * Tables: `products`, `stock_in`, `suppliers`, `users`
 */
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'inventory_list';

try {
    switch ($action) {
        case 'inventory_list':
            $search = trim($_GET['search'] ?? '');
            $stockStatus = trim($_GET['stock_status'] ?? 'all');

            $sql = "
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
                    (p.stock_quantity * p.selling_price) AS inventory_value,
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
                WHERE 1=1
            ";

            $params = [];
            if ($search !== '') {
                $sql .= " AND (p.product_name LIKE :search_name OR p.barcode LIKE :search_code)";
                $params[':search_name'] = "%$search%";
                $params[':search_code'] = "%$search%";
            }

            if ($stockStatus === 'low_stock') {
                $sql .= " AND p.stock_quantity <= p.reorder_level AND p.stock_quantity > 0";
            } elseif ($stockStatus === 'out_of_stock') {
                $sql .= " AND p.stock_quantity = 0";
            } elseif ($stockStatus === 'in_stock') {
                $sql .= " AND p.stock_quantity > p.reorder_level";
            }

            $sql .= " ORDER BY p.stock_quantity ASC, p.product_name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            $totalUnits = 0;
            $totalValuation = 0.0;
            $formatted = array_map(function($p) use (&$totalUnits, &$totalValuation) {
                $qty = (int)$p['stock_quantity'];
                $val = (float)$p['inventory_value'];
                $totalUnits += $qty;
                $totalValuation += $val;
                return [
                    'product_id'      => (int)$p['product_id'],
                    'barcode'         => $p['barcode'],
                    'product_name'    => $p['product_name'],
                    'category_name'   => $p['category_name'] ?? 'General',
                    'selling_price'   => (float)$p['selling_price'],
                    'stock_quantity'  => $qty,
                    'reorder_level'   => (int)$p['reorder_level'],
                    'expiry_date'     => $p['expiry_date'],
                    'inventory_value' => $val,
                    'stock_status'    => $p['stock_status'],
                    'expiry_status'   => $p['expiry_status']
                ];
            }, $products);

            echo json_encode([
                'success'         => true,
                'count'           => count($formatted),
                'total_units'     => $totalUnits,
                'total_valuation' => $totalValuation,
                'products'        => $formatted
            ]);
            break;

        case 'stock_in_list':
            $search = trim($_GET['search'] ?? '');
            $supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
            $startDate = trim($_GET['start_date'] ?? '');
            $endDate = trim($_GET['end_date'] ?? '');

            $sql = "
                SELECT 
                    si.stockin_id,
                    si.product_id,
                    p.product_name,
                    p.barcode,
                    c.category_name,
                    si.supplier_id,
                    sup.supplier_name,
                    si.quantity,
                    si.date_received,
                    si.received_by,
                    COALESCE(u.full_name, 'Staff') AS received_by_name,
                    u.role AS receiver_role
                FROM stock_in si
                LEFT JOIN products p ON si.product_id = p.product_id
                LEFT JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN suppliers sup ON si.supplier_id = sup.supplier_id
                LEFT JOIN users u ON si.received_by = u.user_id
                WHERE 1=1
            ";

            $params = [];

            if ($search !== '') {
                $sql .= " AND (p.product_name LIKE :search_prod OR sup.supplier_name LIKE :search_sup OR p.barcode LIKE :search_code)";
                $params[':search_prod'] = "%$search%";
                $params[':search_sup'] = "%$search%";
                $params[':search_code'] = "%$search%";
            }

            if ($supplierId > 0) {
                $sql .= " AND si.supplier_id = :supplier_id";
                $params[':supplier_id'] = $supplierId;
            }

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
            $logs = $stmt->fetchAll();

            $totalQty = 0;
            $formatted = array_map(function($l) use (&$totalQty) {
                $q = (int)$l['quantity'];
                $totalQty += $q;
                return [
                    'stockin_id'       => (int)$l['stockin_id'],
                    'product_id'       => (int)$l['product_id'],
                    'product_name'     => $l['product_name'] ?? 'Unknown Product',
                    'barcode'          => $l['barcode'] ?? '',
                    'category_name'    => $l['category_name'] ?? 'General',
                    'supplier_id'      => (int)$l['supplier_id'],
                    'supplier_name'    => $l['supplier_name'] ?? 'Unknown Supplier',
                    'quantity'         => $q,
                    'date_received'    => $l['date_received'],
                    'received_by'      => (int)$l['received_by'],
                    'received_by_name' => $l['received_by_name'],
                    'receiver_role'    => $l['receiver_role'] ?? 'Staff'
                ];
            }, $logs);

            echo json_encode([
                'success'        => true,
                'count'          => count($formatted),
                'total_quantity' => $totalQty,
                'stock_in_logs'  => $formatted
            ]);
            break;

        case 'add_stock_in':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $productId    = (int)($input['product_id'] ?? 0);
            $supplierId   = (int)($input['supplier_id'] ?? 0);
            $quantity     = (int)($input['quantity'] ?? 0);
            $dateReceived = trim($input['date_received'] ?? date('Y-m-d'));
            $receivedBy   = (int)($input['received_by'] ?? 1); // default user 1 (Owner)

            if ($productId <= 0 || $supplierId <= 0 || $quantity <= 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Please provide valid Product, Supplier, and positive Quantity.'
                ]);
                exit;
            }

            // Verify product exists
            $prodStmt = $pdo->prepare("SELECT product_id, product_name, stock_quantity FROM products WHERE product_id = :id FOR UPDATE");
            $pdo->beginTransaction();

            $prodStmt->execute([':id' => $productId]);
            $product = $prodStmt->fetch();

            if (!$product) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Selected product does not exist.']);
                exit;
            }

            // Verify supplier exists
            $supStmt = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = :id");
            $supStmt->execute([':id' => $supplierId]);
            $supplier = $supStmt->fetch();

            if (!$supplier) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Selected supplier does not exist.']);
                exit;
            }

            // 1. Insert into `stock_in` table
            $insStmt = $pdo->prepare("
                INSERT INTO stock_in (product_id, supplier_id, quantity, date_received, received_by)
                VALUES (:product_id, :supplier_id, :quantity, :date_received, :received_by)
            ");
            $insStmt->execute([
                ':product_id'    => $productId,
                ':supplier_id'   => $supplierId,
                ':quantity'      => $quantity,
                ':date_received' => $dateReceived,
                ':received_by'   => $receivedBy
            ]);
            $stockInId = (int)$pdo->lastInsertId();

            // 2. Update `products` actual stock quantity
            $updStmt = $pdo->prepare("
                UPDATE products 
                SET stock_quantity = stock_quantity + :qty 
                WHERE product_id = :product_id
            ");
            $updStmt->execute([
                ':qty'        => $quantity,
                ':product_id' => $productId
            ]);

            $newStock = (int)$product['stock_quantity'] + $quantity;

            $pdo->commit();

            echo json_encode([
                'success'        => true,
                'stockin_id'     => $stockInId,
                'product_name'   => $product['product_name'],
                'added_quantity' => $quantity,
                'new_stock'      => $newStock,
                'message'        => "Successfully received {$quantity} units of {$product['product_name']} from {$supplier['supplier_name']}. New stock: {$newStock}."
            ]);
            break;

        case 'metadata':
            // Fast dropdown options for stock-in form
            $prods = $pdo->query("SELECT product_id, barcode, product_name, stock_quantity, reorder_level FROM products ORDER BY product_name ASC")->fetchAll();
            $sups = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name ASC")->fetchAll();
            $users = $pdo->query("SELECT user_id, full_name, role FROM users ORDER BY full_name ASC")->fetchAll();

            echo json_encode([
                'success'   => true,
                'products'  => array_map(function($p) {
                    return [
                        'product_id'     => (int)$p['product_id'],
                        'barcode'        => $p['barcode'],
                        'product_name'   => $p['product_name'],
                        'stock_quantity' => (int)$p['stock_quantity'],
                        'reorder_level'  => (int)$p['reorder_level']
                    ];
                }, $prods),
                'suppliers' => array_map(function($s) {
                    return [
                        'supplier_id'   => (int)$s['supplier_id'],
                        'supplier_name' => $s['supplier_name']
                    ];
                }, $sups),
                'users'     => array_map(function($u) {
                    return [
                        'user_id'   => (int)$u['user_id'],
                        'full_name' => $u['full_name'],
                        'role'      => $u['role']
                    ];
                }, $users)
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown inventory action.']);
            break;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Inventory API error: ' . $e->getMessage()
    ]);
}
