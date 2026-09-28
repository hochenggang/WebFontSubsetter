# Web Font Subsetter

轻量级 Web 字体裁剪工具：在页面上选择服务器字体、输入需要保留的字符，即返回裁剪后的字体文件（TTF / WOFF2），并自动生成带 `unicode-range` 的 `@font-face` CSS。

无数据库、无缓存目录、无后台服务、不依赖 Python / Node.js / Composer。裁剪引擎为 HarfBuzz（经 [go-hbsubset](https://github.com/pgaskin/go-hbsubset) 纯 Go 绑定），最终只部署**一个静态 Linux ELF 二进制 + 一个 index.php**。

## 架构

```text
浏览器 ──POST font/format/text──▶ index.php ──临时文件──▶ bin/font-subset (Go)
                                                            │ HarfBuzz 子集化
浏览器 ◀──字体二进制── index.php ◀──临时字体文件──┘
```

- 字符集通过临时文件传递（避免 shell 参数长度与转义问题），全部中间文件位于 `sys_get_temp_dir()`，响应发出前删除。
- 子集化由 HarfBuzz 处理 glyph 闭包（composite glyph、GSUB/GPOS 依赖、CFF 轮廓、变体字体），不损失 OpenType 特性。

## 目录结构

```text
├── index.php          # Web UI + API
├── build.sh           # 构建脚本
├── bin/
│   └── font-subset    # Linux x86_64 部署产物（font-subset.exe 为本机测试用）
├── fonts/             # 字体库（.ttf / .otf，页面自动扫描）
└── src/font-subset/   # Go 源码
    ├── main.go        # 参数解析与校验
    ├── textfile.go    # 字符集文件读取（UTF-8 解码、去重）
    └── subset.go      # HarfBuzz 子集化 + WOFF2 编码
```

## 本地开发（Windows）

```bash
./build.sh                 # 构建本机 font-subset.exe + Linux ELF
php -S 127.0.0.1:8080      # 启动后访问 http://127.0.0.1:8080
```

### CLI 直接调用

```bash
bin/font-subset.exe \
    --input fonts/NotoSansSC-Regular.ttf \
    --text-file chars.txt \
    --output out.woff2 \
    --format woff2
```

退出码：`0` 成功；`2` 参数错误；`3` 输入不可读；`4` 子集化失败；`5` 输出不可写。

## DirectAdmin 部署

1. 上传 `index.php`、`bin/font-subset`、`fonts/*.ttf|otf` 到 `public_html/`。
2. `chmod 755 bin/font-subset`。
3. PHP 环境自检：

```php
<?php
echo PHP_OS;                     // Linux
echo php_uname('m');             // x86_64
var_dump(function_exists('exec')); // true（部分主机默认禁用）
```

若主机禁用了 `exec()`，需在主机控制面板的 PHP 设置中解除（该工具必须能调用外部二进制）。

## HTTP API

| 请求 | 说明 |
|---|---|
| `GET /` | Web UI |
| `POST /`（`font` / `format` / `text`） | 返回字体二进制，`Content-Type: font/woff2` 或 `font/ttf` |
| `POST /?action=css`（同参数） | 返回 `@font-face` + `unicode-range` CSS 文本 |

## 安全与限制

- 字体名经 `basename` + 白名单（fonts/ 目录扫描）+ `realpath` 三重校验，防路径穿越。
- 命令行参数全部经 `escapeshellarg()`；Go 程序自身再次校验 format 与文件。
- 限制：字符数 ≤ 10000、字体 ≤ 50MB、并发 1 个任务（`flock`，忙时返回 429）。
- 主机存在 GNU `timeout` 时自动套 60 秒超时；不存在则依赖任务串行化兜底。
- `display_errors` 强制关闭，错误以 JSON 返回，临时文件经 `register_shutdown_function` 保证清理。

## 已知限制

- `format=ttf` 输出的是子集化后的 SFNT：CFF 字体（.otf）输出仍是 CFF 容器，浏览器可正常加载。
- WOFF2 编码未做 glyf 表变换（仅 Brotli 压缩），体积略大于 `woff2_compress` 极限，符合规范。
