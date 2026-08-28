<?php
/**
 * 标记频道已读
 *
 * POST /api/channels/read.php
 *
 * 请求体 JSON:
 *   channel_id  int  频道 ID
 *
 * 成功响应:
 *   success  bool
 *   unread_count  int  标记后的未读数（应为 0）
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

$input = get_json_input();
$channelId = isset($input['channel_id']) ? (int)$input['channel_id'] : 0;

if ($channelId <= 0) {
    json_response(400, ['error' => 'invalid_channel', 'message' => '频道 ID 无效']);
}

// 确认用户是该频道成员
$member = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);
if (!$member) {
    json_response(403, ['error' => 'not_member', 'message' => '您不是该频道成员']);
}

// 取当前频道最新消息 ID 作为已读位置
$latest = $db->select('messages', ['channel_id' => $channelId], 'id', 'id DESC', 1);
$lastReadId = !empty($latest) ? (int)$latest[0]['id'] : 0;

// 更新已读位置（LocalDriver 的 update 会为缺失字段补默认值）
$db->update('channel_members', ['last_read_message_id' => $lastReadId], [
    'channel_id' => $channelId,
    'user_id'    => $user['id'],
]);

json_success(['success' => true, 'last_read_message_id' => $lastReadId]);
