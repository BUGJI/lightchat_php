<?php
/**
 * 频道列表
 *
 * GET /api/channels/list.php
 *
 * 响应:
 *   channels  array  频道列表（含 member_count、is_joined）
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

// ── 可选认证（游客也可查看公开频道） ──
$user = null;
$token = get_bearer_token();
if ($token !== '') {
    $session = $db->get('sessions', ['token' => $token]);
    if ($session && isset($session['expires_at']) && strtotime($session['expires_at']) < time()) {
        $db->delete('sessions', ['token' => $token]);
    } elseif ($session) {
        $user = $db->get('users', ['id' => $session['user_id']]);
        if ($user) {
            unset($user['password']);
            maybe_refresh_token($session, $user);
        }
    }
}

// ── 获取所有频道 ──
$channels = $db->select('channels', [], '*', 'id ASC');

// ── 统计成员数 & 当前用户是否已加入 ──
$joinedChannelIds = [];
$memberships = [];
if ($user) {
    $memberships = $db->select('channel_members', ['user_id' => $user['id']]);
    foreach ($memberships as $m) {
        $joinedChannelIds[] = (int)$m['channel_id'];
    }
}
// 已加入频道的已读位置（channel_id => last_read_message_id）
$lastReadMap = [];
foreach ($memberships as $m) {
    $lastReadMap[(int)$m['channel_id']] = isset($m['last_read_message_id']) ? (int)$m['last_read_message_id'] : 0;
}

$result = [];
foreach ($channels as $ch) {
    $memberCount = $db->count('channel_members', ['channel_id' => $ch['id']]);

    // 游客只能看到公开频道和公告频道
    if (!$user) {
        if (!in_array($ch['type'], ['public', 'announcement'])) {
            continue;
        }
    }

    // 未读数：当前用户已加入频道中，id 大于 last_read_message_id 的未删除消息数
    $unreadCount = 0;
    if ($user && isset($lastReadMap[(int)$ch['id']])) {
        $where = [
            'channel_id' => $ch['id'],
            'id > :id'   => $lastReadMap[(int)$ch['id']],
        ];
        if (!role_at_least($user['role'], 'admin')) {
            $where['is_deleted != '] = 1;
        }
        $unreadCount = $db->count('messages', $where);
    }

    $result[] = [
        'id'            => (int)$ch['id'],
        'name'          => $ch['name'],
        'display_name'  => $ch['display_name'],
        'type'          => $ch['type'],
        'description'   => $ch['description'] ?? '',
        'announcement'  => $ch['announcement'] ?? null,
        'owner_id'      => isset($ch['owner_id']) ? (int)$ch['owner_id'] : 0,
        'member_count'  => $memberCount,
        'is_joined'     => $user ? in_array((int)$ch['id'], $joinedChannelIds) : false,
        'unread_count'  => $unreadCount,
        'created_at'    => $ch['created_at'] ?? '',
    ];
}

json_success(['channels' => $result]);
