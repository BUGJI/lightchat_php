<?php
/**
 * 聊天领域服务
 *
 * 抽取「消息增量 / 频道列表 / 私聊列表」的公共查询逻辑，
 * 供 poll.php、sync.php、channels/list.php、private/list.php 复用，避免重复实现。
 *
 * 说明：为兼容 LocalDriver（JSON 文件）与 SQL 驱动，涉及全表遍历的“未读数统计”
 * 在 LocalDriver 下走单次遍历（数据已在内存），SQL 驱动下走带索引的逐项 count。
 */
class ChatService
{
    /**
     * 增量消息拉取（短轮询核心）
     *
     * @param array $user            当前用户
     * @param int[] $channelIds      关注/可见的频道 ID
     * @param int   $sinceId         频道消息游标
     * @param int   $privateChatId   私聊会话 ID（0 表示不拉取）
     * @param int   $privateSinceId  私聊消息游标
     * @return array{messages:array,latest_id:int,private_latest_id:int}
     */
    public static function messageUpdates(array $user, array $channelIds, $sinceId, $privateChatId, $privateSinceId)
    {
        $db = Database::getInstance();

        $newMessages     = [];
        $senderIds       = [];
        $latestId        = (int)$sinceId;
        $privateLatestId = (int)$privateSinceId;

        // ── 频道可见性 + 单次聚合查询 ──
        if (!empty($channelIds)) {
            $memberRows = $db->select('channel_members', [
                'channel_id' => $channelIds,
                'user_id'    => $user['id'],
            ]);
            $memberSet = [];
            foreach ($memberRows as $m) {
                $memberSet[(int)$m['channel_id']] = true;
            }

            $visible = [];
            foreach ($channelIds as $cid) {
                if (isset($memberSet[(int)$cid])) {
                    $visible[] = (int)$cid;
                    continue;
                }
                $ch = $db->get('channels', ['id' => (int)$cid]);
                if ($ch && in_array($ch['type'], ['public', 'announcement'], true)) {
                    $visible[] = (int)$cid;
                }
            }

            if (!empty($visible)) {
                $where = [
                    'channel_id' => $visible,
                    'id >'       => (int)$sinceId,
                ];
                if (!role_at_least($user['role'], 'admin')) {
                    $where['is_deleted != '] = 1;
                }
                foreach ($db->select('messages', $where, '*', 'id ASC', 200) as $msg) {
                    $mid = (int)$msg['id'];
                    if ($mid > $latestId) {
                        $latestId = $mid;
                    }
                    if ((int)$msg['user_id'] === (int)$user['id']) {
                        continue; // 不回显自己发的消息（游标仍前进）
                    }
                    $senderIds[]   = (int)$msg['user_id'];
                    $newMessages[] = ['kind' => 'channel', 'msg' => $msg];
                }
            }
        }

        // ── 私聊增量消息 ──
        if ($privateChatId > 0) {
            $allPm = $db->select('private_messages', [
                'chat_id' => (int)$privateChatId,
                'id >'    => (int)$privateSinceId,
            ], '*', 'id ASC', 200);

            $maxReadId = 0;
            foreach ($allPm as $pm) {
                $mid = (int)$pm['id'];
                if ($mid > $privateLatestId) {
                    $privateLatestId = $mid;
                }
                if ((int)$pm['to_user_id'] !== (int)$user['id']) {
                    continue;
                }
                if (isset($pm['is_deleted']) && (int)$pm['is_deleted'] === 1) {
                    continue;
                }
                $senderIds[]   = (int)$pm['from_user_id'];
                $newMessages[] = ['kind' => 'private', 'msg' => $pm];
                if ($mid > $maxReadId) {
                    $maxReadId = $mid;
                }
            }

            // 本批已读一次写入（避免逐条 update 触发整表重写）
            if ($maxReadId > 0) {
                $db->update('private_messages', ['is_read' => 1], [
                    'chat_id'    => (int)$privateChatId,
                    'to_user_id' => (int)$user['id'],
                    'is_read'    => 0,
                    'id <='      => $maxReadId,
                ]);
            }
        }

        // ── 批量查用户（一次 IN 查询） ──
        $userCache = [];
        $uniqueSenderIds = array_values(array_unique($senderIds));
        if (!empty($uniqueSenderIds)) {
            foreach ($db->select('users', ['id' => $uniqueSenderIds]) as $u) {
                $userCache[(int)$u['id']] = $u;
            }
        }

        // ── 格式化 ──
        $result = [];
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
                    'id'              => (int)$pm['id'],
                    'private_chat_id' => (int)$pm['chat_id'],
                    'from_user_id'    => (int)$pm['from_user_id'],
                    'username'        => $sender ? $sender['username'] : '未知用户',
                    'avatar'          => $sender ? ($sender['avatar'] ?? null) : null,
                    'type'            => $pm['type'] ?? 'text',
                    'content'         => $pm['content'] ?? '',
                    'file_url'        => $pm['file_url'] ?? null,
                    'file_size'       => $pm['file_size'] ?? 0,
                    'is_read'         => isset($pm['is_read']) ? (int)$pm['is_read'] : 0,
                    'created_at'      => $pm['created_at'] ?? '',
                ];
            }
        }

        return [
            'messages'          => $result,
            'latest_id'         => $latestId,
            'private_latest_id' => $privateLatestId,
        ];
    }

    /**
     * 频道列表（含成员数与当前用户未读数）
     * @param array|null $user 游客传 null
     * @return array
     */
    public static function channelList($user)
    {
        $db = Database::getInstance();

        $channels = $db->select('channels', [], '*', 'id ASC');

        $joinedSet   = [];
        $lastReadMap = [];
        if ($user) {
            foreach ($db->select('channel_members', ['user_id' => $user['id']]) as $m) {
                $cid = (int)$m['channel_id'];
                $joinedSet[$cid]   = true;
                $lastReadMap[$cid] = isset($m['last_read_message_id']) ? (int)$m['last_read_message_id'] : 0;
            }
        }

        // 未读数：LocalDriver 单次遍历；SQL 驱动逐频道 count
        $unreadMap = [];
        if ($user && !empty($joinedSet)) {
            if ($db->getDriver() instanceof \LocalDriver) {
                foreach ($db->select('messages', [], 'channel_id, user_id, id, is_deleted') as $msg) {
                    $cid = (int)$msg['channel_id'];
                    if (!isset($joinedSet[$cid])) continue;
                    if ((int)$msg['id'] <= (isset($lastReadMap[$cid]) ? $lastReadMap[$cid] : 0)) continue;
                    if ((int)$msg['user_id'] === (int)$user['id']) continue;
                    if (!role_at_least($user['role'], 'admin')
                        && isset($msg['is_deleted']) && (int)$msg['is_deleted'] === 1) continue;
                    $unreadMap[$cid] = (isset($unreadMap[$cid]) ? $unreadMap[$cid] : 0) + 1;
                }
            } else {
                foreach (array_keys($joinedSet) as $cid) {
                    $where = [
                        'channel_id'  => $cid,
                        'id >'        => $lastReadMap[$cid],
                        'user_id != ' => $user['id'],
                    ];
                    if (!role_at_least($user['role'], 'admin')) {
                        $where['is_deleted != '] = 1;
                    }
                    $unreadMap[$cid] = $db->count('messages', $where);
                }
            }
        }

        $result = [];
        foreach ($channels as $ch) {
            // 游客只能看到公开频道和公告频道
            if (!$user && !in_array($ch['type'], ['public', 'announcement'], true)) {
                continue;
            }
            $cid = (int)$ch['id'];
            $result[] = [
                'id'           => $cid,
                'name'         => $ch['name'],
                'display_name' => $ch['display_name'],
                'type'         => $ch['type'],
                'description'  => $ch['description'] ?? '',
                'announcement' => $ch['announcement'] ?? null,
                'owner_id'     => isset($ch['owner_id']) ? (int)$ch['owner_id'] : 0,
                'member_count' => isset($ch['member_count']) ? (int)$ch['member_count'] : 0,
                'is_joined'    => isset($joinedSet[$cid]),
                'unread_count' => isset($unreadMap[$cid]) ? (int)$unreadMap[$cid] : 0,
                'created_at'   => $ch['created_at'] ?? '',
            ];
        }
        return $result;
    }

    /**
     * 私聊会话列表（含对方信息、未读数、联系人元数据）
     * @param array $user
     * @return array
     */
    public static function privateChatList(array $user)
    {
        $db = Database::getInstance();

        // 只取当前用户参与的会话（条件下推）
        $byId = [];
        foreach ($db->select('private_chats', ['user1_id' => $user['id']]) as $c) {
            $byId[(int)$c['id']] = $c;
        }
        foreach ($db->select('private_chats', ['user2_id' => $user['id']]) as $c) {
            $byId[(int)$c['id']] = $c;
        }
        $allChats = array_values($byId);
        usort($allChats, function ($a, $b) {
            $ta = $a['last_message_at'] ?? '';
            $tb = $b['last_message_at'] ?? '';
            if ($ta === $tb) return 0;
            return ($ta < $tb) ? 1 : -1;
        });

        $isLocal = $db->getDriver() instanceof \LocalDriver;

        // 未读数：LocalDriver 单次遍历；SQL 驱动逐会话 count
        $unreadMap = [];
        if ($isLocal) {
            foreach ($db->select('private_messages', ['to_user_id' => $user['id'], 'is_read' => 0], 'chat_id') as $pm) {
                $cid = (int)$pm['chat_id'];
                $unreadMap[$cid] = (isset($unreadMap[$cid]) ? $unreadMap[$cid] : 0) + 1;
            }
        }

        // 联系人元数据批量
        $chatIds = array_map(function ($c) {
            return (int)$c['id'];
        }, $allChats);
        $metaMap = [];
        if (!empty($chatIds)) {
            foreach ($db->select('private_contact_meta', ['chat_id' => $chatIds, 'user_id' => $user['id']]) as $m) {
                $metaMap[(int)$m['chat_id']] = $m;
            }
        }

        // 对方用户信息批量
        $otherIds = [];
        foreach ($allChats as $chat) {
            $uid1 = (int)$chat['user1_id'];
            $uid2 = (int)$chat['user2_id'];
            $otherIds[] = ($uid1 === (int)$user['id']) ? $uid2 : $uid1;
        }
        $userMap = [];
        if (!empty($otherIds)) {
            foreach ($db->select('users', ['id' => array_values(array_unique($otherIds))]) as $u) {
                $userMap[(int)$u['id']] = $u;
            }
        }

        $chats = [];
        foreach ($allChats as $chat) {
            $cid  = (int)$chat['id'];
            $uid1 = (int)$chat['user1_id'];
            $uid2 = (int)$chat['user2_id'];
            if ($uid1 !== (int)$user['id'] && $uid2 !== (int)$user['id']) {
                continue;
            }
            $otherUserId = ($uid1 === (int)$user['id']) ? $uid2 : $uid1;
            $otherUser   = isset($userMap[$otherUserId]) ? $userMap[$otherUserId] : null;

            // 本端联系人元数据（备注/免打扰/删除）
            $meta = isset($metaMap[$cid]) ? $metaMap[$cid] : null;
            $hidden = $meta ? (int)($meta['hidden'] ?? 0) : 0;
            if ($hidden) {
                // 删除好友=隐藏会话；对方删除后有新消息则自动恢复
                $lastAt   = $chat['last_message_at'] ?? '';
                $hiddenAt = $meta['hidden_at'] ?? '';
                if (!$lastAt || !$hiddenAt || strcmp($lastAt, $hiddenAt) <= 0) {
                    continue;
                }
                $db->update('private_contact_meta', [
                    'hidden' => 0, 'hidden_at' => null, 'updated_at' => date('Y-m-d H:i:s'),
                ], ['chat_id' => $cid, 'user_id' => $user['id']]);
            }

            $unread = $isLocal
                ? (isset($unreadMap[$cid]) ? (int)$unreadMap[$cid] : 0)
                : $db->count('private_messages', ['chat_id' => $cid, 'to_user_id' => $user['id'], 'is_read' => 0]);

            $otherUsername = $otherUser ? $otherUser['username'] : '未知用户';
            $nickname = $meta ? trim((string)($meta['nickname'] ?? '')) : '';

            $chats[] = [
                'id'                 => $cid,
                'other_user_id'      => $otherUserId,
                'other_username'     => $otherUsername,
                'other_display_name' => $nickname !== '' ? $nickname : $otherUsername,
                'other_nickname'     => $nickname,
                'other_avatar'       => $otherUser ? ($otherUser['avatar'] ?? null) : null,
                'other_role'         => $otherUser ? ($otherUser['role'] ?? 'member') : null,
                'dnd'                => $meta ? (int)($meta['dnd'] ?? 0) : 0,
                'last_message'       => $chat['last_message'] ?? '',
                'last_message_at'    => $chat['last_message_at'] ?? '',
                'unread_count'       => $unread,
                'created_at'         => $chat['created_at'] ?? '',
            ];
        }

        return $chats;
    }
}
