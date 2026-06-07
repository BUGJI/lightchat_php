"""
LightChat Bot Python SDK
=========================
零外部依赖，仅用 Python 标准库。支持 Python 3.6+

用法示例:
    from lightchat_bot import LightChatBot

    bot = LightChatBot(api_base="https://chat.bugcode.cc", bot_key="bot_xxxx")

    @bot.on_message
    def handle(channel_id, msg):
        if msg.get("content") == "/ping":
            bot.send_message(channel_id, "pong!")

    @bot.on_private_message
    def handle_pm(chat_id, msg):
        if msg.get("content") == "/help":
            bot.send_private(chat_id, "你好，我是 Bot！")

    bot.run(poll_interval=3)
"""

import json
import time
import threading
import urllib.request
import urllib.error
import urllib.parse
import os
import ssl
import mimetypes
from functools import wraps

__version__ = "1.0.0"


class LightChatBotError(Exception):
    """LightChat API 错误"""

    def __init__(self, status, message, data=None):
        self.status = status
        self.message = message
        self.data = data or {}
        super().__init__(f"[{status}] {message}")


class LightChatBot:
    """LightChat Bot 客户端"""

    def __init__(self, api_base, bot_key, timeout=30, verify_ssl=True):
        """
        初始化 Bot 客户端

        :param api_base:   LightChat API 根地址，如 "https://chat.bugcode.cc"
        :param bot_key:    Bot 的 API Key（创建 Bot 时返回）
        :param timeout:    HTTP 请求超时（秒）
        :param verify_ssl: 是否验证 SSL 证书
        """
        self.api_base = api_base.rstrip("/")
        self.bot_key = bot_key
        self.timeout = timeout
        self.verify_ssl = verify_ssl

        # 事件回调
        self._on_message_cb = None
        self._on_private_message_cb = None
        self._on_error_cb = None

        # 运行时
        self._running = False
        self._poll_thread = None
        self._since_id = 0  # 消息去重游标
        self._channel_ids = []  # 监听的频道列表

        # 缓存
        self._user_id = None
        self._username = None

    # ════════════════════════════════════════════
    #  事件装饰器
    # ════════════════════════════════════════════

    def on_message(self, func):
        """注册频道消息回调。回调签名: fn(channel_id: int, message: dict)"""
        self._on_message_cb = func
        return func

    def on_private_message(self, func):
        """注册私聊消息回调。回调签名: fn(chat_id: int, message: dict)"""
        self._on_private_message_cb = func
        return func

    def on_error(self, func):
        """注册错误回调。回调签名: fn(error: Exception)"""
        self._on_error_cb = func
        return func

    # ════════════════════════════════════════════
    #  底层 HTTP
    # ════════════════════════════════════════════

    def _url(self, path):
        return self.api_base + "/api" + path

    def _request(self, method, path, body=None, files=None, raw=False):
        """
        发送 HTTP 请求，自动添加 X-Bot-Key 认证头
        :return: (status, parsed_json) 或 raw=bytes
        """
        url = self._url(path)
        headers = {"X-Bot-Key": self.bot_key}

        if files:
            # multipart 上传
            boundary = "----LightChatBotBoundary"
            data = self._encode_multipart(files, body or {})
            headers["Content-Type"] = "multipart/form-data; boundary=" + boundary
            req_body = data
        elif body is not None:
            headers["Content-Type"] = "application/json"
            req_body = json.dumps(body, ensure_ascii=False).encode("utf-8")
        else:
            req_body = None

        ctx = None if self.verify_ssl else ssl._create_unverified_context()

        try:
            req = urllib.request.Request(url, data=req_body, headers=headers, method=method)
            resp = urllib.request.urlopen(req, timeout=self.timeout, context=ctx)
        except urllib.error.HTTPError as e:
            err_body = e.read().decode("utf-8", errors="replace")
            try:
                err_data = json.loads(err_body)
            except Exception:
                err_data = {"error": "http_error", "message": err_body[:200]}
            raise LightChatBotError(e.code, err_data.get("message", str(e)), err_data)
        except Exception as e:
            raise LightChatBotError(0, str(e)) from e

        if raw:
            return resp.status, resp.read()

        text = resp.read().decode("utf-8")
        try:
            return resp.status, json.loads(text)
        except json.JSONDecodeError:
            return resp.status, text

    @staticmethod
    def _encode_multipart(files, fields):
        """手工构建 multipart/form-data"""
        boundary = "----LightChatBotBoundary"
        body = b""
        for key, val in fields.items():
            body += f"--{boundary}\r\n".encode()
            body += f'Content-Disposition: form-data; name="{key}"\r\n\r\n'.encode()
            body += str(val).encode() + b"\r\n"
        for field_name, file_path in files.items():
            fname = os.path.basename(file_path)
            mime, _ = mimetypes.guess_type(file_path)
            if mime is None:
                mime = "application/octet-stream"
            with open(file_path, "rb") as f:
                content = f.read()
            body += f"--{boundary}\r\n".encode()
            body += f'Content-Disposition: form-data; name="{field_name}"; filename="{fname}"\r\n'.encode()
            body += f"Content-Type: {mime}\r\n\r\n".encode()
            body += content + b"\r\n"
        body += f"--{boundary}--\r\n".encode()
        return body

    def _get(self, path, params=None):
        if params:
            qs = urllib.parse.urlencode(params)
            path = path + "?" + qs
        return self._request("GET", path)

    def _post(self, path, body=None, files=None):
        return self._request("POST", path, body=body, files=files)

    def _ok(self, result):
        """检查 API 响应是否成功"""
        status, data = result
        if isinstance(data, dict) and data.get("success") is not None:
            if not data["success"]:
                raise LightChatBotError(status, data.get("message", "未知错误"), data)
        return data

    # ════════════════════════════════════════════
    #  身份 / 缓存
    # ════════════════════════════════════════════

    def get_profile(self):
        """获取 Bot 自己的用户信息"""
        _, data = self._get("/users/profile.php")
        if isinstance(data, dict) and "user" in data:
            u = data["user"]
            self._user_id = u.get("id")
            self._username = u.get("username")
            return u
        return data

    @property
    def user_id(self):
        if self._user_id is None:
            self.get_profile()
        return self._user_id

    @property
    def username(self):
        if self._username is None:
            self.get_profile()
        return self._username

    # ════════════════════════════════════════════
    #  频道
    # ════════════════════════════════════════════

    def get_channels(self):
        """获取频道列表 → [{"id":1,"name":...}, ...]"""
        _, data = self._get("/channels/list.php")
        return data.get("channels", []) if isinstance(data, dict) else []

    def join_channel(self, channel_id):
        """加入频道"""
        _, data = self._post("/channels/join.php", {"channel_id": int(channel_id)})
        return data

    def leave_channel(self, channel_id):
        """离开频道"""
        _, data = self._post("/channels/leave.php", {"channel_id": int(channel_id)})
        return data

    def create_channel(self, name, channel_type="public"):
        """创建频道（需要权限）"""
        _, data = self._post("/channels/create.php", {
            "name": name,
            "type": channel_type,
        })
        return data

    # ════════════════════════════════════════════
    #  消息
    # ════════════════════════════════════════════

    def send_message(self, channel_id, content, msg_type="text",
                     parent_id=0, mentioned_users=None, file_id=None):
        """
        发送消息到频道
        :param channel_id:     频道 ID
        :param content:        消息内容
        :param msg_type:       消息类型 (text/image/file/system)
        :param parent_id:      引用回复的消息 ID
        :param mentioned_users: @ 的用户 ID 列表
        :param file_id:        关联上传文件 ID
        :return: {"success":true,"message_id":123}
        """
        body = {
            "channel_id": int(channel_id),
            "content": content,
            "type": msg_type,
        }
        if parent_id:
            body["parent_id"] = int(parent_id)
        if mentioned_users:
            body["mentioned_users"] = [int(u) for u in mentioned_users]
        if file_id:
            body["file_id"] = int(file_id)

        _, data = self._post("/messages/send.php", body)
        return data

    def get_history(self, channel_id, limit=50, before_id=None):
        """
        获取频道历史消息
        :return: {"messages": [...], "has_more": bool}
        """
        params = {"channel_id": int(channel_id), "limit": limit}
        if before_id:
            params["before_id"] = int(before_id)
        _, data = self._get("/messages/history.php", params)
        return data

    def get_private_history(self, chat_id, limit=50, before_id=None):
        """获取私聊历史"""
        params = {"chat_id": int(chat_id), "limit": limit}
        if before_id:
            params["before_id"] = int(before_id)
        _, data = self._get("/private/history.php", params)
        return data

    def delete_message(self, message_id):
        """删除自己的消息"""
        _, data = self._post("/messages/delete.php", {"message_id": int(message_id)})
        return data

    # ════════════════════════════════════════════
    #  私聊
    # ════════════════════════════════════════════

    def send_private(self, chat_id, content):
        """
        发送私聊消息
        :param chat_id: 私聊会话 ID（也可以是对方用户 ID，会自动创建会话）
        :param content:  消息内容
        """
        _, data = self._post("/private/send.php", {
            "to_user_id": int(chat_id),
            "content": content,
        })
        return data

    def get_private_chats(self):
        """获取私聊列表（含未读数）"""
        _, data = self._get("/private/list.php")
        return data.get("chats", []) if isinstance(data, dict) else []

    # ════════════════════════════════════════════
    #  文件
    # ════════════════════════════════════════════

    def upload_file(self, file_path):
        """
        上传文件
        :return: {"success":true, "file_id":123, "file_url":"...", ...}
        """
        if not os.path.isfile(file_path):
            raise FileNotFoundError(file_path)

        _, data = self._post("/files/upload.php", files={"file": file_path})
        return data

    def send_image(self, channel_id, image_path, caption=""):
        """快捷方法：上传图片并发送到频道"""
        up = self.upload_file(image_path)
        return self.send_message(
            channel_id=channel_id,
            content=caption or os.path.basename(image_path),
            msg_type="image",
            file_id=up.get("file_id"),
        )

    def send_file(self, channel_id, file_path, caption=""):
        """快捷方法：上传文件并发送到频道"""
        up = self.upload_file(file_path)
        return self.send_message(
            channel_id=channel_id,
            content=caption or os.path.basename(file_path),
            msg_type="file",
            file_id=up.get("file_id"),
        )

    # ════════════════════════════════════════════
    #  用户
    # ════════════════════════════════════════════

    def search_users(self, query):
        """搜索用户"""
        _, data = self._get("/users/search.php", {"q": query})
        return data.get("users", []) if isinstance(data, dict) else []

    def get_user_list(self):
        """获取所有用户列表"""
        _, data = self._get("/users/list.php")
        return data.get("users", []) if isinstance(data, dict) else []

    def get_user_profile(self, user_id):
        """获取指定用户的资料卡"""
        _, data = self._get("/users/profile.php", {"user_id": int(user_id)})
        return data.get("user", {}) if isinstance(data, dict) else {}

    # ════════════════════════════════════════════
    #  长轮询 / 消息接收
    # ════════════════════════════════════════════

    def poll(self, channel_ids=None, since_id=None, timeout=25):
        """
        单次轮询：检查指定频道的新消息
        :param channel_ids: 频道 ID 列表，None 表示自动从已加入频道获取
        :param since_id:    上次的 latest_id
        :param timeout:     长轮询超时（最大 30 秒）
        :return: {"messages": [...], "latest_id": int}
        """
        if channel_ids is None:
            channels_joined = self.get_channels()
            channel_ids = [ch["id"] for ch in channels_joined]

        if not channel_ids:
            return {"messages": [], "latest_id": since_id or 0}

        params = {
            "channels": ",".join(str(c) for c in channel_ids),
            "since_id": since_id or self._since_id,
            "timeout": min(timeout, 30),
        }

        _, data = self._get("/messages/poll.php", params)
        if isinstance(data, dict) and data.get("success") is not False:
            msgs = data.get("messages", [])
            if msgs:
                self._since_id = data.get("latest_id", self._since_id)
            return data
        return {"messages": [], "latest_id": since_id or 0}

    def run(self, poll_interval=3, channel_ids=None, block=True):
        """
        启动消息监听循环（阻塞）

        用法:
            bot.run()                     # 监听所有已加入的频道
            bot.run(channel_ids=[1,2])   # 只监听指定频道
            bot.run(block=False)         # 后台线程运行

        :param poll_interval: 轮询间隔（秒），长轮询也会自动回退到这个值
        :param channel_ids:   监听的频道 ID 列表，None=全部已加入
        :param block:         True=阻塞当前线程, False=后台线程
        """
        self._running = True

        if channel_ids is not None:
            self._channel_ids = [int(c) for c in channel_ids]
        else:
            channels = self.get_channels()
            self._channel_ids = [c["id"] for c in channels]

        def _loop():
            since_id = 0
            while self._running:
                try:
                    result = self.poll(
                        channel_ids=self._channel_ids,
                        since_id=since_id,
                        timeout=poll_interval + 2,
                    )
                    since_id = result.get("latest_id", since_id)

                    for msg in result.get("messages", []):
                        self._dispatch(msg)

                except LightChatBotError as e:
                    if self._on_error_cb:
                        self._on_error_cb(e)
                    time.sleep(5)  # 错误后等 5 秒再试
                except Exception as e:
                    if self._on_error_cb:
                        self._on_error_cb(e)
                    time.sleep(5)

        if block:
            _loop()
        else:
            self._poll_thread = threading.Thread(target=_loop, daemon=True)
            self._poll_thread.start()

    def stop(self):
        """停止消息监听"""
        self._running = False
        if self._poll_thread and self._poll_thread.is_alive():
            self._poll_thread.join(timeout=5)

    def _dispatch(self, msg):
        """根据消息类型分发到对应回调"""
        if "channel_id" in msg:
            if self._on_message_cb:
                try:
                    self._on_message_cb(msg["channel_id"], msg)
                except Exception as e:
                    if self._on_error_cb:
                        self._on_error_cb(e)
        elif "private_chat_id" in msg:
            if self._on_private_message_cb:
                try:
                    self._on_private_message_cb(msg["private_chat_id"], msg)
                except Exception as e:
                    if self._on_error_cb:
                        self._on_error_cb(e)

    # ════════════════════════════════════════════
    #  上下文管理器
    # ════════════════════════════════════════════

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        self.stop()
        return False

    # ════════════════════════════════════════════
    #  repr
    # ════════════════════════════════════════════

    def __repr__(self):
        return f"<LightChatBot api={self.api_base} key={self.bot_key[:12]}...>"
