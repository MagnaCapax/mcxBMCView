# ADR-0005: password candidates for the browser tools and idrac6

## Metadata
- Author: Sampsa Pellervoinen / MCX
- Date: 2026-11-14
- Status: Accepted

## Context
`BMC_PASS_PREVIOUS` (README, "Changing the password across many BMCs") lets `power` and `getscreen` keep working while a new password is rolled out BMC by BMC. `sendkeys`, `enterbios`, `flashbios` and `idrac6` read `BMC_PASS` only, so during a rollout they fail on every BMC that still has the old password.

The Node scripts behind the first three cannot simply be retried by a caller. They exit 1 for any failure, and a failure after the login (the KVM does not connect, a key press fails, a flash step fails) must not be repeated with another password: keys would be sent twice and a second wrong login would be made on a BMC whose first login worked. They also trusted the CSRF token (`garc`) alone as proof of a login, which AMI MegaRAC seeds on the login page itself, so a rejected login looked like a success until the viewer failed (`kvm-capture.js` already checks the login form as well).

## Options Considered
- **Retry the whole script on any non-zero exit.** Rejected: repeats keys, flashes, and wrong logins that count towards a BMC lockout.
- **Put the candidate loop in each Node script.** Rejected: three copies, in a language the project keeps for the browser layer only (ADR-0001), and the password list would have to reach every script.
- **A distinct exit code for a rejected login, and one PHP helper that moves on only for that code.** Chosen.

## Decision
- `kvm-sendkeys.js`, `kvm-enterbios.js` and `bios-flash.js` treat a login as failed when the CSRF token is missing or the login form is still visible (the check `kvm-capture.js` has), and then exit 3. Exit 3 is used for nothing else, and it happens before the script has done anything on the BMC.
- `lib/kvmLogin.php` runs such a script once per password from `BmcEnv::passCandidates` (`BMC_PASS`, then `BMC_PASS_PREVIOUS`), stops after the first run that is not a rejected login, and passes its exit code on. When every password is rejected it exits 1, as the wrappers always did for a failed login. The password reaches the script through the environment only, and the previous one is never given to it.
- `sendkeys`, `enterbios` and `flashbios` run their script through `lib/kvmLogin.php`. A run that needed `BMC_PASS_PREVIOUS` prints the same warning as `power`.
- `idrac6` tries `BMC_PASS`, then `BMC_PASS_PREVIOUS`, one login each, and warns when the previous one was needed.

## Consequences
- Each BMC that still has the old password costs one extra failed login on the new password before the old one is tried. With a lockout policy of very few failures, set the old password as `BMC_PASS` until the rollout reaches such a BMC.
- The three scripts now also report a login that only looked successful as a failed login, instead of failing later in the viewer. A firmware whose login form stays visible after a good login would now be refused; `kvm-capture.js` has run with the same check.
- `kvm-capture.js` still exits 1 on a rejected login; `getscreen` tries the next password after any failure, including one that happens after a good login.
- `idrac6` cannot tell a rejected login from a BMC that does not answer, so it tries the second password in both cases.

## References
- ADR-0003 (defaults file, `power` in PHP), ADR-0004 (the environment carries the password).
