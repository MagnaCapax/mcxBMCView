<?php
require_once __DIR__ . '/../bmcEnv.php';

/** The parts of `power` that can be tested without a BMC, plus the ipmitool and audit-log calls. */

final class PowerLib
{
    const COMMANDS = [
        'status' => ['chassis power status'],
        'sensors' => ['sdr type Temperature', 'sdr type Voltage', 'sdr type Fan'],
        'sel' => ['sel elist'],
        'on' => ['chassis power on'],
        'off' => ['chassis power off'],
        'cycle' => ['chassis power cycle'],
        'reset' => ['chassis power reset'],
        'soft' => ['chassis power soft'],
    ];
    const DESTRUCTIVE = ['on', 'off', 'cycle', 'reset', 'soft'];
    /** Hard actions that kill a running OS; checked against the host's sshd first. */
    const HOST_CHECKED = ['off', 'cycle', 'reset'];

    const USAGE = <<<'TXT'
Usage: power <action> <bmc-host> [--confirm] [--reason "..."] [--host <host>] [--host-up-ok]

Read-only actions:
  status     chassis power state
  sensors    temperature, voltage and fan sensors
  sel        System Event Log

Destructive actions (need --confirm; written to the audit log):
  on         chassis power on
  off        chassis power off (hard)
  cycle      chassis power cycle (off, wait, on)
  reset      chassis power reset (hard reset)
  soft       chassis power soft (ACPI power button: the OS shuts down cleanly)

Options:
  --confirm        required for destructive actions; without it they print what they would do and exit 3
  --reason "..."   one-line reason, written to the audit log
  --host <host>    the server behind this BMC. Before off, cycle and reset its sshd is asked for a banner;
                   if the OS answers, the action is refused (exit 4): shut it down over SSH instead
  --host-up-ok     allow off, cycle or reset although the OS answers; needs --reason

Environment (unset values can come from the defaults file, see README):
  BMC_PASS              BMC password (required, no default)
  BMC_PASS_PREVIOUS     tried after BMC_PASS while a password change is rolled out; a warning says when it was used
  BMC_USER              BMC user; when unset, BMC_USER_CANDIDATES (default "admin root") are tried in order
  BMC_LOG_DIR           audit log directory (default ~/.local/state/mcxbmcview); file bmc-actions/YYYYMMDD.jsonl
  BMC_ADDRESS_PREFIX    when set, the BMC address must start with it (guards against typing a wrong address)
  BMC_HOST_PREFIX_MAP   "from=to": derive --host from the BMC address by replacing that prefix
  SESSION_ID            written to the audit log as the session
  BMC_SESSION_VAR       name of another variable to take the session from first (e.g. your job runner's run id)

Examples:
  power status 10.0.0.100
  power cycle 10.0.0.100 --confirm --host 192.0.2.10 --reason "hung at boot"

TXT;

    /** Pure: options from argv: action, bmc, confirm, reason, host, hostUpOk; or 'error' / 'help'. */
    public static function parseArgs(array $args): array
    {
        $o = ['confirm' => false, 'reason' => '', 'host' => '', 'hostUpOk' => false];
        $pos = [];
        for ($i = 0; $i < count($args); $i++) {
            $a = $args[$i];
            if ($a === '-h' || $a === '--help') {
                return ['help' => true];
            } elseif ($a === '--confirm') {
                $o['confirm'] = true;
            } elseif ($a === '--host-up-ok') {
                $o['hostUpOk'] = true;
            } elseif ($a === '--reason' || $a === '--host') {
                $o[ltrim($a, '-')] = (string) ($args[++$i] ?? '');
            } elseif (preg_match('/^--(reason|host)=(.*)$/s', $a, $m) === 1) {
                $o[$m[1]] = $m[2];
            } elseif (strncmp($a, '-', 1) === 0) {
                return ['error' => "unknown option: $a"];
            } else {
                $pos[] = $a;
            }
        }
        if (count($pos) !== 2) {
            return ['error' => 'need an action and a BMC address'];
        }
        if (!isset(self::COMMANDS[$pos[0]])) {
            return ['error' => "unknown action: {$pos[0]}"];
        }
        return $o + ['action' => $pos[0], 'bmc' => $pos[1]];
    }

    /** Pure: the host derived from the BMC address with a "from=to" prefix map, or ''. */
    public static function mapHost(string $bmc, string $map): string
    {
        $parts = explode('=', $map, 2);
        if (count($parts) !== 2 || $parts[0] === '' || strncmp($bmc, $parts[0], strlen($parts[0])) !== 0) {
            return '';
        }
        return $parts[1] . substr($bmc, strlen($parts[0]));
    }

    public static function pingable(string $host): bool
    {
        exec('ping -c 1 -W 1 ' . escapeshellarg($host) . ' >/dev/null 2>&1', $o, $rc);
        return $rc === 0;
    }

    /** The sshd banner of $host ('' when none within 5 s). Only a running userland greets. */
    public static function sshBanner(string $host): string
    {
        $port = 22;
        if (preg_match('/^(.+):(\d{1,5})$/', $host, $m) === 1) {
            [$host, $port] = [$m[1], (int) $m[2]];
        }
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($fp === false) {
            return '';
        }
        stream_set_timeout($fp, 5);
        $line = trim((string) fgets($fp, 256));
        fclose($fp);
        return strncmp($line, 'SSH-', 4) === 0 ? $line : '';
    }

    /** Run one ipmitool command; the password goes in the environment (-E), never on the command line. */
    public static function ipmitool(string $bmc, string $user, string $iface, string $command, ?string $pass = null): array
    {
        $argv = array_merge(['ipmitool', '-I', $iface, '-H', $bmc, '-U', $user, '-E'], explode(' ', $command));
        $env = ['PATH' => (string) getenv('PATH'), 'IPMI_PASSWORD' => $pass ?? (string) getenv('BMC_PASS')];
        $p = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($p)) {
            return [127, "ipmitool did not start\n"];
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out];
    }

    /**
     * The first password, user and interface (lanplus, then lan for old BMCs) that pass a read-only `mc info`:
     * [user, iface, password]. Every user is tried with the first password before the next password.
     */
    public static function resolveAuth(string $bmc, array $users, ?array $passes = null): ?array
    {
        foreach ($passes ?? [(string) getenv('BMC_PASS')] as $pass) {
            foreach ($users as $user) {
                foreach (['lanplus', 'lan'] as $iface) {
                    if (self::ipmitool($bmc, $user, $iface, 'mc info', $pass)[0] === 0) {
                        return [$user, $iface, $pass];
                    }
                }
            }
        }
        return null;
    }
}

/** One JSON line per step in <dir>/bmc-actions/YYYYMMDD.jsonl. */
final class PowerLog
{
    /** @var array */
    private $base;
    /** @var string */
    private $file;

    public function __construct(string $dir, string $session, string $action, string $bmc, string $reason, string $reachable)
    {
        $this->base = ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'session' => $session, 'action' => $action, 'bmc_ip' => $bmc];
        $this->base += ['reason' => $reason, 'bmc_reachable' => $reachable];
        if (!is_dir("$dir/bmc-actions")) {
            @mkdir("$dir/bmc-actions", 0700, true);
        }
        $this->file = "$dir/bmc-actions/" . gmdate('Ymd') . '.jsonl';
    }

    public function event(string $phase, string $result, string $detail): void
    {
        $row = $this->base + ['phase' => $phase, 'result' => $result, 'detail' => $detail];
        @file_put_contents($this->file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND);
    }
}
