<?php

declare(strict_types=1);

/**
 * 请求与响应的最小原语。
 *
 * 所有对外输出都必须经过这里，避免 header()/echo 散落在业务流程中而漏设安全头。
 * 本模块不依赖其他模块，可被任意层调用。
 */

defined('FONT_SUBSET_APP') || exit;

function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * 读取 POST 字符串字段。
 * 只接受字符串：数组/对象等注入形态一律回退到默认值，避免上层出现 basename(array) 之类的致命错误。
 */
function postString(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function queryString(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

/** 以 JSON 返回错误并终止请求。这是唯一的错误出口，保证响应格式一致。 */
function fail(int $httpCode, string $message): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 返回二进制附件（字体）。$filename 由调用方保证是 ASCII 安全串。 */
function sendBinary(string $data, string $contentType, string $filename): void
{
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $data;
    exit;
}

function sendText(string $body, string $contentType): void
{
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

/** 记录服务端日志，便于回溯裁剪失败与外部调用异常。 */
function logError(string $message): void
{
    error_log('[font-subset] ' . $message);
}
