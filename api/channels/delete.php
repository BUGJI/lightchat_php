<?php
/**
 * 解散频道（仅频道创建者 owner）
 *
 * POST /api/channels/delete.php
 *
 * 请求体 JSON:
 *   channel_id  int  频道 ID
 *
 * 说明: 删除频道及其全部消息、成员关系（不可恢复）。系统默认频道不可解散。
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

$channel = $db->get('channels', ['id' => $channelId]);
if (!$channel) {
    json_response(404, ['error' => 'not_found', 'message' => '频道不存在']);
}

$systemChannelNames = ['general', 'announcements', 'help'];
if (in_array($channel['name'], $systemChannelNames)) {
    json_response(403, ['error' => 'system_channel', 'message' => '系统默认频道不允许解散']);
}

// 仅频道创建者可解散
$isOwner = (isset($channel['owner_id']) && (int)$channel['owner_id'] === (int)$user['id']);
if (!$isOwner) {
    $membership = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);
    $isOwner = $membership && $membership['role'] === 'owner';
}
if (!$isOwner) {
    json_response(403, ['error' => 'not_owner', 'message' => '只有频道创建者可以解散频道']);
}

try {
    $db->delete('messages', ['channel_id' => $channelId]);
    $db->delete('channel_members', ['channel_id' => $channelId]);
    $db->delete('channels', ['id' => $channelId]);
} catch (Exception $e) {
    json_response(500, ['error' => 'delete_failed', 'message' => '频道解散失败']);
}

json_success([], '频道已解散');
