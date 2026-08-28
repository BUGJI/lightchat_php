<?php
/**
 * 查找或创建私聊会话（不发送消息）
 *
 * POST /api/private/create.php
 *
 * 请求体 JSON:
 *   to_user_id  int  接收方用户 ID
 *
 * 成功响应:
 *   chat_id   int
 *   created   bool  是否新建
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

$input = get_json_input();
$toUserId = isset($input['to_user_id']) ? (int)$input['to_user_id'] : 0;

if ($toUserId <= 0) {
    json_response(400, ['error' => 'invalid_user', 'message' => '接收方用户 ID 无效']);
}
if ($toUserId === $user['id']) {
    json_response(400, ['error' => 'self_message', 'message' => '不能和自己私聊']);
}

$toUser = $db->get('users', ['id' => $toUserId]);
if (!$toUser) {
    json_response(404, ['error' => 'not_found', 'message' => '接收方用户不存在']);
}

// 查找或创建会话（与 send.php 相同的会话定位规则）
$u1 = min($user['id'], $toUserId);
$u2 = max($user['id'], $toUserId);
$chat = $db->get('private_chats', ['user1_id' => $u1, 'user2_id' => $u2]);

if ($chat) {
    json_success(['chat_id' => (int)$chat['id'], 'created' => false]);
}

$chatId = $db->insert('private_chats', [
    'user1_id'        => $u1,
    'user2_id'        => $u2,
    'last_message'    => null,
    'last_message_at' => null,
]);

json_response(201, ['success' => true, 'chat_id' => $chatId, 'created' => true]);
