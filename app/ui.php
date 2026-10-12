<?php

declare(strict_types=1);

/**
 * 页面渲染。唯一的输出出口，负责把所有动态内容转义后再落进 HTML。
 *
 * 样式刻意内联：整站只有一个页面，省掉一次静态请求与缓存协商，也避免
 * 部署时多一个需要确认权限的文件。
 */

defined('FONT_SUBSET_APP') || exit;

function renderUi(): void
{
    $fonts = fontList();
    $issues = runtimeIssues();
    $turnstile = turnstileEnabled();
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Web Font Subsetter</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: system-ui, -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif;
        background: #f4f5f7; color: #1f2328;
        display: flex; justify-content: center; padding: 40px 16px;
    }
    .card {
        width: 100%; max-width: 560px; background: #fff;
        border: 1px solid #e4e7ec; border-radius: 12px;
        padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,.06);
    }
    h1 { font-size: 20px; margin-bottom: 24px; }
    label { display: block; font-size: 13px; color: #57606a; margin: 16px 0 6px; }
    select, textarea {
        width: 100%; padding: 8px 10px; font-size: 14px;
        border: 1px solid #d0d7de; border-radius: 8px; background: #fff;
    }
    textarea { height: 140px; resize: vertical; line-height: 1.6; }
    button {
        margin-top: 20px; width: 100%; padding: 10px; font-size: 14px;
        color: #fff; background: #1f6feb; border: 0; border-radius: 8px; cursor: pointer;
    }
    button:disabled { opacity: .6; cursor: default; }
    button.is-loading { position: relative; color: transparent; opacity: 1; }
    button.is-loading::after {
        content: ''; position: absolute; top: 50%; left: 50%;
        width: 18px; height: 18px; margin: -9px 0 0 -9px;
        border: 2px solid #fff; border-top-color: transparent; border-radius: 50%;
        animation: spin .8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .issues {
        margin-bottom: 20px; padding: 12px 14px; font-size: 13px; line-height: 1.7;
        background: #fff8c5; border: 1px solid #d4a72c33; border-radius: 8px; color: #7d4e00;
    }
    #message { margin-top: 12px; font-size: 13px; color: #cf222e; min-height: 1em; }
    #css-output {
        display: none; margin-top: 20px; padding: 14px;
        background: #0d1117; color: #a5d6ff; border-radius: 8px;
        font: 12px/1.7 ui-monospace, Consolas, monospace;
        white-space: pre-wrap; word-break: break-all;
    }
</style>
</head>
<body>
<div class="card">
    <h1>Web Font Subsetter</h1>

    <?php if ($issues !== []): ?>
    <div class="issues">
        <?php foreach ($issues as $issue): ?>
        <div><?= htmlspecialchars($issue, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form id="form">
        <label for="font">字体</label>
        <select id="font" name="font">
            <?php foreach ($fonts as $font): ?>
            <option value="<?= htmlspecialchars($font, ENT_QUOTES) ?>"><?= htmlspecialchars($font, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="format">输出格式</label>
        <select id="format" name="format">
            <option value="woff2" selected>WOFF2</option>
            <option value="ttf">TTF</option>
        </select>

        <label for="text">字符（仅保留这些字符）</label>
        <textarea id="text" name="text" placeholder="你好世界&#10;欢迎使用本网站&#10;ABCDEFGHIJKLMNOPQRSTUVWXYZ&#10;0123456789">你好世界，欢迎使用字体裁剪工具！</textarea>

        <?php if ($turnstile): ?>
        <label>真人验证</label>
        <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(config()['cfTurnstileSiteKey'], ENT_QUOTES) ?>"
             data-size="flexible" data-callback="onTurnstileDone"></div>
        <?php endif; ?>

        <button id="submit" type="submit"<?= ($turnstile || $issues !== []) ? ' disabled' : '' ?>>获取字体</button>
        <div id="message"></div>
        <pre id="css-output"></pre>
    </form>
</div>

<?php if ($turnstile): ?>
<script>window.onTurnstileDone = function () { document.getElementById('submit').disabled = false; };</script>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<script>
const $form = document.getElementById('form');
const $button = document.getElementById('submit');
const $message = document.getElementById('message');
const $cssOutput = document.getElementById('css-output');

const TURNSTILE_ENABLED = <?= $turnstile ? 'true' : 'false' ?>;
const FORM_BLOCKED = <?= ($turnstile || $issues !== []) ? 'true' : 'false' ?>;

$form.addEventListener('submit', async (event) => {
    event.preventDefault();
    $message.textContent = '';
    $button.disabled = true;
    $button.classList.add('is-loading');
    try {
        const body = new URLSearchParams({
            font: document.getElementById('font').value,
            format: document.getElementById('format').value,
            text: document.getElementById('text').value,
        });
        appendTurnstileToken(body);
        await Promise.all([downloadFont(body), loadCss(body)]);
    } finally {
        $button.classList.remove('is-loading');
        resetTurnstile();
    }
});

function appendTurnstileToken(body) {
    const field = document.querySelector('[name="cf-turnstile-response"]');
    if (field) {
        body.set('cf-turnstile-response', field.value);
    }
}

// Turnstile 的 token 一次性有效，提交后必须重置，否则第二次提交必然失败。
// 重置期间按钮保持禁用，等 onTurnstileDone 回调重新放行。
function resetTurnstile() {
    if (TURNSTILE_ENABLED && window.turnstile) {
        window.turnstile.reset();
        return;
    }
    $button.disabled = FORM_BLOCKED;
}

async function downloadFont(body) {
    const response = await fetch('./', { method: 'POST', body });
    if (!response.ok) {
        await showHttpError(response);
        return;
    }
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = match ? match[1] : 'subset-font';
    link.click();
    URL.revokeObjectURL(url);
}

async function loadCss(body) {
    const response = await fetch('./?action=css', { method: 'POST', body });
    if (!response.ok) {
        await showHttpError(response);
        return;
    }
    $cssOutput.textContent = await response.text();
    $cssOutput.style.display = 'block';
}

async function showHttpError(response) {
    let message = 'HTTP ' + response.status;
    try {
        const data = await response.json();
        if (data && data.error) message = data.error;
    } catch (e) { /* 非 JSON 响应时保留状态码 */ }
    $message.textContent = message;
}
</script>
</body>
</html>
<?php
}
