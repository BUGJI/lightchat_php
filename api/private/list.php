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
    
    // 检查是否将对方加入黑名单，如果是则不显示该会话
    $blacklistCheck = $db->get('user_relations', [
        'user_id' => $user['id'],
        'target_user_id' => $otherUserId,
        'relation_type' => 'blacklist',
    ]);
    if ($blacklistCheck) {
        continue;
    }
    
    $otherUser   = $db->get('users', ['id' => $otherUserId]);

    // 统计未读数
    $unread = $db->count('private_messages', [
        'chat_id'      => $chat['id'],
        'to_user_id'   => $user['id'],
        'is_read'      => 0,
    ]);

    // 检查是否屏蔽了该用户的通知（支持 friend 和 blocked 两种类型）
    $muteCheck = $db->get('user_relations', [
        'user_id' => $user['id'],
        'target_user_id' => $otherUserId,
        'relation_type' => 'friend',
        'mute_notifications' => 1,
    ]);
    
    // 如果不是好友关系，检查是否有 blocked 类型的静音记录
    if (!$muteCheck) {
        $blockedMuteCheck = $db->get('user_relations', [
            'user_id' => $user['id'],
            'target_user_id' => $otherUserId,
            'relation_type' => 'blocked',
            'mute_notifications' => 1,
        ]);
        if ($blockedMuteCheck) {
            $muteCheck = $blockedMuteCheck;
        }
    }
    
    $isMuted = $muteCheck ? true : false;

    $chats[] = [
        'id'               => (int)$chat['id'],
        'other_user_id'    => $otherUserId,
        'other_username'   => $otherUser ? $otherUser['username'] : '未知用户',
        'other_avatar'     => $otherUser ? ($otherUser['avatar'] ?? null) : null,
        'other_role'       => $otherUser ? ($otherUser['role'] ?? 'member') : null,
        'last_message'     => $chat['last_message'] ?? '',
        'last_message_at'  => $chat['last_message_at'] ?? '',
        'unread_count'     => $unread,
        'is_muted'         => $isMuted,
        'created_at'       => $chat['created_at'] ?? '',
    ];
}

json_success(['chats' => $chats]);
