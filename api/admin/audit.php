<?php
/**
 * 审计日志（合规留存）
 *
 * GET /api/admin/audit.php                  列出日志
 * GET /api/admin/audit.php?action=login     按操作筛选
 * GET /api/admin/audit.php?user_id=1        按用户筛选
 * GET /api/admin/audit.php?limit=100        分页
 * GET /api/admin/audit.php?export=1         导出 JSON（下载）
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

// ── 查询日志 ──
$logs = $db->select('audit_logs', [], '*', 'id DESC', 0);

// 过滤
$filtered = [];
foreach ($logs as $log) {
    if ($action !== '' && ($log['action'] ?? '') !== $action) continue;
    if ($userId > 0 && (int)($log['user_id'] ?? 0) !== $userId) continue;
    $filtered[] = $log;
    if (count($filtered) >= $limit) break;
}

// ── 导出模式 ──
if ($export) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.json"');
    echo json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── 统计摘要 ──
$stats = [
    'total_logs'    => count($logs),
    'action_counts' => [],
];
foreach ($logs as $log) {
    $a = $log['action'] ?? 'unknown';
    if (!isset($stats['action_counts'][$a])) $stats['action_counts'][$a] = 0;
    $stats['action_counts'][$a]++;
}

json_success([
    'stats'  => $stats,
    'logs'   => $filtered,
    'count'  => count($filtered),
    'params' => [
        'action'  => $action ?: '(all)',
        'user_id' => $userId ?: '(all)',
        'limit'   => $limit,
    ],
]);
