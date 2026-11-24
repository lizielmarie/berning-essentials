<?php
/**
 * BERNING STORE — API: AUDIT LOGS
 * Table: `audit_logs` in `berning_store`
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $actionFilter = trim($_GET['log_action'] ?? '');
            $entityFilter = trim($_GET['entity_type'] ?? '');
            $startDate = trim($_GET['start_date'] ?? '');
            $endDate = trim($_GET['end_date'] ?? '');
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

            $sql = "
                SELECT 
                    a.log_id,
                    a.user_id,
                    COALESCE(u.full_name, 'System') AS user_full_name,
                    u.username,
                    u.role AS user_role,
                    a.action,
                    a.entity_type,
                    a.entity_id,
                    a.details,
                    a.ip_address,
                    a.created_at
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.user_id
                WHERE 1=1
            ";

            $params = [];

            if ($search !== '') {
                $sql .= " AND (a.details LIKE :s_details OR a.action LIKE :s_action OR a.ip_address LIKE :s_ip)";
                $params[':s_details'] = "%$search%";
                $params[':s_action']  = "%$search%";
                $params[':s_ip']      = "%$search%";
            }

            if ($actionFilter !== '') {
                $sql .= " AND a.action = :act";
                $params[':act'] = $actionFilter;
            }

            if ($entityFilter !== '') {
                $sql .= " AND a.entity_type = :entity";
                $params[':entity'] = $entityFilter;
            }

            if ($startDate !== '') {
                $sql .= " AND DATE(a.created_at) >= :start_date";
                $params[':start_date'] = $startDate;
            }

            if ($endDate !== '') {
                $sql .= " AND DATE(a.created_at) <= :end_date";
                $params[':end_date'] = $endDate;
            }

            $sql .= " ORDER BY a.created_at DESC, a.log_id DESC LIMIT " . (int)$limit;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();

            $formatted = array_map(function($l) {
                return [
                    'log_id'         => (int)$l['log_id'],
                    'user_id'        => $l['user_id'] ? (int)$l['user_id'] : null,
                    'user_full_name' => $l['user_full_name'],
                    'username'       => $l['username'] ?? '',
                    'user_role'      => $l['user_role'] ?? 'System',
                    'action'         => $l['action'],
                    'entity_type'    => $l['entity_type'],
                    'entity_id'      => $l['entity_id'] ? (int)$l['entity_id'] : null,
                    'details'        => $l['details'] ?? '',
                    'ip_address'     => $l['ip_address'] ?? '',
                    'created_at'     => $l['created_at']
                ];
            }, $logs);

            echo json_encode([
                'success' => true,
                'count'   => count($formatted),
                'logs'    => $formatted
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown audit action.']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Audit API error: ' . $e->getMessage()
    ]);
}
