<?php
/**
 * 私聊会话列表
 *
 * GET /api/private/list.php
 *
 * 响应:
 *   chats  array  私聊会话列表（含对方用户信息 & 最后一条消息）
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

$user = authenticate();

// ── 获取所有参与的私聊 ──
$allChats = $db->select('private_chats', [], '*', 'last_message_at DESC');

$chats = [];
foreach ($allChats as $chat) {
    $uid1 = (int)$chat['user1_id'];
    $uid2 = (int)$chat['user2_id'];

    if ($uid1 !== $user['id'] && $uid2 !== $user['id']) {
        continue;
    }

    $otherUserId = ($uid1 === $user['id']) ? $uid2 : $uid1;
    $otherUser   = $db->get('users', ['id' => $otherUserId]);

    // 本端联系人元数据（备注/免打扰/删除）
    $meta = $db->get('private_contact_meta', ['chat_id' => (int)$chat['id'], 'user_id' => $user['id']]);
    $hidden = $meta ? (int)($meta['hidden'] ?? 0) : 0;
    if ($hidden) {
        // 删除好友=隐藏会话；若对方在删除之后又发来新消息则自动恢复（避免错过消息）
        $lastAt = $chat['last_message_at'] ?? '';
        $hiddenAt = $meta['hidden_at'] ?? '';
        if (!$lastAt || !$hiddenAt || strcmp($lastAt, $hiddenAt) <= 0) {
            continue; // 对方删除后无新消息 → 会话从自己列表消失
        }
        // 有新消息 → 恢复显示并清除隐藏标记
        $db->update('private_contact_meta', ['hidden' => 0, 'hidden_at' => null, 'updated_at' => date('Y-m-d H:i:s')],
            ['chat_id' => (int)$chat['id'], 'user_id' => $user['id']]);
    }

    // 统计未读数
    $unread = $db->count('private_messages', [
        'chat_id'      => $chat['id'],
        'to_user_id'   => $user['id'],
        'is_read'      => 0,
    ]);

    $otherUsername = $otherUser ? $otherUser['username'] : '未知用户';
    $nickname = $meta ? trim((string)($meta['nickname'] ?? '')) : '';

    $chats[] = [
        'id'                 => (int)$chat['id'],
        'other_user_id'      => $otherUserId,
        'other_username'     => $otherUsername,
        'other_display_name' => $nickname !== '' ? $nickname : $otherUsername,
        'other_nickname'     => $nickname,
        'other_avatar'       => $otherUser ? ($otherUser['avatar'] ?? null) : null,
        'other_role'         => $otherUser ? ($otherUser['role'] ?? 'member') : null,
        'dnd'                => $meta ? (int)($meta['dnd'] ?? 0) : 0,
        'last_message'       => $chat['last_message'] ?? '',
        'last_message_at'    => $chat['last_message_at'] ?? '',
        'unread_count'       => $unread,
        'created_at'         => $chat['created_at'] ?? '',
    ];
}

json_success(['chats' => $chats]);
