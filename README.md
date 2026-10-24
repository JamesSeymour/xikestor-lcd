# XikeStor LCD for Unraid

Brings the front-panel LCD on XikeStor NAS units back to life under Unraid. Out of the box the
panel sits on **"STARTING SYSTEM / PLEASE WAIT"** forever, because the vendor's own OS drives it
and Unraid has nothing that talks to it.

With this plugin the panel shows live data again, and its menu can shut down or reboot the server:

| Panel screen | Source on Unraid |
|---|---|
| CPU / SYS temperature | `hwmon` (coretemp/k10temp, ACPI or board sensor) |
| HDD1–HDD4 temperature | Unraid's `disks.ini` — **never wakes spun-down disks** (they show 0) |
| Total / used / free capacity | Array data disks, in GB |
| LAN1 / LAN2 IP, netmask, gateway | Auto-detected (skips loopback, Docker and VM interfaces), or configured |
| Menu → Shutdown / Reboot | Clean Unraid `poweroff` / `reboot` (can be disabled) |
| Menu → network settings, USB backup/eject | Ignored on Unraid (logged) |

## Compatibility

Tested on a 4-bay XikeStor unit with the panel exposed as a Holtek USB HID device. Check yours with:

```bash
lsusb | grep -i 374a:b101     # expect: HOLTEK USB-HID AP
```

If your model shows a different ID, please open an issue with the `lsusb` output.

Requires Unraid 6.12+. No other dependencies: the daemon is PHP (part of Unraid) plus a tiny
static helper binary that is built from source in this repo.

## Install

Search **XikeStor LCD** in the Apps tab (once listed in Community Applications), or
**Plugins → Install Plugin** and paste:

```
https://raw.githubusercontent.com/REPO_OWNER/xikestor-lcd/main/xk_lcd.plg
```

Settings live under **Settings → Utilities → XikeStor LCD**. The daemon starts automatically at boot.

> If you previously started the daemon from `/boot/config/go`, remove those lines first.

## Settings

| Setting | Default | Notes |
|---|---|---|
| Enable service | Yes | |
| HDD1–HDD4 slots | `parity,disk1,disk2,cache` | Unraid disk names in panel order; use `-` for an empty slot |
| LAN1/LAN2 interfaces | auto | e.g. `br0,eth1` |
| Allow Shutdown/Reboot from panel | Yes | |
| Refresh interval | 5 s | |

Log: `/var/log/xk_lcd.log` (in RAM). Manual control: `/usr/local/emhttp/plugins/xk_lcd/scripts/rc.xk_lcd {start|stop|restart|status}`.

## Known behaviour

- **The panel cannot show free text.** Its firmware draws fixed screens; the host only supplies values.
- **After a panel Shutdown** the panel waits for power to drop. It is powered from standby 5 V, so it
  only resets on a real power-off — a warm reboot from the Unraid UI does not clear it.
- **After a panel Reboot** the panel resets itself and re-enumerates on USB; the daemon reconnects automatically.
- Before the array starts, capacity shows 0.

## Protocol

Reverse-engineered for interoperability. All traffic uses **9-byte HID feature reports** on the
`hidraw` node (the 64-byte input/output reports in the descriptor are unused):

```
[0x00 report id] [marker] [cmd] [type] [d0] [d1] [d2] [d3] [checksum]
checksum = (255 - sum(bytes 0..7)) & 0xFF
marker   = 0x00 host -> panel,  0x80 panel -> host
```

**Host → panel** (`HIDIOCSFEATURE`):

| cmd | type | data | meaning |
|---|---|---|---|
| `0x88` | `0`, then `1` | – | Init handshake; panel leaves "STARTING SYSTEM" |
| `0x03` | `1` CPU, `2` SYS, `3–6` HDD1–4 | d0 = °C | Temperatures |
| `0x02` | `1` total, `2` used, `3` free | u32 big-endian, GB | Capacity |
| `0x00` | `1`/`2` IP, `0x81`/`0x82` netmask, `0x41`/`0x42` gateway | 4 octets | LAN1/LAN2 |
| `0x08` | `1` | 4 octets | "Default" IP |
| `0x04` | `1` | – | Ack: USB backup/eject finished |

**Panel → host** (`HIDIOCGFEATURE`, polled). Reads return the panel's pending action — or an
**echo of the host's last write with the marker set to `0x80`** (checksum adjusted), which must
be filtered out:

| frame | meaning |
|---|---|
| `00 80 00 00 00 00 00 00 7f` | Idle |
| `00 80 01 00 00 00 00 00 7e` | Shutdown requested |
| `00 80 01 01 00 00 00 00 7d` | Reboot requested |
| `00 80 02 <sel> <ip…>` | Network change requested \* |
| `00 80 04 00` / `00 80 04 01` | USB backup / eject requested \* |

\* Inferred from the vendor software's behaviour, not yet captured from a real panel. Shutdown and
Reboot frames are confirmed on hardware. If you capture these, please open an issue with the log line.

Button navigation is handled inside the panel; only menu actions are reported.

## How it works

- `src/xk_lcd.php` — the daemon: gathers data, drives the panel, handles menu actions.
- `src/xk_hidfeat.c` — a ~60-line helper. The panel only speaks HID *feature reports*, which
  need the `ioctl()` system call, and PHP has no `ioctl` (and Unraid's PHP has no FFI). The
  daemon runs the helper as a co-process and exchanges one hex line per report with it.
- `src/rc.xk_lcd` — start/stop script; `src/XikeStorLCD.page` — the settings page.

The helper is compiled as a static musl binary (~60 KB, no runtime dependencies) and embedded
in the `.plg`; the installer verifies its SHA-256 before use. To check the published binary,
rebuild it with `./build_helper.sh` and compare the hash it prints. An identical hash needs the
same compiler and musl versions; with a different toolchain, review `src/xk_hidfeat.c` instead.

Nothing on Unraid uses Python: PHP ships with Unraid, and the helper is self-contained.

## Development

Source lives in `src/`. The plugin file is generated so everything stays in one place. The build
runs on your development machine and needs Python 3 there, plus `musl-gcc` or Docker for the helper:

```bash
./build_helper.sh                                    # only when src/xk_hidfeat.c changes
python3 build.py --version 2026.10.24 --changes "- What changed"
```

Commit `src/`, `bin/xk_hidfeat`, and the regenerated `xk_lcd.plg`, `templates/xk_lcd.xml` and
`ca_profile.xml`.
Unraid detects updates from the `version` attribute, so bump it for every release. Set `OWNER`
(and optionally `SUPPORT`, your forum thread) at the top of `build.py` once.

The daemon also runs standalone (with the helper next to it):
`php xk_lcd.php --config my.cfg`, or `--test` to send fixed test values once.

## Disclaimer

Not affiliated with or endorsed by XikeStor. This is an independent implementation; no vendor
code or binaries are included. Use at your own risk — the panel menu can power off your server.

## Licence

MIT — see [LICENSE](LICENSE).
