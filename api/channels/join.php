<?php
/**
 * 加入频道
 *
 * POST /api/channels/join.php
 *
 * 请求体 JSON:
 *   channel_id  int  频道 ID
 *
 * 成功响应:
 *   success     bool
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

// ── 读取输入 ──
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

// ── 检查是否已加入 ──
$existing = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);
if ($existing) {
    json_response(409, ['error' => 'already_joined', 'message' => '你已经是该频道成员']);
}

// ── 私密频道检查 ──
if ($channel['type'] === 'private') {
    json_response(403, ['error' => 'private_channel', 'message' => '私密频道需要邀请才能加入']);
}

// ── 检查加入数量限制 ──
$maxChannels = isset($config['chat']['channel']['max_per_user']) ? (int)$config['chat']['channel']['max_per_user'] : 10;
$joinedCount = $db->count('channel_members', ['user_id' => $user['id']]);
if ($joinedCount >= $maxChannels) {
    json_response(400, ['error' => 'limit_reached', 'message' => "你最多可加入 {$maxChannels} 个频道"]);
}

// ── 检查最大成员数 ──
$maxMembers = isset($config['chat']['channel']['max_members']) ? (int)$config['chat']['channel']['max_members'] : 500;
$currentCount = $db->count('channel_members', ['channel_id' => $channelId]);
if ($currentCount >= $maxMembers) {
    json_response(400, ['error' => 'channel_full', 'message' => '频道已满']);
}

// ── 加入频道 ──
try {
    $db->insert('channel_members', [
        'channel_id' => $channelId,
        'user_id'    => $user['id'],
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
            ['{username}', '{channel}'],
            [$user['username'], $channel['display_name']],
            isset($config['notifications']['system_messages']['welcome'])
                ? $config['notifications']['system_messages']['welcome']
                : '欢迎 {username} 加入 {channel}'
        ),
    ]);
} catch (Exception $e) {
    json_response(500, ['error' => 'join_failed', 'message' => '加入频道失败']);
}

json_success([], '已加入频道');
