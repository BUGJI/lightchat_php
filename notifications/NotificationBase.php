<?php
/**
 * 通知方法抽象基类
 * 所有通知方式需继承此类并实现 send()
 */
abstract class NotificationBase
{
    /** @var array 通知方式配置（从 config 注入） */
    protected $config;

    /** @var array 通知数据（收件人用户信息、消息摘要等） */
    protected $data;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * 注入通知数据
     * @param array $data  包含: nickname, email, pushplus_key, unread_count,
     *                      messages_preview, sender_name, last_message_time, ...
     */
    public function setData(array $data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 用数据替换模板中的 {变量}
     * @param string $template 模板字符串
     * @return string
     */
    protected function renderTemplate($template)
    {
        if (empty($this->data)) {
            return $template;
        }
        $vars = [];
        foreach ($this->data as $key => $val) {
            $vars['{' . $key . '}'] = is_scalar($val) ? (string)$val : '';
        }
        return strtr($template, $vars);
    }

    /**
     * 发送通知
     * @return array ['success' => bool, 'message' => string]
     */
    abstract public function send(): array;

    /**
     * 获取通知方式名称
     * @return string
     */
    abstract public function getName(): string;
}
