<?php

declare(strict_types=1);

/**
 * Web Font Subsetter —— 入口与路由
 *
 *   GET  /              渲染 Web UI
 *   POST /              裁剪字体并直接返回字体二进制（font / format / text）
 *   POST /?action=css   同参数，返回 @font-face + unicode-range CSS 文本
 *
 * 职责边界：本文件只做"环境初始化 + 路由分发"，业务实现全部在 app/ 下。
 * 裁剪由 bin/font-subset（Go + HarfBuzz）完成，中间文件仅存在于
 * sys_get_temp_dir() 且随进程退出删除；除字体扫描缓存 cache/fonts.result
 * 外不产生任何持久化文件。
 */

define('FONT_SUBSET_APP', true);
define('APP_ROOT', __DIR__);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require APP_ROOT . '/app/http.php';
require APP_ROOT . '/app/config.php';
require APP_ROOT . '/app/fonts.php';
require APP_ROOT . '/app/turnstile.php';
require APP_ROOT . '/app/subset.php';
require APP_ROOT . '/app/ui.php';

if (isPost() && queryString('action') === 'css') {
    handleCssRequest();
}
if (isPost()) {
    handleFontRequest();
}
renderUi();
