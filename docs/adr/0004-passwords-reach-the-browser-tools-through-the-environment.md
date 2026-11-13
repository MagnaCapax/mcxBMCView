# ADR-0004: passwords reach the browser tools through the environment

## Metadata
- Author: Sampsa Pellervoinen / MCX
- Date: 2026-11-13
- Status: Accepted

## Context
`power` and `getscreen` already keep the BMC password off command lines (ADR-0003: `ipmitool -E`, and the capture script reads the environment first). The other wrappers did not: `sendkeys`, `flashbios` and `enterbios` passed the password to their Node scripts as the third argument, and `enterbios --reboot` passed it to `ipmitool -P`. On a host with more than one account, any process list (`ps`, `/proc/<pid>/cmdline` without `hidepid`) shows those arguments to everyone for as long as the process runs, and a KVM session can run for minutes.

Two smaller things came up in the same review: console screenshots were written under fixed, guessable names in the shared temporary directory, and a defaults file readable by other accounts was loaded without a word.

## Options Considered
- **Keep the argument and document the risk.** Rejected: the safe path is cheap, and a warning in a README does not stop a process list.
- **Read the password only from the environment and drop the argument.** Rejected: it would break callers that run the Node scripts directly, against "never break old users".
- **Read the environment first, keep the argument as a fallback.** Chosen.
- **Refuse a defaults file that others can read.** Rejected for now: it would stop working setups. It warns instead.

## Decision
- `bios-flash.js`, `kvm-enterbios.js` and `kvm-sendkeys.js` use `BMC_PASS` from the environment when it is set, and the third argument otherwise. The wrappers pass an empty third argument; `lib/defaults.sh` already exports the variables it sets.
- `enterbios --reboot` calls `power cycle <bmc> --confirm --host-up-ok --reason "enterbios --reboot"`, so the reboot gets the same password handling, address check and audit log as every other power action. `--reboot` has always cycled a running server, so `--host-up-ok` keeps that behaviour and the reason says why. A failed cycle is reported and the KVM session still starts, as before.
- Screenshots written without a journal directory go to a fresh private directory (`mkdtemp`, mode 0700); a journal directory the tools create is 0700.
- `lib/defaults.sh` and `BmcEnv::withDefaults` print a warning on stderr when the defaults file holds `BMC_PASS` and is readable by group or others. Group- or other-writable files stay refused, as before.
- `tests/credentialHygieneTest.php` fails when a tracked code file gives a credential-named variable a literal default, and checks the warning.

## Consequences
- The password no longer appears in the arguments of any process these tools start.
- Callers that pass the password as the third argument keep working; an empty `BMC_PASS` in the environment falls back to it.
- A shared defaults file now produces a warning on every run until it is made private.
- `enterbios --reboot` now writes a line to the `power` audit log, and a set `BMC_ADDRESS_PREFIX` applies to it.

## References
- ADR-0001 (layout and languages), ADR-0003 (power in PHP, defaults file).
