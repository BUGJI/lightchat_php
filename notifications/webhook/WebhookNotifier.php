<?php
/**
 * Webhook 通知实现
 * 将离线通知以 HTTP POST 方式推送到用户配置的 Webhook URL
 */
require_once __DIR__ . '/../NotificationBase.php';

class WebhookNotifier extends NotificationBase
{
    public function getName(): string
    {
        return 'Webhook';
    }

    public function send(): array
    {
        $url = $this->data['webhook_url'] ?? '';
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'message' => 'Webhook URL 无效或未设置'];
        }

        $secret = $this->data['webhook_secret'] ?? '';

        $title = $this->renderTemplate(
            $this->data['template_title']
            ?? $this->config['templates']['title']
            ?? '【LightChat】离线消息提醒'
        );
        $content = $this->renderTemplate(
            $this->data['template_content']
            ?? $this->config['templates']['content']
            ?? '您好 {nickname}，您有 {unread_count} 条未读消息。'
        );

        $payload = json_encode([
            'title'        => $title,
            'content'      => $content,
            'nickname'     => $this->data['nickname'] ?? '',
            'unread_count' => $this->data['unread_count'] ?? 0,
            'sender_name'  => $this->data['sender_name'] ?? '',
            'last_message_time' => $this->data['last_message_time'] ?? '',
            'messages_preview'  => $this->data['messages_preview'] ?? '',
            'timestamp'    => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json'];
        if ($secret !== '') {
            $headers[] = 'X-Webhook-Secret: ' . $secret;
        }

        $timeout = (int)($this->config['timeout'] ?? 10);

        if (function_exists('curl_init')) {
            return $this->sendViaCurl($url, $payload, $headers, $timeout);
        }
        return $this->sendViaStream($url, $payload, $headers, $timeout);
    }

    private function sendViaCurl($url, $payload, $headers, $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => 'Webhook 请求失败: ' . $error];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'message' => 'Webhook 推送成功'];
        }

        return ['success' => false, 'message' => "Webhook 返回 HTTP {$httpCode}"];
    }

    private function sendViaStream($url, $payload, $headers, $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", $headers) . "\r\n",
                'content' => $payload,
                'timeout' => $timeout,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['success' => false, 'message' => 'Webhook 请求失败'];
        }

        return ['success' => true, 'message' => 'Webhook 推送成功'];
    }
}