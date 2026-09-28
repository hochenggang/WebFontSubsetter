#!/usr/bin/env bash
# 构建 font-subset：Linux x86_64 部署产物 + 本机开发测试产物（纯 Go，无 CGO）。
set -euo pipefail
cd "$(dirname "$0")/src/font-subset"
LDFLAGS="-s -w"

# Linux 部署产物：静态 ELF，DirectAdmin 直接上传使用
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags="$LDFLAGS" -o ../../bin/font-subset .

# Windows 本机开发测试产物
case "$(go env GOOS)" in
    windows) CGO_ENABLED=0 go build -trimpath -ldflags="$LDFLAGS" -o ../../bin/font-subset.exe . ;;
    *) echo "跳过 Windows 本机构建（当前平台: $(go env GOOS)）" ;;
esac

echo "构建完成:"
ls -la ../../bin/
