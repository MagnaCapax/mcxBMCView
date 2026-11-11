<?php
/**
 * power — ipmitool chassis power with a confirm gate, a running-host check and a JSON Lines audit log.
 * The command-line interface is the `power` wrapper; `power --help` describes it. Exit codes:
 * 0 ok, 1 usage, 2 bad address / no password / no user authenticated, 3 destructive without --confirm,
 * 4 refused because the host's OS is running, otherwise ipmitool's own exit code.
 */

require __DIR__ . '/powerLib.php';

$opt = PowerLib::parseArgs(array_slice($argv, 1));
if (isset($opt['help'])) {
    echo PowerLib::USAGE;
    exit(0);
}
if (isset($opt['error'])) {
    fwrite(STDERR, "power: {$opt['error']}\n" . PowerLib::USAGE);
    exit(1);
}
$env = static function (string $k): string {
    return (string) getenv($k);
};
if (($why = BmcEnv::addressProblem($opt['bmc'], $env('BMC_ADDRESS_PREFIX'))) !== null) {
    fwrite(STDERR, "power: $why\n");
    exit(2);
}
if ($env('BMC_PASS') === '') {
    fwrite(STDERR, "power: BMC_PASS must be set; there is no default password\n");
    exit(2);
}

$log = new PowerLog($env('BMC_LOG_DIR') !== '' ? $env('BMC_LOG_DIR') : $env('HOME') . '/.local/state/mcxbmcview',
    $env('SESSION_ID') !== '' ? $env('SESSION_ID') : 'interactive-' . ($env('USER') !== '' ? $env('USER') : 'unknown'),
    $opt['action'], $opt['bmc'], $opt['reason'], PowerLib::pingable($opt['bmc']) ? 'yes' : 'unknown');
$commands = PowerLib::COMMANDS[$opt['action']];
$users = BmcEnv::userCandidates($env('BMC_USER'), $env('BMC_USER_CANDIDATES'));

if (in_array($opt['action'], PowerLib::DESTRUCTIVE, true)) {
    if (!$opt['confirm']) {
        fwrite(STDERR, "power: '{$opt['action']}' is destructive. Add --confirm to execute.\n"
            . "power: it would call: ipmitool -I lanplus -H {$opt['bmc']} -U <" . implode('|', $users) . "> -E {$commands[0]}\n");
        $log->event('preflight', 'missing_confirm', $commands[0]);
        exit(3);
    }
    if ($opt['hostUpOk'] && $opt['reason'] === '') {
        fwrite(STDERR, "power: --host-up-ok needs --reason \"why a running host must be hard-{$opt['action']}ed\"\n");
        exit(1);
    }
    if (in_array($opt['action'], PowerLib::HOST_CHECKED, true) && !$opt['hostUpOk']) {
        $host = $opt['host'] !== '' ? $opt['host'] : PowerLib::mapHost($opt['bmc'], $env('BMC_HOST_PREFIX_MAP'));
        if ($host === '') {
            fwrite(STDERR, "power: WARNING: no --host given, so whether the host's OS is running was not checked\n");
        } elseif (($banner = PowerLib::sshBanner($host)) !== '') {
            fwrite(STDERR, "power: REFUSED: the OS on $host is running (sshd answered: $banner). Shut it down or reboot it over SSH."
                . " If it will not halt, add --host-up-ok and say why in --reason. Wrong host? Pass --host.\n");
            $log->event('preflight', 'host_running', "host=$host");
            exit(4);
        }
    }
}

$auth = PowerLib::resolveAuth($opt['bmc'], $users);
if ($auth === null) {
    fwrite(STDERR, "power: no user authenticated to {$opt['bmc']} (tried " . implode(', ', $users) . " over lanplus and lan)\n");
    $log->event('execute', 'auth_failed', $commands[0]);
    exit(2);
}
[$user, $iface] = $auth;
if ($user !== $users[0] || $iface !== 'lanplus') {
    fwrite(STDERR, "power: authenticated as user=$user interface=$iface\n");
}
foreach ($commands as $i => $command) {
    if ($i > 0) {
        echo "---\n";
    }
    $log->event('execute', 'started', "$command user=$user iface=$iface");
    [$rc, $out] = PowerLib::ipmitool($opt['bmc'], $user, $iface, $command);
    echo $out;
    if ($rc !== 0) {
        $log->event('execute', 'failed', "exit=$rc $out");
        exit($rc);
    }
    $log->event('execute', 'ok', in_array($opt['action'], PowerLib::DESTRUCTIVE, true) ? trim($out) : '');
}
exit(0);
