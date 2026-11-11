<?php
/**
 * Tests for getscreen's vendor routing (lib/getscreen/consoleCapture.php) with no real BMC. A `php -S` stub on
 * 127.0.0.1 plays a Supermicro ATEN web UI; the test's HTTP transport maps https:// to http:// for that stub only,
 * so the real curl transport and the real ImageMagick `convert` run. The other routes use recording runners.
 * Run: php tests/getscreenTest.php   (needs php-curl and ImageMagick)
 */
$root = dirname(__DIR__);
require "$root/lib/bmcEnv.php";
require "$root/lib/getscreen/consoleCapture.php";
require "$root/lib/getscreen/transport.php";
require_once "$root/tests/lib/stub.php";

$fails = 0;
$count = 0;
function check(string $name, bool $ok, string $why = ''): void
{
    global $fails, $count;
    $count++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $why === '' ? '' : "  -- $why") . "\n";
    $fails += $ok ? 0 : 1;
}
if (trim((string) shell_exec('command -v convert')) === '' || !function_exists('curl_init')) {
    echo "FAIL prerequisites: ImageMagick convert and php-curl\n";
    exit(1);
}

$scratch = sys_get_temp_dir() . '/getscreen-test-' . bin2hex(random_bytes(4));
mkdir($scratch, 0700);
$pass = 'stub-' . bin2hex(random_bytes(4));
$none = static function (int $ms): void {
};

// Users and addresses
check('no BMC_USER: admin then root', BmcEnv::userCandidates('', '') === ['admin', 'root']);
check('BMC_USER pinned: only that user', BmcEnv::userCandidates('root', 'admin root') === ['root']);
check('BMC_USER_CANDIDATES order kept', BmcEnv::userCandidates('', 'root  admin') === ['root', 'admin']);
check('shell metacharacters refused', BmcEnv::addressProblem('10.0.0.1;id', '', true) !== null && BmcEnv::addressProblem('-o', '', true) !== null);
check('host:port allowed for getscreen', BmcEnv::addressProblem('127.0.0.1:8080', '', true) === null);
check('address prefix enforced', BmcEnv::addressProblem('192.0.2.1', '10.1.', false) !== null && BmcEnv::addressProblem('10.1.2.3', '10.1.', false) === null);

// ATEN route against the stub
$router = stubAtenRouter($scratch);
$toStub = static function (string $m, string $u, string $b, string $c): array {
    return getscreenHttp($m, str_replace('https://', 'http://', $u), $b, $c);
};
$cap = new ConsoleCapture($toStub, 'getscreenRun', $none, $root);
[$p, $host] = stubStart($router, "$scratch/s1", ['STUB_USER' => 'stubuser', 'STUB_PASS' => $pass]);
umask(0077);
[$ok, $detail] = $cap->capture($host, "$scratch/aten.jpg", 0, ['stubuser'], $pass, []);
stubStop($p);
$hits = (string) @file_get_contents("$scratch/s1/hits");
check('ATEN: capture succeeds', $ok && $detail === 'aten user=stubuser', $detail);
check('ATEN: output is a JPEG, owner-only', is_file("$scratch/aten.jpg") && substr((string) file_get_contents("$scratch/aten.jpg"), 0, 2) === "\xFF\xD8"
    && (fileperms("$scratch/aten.jpg") & 0777) === 0600);
check('ATEN: polled until the image was ready, then logged out', substr_count($hits, 'snapshot') === 2 && strpos($hits, 'logout cookie=stubsid42') !== false, $hits);

[$p, $host] = stubStart($router, "$scratch/s2", ['STUB_USER' => 'stubuser', 'STUB_PASS' => 'other']);
[$ok, $detail] = $cap->capture($host, "$scratch/wrong.jpg", 0, ['admin', 'stubuser'], $pass, []);
stubStop($p);
$hits = (string) @file_get_contents("$scratch/s2/hits");
check('ATEN wrong password: every user tried, refused, no file', !$ok && strpos($detail, 'login refused') !== false
    && substr_count($hits, 'login') === 2 && !is_file("$scratch/wrong.jpg"), "$detail / $hits");

[$p, $host] = stubStart($router, "$scratch/s3", ['STUB_USER' => 'stubuser', 'STUB_PASS' => $pass, 'STUB_NEVER_READY' => '1']);
[$ok, $detail] = $cap->capture($host, "$scratch/never.jpg", 0, ['stubuser'], $pass, []);
stubStop($p);
$hits = (string) @file_get_contents("$scratch/s3/hits");
check('ATEN image never ready: fails after 6 polls and still logs out', !$ok && substr_count($hits, 'snapshot') === 6 && strpos($hits, 'logout') !== false, $hits);

[$p, $host] = stubStart($router, "$scratch/s4", ['STUB_GZIP' => '1']);
check('gzip-compressed ATEN web root still detected', $cap->vendor($host) === 'aten');
stubStop($p);

// AMI route: password in the environment only
[$p, $host] = stubStart($router, "$scratch/s5", ['STUB_VENDOR' => 'ami']);
$seen = [];
$ami = new ConsoleCapture($toStub, static function (array $argv, array $env) use (&$seen): int {
    $seen = [$argv, $env];
    file_put_contents(end($argv), 'jpg');
    return 0;
}, $none, $root);
[$ok, $detail] = $ami->capture($host, "$scratch/ami.jpg", 7000, ['admin'], $pass, ['NODE_PATH' => '/n']);
stubStop($p);
check('AMI: kvm-capture.js with an empty password argument', $ok && $detail === 'ami user=admin' && ($seen[0][0] ?? '') === 'node'
    && substr($seen[0][1] ?? '', -15) === '/kvm-capture.js' && ($seen[0][4] ?? 'x') === '', json_encode($seen[0] ?? null));
check('AMI: password only in the environment', ($seen[1]['BMC_PASS'] ?? '') === $pass && !in_array($pass, $seen[0] ?? [], true));
check('AMI: --wait and NODE_PATH passed on', ($seen[1]['BMC_RENDER_WAIT'] ?? '') === '7000' && ($seen[1]['NODE_PATH'] ?? '') === '/n');
check('kvm-capture.js reads BMC_PASS from the environment', strpos((string) file_get_contents("$root/kvm-capture.js"), 'process.env.BMC_PASS') !== false);

$dead = new ConsoleCapture(static function (): array {
    return [0, '', ''];
}, static function (): int {
    return 1;
}, $none, $root);
check('unreachable web root: ami', $dead->vendor('192.0.2.254') === 'ami');

// Old AMI firmware with only the Java JViewer console
$goahead = static function (bool $https): callable {
    return static function (string $m, string $u) use ($https): array {
        if (strncmp($u, 'https://', 8) === 0) {
            return $https ? [302, "HTTP/1.0 302\r\nServer: GoAhead-Webs\r\n", ''] : [0, '', ''];
        }
        return [302, "HTTP/1.0 302 Redirect\r\nServer: GoAhead-Webs\r\nLocation: /login.asp\r\n", ''];
    };
};
$calls = [];
$record = static function (array $argv) use (&$calls): int {
    $calls[] = $argv;
    return 1;
};
check('http-only BMC: jviewer', (new ConsoleCapture($goahead(false), $record, $none, $root))->vendor('192.0.2.5') === 'jviewer');
check('Server: GoAhead-Webs over https: jviewer', (new ConsoleCapture($goahead(true), $record, $none, $root))->vendor('192.0.2.5') === 'jviewer');
[$ok, $detail] = (new ConsoleCapture($goahead(false), $record, $none, $root))->capture('192.0.2.5', "$scratch/jv.jpg", 0, ['admin'], $pass, []);
check('jviewer: only the HTML5 capture is tried, never Java', !$ok && $calls !== [] && array_unique(array_map(static function (array $a): string {
    return basename($a[1]);
}, $calls)) === ['kvm-capture.js'], json_encode($calls));
check('jviewer: the failure says why', strpos($detail, 'Java JViewer') !== false, $detail);
file_put_contents("$scratch/stale.jpg", 'an older capture');
[$ok] = (new ConsoleCapture($goahead(false), static function (array $argv): int {
    file_put_contents(end($argv), 'blank page');
    return 1;
}, $none, $root))->capture('192.0.2.5', "$scratch/stale.jpg", 0, ['admin'], $pass, []);
check('a file left by a failed attempt is never accepted', !$ok && !file_exists("$scratch/stale.jpg"));

// iDRAC6-era BMC
$http6 = [];
$mbed = static function (string $m, string $u) use (&$http6): array {
    $http6[] = "$m $u";
    return strncmp($u, 'https://', 8) === 0 ? [0, '', ''] : [301, "HTTP/1.1 301 Moved\r\nServer: Mbedthis-Appweb/2.4.2\r\n", ''];
};
$calls = [];
$cap6 = new ConsoleCapture($mbed, $record, $none, $root);
check('Server: Mbedthis-Appweb: idrac6', $cap6->vendor('192.0.2.6') === 'idrac6');
$http6 = [];
[$ok, $detail] = $cap6->capture('192.0.2.6', "$scratch/i6.jpg", 0, ['root'], $pass, []);
check('idrac6 without IDRAC6_JAVA: refused with a reason, no program, no login', !$ok && $calls === [] && strpos($detail, 'IDRAC6_JAVA') !== false
    && preg_grep('/^POST /', $http6) === [], $detail);
$seen = [];
[$ok, $detail] = (new ConsoleCapture($mbed, static function (array $argv, array $env) use (&$seen): int {
    $seen = [$argv, $env];
    file_put_contents(end($argv), 'png');
    return 0;
}, $none, $root))->capture('192.0.2.6', "$scratch/i6ok.png", 0, ['root'], $pass, ['IDRAC6_JAVA' => '/opt/j/java']);
check('idrac6 with IDRAC6_JAVA: runs idrac6 screenshot with the user and password in the environment', $ok && $detail === 'idrac6 user=root'
    && ($seen[0][2] ?? '') === '192.0.2.6' && ($seen[0][3] ?? '') === 'screenshot' && ($seen[1]['BMC_USER'] ?? '') === 'root'
    && ($seen[1]['BMC_PASS'] ?? '') === $pass && ($seen[1]['IDRAC6_JAVA'] ?? '') === '/opt/j/java' && !in_array($pass, $seen[0], true), json_encode($seen));

// Command line, through the wrapper (no defaults file: BMC_DEFAULTS_FILE points nowhere)
function runCli(array $args, array $env): array
{
    $p = proc_open(array_merge([dirname(__DIR__) . '/getscreen'], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $o = stream_get_contents($pipes[1]);
    $e = stream_get_contents($pipes[2]);
    return [proc_close($p), $o, $e];
}
$base = ['PATH' => getenv('PATH'), 'HOME' => $scratch, 'BMC_DEFAULTS_FILE' => "$scratch/none"];
[$rc, $o, $e] = runCli(['192.0.2.1', "$scratch/cli.jpg"], $base);
check('CLI: no BMC_PASS fails closed', $rc === 1 && $o === '' && strpos($e, 'BMC_PASS must be set') !== false, "rc=$rc $e");
[$rc, , $e] = runCli(['10.0.0.1;id'], $base + ['BMC_PASS' => 'x']);
check('CLI: bad host prints usage', $rc === 1 && strpos($e, 'Usage: getscreen') !== false, "rc=$rc $e");
[$rc, , $e] = runCli(['--bogus', '10.0.0.1'], $base + ['BMC_PASS' => 'x']);
check('CLI: unknown option', $rc === 1 && strpos($e, 'Unknown option: --bogus') !== false, "rc=$rc $e");
[$rc, , $e] = runCli(['192.0.2.1', "$scratch/p.jpg"], $base + ['BMC_PASS' => 'x', 'BMC_ADDRESS_PREFIX' => '10.9.']);
check('CLI: address outside BMC_ADDRESS_PREFIX refused before any request', $rc === 1 && strpos($e, 'must start with 10.9.') !== false, "rc=$rc $e");
file_put_contents("$scratch/defaults", "BMC_PASS=fromfile\nBMC_ADDRESS_PREFIX=10.9.\nEVIL=1\n");
chmod("$scratch/defaults", 0600);
[$rc, , $e] = runCli(['192.0.2.1', "$scratch/d.jpg"], ['BMC_DEFAULTS_FILE' => "$scratch/defaults"] + $base);
check('CLI: the defaults file fills BMC_PASS and BMC_ADDRESS_PREFIX', $rc === 1 && strpos($e, 'must start with 10.9.') !== false, "rc=$rc $e");
[$rc, , $e] = runCli(['192.0.2.1', "$scratch/d.jpg"], ['BMC_DEFAULTS_FILE' => "$scratch/defaults", 'BMC_ADDRESS_PREFIX' => '192.'] + $base);
check('CLI: the environment wins over the defaults file', $rc === 1 && strpos($e, 'must start with') === false, "rc=$rc $e");
chmod("$scratch/defaults", 0666);
[$rc, , $e] = runCli(['192.0.2.1', "$scratch/d.jpg"], ['BMC_DEFAULTS_FILE' => "$scratch/defaults"] + $base);
check('CLI: a world-writable defaults file is ignored', $rc === 1 && strpos($e, 'BMC_PASS must be set') !== false, "rc=$rc $e");

exec('rm -rf -- ' . escapeshellarg($scratch));
echo "$count checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
