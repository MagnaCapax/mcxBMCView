<?php
/**
 * The Avocent Java KVM viewer that iDRAC6-era BMCs serve, run headless for one screenshot.
 *
 * The BMC hands out one-time console tokens in /viewer.jnlp and serves the viewer jars over plain HTTP
 * under /software/. The viewer is the BMC vendor's code and needs a 32-bit Java 8 (its native library is
 * i386), so this tool never downloads a Java runtime: IDRAC6_JAVA names the one the user installed.
 * It runs on a private Xvfb display found with -displayfd, so it never touches another X server, and it
 * stops only the processes it started.
 *
 * `setxkbmap us` runs on the display before the viewer starts: a fresh Xvfb has no keyboard map, and the
 * viewer crashes in XLookupString as soon as it processes a key event without one.
 */

final class AvocentViewer
{
    const JARS = ['avctKVM.jar', 'avctKVMIOLinux.jar'];
    const DEFAULT_MAIN = 'com.avocent.lenovo.kvm.Main';

    /** Pure: the console arguments in a viewer.jnlp, plus the main class. Missing keys are absent. */
    public static function parseJnlp(string $jnlp): array
    {
        $tokens = [];
        if (preg_match_all('#<argument>\s*([a-zA-Z]+)=([^<]*)</argument>#', $jnlp, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $key, $value]) {
                if (in_array($key, ['user', 'passwd', 'kmport', 'vport', 'apcp', 'version'], true)) {
                    $tokens[$key] = trim($value);
                }
            }
        }
        if (preg_match('#main-class\s*=\s*"(com\.avocent\.[A-Za-z0-9_.]+\.Main)"#', $jnlp, $c) === 1) {
            $tokens['main'] = $c[1];
        }
        return $tokens;
    }

    /** Pure: the tokens with their documented defaults, or null when the one-time credentials are missing. */
    public static function completeTokens(array $tokens): ?array
    {
        if (($tokens['user'] ?? '') === '' || ($tokens['passwd'] ?? '') === '') {
            return null;
        }
        $tokens += ['kmport' => '2068', 'apcp' => '1', 'version' => '2', 'main' => self::DEFAULT_MAIN];
        $tokens += ['vport' => $tokens['kmport']];
        foreach (['kmport', 'vport', 'apcp', 'version'] as $k) {
            if (!ctype_digit($tokens[$k])) {
                return null;
            }
        }
        return $tokens;
    }

    /** Pure: does this look like a jar (a zip of a sensible size), not an HTML error page? */
    public static function looksLikeJar(string $bytes): bool
    {
        return strlen($bytes) >= 10000 && strncmp($bytes, "PK\x03\x04", 4) === 0;
    }

    /** Pure: the java argv that starts the viewer. */
    public static function javaArgv(string $java, string $host, array $t, string $securityFile): array
    {
        return [$java, '-Djava.security.properties=' . $securityFile, '-Djava.library.path=native',
            '-cp', implode(':', self::JARS), $t['main'], 'title=console', 'ip=' . $host, 'user=' . $t['user'],
            'passwd=' . $t['passwd'], 'kmport=' . $t['kmport'], 'vport=' . $t['vport'], 'apcp=' . $t['apcp'],
            'version=' . $t['version']];
    }

    /** Pure: has the viewer log shown that video is flowing, or that the session failed? 'up', 'failed' or 'wait'. */
    public static function videoState(string $log): string
    {
        if (preg_match('/SSLHandshake|No appropriate protocol|viewer has terminated|connection has been dropped/i', $log) === 1) {
            return 'failed';
        }
        if (preg_match('/Video Decode|VIDEO_PANEL_SIZE_CHANGED/i', $log) === 1
            || substr_count($log, 'HEARTBEAT') + substr_count($log, 'RandomNumber') >= 4) {
            return 'up';
        }
        return 'wait';
    }

    /** Save the viewer jars into $dir and unpack the native library into $dir/native. Error text or null. */
    public static function stageJars(LegacyWebSession $web, string $dir): ?string
    {
        foreach (self::JARS as $jar) {
            [$status, $bytes] = $web->request('/software/' . $jar, null, false);
            if ($status !== 200 || !self::looksLikeJar($bytes)) {
                return "the BMC did not serve /software/$jar (http $status)";
            }
            file_put_contents("$dir/$jar", $bytes);
        }
        mkdir("$dir/native", 0700);
        exec('unzip -o -j -q ' . escapeshellarg("$dir/avctKVMIOLinux.jar") . " '*.so' -d " . escapeshellarg("$dir/native") . ' 2>&1', $o, $rc);
        return glob("$dir/native/*.so") ? null : 'no native library in avctKVMIOLinux.jar (unzip rc ' . $rc . ')';
    }
}
