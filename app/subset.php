<?php

declare(strict_types=1);

/**
 * 用例层：把一次 HTTP 请求翻译成一次 font-subset 子进程调用。
 *
 * 资源边界（顺序即防御顺序）：
 *   真人校验 → 参数与大小校验 → 单任务锁 → 临时文件 → 子进程（带超时）
 * 所有中间文件位于 sys_get_temp_dir()，随进程退出删除，服务器不保留任何产物。
 */

defined('FONT_SUBSET_APP') || exit;

const ALLOWED_FORMATS = ['ttf', 'woff2'];
const FORMAT_MIME = ['ttf' => 'font/ttf', 'woff2' => 'font/woff2'];
const FORMAT_CSS = ['ttf' => 'truetype', 'woff2' => 'woff2'];
const MAX_CHARS = 10000;
const MAX_FONT_BYTES = 50 << 20;        // 50MB，与 Go 侧 maxFontBytes 保持一致
const TASK_LOCK_FILE = 'font-subset.lock';
const EXIT_TIMEOUT = 6;                 // 与 Go 侧 exitTimeout 保持一致

// ============================================================
// 端点
// ============================================================

/** POST / —— 返回裁剪后的字体二进制。 */
function handleFontRequest(): void
{
    verifyTurnstileOrFail();
    $request = readSubsetRequest('woff2');

    acquireTaskLock();
    $textFile = createTempFile('font-text-', $request['text']);
    $outputFile = createTempFile('font-output-', '');
    runSubset($request['path'], $textFile, $outputFile, $request['format']);

    $data = @file_get_contents($outputFile);
    if ($data === false || $data === '') {
        fail(500, '字体裁剪结果为空');
    }
    sendBinary($data, FORMAT_MIME[$request['format']], subsetFilename($request['path'], $request['format']));
}

/** POST /?action=css —— 返回 @font-face + unicode-range 文本。纯字符串运算，不占计算资源。 */
function handleCssRequest(): void
{
    $request = readSubsetRequest('woff2');
    sendText(
        sprintf(
            "@font-face {\n    font-family: \"%s\";\n    src: url(\"%s\") format(\"%s\");\n    unicode-range: %s;\n}",
            fontFamily($request['path']),
            subsetFilename($request['path'], $request['format']),
            FORMAT_CSS[$request['format']],
            buildUnicodeRange($request['text'])
        ),
        'text/css; charset=UTF-8'
    );
}

// ============================================================
// 参数
// ============================================================

/**
 * 解析并校验两个端点共用的 font / format / text。
 * 任一参数非法即直接返回错误响应，因此本函数返回后参数必然可用。
 */
function readSubsetRequest(string $defaultFormat): array
{
    $fontPath = resolveFont(postString('font'));
    if ($fontPath === null) {
        fail(400, '字体不存在或不受支持');
    }
    $format = postString('format', $defaultFormat);
    if (!in_array($format, ALLOWED_FORMATS, true)) {
        fail(400, '输出格式仅支持 ttf / woff2');
    }
    $size = filesize($fontPath);
    if ($size === false || $size > MAX_FONT_BYTES) {
        fail(413, '字体文件超过大小限制');
    }
    return [
        'path'   => $fontPath,
        'format' => $format,
        'text'   => validateText(postString('text')),
    ];
}

function validateText(string $text): string
{
    if ($text === '') {
        fail(400, '请输入需要保留的字符');
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        fail(400, '字符集必须是 UTF-8 编码');
    }
    if (mb_strlen($text, 'UTF-8') > MAX_CHARS) {
        fail(400, '字符数量超过 ' . MAX_CHARS . ' 的限制');
    }
    return $text;
}

/** 将字符转换为排序、去重、连续区间合并后的 unicode-range 值。 */
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

// ============================================================
// 子进程
// ============================================================

function runSubset(string $fontPath, string $textFile, string $outputFile, string $format): void
{
    if (!function_exists('exec')) {
        fail(500, '当前 PHP 环境禁用了 exec()，无法调用裁剪程序');
    }
    $command = sprintf(
        '%s --input %s --text-file %s --output %s --format %s --timeout %d',
        escapeshellarg(subsetBinary()),
        escapeshellarg($fontPath),
        escapeshellarg($textFile),
        escapeshellarg($outputFile),
        escapeshellarg($format),
        config()['timeoutSeconds']
    );

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    if ($exitCode === EXIT_TIMEOUT) {
        fail(504, '字体裁剪超时（超过 ' . config()['timeoutSeconds'] . ' 秒），请减少字符数量后重试');
    }
    if ($exitCode !== 0 || !is_file($outputFile) || filesize($outputFile) === 0) {
        logError('font-subset 退出码 ' . $exitCode . ': ' . implode(' ', $output));
        fail(500, '字体裁剪失败：' . failureDetail($output));
    }
}

/** 子进程 stderr 的最后几行，前端排障用；没有任何输出时给一句可读的兜底说明。 */
function failureDetail(array $output): string
{
    $detail = trim(implode(' ', array_slice($output, -3)));
    return $detail === '' ? '子进程未输出任何信息' : $detail;
}

function subsetBinary(): string
{
    return APP_ROOT . '/bin/' . (DIRECTORY_SEPARATOR === '\\' ? 'font-subset.exe' : 'font-subset');
}

/** 返回部署环境问题列表，供 UI 提示，避免用户对着一个必然失败的按钮反复点。 */
function runtimeIssues(): array
{
    $issues = [];
    if (!function_exists('exec')) {
        $issues[] = '当前 PHP 环境禁用了 exec()，无法调用裁剪程序，请让主机解除限制。';
    }
    if (!is_file(subsetBinary())) {
        $issues[] = '缺少裁剪程序 bin/font-subset，请重新解压发行包。';
    }
    if (fontList() === []) {
        $issues[] = 'fonts/ 目录下没有可用字体，请上传 .ttf / .otf 文件（支持最多 2 层子目录）。';
    }
    return $issues;
}

// ============================================================
// 资源
// ============================================================

/**
 * 全局单任务锁：字体裁剪是 CPU 密集型操作，共享主机上必须串行。
 * 忙时立刻返回 429 而不是排队，避免请求堆积把 FPM 连接数吃满。
 */
function acquireTaskLock(): void
{
    $handle = @fopen(sys_get_temp_dir() . '/' . TASK_LOCK_FILE, 'c');
    if ($handle === false) {
        fail(500, '无法创建任务锁文件');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        fail(429, '已有字体裁剪任务在执行，请稍后重试');
    }
    register_shutdown_function(static function () use ($handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    });
}

/** 创建临时文件并登记"进程退出时删除"，因此任何中途失败都不会留下垃圾。 */
function createTempFile(string $prefix, string $content): string
{
    $path = @tempnam(sys_get_temp_dir(), $prefix);
    if ($path === false || @file_put_contents($path, $content) === false) {
        if ($path !== false) {
            @unlink($path);
        }
        fail(500, '无法创建临时文件');
    }
    register_shutdown_function(static function () use ($path) {
        @unlink($path);
    });
    return $path;
}
