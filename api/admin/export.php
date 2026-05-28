<?php
/**
 * 导出聊天记录（合规留存）
 *
 * GET /api/admin/export.php?type=messages&format=json
 * GET /api/admin/export.php?type=private_messages&format=json
 * GET /api/admin/export.php?type=all&format=json
 *
 * 需要 admin 权限
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

$user = authenticate();

if (!role_at_least($user['role'], 'admin')) {
    json_response(403, ['error' => 'forbidden', 'message' => '仅管理员可导出审计日志']);
}

$type   = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$format = isset($_GET['format']) ? trim($_GET['format']) : 'json';

// ── 查询 ──
$data = [];

if ($type === 'all' || $type === 'messages') {
    $raw = $db->select('messages', [], '*', 'id ASC');
    $messages = [];
    foreach ($raw as $m) {
        $sender = $db->get('users', ['id' => $m['user_id']]);
        $messages[] = [
            'id'          => (int)$m['id'],
            'channel_id'  => (int)$m['channel_id'],
            'sender_id'   => (int)$m['user_id'],
            'sender_name' => $sender ? $sender['username'] : '系统',
            'type'        => $m['type'] ?? 'text',
            'content'     => $m['content'] ?? '',
            'file_url'    => $m['file_url'] ?? null,
            'is_deleted'  => isset($m['is_deleted']) ? (int)$m['is_deleted'] : 0,
            'created_at'  => $m['created_at'] ?? '',
        ];
    }
    $data['channel_messages'] = $messages;
}

if ($type === 'all' || $type === 'private_messages') {
    $raw = $db->select('private_messages', [], '*', 'id ASC');
    $private = [];
    foreach ($raw as $pm) {
        $from = $db->get('users', ['id' => $pm['from_user_id']]);
        $to   = $db->get('users', ['id' => $pm['to_user_id']]);
        $private[] = [
            'id'            => (int)$pm['id'],
            'chat_id'       => (int)$pm['chat_id'],
            'from_user_id'  => (int)$pm['from_user_id'],
            'from_username' => $from ? $from['username'] : '?',
            'to_user_id'    => (int)$pm['to_user_id'],
            'to_username'   => $to ? $to['username'] : '?',
            'content'       => $pm['content'] ?? '',
            'is_deleted'    => isset($pm['is_deleted']) ? (int)$pm['is_deleted'] : 0,
            'created_at'    => $pm['created_at'] ?? '',
        ];
    }
    $data['private_messages'] = $private;
}

if ($type === 'all' || $type === 'users') {
    $users = $db->select('users', [], '*', 'id ASC');
    $userList = [];
    foreach ($users as $u) {
        $userList[] = [
            'id'          => (int)$u['id'],
            'username'    => $u['username'],
            'email'       => $u['email'] ?? '',
            'contact'     => $u['contact'] ?? '',
            'reg_ip'      => $u['reg_ip'] ?? '',
            'role'        => $u['role'] ?? 'member',
            'status'      => (int)($u['status'] ?? 1),
            'last_active' => $u['last_active_at'] ?? '',
            'created_at'  => $u['created_at'] ?? '',
        ];
    }
    $data['users'] = $userList;
}

if ($type === 'all' || $type === 'sessions') {
    $sessions = $db->select('sessions', [], '*', 'id ASC');
    $sessionList = [];
    foreach ($sessions as $s) {
        $su = $db->get('users', ['id' => $s['user_id']]);
        $sessionList[] = [
            'id'         => (int)$s['id'],
            'user_id'    => (int)$s['user_id'],
            'username'   => $su ? $su['username'] : '?',
            'ip'         => $s['ip'] ?? '',
            'user_agent' => $s['user_agent'] ?? '',
            'expires_at' => $s['expires_at'] ?? '',
            'created_at' => $s['created_at'] ?? '',
        ];
    }
    $data['sessions'] = $sessionList;
}

// ── 输出 ──
$filename = 'lightchat_export_' . $type . '_' . date('Ymd_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Export-Count: ' . count($data));
echo json_encode([
    'export_type' => $type,
    'exported_at' => date('Y-m-d H:i:s'),
    'data'        => $data,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
