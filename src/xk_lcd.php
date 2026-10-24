<?php
/*
 * xk_lcd.php - Unraid driver for the XikeStor front-panel LCD (Holtek USB HID 374a:b101).
 *
 * PROJECT_URL  -  MIT licence.
 * Not affiliated with XikeStor. Independent implementation; no vendor code included.
 *
 * Protocol (reverse-engineered for interoperability):
 *   Every message is a 9-byte HID *feature* report:
 *       [0x00 report-id] [marker] [cmd] [type] [d0] [d1] [d2] [d3] [checksum]
 *       checksum = (255 - sum(bytes 0..7)) & 0xFF;  marker 0x00 host->panel, 0x80 panel->host
 *   Host -> panel (SET_FEATURE):
 *       cmd 0x88 type 0, then type 1        init handshake (panel leaves "STARTING SYSTEM")
 *       cmd 0x03 type 1..6, d0 = deg C      temps: 1 CPU, 2 SYS, 3-6 HDD1-4
 *       cmd 0x02 type 1..3, d0-d3 = BE u32  capacity in GB: 1 total, 2 used, 3 free
 *       cmd 0x00 type 1/2, 0x81/0x82, 0x41/0x42   LAN1/2 IP, netmask, gateway (octets)
 *       cmd 0x08 type 1, d0-d3 = octets     "default" IP
 *       cmd 0x04 type 1                     ack: USB backup/eject finished
 *   Panel -> host (GET_FEATURE, polled): [0x00] [0x80] [action] [arg] ... [checksum]
 *       Reads also echo the host's last write with the marker set to 0x80 (checksum
 *       adjusted), e.g. 00 80 08 01 c0 a8 01 cf 3e, so echoes must be filtered out.
 *       action 0: idle
 *       action 1: arg 0 = shutdown (panel then waits for power-off), 1 = reboot
 *                 (panel resets itself and re-enumerates on USB ~3s later)
 *       action 2: network change  -> ignored on Unraid
 *       action 4: arg 0 = USB backup, 1 = eject -> ignored, but acknowledged
 *   The panel draws its own screens and handles button navigation; no free text.
 *
 * PHP has no ioctl(), so feature reports go through the xk_hidfeat helper
 * (src/xk_hidfeat.c), run as a co-process speaking a line protocol on stdin/stdout.
 */

const CFG_FILE  = "/boot/config/plugins/xk_lcd/xk_lcd.cfg";
const DISKS_INI = "/var/local/emhttp/disks.ini";
define("HELPER", getenv("XK_HIDFEAT") ?: __DIR__ . "/xk_hidfeat");   // env overrides are for testing
define("DRY_RUN", getenv("XK_DRY_RUN") === "1");
const HID_ID    = "HID_ID=0003:0000374A:0000B101";
const POLL_US   = 500000;
const DEFAULTS  = [
    "HDD_SLOTS"     => "parity,disk1,disk2,cache",   // Unraid disk names shown as HDD1-4
    "LAN_IFACES"    => "",                           // e.g. "br0,eth1"; blank = auto-detect
    "POWER_ACTIONS" => "yes",                        // honour Shutdown/Reboot from the panel
    "REFRESH"       => "5",                          // seconds between data refreshes
];

class DeviceError extends Exception {}

if (!ini_get("date.timezone") && is_link("/etc/localtime")
    && preg_match('#zoneinfo/(.+)$#', (string)readlink("/etc/localtime"), $tz)) {
    @date_default_timezone_set($tz[1]);
}

function logmsg(string $msg): void {
    echo date("Y-m-d H:i:s"), " ", $msg, "\n";
}

// ------------------------------------------------------------------ config ---
function load_config(string $path): array {
    $cfg = DEFAULTS;
    if (is_readable($path)) {
        $ini = @parse_ini_file($path, false, INI_SCANNER_RAW);
        if (is_array($ini)) {
            foreach ($ini as $k => $v) $cfg[$k] = trim((string)$v, " \t\"");
        }
    }
    $split = fn($v) => array_values(array_filter(array_map("trim", explode(",", $v)), "strlen"));
    $lan = $split($cfg["LAN_IFACES"]);
    return [
        "hdd"     => $split($cfg["HDD_SLOTS"]),
        "lan"     => $lan ?: null,
        "power"   => in_array(strtolower($cfg["POWER_ACTIONS"]), ["yes", "true", "1"], true),
        "refresh" => max(2, (int)$cfg["REFRESH"] ?: 5),
    ];
}

// ------------------------------------------------------------------ device ---
function find_hidraw(): ?string {
    if ($dev = getenv("XK_DEVICE")) return $dev;
    foreach (glob("/sys/class/hidraw/hidraw*") ?: [] as $path) {
        $uevent = @file_get_contents("$path/device/uevent");
        if ($uevent !== false && str_contains($uevent, HID_ID)) return "/dev/" . basename($path);
    }
    return null;
}

function frame(int $cmd, int $type, array $data = [0, 0, 0, 0]): array {
    $b = [0, 0, $cmd & 0xFF, $type & 0xFF];
    foreach ($data as $x) $b[] = $x & 0xFF;
    $b[] = (255 - array_sum($b)) & 0xFF;
    return $b;
}

function hexs(array $b): string {
    return implode(" ", array_map(fn($x) => sprintf("%02x", $x), $b));
}

class Panel {
    private $proc;
    private $in;
    private $out;
    private array $sent = [];   // frames we wrote, plus the 0x80-marked echo of each (hex keys)
    private array $last = [];

    public function __construct(string $dev) {
        $this->proc = proc_open([HELPER, $dev], [0 => ["pipe", "r"], 1 => ["pipe", "w"]], $pipes);
        if (!is_resource($this->proc)) throw new DeviceError("cannot start " . HELPER);
        [$this->in, $this->out] = $pipes;
    }

    private function call(string $line): string {
        if (!is_resource($this->in) || !is_resource($this->proc) || !proc_get_status($this->proc)["running"]) {
            throw new DeviceError("helper not running");
        }
        if (@fwrite($this->in, "$line\n") === false) throw new DeviceError("helper pipe closed");
        $resp = fgets($this->out);
        if ($resp === false) throw new DeviceError("helper exited");
        $resp = rtrim($resp);
        if (str_starts_with($resp, "ERR")) throw new DeviceError(substr($resp, 4));
        return $resp;
    }

    public function send(int $cmd, int $type, array $data = [0, 0, 0, 0]): void {
        $f = frame($cmd, $type, $data);
        $echo = $f;
        $echo[1] = 0x80;
        $echo[8] = (255 - array_sum(array_slice($echo, 0, 8))) & 0xFF;
        $this->last = [hexs($f) => true, hexs($echo) => true];
        $this->sent += $this->last;
        $this->call("S " . vsprintf(str_repeat("%02x", 9), $f));
    }

    public function read(): array {
        $resp = $this->call("G");
        return array_map("hexdec", str_split(substr($resp, 2), 2));
    }

    public function newCycle(): void { $this->sent = $this->last; }
    public function wasSent(array $rep): bool { return isset($this->sent[hexs($rep)]); }

    public function close(): void {
        if (is_resource($this->in)) fclose($this->in);
        if (is_resource($this->out)) fclose($this->out);
        if (is_resource($this->proc)) proc_close($this->proc);
    }

    public function init(): void { $this->send(0x88, 0); $this->send(0x88, 1); }

    public function temp(int $slot, float $c): void {
        $this->send(0x03, $slot, [max(0, min(255, (int)$c)), 0, 0, 0]);
    }

    public function capacity(int $slot, int $gb): void {
        $gb = max(0, min(0xFFFFFFFF, $gb));
        $this->send(0x02, $slot, [($gb >> 24) & 0xFF, ($gb >> 16) & 0xFF, ($gb >> 8) & 0xFF, $gb & 0xFF]);
    }

    public function ip(int $type, string $addr, int $cmd = 0x00): void {
        $this->send($cmd, $type, array_map("intval", explode(".", $addr)));
    }
}

// ------------------------------------------------------------ data sources ---
function read_disks_ini(): array {
    $d = @parse_ini_file(DISKS_INI, true);
    return is_array($d) ? $d : [];
}

function hwmon_temp(array $names, ?string $labelHint = null): ?float {
    foreach (glob("/sys/class/hwmon/hwmon*") ?: [] as $hw) {
        $name = trim((string)@file_get_contents("$hw/name"));
        if (!in_array($name, $names, true)) continue;
        $inputs = glob("$hw/temp*_input") ?: [];
        sort($inputs, SORT_NATURAL);
        if ($labelHint !== null) {
            foreach ($inputs as $inp) {
                $lbl = @file_get_contents(str_replace("_input", "_label", $inp));
                if ($lbl !== false && stripos($lbl, $labelHint) !== false) {
                    return (int)file_get_contents($inp) / 1000;
                }
            }
        }
        if ($inputs) return (int)file_get_contents($inputs[0]) / 1000;
    }
    return null;
}

function cpu_temp(): float {
    return hwmon_temp(["coretemp"], "package") ?? hwmon_temp(["k10temp", "zenpower"], "tctl") ?? 0;
}

function sys_temp(): float {
    return hwmon_temp(["acpitz"])
        ?? hwmon_temp(["nct6775", "nct6798", "it87", "it8613", "it8625"])
        ?? cpu_temp();
}

function disk_temp(array $disks, string $name): int {
    $t = (string)($disks[$name]["temp"] ?? "*");
    return ctype_digit($t) ? (int)$t : 0;          // "*" = spun down; never wake disks
}

function array_capacity_gb(array $disks): array {
    $size = $free = 0;
    foreach ($disks as $d) {
        if (($d["type"] ?? "") === "Data" && ctype_digit((string)($d["fsSize"] ?? ""))) {
            $size += (int)$d["fsSize"];
            $free += (int)($d["fsFree"] ?? 0);
        }
    }
    if ($size) return [intdiv($size, 1 << 20), intdiv($size - $free, 1 << 20), intdiv($free, 1 << 20)];
    if (!is_dir("/mnt/user")) return [0, 0, 0];      // array not started yet
    $total = (int)@disk_total_space("/mnt/user");
    $avail = (int)@disk_free_space("/mnt/user");
    return [intdiv($total, 1 << 30), intdiv($total - $avail, 1 << 30), intdiv($avail, 1 << 30)];
}

function interfaces(?array $want): array {
    $skip = '/^(lo|docker|virbr|veth|vnet|shim|vhost|br-|tun|tap|wg)/';
    $gws = [];
    preg_match_all('/via (\S+) dev (\S+)/', (string)shell_exec("ip -4 route show default 2>/dev/null"), $m, PREG_SET_ORDER);
    foreach ($m as $g) $gws[$g[2]] = $g[1];
    $anyGw = $gws ? reset($gws) : "0.0.0.0";

    $result = [];
    foreach (explode("\n", (string)shell_exec("ip -4 -o addr show 2>/dev/null")) as $line) {
        if (!preg_match('/^\d+:\s+(\S+)\s+inet (\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $mm)) continue;
        [, $name, $addr, $prefix] = $mm;
        if ($want !== null ? !in_array($name, $want, true) : preg_match($skip, $name)) continue;
        $mask = $prefix == 0 ? 0 : (0xFFFFFFFF << (32 - (int)$prefix)) & 0xFFFFFFFF;
        $result[] = [$name, $addr, long2ip($mask), $gws[$name] ?? $anyGw];
    }
    if ($want !== null) usort($result, fn($a, $b) => array_search($a[0], $want) <=> array_search($b[0], $want));
    return array_slice($result, 0, 2);
}

// -------------------------------------------------------------------- main ---
function push_all(Panel $p, array $cfg): void {
    $p->newCycle();
    $disks = read_disks_ini();
    $p->temp(2, sys_temp());
    $p->temp(1, cpu_temp());
    foreach (array_slice($cfg["hdd"], 0, 4) as $i => $name) $p->temp(3 + $i, disk_temp($disks, $name));
    [$total, $used, $free] = array_capacity_gb($disks);
    $p->capacity(1, $total);
    $p->capacity(3, $free);
    $p->capacity(2, $used);
    $lans = interfaces($cfg["lan"]);
    foreach ($lans as $i => [, $addr, $mask, $gw]) {
        $p->ip(1 + $i, $addr);
        $p->ip(0x81 + $i, $mask);
        $p->ip(0x41 + $i, $gw);
    }
    if ($lans) $p->ip(1, $lans[0][1], 0x08);
}

function is_action(Panel $p, array $rep): bool {
    if (count($rep) !== 9 || $rep[1] !== 0x80 || $p->wasSent($rep)) return false;  // echo / not from panel
    if (((255 - array_sum(array_slice($rep, 0, 8))) & 0xFF) !== $rep[8]) return false;  // bad checksum
    return in_array($rep[2], [1, 2, 4], true);
}

function is_idle(array $rep): bool {
    return array_sum(array_slice($rep, 2, 6)) === 0;   // 00 80 00 00 00 00 00 00 7f, or all zero
}

function handle(Panel $p, array $rep, array $cfg): bool {
    [$action, $arg] = [$rep[2], $rep[3]];
    logmsg("panel report: " . hexs($rep));
    if ($action === 1 && ($arg === 0 || $arg === 1)) {
        $cmd = $arg === 0 ? "/sbin/poweroff" : "/sbin/reboot";
        if (!$cfg["power"]) {
            logmsg("panel requested $cmd - ignored (power actions disabled)");
            return false;
        }
        logmsg("panel requested $cmd" . (DRY_RUN ? " (dry run)" : ""));
        if (!DRY_RUN) exec("nohup $cmd >/dev/null 2>&1 &");
        return true;
    }
    if ($action === 2) {
        logmsg("ignoring panel network change request");
    } elseif ($action === 4) {
        logmsg("ignoring panel USB " . ($arg === 0 ? "backup" : "eject") . " request (acknowledged)");
        $p->send(0x04, 1);
    }
    return false;
}

function open_panel(): Panel {
    $dev = find_hidraw();
    if ($dev === null) {
        logmsg("panel (USB 374a:b101) not found; waiting for it to appear");
        while (($dev = find_hidraw()) === null) sleep(5);
    }
    $p = new Panel($dev);
    try {
        $p->init();
    } catch (DeviceError $e) {
        $p->close();
        throw $e;
    }
    logmsg("initialised panel on $dev");
    return $p;
}

function main(array $argv): void {
    $i = array_search("--config", $argv, true);
    $cfg = load_config($i !== false && isset($argv[$i + 1]) ? $argv[$i + 1] : CFG_FILE);
    logmsg(sprintf("config: HDD=%s LAN=%s power_actions=%s refresh=%ds",
        implode(",", $cfg["hdd"]), $cfg["lan"] ? implode(",", $cfg["lan"]) : "auto",
        $cfg["power"] ? "yes" : "no", $cfg["refresh"]));
    if (!is_executable(HELPER)) { logmsg("helper " . HELPER . " missing or not executable"); exit(1); }

    $panel = open_panel();
    if (in_array("--test", $argv, true)) {
        $panel->temp(1, 42);
        $panel->capacity(1, 12345);
        $panel->ip(1, "10.20.30.40");
        $panel->ip(1, "10.20.30.40", 0x08);
        logmsg("sent test values: CPU 42C, total 12345 GB, IP 10.20.30.40");
        $panel->close();
        return;
    }

    $lastPush = 0;
    $lastRep = null;
    $lastError = null;
    while (true) {
        try {
            if (time() - $lastPush >= $cfg["refresh"]) {
                push_all($panel, $cfg);
                $lastPush = time();
            }
            $rep = $panel->read();
            if (is_action($panel, $rep)) {
                if ($rep !== $lastRep && handle($panel, $rep, $cfg)) break;
            } elseif (!$panel->wasSent($rep) && !is_idle($rep) && $rep !== $lastRep) {
                logmsg("unrecognised report: " . hexs($rep));
            }
            $lastRep = $rep;
        } catch (DeviceError $e) {
            logmsg("device error ({$e->getMessage()}); reconnecting");
            $panel->close();
            sleep(5);
            try {
                $panel = open_panel();
                $lastPush = 0;
            } catch (DeviceError $e2) {
                logmsg("reconnect failed ({$e2->getMessage()})");
            }
        } catch (Throwable $e) {                       // data-source problem - keep running
            if ($e->getMessage() !== $lastError) {
                logmsg("error: " . $e->getMessage());
                $lastError = $e->getMessage();
            }
            $lastPush = time();                        // don't retry the failing push every poll
        }
        usleep(POLL_US);
    }
    $panel->close();
}

main($argv);
