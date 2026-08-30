<?php
/**
 * 审计日志（合规留存）
 *
 * GET /api/admin/audit.php                  列出日志
 * GET /api/admin/audit.php?action=login     按操作筛选
 * GET /api/admin/audit.php?user_id=1        按用户筛选
 * GET /api/admin/audit.php?limit=100        分页
 * GET /api/admin/audit.php?export=1         导出 JSON（下载，分批流式全量）
 *
 * 需要 admin 权限
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

$user = authenticate();

if (!role_at_least($user['role'], 'admin')) {
    json_response(403, ['error' => 'forbidden', 'message' => '仅管理员可查看审计日志']);
}

// ── 读取参数 ──
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$export = isset($_GET['export']);

if ($limit < 1 || $limit > 500) $limit = 100;

// ── 构建查询条件（条件下推，避免全表拉进内存） ──
$where = [];
if ($action !== '') {
    $where['action'] = $action;
}
if ($userId > 0) {
    $where['user_id'] = $userId;
}

$total = $db->count('audit_logs', $where);

// ── 导出模式：分批流式输出全量，避免大表 OOM ──
if ($export) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.json"');
    $first = true;
    $cursor = 0;
    echo '[';
    while (true) {
        $batchWhere = $where;
        if ($cursor > 0) {
            $batchWhere['id <'] = $cursor;
        }
        $batch = $db->select('audit_logs', $batchWhere, '*', 'id DESC', 500);
        if (empty($batch)) {
            break;
        }
        foreach ($batch as $log) {
            if (!$first) {
                echo ',';
            }
            $first = false;
            echo json_encode($log, JSON_UNESCAPED_UNICODE);
            $cursor = (int)$log['id'];
        }
        if (count($batch) < 500) {
            break;
        }
    }
    echo ']';
    exit;
}

// ── 普通列表：limit 条（id DESC 快速路径） ──
$logs = $db->select('audit_logs', $where, '*', 'id DESC', $limit);

// 当前页的 action 统计
$actionCounts = [];
foreach ($logs as $log) {
    $a = $log['action'] ?? 'unknown';
    if (!isset($actionCounts[$a])) $actionCounts[$a] = 0;
    $actionCounts[$a]++;
}

json_success([
    'stats'  => [
        'total_logs'    => $total,
        'action_counts' => $actionCounts,
    ],
    'logs'   => $logs,
    'count'  => count($logs),
    'params' => [
        'action'  => $action ?: '(all)',
        'user_id' => $userId ?: '(all)',
        'limit'   => $limit,
    ],
]);
