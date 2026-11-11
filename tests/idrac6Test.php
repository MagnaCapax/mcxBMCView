<?php
/**
 * Tests for the idrac6 tool that need no BMC: the pure parsing and argv logic, the command-line
 * refusals, and the jar download over HTTP against a local stand-in server.
 * Run: php tests/idrac6Test.php   (exit 0 = all pass)
 */

$root = dirname(__DIR__);
require "$root/lib/idrac6/legacyWebSession.php";
require "$root/lib/idrac6/avocentViewer.php";

$fails = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $fails, $count;
    $count++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    $fails += $ok ? 0 : 1;
}

// Transport argv: legacy TLS flags, body on stdin, never a password.
$a = LegacyWebSession::curlArgv('10.0.0.5', '/data/login', '/w/c', '/w/b', true);
check('login posts the body from stdin', in_array('@-', $a, true) && in_array('POST', $a, true));
check('https with TLS 1.0 ceiling', end($a) === 'https://10.0.0.5/data/login' && in_array('--tls-max', $a, true));
check('no password-like argument', preg_grep('/password|passwd/i', $a) === []);
$g = LegacyWebSession::curlArgv('10.0.0.5', '/software/x.jar', '/w/c', '/w/b', false, false);
check('plain http GET for jars', end($g) === 'http://10.0.0.5/software/x.jar' && !in_array('POST', $g, true) && !in_array('--tlsv1', $g, true));

check('authResult 0 accepted', LegacyWebSession::loginAccepted('<root><status>ok</status><authResult>0</authResult></root>'));
check('authResult 1 refused', !LegacyWebSession::loginAccepted('<root><authResult>1</authResult></root>'));
check('authResult 10 refused', !LegacyWebSession::loginAccepted('<authResult>10</authResult>'));
check('pwState 1 = on', LegacyWebSession::powerState('<pwState>1</pwState>') === 'on');
check('pwState 0 = off', LegacyWebSession::powerState('<pwState>0</pwState>') === 'off');
check('no pwState = null', LegacyWebSession::powerState('<status>ok</status>') === null);

$jnlp = '<jnlp codebase="https://10.0.0.5:443/software"><application-desc main-class="com.avocent.idrac.kvm.Main">'
    . '<argument>ip=10.0.0.5</argument><argument>user=1234567</argument><argument>passwd=7654321</argument>'
    . '<argument>kmport=5900</argument><argument>apcp=1</argument></application-desc></jnlp>';
$t = AvocentViewer::completeTokens(AvocentViewer::parseJnlp($jnlp));
check('jnlp tokens parsed', $t !== null && $t['user'] === '1234567' && $t['passwd'] === '7654321');
check('main class from jnlp', $t !== null && $t['main'] === 'com.avocent.idrac.kvm.Main');
check('vport defaults to kmport', $t !== null && $t['vport'] === '5900' && $t['version'] === '2');
check('missing passwd refused', AvocentViewer::completeTokens(['user' => '1']) === null);
check('non-numeric port refused', AvocentViewer::completeTokens(['user' => '1', 'passwd' => '2', 'kmport' => '22;id']) === null);
check('foreign main class ignored', !isset(AvocentViewer::parseJnlp('<x main-class="org.evil.Main">')['main']));
check('default main class', AvocentViewer::completeTokens(['user' => '1', 'passwd' => '2'])['main'] === AvocentViewer::DEFAULT_MAIN);

check('html 404 is not a jar', !AvocentViewer::looksLikeJar(str_repeat('<html>404</html>', 1000)));
check('zip header of size is a jar', AvocentViewer::looksLikeJar("PK\x03\x04" . str_repeat('x', 20000)));
check('tiny zip is not a jar', !AvocentViewer::looksLikeJar("PK\x03\x04abc"));

$j = AvocentViewer::javaArgv('/opt/j/java', '10.0.0.5', $t, '/s');
check('java argv carries the main class and tokens', in_array('com.avocent.idrac.kvm.Main', $j, true) && in_array('kmport=5900', $j, true));
check('video up after heartbeats', AvocentViewer::videoState(str_repeat("HEARTBEAT\n", 4)) === 'up');
check('video failed on dropped session', AvocentViewer::videoState("HEARTBEAT\nconnection has been dropped") === 'failed');
check('video waiting', AvocentViewer::videoState("starting\n") === 'wait');

// Command line: refusals before any network.
function runCli(array $args, array $env): array
{
    $p = proc_open(array_merge(['php', dirname(__DIR__) . '/lib/idrac6/idrac6.php'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => getenv('PATH')]);
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $o];
}
[$rc] = runCli(['bad host!', 'auth-test'], ['BMC_USER' => 'u', 'BMC_PASS' => 'p']);
check('bad host is usage error', $rc === 2);
[$rc] = runCli(['10.0.0.5', 'reboot'], ['BMC_USER' => 'u', 'BMC_PASS' => 'p']);
check('unknown action is usage error', $rc === 2);
[$rc, $o] = runCli(['10.0.0.5', 'auth-test'], []);
check('missing credentials refused', $rc === 1 && strpos($o, 'BMC_USER and BMC_PASS') !== false);
[$rc, $o] = runCli(['10.0.0.5', 'screenshot', sys_get_temp_dir() . '/x.png'], ['BMC_USER' => 'u', 'BMC_PASS' => 'p']);
check('screenshot without IDRAC6_JAVA refused', $rc === 1 && strpos($o, 'IDRAC6_JAVA') !== false);

// Jar download and native library unpack over HTTP, against a local stand-in for the BMC.
$work = sys_get_temp_dir() . '/idrac6test.' . bin2hex(random_bytes(4));
mkdir("$work/www/software", 0700, true);
mkdir("$work/run", 0700);
$haveZip = trim((string) shell_exec('command -v zip')) !== '' && trim((string) shell_exec('command -v unzip')) !== '';
if (!$haveZip) {
    check('zip and unzip installed for the jar test', false);
} else {
    file_put_contents("$work/libavctKVMIO.so", str_repeat("\x7fELF", 4000));
    file_put_contents("$work/filler", random_bytes(20000));
    exec('cd ' . escapeshellarg($work) . ' && zip -q -0 www/software/avctKVMIOLinux.jar libavctKVMIO.so filler && zip -q -0 www/software/avctKVM.jar filler');
    $port = 20000 + random_int(0, 20000);
    $srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$work/www"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $sp);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    $web = new LegacyWebSession("127.0.0.1:$port", "$work/run");
    check('jars staged and native lib unpacked', AvocentViewer::stageJars($web, "$work/run") === null && is_file("$work/run/native/libavctKVMIO.so"));
    unlink("$work/www/software/avctKVM.jar");
    mkdir("$work/run2", 0700);
    $why = AvocentViewer::stageJars(new LegacyWebSession("127.0.0.1:$port", "$work/run2"), "$work/run2");
    check('a missing jar is reported', is_string($why) && strpos($why, 'avctKVM.jar') !== false);
    proc_terminate($srv);
    proc_close($srv);
}
exec('rm -rf -- ' . escapeshellarg($work));

echo "$count checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
