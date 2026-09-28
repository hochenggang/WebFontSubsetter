<?php
declare(strict_types=1);

/**
 * Web Font Subsetter
 *
 * GET  /            渲染 Web UI
 * POST /            裁剪字体并直接返回字体二进制（font / format / text）
 * POST /?action=css 根据相同参数生成 @font-face + unicode-range CSS 文本
 *
 * 裁剪由 bin/font-subset（Go，HarfBuzz 引擎）完成，中间文件仅存在于
 * sys_get_temp_dir()，响应发出前全部删除，服务器不保留任何缓存。
 */

// ============================================================
// 配置
// ============================================================

const FONT_DIR = __DIR__ . '/fonts';
const FONT_EXTENSIONS = ['ttf', 'otf'];
const ALLOWED_FORMATS = ['ttf', 'woff2'];
const FORMAT_MIME = ['ttf' => 'font/ttf', 'woff2' => 'font/woff2'];
const FORMAT_CSS = ['ttf' => 'truetype', 'woff2' => 'woff2'];
const MAX_CHARS = 10000;
const MAX_FONT_BYTES = 52428800; // 50MB
const SUBSET_TIMEOUT = 60;       // 秒；主机存在 GNU timeout 命令时生效

// ============================================================
// 入口
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');

if (!function_exists('exec')) {
    fail(500, '当前 PHP 环境禁用了 exec()，无法调用裁剪程序');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_GET['action'] ?? '') === 'css') {
        handleCssRequest();
    }
    handleFontRequest();
}

renderUi();

// ============================================================
// 字体端点：POST /
// ============================================================

function handleFontRequest(): void
{
    $fontPath = resolveFont($_POST['font'] ?? '');
    $format = $_POST['format'] ?? '';
    $text = (string) ($_POST['text'] ?? '');

    if ($fontPath === null) {
        fail(400, '字体不存在或不受支持');
    }
    if (!in_array($format, ALLOWED_FORMATS, true)) {
        fail(400, '输出格式仅支持 ttf / woff2');
    }
    if (filesize($fontPath) > MAX_FONT_BYTES) {
        fail(413, '字体文件超过大小限制');
    }
    validateText($text);

    acquireLockOrFail();
    $textFile = createTempFile('font-text-', $text);
    $outputFile = createTempFile('font-output-', '');
    register_shutdown_function(function () use ($textFile, $outputFile) {
        @unlink($textFile);
        @unlink($outputFile);
    });

    runSubset($fontPath, $textFile, $outputFile, $format);

    $data = file_get_contents($outputFile);
    if ($data === false || $data === '') {
        fail(500, '字体裁剪结果为空');
    }

    header('Content-Type: ' . FORMAT_MIME[$format]);
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: attachment; filename="' . subsetFilename($fontPath, $format) . '"');
    echo $data;
    exit;
}

function runSubset(string $fontPath, string $textFile, string $outputFile, string $format): void
{
    $command = sprintf(
        '%s --input %s --text-file %s --output %s --format %s',
        escapeshellarg(subsetBinary()),
        escapeshellarg($fontPath),
        escapeshellarg($textFile),
        escapeshellarg($outputFile),
        escapeshellarg($format)
    );
    if (systemTimeout() !== '') {
        $command = 'timeout ' . SUBSET_TIMEOUT . ' ' . $command;
    }

    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0 || !is_file($outputFile) || filesize($outputFile) === 0) {
        fail(500, '字体裁剪失败：' . implode(' ', array_slice($output, -3)));
    }
}

function subsetBinary(): string
{
    $name = DIRECTORY_SEPARATOR === '\\' ? 'font-subset.exe' : 'font-subset';
    return __DIR__ . '/bin/' . $name;
}

function systemTimeout(): string
{
    static $timeout = null;
    if ($timeout === null) {
        exec('command -v timeout 2>/dev/null', $lines, $code);
        $timeout = $code === 0 ? 'timeout' : '';
    }
    return $timeout;
}

function acquireLockOrFail(): void
{
    $handle = fopen(sys_get_temp_dir() . '/font-subset.lock', 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        fail(429, '已有字体裁剪任务在执行，请稍后重试');
    }
    register_shutdown_function(static function () use ($handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    });
}

function createTempFile(string $prefix, string $content): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if ($path === false || file_put_contents($path, $content) === false) {
        fail(500, '无法创建临时文件');
    }
    return $path;
}

// ============================================================
// CSS 端点：POST /?action=css
// ============================================================

function handleCssRequest(): void
{
    $fontPath = resolveFont($_POST['font'] ?? '');
    $format = $_POST['format'] ?? 'woff2';
    $text = (string) ($_POST['text'] ?? '');

    if ($fontPath === null) {
        fail(400, '字体不存在或不受支持');
    }
    if (!in_array($format, ALLOWED_FORMATS, true)) {
        fail(400, '输出格式仅支持 ttf / woff2');
    }
    validateText($text);

    header('Content-Type: text/css; charset=UTF-8');
    echo sprintf(
        "@font-face {\n    font-family: \"%s\";\n    src: url(\"%s\") format(\"%s\");\n    unicode-range: %s;\n}",
        fontFamily($fontPath),
        subsetFilename($fontPath, $format),
        FORMAT_CSS[$format],
        buildUnicodeRange($text)
    );
    exit;
}

// buildUnicodeRange 将字符转换为排序、去重、连续区间合并后的 unicode-range 值。
function buildUnicodeRange(string $text): string
{
    $points = [];
    foreach (mb_str_split($text) as $char) {
        $point = mb_ord($char, 'UTF-8');
        if ($point !== false) {
            $points[$point] = true;
        }
    }

    $points = array_keys($points);
    sort($points);
    $parts = [];
    $count = count($points);
    for ($i = 0; $i < $count; $i++) {
        $start = $points[$i];
        $end = $start;
        while ($i + 1 < $count && $points[$i + 1] === $end + 1) {
            $i++;
            $end = $points[$i];
        }
        $parts[] = $start === $end
            ? sprintf('U+%04X', $start)
            : sprintf('U+%04X-%04X', $start, $end);
    }
    return implode(', ', $parts);
}

function fontFamily(string $fontPath): string
{
    return pathinfo($fontPath, PATHINFO_FILENAME);
}

function subsetFilename(string $fontPath, string $format): string
{
    $base = preg_replace('/[^A-Za-z0-9._-]/', '', fontFamily($fontPath));
    return $base . '-subset.' . $format;
}

// ============================================================
// 输入校验与字体白名单
// ============================================================

function validateText(string $text): void
{
    if ($text === '') {
        fail(400, '请输入需要保留的字符');
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        fail(400, '字符集必须是 UTF-8 编码');
    }
    if (mb_strlen($text) > MAX_CHARS) {
        fail(400, '字符数量超过 ' . MAX_CHARS . ' 的限制');
    }
}

// resolveFont 将用户输入解析为 fonts/ 目录内的合法字体路径。
// 三重防御：basename 剥离目录、白名单校验、realpath 确认未逃逸出字体目录。
function resolveFont(string $name): ?string
{
    $name = basename($name);
    if (!isFontFile($name)) {
        return null;
    }
    $path = FONT_DIR . '/' . $name;
    if (strpos(realpath($path), realpath(FONT_DIR) . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $path;
}

function isFontFile(string $name): bool
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, FONT_EXTENSIONS, true)) {
        return false;
    }
    return is_file(FONT_DIR . '/' . $name);
}

// ============================================================
// 错误响应
// ============================================================

function fail(int $httpCode, string $message): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// Web UI
// ============================================================

function renderUi(): void
{
    $fonts = fontList();
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
    <form id="form">
        <label for="font">字体</label>
        <select id="font" name="font"><?php
            foreach ($fonts as $font) {
                echo '<option value="' . htmlspecialchars($font, ENT_QUOTES) . '">' . htmlspecialchars($font, ENT_QUOTES) . '</option>';
            }
        ?></select>

        <label for="format">输出格式</label>
        <select id="format" name="format">
            <option value="woff2" selected>WOFF2</option>
            <option value="ttf">TTF</option>
        </select>

        <label for="text">字符（仅保留这些字符）</label>
        <textarea id="text" name="text" placeholder="你好世界&#10;欢迎使用本网站&#10;ABCDEFGHIJKLMNOPQRSTUVWXYZ&#10;0123456789">你好世界，欢迎使用字体裁剪工具！</textarea>

        <button id="submit" type="submit">获取字体</button>
        <div id="message"></div>
        <pre id="css-output"></pre>
    </form>
</div>

<script>
const $form = document.getElementById('form');
const $button = document.getElementById('submit');
const $message = document.getElementById('message');
const $cssOutput = document.getElementById('css-output');

$form.addEventListener('submit', async (event) => {
    event.preventDefault();
    $message.textContent = '';
    $button.disabled = true;
    try {
        const body = new URLSearchParams({
            font: document.getElementById('font').value,
            format: document.getElementById('format').value,
            text: document.getElementById('text').value,
        });
        await Promise.all([downloadFont(body), loadCss(body)]);
    } finally {
        $button.disabled = false;
    }
});

async function downloadFont(body) {
    const response = await fetch('/', { method: 'POST', body });
    if (!response.ok) {
        showHttpError(response);
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
    const response = await fetch('?action=css', { method: 'POST', body });
    if (!response.ok) {
        showHttpError(response);
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

function fontList(): array
{
    $fonts = [];
    $entries = scandir(FONT_DIR);
    if ($entries !== false) {
        foreach ($entries as $name) {
            if (isFontFile($name)) {
                $fonts[] = $name;
            }
        }
    }
    sort($fonts, SORT_NATURAL | SORT_FLAG_CASE);
    return $fonts;
}
