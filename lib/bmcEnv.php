<?php
/** Address, user and defaults-file rules shared by the PHP tools (power, getscreen) and by PHP callers. */

final class BmcEnv
{
    /** Pure: why this BMC address is refused, or null. A :port suffix is allowed when $allowPort. */
    public static function addressProblem(string $bmc, string $prefix, bool $allowPort = false): ?string
    {
        $pattern = $allowPort ? '/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}(:\d{1,5})?$/' : '/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/';
        if (preg_match($pattern, $bmc) !== 1) {
            return "not a host name or IPv4 address: $bmc";
        }
        if ($prefix !== '' && strncmp($bmc, $prefix, strlen($prefix)) !== 0) {
            return "the BMC address must start with $prefix (BMC_ADDRESS_PREFIX): $bmc";
        }
        return null;
    }

    /** Pure: the users to try, in order. An explicit BMC_USER is the only candidate. */
    public static function userCandidates(string $user, string $candidates): array
    {
        if ($user !== '') {
            return [$user];
        }
        $list = preg_split('/\s+/', trim($candidates !== '' ? $candidates : 'admin root'), -1, PREG_SPLIT_NO_EMPTY);
        return $list ?: ['admin'];
    }
    /**
     * BMC_PASS, then BMC_PASS_PREVIOUS when it is set and different. The previous password is for the time a
     * password change is rolled out BMC by BMC: a BMC not yet changed still logs in, and the caller says so.
     */
    public static function passCandidates(string $pass, string $previous): array
    {
        return $previous !== '' && $previous !== $pass ? [$pass, $previous] : [$pass];
    }

    /** The keys a defaults file may set; lib/defaults.sh reads the same list. */
    const DEFAULT_KEYS = ['BMC_USER', 'BMC_PASS', 'BMC_PASS_PREVIOUS', 'BMC_USER_CANDIDATES', 'BMC_LOG_DIR', 'BMC_ADDRESS_PREFIX',
        'BMC_HOST_PREFIX_MAP', 'BMC_SESSION_VAR', 'NODE_PATH', 'IDRAC6_JAVA'];

    /**
     * $env with its unset or empty keys filled from the user's defaults file, the same way lib/defaults.sh
     * does for the shell wrappers: BMC_DEFAULTS_FILE, else ${XDG_CONFIG_HOME:-$HOME/.config}/mcxbmcview/defaults;
     * one KEY=VALUE per line; only DEFAULT_KEYS; ignored unless the file is a regular file (not a symlink)
     * owned by this user (needs PHP's posix functions) and not writable by group or others.
     * For PHP callers that need a value such as
     * BMC_PASS without going through a wrapper. $env defaults to the process environment.
     */
    public static function withDefaults(?array $env = null): array
    {
        $env = $env ?? getenv();
        $file = (string) ($env['BMC_DEFAULTS_FILE'] ?? '');
        if ($file === '') {
            $base = (string) ($env['XDG_CONFIG_HOME'] ?? '');
            $file = ($base !== '' ? $base : ($env['HOME'] ?? '') . '/.config') . '/mcxbmcview/defaults';
        }
        $st = @lstat($file);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $st['uid'] !== $uid || ($st['mode'] & 0022) !== 0) {
            return $env;
        }
        $content = (string) @file_get_contents($file);
        if ((($st['mode'] & 0044) !== 0) && preg_match('/^BMC_PASS/m', $content) === 1) {
            fwrite(STDERR, "mcxbmcview: WARNING: $file holds a password and is readable by group or others; chmod 600 it\n");
        }
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2 && in_array($parts[0], self::DEFAULT_KEYS, true) && (string) ($env[$parts[0]] ?? '') === '') {
                $env[$parts[0]] = $parts[1];
            }
        }
        return $env;
    }
}
