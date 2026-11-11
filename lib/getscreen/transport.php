<?php
/** The real HTTP transport and process runner for ConsoleCapture (tests use their own). */

/** One HTTP request through php-curl: [status (0 = no connection), raw headers, body]. */
function getscreenHttp(string $method, string $url, string $body, string $cookie): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,   // BMCs use self-signed certificates
        CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=0',                // older Supermicro TLS
        CURLOPT_ENCODING => '',                                         // some BMCs gzip their pages
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if (!is_string($raw)) {
        return [0, '', ''];
    }
    return [$code, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

/** Run a program without a shell; its stdout is discarded, stderr shown only with BMC_DEBUG=1. */
function getscreenRun(array $argv, array $env): int
{
    $err = getenv('BMC_DEBUG') === '1' ? STDERR : ['file', '/dev/null', 'w'];
    $p = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => $err], $pipes, null, $env === [] ? null : $env);
    return is_resource($p) ? proc_close($p) : 127;
}
