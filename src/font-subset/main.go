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
	"time"
)

// 退出码：0 成功；1 未分类错误；2 参数错误；3 输入不可读；4 子集化失败；5 输出不可写；6 超时。
const (
	exitGeneral = 1
	exitUsage   = 2
	exitInput   = 3
	exitSubset  = 4
	exitOutput  = 5
	exitTimeout = 6
)

// defaultTimeoutSeconds 与 PHP 侧 config.json 的 timeoutSeconds 默认值保持一致。
const defaultTimeoutSeconds = 30

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
		timeout  = flag.Int("timeout", defaultTimeoutSeconds, "单次裁剪最长执行秒数，<=0 表示不限制")
	)
	flag.Parse()

	startTimeoutWatchdog(*timeout)

	if err := run(*input, *textFile, *output, *format); err != nil {
		fmt.Fprintln(os.Stderr, "font-subset:", err)
		os.Exit(exitCode(err))
	}
}

// startTimeoutWatchdog 在超出上限后强制结束进程。
//
// 子集化是一次性的命令行任务，进程内不存在需要善后的共享状态，
// 因此 os.Exit 就是最可靠的超时手段：无论引擎卡在哪一层，
// 操作系统都会回收全部内存与文件句柄，不必依赖引擎自身可中断。
func startTimeoutWatchdog(seconds int) {
	if seconds <= 0 {
		return
	}
	go func() {
		time.Sleep(time.Duration(seconds) * time.Second)
		fmt.Fprintf(os.Stderr, "font-subset: 执行超过 %d 秒，已强制终止\n", seconds)
		os.Exit(exitTimeout)
	}()
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
