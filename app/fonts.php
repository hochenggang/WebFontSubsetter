<?php

declare(strict_types=1);

/**
 * 字体库：扫描 fonts/、维护扫描缓存、把外部输入解析为合法字体路径。
 *
 * 扫描结果的唯一出口是 fontList()；写入缓存的唯一入口是 refreshFontCache()。
 * 调用方只需知道"拿到字体相对路径列表"，不必关心缓存与锁。
 */

defined('FONT_SUBSET_APP') || exit;

const FONT_DIR = APP_ROOT . '/fonts';
const FONT_CACHE_DIR = APP_ROOT . '/cache';
const FONT_CACHE_FILE = FONT_CACHE_DIR . '/fonts.result';
const FONT_CACHE_LOCK = FONT_CACHE_DIR . '/fonts.lock';
const FONT_EXTENSIONS = ['ttf', 'otf'];
const FONT_SCAN_MAX_DEPTH = 2;      // fonts/ 之下的子目录层数上限：fonts/a/b/x.ttf 合法
const FONT_NAME_MAX_LENGTH = 255;

/**
 * 返回 fonts/ 下所有字体的相对路径（形如 "Noto/SansSC-Regular.ttf"）。
 *
 * 优先读 cache/fonts.result；缺失或过期时重建缓存，因此批量 GET 不会
 * 反复遍历磁盘目录——这是抵御 CC 的磁盘保护。
 */
function fontList(): array
{
    $cached = readFontCache();
    return $cached ?? refreshFontCache();
}

/** 读缓存；缺失、损坏、过期均返回 null，交由调用方重建。 */
function readFontCache(): ?array
{
    $raw = @file_get_contents(FONT_CACHE_FILE);
    if ($raw === false) {
        return null;
    }
    $cache = json_decode($raw, true);
    if (!is_array($cache) || !isset($cache['time'], $cache['fonts']) || !is_array($cache['fonts'])) {
        return null;
    }
    if ((int) $cache['time'] + config()['fontScanCacheSeconds'] <= time()) {
        return null;
    }
    return array_values(array_filter($cache['fonts'], 'is_string'));
}

/**
 * 重建缓存并返回字体列表。
 * flock 串行化：并发击穿时只有一个请求真正扫描，其余请求等锁后直接命中新缓存。
 */
function refreshFontCache(): array
{
    $handle = lockFontCache();
    if ($handle === null) {
        return scanFonts();     // 缓存目录不可写时降级为直接扫描，功能不受影响
    }
    try {
        $cached = readFontCache();      // 双重检查：等锁期间可能已被其他进程刷新
        if ($cached !== null) {
            return $cached;
        }
        $fonts = scanFonts();
        writeFontCache($fonts);
        return $fonts;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** 取得排他锁的句柄；无法创建缓存目录或加锁时返回 null。 */
function lockFontCache()
{
    if (!is_dir(FONT_CACHE_DIR) && !@mkdir(FONT_CACHE_DIR, 0755, true) && !is_dir(FONT_CACHE_DIR)) {
        return null;
    }
    $handle = @fopen(FONT_CACHE_LOCK, 'c');
    if ($handle === false) {
        return null;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return null;
    }
    return $handle;
}

/** 原子替换缓存文件：先写临时文件再 rename，读者永远不会看到半截 JSON。 */
function writeFontCache(array $fonts): void
{
    $payload = json_encode(
        ['time' => time(), 'fonts' => $fonts],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($payload === false) {
        return;
    }
    $temp = @tempnam(FONT_CACHE_DIR, 'fonts-');
    if ($temp === false || @file_put_contents($temp, $payload) === false) {
        if ($temp !== false) {
            @unlink($temp);
        }
        return;
    }
    if (!@rename($temp, FONT_CACHE_FILE)) {
        @unlink($temp);
    }
}

/** 遍历 fonts/，返回排序后的相对路径列表。 */
function scanFonts(): array
{
    $fonts = [];
    collectFonts(FONT_DIR, '', 0, $fonts);
    sort($fonts, SORT_NATURAL | SORT_FLAG_CASE);
    return $fonts;
}

function collectFonts(string $directory, string $prefix, int $depth, array &$fonts): void
{
    $entries = @scandir($directory);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '' || $entry[0] === '.') {     // 跳过 . / .. / 隐藏文件
            continue;
        }
        $path = $directory . '/' . $entry;
        $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
        if (is_dir($path)) {
            if ($depth < FONT_SCAN_MAX_DEPTH) {
                collectFonts($path, $relative, $depth + 1, $fonts);
            }
            continue;
        }
        if (hasFontExtension($entry)) {
            $fonts[] = $relative;
        }
    }
}

/**
 * 把外部提交的字体名解析为 fonts/ 目录内的真实路径，非法输入返回 null。
 *
 * 四重防御：字符白名单 → 拒绝 . 与 .. → 扩展名白名单 → realpath 必须落在 fonts/ 内。
 * 最后一步是兜底：即使前面的检查被绕过，逃出字体目录的路径也拿不到。
 */
function resolveFont(string $name): ?string
{
    $name = trim(str_replace('\\', '/', $name));
    if ($name === '' || strlen($name) > FONT_NAME_MAX_LENGTH || !isValidFontName($name)) {
        return null;
    }
    $real = realpath(FONT_DIR . '/' . $name);
    $root = realpath(FONT_DIR);
    if ($real === false || $root === false || !is_file($real)) {
        return null;
    }
    if (strncmp($real, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
        return null;
    }
    return $real;
}

function isValidFontName(string $name): bool
{
    if (strpos($name, "\0") !== false) {
        return false;
    }
    if (preg_match('#(^|/)\.\.?(/|$)#', $name) === 1) {
        return false;
    }
    // 控制字符与文件系统/shell 保留字符一旦出现在文件名中，几乎没有合法场景
    if (preg_match('#[\x00-\x1F\x7F<>"|?*:\\\\]#', $name) === 1) {
        return false;
    }
    return hasFontExtension($name);
}

function hasFontExtension(string $name): bool
{
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), FONT_EXTENSIONS, true);
}

/** fc-scan 意义上的字族名：取文件名主体（含 CJK 时保持原样）。 */
function fontFamily(string $fontPath): string
{
    return pathinfo($fontPath, PATHINFO_FILENAME);
}

/** 生成下载文件名：非 ASCII 字符会被剥离，剥离后为空则回退到 font。 */
function subsetFilename(string $fontPath, string $format): string
{
    $base = preg_replace('/[^A-Za-z0-9._-]/', '', fontFamily($fontPath));
    if (!is_string($base) || $base === '') {
        $base = 'font';
    }
    return $base . '-subset.' . $format;
}
