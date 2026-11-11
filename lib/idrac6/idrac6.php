<?php
/**
 * idrac6 — console screenshot and read-only checks for iDRAC6-era BMCs (Dell iDRAC6 and the
 * Avocent-based boards that share its firmware; the web server reports Mbedthis-Appweb).
 *
 *   idrac6 <bmc> auth-test               log in and out; exit 0 when the credentials work
 *   idrac6 <bmc> power-status            print "on" or "off"
 *   idrac6 <bmc> console-check           every console step except starting Java: login, the one-time
 *                                        tokens in viewer.jnlp, and the viewer jars the BMC serves
 *   idrac6 <bmc> screenshot <out.png>    capture the console; prints the path
 *
 * Credentials: BMC_USER and BMC_PASS from the environment, no defaults. screenshot also needs
 * IDRAC6_JAVA, the path of a 32-bit Java 8 `java` (see the README). BMC_DEBUG=1 prints progress.
 * Exit: 0 ok, 1 failed, 2 usage.
 */

require __DIR__ . '/legacyWebSession.php';
require __DIR__ . '/avocentViewer.php';
require __DIR__ . '/consoleShot.php';

function idrac6Usage(string $why): void
{
    fwrite(STDERR, "idrac6: $why\nusage: idrac6 <bmc-host> auth-test|power-status|console-check|screenshot <out.png>\n");
    exit(2);
}

function idrac6Debug(string $line): void
{
    if (getenv('BMC_DEBUG') === '1') {
        fwrite(STDERR, "idrac6: $line\n");
    }
}

/** A private 0700 work directory, removed with everything in it when the process exits. */
function idrac6WorkDir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '/') . '/idrac6.' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700)) {
        fwrite(STDERR, "idrac6: cannot create a work directory in " . sys_get_temp_dir() . "\n");
        exit(1);
    }
    register_shutdown_function(static function () use ($dir): void {
        exec('rm -rf -- ' . escapeshellarg($dir));
    });
    return $dir;
}

[, $host, $action] = array_pad($argv, 3, '');
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $host) !== 1) {
    idrac6Usage('the BMC must be a host name or IPv4 address');
}
if (!in_array($action, ['auth-test', 'power-status', 'console-check', 'screenshot'], true)) {
    idrac6Usage('unknown action');
}
$out = (string) ($argv[3] ?? '');
$java = (string) getenv('IDRAC6_JAVA');
if ($action === 'screenshot') {
    if ($out === '' || !is_dir(dirname($out))) {
        idrac6Usage('screenshot needs an output file in an existing directory');
    }
    if ($java === '' || !is_executable($java)) {
        fwrite(STDERR, "idrac6: set IDRAC6_JAVA to a 32-bit Java 8 java binary (the viewer's native library is i386)\n");
        exit(1);
    }
}
$user = (string) getenv('BMC_USER');
$pass = (string) getenv('BMC_PASS');
if ($user === '' || $pass === '') {
    fwrite(STDERR, "idrac6: BMC_USER and BMC_PASS must be set\n");
    exit(1);
}

$dir = idrac6WorkDir();
$web = new LegacyWebSession($host, $dir);
if (!$web->login($user, $pass)) {
    fwrite(STDERR, "idrac6: login refused or no answer from $host (wrong credentials, all web sessions in use, or not an iDRAC6-era BMC)\n");
    exit(1);
}
idrac6Debug('logged in');

if ($action === 'auth-test') {
    $web->close();
    echo "ok\n";
    exit(0);
}
if ($action === 'power-status') {
    [$status, $body] = $web->request('/data?get=pwState');
    $web->close();
    $state = LegacyWebSession::powerState($body);
    if ($state === null) {
        fwrite(STDERR, "idrac6: no power state in the reply (http $status)\n");
        exit(1);
    }
    echo "$state\n";
    exit(0);
}

// console-check and screenshot: the one-time tokens, then the web session is no longer needed.
[$status, $jnlp] = $web->request('/viewer.jnlp');
$web->close();
$tokens = AvocentViewer::completeTokens(AvocentViewer::parseJnlp($jnlp));
if ($tokens === null) {
    fwrite(STDERR, "idrac6: viewer.jnlp had no console tokens (http $status)\n");
    exit(1);
}
idrac6Debug("tokens ok, main class {$tokens['main']}, port {$tokens['kmport']}");
$why = AvocentViewer::stageJars($web, $dir);
if ($why !== null) {
    fwrite(STDERR, "idrac6: $why\n");
    exit(1);
}
if ($action === 'console-check') {
    echo "ok: tokens, viewer jars and native library are all available; main class {$tokens['main']}\n";
    exit(0);
}
@unlink($out);
$why = ConsoleShot::capture($java, $host, $tokens, $dir, $out);
if ($why !== null) {
    @unlink($out);
    fwrite(STDERR, "idrac6: $why\n");
    exit(1);
}
echo "$out\n";
exit(0);
