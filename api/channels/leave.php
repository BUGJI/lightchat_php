<?php
/**
 * 退出频道
 *
 * POST /api/channels/leave.php
 *
 * 请求体 JSON:
 *   channel_id  int  频道 ID
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

$input     = get_json_input();
$channelId = isset($input['channel_id']) ? (int)$input['channel_id'] : 0;

if ($channelId <= 0) {
    json_response(400, ['error' => 'invalid_channel', 'message' => '频道 ID 无效']);
}

// ── 查找频道 ──
$channel = $db->get('channels', ['id' => $channelId]);
if (!$channel) {
    json_response(404, ['error' => 'not_found', 'message' => '频道不存在']);
}

// ── 默认频道不可退出 ──
$systemChannelNames = ['general', 'announcements', 'help'];
if (in_array($channel['name'], $systemChannelNames) && !role_at_least($user['role'], 'admin')) {
    json_response(403, ['error' => 'cannot_leave', 'message' => '不能退出系统默认频道']);
}

// ── 检查是否已加入 ──
$membership = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);
if (!$membership) {
    json_response(400, ['error' => 'not_member', 'message' => '你不是该频道成员']);
}

// ── 退出 ──
try {
    $db->delete('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);

    // 更新频道成员数
    $newCount = $db->count('channel_members', ['channel_id' => $channelId]);
    $db->update('channels', ['member_count' => $newCount], ['id' => $channelId]);

    // 如果频道无人且不是系统频道，删除频道
    if ($newCount <= 0 && !in_array($channel['name'], $systemChannelNames)) {
        $db->delete('channels', ['id' => $channelId]);
        $db->delete('messages', ['channel_id' => $channelId]);
    }

    // 发送系统消息
    $db->insert('messages', [
        'channel_id' => $channelId,
        'user_id'    => 0,
        'type'       => 'system',
        'content'    => str_replace(
            ['{username}', '{channel}'],
            [$user['username'], $channel['display_name']],
            isset($config['notifications']['system_messages']['leave'])
                ? $config['notifications']['system_messages']['leave']
                : '{username} 离开了 {channel}'
        ),
    ]);
} catch (Exception $e) {
    json_response(500, ['error' => 'leave_failed', 'message' => '退出频道失败']);
}

json_success([], '已退出频道');
