把 .ttf / .otf 字体放到本目录（或其子目录，最多 2 层），页面会自动扫描并列出。

例如：

    fonts/NotoSansSC-Regular.ttf
    fonts/Han/SourceHanSans-Regular.otf
    fonts/Han/Serif/SourceHanSerif-Regular.otf      <- 2 层，合法
    fonts/a/b/c/Latin.ttf                           <- 3 层，不会被扫描

扫描结果缓存于 ../cache/fonts.result，过期时间见 ../config.json 的
fontScanCacheSeconds（默认 30 秒）。
