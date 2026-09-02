<?php
/**
 * 通知管理器
 * 统一调度所有通知方式
 */

require_once __DIR__ . '/NotificationBase.php';
require_once __DIR__ . '/email/EmailNotifier.php';
require_once __DIR__ . '/pushplus/PushPlusNotifier.php';
require_once __DIR__ . '/webhook/WebhookNotifier.php';

class NotificationManager
{
    /** @var array 全局通知配置 */
    private $config;

    /** @var array<string, NotificationBase> 已注册的通知器 */
    private $notifiers = [];

    /**
     * @param array $config 来自 config.php 的 'notifications.methods' 部分
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->registerBuiltins();
    }

    /**
     * 注册内置通知器
     */
    private function registerBuiltins()
    {
        // 邮件（只有显式 enabled 且配置了真实 SMTP 才注册，避免未配置时白白实例化）
        if (($this->config['email']['enabled'] ?? false)) {
            $emailCfg = $this->config['email'] ?? [];
            $smtp = isset($emailCfg['smtp']) ? $emailCfg['smtp'] : [];
            $smtpReady = !empty($smtp['host']) && !empty($smtp['username']) && !empty($smtp['password'])
                && strpos($smtp['host'], 'example.com') === false;
            if ($smtpReady) {
                $this->notifiers['email'] = new EmailNotifier($emailCfg);
            }
        }

        // PushPlus
        if (($this->config['pushplus']['enabled'] ?? false)) {
            $ppCfg = $this->config['pushplus'] ?? [];
            $this->notifiers['pushplus'] = new PushPlusNotifier($ppCfg);
        }

        // Webhook
        if (($this->config['webhook']['enabled'] ?? false)) {
            $whCfg = $this->config['webhook'] ?? [];
            $this->notifiers['webhook'] = new WebhookNotifier($whCfg);
        }
    }

    /**
     * 获取所有可用的通知方式
     * @return array<array{id:string, name:string}>
     */
    public function getAvailableMethods(): array
    {
        $methods = [];
        foreach ($this->notifiers as $id => $notifier) {
            $methods[] = [
                'id'   => $id,
                'name' => $notifier->getName(),
            ];
        }
        return $methods;
    }

    /**
     * 判断用户是否离线
     * @param string $lastActiveAt 用户最后活跃时间
     * @param int    $thresholdMinutes 离线阈值（分钟）
     * @return bool
     */
    public function isOffline(string $lastActiveAt, int $thresholdMinutes): bool
    {
        if (empty($lastActiveAt)) {
            return true;
        }
        $lastTime = strtotime($lastActiveAt);
        if ($lastTime === false) {
            return true;
        }
        return (time() - $lastTime) > ($thresholdMinutes * 60);
    }

    /**
     * 获取离线时间（分钟）
     * @param string $lastActiveAt
     * @return int 0 表示在线
     */
    public function getOfflineMinutes(string $lastActiveAt): int
    {
        if (empty($lastActiveAt)) {
            return PHP_INT_MAX;
        }
        $lastTime = strtotime($lastActiveAt);
        if ($lastTime === false) {
            return PHP_INT_MAX;
        }
        $diff = (int)((time() - $lastTime) / 60);
        return max(0, $diff);
    }

    /**
     * 发送通知
     * @param string $method   通知方式: email / pushplus
     * @param array  $userData 用户数据
     *   - notification_mode: none / email / pushplus
     *   - notification_email: string
     *   - notification_pushplus_key: string
     *   - nickname: string
     *   - unread_count: int
     *   - messages_preview: string
     *   - sender_name: string
     *   - last_message_time: string
     *   -- 模板覆盖（可选）--
     *   - template_subject / template_body / template_title / template_content
     * @return array
     */
    public function send(string $method, array $userData): array
    {
        if (!isset($this->notifiers[$method])) {
            return ['success' => false, 'message' => "通知方式 '{$method}' 不可用或未启用"];
        }

        // 校验必要条件
        if ($method === 'email' && empty($userData['notification_email'])) {
            return ['success' => false, 'message' => '未设置通知邮箱'];
        }
        if ($method === 'pushplus' && empty($userData['notification_pushplus_key'])) {
            return ['success' => false, 'message' => '未设置 PushPlus Token'];
        }
        if ($method === 'webhook' && empty($userData['notification_webhook_url'])) {
            return ['success' => false, 'message' => '未设置 Webhook URL'];
        }

        // 映射数据
        $data = [
            'nickname'       => $userData['nickname'] ?? '',
            'email'          => $userData['notification_email'] ?? '',
            'pushplus_key'   => $userData['notification_pushplus_key'] ?? '',
            'webhook_url'    => $userData['notification_webhook_url'] ?? '',
            'webhook_secret' => $userData['notification_webhook_secret'] ?? '',
            'unread_count'   => $userData['unread_count'] ?? 0,
            'messages_preview' => $userData['messages_preview'] ?? '',
            'sender_name'    => $userData['sender_name'] ?? '',
            'last_message_time' => $userData['last_message_time'] ?? '',
        ];

        // 合并自定义模板
        if (isset($userData['template_subject'])) {
            $data['template_subject'] = $userData['template_subject'];
        }
        if (isset($userData['template_body'])) {
            $data['template_body'] = $userData['template_body'];
        }
        if (isset($userData['template_title'])) {
            $data['template_title'] = $userData['template_title'];
        }
        if (isset($userData['template_content'])) {
            $data['template_content'] = $userData['template_content'];
        }

        return $this->notifiers[$method]->setData($data)->send();
    }

    /**
     * 发送离线通知（自动选择用户配置的方式并判断离线状态）
     * @param array $user      用户记录
     * @param array $messageData 消息数据
     * @param int   $thresholdMinutes 离线阈值
     * @return array|null  null = 用户在线 / 未配置通知
     */
    public function sendIfOffline(array $user, array $messageData, int $thresholdMinutes): ?array
    {
        $mode = $user['notification_mode'] ?? 'none';
        if ($mode === 'none' || $mode === '') {
            return null;
        }

        $lastActive = $user['last_active_at'] ?? '';
        if (!$this->isOffline($lastActive, $thresholdMinutes)) {
            return null; // 用户在线，不通知
        }

        $data = array_merge($user, $messageData);
        return $this->send($mode, $data);
    }
}
