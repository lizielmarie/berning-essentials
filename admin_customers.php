<?php
/**
 * BERNING STORE — API: CUSTOMER MANAGEMENT (CRUD)
 * Table: `customers` in `berning_store`
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'POST' ? 'save' : 'list');

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $status = trim($_GET['status'] ?? 'all');

            $sql = "
                SELECT 
                    c.customer_id,
                    c.customer_name,
                    c.phone,
                    c.email,
                    c.address,
                    c.status,
                    c.created_at,
                    COUNT(s.sale_id) AS total_orders,
                    COALESCE(SUM(s.total_amount), 0) AS total_spent,
                    MAX(s.sale_date) AS last_purchase_date
                FROM customers c
                LEFT JOIN sales s ON c.customer_id = s.customer_id
                WHERE 1=1
            ";

            $params = [];
            if ($search !== '') {
                $sql .= " AND (c.customer_name LIKE :s_name OR c.phone LIKE :s_phone OR c.email LIKE :s_email)";
                $params[':s_name']  = "%$search%";
                $params[':s_phone'] = "%$search%";
                $params[':s_email'] = "%$search%";
            }

            if ($status !== 'all' && in_array($status, ['active', 'inactive'])) {
                $sql .= " AND c.status = :status";
                $params[':status'] = $status;
            }

            $sql .= " GROUP BY c.customer_id, c.customer_name, c.phone, c.email, c.address, c.status, c.created_at
                      ORDER BY c.customer_id ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $customers = $stmt->fetchAll();

            $formatted = array_map(function($c) {
                return [
                    'customer_id'        => (int)$c['customer_id'],
                    'customer_name'      => $c['customer_name'],
                    'phone'              => $c['phone'] ?? '',
                    'email'              => $c['email'] ?? '',
                    'address'            => $c['address'] ?? '',
                    'status'             => $c['status'] ?? 'active',
                    'created_at'         => $c['created_at'],
                    'total_orders'       => (int)$c['total_orders'],
                    'total_spent'        => (float)$c['total_spent'],
                    'last_purchase_date' => $c['last_purchase_date']
                ];
            }, $customers);

            echo json_encode([
                'success'   => true,
                'count'     => count($formatted),
                'customers' => $formatted
            ]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Customer ID is required.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = :id");
            $stmt->execute([':id' => $id]);
            $cust = $stmt->fetch();

            if (!$cust) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Customer not found.']);
                exit;
            }

            // Purchase history
            $histStmt = $pdo->prepare("
                SELECT 
                    s.sale_id,
                    s.sale_date,
                    s.total_amount,
                    s.payment_method,
                    u.full_name AS cashier_name,
                    COUNT(si.sale_item_id) AS items_count
                FROM sales s
                LEFT JOIN users u ON s.cashier_id = u.user_id
                LEFT JOIN sale_items si ON s.sale_id = si.sale_id
                WHERE s.customer_id = :id
                GROUP BY s.sale_id, s.sale_date, s.total_amount, s.payment_method, u.full_name
                ORDER BY s.sale_date DESC
                LIMIT 15
            ");
            $histStmt->execute([':id' => $id]);
            $history = $histStmt->fetchAll();

            echo json_encode([
                'success'  => true,
                'customer' => [
                    'customer_id'   => (int)$cust['customer_id'],
                    'customer_name' => $cust['customer_name'],
                    'phone'         => $cust['phone'] ?? '',
                    'email'         => $cust['email'] ?? '',
                    'address'       => $cust['address'] ?? '',
                    'status'        => $cust['status'] ?? 'active',
                    'created_at'    => $cust['created_at'],
                    'history'       => $history
                ]
            ]);
            break;

        case 'create':
        case 'add':
            $input   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $name    = trim($input['customer_name'] ?? '');
            $phone   = trim($input['phone'] ?? '');
            $email   = trim($input['email'] ?? '');
            $address = trim($input['address'] ?? '');
            $status  = trim($input['status'] ?? 'active');

            if ($name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Customer name is required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO customers (customer_name, phone, email, address, status)
                VALUES (:name, :phone, :email, :address, :status)
            ");
            $stmt->execute([
                ':name'    => $name,
                ':phone'   => $phone,
                ':email'   => $email,
                ':address' => $address,
                ':status'  => in_array($status, ['active', 'inactive']) ? $status : 'active'
            ]);

            $newId = (int)$pdo->lastInsertId();
            bs_log_audit($pdo, null, 'CUSTOMER_ADDED', 'CUSTOMERS', $newId, "Added customer $name");

            echo json_encode([
                'success'     => true,
                'customer_id' => $newId,
                'message'     => "Customer '$name' added successfully."
            ]);
            break;

        case 'update':
        case 'edit':
            $input   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id      = (int)($input['customer_id'] ?? 0);
            $name    = trim($input['customer_name'] ?? '');
            $phone   = trim($input['phone'] ?? '');
            $email   = trim($input['email'] ?? '');
            $address = trim($input['address'] ?? '');
            $status  = trim($input['status'] ?? 'active');

            if ($id <= 0 || $name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Customer ID and Name are required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE customers 
                SET customer_name = :name,
                    phone = :phone,
                    email = :email,
                    address = :address,
                    status = :status
                WHERE customer_id = :id
            ");
            $stmt->execute([
                ':name'    => $name,
                ':phone'   => $phone,
                ':email'   => $email,
                ':address' => $address,
                ':status'  => in_array($status, ['active', 'inactive']) ? $status : 'active',
                ':id'      => $id
            ]);

            bs_log_audit($pdo, null, 'CUSTOMER_UPDATED', 'CUSTOMERS', $id, "Updated customer $name");

            echo json_encode([
                'success' => true,
                'message' => "Customer '$name' updated successfully."
            ]);
            break;

        case 'delete':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id    = (int)($input['customer_id'] ?? ($_GET['id'] ?? 0));

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Customer ID is required.']);
                exit;
            }

            if ($id === 1) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Default Walk-in Customer (ID 1) cannot be deleted.']);
                exit;
            }

            // Check if customer has sales records
            $check = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE customer_id = :id");
            $check->execute([':id' => $id]);
            $count = (int)$check->fetchColumn();

            if ($count > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete customer: Linked to {$count} sales transactions. Set customer status to 'inactive' instead."
                ]);
                exit;
            }

            $delStmt = $pdo->prepare("DELETE FROM customers WHERE customer_id = :id");
            $delStmt->execute([':id' => $id]);

            bs_log_audit($pdo, null, 'CUSTOMER_DELETED', 'CUSTOMERS', $id, "Deleted customer ID $id");

            echo json_encode([
                'success' => true,
                'message' => 'Customer removed successfully.'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown customer action.']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Customer API error: ' . $e->getMessage()
    ]);
}
