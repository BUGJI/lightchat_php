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
    $senderIds   = [];

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

        // 获取每个频道的增量消息（id > since_id 直接下推，避免只取最近 N 条导致漏消息）
        foreach ($visibleChannelIds as $cid) {
            $where = ['channel_id' => $cid];
            if ($sinceId > 0) {
                $where['id >'] = $sinceId;
            }
            if (!role_at_least($user['role'], 'admin')) {
                $where['is_deleted != '] = 1;
            }
            $allMsgs = $db->select('messages', $where, '*', 'id ASC', 200);
            foreach ($allMsgs as $msg) {
                if ((int)$msg['user_id'] !== $user['id']) {
                    $senderIds[] = (int)$msg['user_id'];
                    $newMessages[] = ['kind' => 'channel', 'msg' => $msg];
                }
            }
        }
    }

    // ── 检查私聊新消息 ──
    if ($privateChatId > 0) {
        $pmWhere = ['chat_id' => $privateChatId];
        if ($sinceId > 0) {
            $pmWhere['id >'] = $sinceId;
        }
        $allPm = $db->select('private_messages', $pmWhere, '*', 'id ASC', 200);
        foreach ($allPm as $pm) {
            if ((int)$pm['to_user_id'] === $user['id']) {
                if (isset($pm['is_deleted']) && (int)$pm['is_deleted'] === 1) continue;

                $senderIds[] = (int)$pm['from_user_id'];
                $newMessages[] = ['kind' => 'private', 'msg' => $pm];

                // 标记为已读
                $db->update('private_messages', ['is_read' => 1], ['id' => $pm['id']]);
            }
        }
    }

    // ── 如果有新消息，立即返回 ──
    if (count($newMessages) > 0) {
        // 批量查用户（避免 N+1）
        $userCache = [];
        foreach (array_unique($senderIds) as $uid) {
            $u = $db->get('users', ['id' => $uid]);
            if ($u) {
                $userCache[$uid] = $u;
            }
        }

        $result = [];
        $latestId = $sinceId;
        foreach ($newMessages as $item) {
            if ($item['kind'] === 'channel') {
                $msg = $item['msg'];
                $sender = isset($userCache[(int)$msg['user_id']]) ? $userCache[(int)$msg['user_id']] : null;
                $result[] = [
                    'id'              => (int)$msg['id'],
                    'channel_id'      => (int)$msg['channel_id'],
                    'user_id'         => (int)$msg['user_id'],
                    'username'        => $sender ? $sender['username'] : '系统',
                    'avatar'          => $sender ? ($sender['avatar'] ?? null) : null,
                    'parent_id'       => isset($msg['parent_id']) ? (int)$msg['parent_id'] : 0,
                    'type'            => $msg['type'] ?? 'text',
                    'content'         => $msg['content'] ?? '',
                    'file_url'        => $msg['file_url'] ?? null,
                    'mentioned_users' => $msg['mentioned_users'] ?? null,
                    'created_at'      => $msg['created_at'] ?? '',
                ];
            } else {
                $pm = $item['msg'];
                $sender = isset($userCache[(int)$pm['from_user_id']]) ? $userCache[(int)$pm['from_user_id']] : null;
                $result[] = [
                    'id'               => (int)$pm['id'],
                    'private_chat_id'  => (int)$pm['chat_id'],
                    'from_user_id'     => (int)$pm['from_user_id'],
                    'username'         => $sender ? $sender['username'] : '未知用户',
                    'avatar'           => $sender ? ($sender['avatar'] ?? null) : null,
                    'type'             => $pm['type'] ?? 'text',
                    'content'          => $pm['content'] ?? '',
                    'file_url'         => $pm['file_url'] ?? null,
                    'file_size'        => $pm['file_size'] ?? 0,
                    'is_read'          => isset($pm['is_read']) ? (int)$pm['is_read'] : 0,
                    'created_at'       => $pm['created_at'] ?? '',
                ];
            }
            if ($item['msg']['id'] > $latestId) {
                $latestId = (int)$item['msg']['id'];
            }
        }

        json_success([
            'messages'  => $result,
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
