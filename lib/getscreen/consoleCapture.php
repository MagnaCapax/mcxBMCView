<?php
/**
 * Vendor-aware console screenshot: the logic behind `getscreen`.
 *
 * The BMC web root decides the route:
 *   - Supermicro ATEN web UI: its read-only console preview (/cgi/login.cgi, /cgi/CapturePreview.cgi, then the
 *     Snapshot BMP, converted with ImageMagick). No browser needed.
 *   - iDRAC6-era BMC (Server: Mbedthis-Appweb): `idrac6 screenshot` when IDRAC6_JAVA is set, else a reason.
 *   - old AMI MegaRAC whose only console is the Java JViewer (answers plain http only, or Server: GoAhead-Webs):
 *     the HTML5 capture is still tried, because the detection is a heuristic; on failure the reason says why.
 *   - anything else: the AMI MegaRAC HTML5 KVM capture, kvm-capture.js.
 * Each user from BMC_USER (or BMC_USER_CANDIDATES) is tried in order with BMC_PASS. The password goes over
 * HTTPS only and reaches child processes through the environment, never a command line.
 */

final class ConsoleCapture
{
    /** @var callable fn(string $method, string $url, string $body, string $cookie): array [status (0 = no connection), headers, body] */
    private $http;
    /** @var callable fn(array $argv, array $env): int */
    private $run;
    /** @var callable fn(int $ms): void */
    private $sleep;
    /** @var string repository root */
    private $root;

    public function __construct(callable $http, callable $run, callable $sleep, string $root)
    {
        $this->http = $http;
        $this->run = $run;
        $this->sleep = $sleep;
        $this->root = $root;
    }

    /** 'aten', 'idrac6', 'jviewer' or 'ami' (also when the web root does not answer). */
    public function vendor(string $host): string
    {
        foreach (['https', 'http'] as $scheme) {
            [$code, $headers, $body] = ($this->http)('GET', "$scheme://$host/", '', '');
            if ($code <= 0) {
                continue;
            }
            if (stripos((string) $body, 'ATEN International') !== false) {
                return 'aten';
            }
            if (preg_match('/^Server:\s*Mbedthis-Appweb/mi', (string) $headers) === 1) {
                return 'idrac6';
            }
            if ($scheme === 'http' || preg_match('/^Server:\s*GoAhead/mi', (string) $headers) === 1) {
                return 'jviewer';
            }
            return 'ami';
        }
        return 'ami';
    }

    /** @return array [ok, detail]: "<route> user=<user>" on success, the reason otherwise. */
    public function capture(string $host, string $out, int $waitMs, array $users, string $pass, array $env): array
    {
        $vendor = $this->vendor($host);
        $routes = ['aten' => ['aten'], 'idrac6' => ['idrac6'], 'jviewer' => ['ami'], 'ami' => ['ami']][$vendor];
        if ($vendor === 'idrac6' && (string) ($env['IDRAC6_JAVA'] ?? '') === '') {
            @unlink($out);
            return [false, 'idrac6: iDRAC6-era BMC (Server: Mbedthis-Appweb); its console is the BMC\'s Java viewer.'
                . ' Set IDRAC6_JAVA to a 32-bit Java 8 to capture it (see README, idrac6)'];
        }
        $last = '';
        foreach ($users as $user) {
            foreach ($routes as $route) {
                @unlink($out);   // never accept a file left by an earlier or failed attempt
                if ($route === 'aten') {
                    [$ok, $detail] = $this->captureAten($host, $user, $pass, $out);
                } else {
                    [$ok, $detail] = $this->captureByProgram($route, $host, $user, $pass, $out, $waitMs, $env);
                }
                if ($ok) {
                    @chmod($out, 0600);
                    return [true, "$route user=$user"];
                }
                $last = "$route user=$user: $detail";
            }
        }
        @unlink($out);
        return [false, $vendor === 'jviewer'
            ? "$last; this looks like old AMI MegaRAC firmware whose only console is the Java JViewer (http only or GoAhead-Webs), which these tools do not drive"
            : $last];
    }

    private function captureAten(string $host, string $user, string $pass, string $out): array
    {
        $base = "https://$host";
        [$code, $headers] = ($this->http)('POST', "$base/cgi/login.cgi", http_build_query(['name' => $user, 'pwd' => $pass]), '');
        if (preg_match('/^Set-Cookie:\s*SID=([^;\s]+)/mi', (string) $headers, $m) !== 1) {
            return [false, "login refused (http $code)"];
        }
        $cookie = 'SID=' . $m[1];
        $ts = (string) (int) (microtime(true) * 1000);
        ($this->http)('GET', "$base/cgi/CapturePreview.cgi?IKVM_PREVIEW_XML=(0,0)&time_stamp=$ts", '', $cookie);
        $bmp = '';
        for ($i = 0; $i < 6 && $bmp === ''; $i++) {
            ($this->sleep)(2000);
            [, , $body] = ($this->http)('GET', "$base/cgi/url_redirect.cgi?url_name=Snapshot&url_type=img&time_stamp=$ts", '', $cookie);
            if (strncmp((string) $body, 'BM', 2) === 0) {
                $bmp = (string) $body;
            }
        }
        ($this->http)('GET', "$base/cgi/logout.cgi", '', $cookie);   // free the BMC session slot, best effort
        if ($bmp === '') {
            return [false, 'no snapshot image after 12 s'];
        }
        $dir = self::privateTempDir();
        if ($dir === '') {
            return [false, 'cannot create a private temp dir'];
        }
        file_put_contents("$dir/snap.bmp", $bmp);
        $rc = ($this->run)(['convert', "$dir/snap.bmp", $out], []);
        @unlink("$dir/snap.bmp");
        @rmdir($dir);
        return $rc === 0 && is_file($out) && filesize($out) > 0 ? [true, ''] : [false, "convert failed rc=$rc"];
    }

    /** The AMI HTML5 capture (node) or the iDRAC6 viewer (idrac6); the password only in the environment. */
    private function captureByProgram(string $route, string $host, string $user, string $pass, string $out, int $waitMs, array $env): array
    {
        $child = ['BMC_USER' => $user, 'BMC_PASS' => $pass, 'PATH' => $env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $env['HOME'] ?? sys_get_temp_dir()];
        foreach (['NODE_PATH', 'BMC_DEBUG', 'IDRAC6_JAVA'] as $k) {
            if ((string) ($env[$k] ?? '') !== '') {
                $child[$k] = (string) $env[$k];
            }
        }
        $wait = $waitMs > 0 ? (string) $waitMs : (string) ($env['BMC_RENDER_WAIT'] ?? '');
        if ($wait !== '') {
            $child['BMC_RENDER_WAIT'] = $wait;
        }
        $argv = $route === 'idrac6'
            ? ['php', $this->root . '/lib/idrac6/idrac6.php', $host, 'screenshot', $out]
            : ['node', $this->root . '/kvm-capture.js', $host, $user, '', $out];
        $rc = ($this->run)($argv, $child);
        $name = $route === 'idrac6' ? 'idrac6' : 'kvm-capture';
        return $rc === 0 && is_file($out) && filesize($out) > 0 ? [true, ''] : [false, "$name rc=$rc"];
    }

    private static function privateTempDir(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $d = sys_get_temp_dir() . '/bmc-capture-' . bin2hex(random_bytes(6));
            if (@mkdir($d, 0700)) {
                return $d;
            }
        }
        return '';
    }
}
