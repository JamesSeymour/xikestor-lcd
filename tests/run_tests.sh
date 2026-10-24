#!/bin/bash
# Test suite for the XikeStor LCD plugin. Needs: php, python3, gcc (+ linux headers).
# Runs the real daemon against tests/mock_helper.py, a simulated panel.
set -u
cd "$(dirname "$0")/.."
ROOT=$PWD
FAILS=0
pass() { echo "  ok   $1"; }
fail() { echo "  FAIL $1"; FAILS=$((FAILS + 1)); }
check() { if eval "$2"; then pass "$1"; else fail "$1"; fi; }

echo "== static checks"
check "php syntax"            "php -l src/xk_lcd.php >/dev/null"
check "rc script syntax"      "bash -n src/rc.xk_lcd"
check "generated files fresh" "python3 build.py --check >/dev/null"
gcc -o /tmp/xk_check_ioctl tests/check_ioctl.c && check "ioctl numbers" "/tmp/xk_check_ioctl >/dev/null"

# start_daemon <cfg body> : runs the daemon against a fresh mock panel
start_daemon() {
  export MOCK_DIR=$(mktemp -d)
  printf '%s\n' "$1" > "$MOCK_DIR/test.cfg"
  XK_HIDFEAT="$ROOT/tests/mock_helper.py" XK_DEVICE=/dev/mock XK_DRY_RUN=1 \
    php src/xk_lcd.php --config "$MOCK_DIR/test.cfg" > "$MOCK_DIR/daemon.log" 2>&1 &
  DPID=$!
  sleep 3
}
LOG() { cat "$MOCK_DIR/daemon.log"; }
alive() { kill -0 "$DPID" 2>/dev/null; }

echo "== daemon: data push, echoes, reconnect, USB ack"
start_daemon 'POWER_ACTIONS="yes"
REFRESH="2"'
check "handshake sent first"        "head -2 \$MOCK_DIR/frames.log | tr '\n' ' ' | grep -q '^000088000000000077 000088010000000076 '"
check "temperatures sent"           "grep -q '^000003' \$MOCK_DIR/frames.log"
check "capacity sent"               "grep -q '^000002' \$MOCK_DIR/frames.log"
check "echoes not treated as input" "! LOG | grep -qE 'panel report|unrecognised'"
touch "$MOCK_DIR/die"; sleep 7
check "reconnects after helper exit" "LOG | grep -q 'reconnecting' && [ \$(LOG | grep -c 'initialised panel') -eq 2 ]"
echo 00800401000000007a > "$MOCK_DIR/inject"; sleep 3
check "eject request acknowledged once" "[ \$(grep -c '^0000040100000000fa' \$MOCK_DIR/frames.log) -eq 1 ]"
touch "$MOCK_DIR/unlatch"; sleep 1
check "daemon still running"        "alive"
kill "$DPID" 2>/dev/null; wait "$DPID" 2>/dev/null

echo "== daemon: power actions disabled"
start_daemon 'POWER_ACTIONS="no"'
echo 00800100000000007e > "$MOCK_DIR/inject"; sleep 2
check "shutdown ignored when disabled" "LOG | grep -q 'ignored (power actions disabled)' && alive"
kill "$DPID" 2>/dev/null; wait "$DPID" 2>/dev/null

echo "== daemon: power actions enabled"
start_daemon 'POWER_ACTIONS="yes"'
echo 00800101000000007d > "$MOCK_DIR/inject"; sleep 2
check "reboot requested"            "LOG | grep -q 'panel requested /sbin/reboot (dry run)'"
check "daemon exits after power action" "! alive"
kill "$DPID" 2>/dev/null; wait "$DPID" 2>/dev/null

echo "== data sources"
check "disks.ini, capacity, config" "php tests/data_sources.php"

echo
if [ "$FAILS" -eq 0 ]; then echo "All tests passed"; else echo "$FAILS test(s) failed"; exit 1; fi
