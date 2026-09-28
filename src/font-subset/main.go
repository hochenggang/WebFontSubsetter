// font-subset 将完整字体按字符集裁剪为子集，输出 TTF 或 WOFF2。
//
// 调用方只需提供 --input / --text-file / --output / --format，
// 字符集文件必须是合法 UTF-8，字符经去重后提交给 HarfBuzz 子集化引擎。
package main

import (
	"errors"
	"flag"
	"fmt"
	"os"
)

// 退出码：0 成功；1 未分类错误；2 参数错误；3 输入不可读；4 子集化失败；5 输出不可写。
const (
	exitGeneral = 1
	exitUsage   = 2
	exitInput   = 3
	exitSubset  = 4
	exitOutput  = 5
)

var (
	ErrUsage  = errors.New("invalid arguments")
	ErrInput  = errors.New("cannot read input")
	ErrSubset = errors.New("subsetting failed")
	ErrOutput = errors.New("cannot write output")
)

func main() {
	var (
		input    = flag.String("input", "", "原始字体文件（TTF/OTF/TTC）")
		textFile = flag.String("text-file", "", "UTF-8 字符集文件")
		output   = flag.String("output", "", "输出字体文件")
		format   = flag.String("format", formatWOFF2, "输出格式：ttf / woff2")
	)
	flag.Parse()

	if err := run(*input, *textFile, *output, *format); err != nil {
		fmt.Fprintln(os.Stderr, "font-subset:", err)
		os.Exit(exitCode(err))
	}
}

func run(input, textFile, output, format string) error {
	if input == "" || textFile == "" || output == "" || !validFormat(format) {
		flag.Usage()
		return fmt.Errorf("%w: input, text-file, output 为必填，format 仅支持 ttf / woff2", ErrUsage)
	}

	font, err := os.ReadFile(input)
	if err != nil {
		return fmt.Errorf("%w: %w", ErrInput, err)
	}
	if len(font) > maxFontBytes {
		return fmt.Errorf("%w: %s 超过 %d 字节限制", ErrInput, input, maxFontBytes)
	}

	unicodes, err := loadUnicodes(textFile)
	if err != nil {
		return fmt.Errorf("%w: %w", ErrInput, err)
	}

	out, err := subsetFont(font, unicodes, format)
	if err != nil {
		return fmt.Errorf("%w: %w", ErrSubset, err)
	}

	if err := os.WriteFile(output, out, 0o644); err != nil {
		return fmt.Errorf("%w: %w", ErrOutput, err)
	}
	return nil
}

func exitCode(err error) int {
	switch {
	case errors.Is(err, ErrUsage):
		return exitUsage
	case errors.Is(err, ErrInput):
		return exitInput
	case errors.Is(err, ErrSubset):
		return exitSubset
	case errors.Is(err, ErrOutput):
		return exitOutput
	default:
		return exitGeneral
	}
}
