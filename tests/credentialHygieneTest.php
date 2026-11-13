<?php
/**
 * Credential hygiene: no tracked code file may carry a literal default for a variable named like a
 * credential, and a defaults file that others can read says so on stderr. The first check scans the code,
 * it does not run it.
 * Run: php tests/credentialHygieneTest.php   (needs no BMC)
 */
$root = dirname(__DIR__);
$fails = 0;
$count = 0;
function check(string $name, bool $ok, string $why = ''): void
{
    global $fails, $count;
    $count++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $why === '' ? '' : "  -- $why") . "\n";
    $fails += $ok ? 0 : 1;
}

$name = '[A-Za-z_]*(?:PASS|PASSWORD|PASSWD|SECRET|TOKEN|API_?KEY)[A-Za-z_]*';
$shapes = [
    'shell default expansion with a literal' => '/\$\{' . $name . ':?-[^}$]+\}/i',
    'shell assignment of a literal'          => '/^\s*(?:export\s+)?' . $name . '=["\']?(?![$"\'\s]|$)[^"\'\s]+/mi',
    'PHP getenv with a literal fallback'     => '/getenv\(\s*[\'"]' . $name . '[\'"]\s*\)\s*(?:\?:|\?\?)\s*[\'"][^\'"]+[\'"]/i',
    'JS env with a literal fallback'         => '/process\.env\.' . $name . '\s*(?:\|\||\?\?)\s*[\'"][^\'"]+[\'"]/i',
];
$files = [];
$list = array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null')));
if ($list === []) {
    // A copy without git metadata (a test machine): walk the tree, skipping what .gitignore keeps out.
    $walk = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function ($c) { return !in_array($c->getFilename(), ['.git', 'node_modules', 'tmp'], true); }
    ));
    foreach ($walk as $item) {
        $list[] = substr($item->getPathname(), strlen($root) + 1);
    }
}
foreach ($list as $f) {
    $isDoc = (bool) preg_match('#^(tests|docs|development)/|\.md$|^LICENSE$|package(-lock)?\.json$#', $f);
    if (!$isDoc && is_file("$root/$f")) {
        $files[] = $f;
    }
}
check('the scan found the code files', count($files) >= 10, 'files=' . count($files));
$bad = [];
foreach ($files as $f) {
    $text = (string) file_get_contents("$root/$f");
    foreach ($shapes as $label => $re) {
        if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $bad[] = "$f line " . (substr_count(substr($text, 0, $m[0][1]), "\n") + 1) . " ($label)";
        }
    }
}
check('no code file has a literal default for a credential variable', $bad === [], implode('; ', $bad));

// Negative control: the scanner must flag the shapes it exists for.
$probe = ['BMC_' . 'PASS="${BMC_' . 'PASS:-abc123xyz}"', "const p = process.env.API_" . "TOKEN || 'abc123xyz';", "\$p = getenv('DB_" . "PASSWORD') ?: 'abc123xyz';"];
$caught = 0;
foreach ($probe as $line) {
    foreach ($shapes as $re) {
        if (preg_match($re, $line) === 1) {
            $caught++;
            break;
        }
    }
}
check('negative control: the scanner flags a literal default in shell, JS and PHP', $caught === 3, "caught=$caught of 3");

// A defaults file that others can read, and that holds the secret, is used but reported; a private one is silent.
function sourceDefaults(string $root, string $file): array
{
    $p = proc_open(['bash', '-c', 'set -euo pipefail; . ' . escapeshellarg("$root/lib/defaults.sh")],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        ['PATH' => (string) getenv('PATH'), 'HOME' => '/nonexistent', 'BMC_DEFAULTS_FILE' => $file]);
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $err];
}
$dir = sys_get_temp_dir() . '/mcxhyg-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$f = "$dir/defaults";
file_put_contents($f, 'BMC_' . 'PASS=' . bin2hex(random_bytes(4)) . "\n");
chmod($f, 0644);
[$rc, $err] = sourceDefaults($root, $f);
check('0644 defaults file with a secret: still loads, warns on stderr', $rc === 0 && strpos($err, 'readable by group or others') !== false, "rc=$rc err=$err");
chmod($f, 0600);
[$rc, $err] = sourceDefaults($root, $f);
check('0600 defaults file: loads and stays silent', $rc === 0 && $err === '', "rc=$rc err=$err");
unlink($f);
rmdir($dir);

echo "$count checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
