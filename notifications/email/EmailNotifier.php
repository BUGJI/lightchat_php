<?php
/**
 * 邮件通知实现
 * 通过 SMTP 发送离线通知邮件
 */

require_once __DIR__ . '/../NotificationBase.php';

class EmailNotifier extends NotificationBase
{
    public function getName(): string
    {
        return '邮件通知';
    }

    /**
     * 发送邮件
     * @return array
     */
    public function send(): array
    {
        $to = $this->data['email'] ?? '';
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => '收件邮箱无效'];
        }

        $smtp = $this->config['smtp'] ?? [];
        if (empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password'])) {
            return ['success' => false, 'message' => 'SMTP 未配置'];
        }

        // 模板（优先使用用户自定义，否则用系统默认）
        $subject = $this->renderTemplate(
            $this->data['template_subject']
            ?? $this->config['templates']['subject']
            ?? '【LightChat】离线消息提醒'
        );
        $body = $this->renderTemplate(
            $this->data['template_body']
            ?? $this->config['templates']['body']
            ?? '您好 {nickname}，您有 {unread_count} 条未读消息。'
        );

        $from = $this->config['from'] ?? ['email' => 'noreply@example.com', 'name' => 'LightChat'];

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'From: ' . $this->encodeHeader($from['name']) . ' <' . $from['email'] . '>',
            'To: ' . $to,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'X-Mailer: LightChat/Notifier',
        ];

        $result = $this->smtpSend($to, $from['email'], implode("\r\n", $headers), $body);

        return $result
            ? ['success' => true, 'message' => '邮件已发送']
            : ['success' => false, 'message' => '邮件发送失败'];
    }

    /**
     * 通过 SMTP 发送邮件
     */
    private function smtpSend($to, $from, $headers, $body): bool
    {
        $smtp = $this->config['smtp'];
        $host = $smtp['host'];
        $port = (int)($smtp['port'] ?? 587);
        $username = $smtp['username'];
        $password = $smtp['password'];
        $encryption = $smtp['encryption'] ?? 'tls';
        $timeout = (int)($smtp['timeout'] ?? 10);

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen(($encryption === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return false;
        }

        $this->smtpCommand($socket, null); // 等待欢迎信息
        $this->smtpCommand($socket, 'EHLO ' . gethostname());

        if ($encryption === 'tls') {
            $this->smtpCommand($socket, 'STARTTLS');
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->smtpCommand($socket, 'EHLO ' . gethostname());
        }

        $this->smtpCommand($socket, 'AUTH LOGIN');
        $this->smtpCommand($socket, base64_encode($username));
        $this->smtpCommand($socket, base64_encode($password));

        $this->smtpCommand($socket, 'MAIL FROM:<' . $from . '>');
        $this->smtpCommand($socket, 'RCPT TO:<' . $to . '>');
        $this->smtpCommand($socket, 'DATA');
        $this->smtpCommand($socket, $headers . "\r\n\r\n" . $body . "\r\n.");
        $this->smtpCommand($socket, 'QUIT');

        fclose($socket);
        return true;
    }

    /**
     * 发送 SMTP 命令并读取响应
     */
    private function smtpCommand($socket, $cmd): string
    {
        if ($cmd !== null) {
            fwrite($socket, $cmd . "\r\n");
        }
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * 邮件头编码（处理中文）
     */
    private function encodeHeader($str): string
    {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
}
