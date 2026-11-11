<?php
/**
 * One web session on an iDRAC6-era BMC (Mbedthis-Appweb, SSLv3/TLS 1.0 only).
 *
 * The transport is the system curl with idrac6/openssl-legacy.cnf in OPENSSL_CONF: OpenSSL 3 still
 * has the old protocol and ciphers, it only disables them by default. The password goes to curl on
 * stdin, never on the command line. The cookie jar lives in a private 0700 directory that close()
 * removes. The BMC allows only a few web sessions and keeps an abandoned one for about 30 minutes,
 * so every caller must call close(), which logs out.
 */

final class LegacyWebSession
{
    /** @var string */
    private $host;
    /** @var string private 0700 directory for the cookie jar and response bodies */
    private $dir;
    /** @var bool */
    private $loggedIn = false;

    public function __construct(string $host, string $dir)
    {
        $this->host = $host;
        $this->dir = $dir;
    }

    /** Pure: the curl argv for one request. $bodyFile receives the response body. */
    public static function curlArgv(string $host, string $path, string $jar, string $bodyFile, bool $post, bool $tls = true): array
    {
        $argv = ['curl', '-sS', '-k', '-m', '40', '-b', $jar, '-c', $jar, '-o', $bodyFile, '-w', '%{http_code}'];
        if ($tls) {
            array_push($argv, '--tlsv1', '--tls-max', '1.0', '--ciphers', 'ALL:@SECLEVEL=0');
        }
        if ($post) {
            array_push($argv, '-X', 'POST', '--data-binary', '@-');
        }
        $argv[] = ($tls ? 'https://' : 'http://') . $host . $path;
        return $argv;
    }

    /** Pure: is this /data/login reply a successful login? */
    public static function loginAccepted(string $body): bool
    {
        return preg_match('#<authResult>\s*0\s*</authResult>#', $body) === 1;
    }

    /** Pure: the power state in a /data?get=pwState reply: 'on', 'off' or null. */
    public static function powerState(string $body): ?string
    {
        if (preg_match('#<pwState>\s*([01])\s*</pwState>#', $body, $m) !== 1) {
            return null;
        }
        return $m[1] === '1' ? 'on' : 'off';
    }

    /** One request. Returns [http status (0 = transport failure), body]. */
    public function request(string $path, ?string $postBody = null, bool $tls = true): array
    {
        $bodyFile = $this->dir . '/body';
        @unlink($bodyFile);
        $argv = self::curlArgv($this->host, $path, $this->dir . '/cookies', $bodyFile, $postBody !== null, $tls);
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'OPENSSL_CONF' => __DIR__ . '/openssl-legacy.cnf'];
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);
        if (!is_resource($proc)) {
            return [0, ''];
        }
        fwrite($pipes[0], (string) $postBody);
        fclose($pipes[0]);
        $code = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        if ($err !== '' && getenv('BMC_DEBUG') === '1') {
            fwrite(STDERR, "curl: $err\n");
        }
        $body = (string) @file_get_contents($bodyFile);
        @unlink($bodyFile);
        return [$rc === 0 && ctype_digit($code) ? (int) $code : 0, $body];
    }

    /** Log in. True when the BMC accepted the credentials and gave a session. */
    public function login(string $user, string $pass): bool
    {
        [$status, $body] = $this->request('/data/login', 'user=' . rawurlencode($user) . '&password=' . rawurlencode($pass));
        $this->loggedIn = $status === 200 && self::loginAccepted($body);
        return $this->loggedIn;
    }

    /** Log out (when logged in) and remove the cookie jar. Safe to call more than once. */
    public function close(): void
    {
        if ($this->loggedIn) {
            $this->request('/data/logout', '');
            $this->loggedIn = false;
        }
        @unlink($this->dir . '/cookies');
    }
}
