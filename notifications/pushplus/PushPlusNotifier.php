<?php
/**
 * PushPlus 通知实现
 * 通过 PushPlus API 推送到微信/短信/企业微信等渠道
 * 文档: https://www.pushplus.plus/doc/
 */

require_once __DIR__ . '/../NotificationBase.php';

class PushPlusNotifier extends NotificationBase
{
    public function getName(): string
    {
        return 'PushPlus';
    }

    /**
     * 发送 PushPlus 消息
     * @return array
     */
    public function send(): array
    {
        $token = $this->data['pushplus_key'] ?? '';
        if ($token === '') {
            return ['success' => false, 'message' => 'PushPlus Token 未设置'];
        }

        $apiUrl = $this->config['api_url'] ?? 'https://www.pushplus.plus/send';

        // 模板（优先用户自定义，否则系统默认）
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
            'token'    => $token,
            'title'    => $this->utf8Substr($title, 100),  // PushPlus 标题限制 100 字符
            'content'  => $content,
            'template' => $this->config['template'] ?? 'html', // html / txt / json / markdown
            'channel'  => $this->config['channel'] ?? 'wechat',
        ], JSON_UNESCAPED_UNICODE);

        // curl 可用时优先使用 curl，否则回退到 file_get_contents 流
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $apiUrl,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => (int)($this->config['timeout'] ?? 10),
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'message' => 'PushPlus 请求失败: ' . $error];
            }
        } else {
            $result = $this->sendViaStream($apiUrl, $payload);
            if ($result === null) {
                return ['success' => false, 'message' => 'PushPlus 请求失败: 网络不可用'];
            }
            $httpCode = 200;
            $response = json_encode($result, JSON_UNESCAPED_UNICODE);
        }

        $result = json_decode($response, true);
        $code = $result['code'] ?? -1;

        // PushPlus 返回 code=200 表示成功
        if ($httpCode === 200 && $code === 200) {
            return ['success' => true, 'message' => 'PushPlus 推送成功'];
        }

        return [
            'success' => false,
            'message' => 'PushPlus 推送失败: ' . ($result['msg'] ?? '未知错误'),
        ];
    }

    /**
     * UTF-8 安全截断（按字符数），不依赖 mbstring / iconv
     * 供标题等有限制长度的字段使用
     */
    private function utf8Substr($str, $limit)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($str, 0, $limit, 'UTF-8');
        }
        if ($str === '' || $limit <= 0) {
            return '';
        }
        // 纯 PHP 逐字符解析 UTF-8
        $out = '';
        $count = 0;
        $len = strlen($str);
        $i = 0;
        while ($i < $len && $count < $limit) {
            $b = ord($str[$i]);
            if ($b < 0x80) {
                $out .= $str[$i];
                $i += 1;
            } elseif (($b & 0xE0) === 0xC0) {
                $out .= substr($str, $i, 2);
                $i += 2;
            } elseif (($b & 0xF0) === 0xE0) {
                $out .= substr($str, $i, 3);
                $i += 3;
            } elseif (($b & 0xF8) === 0xF0) {
                $out .= substr($str, $i, 4);
                $i += 4;
            } else {
                $out .= $str[$i];
                $i += 1;
            }
            $count++;
        }
        return $out;
    }

    /**
     * 通过 file_get_contents 发送（curl 不可用时的后备）
     */
    private function sendViaStream($apiUrl, $payload): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($apiUrl, false, $context);
        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }
}
