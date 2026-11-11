# ADR-0003: power in PHP, vendor-aware getscreen, and a defaults file

## Metadata
- Author: Sampsa Pellervoinen / MCX
- Date: 2026-11-11
- Status: Accepted

## Context
Three changes arrived together from running these tools against a mixed fleet of BMCs:
- `power` needed a graceful `soft` action, a fallback across user names (AMI boards use `admin`, older boards `root`), and a refusal to hard-cycle a server whose OS is still running. ADR-0001 says `power` moves to PHP when it is next changed beyond a small fix. It also carried one site's network range and a host-mapping rule in the public code.
- `getscreen` drove only the AMI MegaRAC HTML5 viewer. Supermicro ATEN BMCs have no HTML5 KVM but do serve a read-only console preview, and iDRAC6-era BMCs need `idrac6` (ADR-0002).
- Every tool takes credentials from the environment. Scripts that call the tools many times, and people at a terminal, end up exporting the password into shells or writing wrappers that hold it.

## Options Considered
- **Keep `power` in shell and add the features.** Rejected: ADR-0001.
- **A separate command per BMC family for screenshots.** Rejected: callers would have to know the vendor first. `getscreen` reads the web root and picks the route, with the same interface as before.
- **Source a shell file for defaults.** Rejected: it would execute whatever is in the file. The loader reads `KEY=VALUE` lines for a fixed list of keys.

## Decision
- `power` is `lib/power/power.php` behind a thin wrapper. Same actions, `--confirm`, `--reason` and exit codes, plus:
  - `soft`;
  - `BMC_USER_CANDIDATES` (default `admin root`, tried over lanplus, then lan) when `BMC_USER` is unset;
  - `--host`: before `off`, `cycle` or `reset`, the host's sshd is asked for a banner; if it greets, the action is refused with exit 4 unless `--host-up-ok` and `--reason` are given;
  - the password goes to `ipmitool` through `IPMI_PASSWORD` (`-E`), never on its command line.
  The network check is no longer built in: `BMC_ADDRESS_PREFIX` restricts the address when set, and `BMC_HOST_PREFIX_MAP` (`from=to`) derives `--host` from the BMC address.
- `getscreen` is `lib/getscreen/` behind a thin wrapper. It reads the web root:
  - Supermicro ATEN: the console preview, converted with ImageMagick;
  - `Server: Mbedthis-Appweb`: `idrac6 screenshot` when `IDRAC6_JAVA` is set, otherwise a refusal that says so;
  - an old AMI board that answers only plain http or names itself GoAhead-Webs: the HTML5 capture is tried, and the failure says the board has only the Java JViewer console;
  - anything else: `kvm-capture.js`.
  The password goes over HTTPS only and reaches child processes through the environment. A file left by a failed attempt is never reported as a capture.
- `kvm-capture.js` takes the password from `BMC_PASS` when the argument is empty. It no longer reports a login page as a capture: a login fails unless the login form is gone.
- The wrappers load `lib/defaults.sh`. It fills unset variables from `${XDG_CONFIG_HOME:-~/.config}/mcxbmcview/defaults` (or `BMC_DEFAULTS_FILE`), one `KEY=VALUE` per line, for a fixed list of keys. The environment always wins and nothing is executed. The file is ignored unless it belongs to the user and is not writable by group or others. AGENTS.md's credential rule now reads "from the environment or the user's defaults file; no real or default password in code or docs".
- Tests without a BMC: `tests/powerTest.php` (a fake `ipmitool` and a fake sshd), `tests/getscreenTest.php` (a stub ATEN web UI and recording runners).

## Consequences
- `power`'s audit log keeps its fields; the session id comes from `SESSION_ID`.
- Setups that relied on the built-in network check set `BMC_ADDRESS_PREFIX`.
- `sendkeys`, `enterbios` and `flashbios` use the first of `BMC_USER_CANDIDATES` when `BMC_USER` is unset; their browser flow is unchanged.

## References
- ADR-0001, ADR-0002
- ipmitool(1): `-E` reads the password from `IPMI_PASSWORD`
