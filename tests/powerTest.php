<?php
/**
 * Tests for `power` with no BMC: the argument and address rules, and the command line run against a fake
 * ipmitool on PATH (it records its arguments and whether the password arrived in IPMI_PASSWORD) and a fake
 * sshd that greets with a banner.
 * Run: php tests/powerTest.php
 */
$root = dirname(__DIR__);
require "$root/lib/power/powerLib.php";

$fails = 0;
$count = 0;
function check(string $name, bool $ok, string $why = ''): void
{
    global $fails, $count;
    $count++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $why === '' ? '' : "  -- $why") . "\n";
    $fails += $ok ? 0 : 1;
}

$o = PowerLib::parseArgs(['cycle', '10.0.0.5', '--confirm', '--reason', 'hung', '--host=192.0.2.9', '--host-up-ok']);
check('options parsed', $o['action'] === 'cycle' && $o['bmc'] === '10.0.0.5' && $o['confirm'] && $o['reason'] === 'hung'
    && $o['host'] === '192.0.2.9' && $o['hostUpOk']);
check('unknown action refused', isset(PowerLib::parseArgs(['explode', '10.0.0.5'])['error']));
check('unknown option refused', isset(PowerLib::parseArgs(['status', '10.0.0.5', '--force'])['error']));
check('missing address refused', isset(PowerLib::parseArgs(['status'])['error']));
check('host map derives the host', PowerLib::mapHost('10.20.3.4', '10.20.3.=192.0.2.') === '192.0.2.4');
check('host map ignores other prefixes', PowerLib::mapHost('10.21.3.4', '10.20.3.=192.0.2.') === '');
check('soft is destructive but not host-checked', in_array('soft', PowerLib::DESTRUCTIVE, true) && !in_array('soft', PowerLib::HOST_CHECKED, true));

$scratch = sys_get_temp_dir() . '/power-test-' . bin2hex(random_bytes(4));
mkdir("$scratch/bin", 0700, true);
file_put_contents("$scratch/bin/ipmitool", "#!/bin/sh\necho \"\$*\" >> \"$scratch/calls\"\n"
    . "[ -n \"\$IPMI_PASSWORD\" ] && echo pw-in-env >> \"$scratch/calls\"\n"
    . "case \"\$*\" in *'-U root '*'mc info'*) exit 0;; *'mc info'*) exit 1;; esac\necho 'Chassis Power is on'\nexit 0\n");
chmod("$scratch/bin/ipmitool", 0700);

function runPower(array $args, array $env, string $scratch): array
{
    @unlink("$scratch/calls");
    $base = ['PATH' => "$scratch/bin:" . getenv('PATH'), 'HOME' => $scratch, 'BMC_DEFAULTS_FILE' => "$scratch/none", 'BMC_LOG_DIR' => "$scratch/log"];
    $p = proc_open(array_merge([dirname(__DIR__) . '/power'], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, $env + $base);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out, (string) @file_get_contents("$scratch/calls")];
}
$pw = 'stub-' . bin2hex(random_bytes(4));

[$rc, $out] = runPower(['status', '10.0.0.5'], [], $scratch);
check('no BMC_PASS: exit 2', $rc === 2 && strpos($out, 'BMC_PASS must be set') !== false, "rc=$rc $out");
[$rc, $out, $calls] = runPower(['status', '10.0.0.5'], ['BMC_PASS' => $pw], $scratch);
check('status: admin refused, root accepted, status read', $rc === 0 && strpos($out, 'Chassis Power is on') !== false
    && strpos($calls, '-U admin') !== false && strpos($calls, '-U root -E chassis power status') !== false, "rc=$rc $out $calls");
check('password only in the environment, never an argument', strpos($calls, $pw) === false && strpos($calls, 'pw-in-env') !== false && strpos($calls, ' -P ') === false, $calls);
$log = (string) @file_get_contents("$scratch/log/bmc-actions/" . gmdate('Ymd') . '.jsonl');
check('audit log written as JSON lines', $log !== '' && json_decode(strtok($log, "\n"), true)['action'] === 'status', $log);
[$rc, $out] = runPower(['status', '10.0.0.5'], ['BMC_PASS' => $pw, 'BMC_ADDRESS_PREFIX' => '10.9.'], $scratch);
check('address outside BMC_ADDRESS_PREFIX: exit 2', $rc === 2 && strpos($out, 'must start with 10.9.') !== false, "rc=$rc $out");
[$rc, $out, $calls] = runPower(['cycle', '10.0.0.5'], ['BMC_PASS' => $pw], $scratch);
check('cycle without --confirm: exit 3, no ipmitool call', $rc === 3 && $calls === '', "rc=$rc $out $calls");
[$rc, $out] = runPower(['cycle', '10.0.0.5', '--confirm', '--host-up-ok'], ['BMC_PASS' => $pw], $scratch);
check('--host-up-ok without --reason: exit 1', $rc === 1 && strpos($out, 'needs --reason') !== false, "rc=$rc $out");

// A fake sshd that greets: the OS is running, so a hard cycle is refused.
file_put_contents("$scratch/sshd.php", '<?php $s = stream_socket_server("tcp://127.0.0.1:0"); echo stream_socket_get_name($s, false), "\n";'
    . ' while ($c = @stream_socket_accept($s, 30)) { fwrite($c, "SSH-2.0-stub\r\n"); fclose($c); }');
$sshd = proc_open([PHP_BINARY, "$scratch/sshd.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $sp);
$addr = trim((string) fgets($sp[1]));
[$rc, $out, $calls] = runPower(['cycle', '10.0.0.5', '--confirm', "--host=$addr"], ['BMC_PASS' => $pw], $scratch);
check('running host: cycle refused with exit 4, no power command', $rc === 4 && strpos($calls, 'chassis power cycle') === false
    && strpos($out, 'REFUSED') !== false, "rc=$rc $out $calls");
[$rc, $out, $calls] = runPower(['cycle', '10.0.0.5', '--confirm', "--host=$addr", '--host-up-ok', '--reason', 'kernel deadlock'], ['BMC_PASS' => $pw], $scratch);
check('running host with --host-up-ok and --reason: cycle runs', $rc === 0 && strpos($calls, 'chassis power cycle') !== false, "rc=$rc $out $calls");
[$rc, $out, $calls] = runPower(['soft', '10.0.0.5', '--confirm', "--host=$addr"], ['BMC_PASS' => $pw], $scratch);
check('soft is allowed on a running host (graceful)', $rc === 0 && strpos($calls, 'chassis power soft') !== false, "rc=$rc $out $calls");
proc_terminate($sshd);
proc_close($sshd);
[$rc, $out, $calls] = runPower(['cycle', '10.0.0.5', '--confirm', '--host=127.0.0.1:1'], ['BMC_PASS' => $pw], $scratch);
check('host not greeting: cycle runs', $rc === 0 && strpos($calls, 'chassis power cycle') !== false, "rc=$rc $out $calls");
[$rc, $out, $calls] = runPower(['cycle', '10.20.0.1', '--confirm'], ['BMC_PASS' => $pw, 'BMC_HOST_PREFIX_MAP' => '10.20.0.1=127.0.0.1:1'], $scratch);
check('host derived from BMC_HOST_PREFIX_MAP and probed', $rc === 0 && strpos($out, 'WARNING: no --host') === false, "rc=$rc $out");
[$rc, $out] = runPower(['cycle', '10.0.0.5', '--confirm'], ['BMC_PASS' => $pw], $scratch);
check('no host known: warns and proceeds as before', $rc === 0 && strpos($out, 'WARNING: no --host') !== false, "rc=$rc $out");
[$rc, $out, $calls] = runPower(['status', '10.0.0.5'], ['BMC_PASS' => $pw, 'BMC_USER' => 'admin'], $scratch);
check('pinned BMC_USER is the only user tried', $rc === 2 && strpos($calls, '-U root') === false, "rc=$rc $calls");

exec('rm -rf -- ' . escapeshellarg($scratch));
echo "$count checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
