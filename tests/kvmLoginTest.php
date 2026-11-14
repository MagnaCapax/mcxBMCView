<?php
/**
 * Tests for BMC_PASS_PREVIOUS in sendkeys, enterbios, flashbios (lib/kvmLogin.php), their Node scripts' login-rejected
 * exit code, and idrac6, with no BMC. A fake `node` on PATH records its arguments and environment and answers like a
 * script: exit 3 when BMC_PASS is not the password it was told is good, 0 otherwise. A stand-in `playwright` module
 * (found through NODE_PATH) plays the login page, so the real scripts run up to and past their login check.
 * Run: php tests/kvmLoginTest.php   (needs node)
 */
$root = dirname(__DIR__);
require "$root/lib/idrac6/legacyWebSession.php";

$fails = 0;
$count = 0;
function check(string $name, bool $ok, string $why = ''): void
{
    global $fails, $count;
    $count++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $why === '' ? '' : "  -- $why") . "\n";
    $fails += $ok ? 0 : 1;
}
$realNode = trim((string) shell_exec('command -v node'));
if ($realNode === '') {
    echo "FAIL prerequisites: node\n";
    exit(1);
}

$scratch = sys_get_temp_dir() . '/kvmlogin-test-' . bin2hex(random_bytes(4));
mkdir("$scratch/bin", 0700, true);
mkdir("$scratch/node_modules/playwright", 0700, true);
file_put_contents("$scratch/rom.bin", 'rom');
file_put_contents("$scratch/bin/node", <<<'PHP'
#!/usr/bin/env php
<?php
$d = getenv('STUB_DIR');
file_put_contents("$d/calls", json_encode(['argv' => array_slice($argv, 1), 'pass' => (string) getenv('BMC_PASS'),
    'prevInEnv' => getenv('BMC_PASS_PREVIOUS') !== false]) . "\n", FILE_APPEND);
fwrite(STDOUT, "node-out\n");
fwrite(STDERR, "node-err\n");
if (getenv('STUB_MODE') === 'exit7') {
    exit(7);
}
exit(getenv('BMC_PASS') === getenv('STUB_GOOD') ? 0 : 3);
PHP);
chmod("$scratch/bin/node", 0700);
file_put_contents("$scratch/node_modules/playwright/index.js", <<<'JS'
const mode = process.env.STUB_LOGIN || 'accept';
const noop = async () => undefined;
const viewer = { waitForLoadState: noop, $eval: async () => 'Stop', click: noop, waitForTimeout: noop, keyboard: { press: noop }, evaluate: noop, close: noop };
const page = {
    goto: noop, waitForSelector: noop, fill: noop, press: noop, waitForTimeout: noop, screenshot: noop, on: () => {},
    // 'seeded': the login page seeded a CSRF token but the login form is still there (a rejected login on AMI MegaRAC)
    isVisible: async () => mode === 'seeded',
    evaluate: async (fn) => (String(fn).includes("getItem('garc')") ? (mode === 'nogarc' ? null : 'tok-123456') : undefined),
};
const context = { newPage: async () => page, waitForEvent: async () => viewer };
module.exports = { chromium: { launch: async () => ({ newContext: async () => context, close: noop }) } };
JS);

/** [exit code, stdout+stderr, recorded fake-node calls] of one command with $env on top of a fixed base. */
function run(array $cmd, array $env, string $scratch): array
{
    @unlink("$scratch/calls");
    $base = ['PATH' => "$scratch/bin:" . getenv('PATH'), 'HOME' => $scratch, 'BMC_DEFAULTS_FILE' => "$scratch/none",
        'BMC_USER' => 'admin', 'STUB_DIR' => $scratch, 'NODE_PATH' => "$scratch/node_modules"];
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + $base);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $calls = array_map(static function (string $l): array {
        return json_decode($l, true);
    }, array_filter(explode("\n", (string) @file_get_contents("$scratch/calls")), 'strlen'));
    return [proc_close($p), $out, array_values($calls)];
}

$good = 'good-' . bin2hex(random_bytes(4));
$bad = 'bad-' . bin2hex(random_bytes(4));
$tools = [
    'sendkeys' => [["$root/sendkeys", '10.0.0.5', '{Enter}'], 'kvm-sendkeys.js'],
    'enterbios' => [["$root/enterbios", '--timeout', '5', '10.0.0.5'], 'kvm-enterbios.js'],
    'flashbios' => [["$root/flashbios", '10.0.0.5', "$scratch/rom.bin"], 'bios-flash.js'],
];
foreach ($tools as $name => [$cmd, $script]) {
    $passes = static function (array $calls): array {
        return array_column($calls, 'pass');
    };
    $leak = static function (string $out, array $calls) use ($good, $bad): bool {
        $all = $out . json_encode(array_column($calls, 'argv'));
        return strpos($all, $good) !== false || strpos($all, $bad) !== false;
    };

    [$rc, $out, $calls] = run($cmd, ['BMC_PASS' => $good, 'STUB_GOOD' => $good], $scratch);
    check("$name: BMC_PASS accepted -> one attempt, exit 0", $rc === 0 && $passes($calls) === [$good]
        && strpos($out, 'accepted BMC_PASS_PREVIOUS') === false, "rc=$rc $out");
    check("$name: runs $script with host, user and an empty password argument",
        isset($calls[0]) && substr($calls[0]['argv'][0], -strlen($script)) === $script && $calls[0]['argv'][1] === '10.0.0.5'
        && $calls[0]['argv'][2] === 'admin' && $calls[0]['argv'][3] === '', json_encode($calls));

    [$rc, $out, $calls] = run($cmd, ['BMC_PASS' => $bad, 'BMC_PASS_PREVIOUS' => $good, 'STUB_GOOD' => $good], $scratch);
    check("$name: BMC_PASS rejected, BMC_PASS_PREVIOUS accepted -> two attempts in order, exit 0, warning",
        $rc === 0 && $passes($calls) === [$bad, $good] && strpos($out, 'accepted BMC_PASS_PREVIOUS, not BMC_PASS') !== false, "rc=$rc $out " . json_encode($calls));
    check("$name: the previous password never reaches the script's environment", $calls !== [] && array_sum(array_column($calls, 'prevInEnv')) === 0);
    check("$name: no password in any script argument or in the output", !$leak($out, $calls));

    [$rc, $out, $calls] = run($cmd, ['BMC_PASS' => $bad, 'BMC_PASS_PREVIOUS' => $bad . 'x', 'STUB_GOOD' => $good], $scratch);
    check("$name: both rejected -> two attempts, exit 1, says so", $rc === 1 && count($calls) === 2
        && strpos($out, 'rejected the login with BMC_PASS and BMC_PASS_PREVIOUS') !== false && !$leak($out, $calls), "rc=$rc $out");

    [$rc, $out, $calls] = run($cmd, ['BMC_PASS' => $bad, 'BMC_PASS_PREVIOUS' => $good, 'STUB_GOOD' => $good, 'STUB_MODE' => 'exit7'], $scratch);
    check("$name: a failure that is not a rejected login ends the run, never tried twice", $rc === 7 && count($calls) === 1, "rc=$rc " . json_encode($calls));

    [$rc, $out, $calls] = run($cmd, ['BMC_PASS' => $bad, 'STUB_GOOD' => $good], $scratch);
    check("$name: no BMC_PASS_PREVIOUS -> one attempt, exit 1", $rc === 1 && count($calls) === 1, "rc=$rc " . json_encode($calls));
    [$rc, , $calls] = run($cmd, ['BMC_PASS' => $bad, 'BMC_PASS_PREVIOUS' => $bad, 'STUB_GOOD' => $good], $scratch);
    check("$name: BMC_PASS_PREVIOUS equal to BMC_PASS -> one attempt", count($calls) === 1);

    // Both passwords from the defaults file, as in a rollout.
    $file = "$scratch/defaults-$name";
    file_put_contents($file, "BMC_PASS=$bad\nBMC_PASS_PREVIOUS=$good\n");
    chmod($file, 0600);
    [$rc, $out, $calls] = run($cmd, ['BMC_DEFAULTS_FILE' => $file, 'STUB_GOOD' => $good], $scratch);
    check("$name: both passwords from the defaults file", $rc === 0 && $passes($calls) === [$bad, $good], "rc=$rc $out " . json_encode($calls));
}

// Stderr: the wrappers that always hid the script's stderr still do, unless BMC_DEBUG is set; enterbios never hid it.
[, $out] = run(["$root/sendkeys", '10.0.0.5', '{Enter}'], ['BMC_PASS' => $good, 'STUB_GOOD' => $good], $scratch);
check('sendkeys: script stderr hidden without BMC_DEBUG', strpos($out, 'node-err') === false && strpos($out, 'node-out') !== false, $out);
[, $out] = run(["$root/sendkeys", '10.0.0.5', '{Enter}'], ['BMC_PASS' => $good, 'STUB_GOOD' => $good, 'BMC_DEBUG' => '1'], $scratch);
check('sendkeys: script stderr shown with BMC_DEBUG', strpos($out, 'node-err') !== false, $out);
[, $out] = run(["$root/flashbios", '10.0.0.5', "$scratch/rom.bin"], ['BMC_PASS' => $good, 'STUB_GOOD' => $good], $scratch);
check('flashbios: script stderr hidden without BMC_DEBUG', strpos($out, 'node-err') === false, $out);
[, $out] = run(["$root/enterbios", '--timeout', '5', '10.0.0.5'], ['BMC_PASS' => $good, 'STUB_GOOD' => $good], $scratch);
check('enterbios: script stderr not hidden', strpos($out, 'node-err') !== false, $out);

// The real scripts against a stand-in playwright: a login that only SEEDED a CSRF token, or gave none, exits 3
// before anything is done; a real login goes on (sendkeys: a {Delay} only, exit 0).
$js = [
    'kvm-sendkeys.js' => ['10.0.0.5', 'admin', '', '{Delay:1}'],
    'kvm-enterbios.js' => ['10.0.0.5', 'admin', '', '5'],
    'bios-flash.js' => ['10.0.0.5', 'admin', '', "$scratch/rom.bin"],
];
foreach ($js as $script => $args) {
    foreach (['seeded' => 'token seeded, login form still shown', 'nogarc' => 'no token'] as $mode => $label) {
        [$rc, $out] = run(array_merge([$realNode, "$root/$script"], $args), ['BMC_PASS' => $good, 'STUB_LOGIN' => $mode, 'BMC_DEBUG' => '1'], $scratch);
        check("$script: login rejected ($label) -> exit 3", $rc === 3 && strpos($out, 'Login failed') !== false && strpos($out, $good) === false, "rc=$rc $out");
    }
}
[$rc, $out] = run(array_merge([$realNode, "$root/kvm-sendkeys.js"], $js['kvm-sendkeys.js']), ['BMC_PASS' => $good, 'STUB_LOGIN' => 'accept'], $scratch);
check('kvm-sendkeys.js: a real login goes on and exits 0', $rc === 0, "rc=$rc $out");

// idrac6: the first accepted password wins, and the later ones are not tried.
check('idrac6: LegacyWebSession::firstAccepted exists', method_exists('LegacyWebSession', 'firstAccepted'));
if (method_exists('LegacyWebSession', 'firstAccepted')) {
    $tried = [];
    $try = static function (array $accepts) use (&$tried): callable {
        return static function (string $p) use ($accepts, &$tried): bool {
            $tried[] = $p;
            return in_array($p, $accepts, true);
        };
    };
    $tried = [];
    check('firstAccepted: BMC_PASS accepted -> index 0, previous never tried',
        LegacyWebSession::firstAccepted([$good, $bad], $try([$good, $bad])) === 0 && $tried === [$good]);
    $tried = [];
    check('firstAccepted: only the previous accepted -> index 1, both tried in order',
        LegacyWebSession::firstAccepted([$bad, $good], $try([$good])) === 1 && $tried === [$bad, $good]);
    $tried = [];
    check('firstAccepted: none accepted -> null', LegacyWebSession::firstAccepted([$bad, $bad . 'x'], $try([])) === null && count($tried) === 2);
    check('firstAccepted: no passwords -> null', LegacyWebSession::firstAccepted([], $try([$good])) === null);
}
// The tool itself, against a closed local port: it loads (bmcEnv.php is found) and reports a refused login.
[$rc, $out] = run(["$root/idrac6", '127.0.0.1', 'auth-test'], ['BMC_PASS' => $bad, 'BMC_PASS_PREVIOUS' => $good], $scratch);
check('idrac6: loads and reports a login that no password got through', $rc === 1 && strpos($out, 'login refused or no answer') !== false
    && strpos($out, $good) === false && strpos($out, $bad) === false, "rc=$rc $out");

// Clean up: only this test's own scratch tree, named by its literal prefix.
if (strpos($scratch, sys_get_temp_dir() . '/kvmlogin-test-') === 0) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($scratch);
}
echo $fails === 0 ? "ALL PASS ($count checks)\n" : "$fails of $count FAILED\n";
exit($fails === 0 ? 0 : 1);
