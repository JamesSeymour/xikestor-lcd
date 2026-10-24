#!/usr/bin/env python3
"""Stand-in for xk_hidfeat + the real panel, used by run_tests.sh.

Mimics observed hardware behaviour: GET_FEATURE returns the idle frame until the host
writes, then echoes the host's last write with the 0x80 marker and adjusted checksum.
Control files in $MOCK_DIR:  inject  - one-shot action frame, latched until 'unlatch'
                             unlatch - clear a latched action
                             die     - helper exits on next read (simulates USB drop)
Every frame the daemon sends is appended to $MOCK_DIR/frames.log.
"""
import os, sys

d = os.environ["MOCK_DIR"]
p = lambda n: os.path.join(d, n)
last = None
with open(p("frames.log"), "a") as log:
    for line in sys.stdin:
        line = line.strip()
        if line.startswith("S "):
            last = bytes.fromhex(line[2:])
            log.write(line[2:] + "\n")
            log.flush()
            print("OK", flush=True)
        elif line == "G":
            if os.path.exists(p("die")):
                os.remove(p("die"))
                sys.exit(0)
            if os.path.exists(p("inject")):
                os.replace(p("inject"), p("latched"))
            if os.path.exists(p("unlatch")):
                for n in ("unlatch", "latched"):
                    if os.path.exists(p(n)):
                        os.remove(p(n))
            if os.path.exists(p("latched")):
                print("R " + open(p("latched")).read().strip(), flush=True)
            elif last is None:
                print("R 00800000000000007f", flush=True)
            else:
                e = bytearray(last)
                e[1] = 0x80
                e[8] = (255 - sum(e[:8])) & 0xFF
                print("R " + e.hex(), flush=True)
        else:
            print("ERR 22 unknown command", flush=True)
