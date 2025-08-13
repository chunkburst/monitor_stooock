<?php
$startTime = microtime(true);

// 包含配置文件
require_once __DIR__ . '/config.php';

// 定义默认配置（如果配置中未定义）
if (!defined('MAX_HISTORY_AGE_DAYS')) {
    define('MAX_HISTORY_AGE_DAYS', 30); // 默认保留30天的历史记录
}

if (!defined('MIN_NOTIFICATION_INTERVAL')) {
    define('MIN_NOTIFICATION_INTERVAL', 3600); // 默认最小通知间隔1小时
}

// 创建缓存目录（如果不存在）
$cacheDir = __DIR__ . "/cache";
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

// --- Helper Functions ---

/**
 * 获取下一个 AI API Key (轮询)
 * @return string|null 可用的 API Key 或 null (如果没有配置密钥)
 */
function getNextAiApiKey(): ?string {
    if (empty(AI_API_KEYS)) {
        return null;
    }

    // 随机选择一个密钥索引
    $randomIndex = array_rand(AI_API_KEYS);

    return AI_API_KEYS[$randomIndex];
}

/**
 * 记录日志消息
 * @param string $message 要记录的消息
 * @param string $level 日志级别 ('INFO', 'ERROR')
 */
function logMessage(string $message, string $level = 'INFO'): void {
    // 定义日志级别优先级
    $levels = ['ERROR' => 1, 'INFO' => 2];

    // 检查当前消息级别是否高于或等于配置的日志级别
    if (!isset($levels[$level]) || !isset($levels[LOG_LEVEL]) || $levels[$level] > $levels[LOG_LEVEL]) {
        return; // 不记录低于配置级别的消息
    }

    $timestamp = date('Y-m-d H:i:s');
    $output = "[{$timestamp}] [{$level}] {$message}\n";

    echo $output;
    // if (defined('LOG_FILE')) {
    //     file_put_contents(LOG_FILE, $output, FILE_APPEND);
    // }
}

/**
 * 发送 Telegram 消息
 * @param string $message 要发送的消息 (支持 HTML)
 * @return bool 是否发送成功
 */
function sendTelegramMessage(string $message): bool {
    if (empty($message)) {
        logMessage("Attempted to send empty Telegram message.", 'INFO');
        return false;
    }

    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID') || TELEGRAM_BOT_TOKEN === 'YOUR_TELEGRAM_BOT_TOKEN' || TELEGRAM_CHAT_ID === 'YOUR_TELEGRAM_CHAT_ID') {
        logMessage("Telegram configuration is incomplete. Cannot send message.", 'ERROR');
        return false;
    }

    $api_url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $payload = json_encode([
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ]);

    for ($i = 0; $i <= MAX_RETRIES; $i++) {
        if ($i > 0) {
            logMessage("Retrying Telegram message send (Attempt " . ($i + 1) . ")...", 'INFO');
            sleep(RETRY_DELAY_SECONDS);
        }

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TELEGRAM_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignore SSL certificate verification

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpcode === 200) {
            $responseData = json_decode($response, true);
            if ($responseData && $responseData['ok']) {
                 logMessage("Telegram message sent successfully (Attempt " . ($i + 1) . ").", 'INFO');
                 return true;
            } else {
                 logMessage("Telegram API reported error (Attempt " . ($i + 1) . ", HTTP {$httpcode}): " . ($response ? $response : 'No response body'), 'ERROR');
            }
        } else {
            logMessage("Error sending Telegram message (Attempt " . ($i + 1) . ", HTTP {$httpcode}): {$error}. Response: " . ($response ? $response : 'No response body'), 'ERROR');
        }
    }

    logMessage("Failed to send Telegram message after " . (MAX_RETRIES + 1) . " attempts.", 'ERROR');
    return false;
}

/**
 * 抓取网页内容
 * @param string $url 目标 URL
 * @return string|null 网页内容或 null (失败)
 */
function fetchPageContent(string $url): ?string {
    logMessage("Fetching page content from: {$url}", 'INFO');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, FETCH_TIMEOUT_SECONDS);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; ServerMonitor/1.0; +https://example.com/monitor)'); // Custom User-Agent
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignore SSL certificate verification
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects

    $content = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpcode !== 200) {
        logMessage("Error fetching page content from {$url} (HTTP {$httpcode}): {$error}", 'ERROR');
        return null;
    }
    logMessage("Successfully fetched page content (HTTP {$httpcode}).", 'INFO');
    return $content;
}

/**
 * 从字符串中提取 JSON 字符串 (处理 AI 可能返回的额外文本或 markdown)
 * @param string $text 包含 JSON 的文本
 * @return string|null 提取到的 JSON 字符串或 null
 */
function extractJsonString(string $text): ?string {
    // 查找第一个 { 和最后一个 }
    $firstBrace = strpos($text, '{');
    $lastBrace = strrpos($text, '}');

    // 查找第一个 [ 和最后一个 ]
    $firstBracket = strpos($text, '[');
    $lastBracket = strrpos($text, ']');

    $jsonString = null;

    // 优先匹配 JSON 数组
    if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
         $jsonString = substr($text, $firstBracket, $lastBracket - $firstBracket + 1);
    } elseif ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
         // 如果没有数组，尝试匹配 JSON 对象
         $jsonString = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
    }

    // 移除可能的 markdown 代码块标记
    if ($jsonString) {
        $jsonString = str_replace(['```json', '```'], '', $jsonString);
        $jsonString = trim($jsonString);
    }


    return $jsonString;
}


/**
 * 加载服务器历史记录
 * @param string $filePath 历史文件路径
 * @return array 服务器历史记录数组
 */
function loadServersHistory(string $filePath): array {
    if (file_exists($filePath)) {
        $data = file_get_contents($filePath);
        if ($data === false) {
            logMessage("Error reading servers history file: {$filePath}", 'ERROR');
            return [];
        }
        $history = json_decode($data, true);
        if ($history === null && json_last_error() !== JSON_ERROR_NONE) {
            logMessage("Error decoding JSON from {$filePath}: " . json_last_error_msg(), 'ERROR');
            // If decoding fails, return empty history to avoid errors, but log it
            return [];
        }
        // Ensure history is an associative array (URL_MD5 => server_history_map)
        // And each server_history_map is an associative array (SERVER_KEY_HASH => { server_data, last_notified_stock })
        if (!is_array($history)) {
             logMessage("History file content is not a valid array. Returning empty history.", 'ERROR');
             return [];
        }
        // Basic validation of the structure
        foreach ($history as $urlHash => $serverHistoryMap) {
            if (!is_array($serverHistoryMap)) {
                 logMessage("History for URL hash {$urlHash} is not a valid array. Returning empty history.", 'ERROR');
                 return [];
            }
            foreach ($serverHistoryMap as $serverKeyHash => $serverRecord) {
                if (!is_array($serverRecord) || !isset($serverRecord['server_data']) || !isset($serverRecord['last_notified_stock'])) {
                     logMessage("History record for server key hash {$serverKeyHash} under URL hash {$urlHash} is invalid. Returning empty history.", 'ERROR');
                     return [];
                }
            }
        }
        logMessage("Successfully loaded history for " . count($history) . " URLs from {$filePath}.", 'INFO');
        return $history;
    } else {
        logMessage("Servers history file not found: {$filePath}. Starting with empty history.", 'INFO');
        return [];
    }
}

/**
 * 保存服务器历史记录
 * @param string $filePath 历史文件路径
 * @param array $history 要保存的历史记录数组
 * @return bool 是否保存成功
 */
function saveServersHistory(string $filePath, array $history): bool {
    // History is an associative array (URL_MD5 => server_history_map)
    // server_history_map is an associative array (SERVER_KEY_HASH => { server_data, last_notified_stock })

    $data = json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($data === false) {
         logMessage("Error encoding JSON for {$filePath}: " . json_last_error_msg(), 'ERROR');
         return false;
    }
    // Ensure directory exists
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // Use file_put_contents with LOCK_EX to prevent race conditions
    if (file_put_contents($filePath, $data, LOCK_EX) === false) {
        logMessage("Error writing servers history file: {$filePath}", 'ERROR');
        return false;
    }
    logMessage("Saved history for " . count($history) . " URLs to {$filePath}.", 'INFO');
    return true;
}

/**
 * 加载本地缓存
 * @param string $urlHash URL的哈希值
 * @return array 缓存数据数组
 */
function loadCache(string $urlHash): array {
    $cacheDir = URL_CACHE_DIR;
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . "/{$urlHash}.cache";
    if (file_exists($cacheFile)) {
        $data = file_get_contents($cacheFile);
        if ($data === false) {
            logMessage("Error reading cache file: {$cacheFile}", 'ERROR');
            return [];
        }
        $cache = json_decode($data, true);
        if ($cache === null && json_last_error() !== JSON_ERROR_NONE) {
            logMessage("Error decoding JSON from {$cacheFile}: " . json_last_error_msg(), 'ERROR');
            return [];
        }
        logMessage("Successfully loaded cache for URL hash {$urlHash}.", 'INFO');
        return $cache;
    } else {
        logMessage("Cache file not found for URL hash {$urlHash}. Starting with empty cache.", 'INFO');
        return [];
    }
}

/**
 * 保存本地缓存
 * @param string $urlHash URL的哈希值
 * @param array $data 要缓存的数据
 * @return bool 是否保存成功
 */
function saveCache(string $urlHash, array $data): bool {
    $cacheDir = __DIR__ . "/cache";
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . "/{$urlHash}.cache";
    $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($jsonData === false) {
        logMessage("Error encoding JSON for {$cacheFile}: " . json_last_error_msg(), 'ERROR');
        return false;
    }
    
    if (file_put_contents($cacheFile, $jsonData, LOCK_EX) === false) {
        logMessage("Error writing cache file: {$cacheFile}", 'ERROR');
        return false;
    }
    logMessage("Saved cache for URL hash {$urlHash}.", 'INFO');
    return true;
}

/**
 * 检查是否应该监控URL（基于执行次数单位）
 * @param string $url 监控URL
 * @param int $timeUnit 执行次数单位倍数（默认为1，表示每次都监控；如果为4，则每4次执行周期监控一次）
 * @return bool 是否应该监控
 */
function shouldMonitor(string $url, int $timeUnit = 1): bool {
    if ($timeUnit <= 1) {
        return true; // 如果执行次数单位为1或更小，则始终监控
    }
    
    $urlHash = md5($url);
    $cacheDir = __DIR__ . "/cache";
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }
    $executionCountFile = $cacheDir . "/{$urlHash}.execcount";
    
    // 读取当前执行次数
    $currentCount = 0;
    if (file_exists($executionCountFile)) {
        $currentCount = (int) file_get_contents($executionCountFile);
    }
    
    // 增加执行次数
    $currentCount++;
    
    // 检查是否达到监控周期
    if ($currentCount % $timeUnit === 0) {
        // 达到监控周期，重置计数器并返回true
        file_put_contents($executionCountFile, 0);
        logMessage("Monitoring URL {$url}: execution cycle reached (every {$timeUnit} executions).", 'INFO');
        return true;
    } else {
        // 未达到监控周期，更新计数器并返回false
        file_put_contents($executionCountFile, $currentCount);
        $remainingCycles = $timeUnit - ($currentCount % $timeUnit);
        logMessage("Skipping URL {$url}: {$remainingCycles} execution cycles remaining.", 'INFO');
        return false;
    }
}

// --- AI Interaction Functions ---

/**
 * 使用 AI 模型从 HTML 内容中识别服务器列表
 * @param string $htmlContent 网页的 HTML 内容
 * @param string $customInstruction 自定义 AI 抓取指令 (可选)
 * @return array|null 识别到的服务器列表数组或 null (失败)
 */
function useAiToIdentifyServersFromHtml(string $htmlContent, string $customInstruction = ''): array|string|null {
    if (empty($htmlContent)) {
        logMessage("No HTML content provided for AI parsing.", 'INFO');
        return []; // Returning empty array for no content is acceptable
    }

    $apiKey = getNextAiApiKey();
    if (!defined('AI_API_ENDPOINT') || !$apiKey || !defined('AI_MODEL_NAME') ||
        AI_API_ENDPOINT === 'YOUR_AI_API_ENDPOINT' || $apiKey === 'YOUR_AI_API_KEY' || AI_MODEL_NAME === 'YOUR_AI_MODEL_NAME') {
        logMessage("AI API configuration is incomplete or no valid API key available. Cannot use AI for parsing.", 'ERROR');
        return null; // Indicate failure due to missing config or key
    }

    // Craft the prompt to instruct AI to parse HTML and extract details into JSON
    // Emphasize looking for patterns within the text content due to changing structure
    $prompt = "以下是一个网页的 HTML 内容。请仔细阅读 HTML，识别并提取所有有效的服务器产品列表。\n";
    $prompt .= "一个有效的服务器产品列表通常包含以下特征的文本组合：CPU 型号（如 'Intel Core', 'AMD EPYC'）、内存大小（如 'GB DDR', 'GB RAM'）、存储类型和容量（如 'GB SSD', 'TB NVMe'）以及价格（包含 '$' 符号和数字，可能带有 '/year' 或 '/month'）。\n";
    $prompt .= "请在提供的 HTML 内容中，识别出所有符合这些文本特征组合的段落或文本块，并将每个识别出的服务器信息提取到指定的 JSON 格式中。\n";
    $prompt .= "请忽略导航、页脚、广告等不相关的文本。\n";

    // Add custom instruction if provided, integrating it into the filtering criteria
    if (!empty($customInstruction)) {
        $prompt .= "请只识别和提取符合以下要求的服务器：{$customInstruction}\n"; // Modified instruction
    } else {
        $prompt .= "请识别和提取所有有效的服务器产品列表。\n"; // Default instruction if no custom instruction
    }

    $prompt .= "对于每个识别出的服务器，请提取以下属性：\n";
    $prompt .= "- `original_name`: 服务器原始名称或主要标识（例如 CPU 型号）。\n";
    $prompt .= "- `cpu`: CPU 配置信息。\n";
    $prompt .= "- `ram`: 内存配置信息。\n";
    $prompt .= "- `storage`: 存储配置信息。\n";
    $prompt .= "- `price_value`: 服务器的价格数值（不包含货币符号）。\n";
    $prompt .= "- `currency`: 服务器的价格货币符号或代码（例如 '$', '¥', '€', 'USD', 'CNY', 'EUR'）。\n";
    $prompt .= "- `price`: 服务器的原始价格字符串（包含货币符号和单位，如 \"$77.57/year\"）。\n";
    $prompt .= "- `stock`: 服务器的库存状态。请优先识别表示“有货”的状态，例如页面上显示“有货”、“库存充足”、“立即购买”、“加入购物车”等文本。**只有在页面明确显示“缺货”、“已售罄”、“库存不足”等文本时，才标记为缺货。否则，如果无法明确判断为缺货，请标记为 '有货' 或 '未知'，不要轻易标记为缺货。**\n";
    $prompt .= "- `discount`: 服务器的折扣信息（例如 'OFF 程度' 或折扣百分比）。如果无折扣，请留空或标记为 '无'。\n";
    $prompt .= "- `bandwidth`: 宽带信息（例如 '1Gbps', '10Gbps'）。\n"; // Added bandwidth field
    $prompt .= "- `traffic`: 流量信息（例如 '无限流量', '10TB/月'）。\n"; // Added traffic field
    $prompt .= "- `ip_address`: IP 地址信息（例如 '1 IPv4', '/64 IPv6'）。\n"; // Added IP address field
    $prompt .= "- `remark`: 产品本身的备注信息。请仔细阅读页面内容，提取与该服务器相关的备注或额外说明。如果页面上没有明确的备注信息，请标记为 '无', 请不要在备注中写多余/重复的内容(即简明扼要)。\n";
    $prompt .= "- `order_url`: 与该服务器产品条目直接关联的“Order Now”或“订购”按钮/链接的完整 URL。请确保这个链接是针对当前识别到的服务器。\n";
    $prompt .= "请根据服务器的配置和价格，分析其性价比，并为其生成一个个性化的名称 (`cost_effective_name`)。\n";
    $prompt .= "请将识别出的服务器列表以 JSON 数组的格式返回。每个元素是一个 JSON 对象，包含上述属性(如果属性未知, 则默认填入 `Unkown`)。\n";
    $prompt .= "如果无法识别出任何有效的服务器列表，请返回一个空的 JSON 数组 `[]`。\n";
    $prompt .= "**重要提示**: 如果你无法直接识别服务器列表，但能在 HTML 中找到一个明确指向服务器产品列表页面的链接，请返回一个包含 `suggested_url` 字段的 JSON 对象，例如 `{\"suggested_url\": \"找到的链接\"}`。请确保 `suggested_url` 是一个完整的 URL。\n";
    $prompt .= "请确保返回的内容是有效的 JSON 格式，并且只包含 JSON，不要包含任何额外的解释性文本或markdown格式（如```json```）。\n\n";
    $prompt .= "期望的 JSON 格式示例 (服务器列表):\n";
    $prompt .= "[\n";
    $prompt .= "  {\n";
    $prompt .= "    \"cost_effective_name\": \"i5-6600T杜甫\",\n";
    $prompt .= "    \"original_name\": \"Intel Core i5-6600T\",\n";
    $prompt .= "    \"cpu\": \"Intel Core i5-6600T\",\n";
    $prompt .= "    \"ram\": \"16GB DDR4\",\n";
    $prompt .= "    \"storage\": \"1x256GB NVMe\",\n";
    $prompt .= "    \"price_value\": \"77.57\",\n";
    $prompt .= "    \"currency\": \"$\",\n";
    $prompt .= "    \"price\": \"$77.57/year\",\n";
    $prompt .= "    \"stock\": \"有货\",\n";
    $prompt .= "    \"discount\": \"无\",\n";
    $prompt .= "    \"remark\": \"产品备注信息或 无\"\n"; // 新增备注字段示例
    $prompt .= "  }\n";
    $prompt .= "]\n\n";
    $prompt .= "期望的 JSON 格式示例 (建议链接):\n";
    $prompt .= "{\n";
    $prompt .= "  \"suggested_url\": \"https://example.com/servers\"\n";
    $prompt .= "}\n\n";
    $prompt .= "HTML 内容：\n" . $htmlContent . "\n\n"; // Include the raw HTML
    $prompt .= "请返回有效的服务器列表的 JSON 数组或包含 suggested_url 的 JSON 对象：\n";


    $payload = json_encode([
        "model" => AI_MODEL_NAME,
        "messages" => [["role" => "user", "content" => $prompt]],
        "max_tokens" => 4000, // Increased max_tokens for detailed output
        "temperature" => 0.1
        // Some models support response_format
        // "response_format" => ["type" => "json_object"] // Uncomment if your model supports this
    ]);

    for ($i = 0; $i <= MAX_RETRIES; $i++) {
        if ($i > 0) {
            logMessage("Retrying AI API call (Attempt " . ($i + 1) . ")...", 'INFO');
            sleep(RETRY_DELAY_SECONDS);
        }

        $ch = curl_init(AI_API_ENDPOINT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer " . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignore SSL certificate verification
        curl_setopt($ch, CURLOPT_TIMEOUT, AI_PARSE_TIMEOUT_SECONDS); // Increased timeout for AI response

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpcode === 200) {
            // Attempt to decode the JSON response from AI
            $result = json_decode($response, true);

            // Adjust based on the actual AI API response structure
            // Assuming the AI response is directly the JSON array or within a message content field
            $aiOutput = $result['choices'][0]['message']['content'] ?? $response; // Fallback to raw response if structure differs

            // Try to find and decode the JSON string within the AI output
            // Sometimes AI might include introductory text or markdown formatting
            $jsonString = extractJsonString($aiOutput);

            $data = json_decode($jsonString, true);

            // Check if AI returned a suggested URL
            if (is_array($data) && isset($data['suggested_url']) && filter_var($data['suggested_url'], FILTER_VALIDATE_URL)) {
                 logMessage("AI suggested a URL: " . $data['suggested_url'] . " (Attempt " . ($i + 1) . ").", 'INFO');
                 return $data['suggested_url']; // Return the suggested URL string
            }

            // Check if AI returned a list of servers (an array of objects)
            if (is_array($data) && !empty($data) && isset($data[0]) && is_array($data[0])) {
                 // Sort servers based on a combination of attributes for consistent comparison
                usort($data, function($a, $b) {
                    $a_str = ($a['cost_effective_name'] ?? '') . ($a['cpu'] ?? '') . ($a['ram'] ?? '') . ($a['storage'] ?? '') . ($a['price'] ?? '');
                    $b_str = ($b['cost_effective_name'] ?? '') . ($b['cpu'] ?? '') . ($b['ram'] ?? '') . ($b['storage'] ?? '') . ($b['price'] ?? '');
                    return strcmp($a_str, $b_str);
                });
                logMessage("AI parsing successful, identified " . count($data) . " servers (Attempt " . ($i + 1) . ").", 'INFO');
                return $data; // Return the array of servers
            }

            // If not a suggested URL and not a server array
            logMessage("AI response is not a valid server array or suggested URL (Attempt " . ($i + 1) . "). Response: " . $response, 'ERROR');
            logMessage("Attempted JSON string: " . $jsonString, 'ERROR');
            // Continue to retry if JSON decoding failed or format is wrong

        } elseif ($httpcode === 429) {
            logMessage("AI API returned 429 Too Many Requests (Attempt " . ($i + 1) . "). Switching API key and retrying.", 'INFO');
            $apiKey = getNextAiApiKey(); // Switch to the next key
            if (!$apiKey) {
                logMessage("No more AI API keys available after receiving 429.", 'ERROR');
                break; // Exit loop if no more keys
            }
            // Continue to retry with the new key
        } else {
            logMessage("Error interacting with AI model (Attempt " . ($i + 1) . ", HTTP {$httpcode}): {$error}. Response: " . ($response ? $response : 'No response body'), 'ERROR');
            // Continue to retry if other HTTP error occurred
        }
    }

    logMessage("Failed to get valid AI response after " . (MAX_RETRIES + 1) . " attempts.", 'ERROR');
    return null; // Indicate failure after retries
}


/**
 * 使用 AI 模型生成服务器列表的评价
 * @param array $currentServers 当前服务器列表
 * @param array $previousServers 上次监控到的服务器列表
 * @return string 生成的评价文本
 */
function useAiToGenerateEvaluation(array $currentServers, array $previousServers): string {
    if (empty($currentServers)) {
        logMessage("No current servers provided for AI evaluation.", 'INFO');
        return "当前页面没有识别到服务器。";
    }

    $apiKey = getNextAiApiKey();
    if (!defined('AI_API_ENDPOINT') || !$apiKey || !defined('AI_MODEL_NAME') ||
        AI_API_ENDPOINT === 'YOUR_AI_API_ENDPOINT' || $apiKey === 'YOUR_AI_API_KEY' || AI_MODEL_NAME === 'YOUR_AI_MODEL_NAME') {
        logMessage("AI API configuration is incomplete or no valid API key available. Cannot generate evaluation.", 'ERROR');
        return "无法生成服务器评价（AI配置不完整或无可用密钥）。";
    }

    $currentServersJson = json_encode($currentServers, JSON_UNESCAPED_UNICODE);
    $previousServersJson = json_encode($previousServers, JSON_UNESCAPED_UNICODE); // Pass previous for context

    $prompt = "以下是当前监控到的服务器列表（JSON格式）：\n" . $currentServersJson . "\n\n";
    if (!empty($previousServers)) {
         $prompt .= "以下是上次监控到的服务器列表（JSON格式）：\n" . $previousServersJson . "\n\n";
         $prompt .= "请根据当前服务器列表，结合上次的列表（用于判断是否有新增或下架），生成一个简洁、生动、带有“抽象狠活”风格的评价。评价内容可以包括：\n";
         $prompt .= "- 整体库存情况（是否有新货，哪些卖完了）\n";
         $prompt .= "- 亮点服务器（性价比高的，配置强的）\n";
         $prompt .= "- 购买建议或观察\n";
         $prompt .= "请用中文，评价控制在100字以内，直接返回评价文本，不要包含其他任何内容。\n";
    } else {
         $prompt .= "这是第一次监控到的服务器列表。请根据当前列表生成一个简洁、生动、带有“抽象狠活”风格的评价。评价内容可以包括：\n";
         $prompt .= "- 整体库存情况\n";
         $prompt .= "- 亮点服务器（性价比高的，配置强的）\n";
         $prompt .= "- 购买建议或观察\n";
         $prompt .= "请用中文，评价控制在100字以内，直接返回评价文本，不要包含其他任何内容。\n";
    }

    $payload = json_encode([
        "model" => AI_MODEL_NAME,
        "messages" => [["role" => "user", "content" => $prompt]],
        "max_tokens" => 200, // Sufficient for a short evaluation
        "temperature" => 0.7 // More creative temperature for evaluation
    ]);

    for ($i = 0; $i <= MAX_RETRIES; $i++) {
        if ($i > 0) {
            logMessage("Retrying AI evaluation call (Attempt " . ($i + 1) . ")...", 'INFO');
            sleep(RETRY_DELAY_SECONDS);
        }

        $ch = curl_init(AI_API_ENDPOINT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer " . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, AI_EVAL_TIMEOUT_SECONDS); // Timeout for evaluation

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            $evaluation = $result['choices'][0]['message']['content'] ?? null;
            if ($evaluation) {
                logMessage("AI evaluation successful (Attempt " . ($i + 1) . ").", 'INFO');
                return trim($evaluation);
            } else {
                 logMessage("AI evaluation returned empty content (Attempt " . ($i + 1) . "). Response: " . ($response ? $response : 'No response body'), 'ERROR');
            }
        } elseif ($httpcode === 429) {
            logMessage("AI API returned 429 Too Many Requests during evaluation (Attempt " . ($i + 1) . "). Switching API key and retrying.", 'INFO');
            $apiKey = getNextAiApiKey(); // Switch to the next key
            if (!$apiKey) {
                logMessage("No more AI API keys available after receiving 429 during evaluation.", 'ERROR');
                break; // Exit loop if no more keys
            }
            // Continue to retry with the new key
        } else {
            logMessage("Error interacting with AI model for evaluation (Attempt " . ($i + 1) . ", HTTP {$httpcode}): {$error}. Response: " . ($response ? $response : 'No response body'), 'ERROR');
        }
    }

    logMessage("Failed to generate AI evaluation after " . (MAX_RETRIES + 1) . " attempts.", 'ERROR');
    return "未能生成服务器评价。"; // Fallback message
}

/**
 * 使用 AI 模型从 HTML 内容中查找建议的服务器列表 URL
 * @param string $htmlContent 网页的 HTML 内容
 * @return string|null 建议的 URL 或 null
 */
function askAiForSuggestedUrl(string $htmlContent): ?string {
    if (empty($htmlContent)) {
        logMessage("No HTML content provided for AI suggested URL check.", 'INFO');
        return null;
    }

    $apiKey = getNextAiApiKey();
    if (!defined('AI_API_ENDPOINT') || !$apiKey || !defined('AI_MODEL_NAME') ||
        AI_API_ENDPOINT === 'YOUR_AI_API_ENDPOINT' || $apiKey === 'YOUR_AI_API_KEY' || AI_MODEL_NAME === 'YOUR_AI_MODEL_NAME') {
        logMessage("AI API configuration is incomplete or no valid API key available. Cannot ask for suggested URL.", 'ERROR');
        return null;
    }

    // Prompt specifically for a suggested URL
    $prompt = "以下是一个网页的 HTML 内容。你之前未能直接从中识别出服务器列表。\n";
    $prompt .= "请仔细阅读 HTML，查找一个明确指向服务器产品列表页面的链接。\n";
    $prompt .= "如果找到这样的链接，请以 JSON 格式返回，例如 `{\"suggested_url\": \"找到的链接\"}`。请确保 `suggested_url` 是一个完整的 URL。\n";
    $prompt .= "如果找不到任何相关的链接，请返回一个空的 JSON 对象 `{}`。\n";
    $prompt .= "请确保返回的内容是有效的 JSON 格式，并且只包含 JSON，不要包含任何额外的解释性文本或markdown格式（如```json```）。\n\n";
    $prompt .= "HTML 内容：\n" . $htmlContent . "\n\n"; // Include the raw HTML
    $prompt .= "请返回包含 suggested_url 的 JSON 对象或空 JSON 对象：\n";


    $payload = json_encode([
        "model" => AI_MODEL_NAME,
        "messages" => [["role" => "user", "content" => $prompt]],
        "max_tokens" => 500, // Sufficient for a small JSON response
        "temperature" => 0.1
    ]);

    for ($i = 0; $i <= MAX_RETRIES; $i++) {
        if ($i > 0) {
            logMessage("Retrying AI suggested URL call (Attempt " . ($i + 1) . ")...", 'INFO');
            sleep(RETRY_DELAY_SECONDS);
        }

        $ch = curl_init(AI_API_ENDPOINT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer " . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, AI_EVAL_TIMEOUT_SECONDS); // Use evaluation timeout, should be quick
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignore SSL certificate verification

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            $aiOutput = $result['choices'][0]['message']['content'] ?? $response;
            $jsonString = extractJsonString($aiOutput);
            $data = json_decode($jsonString, true);

            if (is_array($data) && isset($data['suggested_url']) && filter_var($data['suggested_url'], FILTER_VALIDATE_URL)) {
                 logMessage("AI suggested URL found (Attempt " . ($i + 1) . "): " . $data['suggested_url'], 'INFO');
                 return $data['suggested_url']; // Success
            } elseif (is_array($data) && empty($data)) {
                 logMessage("AI returned empty JSON, no suggested URL found (Attempt " . ($i + 1) . ").", 'INFO');
                 return null; // AI found nothing, returned {}
            } else {
                 logMessage("AI response for suggested URL is not valid JSON or missing suggested_url (Attempt " . ($i + 1) . "). Response: " . $response, 'ERROR');
                 logMessage("Attempted JSON string: " . $jsonString, 'ERROR');
                 // Continue to retry if JSON decoding failed or format is wrong
            }
        } elseif ($httpcode === 429) {
            logMessage("AI API returned 429 Too Many Requests during suggested URL check (Attempt " . ($i + 1) . "). Switching API key and retrying.", 'INFO');
            $apiKey = getNextAiApiKey(); // Switch to the next key
            if (!$apiKey) {
                logMessage("No more AI API keys available after receiving 429 during suggested URL check.", 'ERROR');
                break; // Exit loop if no more keys
            }
            // Continue to retry with the new key
        } else {
            logMessage("Error interacting with AI model for suggested URL (Attempt " . ($i + 1) . ", HTTP {$httpcode}): {$error}. Response: " . ($response ? $response : 'No response body'), 'ERROR');
            // Continue to retry if other HTTP error occurred
        }
    }

    logMessage("Failed to get valid AI suggested URL response after " . (MAX_RETRIES + 1) . " attempts.", 'ERROR');
    return null; // Indicate failure after retries
}


/**
 * 使用 AI 模型判断两个服务器对象是否相似
 * @param array $server1 第一个服务器对象
 * @param array $server2 第二个服务器对象
 * @return bool 是否相似
 */
function areServersSimilar(array $server1, array $server2): bool {
    // 记录比较操作以便调试
    $server1Key = ($server1['cost_effective_name'] ?? '') . '|' . ($server1['cpu'] ?? '') . '|' . ($server1['ram'] ?? '') . '|' . ($server1['storage'] ?? '') . '|' . ($server1['price'] ?? '');
    $server2Key = ($server2['cost_effective_name'] ?? '') . '|' . ($server2['cpu'] ?? '') . '|' . ($server2['ram'] ?? '') . '|' . ($server2['storage'] ?? '') . '|' . ($server2['price'] ?? '');
    logMessage("比较服务器相似性: {$server1Key} vs {$server2Key}", 'INFO');
    
    // 检查基本不相似情况 (例如，必要字段缺失)
    if (empty($server1) || empty($server2) ||
        empty($server1['cpu']) || empty($server1['ram']) || empty($server1['storage']) ||
        empty($server2['cpu']) || empty($server2['ram']) || empty($server2['storage'])) {
        // 如果缺少必要信息，不太可能相似到足以绕过严格匹配
        logMessage("服务器缺少必要字段，视为不相似", 'INFO');
        return false;
    }

    $apiKey = getNextAiApiKey();
    if (!defined('AI_API_ENDPOINT') || !$apiKey || !defined('AI_MODEL_NAME') ||
        AI_API_ENDPOINT === 'YOUR_AI_API_ENDPOINT' || $apiKey === 'YOUR_AI_API_KEY' || AI_MODEL_NAME === 'YOUR_AI_MODEL_NAME') {
        logMessage("AI API configuration is incomplete or no valid API key available. Cannot use AI for similarity check.", 'ERROR');
        return false; // Cannot perform similarity check
    }

    $server1Json = json_encode($server1, JSON_UNESCAPED_UNICODE);
    $server2Json = json_encode($server2, JSON_UNESCAPED_UNICODE);

    $prompt = "请判断以下两个服务器配置（JSON格式,无视 `cost_effective_name` 字段）是否代表同一个服务器产品。请比较 CPU、内存、存储和价格等关键属性(可能带有杂项文字，提取关键字即可, 例如 `1vCore` 和 `CPU 1vCore`是等价的)。即使描述差异，如果核心配置相似，也应视为同一个产品。\n";
    $prompt .= "服务器 1:\n" . $server1Json . "\n\n";
    $prompt .= "服务器 2:\n" . $server2Json . "\n\n";
    $prompt .= "请以 JSON 格式返回判断结果，例如 `{\"similar\": true}` 或 `{\"similar\": false}`。不要包含其他任何解释性文本或markdown格式（如```json```）。\n";
    $prompt .= "期望的 JSON 格式示例:\n";
    $prompt .= "{\n";
    $prompt .= "  \"similar\": true\n";
    $prompt .= "}\n\n";
    $prompt .= "请返回判断结果的 JSON 对象：\n";


    $payload = json_encode([
        "model" => AI_MODEL_NAME,
        "messages" => [["role" => "user", "content" => $prompt]],
        "max_tokens" => 100, // Should be sufficient for a boolean JSON
        "temperature" => 0.1 // Low temperature for factual comparison
    ]);

    // Use a shorter timeout for similarity check
    $timeout = min(AI_PARSE_TIMEOUT_SECONDS, 30); // e.g., 30 seconds max

    for ($i = 0; $i <= MAX_RETRIES; $i++) {
        if ($i > 0) {
            logMessage("Retrying AI similarity check (Attempt " . ($i + 1) . ")...", 'INFO');
            sleep(RETRY_DELAY_SECONDS);
        }

        $ch = curl_init(AI_API_ENDPOINT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer " . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignore SSL certificate verification
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            $aiOutput = $result['choices'][0]['message']['content'] ?? $response;
            $jsonString = extractJsonString($aiOutput);
            $data = json_decode($jsonString, true);

            if (is_array($data) && isset($data['similar']) && is_bool($data['similar'])) {
                 logMessage("AI similarity check successful (Attempt " . ($i + 1) . "). Result: " . ($data['similar'] ? 'true' : 'false'), 'INFO');
                 return $data['similar']; // Return the boolean result
             } else {
                  logMessage("AI response for similarity check is not valid JSON or missing boolean 'similar' field (Attempt " . ($i + 1) . "). Response: " . $response, 'ERROR');
                  logMessage("Attempted JSON string: " . $jsonString, 'ERROR');
                  // Continue to retry if JSON decoding failed or format is wrong
             }
         } elseif ($httpcode === 429) {
             logMessage("AI API returned 429 Too Many Requests during similarity check (Attempt " . ($i + 1) . "). Switching API key and retrying.", 'INFO');
             $apiKey = getNextAiApiKey(); // Switch to the next key
             if (!$apiKey) {
                 logMessage("No more AI API keys available after receiving 429 during similarity check.", 'ERROR');
                 break; // Exit loop if no more keys
             }
             // Continue to retry with the new key
         } else {
             logMessage("Error interacting with AI model for similarity check (Attempt " . ($i + 1) . ", HTTP {$httpcode}): {$error}. Response: " . ($response ? $response : 'No response body'), 'ERROR');
             // Continue to retry if other HTTP error occurred
         }
     }
logMessage("Failed to get valid AI similarity response after " . (MAX_RETRIES + 1) . " attempts. Assuming not similar.", 'ERROR');

// 失败时，尝试一个基于字符串匹配的简单回退方法
$server1Key = strtolower(($server1['cpu'] ?? '') . ($server1['ram'] ?? '') . ($server1['storage'] ?? ''));
$server2Key = strtolower(($server2['cpu'] ?? '') . ($server2['ram'] ?? '') . ($server2['storage'] ?? ''));

// 计算相似度百分比
$similarity = 0;
if (!empty($server1Key) && !empty($server2Key)) {
    similar_text($server1Key, $server2Key, $similarity);
}

$isSimilar = $similarity >= 90; // 如果相似度大于90%，则认为是相似的
logMessage("回退相似度检查: {$server1Key} vs {$server2Key}, 相似度: {$similarity}%, 结果: " . ($isSimilar ? "相似" : "不相似"), 'INFO');

return $isSimilar; // 使用基于字符串的相似度回退
}



/**
 * Apply data to a message template.
 *
 * @param string $template The message template string with {{placeholders}}.
 * @param array $data Associative array of data to substitute.
 * @return string The message with placeholders replaced.
 */
function applyTemplate(string $template, array $data): string {
    $message = $template;
    foreach ($data as $key => $value) {
        // Ensure value is a string before replacement
        $value = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        $placeholder = '{{' . $key . '}}';
        $message = str_replace($placeholder, $value, $message);
    }
    // Replace any remaining {{...}} with "未知"
    $message = preg_replace('/\{\{.*?\}\}/', '未知', $message);
    return $message;
}


// --- Main Logic ---

logMessage("Starting multi-site server monitor check at " . date('Y-m-d H:i:s'), 'INFO');

$serversHistory = loadServersHistory(SERVERS_HISTORY_FILE);

if (empty(MONITOR_URLS)) {
    logMessage("No URLs configured for monitoring. Exiting.", 'ERROR');
    exit;
}


// 解析MONITOR_URLS配置，支持时间单位
$parsedMonitorUrls = [];
foreach (MONITOR_URLS as $url => $customInstruction) {
    // 检查是否包含时间单位配置
    $timeUnit = 1; // 默认时间单位为1
    
    // 如果自定义指令包含时间单位配置 [timeUnit:N]
    if (preg_match('/\[timeUnit:(\d+)\]/', $customInstruction, $matches)) {
        $timeUnit = (int) $matches[1];
        // 移除时间单位配置部分
        $customInstruction = trim(preg_replace('/\[timeUnit:\d+\]/', '', $customInstruction));
    }
    
    $parsedMonitorUrls[$url] = [
        'instruction' => $customInstruction,
        'timeUnit' => $timeUnit
    ];
}

foreach ($parsedMonitorUrls as $url => $config) { // Iterate over URLs and configurations
    $customInstruction = $config['instruction'];
    $timeUnit = $config['timeUnit'];
    
    // 检查是否应该监控此URL
    if (!shouldMonitor($url, $timeUnit)) {
        continue; // 如果不应该监控，则跳过
    }
    
    logMessage("--- Monitoring URL: {$url} (Time Unit: {$timeUnit}) ---", 'INFO');

    // Get previous servers for this specific URL using MD5 hash as key
    $urlHash = md5($url);
    
    // 优先从缓存加载上次数据
    $cachedServers = loadCache($urlHash);
    if (!empty($cachedServers)) {
        $latestPreviousServers = $cachedServers;
        logMessage("Loaded " . count($latestPreviousServers) . " servers from cache for {$url}.", 'INFO');
    } else {
        // 如果缓存不存在，则从历史记录加载
        $previousServers = $serversHistory[$urlHash] ?? [];
        if (!empty($previousServers)) {
            // Get the latest record for this URL
            $latestPreviousServers = end($previousServers);
            if ($latestPreviousServers === false) {
                $latestPreviousServers = [];
            }
            logMessage("Loaded " . count($latestPreviousServers) . " servers from the latest history record for {$url}.", 'INFO');
        } else {
            $latestPreviousServers = [];
            logMessage("No previous history found for {$url}.", 'INFO');
        }
    }

    $htmlContent = fetchPageContent($url);

    if ($htmlContent) {
        // Pass the custom instruction to the AI parsing function
        $aiParseResult = useAiToIdentifyServersFromHtml($htmlContent, $customInstruction);

        $currentServers = null; // Initialize currentServers as null
        $suggestedUrl = null; // Initialize suggestedUrl as null

        if (is_array($aiParseResult)) {
            // AI returned server data directly
            $currentServers = $aiParseResult;
            logMessage("AI identified " . count($currentServers) . " servers for {$url} on first attempt.", 'INFO');
            
            // 保存到缓存文件
            saveCache($urlHash, $currentServers);
        } elseif (is_string($aiParseResult)) {
            // AI suggested a URL
            $suggestedUrl = $aiParseResult;
            logMessage("AI suggested a URL for {$url}: {$suggestedUrl}. Attempting to fetch and re-parse...", 'INFO');

            // Attempt to fetch and parse the suggested URL with retries
            for ($retryAttempt = 0; $retryAttempt <= MAX_RETRIES; $retryAttempt++) {
                if ($retryAttempt > 0) {
                    logMessage("Retrying fetch and parse of suggested URL for {$url} (Attempt " . ($retryAttempt + 1) . ")...", 'INFO');
                    sleep(RETRY_DELAY_SECONDS);
                }

                $suggestedHtmlContent = fetchPageContent($suggestedUrl);

                if ($suggestedHtmlContent) {
                    $secondaryAiParseResult = useAiToIdentifyServersFromHtml($suggestedHtmlContent, $customInstruction);

                    if (is_array($secondaryAiParseResult)) {
                        $currentServers = $secondaryAiParseResult;
                        logMessage("AI identified " . count($currentServers) . " servers for {$url} from suggested URL after " . ($retryAttempt + 1) . " attempts.", 'INFO');
                        
                        // 保存到缓存文件
                        saveCache($urlHash, $currentServers);
                        break; // Found servers, exit retry loop
                    } elseif (is_string($secondaryAiParseResult)) {
                         logMessage("AI suggested another URL from {$suggestedUrl}. Not following further suggestions.", 'INFO');
                         break; // AI suggested another URL, stop here
                    } else {
                        logMessage("AI parsing of suggested URL content failed for {$suggestedUrl} (Attempt " . ($retryAttempt + 1) . ").", 'ERROR');
                        // Continue retry loop
                    }
                } else {
                    logMessage("Failed to fetch content from suggested URL {$suggestedUrl} (Attempt " . ($retryAttempt + 1) . ").", 'ERROR');
                    // Continue retry loop
                }
            }

            if ($currentServers === null) {
                 logMessage("Failed to identify servers for {$url} even after trying suggested URL and retries. Treating current list as empty.", 'ERROR');
                 $currentServers = []; // Set to empty array to proceed with comparison and history update
            }

        } else {
            // AI parsing failed completely on first attempt
            logMessage("AI parsing failed for {$url} on first attempt.", 'ERROR');
        }


        if ($currentServers !== null) { // Proceed only if servers were identified
            // Get history for this specific URL using MD5 hash as key
            $urlHash = md5($url);
            // History structure: $serversHistory[$urlHash] = [SERVER_KEY_HASH => ['server_data' => {...}, 'last_notified_stock' => '...']]
            $urlHistory = $serversHistory[$urlHash] ?? [];
            logMessage("Loaded " . count($urlHistory) . " historical server records for {$url}.", 'INFO');

            // New history structure for the next run
            $nextUrlHistory = [];

            $newServers = []; // 全新上架 (从未在历史中出现)
            $restockedServers = []; // 重新有货 (历史中存在，之前无货，现在有货)
            $soldOutServers = []; // 下架/无货 (历史中存在，之前有货，现在无货/移除)

            // Track which historical servers are matched with a current server
            $matchedHistoricalKeys = [];

            // Iterate through current servers and compare with history
            foreach ($currentServers as $currentServer) {
                $currentStock = $currentServer['stock'] ?? '未知库存';
                $matchedHistoricalKey = null;

                // 尝试找到类似的历史服务器
                foreach ($urlHistory as $historicalKey => $historicalRecord) {
                    // 先检查历史键是否已经被匹配过，避免重复匹配
                    if (in_array($historicalKey, $matchedHistoricalKeys)) {
                        continue; // 跳过已匹配的历史记录
                    }
                    
                    if (areServersSimilar($currentServer, $historicalRecord['server_data'])) {
                        $matchedHistoricalKey = $historicalKey;
                        $matchedHistoricalKeys[] = $historicalKey; // 标记为已匹配
                        break; // 找到匹配项，移至下一个当前服务器
                    }
                }

                if ($matchedHistoricalKey === null) {
                    // 这个当前服务器是新的（未找到类似的历史服务器）
                    // 生成服务器键值以进行记录
                    $serverIdentifier = ($currentServer['cost_effective_name'] ?? '') . '|' .
                                       ($currentServer['cpu'] ?? '') . '|' .
                                       ($currentServer['ram'] ?? '') . '|' .
                                       ($currentServer['storage'] ?? '') . '|' .
                                       ($currentServer['price'] ?? '');
                    
                    // 仅当当前库存为"有货"时才将其添加到新服务器列表
                    if ($currentStock === '有货') {
                        $newServers[] = $currentServer;
                        logMessage("检测到新服务器（有货）: " . ($currentServer['original_name'] ?? '未知名称') . " for {$url}.", 'INFO');
                    } else {
                        logMessage("检测到新服务器（非有货状态）: " . ($currentServer['original_name'] ?? '未知名称') . " for {$url}. 状态: {$currentStock}", 'INFO');
                    }
                    
                    // 将新服务器添加到下次历史记录中，使用当前数据和库存作为最后通知的状态
                    $currentKey = md5($serverIdentifier); // 为新服务器生成键
                    // 确保键在nextUrlHistory中是唯一的，如果需要，添加计数器（使用MD5不太可能，但这是一个好习惯）
                    $originalCurrentKey = $currentKey;
                    $counter = 1;
                    while(isset($nextUrlHistory[$currentKey])) {
                         $currentKey = $originalCurrentKey . '_' . $counter++;
                    }

                    $nextUrlHistory[$currentKey] = [
                        'server_data' => $currentServer,
                        'last_notified_stock' => $currentStock // 为新服务器通知
                    ];

                } else {
                    // This current server is similar to a historical server
                    $historicalRecord = $urlHistory[$matchedHistoricalKey];
                    $previousServerData = $historicalRecord['server_data'];
                    $lastNotifiedStock = $historicalRecord['last_notified_stock'];
                    $previousStock = $previousServerData['stock'] ?? '未知库存';

                    $nextNotifiedStock = $lastNotifiedStock; // Default: keep previous notification status

                    // 检查是否重新有货
                    if ($currentStock === '有货' && $previousStock !== '有货') {
                        // 状态从缺货/移除变为有货
                        $restockedServers[] = $currentServer;
                        $nextNotifiedStock = '有货'; // 通知重新有货
                        logMessage("检测到重新有货服务器: " . ($currentServer['original_name'] ?? '未知名称') . " for {$url}. 之前状态: {$previousStock}.", 'INFO');
                    }
                    // 检查售罄（每次售罄期间只通知一次）
                    // 此情况在稍后遍历未匹配的历史服务器时处理
                    
                    // 记录库存变化但未发送通知的情况（例如，缺货 -> 未知，或缺货 -> 缺货）
                    if ($currentStock !== $previousStock && $nextNotifiedStock === $lastNotifiedStock) {
                        logMessage("服务器库存变化，类似于 " . ($previousServerData['original_name'] ?? '未知名称') . " for {$url} ({$previousStock} -> {$currentStock}), 但根据历史记录无需新通知。", 'INFO');
                    }
                    
                    // 使用历史键添加到下一个历史记录以保持连续性
                    $nextUrlHistory[$matchedHistoricalKey] = [
                        'server_data' => $currentServer, // 使用当前数据
                        'last_notified_stock' => $nextNotifiedStock, // 使用确定的通知状态
                        'last_updated' => time() // 更新时间戳
                    ];
                }
            }

            // 历史记录管理 - 清理长期未出现的服务器
            $maxHistoryAge = defined('MAX_HISTORY_AGE_DAYS') ? MAX_HISTORY_AGE_DAYS : 30; // 默认30天
            $cutoffTime = time() - ($maxHistoryAge * 86400); // 86400秒 = 1天
            
            // 遍历未在当前匹配的历史服务器
            // 这些服务器要么已被移除，要么仍然缺货
            foreach ($urlHistory as $historicalKey => $historicalRecord) {
                if (!in_array($historicalKey, $matchedHistoricalKeys)) {
                    // 此历史服务器不在当前列表中（或未找到类似的当前服务器）
                    $previousServerData = $historicalRecord['server_data'];
                    $lastNotifiedStock = $historicalRecord['last_notified_stock'];
                    $previousStock = $previousServerData['stock'] ?? '未知库存';
                    $lastUpdatedTime = $historicalRecord['last_updated'] ?? time(); // 如果没有记录，假设是当前时间
                    
                    // 检查此记录是否应该被保留
                    $shouldKeep = true;
                    
                    // 如果记录太旧且已通知为移除或缺货，不保留
                    if ($lastUpdatedTime < $cutoffTime && ($lastNotifiedStock === 'removed' || $lastNotifiedStock === '缺货')) {
                        $shouldKeep = false;
                        logMessage("移除过期历史记录: " . ($previousServerData['original_name'] ?? '未知名称') . " for {$url}. 最后更新: " . date('Y-m-d', $lastUpdatedTime), 'INFO');
                        continue; // 跳过此记录，不添加到nextUrlHistory
                    }

                    $nextNotifiedStock = $lastNotifiedStock; // 默认：保持之前的通知状态

                    // 如果之前有货，并且我们没有通知其被移除
                    if ($previousStock === '有货' && $lastNotifiedStock !== 'removed') {
                        $soldOutServers[] = $previousServerData; // 视为已移除/售罄
                        $nextNotifiedStock = 'removed'; // 通知移除
                        logMessage("检测到移除服务器: " . ($previousServerData['original_name'] ?? '未知名称') . " for {$url}. 之前状态: {$previousStock}.", 'INFO');
                    }
                    // 如果之前不是有货状态，且我们没有通知其售罄
                    elseif ($previousStock !== '有货' && $lastNotifiedStock !== '缺货' && $lastNotifiedStock !== 'removed') {
                         $soldOutServers[] = $previousServerData; // 视为仍然售罄，首次通知
                         $nextNotifiedStock = '缺货'; // 通知售罄
                         logMessage("检测到持续售罄服务器（首次通知）: " . ($previousServerData['original_name'] ?? '未知名称') . " for {$url}. 之前状态: {$previousStock}.", 'INFO');
                    }
                    else {
                         logMessage("历史服务器 " . ($previousServerData['original_name'] ?? '未知名称') . " for {$url} 未在当前列表中找到，无需新通知。", 'INFO');
                    }
                    
                    // 如果我们应该保留此记录，将其添加到下一个历史记录中
                    if ($shouldKeep) {
                        // 使用历史键添加到下一个历史记录
                        $nextUrlHistory[$historicalKey] = [
                            'server_data' => $previousServerData, // 保留之前的数据
                            'last_notified_stock' => $nextNotifiedStock, // 使用确定的通知状态
                            'last_updated' => time() // 更新时间戳
                        ];
                    }
                }
            }

            // 注意：这里不要更新历史记录，在所有处理完成后再统一更新
            

            // Determine if there are any changes that require notification
            // Notification is needed if there are new servers, restocked servers,
            // or sold out/removed servers that triggered a notification in this run.
            // Also notify on initial run if servers are found.
            $isInitialRun = empty($urlHistory); // Check against the history loaded at the start of the URL loop
            // 去重服务器列表，防止重复通知
            $newServers = array_values(array_unique(array_map('serialize', $newServers)));
            $newServers = array_map('unserialize', $newServers);
            
            $restockedServers = array_values(array_unique(array_map('serialize', $restockedServers)));
            $restockedServers = array_map('unserialize', $restockedServers);
            
            $soldOutServers = array_values(array_unique(array_map('serialize', $soldOutServers)));
            $soldOutServers = array_map('unserialize', $soldOutServers);
            
            // 记录服务器变动统计
            logMessage("服务器变动统计 - 新服务器: " . count($newServers) . ", 重新有货: " . count($restockedServers) . ", 下架/售罄: " . count($soldOutServers), 'INFO');
            
            // 确定是否需要通知
            // 当有新服务器、重新有货的服务器、或下架/售罄的服务器触发通知时，需要通知。
            // 首次运行且发现服务器时也通知。
            $isInitialRun = empty($urlHistory); // 检查URL循环开始时加载的历史记录
            $hasChanges = !empty($newServers) || !empty($restockedServers) || !empty($soldOutServers) || ($isInitialRun && !empty($currentServers));
            
            // 添加防抖动逻辑，避免频繁通知
            $lastNotificationTime = 0;
            $notificationCacheFile = __DIR__ . "/cache/{$urlHash}.lastnotification";
            
            if (file_exists($notificationCacheFile)) {
                $lastNotificationTime = (int)file_get_contents($notificationCacheFile);
            }
            
            $currentTime = time();
            $minNotificationInterval = defined('MIN_NOTIFICATION_INTERVAL') ? MIN_NOTIFICATION_INTERVAL : 3600; // 默认最小通知间隔1小时
            $canNotify = ($currentTime - $lastNotificationTime) >= $minNotificationInterval;
            
            if (!$canNotify && $hasChanges) {
                $timeToNextNotification = $minNotificationInterval - ($currentTime - $lastNotificationTime);
                $timeToNextNotificationMinutes = ceil($timeToNextNotification / 60);
                logMessage("通知冷却中，距离下次可通知还有约 {$timeToNextNotificationMinutes} 分钟", 'INFO');
            }
            
            $needsNotification = $canNotify && $hasChanges;

            // 仅当当前服务器不为空时生成AI评估
            $evaluation = "";
            if (!empty($currentServers)) {
                // 传递实际的前一个服务器数据作为评估上下文
                // 需要从历史记录中提取server_data
                $latestPreviousServersData = [];
                
                // 只使用有意义的历史记录（避免使用已标记为removed的记录）
                foreach ($urlHistory as $record) {
                    if (isset($record['server_data']) && isset($record['last_notified_stock']) &&
                        $record['last_notified_stock'] !== 'removed') {
                        $latestPreviousServersData[] = $record['server_data'];
                    }
                }
                
                $evaluation = useAiToGenerateEvaluation($currentServers, $latestPreviousServersData);
            } else {
                logMessage("当前服务器列表为空，跳过AI评估 {$url}。", 'INFO');
            }
            
            // 如果有当前服务器但没有变化（没有新上架、重新有货或售罄），将其添加到变化列表中以便显示
            $allCurrentServersShown = false;
            if (!empty($currentServers) && empty($newServers) && empty($restockedServers) && empty($soldOutServers)) {
                // 将当前所有服务器视为需要显示
                $hasChanges = true;
                $allCurrentServersShown = true;
                logMessage("无服务器状态变化，但将显示所有当前服务器配置信息", 'INFO');
            }

            // Send a Telegram message if changes were detected
            if ($needsNotification) {
                logMessage("Changes detected for {$url}. Building message...", 'INFO');

                $message = "🌐 监控网站: <b>" . htmlspecialchars($url) . "</b>\n\n";
                $serverDetailsMessages = []; // Array to hold formatted details for each server type

                // Helper function to format server data for template
                $formatServerData = function(array $server) {
                    $formattedPrice = '未知价格';
                    if (isset($server['price_value']) && isset($server['currency'])) {
                        $formattedPrice = htmlspecialchars($server['currency']) . htmlspecialchars($server['price_value']);
                        if (isset($server['price']) && strpos($server['price'], '/') !== false && strpos($formattedPrice, '/') === false) {
                            $priceParts = explode('/', $server['price']);
                            if (count($priceParts) > 1) {
                                $formattedPrice .= '/' . htmlspecialchars($priceParts[1]);
                            }
                        }
                    } elseif (isset($server['price'])) {
                        $formattedPrice = htmlspecialchars($server['price']);
                    }

                    $bandwidthTraffic = '';
                    if (!empty($server['bandwidth']) && !empty($server['traffic'])) {
                        $bandwidthTraffic = htmlspecialchars($server['bandwidth']) . '（' . htmlspecialchars($server['traffic']) . '）';
                    } elseif (!empty($server['bandwidth'])) {
                        $bandwidthTraffic = htmlspecialchars($server['bandwidth']);
                    } elseif (!empty($server['traffic'])) {
                        $bandwidthTraffic = htmlspecialchars($server['traffic']);
                    } else {
                        $bandwidthTraffic = '未知';
                    }


                    $data = [
                        'cost_effective_name' => htmlspecialchars($server['cost_effective_name'] ?? $server['original_name'] ?? '未知名称'),
                        'original_name' => htmlspecialchars($server['original_name'] ?? '未知名称'),
                        'cpu' => htmlspecialchars($server['cpu'] ?? '未知'),
                        'ram' => htmlspecialchars($server['ram'] ?? '未知'),
                        'discount' => htmlspecialchars($server['discount'] ?? '未知'),
                        'storage' => htmlspecialchars($server['storage'] ?? '未知'),
                        'bandwidth_traffic' => $bandwidthTraffic,
                        'ip_address' => htmlspecialchars($server['ip_address'] ?? '未知'),
                        'price' => $formattedPrice,
                        'stock' => htmlspecialchars($server['stock'] ?? '未知库存'),
                        'remark' => htmlspecialchars($server['remark'] ?? '无'),
                        'order_url' => htmlspecialchars($server['order_url'] ?? '无') ?? '无',
                    ];

                    // Add other dynamic fields if they exist and are not in the standard list
                     foreach ($server as $key => $value) {
                         if (!in_array($key, ['cost_effective_name', 'original_name', 'cpu', 'ram', 'storage', 'bandwidth', 'traffic', 'ip_address', 'price', 'stock', 'discount', 'price_value', 'currency', 'remark', 'order_url']) && !empty($value)) {
                             $data[htmlspecialchars(ucfirst($key))] = htmlspecialchars($value); // Use key as placeholder name
                         }
                     }

                    return $data;
                };


                // 构建新上架服务器消息
                if (!empty($newServers)) {
                    logMessage("Detected " . count($newServers) . " new servers for {$url}.", 'INFO');
                    $newServersDetails = "";
                    foreach ($newServers as $server) {
                        $serverData = $formatServerData($server);
                        $newServersDetails .= applyTemplate(MESSAGE_TEMPLATES['server_item'], $serverData);
                    }
                    $message .= applyTemplate(MESSAGE_TEMPLATES['new_server_found'], ['server_details' => $newServersDetails]);
                    $serverDetailsMessages[] = 'new'; // Mark that new server message was added
                }

                // 构建重新有货服务器消息
                if (!empty($restockedServers)) {
                    if (!empty($serverDetailsMessages)) $message .= "---\n\n"; // 添加分隔符
                    logMessage("Detected " . count($restockedServers) . " restocked servers for {$url}.", 'INFO');
                    $restockedServersDetails = "";
                    foreach ($restockedServers as $server) {
                        $serverData = $formatServerData($server);
                        $restockedServersDetails .= applyTemplate(MESSAGE_TEMPLATES['server_item'], $serverData);
                    }
                    // The stock_changed template includes {{stock}}, use the stock from the first restocked server (should be '有货')
                    $stockStatus = !empty($restockedServers) ? htmlspecialchars($restockedServers[0]['stock'] ?? '未知库存') : '未知库存';
                    $message .= applyTemplate(MESSAGE_TEMPLATES['stock_changed'], ['server_details' => $restockedServersDetails, 'stock' => $stockStatus]);
                     $serverDetailsMessages[] = 'restocked'; // Mark that restocked server message was added
                }

                // 构建下架/无货服务器消息
                if (!empty($soldOutServers)) {
                    if (!empty($serverDetailsMessages)) $message .= "---\n\n"; // 添加分隔符
                    logMessage("Detected " . count($soldOutServers) . " sold out/removed servers for {$url}.", 'INFO');
                    $soldOutServersDetails = "";
                    foreach ($soldOutServers as $server) {
                        $serverData = $formatServerData($server);
                        $soldOutServersDetails .= applyTemplate(MESSAGE_TEMPLATES['server_item'], $serverData);
                    }
                    $message .= applyTemplate(MESSAGE_TEMPLATES['server_removed'], ['server_details' => $soldOutServersDetails]);
                     $serverDetailsMessages[] = 'sold_out'; // Mark that sold out server message was added
                }


                // 如果没有服务器变化但有当前服务器，显示所有当前服务器
                if ($allCurrentServersShown && !empty($currentServers)) {
                    if (!empty($serverDetailsMessages)) $message .= "---\n\n"; // 添加分隔符
                    logMessage("显示所有当前服务器 (" . count($currentServers) . " 个) for {$url}.", 'INFO');
                    $allServersDetails = "";
                    foreach ($currentServers as $server) {
                        $serverData = $formatServerData($server);
                        $allServersDetails .= applyTemplate(MESSAGE_TEMPLATES['server_item'], $serverData);
                    }
                    $message .= applyTemplate(MESSAGE_TEMPLATES['all_servers'], ['server_details' => $allServersDetails]);
                    $serverDetailsMessages[] = 'all_current'; // 标记所有当前服务器的消息已添加
                }
                
                // Append evaluation if successfully generated and not the fallback message
                if ($evaluation !== "" && $evaluation !== "未能生成服务器评价。") {
                     // Add a separator if there were any server changes
                     if (!empty($serverDetailsMessages)) {
                         //$message .= "---\n\n";
                         $message .= "\n\n";
                     }
                     $message .= applyTemplate(MESSAGE_TEMPLATES['evaluation'], ['evaluation_text' => htmlspecialchars($evaluation)]);
                } else {
                     logMessage("Skipping evaluation message for {$url} as generation failed or returned fallback.", 'INFO');
                }

                // Add URL at the end (only if there was content before)
                if (!empty($serverDetailsMessages) || ($evaluation !== "" && $evaluation !== "未能生成服务器评价。")) {
                     if (substr($message, -2) === "\n\n") {
                         $message = substr($message, 0, -2);
                     }
                     $message .= "\n\n🔗 网站链接: " . htmlspecialchars($url); // Add URL explicitly at the end
                }


                $messageSent = sendTelegramMessage($message);
                if ($messageSent) {
                    logMessage("Telegram消息已发送 {$url}。", 'INFO');
                    
                    // 更新最后通知时间
                    file_put_contents($notificationCacheFile, time());
                } else {
                    logMessage("Telegram消息发送失败 {$url}。", 'ERROR');
                }

            } else {
                logMessage("No significant changes detected for {$url}. No Telegram message sent.", 'INFO');
            }

            // 更新缓存和历史记录
            saveCache($urlHash, $currentServers);
            logMessage("Updated cache for {$url}.", 'INFO');
            
            // 更新历史记录
            $serversHistory[$urlHash] = $nextUrlHistory;
            logMessage("Updated history for {$url} with " . count($nextUrlHistory) . " records.", 'INFO');

        } else {
            logMessage("AI parsing failed or returned invalid data after retries for {$url}. Skipping this check's comparison and history update for this URL.", 'ERROR');
            // History is not updated if AI parsing failed after retries
        }

    } else {
        logMessage("Failed to fetch page content for {$url}. Skipping this check.", 'ERROR');
        // History is not updated if fetch failed
    }
     logMessage("--- Finished Monitoring URL: {$url} ---", 'INFO');
}

// Save the entire history object after processing all URLs
saveServersHistory(SERVERS_HISTORY_FILE, $serversHistory);


logMessage("Multi-site server monitor check finished.", 'INFO');

// Calculate and output the execution time
$endTime = microtime(true);
$executionTime = round($endTime - $startTime, 4);
echo "Script execution time: {$executionTime} seconds\n";

?>