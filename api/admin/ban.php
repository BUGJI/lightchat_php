<?php
/**
 * 用户封禁管理
 *
 * POST /api/admin/ban.php
 *   封禁:   {"action":"ban",   "user_id":1, "reason":"违规", "duration_hours":24}
 *   解封:   {"action":"unban", "user_id":1}
 * GET  /api/admin/ban.php?list=1   列出当前有效封禁
 *
 * duration_hours 省略或 <= 0 表示永久封禁。
 * 需要 admin.user.ban 权限。
 */

require_once __DIR__ . '/../bootstrap.php';

$user = authenticate();

if (!has_permission($user, 'admin.user.ban')) {
    json_response(403, ['error' => 'forbidden', 'message' => '您没有封禁管理权限']);
}

// ── 查询当前有效封禁 ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['list'])) {
    $allBans = $db->select('bans', [], '*', 'id DESC', 100);
    $now = time();
    $active = [];
    foreach ($allBans as $b) {
        $exp = isset($b['expires_at']) ? $b['expires_at'] : '';
        $expTs = ($exp !== '' && $exp !== null) ? strtotime($exp) : 0;
        if ($expTs === 0 || $expTs > $now) {
            $target = $db->get('users', ['id' => $b['user_id']]);
            $active[] = [
                'id'         => (int)$b['id'],
                'user_id'    => (int)$b['user_id'],
                'username'   => $target ? $target['username'] : '?',
                'reason'     => $b['reason'] ?? '',
                'expires_at' => $exp ?: null,
                'created_at' => $b['created_at'] ?? '',
            ];
        }
    }
    json_success(['bans' => $active, 'count' => count($active)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$input  = get_json_input();
$action = trim($input['action'] ?? '');
$targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;

if (!in_array($action, ['ban', 'unban'], true)) {
    json_response(400, ['error' => 'invalid_action', 'message' => 'action 必须为 ban 或 unban']);
}
if ($targetUserId <= 0) {
    json_response(400, ['error' => 'missing_fields', 'message' => 'user_id 不能为空']);
}

// ── 目标用户 ──
$target = $db->get('users', ['id' => $targetUserId]);
if (!$target) {
    json_response(404, ['error' => 'user_not_found', 'message' => '目标用户不存在']);
}
if ((int)$target['id'] === (int)$user['id']) {
    json_response(400, ['error' => 'cannot_ban_self', 'message' => '不能封禁自己']);
}

if ($action === 'ban') {
    $reason = trim($input['reason'] ?? '');
    $durationHours = isset($input['duration_hours']) ? (int)$input['duration_hours'] : 0;
    if ($durationHours > 0) {
        $expiresAt = date('Y-m-d H:i:s', time() + $durationHours * 3600);
    } else {
        $expiresAt = null; // 永久封禁
    }

    // 先删除已有封禁（避免重复）
    $db->delete('bans', ['user_id' => $targetUserId]);

    $banId = $db->insert('bans', [
        'user_id'    => $targetUserId,
        'admin_id'   => $user['id'],
        'reason'     => $reason,
        'expires_at' => $expiresAt,
    ]);

    // 审计日志
    $db->insert('audit_logs', [
        'user_id'     => $user['id'],
        'username'    => $user['username'],
        'action'      => 'user_ban',
        'target_type' => 'user',
        'target_id'   => $targetUserId,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'detail'      => json_encode(['username' => $target['username'], 'reason' => $reason, 'expires_at' => $expiresAt], JSON_UNESCAPED_UNICODE),
    ]);

    json_response(201, [
        'success'    => true,
        'ban_id'     => (int)$banId,
        'user_id'    => $targetUserId,
        'username'   => $target['username'],
        'expires_at' => $expiresAt,
        'message'    => $expiresAt ? "已封禁至 {$expiresAt}" : '已永久封禁',
    ]);
}

// ── unban ──
$deleted = $db->delete('bans', ['user_id' => $targetUserId]);

$db->insert('audit_logs', [
    'user_id'     => $user['id'],
    'username'    => $user['username'],
    'action'      => 'user_unban',
    'target_type' => 'user',
    'target_id'   => $targetUserId,
    'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'detail'      => json_encode(['username' => $target['username']], JSON_UNESCAPED_UNICODE),
]);

json_success([
    'success'  => true,
    'user_id'  => $targetUserId,
    'username' => $target['username'],
    'removed'  => $deleted > 0,
    'message'  => $deleted > 0 ? '已解除封禁' : '该用户未被封禁',
]);
