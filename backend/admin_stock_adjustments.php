<?php
/**
 * BERNING STORE — API: STOCK ADJUSTMENTS & AUDIT TRAILS
 * Tables: `stock_adjustments`, `products`, `users`
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'POST' ? 'add' : 'list');

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $productId = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
            $type = trim($_GET['type'] ?? '');
            $startDate = trim($_GET['start_date'] ?? '');
            $endDate = trim($_GET['end_date'] ?? '');

            $sql = "
                SELECT 
                    sa.adjustment_id,
                    sa.product_id,
                    p.product_name,
                    p.barcode,
                    c.category_name,
                    sa.user_id,
                    COALESCE(u.full_name, 'System') AS user_full_name,
                    u.role AS user_role,
                    sa.adjustment_type,
                    sa.quantity,
                    sa.reason,
                    sa.reference_note,
                    sa.created_at
                FROM stock_adjustments sa
                LEFT JOIN products p ON sa.product_id = p.product_id
                LEFT JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN users u ON sa.user_id = u.user_id
                WHERE 1=1
            ";

            $params = [];
            if ($search !== '') {
                $sql .= " AND (p.product_name LIKE :s_name OR p.barcode LIKE :s_code OR sa.reason LIKE :s_reason)";
                $params[':s_name']   = "%$search%";
                $params[':s_code']   = "%$search%";
                $params[':s_reason'] = "%$search%";
            }

            if ($productId > 0) {
                $sql .= " AND sa.product_id = :p_id";
                $params[':p_id'] = $productId;
            }

            if ($type !== '' && in_array($type, ['in', 'out', 'adjustment'])) {
                $sql .= " AND sa.adjustment_type = :type";
                $params[':type'] = $type;
            }

            if ($startDate !== '') {
                $sql .= " AND DATE(sa.created_at) >= :start_date";
                $params[':start_date'] = $startDate;
            }

            if ($endDate !== '') {
                $sql .= " AND DATE(sa.created_at) <= :end_date";
                $params[':end_date'] = $endDate;
            }

            $sql .= " ORDER BY sa.created_at DESC, sa.adjustment_id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $formatted = array_map(function($r) {
                return [
                    'adjustment_id'   => (int)$r['adjustment_id'],
                    'product_id'      => (int)$r['product_id'],
                    'product_name'    => $r['product_name'] ?? 'Unknown',
                    'barcode'         => $r['barcode'] ?? '',
                    'category_name'   => $r['category_name'] ?? 'General',
                    'user_id'         => (int)$r['user_id'],
                    'user_full_name'  => $r['user_full_name'],
                    'user_role'       => $r['user_role'] ?? 'Staff',
                    'adjustment_type' => $r['adjustment_type'],
                    'quantity'        => (int)$r['quantity'],
                    'reason'          => $r['reason'],
                    'reference_note'  => $r['reference_note'] ?? '',
                    'created_at'      => $r['created_at']
                ];
            }, $rows);

            echo json_encode([
                'success'     => true,
                'count'       => count($formatted),
                'adjustments' => $formatted
            ]);
            break;

        case 'create':
        case 'add':
            $input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $productId = (int)($input['product_id'] ?? 0);
            $type      = trim($input['adjustment_type'] ?? 'adjustment');
            $quantity  = (int)($input['quantity'] ?? 0);
            $reason    = trim($input['reason'] ?? '');
            $note      = trim($input['reference_note'] ?? ($input['notes'] ?? ''));
            
            // Try to resolve current user from session or input
            $sessionUser = bs_validate_session($pdo);
            $userId = $sessionUser ? (int)$sessionUser['user_id'] : (int)($input['user_id'] ?? 1);

            if ($productId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please select a valid product.']);
                exit;
            }

            if (!in_array($type, ['in', 'out', 'adjustment'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid adjustment type. Must be in, out, or adjustment.']);
                exit;
            }

            if ($reason === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please provide a clear reason for the stock adjustment.']);
                exit;
            }

            $pdo->beginTransaction();

            $pStmt = $pdo->prepare("SELECT product_id, product_name, stock_quantity FROM products WHERE product_id = :id FOR UPDATE");
            $pStmt->execute([':id' => $productId]);
            $prod = $pStmt->fetch();

            if (!$prod) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found in database.']);
                exit;
            }

            $oldStock = (int)$prod['stock_quantity'];
            $newStock = $oldStock;

            if ($type === 'in') {
                if ($quantity <= 0) {
                    throw new Exception("Stock-In adjustment requires a positive quantity.");
                }
                $newStock = $oldStock + $quantity;
            } elseif ($type === 'out') {
                if ($quantity <= 0) {
                    throw new Exception("Stock-Out adjustment requires a positive quantity.");
                }
                if ($oldStock < $quantity) {
                    throw new Exception("Cannot deduct {$quantity} units. Current stock is only {$oldStock} units.");
                }
                $newStock = $oldStock - $quantity;
            } elseif ($type === 'adjustment') {
                // Adjustment sets the absolute stock count
                if ($quantity < 0) {
                    throw new Exception("Adjusted stock quantity cannot be negative.");
                }
                $newStock = $quantity;
            }

            // Update product stock
            $upd = $pdo->prepare("UPDATE products SET stock_quantity = :stock WHERE product_id = :id");
            $upd->execute([':stock' => $newStock, ':id' => $productId]);

            // Insert into stock_adjustments
            $ins = $pdo->prepare("
                INSERT INTO stock_adjustments (product_id, user_id, adjustment_type, quantity, reason, reference_note)
                VALUES (:pid, :uid, :type, :qty, :reason, :note)
            ");
            $ins->execute([
                ':pid'    => $productId,
                ':uid'    => $userId,
                ':type'   => $type,
                ':qty'    => $quantity,
                ':reason' => $reason,
                ':note'   => $note
            ]);
            $adjId = (int)$pdo->lastInsertId();

            $pdo->commit();

            bs_log_audit($pdo, $userId, 'STOCK_ADJUSTMENT', 'PRODUCTS', $productId, "Stock adjusted ($type): $oldStock -> $newStock. Reason: $reason");

            echo json_encode([
                'success'       => true,
                'adjustment_id' => $adjId,
                'product_name'  => $prod['product_name'],
                'old_stock'     => $oldStock,
                'new_stock'     => $newStock,
                'message'       => "Stock for '{$prod['product_name']}' successfully updated from {$oldStock} to {$newStock} units."
            ]);
            break;

        case 'product_history':
            $productId = (int)($_GET['id'] ?? 0);
            if ($productId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Product ID is required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    sa.adjustment_id,
                    sa.adjustment_type,
                    sa.quantity,
                    sa.reason,
                    sa.reference_note,
                    sa.created_at,
                    COALESCE(u.full_name, 'Staff') AS adjusted_by
                FROM stock_adjustments sa
                LEFT JOIN users u ON sa.user_id = u.user_id
                WHERE sa.product_id = :id
                ORDER BY sa.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([':id' => $productId]);
            $history = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'history' => $history
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown stock adjustment action.']);
            break;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
