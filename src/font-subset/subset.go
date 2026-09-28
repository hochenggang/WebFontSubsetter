package main

import (
	"fmt"

	"github.com/pgaskin/go-hbsubset"
	tdfont "github.com/tdewolff/font"
)

const (
	formatTTF   = "ttf"
	formatWOFF2 = "woff2"
)

func validFormat(format string) bool {
	return format == formatTTF || format == formatWOFF2
}

// subsetFont 调用 HarfBuzz 子集化引擎裁剪字体，按 format 返回字节。
// 引擎自动处理 glyph 闭包（composite glyph、GSUB/GPOS 依赖），无需调用方关心。
// format=ttf 时原样返回子集化后的 SFNT（CFF 字体输出仍是 CFF 容器）。
func subsetFont(font []byte, unicodes []rune, format string) ([]byte, error) {
	out, err := hbsubset.Subset(font, 0, &hbsubset.Options{
		Unicodes: unicodes,
	})
	if err != nil {
		return nil, err
	}
	if format == formatTTF {
		return out, nil
	}
	return encodeWOFF2(out)
}

func encodeWOFF2(sfnt []byte) ([]byte, error) {
	f, err := tdfont.ParseSFNT(sfnt, 0)
	if err != nil {
		return nil, fmt.Errorf("解析子集字体失败: %w", err)
	}
	out, err := f.WriteWOFF2()
	if err != nil {
		return nil, fmt.Errorf("编码 WOFF2 失败: %w", err)
	}
	return out, nil
}
