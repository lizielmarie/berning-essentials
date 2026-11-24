<?php
/**
 * BERNING STORE — API: PRODUCT MANAGEMENT (CRUD & FILTERS)
 * Source of truth: `products` table in MySQL `berning_store`
 */
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'POST' ? 'save' : 'list');

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
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
                    CASE 
                        WHEN p.stock_quantity = 0 THEN 'Out of Stock'
                        WHEN p.stock_quantity <= p.reorder_level THEN 'Low Stock'
                        ELSE 'In Stock'
                    END AS stock_status,
                    CASE 
                        WHEN p.expiry_date IS NOT NULL AND p.expiry_date < CURDATE() THEN 'Expired'
                        WHEN p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 'Expiring Soon'
                        ELSE 'Good'
                    END AS expiry_status,
                    DATEDIFF(p.expiry_date, CURDATE()) AS days_to_expiry
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

            if ($categoryId !== null) {
                $sql .= " AND p.category_id = :category_id";
                $params[':category_id'] = $categoryId;
            }

            if ($stockStatus === 'low_stock') {
                $sql .= " AND p.stock_quantity <= p.reorder_level AND p.stock_quantity > 0";
            } elseif ($stockStatus === 'out_of_stock') {
                $sql .= " AND p.stock_quantity = 0";
            } elseif ($stockStatus === 'in_stock') {
                $sql .= " AND p.stock_quantity > p.reorder_level";
            } elseif ($stockStatus === 'expiring') {
                $sql .= " AND p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
            }

            $sql .= " ORDER BY p.product_name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            $formatted = array_map(function($p) {
                return [
                    'product_id'     => (int)$p['product_id'],
                    'barcode'        => $p['barcode'],
                    'product_name'   => $p['product_name'],
                    'category_id'    => (int)$p['category_id'],
                    'category_name'  => $p['category_name'] ?? 'Uncategorized',
                    'selling_price'  => (float)$p['selling_price'],
                    'stock_quantity' => (int)$p['stock_quantity'],
                    'reorder_level'  => (int)$p['reorder_level'],
                    'expiry_date'    => $p['expiry_date'],
                    'stock_status'   => $p['stock_status'],
                    'expiry_status'  => $p['expiry_status'],
                    'days_to_expiry' => $p['days_to_expiry'] !== null ? (int)$p['days_to_expiry'] : null
                ];
            }, $products);

            echo json_encode([
                'success'  => true,
                'count'    => count($formatted),
                'products' => $formatted
            ]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception("Product ID is required.");
            }

            $stmt = $pdo->prepare("
                SELECT 
                    p.*,
                    c.category_name,
                    COALESCE((SELECT SUM(si.quantity) FROM sale_items si WHERE si.product_id = p.product_id), 0) AS total_units_sold,
                    COALESCE((SELECT SUM(si.subtotal) FROM sale_items si WHERE si.product_id = p.product_id), 0) AS total_revenue_generated,
                    COALESCE((SELECT SUM(st.quantity) FROM stock_in st WHERE st.product_id = p.product_id), 0) AS total_units_received
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                WHERE p.product_id = :id
            ");
            $stmt->execute([':id' => $id]);
            $p = $stmt->fetch();

            if (!$p) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found in database.']);
                exit;
            }

            // Fetch recent stock-in history for this product
            $stockInStmt = $pdo->prepare("
                SELECT 
                    s.stockin_id,
                    s.quantity,
                    s.date_received,
                    sup.supplier_name,
                    u.full_name AS received_by_name
                FROM stock_in s
                LEFT JOIN suppliers sup ON s.supplier_id = sup.supplier_id
                LEFT JOIN users u ON s.received_by = u.user_id
                WHERE s.product_id = :id
                ORDER BY s.date_received DESC, s.stockin_id DESC
                LIMIT 5
            ");
            $stockInStmt->execute([':id' => $id]);
            $stockHistory = $stockInStmt->fetchAll();

            echo json_encode([
                'success' => true,
                'product' => [
                    'product_id'              => (int)$p['product_id'],
                    'barcode'                 => $p['barcode'],
                    'product_name'            => $p['product_name'],
                    'category_id'             => (int)$p['category_id'],
                    'category_name'           => $p['category_name'] ?? 'General',
                    'selling_price'           => (float)$p['selling_price'],
                    'stock_quantity'          => (int)$p['stock_quantity'],
                    'reorder_level'           => (int)$p['reorder_level'],
                    'expiry_date'             => $p['expiry_date'],
                    'total_units_sold'        => (int)$p['total_units_sold'],
                    'total_revenue_generated' => (float)$p['total_revenue_generated'],
                    'total_units_received'    => (int)$p['total_units_received'],
                    'stock_history'           => $stockHistory
                ]
            ]);
            break;

        case 'add':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $barcode      = trim($input['barcode'] ?? '');
            $productName  = trim($input['product_name'] ?? '');
            $categoryId   = (int)($input['category_id'] ?? 0);
            $sellingPrice = (float)($input['selling_price'] ?? 0);
            $stockQty     = (int)($input['stock_quantity'] ?? 0);
            $reorderLevel = (int)($input['reorder_level'] ?? 5);
            $expiryDate   = !empty($input['expiry_date']) ? $input['expiry_date'] : null;

            if ($barcode === '' || $productName === '' || $categoryId <= 0 || $sellingPrice <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please provide valid barcode, product name, category, and selling price.']);
                exit;
            }

            // Check unique barcode
            $check = $pdo->prepare("SELECT product_id FROM products WHERE barcode = :barcode");
            $check->execute([':barcode' => $barcode]);
            if ($check->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "A product with barcode '$barcode' already exists in the database."]);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO products (barcode, product_name, category_id, selling_price, stock_quantity, reorder_level, expiry_date)
                VALUES (:barcode, :name, :cat_id, :price, :stock, :reorder, :expiry)
            ");
            $stmt->execute([
                ':barcode' => $barcode,
                ':name'    => $productName,
                ':cat_id'  => $categoryId,
                ':price'   => $sellingPrice,
                ':stock'   => $stockQty,
                ':reorder' => $reorderLevel,
                ':expiry'  => $expiryDate
            ]);

            $newId = (int)$pdo->lastInsertId();
            echo json_encode([
                'success'    => true,
                'product_id' => $newId,
                'message'    => "Product '$productName' added successfully."
            ]);
            break;

        case 'edit':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $productId    = (int)($input['product_id'] ?? 0);
            $barcode      = trim($input['barcode'] ?? '');
            $productName  = trim($input['product_name'] ?? '');
            $categoryId   = (int)($input['category_id'] ?? 0);
            $sellingPrice = (float)($input['selling_price'] ?? 0);
            $stockQty     = (int)($input['stock_quantity'] ?? 0);
            $reorderLevel = (int)($input['reorder_level'] ?? 5);
            $expiryDate   = !empty($input['expiry_date']) ? $input['expiry_date'] : null;

            if ($productId <= 0 || $barcode === '' || $productName === '' || $categoryId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid product data for update.']);
                exit;
            }

            // Check if barcode belongs to another product
            $check = $pdo->prepare("SELECT product_id FROM products WHERE barcode = :barcode AND product_id != :id");
            $check->execute([':barcode' => $barcode, ':id' => $productId]);
            if ($check->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Another product is already using barcode '$barcode'."]);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE products 
                SET barcode = :barcode,
                    product_name = :name,
                    category_id = :cat_id,
                    selling_price = :price,
                    stock_quantity = :stock,
                    reorder_level = :reorder,
                    expiry_date = :expiry
                WHERE product_id = :id
            ");
            $stmt->execute([
                ':barcode' => $barcode,
                ':name'    => $productName,
                ':cat_id'  => $categoryId,
                ':price'   => $sellingPrice,
                ':stock'   => $stockQty,
                ':reorder' => $reorderLevel,
                ':expiry'  => $expiryDate,
                ':id'      => $productId
            ]);

            echo json_encode([
                'success' => true,
                'message' => "Product '$productName' updated successfully."
            ]);
            break;

        case 'delete':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $productId = (int)($input['product_id'] ?? ($_GET['id'] ?? 0));

            if ($productId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Product ID is required.']);
                exit;
            }

            // Check constraints in sale_items and stock_in
            $saleCheck = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id = :id");
            $saleCheck->execute([':id' => $productId]);
            $salesCount = (int)$saleCheck->fetchColumn();

            $stockCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_in WHERE product_id = :id");
            $stockCheck->execute([':id' => $productId]);
            $stockCount = (int)$stockCheck->fetchColumn();

            if ($salesCount > 0 || $stockCount > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete product: It is referenced in {$salesCount} sales records and {$stockCount} stock-in logs. To preserve historical audit trails, you may set its stock to 0 instead."
                ]);
                exit;
            }

            $delStmt = $pdo->prepare("DELETE FROM products WHERE product_id = :id");
            $delStmt->execute([':id' => $productId]);

            echo json_encode([
                'success' => true,
                'message' => 'Product removed from database.'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown product action.']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Product API error: ' . $e->getMessage()
    ]);
}
