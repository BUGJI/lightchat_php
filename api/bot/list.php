<?php
/**
 * Bot 列表 / 管理
 *
 * GET /api/bot/list.php          列出所有 Bot
 * GET /api/bot/list.php?id=1     查看单个 Bot
 *
 * POST /api/bot/list.php         切换 Bot 启用/禁用或重新生成 Key
 *   请求体 JSON:
 *   action    string  "toggle" 或 "regenerate"
 *   bot_id    int     Bot 的 user_id
 */

require_once __DIR__ . '/../bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = authenticate();

if (!has_permission($user, 'admin.bot.manage') && !role_at_least($user['role'], 'admin')) {
    json_response(403, ['error' => 'forbidden', 'message' => '您没有管理 Bot 的权限']);
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
        json_success([
            'bot'  => [
                'id'       => (int)$botUser['id'],
                'username' => $botUser['username'],
                'status'   => (int)($botUser['status'] ?? 1),
            ],
            'keys' => array_map(function($k) {
                return [
                    'id'           => (int)$k['id'],
                    'api_key'      => $k['api_key'],
                    'name'         => $k['name'] ?? '',
                    'active'       => (int)($k['active'] ?? 1),
                    'last_used_at' => $k['last_used_at'] ?? null,
                    'created_at'   => $k['created_at'] ?? '',
                ];
            }, $keys),
        ]);
        return;
    }

    // 列表
    $users = $db->select('users', ['account_type' => 'bot'], '*', 'id ASC');
    $bots  = [];
    foreach ($users as $u) {
        $keys = $db->select('bot_keys', ['user_id' => $u['id']], '*', 'id DESC', 1);
        $latestKey = !empty($keys) ? $keys[0] : null;
        $bots[] = [
            'id'          => (int)$u['id'],
            'username'    => $u['username'],
            'status'      => (int)($u['status'] ?? 1),
            'has_active_key' => $latestKey && (int)($latestKey['active'] ?? 0) === 1,
            'last_used_at'=> $latestKey ? ($latestKey['last_used_at'] ?? null) : null,
            'created_at'  => $u['created_at'] ?? '',
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

        json_success(['api_key' => $newKey], 'API Key 已重新生成');
        return;
    }

    json_response(400, ['error' => 'unknown_action', 'message' => '未知操作，支持: toggle, regenerate']);
}

json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET/POST 请求']);
