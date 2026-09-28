<?php

declare(strict_types=1);

/**
 * Cloudflare Turnstile 真人校验。
 *
 * 开关只有一个：config.json 的 cfTurnstileSecret 是否为空。
 * 为空时本模块所有函数都退化为放行，页面也不会注入任何组件——
 * 也就是说"不想用验证码的用户完全感知不到这个功能存在"。
 */

defined('FONT_SUBSET_APP') || exit;

const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
const TURNSTILE_VERIFY_TIMEOUT = 10;    // 秒；外部 HTTP 调用必须设上限，否则会拖死 PHP-FPM 进程

const TURNSTILE_FAILED_MESSAGE = '真人验证失败，请刷新页面后重试';

function turnstileEnabled(): bool
{
    return config()['cfTurnstileSecret'] !== '';
}

/**
 * 计算资源入口调用它：先过真人校验，再谈要不要干活。
 * 未配置 secret 时直接返回，不产生任何外部请求。
 */
function verifyTurnstileOrFail(): void
{
    if (!turnstileEnabled()) {
        return;
    }
    $token = postString('cf-turnstile-response');
    if ($token === '') {
        fail(403, TURNSTILE_FAILED_MESSAGE);
    }
    $passed = turnstileVerify($token);
    if ($passed === null) {
        fail(503, '真人验证服务暂时不可用，请稍后重试');
    }
    if (!$passed) {
        fail(403, TURNSTILE_FAILED_MESSAGE);
    }
}

/**
 * 向 Cloudflare 校验 token。
 *
 * 返回 true = 通过，false = 不通过，null = 校验服务不可达。
 * 区分后两者，是为了不把"Cloudflare 挂了"误报成"你是机器人"。
 */
function turnstileVerify(string $token): ?bool
{
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query([
                'secret'   => config()['cfTurnstileSecret'],
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]),
            'timeout'       => TURNSTILE_VERIFY_TIMEOUT,
            'ignore_errors' => true,    // 非 2xx 也要拿到响应体，才能读到 error-codes
        ],
    ]);
    $response = @file_get_contents(TURNSTILE_VERIFY_URL, false, $context);
    if ($response === false) {
        logError('Turnstile 校验请求失败（网络不可达或未启用 openssl）');
        return null;
    }
    $result = json_decode($response, true);
    if (!is_array($result) || !isset($result['success'])) {
        logError('Turnstile 返回无法解析的响应: ' . substr($response, 0, 200));
        return null;
    }
    if ($result['success'] !== true) {
        logError('Turnstile 校验未通过: ' . implode(',', (array) ($result['error-codes'] ?? [])));
    }
    return $result['success'] === true;
}
