<?php
/**
 * 导出聊天记录（合规留存）
 *
 * GET /api/admin/export.php?type=messages&format=json
 * GET /api/admin/export.php?type=private_messages&format=json
 * GET /api/admin/export.php?type=all&format=json
 *
 * 需要 admin 权限
 *
 * 采用分批游标查询 + 流式输出，避免大数据量下 OOM。
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

$user = authenticate();

if (!role_at_least($user['role'], 'admin')) {
    json_response(403, ['error' => 'forbidden', 'message' => '仅管理员可导出审计日志']);
}

$type   = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$format = isset($_GET['format']) ? trim($_GET['format']) : 'json';

/**
 * 分批流式输出一个表为 JSON 数组（id 游标 + 批量用户缓存）
 * @param callable $callback 接收 ($batch, $userCache)，返回要输出的行数组
 */
function export_stream_array($db, $table, $callback) {
    $first  = true;
    $cursor = 0;
    echo '[';
    while (true) {
        $where = [];
        if ($cursor > 0) {
            $where['id >'] = $cursor;
        }
        $batch = $db->select($table, $where, '*', 'id ASC', 500);
        if (empty($batch)) {
            break;
        }

        // 批量收集用户 ID
        $userIds = [];
        foreach ($batch as $row) {
            foreach (['user_id', 'from_user_id', 'to_user_id'] as $f) {
                if (isset($row[$f]) && (int)$row[$f] > 0) {
                    $userIds[] = (int)$row[$f];
                }
            }
        }
        $userCache = [];
        if (!empty($userIds)) {
            $us = $db->select('users', ['id' => array_values(array_unique($userIds))]);
            foreach ($us as $u) {
                $userCache[(int)$u['id']] = $u;
            }
        }

        $rows = $callback($batch, $userCache);
        foreach ($rows as $r) {
            if (!$first) {
                echo ',';
            }
            $first = false;
            echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $cursor = (int)$batch[count($batch) - 1]['id'];
        if (count($batch) < 500) {
            break;
        }
    }
    echo ']';
}

// ── 流式输出 ──
$filename = 'lightchat_export_' . $type . '_' . date('Ymd_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Export-Type: ' . $type);

echo '{"export_type":"' . $type . '","exported_at":"' . date('Y-m-d H:i:s') . '","data":{';

$firstSection = true;
$openSection = function ($key) use (&$firstSection) {
    if (!$firstSection) {
        echo ',';
    }
    $firstSection = false;
    echo '"' . $key . '":';
};

if ($type === 'all' || $type === 'messages') {
    $openSection('channel_messages');
    export_stream_array($db, 'messages', function ($batch, $uc) {
        $out = [];
        foreach ($batch as $m) {
            $sender = isset($uc[(int)$m['user_id']]) ? $uc[(int)$m['user_id']] : null;
            $out[] = [
                'id'          => (int)$m['id'],
                'channel_id'  => (int)$m['channel_id'],
                'sender_id'   => (int)$m['user_id'],
                'sender_name' => $sender ? $sender['username'] : '系统',
                'type'        => $m['type'] ?? 'text',
                'content'     => $m['content'] ?? '',
                'file_url'    => $m['file_url'] ?? null,
                'is_deleted'  => isset($m['is_deleted']) ? (int)$m['is_deleted'] : 0,
                'created_at'  => $m['created_at'] ?? '',
            ];
        }
        return $out;
    });
}

if ($type === 'all' || $type === 'private_messages') {
    $openSection('private_messages');
    export_stream_array($db, 'private_messages', function ($batch, $uc) {
        $out = [];
        foreach ($batch as $pm) {
            $from = isset($uc[(int)$pm['from_user_id']]) ? $uc[(int)$pm['from_user_id']] : null;
            $to   = isset($uc[(int)$pm['to_user_id']]) ? $uc[(int)$pm['to_user_id']] : null;
            $out[] = [
                'id'            => (int)$pm['id'],
                'chat_id'       => (int)$pm['chat_id'],
                'from_user_id'  => (int)$pm['from_user_id'],
                'from_username' => $from ? $from['username'] : '?',
                'to_user_id'    => (int)$pm['to_user_id'],
                'to_username'   => $to ? $to['username'] : '?',
                'content'       => $pm['content'] ?? '',
                'is_deleted'    => isset($pm['is_deleted']) ? (int)$pm['is_deleted'] : 0,
                'created_at'    => $pm['created_at'] ?? '',
            ];
        }
        return $out;
    });
}

if ($type === 'all' || $type === 'users') {
    $openSection('users');
    export_stream_array($db, 'users', function ($batch, $uc) {
        $out = [];
        foreach ($batch as $u) {
            $out[] = [
                'id'          => (int)$u['id'],
                'username'    => $u['username'],
                'email'       => $u['email'] ?? '',
                'contact'     => $u['contact'] ?? '',
                'reg_ip'      => $u['reg_ip'] ?? '',
                'role'        => $u['role'] ?? 'member',
                'status'      => (int)($u['status'] ?? 1),
                'last_active' => $u['last_active_at'] ?? '',
                'created_at'  => $u['created_at'] ?? '',
            ];
        }
        return $out;
    });
}

if ($type === 'all' || $type === 'sessions') {
    $openSection('sessions');
    export_stream_array($db, 'sessions', function ($batch, $uc) {
        $out = [];
        foreach ($batch as $s) {
            $su = isset($uc[(int)$s['user_id']]) ? $uc[(int)$s['user_id']] : null;
            $out[] = [
                'id'         => (int)$s['id'],
                'user_id'    => (int)$s['user_id'],
                'username'   => $su ? $su['username'] : '?',
                'ip'         => $s['ip'] ?? '',
                'user_agent' => $s['user_agent'] ?? '',
                'expires_at' => $s['expires_at'] ?? '',
                'created_at' => $s['created_at'] ?? '',
            ];
        }
        return $out;
    });
}

echo '}}';
exit;
