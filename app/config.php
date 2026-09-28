<?php

declare(strict_types=1);

/**
 * 配置层：读取 ./config.json 并与内置默认值合并。
 *
 * 设计取舍：config.json 是可选的。缺失、损坏、类型不符都不会中断服务，
 * 一律降级到默认值——工具必须在"用户还没配好"的状态下就能跑起来。
 */

defined('FONT_SUBSET_APP') || exit;

const CONFIG_FILE = APP_ROOT . '/config.json';

/** 默认值同时充当"合法 key 与类型"的白名单，config.json 里多余的键会被忽略。 */
const CONFIG_DEFAULTS = [
    'timeoutSeconds'       => 30,   // 单次字体裁剪最长执行秒数，超出即强杀
    'fontScanCacheSeconds' => 30,   // fonts/ 扫描结果缓存有效期
    'cfTurnstileSiteKey'   => '',   // Cloudflare Turnstile 站点公钥（为空则不注入组件）
    'cfTurnstileSecret'    => '',   // Cloudflare Turnstile 私钥（为空则跳过真人校验）
];

const CONFIG_LIMITS = [
    'timeoutSeconds'       => [1, 600],
    'fontScanCacheSeconds' => [1, 3600],
];

/** 返回合并后的配置，进程内只解析一次。 */
function config(): array
{
    static $config = null;
    if ($config === null) {
        $config = loadConfig();
    }
    return $config;
}

function loadConfig(): array
{
    $config = CONFIG_DEFAULTS;
    foreach (readConfigFile() as $key => $value) {
        // 严格比对类型：避免 "30" 这类字符串混进整数配置
        if (array_key_exists($key, $config) && gettype($value) === gettype($config[$key])) {
            $config[$key] = $value;
        }
    }
    foreach (CONFIG_LIMITS as $key => [$min, $max]) {
        $config[$key] = max($min, min($max, $config[$key]));
    }
    return $config;
}

function readConfigFile(): array
{
    if (!is_file(CONFIG_FILE)) {
        return [];
    }
    $decoded = json_decode((string) @file_get_contents(CONFIG_FILE), true);
    if (!is_array($decoded)) {
        logError('config.json 不是合法 JSON，已回退到默认配置');
        return [];
    }
    return $decoded;
}
