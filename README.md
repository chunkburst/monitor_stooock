# Monitor Stooock

基于PHP的纯模型补货监控

## 🌟 主要功能

- **AI 智能识别**：利用 AI 模型自动识别网页中的服务器产品列表（VPS、独立服务器等）
- **实时监控**：监控服务器库存状态、价格变化
- **Telegram 通知**：通过 Telegram Bot 实时推送变化通知
- **原生多API密钥支持**：本地原生支持多个 AI API 密钥轮询（避免需要建立轮询池的麻烦）
- **自定义抓取规则**：支持为每个 URL 设置自定义的 AI 抓取指令
- **缓存机制**：本地缓存减少重复请求
- **历史记录**：保存监控历史，支持对比分析（以及锐评）

## 📦 特别功能
- **二次抓取**：通过指定AI行为，可引导其进行“二次抓取”，获取更为详细的数据
- **主动锐评**：AI 生成服务器锐评评价
- **执行频率控制**：支持为不同 URL 设置不同的监控频率（通过 [TimeUnit] 属性设置不同粒度）

## 📋 系统要求

- PHP 8.0 或更高版本
- cURL 扩展
- JSON 扩展
- 可写权限（用于缓存目录）

## 🚀 快速开始

### 1. 克隆项目

```bash
git clone https://github.com/chunkburst/monitor_stooock.git
cd monitor_stooock
```

### 2. 配置文件

编辑 `src/config.php` 文件：

```php
// 配置监控的 URL 列表
const MONITOR_URLS = [
    "https://example.com/servers" => "", // 基础监控
    "https://vps.com/specials" => "只识别价格低于$50的服务器", // 自定义 AI 指令
];

// 配置 Telegram Bot
const TELEGRAM_BOT_TOKEN = "your_telegram_bot_token";
const TELEGRAM_CHAT_ID = "your_telegram_chat_id";

//配置API（此处以硅基举例）
const AI_API_ENDPOINT = "https://api.siliconflow.cn/v1/chat/completions";
const AI_MODEL_NAME = "moonshotai/Kimi-K2-Instruct";
define('AI_API_KEYS', ['your_api_key_1', 'your_api_key_2']);
```

### 3. 运行监控

```bash
php src/monitor.php
```

### 4. 设置定时任务（可选）

使用 cron 设置定期执行：

```bash
# 每1分钟执行一次
*/1 * * * * /usr/bin/php /path/to/monitor_stooock/src/monitor.php
```

## ⚙️ 配置参数详解

### 核心配置

| 参数 | 说明 | 默认值 |
|------|------|--------|
| `MONITOR_URLS` | 监控的 URL 列表和自定义 AI 指令 | 数组 |
| `TELEGRAM_BOT_TOKEN` | Telegram Bot Token | - |
| `TELEGRAM_CHAT_ID` | Telegram 聊天 ID | - |
| `AI_API_ENDPOINT` | AI API 端点 | - |
| `AI_MODEL_NAME` | AI 模型名称 | - |
| `AI_API_KEYS` | AI API 密钥数组 | - |

### 性能配置

| 参数 | 说明 | 默认值 |
|------|------|--------|
| `MIN_NOTIFICATION_INTERVAL` | 最小通知间隔（秒） | 300 |
| `MAX_HISTORY_AGE_DAYS` | 历史记录保留天数 | 30 |
| `MAX_HISTORY_RECORDS` | 最大历史记录数 | 50 |
| `MAX_RETRIES` | 最大重试次数 | 2 |
| `RETRY_DELAY_SECONDS` | 重试延迟（秒） | 10 |
| `FETCH_TIMEOUT_SECONDS` | 抓取网页超时时间 | 20 |
| `AI_PARSE_TIMEOUT_SECONDS` | AI 解析超时时间 | 60 |
| `AI_EVAL_TIMEOUT_SECONDS` | AI 评价超时时间 | 60 |
| `TELEGRAM_TIMEOUT_SECONDS` | Telegram 发送超时时间 | 25 |

### 日志配置

| 参数 | 说明 | 可选值 |
|------|------|--------|
| `LOG_LEVEL` | 日志级别 | 'ERROR', 'INFO' |

## 📁 目录结构

```
monitor_stooock/
├── src/
│   ├── monitor.php         # 主监控脚本
│   ├── config.php          # 配置文件
│   └── keys.txt            # API 密钥文件（可选）
├── cache/                  # 缓存目录（自动创建）
│   ├── *.cache            # URL 缓存文件
│   └── *.execcount        # 执行计数文件
├── servers_history.json   # 服务器历史记录
└── README.md              # 说明文档
```

## 📨 通知消息模板

系统支持自定义消息模板，在 `config.php` 中的 `MESSAGE_TEMPLATES` 配置：

- `new_server_found`: 发现新服务器
- `stock_changed`: 库存变化
- `price_changed`: 价格变化
- `server_removed`: 服务器下架
- `evaluation`: AI 评价
- `summary`: 监控总结

## 🔄 工作流程(Workflow)

1. **抓取网页**：从配置的 URL 抓取 HTML 内容
2. **识别功能**：使用模型识别服务器列表
3. **数据对比**：与历史记录对比，检测变化
4. **生成评价**：AI生成锐评
5. **发送通知**：通过 Telegram 发送变化通知
6. **保存记录**：更新缓存和历史记录

## 🛡️ 错误处理

- **自动重试**：网络请求失败时自动重试
- **API 密钥轮询**：遇到限流时自动切换 API 密钥
- **降级处理**：AI 服务不可用时跳过相关功能
- **详细日志**：记录详细的错误信息便于调试

## 📝 自定义 AI 抓取指令示例

在 `MONITOR_URLS` 中为每个 URL 设置自定义指令：

```php
const MONITOR_URLS = [
    "https://example.com/vps" => "只识别价格在$10-$50之间的服务器",
    
    "https://example.com/dedicated" => "只识别内存大于16GB的独立服务器",
    
    "https://example.com/specials" => "只识别标记为有货或可购买的服务器",
    
    "https://example.com/deals" => "只识别带有折扣且价格低于$100的服务器",
];
```

## 📄 许可证

MIT License

## 📞 联系我

如有问题或建议，可以在TG上pm我 (@auto_ccb)