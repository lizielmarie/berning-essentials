<?php
/**
 * BERNING STORE — API: SALES MANAGEMENT
 * Tables: `sales`, `sale_items`, `products`, `users`, `customers`
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $startDate = trim($_GET['start_date'] ?? '');
            $endDate = trim($_GET['end_date'] ?? '');
            $dateFilter = trim($_GET['date_filter'] ?? '');

            if ($dateFilter === 'today') {
                $startDate = date('Y-m-d');
                $endDate = date('Y-m-d');
            } elseif ($dateFilter === 'yesterday') {
                $startDate = date('Y-m-d', strtotime('-1 day'));
                $endDate = date('Y-m-d', strtotime('-1 day'));
            } elseif ($dateFilter === 'week') {
                $startDate = date('Y-m-d', strtotime('-7 days'));
                $endDate = date('Y-m-d');
            } elseif ($dateFilter === 'month') {
                $startDate = date('Y-m-01');
                $endDate = date('Y-m-d');
            }

            $cashierId = !empty($_GET['cashier_id']) ? (int)$_GET['cashier_id'] : 0;
            $customerId = !empty($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
            $status = trim($_GET['status'] ?? 'all');
            $paymentMethod = trim($_GET['payment_method'] ?? 'all');

            $sql = "
                SELECT 
                    s.sale_id,
                    s.cashier_id,
                    COALESCE(u.full_name, 'Cashier') AS cashier_name,
                    u.username AS cashier_username,
                    u.role AS cashier_role,
                    s.customer_id,
                    COALESCE(c.customer_name, 'Walk-in Customer') AS customer_name,
                    c.phone AS customer_phone,
                    s.sale_date,
                    s.total_amount,
                    s.subtotal_amount,
                    s.discount_amount,
                    s.tax_amount,
                    s.payment_method,
                    s.cash_received,
                    s.change_amount,
                    s.status,
                    COUNT(si.sale_item_id) AS total_items_count,
                    COALESCE(SUM(si.quantity), 0) AS total_units_sold
                FROM sales s
                LEFT JOIN users u ON s.cashier_id = u.user_id
                LEFT JOIN customers c ON s.customer_id = c.customer_id
                LEFT JOIN sale_items si ON s.sale_id = si.sale_id
                WHERE 1=1
            ";

            $params = [];

            if ($search !== '') {
                $sql .= " AND (s.sale_id = :search_id OR u.full_name LIKE :search_name OR c.customer_name LIKE :search_cust OR u.username LIKE :search_user)";
                $params[':search_id']   = is_numeric($search) ? (int)$search : -1;
                $params[':search_name'] = "%$search%";
                $params[':search_cust'] = "%$search%";
                $params[':search_user'] = "%$search%";
            }

            if ($cashierId > 0) {
                $sql .= " AND s.cashier_id = :cashier_id";
                $params[':cashier_id'] = $cashierId;
            }

            if ($customerId > 0) {
                $sql .= " AND s.customer_id = :customer_id";
                $params[':customer_id'] = $customerId;
            }

            if ($status !== 'all' && in_array($status, ['completed', 'voided'])) {
                $sql .= " AND s.status = :status";
                $params[':status'] = $status;
            }

            if ($paymentMethod !== '' && $paymentMethod !== 'all') {
                $sql .= " AND LOWER(s.payment_method) = :payment_method";
                $params[':payment_method'] = strtolower($paymentMethod);
            }

            if ($startDate !== '') {
                $sql .= " AND DATE(s.sale_date) >= :start_date";
                $params[':start_date'] = $startDate;
            }

            if ($endDate !== '') {
                $sql .= " AND DATE(s.sale_date) <= :end_date";
                $params[':end_date'] = $endDate;
            }

            $sql .= " GROUP BY s.sale_id, s.cashier_id, u.full_name, u.username, u.role, s.customer_id, c.customer_name, c.phone, s.sale_date, s.total_amount, s.subtotal_amount, s.discount_amount, s.tax_amount, s.payment_method, s.cash_received, s.change_amount, s.status
                      ORDER BY s.sale_date DESC, s.sale_id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $sales = $stmt->fetchAll();

            $totalRevenue = 0.0;
            $formatted = array_map(function($s) use (&$totalRevenue) {
                $amt = (float)$s['total_amount'];
                if ($s['status'] === 'completed') {
                    $totalRevenue += $amt;
                }
                return [
                    'sale_id'           => (int)$s['sale_id'],
                    'order_no'          => 'BS-' . str_pad($s['sale_id'], 5, '0', STR_PAD_LEFT),
                    'cashier_id'        => (int)$s['cashier_id'],
                    'cashier_name'      => $s['cashier_name'],
                    'cashier_username'  => $s['cashier_username'] ?? '',
                    'cashier_role'      => $s['cashier_role'] ?? 'Cashier',
                    'customer_id'       => (int)($s['customer_id'] ?? 1),
                    'customer_name'     => $s['customer_name'] ?: 'Walk-in Customer',
                    'customer_phone'    => $s['customer_phone'] ?? '',
                    'sale_date'         => $s['sale_date'],
                    'total_amount'      => $amt,
                    'subtotal_amount'   => (float)$s['subtotal_amount'],
                    'discount_amount'   => (float)$s['discount_amount'],
                    'tax_amount'        => (float)$s['tax_amount'],
                    'payment_method'    => strtoupper($s['payment_method'] ?? 'cash'),
                    'cash_received'     => (float)$s['cash_received'],
                    'change_amount'     => (float)$s['change_amount'],
                    'status'            => $s['status'] ?? 'completed',
                    'total_items_count' => (int)$s['total_items_count'],
                    'total_units_sold'  => (int)$s['total_units_sold']
                ];
            }, $sales);

            echo json_encode([
                'success'       => true,
                'count'         => count($formatted),
                'total_revenue' => $totalRevenue,
                'sales'         => $formatted
            ]);
            break;

        case 'details':
            $saleId = (int)($_GET['id'] ?? 0);
            if ($saleId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Sale ID is required.']);
                exit;
            }

            // Sale header
            $stmt = $pdo->prepare("
                SELECT 
                    s.sale_id,
                    s.cashier_id,
                    COALESCE(u.full_name, 'Unknown Cashier') AS cashier_name,
                    u.username AS cashier_username,
                    u.role AS cashier_role,
                    s.customer_id,
                    COALESCE(c.customer_name, 'Walk-in Customer') AS customer_name,
                    c.phone AS customer_phone,
                    c.email AS customer_email,
                    s.sale_date,
                    s.total_amount,
                    s.subtotal_amount,
                    s.discount_amount,
                    s.tax_amount,
                    s.payment_method,
                    s.cash_received,
                    s.change_amount,
                    s.status
                FROM sales s
                LEFT JOIN users u ON s.cashier_id = u.user_id
                LEFT JOIN customers c ON s.customer_id = c.customer_id
                WHERE s.sale_id = :id
            ");
            $stmt->execute([':id' => $saleId]);
            $sale = $stmt->fetch();

            if (!$sale) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Sale record not found.']);
                exit;
            }

            // Line items joined with actual products
            $itemsStmt = $pdo->prepare("
                SELECT 
                    si.sale_item_id,
                    si.sale_id,
                    si.product_id,
                    si.quantity,
                    si.unit_price,
                    si.subtotal,
                    p.barcode,
                    COALESCE(p.product_name, 'Deleted Product') AS product_name,
                    c.category_name
                FROM sale_items si
                LEFT JOIN products p ON si.product_id = p.product_id
                LEFT JOIN categories c ON p.category_id = c.category_id
                WHERE si.sale_id = :id
                ORDER BY si.sale_item_id ASC
            ");
            $itemsStmt->execute([':id' => $saleId]);
            $items = $itemsStmt->fetchAll();

            $formattedItems = array_map(function($item) {
                return [
                    'sale_item_id' => (int)$item['sale_item_id'],
                    'product_id'   => (int)$item['product_id'],
                    'product_name' => $item['product_name'],
                    'barcode'      => $item['barcode'] ?? '',
                    'category_name'=> $item['category_name'] ?? 'General',
                    'quantity'     => (int)$item['quantity'],
                    'unit_price'   => (float)$item['unit_price'],
                    'subtotal'     => (float)$item['subtotal']
                ];
            }, $items);

            echo json_encode([
                'success' => true,
                'sale'    => [
                    'sale_id'          => (int)$sale['sale_id'],
                    'order_no'         => 'BS-' . str_pad($sale['sale_id'], 5, '0', STR_PAD_LEFT),
                    'cashier_id'       => (int)$sale['cashier_id'],
                    'cashier_name'     => $sale['cashier_name'],
                    'cashier_username' => $sale['cashier_username'] ?? '',
                    'cashier_role'     => $sale['cashier_role'] ?? 'Cashier',
                    'customer_id'      => (int)($sale['customer_id'] ?? 1),
                    'customer_name'    => $sale['customer_name'],
                    'customer_phone'   => $sale['customer_phone'] ?? '',
                    'customer_email'   => $sale['customer_email'] ?? '',
                    'sale_date'        => $sale['sale_date'],
                    'total_amount'     => (float)$sale['total_amount'],
                    'subtotal_amount'  => (float)$sale['subtotal_amount'],
                    'discount_amount'  => (float)$sale['discount_amount'],
                    'tax_amount'       => (float)$sale['tax_amount'],
                    'payment_method'   => strtoupper($sale['payment_method'] ?? 'cash'),
                    'cash_received'    => (float)$sale['cash_received'],
                    'change_amount'    => (float)$sale['change_amount'],
                    'status'           => $sale['status'] ?? 'completed',
                    'items'            => $formattedItems
                ],
                'items'   => $formattedItems
            ]);
            break;

        case 'void':
            $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $saleId = (int)($input['sale_id'] ?? ($_GET['id'] ?? 0));
            $reason = trim($input['reason'] ?? 'Voided by store manager');

            if ($saleId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Sale ID is required.']);
                exit;
            }

            $pdo->beginTransaction();

            $sCheck = $pdo->prepare("SELECT sale_id, total_amount, status FROM sales WHERE sale_id = :id FOR UPDATE");
            $sCheck->execute([':id' => $saleId]);
            $s = $sCheck->fetch();

            if (!$s) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Sale record not found.']);
                exit;
            }

            if ($s['status'] === 'voided') {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Sale has already been voided.']);
                exit;
            }

            // Fetch items to return to inventory
            $itemStmt = $pdo->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = :id");
            $itemStmt->execute([':id' => $saleId]);
            $items = $itemStmt->fetchAll();

            $restockStmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + :qty WHERE product_id = :pid");
            $adjStmt = $pdo->prepare("
                INSERT INTO stock_adjustments (product_id, user_id, adjustment_type, quantity, reason, reference_note)
                VALUES (:pid, :uid, 'in', :qty, :reason, :note)
            ");

            // Determine the acting user (from session or fallback to first active owner)
            $actingUserId = null;
            $sessionUser = bs_validate_session($pdo);
            if ($sessionUser) {
                $actingUserId = (int)$sessionUser['user_id'];
            } else {
                $ownerRow = $pdo->query("SELECT user_id FROM users WHERE role = 'Owner' AND status = 'active' ORDER BY user_id LIMIT 1")->fetch();
                $actingUserId = $ownerRow ? (int)$ownerRow['user_id'] : null;
            }
            // Final fallback: use any active user
            if (!$actingUserId) {
                $anyUser = $pdo->query("SELECT user_id FROM users WHERE status = 'active' ORDER BY user_id LIMIT 1")->fetch();
                $actingUserId = $anyUser ? (int)$anyUser['user_id'] : 2;
            }

            foreach ($items as $item) {
                $pid = (int)$item['product_id'];
                $qty = (int)$item['quantity'];

                $restockStmt->execute([':qty' => $qty, ':pid' => $pid]);
                $adjStmt->execute([
                    ':pid'    => $pid,
                    ':uid'    => $actingUserId,
                    ':qty'    => $qty,
                    ':reason' => "Void sale #$saleId: $reason",
                    ':note'   => "Auto-restocked from voided transaction"
                ]);
            }

            // Mark sale as voided
            $voidStmt = $pdo->prepare("UPDATE sales SET status = 'voided' WHERE sale_id = :id");
            $voidStmt->execute([':id' => $saleId]);

            $pdo->commit();

            bs_log_audit($pdo, $actingUserId, 'SALE_VOIDED', 'SALES', $saleId, "Voided sale #$saleId. Reason: $reason");

            echo json_encode([
                'success' => true,
                'message' => "Sale #$saleId has been voided and all items have been returned to inventory."
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown sales action.']);
            break;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sales API error: ' . $e->getMessage()
    ]);
}
