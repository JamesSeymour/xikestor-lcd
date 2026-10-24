<?php
// Unit checks for the daemon's data-source functions, against fixtures in this directory.
eval('?>' . str_replace('main($argv);', '', file_get_contents(__DIR__ . '/../src/xk_lcd.php')));
$fails = 0;
function expect($name, $got, $want) {
    global $fails;
    if ($got !== $want) { $fails++; fwrite(STDERR, "    $name: got " . json_encode($got) . ", want " . json_encode($want) . "\n"); }
}
$d = parse_ini_file(__DIR__ . '/fixtures/disks.ini', true);
expect('parity temp',       disk_temp($d, 'parity'), 34);
expect('spun-down disk = 0', disk_temp($d, 'disk1'), 0);
expect('empty slot = 0',    disk_temp($d, '-'), 0);
expect('capacity GB',       array_capacity_gb($d), [22351, 12815, 9536]);
expect('frame checksum',    frame(0x88, 0), [0, 0, 0x88, 0, 0, 0, 0, 0, 0x77]);
$c = load_config(__DIR__ . '/fixtures/custom.cfg');
expect('cfg hdd',   $c['hdd'], ['disk1', '-', 'cache']);
expect('cfg lan',   $c['lan'], ['br0', 'eth1']);
expect('cfg power', $c['power'], false);
expect('cfg refresh floor', $c['refresh'], 2);
expect('defaults when missing', load_config('/nonexistent')['hdd'], ['parity', 'disk1', 'disk2', 'cache']);
exit($fails ? 1 : 0);
