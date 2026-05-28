<?php
/**
 * 获取私聊消息历史
 *
 * GET /api/private/history.php?chat_id=1&before=100&limit=50
 *
 * 参数:
 *   chat_id  int  私聊会话 ID
 *   before   int  消息 ID（获取此 ID 之前的消息，可选）
 *   limit    int  返回数量（默认 50，最大 200）
 *
 * 响应:
 *   messages  array
 *   has_more  bool
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

$user   = authenticate();
$chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
$before = isset($_GET['before']) ? (int)$_GET['before'] : 0;
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

if ($chatId <= 0) {
    json_response(400, ['error' => 'invalid_chat', 'message' => '私聊会话 ID 无效']);
}
if ($limit < 1 || $limit > 200) {
    $limit = 50;
}

// ── 检查会话是否存在 & 用户是否参与者 ──
$chat = $db->get('private_chats', ['id' => $chatId]);
if (!$chat) {
    json_response(404, ['error' => 'not_found', 'message' => '私聊会话不存在']);
}

$uid1 = (int)$chat['user1_id'];
$uid2 = (int)$chat['user2_id'];
if ($user['id'] !== $uid1 && $user['id'] !== $uid2) {
    json_response(403, ['error' => 'forbidden', 'message' => '你不在该私聊中']);
}

// ── 获取消息 ──
$where = ['chat_id' => $chatId];

// ── 获取消息（id ASC = 旧→新），取最新一批 ──
$allMsgs = $db->select('private_messages', $where, '*', 'id ASC');
$total = count($allMsgs);
$start = max(0, $total - $limit - 1);
$msgs = array_slice($allMsgs, $start, $limit + 1);

$hasMore = count($msgs) > $limit;
if ($hasMore) {
    array_pop($msgs);
}

// ── 过滤已删除 ──
$filtered = [];
foreach ($msgs as $msg) {
    if (isset($msg['is_deleted']) && (int)$msg['is_deleted'] === 1) continue;
    $filtered[] = $msg;
}

// ── 组装用户缓存 ──
$userCache = [];
foreach ([$uid1, $uid2] as $uid) {
    $u = $db->get('users', ['id' => $uid]);
    if ($u) {
        $userCache[$uid] = [
            'id'       => (int)$u['id'],
            'username' => $u['username'],
            'avatar'   => $u['avatar'] ?? null,
        ];
    }
}

// ── 格式化 ──
$messages = [];
foreach ($filtered as $msg) {
    $sender = isset($userCache[(int)$msg['from_user_id']]) ? $userCache[(int)$msg['from_user_id']] : null;

    $messages[] = [
        'id'           => (int)$msg['id'],
        'chat_id'      => (int)$msg['chat_id'],
        'from_user_id' => (int)$msg['from_user_id'],
        'to_user_id'   => (int)$msg['to_user_id'],
        'username'     => $sender ? $sender['username'] : '未知用户',
        'avatar'       => $sender ? $sender['avatar'] : null,
        'content'      => $msg['content'] ?? '',
        'is_read'      => isset($msg['is_read']) ? (int)$msg['is_read'] : 0,
        'created_at'   => $msg['created_at'] ?? '',
    ];

    // 标记为已读
    if ((int)$msg['to_user_id'] === $user['id'] && (int)$msg['is_read'] === 0) {
        $db->update('private_messages', ['is_read' => 1], ['id' => $msg['id']]);
    }
}

json_success([
    'messages' => $messages,
    'has_more' => $hasMore,
]);
