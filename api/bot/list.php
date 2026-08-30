<?php
/**
 * Bot 列表 / 管理
 *
 * GET /api/bot/list.php          列出所有 Bot
 * GET /api/bot/list.php?id=1     查看单个 Bot
 *
 * POST /api/bot/list.php         切换 Bot 启用/禁用、重新生成 Key 或删除
 *   请求体 JSON:
 *   action    string  "toggle" | "regenerate" | "delete"
 *   bot_id    int     Bot 的 user_id
 */

require_once __DIR__ . '/../bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = authenticate();

if (!has_permission($user, 'admin.bot.manage') && !role_at_least($user['role'], 'admin')) {
    json_response(403, ['error' => 'forbidden', 'message' => '您没有管理 Bot 的权限']);
}

// 审计辅助
function bot_audit($user, $action, $targetId, $detail = []) {
    global $db;
    $db->insert('audit_logs', [
        'user_id'     => $user['id'],
        'username'    => $user['username'],
        'action'      => $action,
        'target_type' => 'bot',
        'target_id'   => $targetId,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'detail'      => json_encode($detail, JSON_UNESCAPED_UNICODE),
    ]);
}

// Key 脱敏：仅 active=1 的 key 显示完整，历史/禁用 key 打码
function mask_key($key, $visible = false) {
    if ($visible) {
        return $key;
    }
    if (strlen($key) <= 12) {
        return '***';
    }
    return substr($key, 0, 8) . '****' . substr($key, -4);
}

if ($method === 'GET') {
    $botId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($botId > 0) {
        // 单个 Bot 详情
        $botUser = $db->get('users', ['id' => $botId, 'account_type' => 'bot']);
        if (!$botUser) {
            json_response(404, ['error' => 'not_found', 'message' => 'Bot 不存在']);
        }
        $keys = $db->select('bot_keys', ['user_id' => $botId], '*', 'id DESC', 10);

        // 创建者信息
        $creator = null;
        if (!empty($botUser['created_by'])) {
            $c = $db->get('users', ['id' => (int)$botUser['created_by']]);
            if ($c) {
                $creator = ['id' => (int)$c['id'], 'username' => $c['username']];
            }
        }

        json_success([
            'bot'  => [
                'id'         => (int)$botUser['id'],
                'username'   => $botUser['username'],
                'status'     => (int)($botUser['status'] ?? 1),
                'creator'    => $creator,
                'created_at' => $botUser['created_at'] ?? '',
            ],
            'keys' => array_map(function($k) {
                $active = (int)($k['active'] ?? 1) === 1;
                return [
                    'id'           => (int)$k['id'],
                    'api_key'      => mask_key($k['api_key'], $active),
                    'name'         => $k['name'] ?? '',
                    'active'       => (int)($k['active'] ?? 1),
                    'last_used_at' => $k['last_used_at'] ?? null,
                    'created_at'   => $k['created_at'] ?? '',
                ];
            }, $keys),
        ]);
        return;
    }

    // 列表：一次拉取所有 Bot 用户 + 所有 Key，按 user_id 分组（避免 N+1）
    $users = $db->select('users', ['account_type' => 'bot'], '*', 'id ASC');
    $allKeys = $db->select('bot_keys');
    $keysByUser = [];
    foreach ($allKeys as $k) {
        $uid = (int)$k['user_id'];
        if (!isset($keysByUser[$uid])) {
            $keysByUser[$uid] = [];
        }
        $keysByUser[$uid][] = $k;
    }

    // 创建者用户名（一次 IN 查询）
    $creatorIds = [];
    foreach ($users as $u) {
        if (!empty($u['created_by'])) {
            $creatorIds[] = (int)$u['created_by'];
        }
    }
    $creatorNames = [];
    if (!empty($creatorIds)) {
        $creatorRows = $db->select('users', ['id' => array_values(array_unique($creatorIds))]);
        foreach ($creatorRows as $c) {
            $creatorNames[(int)$c['id']] = $c['username'];
        }
    }

    $bots = [];
    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $keys = isset($keysByUser[$uid]) ? $keysByUser[$uid] : [];
        // 最新 key = id 最大（不依赖 select 排序）
        $latestKey = null;
        foreach ($keys as $k) {
            if ($latestKey === null || (int)$k['id'] > (int)$latestKey['id']) {
                $latestKey = $k;
            }
        }
        $creatorId = !empty($u['created_by']) ? (int)$u['created_by'] : null;
        $bots[] = [
            'id'             => $uid,
            'username'       => $u['username'],
            'status'         => (int)($u['status'] ?? 1),
            'creator'        => $creatorId !== null
                ? ['id' => $creatorId, 'username' => isset($creatorNames[$creatorId]) ? $creatorNames[$creatorId] : null]
                : null,
            'has_active_key' => $latestKey && (int)($latestKey['active'] ?? 0) === 1,
            'last_used_at'   => $latestKey ? ($latestKey['last_used_at'] ?? null) : null,
            'created_at'     => $u['created_at'] ?? '',
        ];
    }
    json_success(['bots' => $bots, 'count' => count($bots)]);
    return;
}

if ($method === 'POST') {
    $input  = get_json_input();
    $action = trim($input['action'] ?? '');
    $botId  = isset($input['bot_id']) ? (int)$input['bot_id'] : 0;

    if ($botId <= 0) {
        json_response(400, ['error' => 'invalid_bot', 'message' => 'Bot ID 无效']);
    }

    $botUser = $db->get('users', ['id' => $botId, 'account_type' => 'bot']);
    if (!$botUser) {
        json_response(404, ['error' => 'not_found', 'message' => 'Bot 不存在']);
    }

    if ($action === 'toggle') {
        // 切换启用/禁用
        $newStatus = (int)($botUser['status'] ?? 1) === 1 ? 0 : 1;
        $db->update('users', ['status' => $newStatus], ['id' => $botId]);

        // 同步禁用所有 api key
        if ($newStatus === 0) {
            $keys = $db->select('bot_keys', ['user_id' => $botId]);
            foreach ($keys as $k) {
                $db->update('bot_keys', ['active' => 0], ['id' => $k['id']]);
            }
        } else {
            // 启用时恢复最后一个 key
            $keys = $db->select('bot_keys', ['user_id' => $botId], '*', 'id DESC', 1);
            if (!empty($keys)) {
                $db->update('bot_keys', ['active' => 1], ['id' => $keys[0]['id']]);
            }
        }
        bot_audit($user, 'bot.toggle', $botId, ['status' => $newStatus]);
        json_success(['status' => $newStatus], $newStatus === 1 ? 'Bot 已启用' : 'Bot 已禁用');
        return;
    }

    if ($action === 'regenerate') {
        // 重新生成 API Key
        $newKey = 'bot_' . bin2hex(random_bytes(24));

        // 禁用旧 key
        $keys = $db->select('bot_keys', ['user_id' => $botId]);
        foreach ($keys as $k) {
            $db->update('bot_keys', ['active' => 0], ['id' => $k['id']]);
        }

        // 创建新 key
        $keyName = $input['name'] ?? ('再生_' . date('Ymd'));
        $db->insert('bot_keys', [
            'user_id' => $botId,
            'api_key' => $newKey,
            'name'    => $keyName,
            'active'  => 1,
        ]);

        bot_audit($user, 'bot.regenerate', $botId, ['key_name' => $keyName]);
        json_success(['api_key' => $newKey], 'API Key 已重新生成');
        return;
    }

    if ($action === 'delete') {
        // 删除 Bot（级联删除用户与全部 Key）
        $db->delete('bot_keys', ['user_id' => $botId]);
        $db->delete('users', ['id' => $botId]);
        bot_audit($user, 'bot.delete', $botId, ['username' => $botUser['username']]);
        json_success(['deleted' => $botId], 'Bot 已删除');
        return;
    }

    json_response(400, ['error' => 'unknown_action', 'message' => '未知操作，支持: toggle, regenerate, delete']);
}

json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET/POST 请求']);
