"""
LightChat Bot 示例
===================
先设置环境变量，再运行:

    export LIGHTCHAT_API_BASE="https://chat.bugcode.cc"
    export LIGHTCHAT_BOT_KEY="bot_xxxx..."
    python3 example_bot.py

也可以用代码直接传:
    bot = LightChatBot(api_base="...", bot_key="...")
"""

import os
import sys

# 添加 SDK 路径
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lightchat_bot import LightChatBot, LightChatBotError

API_BASE = os.environ.get("LIGHTCHAT_API_BASE", "https://chat.bugcode.cc")
BOT_KEY  = os.environ.get("LIGHTCHAT_BOT_KEY", "")


# ─────────────── 自定义 Bot ───────────────
class MyBot:
    def __init__(self, bot):
        self.bot = bot

    def handle_message(self, channel_id, msg):
        """所有频道消息都会到这里"""
        content = msg.get("content", "").strip()
        username = msg.get("username", "未知")

        print(f"[频道 {channel_id}] {username}: {content}")

        # ── 命令路由 ──
        if content == "/ping":
            self.bot.send_message(channel_id, "pong! 🏓")

        elif content == "/time":
            import datetime
            now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            self.bot.send_message(channel_id, f"现在是 {now}")

        elif content == "/help":
            self.bot.send_message(channel_id,
                "命令列表:\n"
                "  /ping   - 测试连通\n"
                "  /time   - 当前时间\n"
                "  /info   - Bot 信息\n"
                "  /help   - 本帮助"
            )

        elif content == "/info":
            self.bot.send_message(channel_id,
                f"🤖 {self.bot.username}\n"
                f"   API: {self.bot.api_base}\n"
                f"   用户 ID: {self.bot.user_id}"
            )

        elif content.startswith("/echo "):
            text = content[6:].strip()
            if text:
                self.bot.send_message(channel_id, text)

        elif content.startswith("/upload "):
            # 上传文件到频道
            file_path = content[8:].strip()
            try:
                result = self.bot.send_file(channel_id, file_path)
                self.bot.send_message(channel_id, f"✅ 已发送: {result.get('file_url', '?')}")
            except FileNotFoundError:
                self.bot.send_message(channel_id, "❌ 文件不存在")
            except LightChatBotError as e:
                self.bot.send_message(channel_id, f"❌ 上传失败: {e.message}")

        elif content.startswith("/search "):
            query = content[8:].strip()
            users = self.bot.search_users(query)
            if users:
                names = [u["username"] for u in users[:5]]
                self.bot.send_message(channel_id, "搜索结果: " + ", ".join(names))
            else:
                self.bot.send_message(channel_id, "未找到用户")

    def handle_private(self, chat_id, msg):
        """私聊消息"""
        content = msg.get("content", "").strip()
        print(f"[私聊 {chat_id}] {msg.get('username')}: {content}")

        if content == "/help":
            self.bot.send_private(chat_id,
                "私聊命令:\n"
                "  /help - 帮助\n"
                "  /time - 当前时间\n"
                "  /ping - pong!"
            )
        elif content == "/ping":
            self.bot.send_private(chat_id, "pong!")
        elif content == "/time":
            import datetime
            now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            self.bot.send_private(chat_id, f"现在是 {now}")
        else:
            self.bot.send_private(chat_id, f"收到: {content}")

    def on_error(self, err):
        print(f"[错误] {err}")


# ─────────────── 入口 ───────────────
if __name__ == "__main__":
    if not BOT_KEY:
        print("请设置环境变量 LIGHTCHAT_BOT_KEY")
        print("或者直接修改脚本中的 BOT_KEY")
        sys.exit(1)

    print(f"🤖 启动 Bot...")
    print(f"   API: {API_BASE}")

    bot = LightChatBot(api_base=API_BASE, bot_key=BOT_KEY)

    # 获取身份
    try:
        profile = bot.get_profile()
        print(f"   已登录: {profile.get('username')} (ID: {profile.get('id')})")
    except LightChatBotError as e:
        print(f"❌ 登录失败: {e}")
        sys.exit(1)

    # 列出已加入的频道
    channels = bot.get_channels()
    print(f"   频道: {len(channels)} 个")
    for ch in channels:
        print(f"      #{ch.get('display_name', ch.get('name'))} (ID: {ch['id']})")

    if not channels:
        print("   ⚠️  没有加入任何频道，Bot 无法接收消息。")
        print("   请在 Bot 管理面板中将 Bot 加入频道。")

    # 注册回调
    app = MyBot(bot)
    bot.on_message(app.handle_message)
    bot.on_private_message(app.handle_private)
    bot.on_error(app.on_error)

    print(f"   💡 试试在频道里发送 /help")
    print(f"   Ctrl+C 停止\n")

    try:
        bot.run()
    except KeyboardInterrupt:
        print("\n👋 Bot 已停止")
