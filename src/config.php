<?php

// --- Configuration ---
// 格式: "URL" => "自定义 AI 指令"
// 如果没有自定义指令，可以使用空字符串 ""
const MONITOR_URLS = [
];

// Telegram Bot Token 和 Chat ID
// 替换为你的实际 Telegram Bot Token 和 Chat ID
const TELEGRAM_BOT_TOKEN = "autoccb";
const TELEGRAM_CHAT_ID = "114514";


// AI Model configuration
const AI_API_ENDPOINT = "https://api.siliconflow.cn/v1/chat/completions";
//const AI_KEYS_FILE = __DIR__ . "/keys.txt"; // API密钥文件路径
const AI_MODEL_NAME = "moonshotai/Kimi-K2-Instruct"; // e.g., "gpt-3.5-turbo" or "gemini-1.5-flash-latest"

// 定义 AI_API_KEYS 常量
//define('AI_API_KEYS', loadApiKeysFromFile(AI_KEYS_FILE));
define('AI_API_KEYS', ['sksksksksksksksk']);

// 文件路径
const CACHE_FILE = __DIR__ . '/cache.json';
const SERVERS_HISTORY_FILE = __DIR__ . "/servers_history.json";
const URL_CACHE_DIR = __DIR__ . "/cache";
// const LOG_FILE = __DIR__ . "/monitor.log"; // 日志文件 (可选)

const LOG_LEVEL = 'ERROR';

// --- Settings ---

const MIN_NOTIFICATION_INTERVAL = 300;
const MAX_HISTORY_AGE_DAYS = 30;

const MAX_HISTORY_RECORDS = 50;
const MAX_RETRIES = 2;
const RETRY_DELAY_SECONDS = 10;
const FETCH_TIMEOUT_SECONDS = 20;
const AI_PARSE_TIMEOUT_SECONDS = 60;
const AI_EVAL_TIMEOUT_SECONDS = 60;
const TELEGRAM_TIMEOUT_SECONDS = 25;

// --- Message Templates ---
// 定义消息模板，可以使用 {{placeholder}} 语法
const MESSAGE_TEMPLATES = [
    'evaluation' => "评价：{{evaluation_text}}\n",
    'new_server_found' => "发现新服务器！\n{{server_details}}",
    'stock_changed' => "库存变化！\n{{server_details}}\n库存状态: {{stock}}",
    'price_changed' => "价格变化！\n{{server_details}}\n新价格: {{price}}",
    'all_servers' => "当前所有服务器：\n{{server_details}}",
    'server_removed' => "服务器下架或消失！\n{{server_details}}",
    'summary' => "本次监控总结：\n总计监控 {{url_count}} 个页面，发现 {{server_count}} 个服务器。\n{{evaluation}}\n",
    'error' => "监控出错！\nURL: {{url}}\n错误信息: {{error_message}}\n",
    'server_item' => " 【{{original_name}}】 ({{cost_effective_name}})\n CPU：{{cpu}}\n 内存：{{ram}}\n 存储：{{storage}}\n 带宽/流量：{{bandwidth_traffic}}\n IP： {{ip_address}}\n 价格(折扣)：{{price}} （{{discount}}）\n 库存：{{stock}}\n ®备注：{{remark}}\n 订购链接：{{order_url}}\n-----\n",
];


// 从文本文件动态加载 API 密钥
function loadApiKeysFromFile($filePath) {
    if (!file_exists($filePath)) {
        error_log("API密钥文件不存在: " . $filePath);
        return [];
    }
    
    $keys = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($keys)) {
        error_log("API密钥文件为空或格式不正确: " . $filePath);
        return [];
    }
    
    // 过滤空行和注释行（以#开头）
    $keys = array_filter($keys, function($line) {
        $line = trim($line);
        return !empty($line) && $line[0] !== '#';
    });
    
    return $keys;
}
