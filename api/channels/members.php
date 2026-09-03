<?php
/**
 * 获取频道成员列表
 *
 * GET /api/channels/members.php?channel_id=1
 *
 * 成功响应:
 *   success     bool
 *   channel     { id, display_name, type, member_count, owner_id }
 *   members     [{ user_id, username, nickname, role }]
 *
 * 权限:
 *   - 私密频道: 仅成员可查看名单（非成员只能从 list.php 看到人数）
 *   - 公开频道: 登录用户可查看
 */

require_once __DIR__ . '/../bootstrap.php';

$user = authenticate();

$channelId = isset($_GET['channel_id']) ? (int)$_GET['channel_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = get_json_input();
    $channelId = isset($input['channel_id']) ? (int)$input['channel_id'] : $channelId;
}
if ($channelId <= 0) {
    json_response(400, ['error' => 'invalid_channel', 'message' => '频道 ID 无效']);
}

$channel = $db->get('channels', ['id' => $channelId]);
if (!$channel) {
    json_response(404, ['error' => 'not_found', 'message' => '频道不存在']);
}

// 私密频道：仅成员可查看成员名单
if ($channel['type'] === 'private') {
    $membership = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);
    if (!$membership) {
        json_response(403, ['error' => 'not_member', 'message' => '私密频道仅成员可查看成员名单']);
    }
}

$rows = $db->select('channel_members', ['channel_id' => $channelId], '*', 'id ASC', 0);
$members = [];
foreach ($rows as $row) {
    $u = $db->get('users', ['id' => (int)$row['user_id']]);
    if (!$u) continue;
    $members[] = [
        'user_id'  => (int)$u['id'],
        'username' => $u['username'],
        'nickname' => $u['nickname'] ?? '',
        'avatar'   => $u['avatar'] ?? '',
        'role'     => $row['role'] === 'owner' ? 'owner' : 'member',
    ];
}

json_success([
    'channel' => [
        'id'           => (int)$channel['id'],
        'display_name' => $channel['display_name'],
        'type'         => $channel['type'],
        'member_count' => (int)$channel['member_count'],
        'owner_id'     => isset($channel['owner_id']) ? (int)$channel['owner_id'] : 0,
    ],
    'members' => $members,
]);
