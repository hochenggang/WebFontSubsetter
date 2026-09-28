# Web Font Subsetter

在网页上挑一个服务器上的字体、粘贴要保留的字符，立刻拿到裁剪后的字体文件（TTF / WOFF2），并自动生成带 `unicode-range` 的 `@font-face` CSS。

无数据库、无后台服务、不依赖 Python / Node.js / Composer 等运行时。子集化引擎 HarfBuzz（经 [go-hbsubset](https://github.com/pgaskin/go-hbsubset) 纯 Go 绑定）编译进单个静态 ELF，整个部署物就是 **一个 `index.php` + 若干 PHP 模块 + 一个二进制**。

```text
浏览器 ──POST font/format/text──▶ index.php ──临时文件──▶ bin/font-subset (Go)
                                                              │ HarfBuzz 子集化
浏览器 ◀──字体二进制── index.php ◀──临时字体文件──┘
```

## 特性

| | |
|---|---|
| 正确性 | HarfBuzz 处理 glyph 闭包（composite glyph、GSUB/GPOS 依赖、CFF 轮廓、变体字体），不丢 OpenType 特性 |
| 输出 | `woff2`（默认，Brotli 压缩）/ `ttf`；`.otf` 输入输出仍是 CFF 容器，浏览器可直接加载 |
| 字体库 | 递归扫描 `fonts/`（**最多 2 层子目录**），页面按 `目录/字体` 展示 |
| 扫描缓存 | 结果落 `cache/fonts.result`，默认 30 秒过期，原子写入 + `flock` 防击穿，批量 GET 不会反复遍历磁盘 |
| 超时 | 单次裁剪可配置上限（默认 30 秒），超时由 Go 侧看门狗强制终止进程并返回 504 |
| 并发 | 全局单任务锁，忙时立刻 429，不排队堆积 |
| 人机校验 | 可选接入 Cloudflare Turnstile；不配置 key 时页面与后端完全不出现该功能 |
| 依赖 | PHP 7.4+，`exec()` 未被禁用，x86_64 Linux |

## 一键部署

发行包为 **Linux x86_64** 静态二进制，适用于 DirectAdmin / cPanel 等共享主机。

`cd` 到站点根目录（即 `index.php` 应落地的地方），执行：

```bash
cd ~/domains/font.imhcg.cn/public_html && \
  curl -fsSL https://github.com/hochenggang/WebFontSubsetter/releases/latest/download/font-subsetter.zip -o font-subsetter.zip && \
  unzip -oq font-subsetter.zip && rm -f font-subsetter.zip && \
  chmod 755 bin/font-subset
```

三件事：**下载最新发行包 → 就地解压（包内无顶层目录）→ 给二进制加可执行位**。

接着：

1. 上传字体：把 `.ttf` / `.otf` 放进 `fonts/`（子目录最多 2 层）。
2. 可选配置：`cp config.example.json config.json` 后按需修改，见下一节。
3. 访问站点根路径即可。

> 若主机没有 `unzip`，可先在本地解压再整体上传；`config.json` 不会随包分发，升级时直接覆盖解压不会丢配置。

### 前置自检

```php
<?php
echo PHP_OS;                        // Linux
echo php_uname('m');                // x86_64
var_dump(function_exists('exec'));  // true —— 部分主机默认禁用
```

`exec()` 被禁用时页面会直接给出红色告警，不会让你对着一个必然失败的按钮反复点。

## 目录结构

```text
├── index.php              # 入口与路由（只做环境初始化 + 分发）
├── config.example.json    # 配置模板；复制为 config.json 后生效
├── .htaccess              # 保护 config.json / app/ / cache/
├── app/                   # 应用模块（每个文件单一职责）
│   ├── http.php           #   请求/响应原语、错误出口
│   ├── config.php         #   配置加载与校验
│   ├── fonts.php          #   字体扫描、缓存、路径解析
│   ├── turnstile.php      #   真人校验
│   ├── subset.php         #   用例层：裁剪、CSS、临时文件、子进程
│   └── ui.php             #   页面渲染
├── bin/
│   └── font-subset        # Linux x86_64 部署产物（CI 编译）
├── fonts/                 # 字体库（.ttf / .otf，最多 2 层子目录）
├── cache/                 # 运行时生成：fonts.result + fonts.lock
└── src/font-subset/       # Go 源码
    ├── main.go            #   参数解析、退出码、超时看门狗
    ├── textfile.go        #   字符集文件读取（UTF-8 校验、去重、剔控制字符）
    └── subset.go          #   HarfBuzz 子集化 + WOFF2 编码
```

## 配置 `config.json`

可选。文件不存在、JSON 损坏、字段类型不对，都会**降级到默认值而不是拒绝服务**。

```json
{
    "timeoutSeconds": 30,
    "fontScanCacheSeconds": 30,
    "cfTurnstileSiteKey": "",
    "cfTurnstileSecret": ""
}
```

| 字段 | 默认 | 取值范围 | 说明 |
|---|---|---|---|
| `timeoutSeconds` | `30` | 1–600 | 单次裁剪最长执行秒数，超时返回 504 |
| `fontScanCacheSeconds` | `30` | 1–3600 | `fonts/` 扫描结果缓存有效期 |
| `cfTurnstileSiteKey` | `""` | — | Turnstile 站点公钥，用于前端注入组件 |
| `cfTurnstileSecret` | `""` | — | Turnstile 私钥；**为空即彻底关闭真人校验** |

### 关于 Turnstile

在 [Cloudflare Turnstile 控制台](https://dash.cloudflare.com/)建站点拿到一对 key，两个字段都填上即可：

- 前端自动注入 `api.js` 与 `.cf-turnstile` 组件，验证完成前提交按钮保持禁用。
- 后端在**调用裁剪子进程之前**校验 token，校验不通过返回 403；Cloudflare 不可达返回 503（区分"你不是人"和"验证服务挂了"）。
- Turnstile token 一次性有效，页面在每次提交后自动 `reset()`，否则第二次提交必然失败。

> `?action=css` 只做纯字符串运算、不占用计算资源，因此不经过人机校验。

## 本地开发

```bash
./build.sh                     # go vet + 构建 Linux ELF 与本机测试用二进制
cp config.example.json config.json
php -S 127.0.0.1:8080          # 访问 http://127.0.0.1:8080
```

Windows 下会额外产出 `bin/font-subset.exe`，PHP 会按平台自动选用。

### 命令行直接调用

```bash
bin/font-subset \
    --input fonts/NotoSansSC-Regular.ttf \
    --text-file chars.txt \
    --output out.woff2 \
    --format woff2 \
    --timeout 30
```

| 参数 | 必填 | 说明 |
|---|---|---|
| `--input` | ✅ | 原始字体（TTF / OTF / TTC） |
| `--text-file` | ✅ | UTF-8 字符集文件；不通过命令行传字符，避免参数长度与转义问题 |
| `--output` | ✅ | 输出字体路径 |
| `--format` | | `ttf` / `woff2`，默认 `woff2` |
| `--timeout` | | 秒；`<= 0` 表示不限，默认 `30` |

退出码：`0` 成功 / `1` 未分类错误 / `2` 参数错误 / `3` 输入不可读 / `4` 子集化失败 / `5` 输出不可写 / `6` 超时。

## HTTP API

| 请求 | 说明 |
|---|---|
| `GET /` | Web UI |
| `POST /`（`font` / `format` / `text`） | 返回字体二进制，`Content-Type: font/woff2` 或 `font/ttf` |
| `POST /?action=css`（同参数） | 返回 `@font-face` + `unicode-range` CSS 文本 |

```bash
curl -X POST https://font.example.com/ \
    --data-urlencode "font=NotoSansSC-Regular.ttf" \
    --data-urlencode "format=woff2" \
    --data-urlencode "text=你好世界" \
    -o subset.woff2
```

错误统一为 JSON：`{"error":"..."}`，状态码 `400 / 403 / 413 / 429 / 500 / 503 / 504`。

## 安全设计

- **路径穿越**：字体名经「字符白名单 → 拒绝 `.` `..` → 扩展名白名单 → `realpath` 必须落在 `fonts/` 内」四重校验。最后一步是兜底，前面被绕过也逃不出字体目录。
- **命令执行**：所有参数经 `escapeshellarg()`；Go 程序自身再校验 format 与文件，`format` 只接受 `ttf` / `woff2`。
- **类型注入**：`$_POST` 中的数组/对象一律回退到默认值，不会出现 `basename(array)` 这类致命错误。
- **资源上限**：字符数 ≤ 10000、字体 ≤ 50MB（PHP 与 Go 双侧一致）、并发 1 个任务、单任务超时。
- **临时文件**：全部位于 `sys_get_temp_dir()`，创建即登记"进程退出时删除"，任何中途失败都不留垃圾。
- **敏感文件**：`.htaccess` 拒绝访问 `config.json` / `app/` / `cache/`；同时 `app/*.php` 每个文件都要求入口常量 `FONT_SUBSET_APP`，即使主机未启用 `mod_rewrite`，直接请求也只会得到空白而不是源码泄漏。
- **错误信息**：`display_errors` 强制关闭，错误以 JSON 返回并写入 `error_log`。

部署后建议确认 `config.json` 无法通过 URL 访问：

```bash
curl -I https://font.example.com/config.json   # 期望 403
```

## 已知限制

- WOFF2 编码未做 glyf 表变换（仅 Brotli 压缩），体积略大于 `woff2_compress` 的理论极限，但完全符合规范。
- 单个 PHP 进程串行处理，`flock` 锁只在多进程/多 worker 的 FPM 环境下才有意义。
- `request_terminate_timeout` 需大于 `timeoutSeconds`，否则 PHP-FPM 会先杀掉请求。
- 发行包不含字体文件，请自行放入 `fonts/` 并确认字体授权允许 Web 分发。

## 发布新版本

打 tag 即自动编译 x86_64 发行包并创建 Release：

```bash
git tag v0.1.1 && git push origin v0.1.1
```

工作流也可手动触发（`Actions → Release → Run workflow`），此模式只产出 Artifact、不建 Release，便于验证构建。

## License

[MIT](LICENSE)
