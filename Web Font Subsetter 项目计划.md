# Web Font Subsetter 项目计划

## 1. 项目目标

构建一个轻量级的 Web 字体裁剪工具，用于将服务器上的完整字体文件，根据用户输入的字符集合进行子集化，并直接返回裁剪后的字体文件。

目标部署环境：

- DirectAdmin 虚拟主机
- Linux x86_64
- PHP 7.4+
- PHP 允许执行外部二进制程序
- 不依赖 Python
- 不依赖 Node.js
- 不依赖 Composer
- 不要求服务器安装系统级字体处理工具

核心架构：

```text
浏览器
   │
   │ POST
   │ font + format + text
   ▼
index.php
   │
   │ 临时文件
   ▼
font-subset
   │
   │ 字体子集化
   ▼
临时字体文件
   │
   ▼
PHP 读取到内存
   │
   ├── 删除临时输入文件
   └── 删除临时输出文件
   │
   ▼
HTTP Response
   │
   ▼
浏览器
```

---

# 2. 核心功能

## 2.1 字体选择

页面从：

```text
./fonts/
```

自动读取字体文件。

支持：

```text
.ttf
.otf
```

例如：

```text
fonts/
├── NotoSansSC-Regular.ttf
├── NotoSansSC-Bold.ttf
├── SourceHanSansSC-Regular.otf
└── ...
```

前端通过 `<select>` 选择字体。

---

## 2.2 输出格式选择

支持：

```text
TTF
WOFF2
```

页面：

```html
<select>
    <option value="ttf">TTF</option>
    <option value="woff2">WOFF2</option>
</select>
```

默认：

```text
WOFF2
```

因为 Web 页面通常优先使用 WOFF2。

---

## 2.3 字符输入

页面提供：

```html
<textarea>
```

用户输入需要保留的字符。

例如：

```text
你好世界
欢迎使用本网站
ABCDEFGHIJKLMNOPQRSTUVWXYZ
0123456789
，。！？：；（）【】
```

程序需要：

1. UTF-8 解码
2. 字符去重
3. 转换为 Unicode Code Point
4. 提交给字体 subsetter

---

# 3. Go 字体处理程序

## 3.1 程序名称

建议：

```text
font-subset
```

最终部署：

```text
bin/font-subset
```

---

## 3.2 CLI 接口

建议：

```bash
font-subset \
    --input /tmp/input.ttf \
    --text-file /tmp/chars.txt \
    --output /tmp/output.woff2 \
    --format woff2
```

参数：

| 参数 | 说明 |
|---|---|
| `--input` | 原始字体文件 |
| `--text-file` | UTF-8 字符文件 |
| `--output` | 输出字体文件 |
| `--format` | `ttf` / `woff2` |

---

## 3.3 不直接通过命令行传递字符

不推荐：

```bash
font-subset --text "你好世界..."
```

原因：

- 字符数量可能很大
- Shell 参数存在长度限制
- 特殊字符处理复杂
- 转义容易产生问题

采用：

```text
/tmp/font-text-XXXXXX
```

PHP 将 UTF-8 字符写入临时文件。

Go 读取：

```text
--text-file
```

---

# 4. 字体子集化技术方案

## 4.1 核心要求

不能简单删除 glyph。

必须正确处理：

```text
cmap
glyf
loca
hmtx
maxp
head
OS/2
name
post
GSUB
GPOS
GDEF
```

并处理 glyph dependency / glyph closure。

例如：

```text
汉
 ↓
glyph
 ↓
composite glyph
 ↓
依赖 glyph
```

不能因为用户没有直接指定某个 glyph，就把它错误删除。

---

## 4.2 字体引擎

优先采用：

```text
HarfBuzz hb-subset
```

而不是自行实现 OpenType subset。

原因：

- OpenType 规范复杂
- CJK 字体 glyph 数量巨大
- composite glyph 处理复杂
- GSUB / GPOS 存在依赖
- CFF / CFF2 处理复杂
- Variable Font 存在额外问题

项目目标不是重新实现字体引擎，而是封装成熟的 subset 能力。

---

# 5. WOFF2

Go 程序直接负责：

```text
TTF / OTF
      ↓
subset
      ↓
WOFF2
```

不再依赖：

```text
woff2_compress
```

这样服务器只需要一个：

```text
font-subset
```

二进制。

---

# 6. PHP 与 Go 的职责划分

## PHP

负责：

- Web UI
- 参数接收
- 字体白名单
- 请求验证
- 临时文件创建
- 调用 Go 二进制
- HTTP 响应
- CSS `@font-face` 生成

---

## Go

负责：

- 字体解析
- Unicode 字符解析
- glyph subset
- OpenType 数据处理
- TTF 输出
- WOFF2 输出

---

# 7. 临时文件策略

项目不生成缓存。

不使用：

```text
generated/
cache/
output/
```

所有中间文件放在：

```php
sys_get_temp_dir()
```

例如：

```text
/tmp/font-input-a81d.ttf
/tmp/font-text-93ab.txt
/tmp/font-output-1c82.woff2
```

处理完成：

```php
$data = file_get_contents($output);
```

之后立即：

```php
unlink($input);
unlink($textFile);
unlink($output);
```

最终：

```php
echo $data;
```

---

# 8. 内存返回机制

PHP 中：

```php
$data = file_get_contents($output);
```

得到的是 PHP 内存中的 binary string。

因此：

```php
unlink($output);
```

不会影响：

```php
$data
```

最终：

```php
header('Content-Type: font/woff2');
header('Content-Length: ' . strlen($data));

echo $data;
```

字体直接通过 HTTP 返回。

完整生命周期：

```text
磁盘
 ↓
Go 处理
 ↓
磁盘临时文件
 ↓
PHP file_get_contents()
 ↓
PHP 内存
 ↓
unlink()
 ↓
HTTP
```

---

# 9. HTTP API

## POST

请求：

```text
POST /
```

参数：

```text
font
format
text
```

例如：

```text
font=NotoSansSC-Regular.ttf
format=woff2
text=你好世界
```

---

## 成功响应

直接返回字体二进制。

WOFF2：

```http
Content-Type: font/woff2
```

TTF：

```http
Content-Type: font/ttf
```

建议同时：

```http
Content-Disposition: attachment; filename="NotoSansSC-subset.woff2"
```

---

# 10. 字体文件安全

字体文件必须限制在：

```text
./fonts/
```

禁止用户提交：

```text
../../etc/passwd
```

使用：

```php
basename($font)
```

同时验证：

```php
is_file()
```

并建议建立白名单。

例如：

```php
$allowedFonts = [
    'NotoSansSC-Regular.ttf',
    'NotoSansSC-Bold.ttf',
];
```

---

# 11. 命令执行安全

禁止：

```php
shell_exec($_POST['command']);
```

所有参数必须经过：

```php
escapeshellarg()
```

例如：

```php
$command = sprintf(
    '%s --input %s --text-file %s --output %s --format %s',
    escapeshellarg($binary),
    escapeshellarg($input),
    escapeshellarg($textFile),
    escapeshellarg($output),
    escapeshellarg($format)
);
```

Go 程序自身也必须验证：

```text
format
input
output
```

---

# 12. 请求限制

建议限制：

```text
最大字符数量：10,000
最大字体文件：50MB
最大输出字体：50MB
```

PHP：

```php
mb_strlen($text, 'UTF-8')
```

进行限制。

同时避免同时执行大量字体裁剪任务。

---

# 13. 并发控制

字体裁剪是 CPU 密集型操作。

共享虚拟主机不能允许用户无限并发调用。

第一阶段：

```text
单请求单任务
```

后续可以加入：

```text
/tmp/font-subset.lock
```

或者使用：

```text
flock()
```

控制并发。

建议初始限制：

```text
同时最多 1～2 个 subset 任务
```

具体数量根据服务器 CPU 和 PHP-FPM 限制调整。

---

# 14. 超时控制

PHP 调用 Go 程序时需要避免异常任务长期占用 CPU。

可以通过：

```text
timeout
```

或者 Go 自身增加处理限制。

推荐后续让 Go 支持：

```text
--timeout
```

默认：

```text
30 秒
```

---

# 15. Unicode Range

PHP 根据用户输入自动生成：

```css
unicode-range:
    U+4E00-4E01,
    U+4E03,
    U+4E16,
    U+754C;
```

例如输入：

```text
你好世界
```

输出：

```css
@font-face {
    font-family: NotoSansSC;
    src: url("/font-subset.woff2") format("woff2");
    unicode-range:
        U+4E16,
        U+4F60,
        U+597D,
        U+754C;
}
```

需要：

1. UTF-8 → Unicode Code Point
2. 去重
3. 排序
4. 连续 Code Point 合并

---

# 16. 项目目录

最终项目：

```text
font-subsetter/
│
├── index.php
├── README.md
│
├── bin/
│   └── font-subset
│
├── fonts/
│   ├── NotoSansSC-Regular.ttf
│   └── ...
│
└── src/
    └── font-subset/
        ├── main.go
        ├── go.mod
        └── ...
```

部署到 DirectAdmin 后：

```text
public_html/
│
├── index.php
│
├── bin/
│   └── font-subset
│
└── fonts/
    ├── xxx.ttf
    └── xxx.otf
```

---

# 17. Go 编译

目标平台：

```text
Linux
x86_64
```

即：

```text
GOOS=linux
GOARCH=amd64
```

最终：

```bash
CGO_ENABLED=0 \
GOOS=linux \
GOARCH=amd64 \
go build \
    -trimpath \
    -ldflags="-s -w" \
    -o font-subset
```

但是如果最终采用 HarfBuzz 原生库，则可能需要 CGO。

因此在技术验证阶段需要首先确认：

> 是否可以将 HarfBuzz + hb-subset 静态链接进最终 ELF。

如果无法稳定做到 `CGO_ENABLED=0`，则使用：

```text
CGO_ENABLED=1
```

并制作适用于目标服务器的 x86_64 Linux 二进制。

---

# 18. DirectAdmin 部署检查

上传：

```text
bin/font-subset
```

执行：

```bash
chmod 755 bin/font-subset
```

测试：

```bash
./bin/font-subset --help
```

PHP 测试：

```php
echo PHP_OS;
echo php_uname('m');
```

确认：

```text
Linux
x86_64
```

确认 PHP：

```php
function_exists('exec')
```

返回：

```text
true
```

---

# 19. 开发阶段

## Phase 1：字体子集核心

完成：

- Go 项目初始化
- 字体读取
- Unicode 输入
- glyph subset
- TTF 输出
- WOFF2 输出

验收：

```text
输入 NotoSansSC.ttf
输入 你好世界
输出 subset.woff2
```

浏览器可以正常加载。

---

## Phase 2：CLI

完成：

```bash
font-subset \
    --input font.ttf \
    --text-file chars.txt \
    --output result.woff2 \
    --format woff2
```

增加：

- 参数校验
- 错误码
- 错误信息
- 文件检查

---

## Phase 3：PHP

完成：

- 字体扫描
- 字体 select
- 格式 select
- textarea
- POST
- 临时文件
- 调用 Go
- 读取结果
- 删除临时文件
- 返回字体

---

## Phase 4：CSS

完成自动生成：

```css
@font-face {
    font-family: xxx;
    src: url("xxx.woff2") format("woff2");
    unicode-range:
        ...
}
```

页面显示 CSS 代码。

---

## Phase 5：安全

完成：

- 字体白名单
- 参数过滤
- 字符数量限制
- 文件大小限制
- 执行超时
- 并发限制
- 临时文件清理

---

## Phase 6：DirectAdmin 验证

在真实虚拟主机环境测试：

```text
PHP-FPM
exec()
权限
/tmp
x86_64
字体读取
二进制执行
WOFF2 输出
HTTP 下载
```

---

# 20. 验收标准

最终工具应满足：

### 功能

- [ ] GET 显示 Web UI
- [ ] 自动读取 `./fonts`
- [ ] 支持 TTF
- [ ] 支持 OTF
- [ ] 支持 TTF 输出
- [ ] 支持 WOFF2 输出
- [ ] 支持中文
- [ ] 支持英文
- [ ] 支持数字
- [ ] 支持标点
- [ ] 自动生成 `unicode-range`

### 性能

- [ ] 不生成永久缓存
- [ ] 中间文件仅存在于 `/tmp`
- [ ] PHP 读取字体到内存
- [ ] 返回 HTTP 后删除临时文件
- [ ] 不依赖 Python
- [ ] 不依赖 Node.js
- [ ] 不依赖 Composer

### 部署

- [ ] Linux x86_64
- [ ] DirectAdmin 可部署
- [ ] 单个 Go ELF
- [ ] `chmod 755` 后可执行
- [ ] PHP 可以调用

### 安全

- [ ] 防止路径穿越
- [ ] 防止任意命令执行
- [ ] 限制字符数量
- [ ] 限制字体大小
- [ ] 限制执行时间
- [ ] 限制并发
- [ ] 异常情况下自动删除临时文件

---

# 21. 最终产品形态

用户看到：

```text
┌──────────────────────────────────┐
│ Font Subsetter                   │
│                                  │
│ 字体                             │
│ [ Noto Sans SC              ▼ ] │
│                                  │
│ 输出格式                         │
│ [ WOFF2                      ▼ ] │
│                                  │
│ 字符                             │
│ ┌──────────────────────────────┐ │
│ │ 你好世界                     │ │
│ │ 欢迎使用字体裁剪工具         │ │
│ └──────────────────────────────┘ │
│                                  │
│              [ 获取字体 ]        │
│                                  │
│ @font-face {                     │
│     font-family: ...;            │
│     src: url(...);               │
│     unicode-range: ...;          │
│ }                                │
└──────────────────────────────────┘
```

服务器实际上只运行：

```text
index.php
    │
    ▼
font-subset
    │
    ▼
HTTP
```

没有数据库，没有缓存目录，没有后台服务，没有 Python，没有 Node.js。

---

# 22. 后续可选功能

第一版不实现，但架构预留：

- [ ] 字体预览
- [ ] 原字体大小 / 子集大小对比
- [ ] 压缩率显示
- [ ] 自动加入 ASCII 字符
- [ ] 自动加入常用中文标点
- [ ] 字符集模板
- [ ] Unicode Range 分组
- [ ] CSS 一键复制
- [ ] 拖拽上传字体
- [ ] Variable Font 支持
- [ ] 多字体批量处理
- [ ] WOFF 输出
- [ ] OTF 输出
- [ ] 字体 metadata 查看

第一版本的原则：

> **先把“字符 → 字体子集 → HTTP 返回”这条链路做稳定，再增加功能。**