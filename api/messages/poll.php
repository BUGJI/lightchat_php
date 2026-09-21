<?php
/**
 * 消息轮询（短轮询，单次执行立即返回，不使用长轮询/长连接）
 *
 * GET /api/messages/poll.php?channels=1,2,3&since_id=100&private_chat_id=0&private_since_id=8
 *
 * 说明:
 *   本接口单次执行立即返回，不挂起等待。客户端应定时（建议 2~3 秒）调用。
 *   频道消息在单次聚合查询中完成（channel_id IN (...) + id > 游标）。
 *   需要「消息 + 频道列表 + 私聊列表」一次拿全时，请使用 /api/sync.php。
 *
 * 响应:
 *   messages           array  新消息列表
 *   latest_id          int    频道侧最新游标
 *   private_latest_id  int    私聊侧最新游标
 */

require_once __DIR__ . '/../bootstrap.php';

require_method('GET');

$user = authenticate();

$channelsStr    = isset($_GET['channels']) ? trim($_GET['channels']) : '';
$sinceId        = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$privateChatId  = isset($_GET['private_chat_id']) ? (int)$_GET['private_chat_id'] : 0;
$privateSinceId = isset($_GET['private_since_id']) ? (int)$_GET['private_since_id'] : $sinceId;

$channelIds = [];
if ($channelsStr !== '') {
    foreach (explode(',', $channelsStr) as $p) {
        $id = (int)trim($p);
        if ($id > 0) {
            $channelIds[] = $id;
        }
    }
    $channelIds = array_values(array_unique($channelIds));
}

// 私聊会话校验（仅参与者可轮询）
if ($privateChatId > 0) {
    $chat = $db->get('private_chats', ['id' => $privateChatId]);
    if (!$chat || ((int)$chat['user1_id'] !== (int)$user['id'] && (int)$chat['user2_id'] !== (int)$user['id'])) {
        json_response(403, ['error' => 'forbidden', 'message' => '你不在该私聊中']);
    }
}

$data = ChatService::messageUpdates($user, $channelIds, $sinceId, $privateChatId, $privateSinceId);

json_success($data);
