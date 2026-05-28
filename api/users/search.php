<?php
/**
 * 搜索用户
 *
 * GET /api/users/search.php?q=keyword&limit=20
 *
 * 参数:
 *   q      string  搜索关键词（用户名模糊匹配）
 *   limit  int     返回数量（默认 20，最大 50）
 *
 * 响应:
 *   users  array   匹配的用户列表（仅公开字段）
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

authenticate();

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

if ($query === '') {
    json_success(['users' => []]);
}
if ($limit < 1 || $limit > 50) {
    $limit = 20;
}

// 敏感词检查
if (mb_strlen($query, 'UTF-8') < 1 || mb_strlen($query, 'UTF-8') > 30) {
    json_response(400, ['error' => 'invalid_query', 'message' => '搜索关键词长度应在 1-30 个字符']);
}

// ── 全表扫描用户名（LocalDriver 不支持 LIKE，在应用层做） ──
$allUsers = $db->select('users', [], '*', '', 0);

$matched = [];
$queryLower = mb_strtolower($query, 'UTF-8');

foreach ($allUsers as $u) {
    if (isset($u['status']) && (int)$u['status'] !== 1) continue;

    $username = $u['username'] ?? '';
    $nickname = $u['nickname'] ?? '';

    if (mb_stripos($username, $query) !== false || mb_stripos($nickname, $query) !== false) {
        $matched[] = [
            'id'        => (int)$u['id'],
            'username'  => $u['username'],
            'nickname'  => $u['nickname'] ?? $u['username'],
            'avatar'    => $u['avatar'] ?? null,
            'role'      => $u['role'] ?? 'member',
            'signature' => $u['signature'] ?? '',
        ];
    }

    if (count($matched) >= $limit) break;
}

json_success(['users' => $matched]);
