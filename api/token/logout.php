<?php
/**
 * 退出登录（销毁服务端会话）
 *
 * POST /api/token/logout.php
 *
 * 鉴权：Header Authorization: Bearer <token>
 * 说明：即使 Token 已过期也返回成功（幂等），确保客户端能干净登出。
 */

require_once __DIR__ . '/../bootstrap.php';

require_method('POST');

$token = get_bearer_token();

if ($token !== '') {
    // 记录登出审计（可选，仅当会话有效时）
    $session = get_session_by_token($token);
    if ($session) {
        $user = $db->get('users', ['id' => $session['user_id']]);
        if ($user) {
            try {
                $db->insert('audit_logs', [
                    'user_id'    => $user['id'],
                    'username'   => $user['username'],
                    'action'     => 'logout',
                    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]);
            } catch (Exception $e) {
                // 审计失败不影响登出
            }
        }
    }

    $db->delete('sessions', ['id' => $session['id']]);
}

json_success([], '已退出登录');
