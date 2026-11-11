<?php
/** A `php -S` stand-in for a BMC web UI, shared by the tests. */

/** Write the router that plays a Supermicro ATEN web UI (or an AMI one with STUB_VENDOR=ami); returns its path. */
function stubAtenRouter(string $dir): string
{
    $router = "$dir/router.php";
    file_put_contents($router, <<<'PHP'
<?php
$st = getenv('STUB_STATE');
$uri = $_SERVER['REQUEST_URI'];
$log = function (string $s) use ($st) {
    file_put_contents("$st/hits", $s . ' cookie=' . ($_COOKIE['SID'] ?? '') . "\n", FILE_APPEND);
};
if ($uri === '/') {
    $page = getenv('STUB_VENDOR') === 'ami' ? '<html>MegaRAC SP</html>' : '<html>ATEN International Co Ltd</html>';
    if (getenv('STUB_GZIP') === '1') { header('Content-Encoding: gzip'); $page = gzencode($page); }
    echo $page;
    return true;
}
if (strncmp($uri, '/cgi/login.cgi', 14) === 0) {
    $log('login');
    if (($_POST['name'] ?? '') === getenv('STUB_USER') && ($_POST['pwd'] ?? '') === getenv('STUB_PASS')) {
        header('Set-Cookie: SID=stubsid42; path=/');
        echo 'ok';
    } else {
        echo 'fail';
    }
    return true;
}
if (($_COOKIE['SID'] ?? '') !== 'stubsid42') { http_response_code(403); return true; }
if (strncmp($uri, '/cgi/CapturePreview.cgi', 23) === 0) { $log('preview'); echo '<xml/>'; return true; }
if (strncmp($uri, '/cgi/logout.cgi', 15) === 0) { $log('logout'); return true; }
if (strncmp($uri, '/cgi/url_redirect.cgi', 21) === 0 && strpos($uri, 'url_name=Snapshot') !== false) {
    $n = (int) @file_get_contents("$st/polls") + 1;
    file_put_contents("$st/polls", (string) $n);
    $log('snapshot');
    if (getenv('STUB_NEVER_READY') === '1' || $n < 2) { echo '<html>not ready</html>'; return true; }
    header('Content-Type: image/bmp');
    echo pack('a2VvvV', 'BM', 70, 0, 0, 54) . pack('VVVvvVVVVVV', 40, 2, 2, 1, 24, 0, 16, 2835, 2835, 0, 0)
        . str_repeat("\xff\x00\x00\x00\xff\x00\x00\x00", 2);
    return true;
}
http_response_code(404);
return true;
PHP
    );
    return $router;
}

/** Start the stub on a free local port. Returns [process, "127.0.0.1:port"]. */
function stubStart(string $router, string $state, array $env): array
{
    @mkdir($state, 0700, true);
    for ($try = 0; $try < 20; $try++) {
        $port = random_int(20000, 60000);
        $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router], [0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, array_merge(getenv(), ['STUB_STATE' => $state], $env));
        for ($i = 0; $i < 50; $i++) {
            usleep(100000);
            $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
            if ($s) {
                fclose($s);
                return [$p, "127.0.0.1:$port"];
            }
        }
        proc_terminate($p);
        proc_close($p);
    }
    echo "FAIL could not start the php -S stub\n";
    exit(1);
}

function stubStop($p): void
{
    proc_terminate($p);
    proc_close($p);
}
