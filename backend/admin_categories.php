<?php
/**
 * BERNING STORE — API: CATEGORY MANAGEMENT (CRUD)
 * Source of truth: `categories` table with `products` relationship in `berning_store`
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
                    c.category_id,
                    c.category_name,
                    COUNT(p.product_id) AS product_count,
                    COALESCE(SUM(p.stock_quantity), 0) AS total_inventory_units
                FROM categories c
                LEFT JOIN products p ON c.category_id = p.category_id
            ";
            
            $params = [];
            if ($search !== '') {
                $sql .= " WHERE c.category_name LIKE :search";
                $params[':search'] = "%$search%";
            }

            $sql .= " GROUP BY c.category_id, c.category_name ORDER BY c.category_name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $categories = $stmt->fetchAll();

            $formatted = array_map(function($c) {
                return [
                    'category_id'           => (int)$c['category_id'],
                    'category_name'         => $c['category_name'],
                    'product_count'         => (int)$c['product_count'],
                    'total_inventory_units' => (int)$c['total_inventory_units']
                ];
            }, $categories);

            echo json_encode([
                'success'    => true,
                'count'      => count($formatted),
                'categories' => $formatted
            ]);
            break;

        case 'add':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $name  = trim($input['category_name'] ?? '');

            if ($name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category name cannot be empty.']);
                exit;
            }

            // Check duplicate name
            $check = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = :name");
            $check->execute([':name' => $name]);
            if ($check->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Category '$name' already exists."]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO categories (category_name) VALUES (:name)");
            $stmt->execute([':name' => $name]);
            $newId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success'     => true,
                'category_id' => $newId,
                'message'     => "Category '$name' created successfully."
            ]);
            break;

        case 'edit':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id    = (int)($input['category_id'] ?? 0);
            $name  = trim($input['category_name'] ?? '');

            if ($id <= 0 || $name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Category ID and name are required.']);
                exit;
            }

            $check = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = :name AND category_id != :id");
            $check->execute([':name' => $name, ':id' => $id]);
            if ($check->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Another category is already named '$name'."]);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE categories SET category_name = :name WHERE category_id = :id");
            $stmt->execute([':name' => $name, ':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => "Category updated to '$name'."
            ]);
            break;

        case 'delete':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id    = (int)($input['category_id'] ?? ($_GET['id'] ?? 0));

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid Category ID is required.']);
                exit;
            }

            // Check if products exist in category
            $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = :id");
            $check->execute([':id' => $id]);
            $count = (int)$check->fetchColumn();

            if ($count > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete category: {$count} product(s) are assigned to it. Reassign or delete those products first."
                ]);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => 'Category deleted successfully.'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown category action.']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Category API error: ' . $e->getMessage()
    ]);
}