<?php
/**
 * 私聊联系人元数据（按会话 + 用户维度：备注 / 免打扰 / 删除好友）
 *
 * GET  /api/private/contact.php?chat_id=1
 *   返回当前用户对该会话的设置 { chat_id, nickname, dnd, hidden }
 *
 * POST /api/private/contact.php
 *   body: { chat_id, action: "update", nickname?: "备注名", dnd?: 0|1 }
 *         { chat_id, action: "delete" }      // 删除好友=隐藏会话（对方再发消息时自动恢复）
 *
 * 语义：
 *   - 备注 nickname：仅本端可见，空字符串 = 清除
 *   - 免打扰 dnd：仅本端生效，前端列表页不震动提醒（红点仍计）
 *   - 删除好友 delete：从自己的会话列表移除；对方在此之后发来新消息，
 *     列表会自动恢复该会话（避免"删除=拉黑"错过消息）
 */

require_once __DIR__ . '/../bootstrap.php';

$user = authenticate();
$db   = Database::getInstance();
$chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = get_json_input();
    $chatId = isset($input['chat_id']) ? (int)$input['chat_id'] : 0;
}

if ($chatId <= 0) {
    json_response(400, ['error' => 'invalid_chat', 'message' => '私聊会话 ID 无效']);
}

// 必须是会话参与者
$chat = $db->get('private_chats', ['id' => $chatId]);
if (!$chat) {
    json_response(404, ['error' => 'not_found', 'message' => '私聊会话不存在']);
}
$uid = (int)$user['id'];
$isParticipant = ((int)$chat['user1_id'] === $uid || (int)$chat['user2_id'] === $uid);
if (!$isParticipant) {
    json_response(403, ['error' => 'forbidden', 'message' => '你不是该会话的参与者']);
}

// ── GET：读取本端 meta ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $meta = $db->get('private_contact_meta', ['chat_id' => $chatId, 'user_id' => $uid]);
    json_success([
        'chat_id'  => $chatId,
        'nickname' => $meta ? ($meta['nickname'] ?? '') : '',
        'dnd'      => $meta ? (int)($meta['dnd'] ?? 0) : 0,
        'hidden'   => $meta ? (int)($meta['hidden'] ?? 0) : 0,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET/POST 请求']);
}

$action = isset($input['action']) ? trim((string)$input['action']) : '';
$meta = $db->get('private_contact_meta', ['chat_id' => $chatId, 'user_id' => $uid]);

if ($action === 'update') {
    $updates = [];
    if (array_key_exists('nickname', $input)) {
        $nickname = trim((string)$input['nickname']);
        if (mb_strlen($nickname) > 30) {
            json_response(400, ['error' => 'nickname_too_long', 'message' => '备注最长 30 个字符']);
        }
        $updates['nickname'] = $nickname === '' ? null : $nickname;
    }
    if (array_key_exists('dnd', $input)) {
        $updates['dnd'] = $input['dnd'] ? 1 : 0;
    }
    if (!$updates) {
        json_response(400, ['error' => 'nothing_to_update', 'message' => '没有可更新的字段']);
    }
    $updates['updated_at'] = date('Y-m-d H:i:s');
    if ($meta) {
        $db->update('private_contact_meta', $updates, ['chat_id' => $chatId, 'user_id' => $uid]);
    } else {
        $db->insert('private_contact_meta', array_merge([
            'chat_id' => $chatId,
            'user_id' => $uid,
            'dnd'     => 0,
            'hidden'  => 0,
        ], $updates));
    }
    json_success(['success' => true, 'chat_id' => $chatId]);
}

if ($action === 'delete') {
    $now = date('Y-m-d H:i:s');
    if ($meta) {
        $db->update('private_contact_meta', ['hidden' => 1, 'hidden_at' => $now, 'updated_at' => $now], ['chat_id' => $chatId, 'user_id' => $uid]);
    } else {
        $db->insert('private_contact_meta', [
            'chat_id'   => $chatId,
            'user_id'   => $uid,
            'nickname'  => null,
            'dnd'       => 0,
            'hidden'    => 1,
            'hidden_at' => $now,
        ]);
    }
    json_success(['success' => true, 'chat_id' => $chatId, 'hidden' => true]);
}

json_response(400, ['error' => 'invalid_action', 'message' => '未知操作']);
