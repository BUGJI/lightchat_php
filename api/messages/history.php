<?php
/**
 * 获取频道消息历史
 *
 * GET /api/messages/history.php?channel_id=1&before=100&limit=50
 *
 * 参数:
 *   channel_id  int     频道 ID
 *   before      int     消息 ID（获取此 ID 之前的消息，可选）
 *   after       int     消息 ID（获取此 ID 之后的消息，可选）
 *   limit       int     返回数量（默认 50，最大 200）
 *
 * 响应:
 *   messages    array
 *   has_more    bool
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

$user = authenticate();

// ── 读取参数 ──
$channelId = isset($_GET['channel_id']) ? (int)$_GET['channel_id'] : 0;
$before    = isset($_GET['before']) ? (int)$_GET['before'] : 0;
$after     = isset($_GET['after']) ? (int)$_GET['after'] : 0;
$limit     = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

if ($channelId <= 0) {
    json_response(400, ['error' => 'invalid_channel', 'message' => '频道 ID 无效']);
}

if ($limit < 1 || $limit > 200) {
    $limit = 50;
}

// ── 检查频道是否存在 ──
$channel = $db->get('channels', ['id' => $channelId]);
if (!$channel) {
    json_response(404, ['error' => 'not_found', 'message' => '频道不存在']);
}

// ── 检查访问权限 ──
$isMember = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);
if (!$isMember && $channel['type'] !== 'public') {
    json_response(403, ['error' => 'forbidden', 'message' => '你没有权限查看此频道']);
}

// ── 获取消息 ──
$where = ['channel_id' => $channelId];

// 排除已删除消息（非管理员看不到；is_deleted=1 表示已删除）
if (!role_at_least($user['role'], 'admin')) {
    $where['is_deleted != '] = 1;
}

// ── 获取消息：id DESC 快速路径（LocalDriver 倒序提前终止，避免全量扫描） ──
// 取 limit+1 条用于判断 has_more，再反转回 id ASC（旧→新）
$msgs = $db->select('messages', $where, '*', 'id DESC', $limit + 1);

if ($after > 0) {
    // 获取 after 之后的新消息：先取足够大的 DESC 窗口再过滤
    $msgs = [];
    $allRecent = $db->select('messages', $where, '*', 'id DESC', 200);
    foreach ($allRecent as $m) {
        if ((int)$m['id'] > $after) {
            $msgs[] = $m;
        }
    }
    $msgs = array_slice($msgs, 0, $limit + 1);
} else {
    // 默认：取最新的一批
    $msgs = $db->select('messages', $where, '*', 'id DESC', $limit + 1);
}

// ── 分页处理 ──
$hasMore = count($msgs) > $limit;
if ($hasMore) {
    array_pop($msgs); // 去掉多取的那条
}

// 反转回 id ASC（旧→新），保持原响应顺序
$msgs = array_reverse($msgs);

// ── 组装用户信息（批量获取发消息的用户） ──
$userIds = [];
foreach ($msgs as $msg) {
    if ((int)$msg['user_id'] > 0) {
        $userIds[(int)$msg['user_id']] = true;
    }
}
$userCache = [];
foreach (array_keys($userIds) as $uid) {
    $u = $db->get('users', ['id' => $uid]);
    if ($u) {
        $userCache[$uid] = [
            'id'       => (int)$u['id'],
            'username' => $u['username'],
            'avatar'   => $u['avatar'] ?? null,
            'role'     => $u['role'] ?? 'member',
        ];
    }
}

// ── 格式化消息（附上用户信息） ──
$messages = [];
foreach ($msgs as $msg) {
    $sender = isset($userCache[(int)$msg['user_id']]) ? $userCache[(int)$msg['user_id']] : null;

    $messages[] = [
        'id'              => (int)$msg['id'],
        'channel_id'      => (int)$msg['channel_id'],
        'user_id'         => (int)$msg['user_id'],
        'username'        => $sender ? $sender['username'] : '系统',
        'avatar'          => $sender ? $sender['avatar'] : null,
        'role'            => $sender ? $sender['role'] : null,
        'parent_id'       => isset($msg['parent_id']) ? (int)$msg['parent_id'] : 0,
        'type'            => $msg['type'] ?? 'text',
        'content'         => $msg['content'] ?? '',
        'file_url'        => $msg['file_url'] ?? null,
        'file_size'       => isset($msg['file_size']) ? (int)$msg['file_size'] : null,
        'mentioned_users' => $msg['mentioned_users'] ?? null,
        'is_deleted'      => isset($msg['is_deleted']) ? (int)$msg['is_deleted'] : 0,
        'created_at'      => $msg['created_at'] ?? '',
    ];
}

json_success([
    'messages' => $messages,
    'has_more' => $hasMore,
]);
