<?php
/**
 * Run the staged Avocent viewer on a private Xvfb display and save one console frame.
 * Every process started here is stopped here; nothing outside this run is looked up or killed.
 */

final class ConsoleShot
{
    /** Start a process with no shell. Returns [handle, pipes] or null. */
    private static function start(array $argv, array $spec, string $cwd, array $env)
    {
        $proc = proc_open($argv, $spec, $pipes, $cwd, $env);
        return is_resource($proc) ? [$proc, $pipes] : null;
    }

    /** Stop a process this class started: TERM, then KILL after two seconds. */
    private static function stop($proc): void
    {
        if (!is_resource($proc)) {
            return;
        }
        proc_terminate($proc, 15);
        for ($i = 0; $i < 20 && proc_get_status($proc)['running']; $i++) {
            usleep(100000);
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, 9);
        }
        proc_close($proc);
    }

    /** Capture to $out. Returns null on success, else the reason. $dir holds the staged jars. */
    public static function capture(string $java, string $host, array $tokens, string $dir, string $out): ?string
    {
        $base = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $dir];
        $null = ['file', '/dev/null', 'w'];
        $x = self::start(['Xvfb', '-displayfd', '3', '-screen', '0', '1280x1024x24', '-nolisten', 'tcp'],
            [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null, 3 => ['pipe', 'w']], $dir, $base);
        if ($x === null) {
            return 'Xvfb did not start (is it installed?)';
        }
        $display = trim((string) fgets($x[1][3]));
        fclose($x[1][3]);
        $viewer = null;
        try {
            if (!ctype_digit($display)) {
                return 'Xvfb did not report a display';
            }
            $env = $base + ['DISPLAY' => ':' . $display];
            exec('DISPLAY=:' . $display . ' setxkbmap us 2>&1', $o, $rc);
            if ($rc !== 0) {
                return 'setxkbmap failed (install x11-xkb-utils); without a keymap the viewer crashes';
            }
            $log = "$dir/viewer.log";
            $viewer = self::start(AvocentViewer::javaArgv($java, $host, $tokens, __DIR__ . '/legacy-tls.security'),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $dir, $env);
            if ($viewer === null) {
                return 'the viewer did not start';
            }
            $state = 'wait';
            for ($i = 0; $i < 30 && $state === 'wait'; $i++) {
                sleep(1);
                $state = AvocentViewer::videoState((string) @file_get_contents($log));
                if ($state === 'wait' && !proc_get_status($viewer[0])['running']) {
                    $state = 'failed';
                }
            }
            if ($state !== 'up') {
                return $state === 'failed' ? 'the console session failed or dropped (see BMC_DEBUG=1)' : 'no video within 30 seconds';
            }
            return self::bestFrame($display, $dir, $out);
        } finally {
            if (getenv('BMC_DEBUG') === '1' && is_file("$dir/viewer.log")) {
                fwrite(STDERR, substr((string) file_get_contents("$dir/viewer.log"), -2000) . "\n");
            }
            self::stop($viewer[0] ?? null);
            self::stop($x[0]);
        }
    }

    /** Grab four frames and keep the largest: a decoded console compresses worse than a blank canvas. */
    private static function bestFrame(string $display, string $dir, string $out): ?string
    {
        sleep(2);
        $best = 0;
        for ($f = 0; $f < 4; $f++) {
            $tmp = "$dir/frame$f.png";
            exec('DISPLAY=:' . $display . ' import -window root ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
            $size = (int) @filesize($tmp);
            if ($rc === 0 && $size > $best && @copy($tmp, $out)) {
                $best = $size;
            }
            @unlink($tmp);
            usleep(1500000);
        }
        return $best > 0 ? null : 'import (ImageMagick) saved no frame';
    }
}
