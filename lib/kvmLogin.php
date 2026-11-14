<?php
/**
 * kvmLogin — run a Node browser script (kvm-sendkeys.js, kvm-enterbios.js, bios-flash.js) with the password
 * candidates BMC_PASS, then BMC_PASS_PREVIOUS (BmcEnv::passCandidates), one login attempt per candidate.
 * Usage: php lib/kvmLogin.php [--quiet] <script.js> <argument>...
 *
 * A script exits 3 only when the BMC rejected its login, before it did anything. Only that moves on to the next
 * password; every other result (done, a failure after the login, no answer from the BMC) ends the run and is
 * passed through, so keys are never sent twice and a BMC that is down is not tried twice.
 * The password reaches the script through the environment only, the previous one not at all. --quiet drops the
 * script's stderr unless BMC_DEBUG is set, as the wrappers always did. Exit: the script's, 1 when every password
 * was rejected, 127 when node did not start.
 */

require __DIR__ . '/bmcEnv.php';

const KVM_LOGIN_REJECTED = 3;

$args = array_slice($argv, 1);
$quiet = ($args[0] ?? '') === '--quiet';
if ($quiet) {
    array_shift($args);
}
$script = (string) array_shift($args);
if ($script === '') {
    fwrite(STDERR, "Usage: php lib/kvmLogin.php [--quiet] <script.js> <argument>...\n");
    exit(1);
}
$env = getenv();
$passes = BmcEnv::passCandidates((string) ($env['BMC_PASS'] ?? ''), (string) ($env['BMC_PASS_PREVIOUS'] ?? ''));
unset($env['BMC_PASS_PREVIOUS']);
$stderr = $quiet && (string) getenv('BMC_DEBUG') === '' ? ['file', '/dev/null', 'w'] : STDERR;

foreach ($passes as $i => $pass) {
    $proc = proc_open(array_merge(['node', $script], $args), [0 => STDIN, 1 => STDOUT, 2 => $stderr], $pipes, null, ['BMC_PASS' => $pass] + $env);
    if (!is_resource($proc)) {
        fwrite(STDERR, "kvmLogin: node did not start\n");
        exit(127);
    }
    $rc = proc_close($proc);
    if ($rc !== KVM_LOGIN_REJECTED) {
        if ($i > 0) {
            fwrite(STDERR, "kvmLogin: WARNING: the BMC accepted BMC_PASS_PREVIOUS, not BMC_PASS: its password has not been changed yet\n");
        }
        exit($rc);
    }
}
fwrite(STDERR, 'kvmLogin: the BMC rejected the login' . (count($passes) > 1 ? ' with BMC_PASS and BMC_PASS_PREVIOUS' : '') . "\n");
exit(1);
