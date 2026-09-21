<?php
/**
 * 频道列表
 *
 * GET /api/channels/list.php
 *
 * 响应:
 *   channels  array  频道列表（含 member_count、is_joined、unread_count）
 *
 * 说明：查询逻辑见 core/ChatService::channelList()，与 /api/sync.php 复用同一实现。
 */

require_once __DIR__ . '/../bootstrap.php';

require_method('GET');

// ── 可选认证（游客也可查看公开频道） ──
$user = optional_authenticate();

json_success(['channels' => ChatService::channelList($user)]);
