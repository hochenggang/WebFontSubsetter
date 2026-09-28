package main

import (
	"fmt"
	"os"
	"unicode"
	"unicode/utf8"
)

// maxFontBytes 与 PHP 侧 MAX_FONT_BYTES 保持一致：50MB。
const maxFontBytes = 50 << 20

// loadUnicodes 读取 UTF-8 字符集文件，剔除控制字符（换行、缩进等输入格式痕迹）
// 后按 rune 去重。空格属于可打印内容，予以保留。
func loadUnicodes(path string) ([]rune, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	if !utf8.Valid(data) {
		return nil, fmt.Errorf("%s 不是合法的 UTF-8 编码", path)
	}

	seen := make(map[rune]struct{})
	var unicodes []rune
	for _, r := range string(data) {
		if unicode.IsControl(r) {
			continue
		}
		if _, dup := seen[r]; dup {
			continue
		}
		seen[r] = struct{}{}
		unicodes = append(unicodes, r)
	}
	if len(unicodes) == 0 {
		return nil, fmt.Errorf("%s 不包含任何可保留的字符", path)
	}
	return unicodes, nil
}
