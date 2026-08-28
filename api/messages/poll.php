<?php
/**
 * 消息轮询（支持长轮询）
 *
 * GET /api/messages/poll.php?channels=1,2,3&since_id=100&private_chat_id=0&timeout=25
 *
 * 参数:
 *   channels        string  频道 ID 列表（逗号分隔），必填
 *   since_id        int     上一次拉取到的最大消息 ID（不含此 ID），可选
 *   private_chat_id int     私聊 ID（可选，与 channels 二选一或同时）
 *   timeout         int     长轮询超时秒数（默认 25，最大 30）
 *
 * 响应:
 *   messages        array   新消息列表
 *   latest_id       int     当前最新消息 ID
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

$user = authenticate();

// ── 读取参数 ──
$channelsStr   = isset($_GET['channels']) ? trim($_GET['channels']) : '';
$sinceId       = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$privateChatId = isset($_GET['private_chat_id']) ? (int)$_GET['private_chat_id'] : 0;
$timeout       = isset($_GET['timeout']) ? (int)$_GET['timeout'] : 25;

if ($timeout < 1 || $timeout > 30) {
    $timeout = 25;
}
if ($timeout > 30) {
    $timeout = 30;
}

// ── 解析频道列表 ──
$channelIds = [];
if ($channelsStr !== '') {
    $parts = explode(',', $channelsStr);
    foreach ($parts as $p) {
        $id = (int)trim($p);
        if ($id > 0) {
            $channelIds[] = $id;
        }
    }
}

// ── 长轮询循环 ──
$waitIntervalMs = 2000000; // 2 秒微秒
$maxCycles      = max(1, intval(($timeout * 1000000) / $waitIntervalMs));
$cycle          = 0;

while ($cycle <= $maxCycles) {
    $newMessages = [];

    // ── 检查频道新消息 ──
    if (!empty($channelIds)) {
        // 验证频道访问权限
        $visibleChannelIds = [];
        foreach ($channelIds as $cid) {
            $member = $db->get('channel_members', ['channel_id' => $cid, 'user_id' => $user['id']]);
            if ($member) {
                $visibleChannelIds[] = $cid;
            } else {
                // 检查是否是公开频道
                $ch = $db->get('channels', ['id' => $cid]);
                if ($ch && in_array($ch['type'], ['public', 'announcement'])) {
                    $visibleChannelIds[] = $cid;
                }
            }
        }

        // 获取每个频道的消息
        foreach ($visibleChannelIds as $cid) {
            $where = ['channel_id' => $cid];
            if (!role_at_least($user['role'], 'admin')) {
                $where['is_deleted != '] = 1;
            }
            $allMsgs = $db->select('messages', $where, '*', 'id DESC', 50);
            foreach ($allMsgs as $msg) {
                if ((int)$msg['id'] > $sinceId && (int)$msg['user_id'] !== $user['id']) {
                    // 附用户信息
                    $sender = $db->get('users', ['id' => $msg['user_id']]);
                    $newMessages[] = [
                        'id'              => (int)$msg['id'],
                        'channel_id'      => (int)$msg['channel_id'],
                        'user_id'         => (int)$msg['user_id'],
                        'username'        => $sender ? $sender['username'] : '系统',
                        'avatar'          => $sender ? $sender['avatar'] : null,
                        'parent_id'       => isset($msg['parent_id']) ? (int)$msg['parent_id'] : 0,
                        'type'            => $msg['type'] ?? 'text',
                        'content'         => $msg['content'] ?? '',
                        'file_url'        => $msg['file_url'] ?? null,
                        'mentioned_users' => $msg['mentioned_users'] ?? null,
                        'created_at'      => $msg['created_at'] ?? '',
                    ];
                }
            }
        }
    }

    // ── 检查私聊新消息 ──
    if ($privateChatId > 0) {
        $allPm = $db->select('private_messages', ['chat_id' => $privateChatId], '*', 'id DESC', 50);
        foreach ($allPm as $pm) {
            if ((int)$pm['id'] > $sinceId && (int)$pm['to_user_id'] === $user['id']) {
                if (isset($pm['is_deleted']) && (int)$pm['is_deleted'] === 1) continue;

                $sender = $db->get('users', ['id' => $pm['from_user_id']]);
                $newMessages[] = [
                    'id'            => (int)$pm['id'],
                    'private_chat_id' => (int)$pm['chat_id'],
                    'from_user_id'  => (int)$pm['from_user_id'],
                    'username'      => $sender ? $sender['username'] : '未知用户',
                    'avatar'        => $sender ? $sender['avatar'] : null,
                    'type'          => $pm['type'] ?? 'text',
                    'content'       => $pm['content'] ?? '',
                    'file_url'      => $pm['file_url'] ?? null,
                    'file_size'     => $pm['file_size'] ?? 0,
                    'is_read'       => isset($pm['is_read']) ? (int)$pm['is_read'] : 0,
                    'created_at'    => $pm['created_at'] ?? '',
                ];

                // 标记为已读
                $db->update('private_messages', ['is_read' => 1], ['id' => $pm['id']]);
            }
        }
    }

    // ── 如果有新消息，立即返回 ──
    if (count($newMessages) > 0) {
        // 计算最新的消息 ID
        $latestId = $sinceId;
        foreach ($newMessages as $m) {
            if ($m['id'] > $latestId) {
                $latestId = $m['id'];
            }
        }

        json_success([
            'messages'  => $newMessages,
            'latest_id' => $latestId,
        ]);
    }

    // ── 如果不是第一次循环，等待后再检查 ──
    if ($cycle < $maxCycles) {
        usleep($waitIntervalMs);
    }
    $cycle++;
}

// ── 超时，返回空 ──
json_success([
    'messages'  => [],
    'latest_id' => $sinceId,
]);
