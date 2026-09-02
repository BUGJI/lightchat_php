<?php
/**
 * 邀请用户加入私密频道
 *
 * POST /api/channels/invite.php
 *
 * 请求体 JSON:
 *   channel_id  int     频道 ID（必填）
 *   username    string  被邀请用户名（与 user_id 二选一）
 *   user_id     int     被邀请用户 ID（与 username 二选一）
 *
 * 成功响应:
 *   success     bool
 *   channel_id  int
 *
 * 说明:
 *   - 仅私密频道支持邀请（公开频道可直接加入）
 *   - 邀请者必须是该频道现有成员
 *   - 被邀请者自动成为成员，之后可在自己的频道列表中看到该频道
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

// ── 读取输入 ──
$input     = get_json_input();
$channelId = isset($input['channel_id']) ? (int)$input['channel_id'] : 0;
$username  = isset($input['username']) ? trim($input['username']) : '';
$targetId  = isset($input['user_id']) ? (int)$input['user_id'] : 0;

if ($channelId <= 0) {
    json_response(400, ['error' => 'invalid_channel', 'message' => '频道 ID 无效']);
}
if ($username === '' && $targetId <= 0) {
    json_response(400, ['error' => 'invalid_target', 'message' => '请提供被邀请用户的用户名或 ID']);
}

// ── 查找频道 ──
$channel = $db->get('channels', ['id' => $channelId]);
if (!$channel) {
    json_response(404, ['error' => 'not_found', 'message' => '频道不存在']);
}

// ── 仅私密频道可邀请 ──
if ($channel['type'] !== 'private') {
    json_response(400, ['error' => 'not_private', 'message' => '公开频道可直接加入，无需邀请']);
}

// ── 邀请者必须是频道成员 ──
$inviter = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);
if (!$inviter) {
    json_response(403, ['error' => 'not_member', 'message' => '只有频道成员才能邀请他人加入']);
}

// ── 解析被邀请用户 ──
if ($targetId > 0) {
    $target = $db->get('users', ['id' => $targetId]);
} else {
    $target = $db->get('users', ['username' => $username]);
}
if (!$target) {
    json_response(404, ['error' => 'user_not_found', 'message' => '用户不存在']);
}
if (isset($target['status']) && (int)$target['status'] !== 1) {
    json_response(400, ['error' => 'user_disabled', 'message' => '该用户已禁用，无法邀请']);
}
if ((int)$target['id'] === (int)$user['id']) {
    json_response(400, ['error' => 'invite_self', 'message' => '不能邀请自己']);
}

// ── 是否已在频道 ──
$targetId = (int)$target['id'];
$existing = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $targetId]);
if ($existing) {
    json_response(409, ['error' => 'already_member', 'message' => '该用户已是频道成员']);
}

// ── 检查受邀者频道数量限制 ──
$maxChannels = isset($config['chat']['channel']['max_per_user']) ? (int)$config['chat']['channel']['max_per_user'] : 10;
$joinedCount = $db->count('channel_members', ['user_id' => $targetId]);
if ($joinedCount >= $maxChannels) {
    json_response(400, ['error' => 'limit_reached', 'message' => "对方已加入 {$maxChannels} 个频道，无法继续邀请"]);
}

// ── 检查频道成员上限 ──
$maxMembers = isset($config['chat']['channel']['max_members']) ? (int)$config['chat']['channel']['max_members'] : 500;
$currentCount = $db->count('channel_members', ['channel_id' => $channelId]);
if ($currentCount >= $maxMembers) {
    json_response(400, ['error' => 'channel_full', 'message' => '频道成员已满']);
}

// ── 加入频道 ──
try {
    $db->insert('channel_members', [
        'channel_id' => $channelId,
        'user_id'    => $targetId,
        'role'       => 'member',
    ]);

    // 更新频道成员数
    $newCount = $db->count('channel_members', ['channel_id' => $channelId]);
    $db->update('channels', ['member_count' => $newCount], ['id' => $channelId]);

    // 发送系统消息
    $db->insert('messages', [
        'channel_id' => $channelId,
        'user_id'    => 0,   // 系统消息
        'type'       => 'system',
        'content'    => str_replace(
            ['{inviter}', '{username}', '{channel}'],
            [$user['username'], $target['username'], $channel['display_name']],
            isset($config['notifications']['system_messages']['invite'])
                ? $config['notifications']['system_messages']['invite']
                : '{inviter} 邀请 {username} 加入了 {channel}'
        ),
    ]);
} catch (Exception $e) {
    json_response(500, ['error' => 'invite_failed', 'message' => '邀请失败']);
}

json_success(['channel_id' => (int)$channelId, 'user_id' => $targetId], '已邀请 ' . $target['username'] . ' 加入频道');
