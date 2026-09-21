<?php
/**
 * 统一同步接口（短轮询，单次请求拿全：消息 + 频道列表 + 私聊列表）
 *
 * GET /api/sync.php?channels=1,2,3&since_id=100&private_chat_id=5&private_since_id=8&lists=1
 *
 * 参数:
 *   channels          string  频道 ID 列表（逗号分隔），可选
 *   since_id          int     频道消息游标（仅返回 id > 此值的消息）
 *   private_chat_id   int     私聊会话 ID，可选（与 channels 可同时传入）
 *   private_since_id  int     私聊消息游标（缺省回退到 since_id）
 *   lists             int     是否返回频道/私聊列表（默认 1；传 0 可只取消息）
 *
 * 说明:
 *   短轮询：单次执行立即返回，不使用长轮询/长连接。客户端定时（建议 3 秒）调用本接口，
 *   即可同时获得增量消息与侧边栏（未读数）数据，替代原来的 poll + channels/list + private/list 三次请求。
 *
 * 响应:
 *   messages           array  增量消息
 *   latest_id          int    频道侧最新游标
 *   private_latest_id  int    私聊侧最新游标
 *   channels           array  频道列表（含 unread_count / is_joined）
 *   private_chats      array  私聊会话列表（含 unread_count / dnd）
 */

require_once __DIR__ . '/bootstrap.php';

require_method('GET');

$user = authenticate();

$channelsStr    = isset($_GET['channels']) ? trim($_GET['channels']) : '';
$sinceId        = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$privateChatId  = isset($_GET['private_chat_id']) ? (int)$_GET['private_chat_id'] : 0;
$privateSinceId = isset($_GET['private_since_id']) ? (int)$_GET['private_since_id'] : $sinceId;
$withLists      = !isset($_GET['lists']) || (int)$_GET['lists'] !== 0;

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

// 私聊会话校验（仅参与者可同步该会话消息）
if ($privateChatId > 0) {
    $chat = $db->get('private_chats', ['id' => $privateChatId]);
    if (!$chat || ((int)$chat['user1_id'] !== (int)$user['id'] && (int)$chat['user2_id'] !== (int)$user['id'])) {
        json_response(403, ['error' => 'forbidden', 'message' => '你不在该私聊中']);
    }
}

$result = ChatService::messageUpdates($user, $channelIds, $sinceId, $privateChatId, $privateSinceId);

if ($withLists) {
    $result['channels']      = ChatService::channelList($user);
    $result['private_chats'] = ChatService::privateChatList($user);
}

json_success($result);
