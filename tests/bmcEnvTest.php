<?php
/**
 * Tests for BmcEnv::withDefaults and its parity with lib/defaults.sh: both read the same defaults file
 * the same way (fixed keys, environment wins, a last line without a newline, CRLF line ends, and the
 * file ignored when it is writable by others or is a symlink).
 * Run: php tests/bmcEnvTest.php
 */
$root = dirname(__DIR__);
require "$root/lib/bmcEnv.php";

$fails = 0;
$count = 0;
function check(string $name, bool $ok, string $why = ''): void
{
    global $fails, $count;
    $count++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $why === '' ? '' : "  -- $why") . "\n";
    $fails += $ok ? 0 : 1;
}

/** The value of $key after lib/defaults.sh is sourced with $env, through bash with set -euo pipefail. */
function shellValue(string $root, array $env, string $key): string
{
    $script = 'set -euo pipefail; . ' . escapeshellarg("$root/lib/defaults.sh") . '; printf %s "${' . $key . ':-}"';
    $p = proc_open(['bash', '-c', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        $env + ['PATH' => (string) getenv('PATH')]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($p) === 0 ? $out : '<shell failed>';
}

$scratch = sys_get_temp_dir() . '/bmcenv-test-' . bin2hex(random_bytes(4));
mkdir($scratch, 0700);
$file = "$scratch/defaults";
$pw = 'stub-' . bin2hex(random_bytes(4));
file_put_contents($file, "BMC_USER_CANDIDATES=root admin\r\nEVIL=1\nPATH=/nowhere\n# BMC_USER=comment\nBMC_PASS=$pw");
chmod($file, 0600);
$base = ['HOME' => $scratch, 'BMC_DEFAULTS_FILE' => $file];

$e = BmcEnv::withDefaults($base);
check('fills BMC_PASS from a last line without a newline', ($e['BMC_PASS'] ?? '') === $pw);
check('strips a CR line end', ($e['BMC_USER_CANDIDATES'] ?? '') === 'root admin');
check('ignores keys outside the list', !isset($e['EVIL']) && !isset($e['PATH']) && !isset($e['# BMC_USER']));
check('the environment wins', BmcEnv::withDefaults($base + ['BMC_PASS' => 'fromenv'])['BMC_PASS'] === 'fromenv');
check('an empty environment value is filled', BmcEnv::withDefaults(['BMC_PASS' => ''] + $base)['BMC_PASS'] === $pw);
check('shell: same BMC_PASS', shellValue($root, $base, 'BMC_PASS') === $pw);
check('shell: same BMC_USER_CANDIDATES', shellValue($root, $base, 'BMC_USER_CANDIDATES') === 'root admin');
check('shell: the environment wins', shellValue($root, $base + ['BMC_PASS' => 'fromenv'], 'BMC_PASS') === 'fromenv');
check('shell: keys outside the list are not exported', shellValue($root, $base, 'EVIL') === '');

mkdir("$scratch/xdg/mcxbmcview", 0700, true);
copy($file, "$scratch/xdg/mcxbmcview/defaults");
chmod("$scratch/xdg/mcxbmcview/defaults", 0600);
$xdg = ['HOME' => '/nonexistent', 'XDG_CONFIG_HOME' => "$scratch/xdg"];
check('XDG_CONFIG_HOME locates the file', (BmcEnv::withDefaults($xdg)['BMC_PASS'] ?? '') === $pw);
check('shell: XDG_CONFIG_HOME locates the file', shellValue($root, $xdg, 'BMC_PASS') === $pw);

chmod($file, 0620);
check('a group-writable file is ignored', !isset(BmcEnv::withDefaults($base)['BMC_PASS']));
check('shell: a group-writable file is ignored', shellValue($root, $base, 'BMC_PASS') === '');
chmod($file, 0600);
symlink($file, "$scratch/link");
$linked = ['HOME' => $scratch, 'BMC_DEFAULTS_FILE' => "$scratch/link"];
check('a symlinked file is ignored', !isset(BmcEnv::withDefaults($linked)['BMC_PASS']));
check('shell: a symlinked file is ignored', shellValue($root, $linked, 'BMC_PASS') === '');
check('passCandidates: the previous password after the current one', BmcEnv::passCandidates('a', 'b') === ['a', 'b']);
check('passCandidates: an unset or equal previous password is not tried twice',
    BmcEnv::passCandidates('a', '') === ['a'] && BmcEnv::passCandidates('a', 'a') === ['a']);
file_put_contents("$scratch/prev", "BMC_PASS=new1\nBMC_PASS_PREVIOUS=old1\n");
chmod("$scratch/prev", 0600);
check('the defaults file may set BMC_PASS_PREVIOUS', (BmcEnv::withDefaults(['BMC_DEFAULTS_FILE' => "$scratch/prev"])['BMC_PASS_PREVIOUS'] ?? '') === 'old1'
    && shellValue($root, ['HOME' => $scratch, 'BMC_DEFAULTS_FILE' => "$scratch/prev"], 'BMC_PASS_PREVIOUS') === 'old1');
check('a missing file changes nothing', BmcEnv::withDefaults(['BMC_DEFAULTS_FILE' => "$scratch/none"]) === ['BMC_DEFAULTS_FILE' => "$scratch/none"]);

exec('rm -rf -- ' . escapeshellarg($scratch));
echo "$count checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
