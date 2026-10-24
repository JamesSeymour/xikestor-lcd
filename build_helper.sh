#!/bin/sh
# Build bin/xk_hidfeat as a small static binary (no runtime dependencies on Unraid).
# Uses musl-gcc if available (Ubuntu 24.04 gives a hash-identical build to CI),
# otherwise an Ubuntu 24.04 container via Docker.
set -e
cd "$(dirname "$0")"
mkdir -p bin
FLAGS="-static -Os -s -Wall -Wextra -Werror"
if command -v musl-gcc >/dev/null; then
  musl-gcc $FLAGS -o bin/xk_hidfeat src/xk_hidfeat.c
else
  # Same toolchain as CI (Ubuntu 24.04 musl-tools), so the hash matches the CI check
  docker run --rm -v "$PWD":/w -w /w ubuntu:24.04 \
    sh -c "apt-get update -q >/dev/null && apt-get install -y -q musl-tools >/dev/null && musl-gcc $FLAGS -o bin/xk_hidfeat src/xk_hidfeat.c"
fi
ls -l bin/xk_hidfeat
sha256sum bin/xk_hidfeat
