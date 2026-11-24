<?php
/**
 * BERNING STORE — API: SUPPLIER MANAGEMENT (CRUD)
 * Table: `suppliers` in `berning_store`
 */
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'POST' ? 'save' : 'list');

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');

            $sql = "
                SELECT 
                    s.supplier_id,
                    s.supplier_name,
                    s.contact_number,
                    s.address,
                    COUNT(si.stockin_id) AS delivery_count,
                    COALESCE(SUM(si.quantity), 0) AS total_units_supplied
                FROM suppliers s
                LEFT JOIN stock_in si ON s.supplier_id = si.supplier_id
            ";

            $params = [];
            if ($search !== '') {
                $sql .= " WHERE s.supplier_name LIKE :s_name OR s.contact_number LIKE :s_num OR s.address LIKE :s_addr";
                $params[':s_name'] = "%$search%";
                $params[':s_num']  = "%$search%";
                $params[':s_addr'] = "%$search%";
            }

            $sql .= " GROUP BY s.supplier_id, s.supplier_name, s.contact_number, s.address ORDER BY s.supplier_name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $suppliers = $stmt->fetchAll();

            $formatted = array_map(function($s) {
                return [
                    'supplier_id'          => (int)$s['supplier_id'],
                    'supplier_name'        => $s['supplier_name'],
                    'contact_number'       => $s['contact_number'] ?? '',
                    'address'              => $s['address'] ?? '',
                    'delivery_count'       => (int)$s['delivery_count'],
                    'total_units_supplied' => (int)$s['total_units_supplied']
                ];
            }, $suppliers);

            echo json_encode([
                'success'   => true,
                'count'     => count($formatted),
                'suppliers' => $formatted
            ]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Supplier ID is required.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = :id");
            $stmt->execute([':id' => $id]);
            $supplier = $stmt->fetch();

            if (!$supplier) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
                exit;
            }

            // Fetch delivery history
            $histStmt = $pdo->prepare("
                SELECT 
                    si.stockin_id,
                    si.quantity,
                    si.date_received,
                    p.product_name,
                    p.barcode,
                    u.full_name AS received_by_name
                FROM stock_in si
                LEFT JOIN products p ON si.product_id = p.product_id
                LEFT JOIN users u ON si.received_by = u.user_id
                WHERE si.supplier_id = :id
                ORDER BY si.date_received DESC, si.stockin_id DESC
                LIMIT 15
            ");
            $histStmt->execute([':id' => $id]);
            $history = $histStmt->fetchAll();

            echo json_encode([
                'success'  => true,
                'supplier' => [
                    'supplier_id'    => (int)$supplier['supplier_id'],
                    'supplier_name'  => $supplier['supplier_name'],
                    'contact_number' => $supplier['contact_number'] ?? '',
                    'address'        => $supplier['address'] ?? '',
                    'history'        => $history
                ]
            ]);
            break;

        case 'add':
            $input   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $name    = trim($input['supplier_name'] ?? '');
            $contact = trim($input['contact_number'] ?? '');
            $address = trim($input['address'] ?? '');

            if ($name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Supplier name is required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO suppliers (supplier_name, contact_number, address)
                VALUES (:name, :contact, :address)
            ");
            $stmt->execute([
                ':name'    => $name,
                ':contact' => $contact,
                ':address' => $address
            ]);

            $newId = (int)$pdo->lastInsertId();
            echo json_encode([
                'success'     => true,
                'supplier_id' => $newId,
                'message'     => "Supplier '$name' added successfully."
            ]);
            break;

        case 'edit':
            $input   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id      = (int)($input['supplier_id'] ?? 0);
            $name    = trim($input['supplier_name'] ?? '');
            $contact = trim($input['contact_number'] ?? '');
            $address = trim($input['address'] ?? '');

            if ($id <= 0 || $name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Supplier ID and Name are required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE suppliers 
                SET supplier_name = :name,
                    contact_number = :contact,
                    address = :address
                WHERE supplier_id = :id
            ");
            $stmt->execute([
                ':name'    => $name,
                ':contact' => $contact,
                ':address' => $address,
                ':id'      => $id
            ]);

            echo json_encode([
                'success' => true,
                'message' => "Supplier '$name' updated successfully."
            ]);
            break;

        case 'delete':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id    = (int)($input['supplier_id'] ?? ($_GET['id'] ?? 0));

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Supplier ID is required.']);
                exit;
            }

            // Check if supplier has stock_in records
            $check = $pdo->prepare("SELECT COUNT(*) FROM stock_in WHERE supplier_id = :id");
            $check->execute([':id' => $id]);
            $count = (int)$check->fetchColumn();

            if ($count > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete supplier: Linked to {$count} stock-in record(s). Retain supplier record for inventory audit logs."
                ]);
                exit;
            }

            $delStmt = $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = :id");
            $delStmt->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => 'Supplier removed successfully.'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown supplier action.']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Supplier API error: ' . $e->getMessage()
    ]);
}
