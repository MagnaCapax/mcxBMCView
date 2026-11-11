<?php
/** Address and user rules shared by the PHP tools (power, getscreen). */

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
}
