#!/bin/sh
# Build bin/xk_hidfeat as a small static binary (no runtime dependencies on Unraid).
# Uses musl-gcc if available, otherwise an Alpine container (musl) via Docker.
set -e
cd "$(dirname "$0")"
mkdir -p bin
FLAGS="-static -Os -s -Wall -Wextra"
if command -v musl-gcc >/dev/null; then
  musl-gcc $FLAGS -o bin/xk_hidfeat src/xk_hidfeat.c
else
  docker run --rm -v "$PWD":/w -w /w alpine:3.20 \
    sh -c "apk add --no-cache build-base >/dev/null && gcc $FLAGS -o bin/xk_hidfeat src/xk_hidfeat.c"
fi
ls -l bin/xk_hidfeat
sha256sum bin/xk_hidfeat
