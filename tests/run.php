<?php
/**
 * Runs every tests/*Test.php in its own PHP process and fails if any of them fails.
 * Run: php tests/run.php   (these tests need no BMC; behaviour on real hardware is checked by hand)
 */
$failed = [];
foreach (glob(__DIR__ . '/*Test.php') as $test) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test), $rc);
    if ($rc !== 0) {
        $failed[] = basename($test);
    }
}
echo $failed === [] ? "ALL PASS\n" : 'FAILED: ' . implode(', ', $failed) . "\n";
exit($failed === [] ? 0 : 1);
