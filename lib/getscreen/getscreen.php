<?php
/**
 * getscreen [--wait MS] <bmc-host> [output.jpg]
 * Prints the output path and exits 0, or prints "ERROR: Screenshot capture failed for <host> (<reason>)" and exits 1.
 * Env: BMC_PASS (required); BMC_USER, or BMC_USER_CANDIDATES (default "admin root"); BMC_ADDRESS_PREFIX;
 * BMC_RENDER_WAIT; IDRAC6_JAVA (iDRAC6-era BMCs); BMC_DEBUG=1. Routes: lib/getscreen/consoleCapture.php.
 */

require __DIR__ . '/../bmcEnv.php';
require __DIR__ . '/consoleCapture.php';
require __DIR__ . '/transport.php';

umask(0077);
$usage = "Usage: getscreen [--wait MS] <bmc-host> [output.jpg]\n"
    . "  Env: BMC_PASS (required), BMC_USER or BMC_USER_CANDIDATES, BMC_RENDER_WAIT, IDRAC6_JAVA, BMC_DEBUG=1\n";
$args = array_slice($argv, 1);
$wait = 0;
while ($args !== [] && strncmp($args[0], '--', 2) === 0) {
    $a = array_shift($args);
    if ($a === '--wait') {
        $wait = (int) array_shift($args);
    } elseif (strncmp($a, '--wait=', 7) === 0) {
        $wait = (int) substr($a, 7);
    } else {
        fwrite(STDERR, "Unknown option: $a\n$usage");
        exit(1);
    }
}
$host = (string) ($args[0] ?? '');
if ($host === '' || BmcEnv::addressProblem($host, '', true) !== null) {
    fwrite(STDERR, $usage);
    exit(1);
}
$fail = static function (string $why) use ($host): void {
    fwrite(STDERR, "ERROR: Screenshot capture failed for $host ($why)\n");
    exit(1);
};
if (($why = BmcEnv::addressProblem($host, (string) getenv('BMC_ADDRESS_PREFIX'), true)) !== null) {
    $fail($why);
}
if ((string) getenv('BMC_PASS') === '') {
    $fail('BMC_PASS must be set; there is no default password');
}
$out = (string) ($args[1] ?? sys_get_temp_dir() . "/bmc-$host.jpg");
$capture = new ConsoleCapture('getscreenHttp', 'getscreenRun', static function (int $ms): void {
    usleep($ms * 1000);
}, dirname(__DIR__, 2));
[$ok, $detail] = $capture->capture($host, $out, $wait,
    BmcEnv::userCandidates((string) getenv('BMC_USER'), (string) getenv('BMC_USER_CANDIDATES')), (string) getenv('BMC_PASS'), getenv());
if (!$ok) {
    $fail($detail);
}
if (getenv('BMC_DEBUG') === '1') {
    fwrite(STDERR, "getscreen: $detail\n");
}
echo $out, "\n";
exit(0);
