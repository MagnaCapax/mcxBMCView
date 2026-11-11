# ADR-0002: iDRAC6-era BMCs: legacy TLS through the system curl, console through the BMC's own Java viewer

## Metadata
- Author: Sampsa Pellervoinen / MCX
- Date: 2026-11-11
- Status: Accepted

## Context
Dell iDRAC6 and the Avocent-based boards that share its firmware (the web server reports `Mbedthis-Appweb`) are still in service on older servers. Their web interface speaks only SSLv3 or TLS 1.0 with old ciphers, so modern browsers, Playwright and a default `curl` cannot connect. Their remote console is not HTML5: the BMC serves a Java Web Start file, `viewer.jnlp`, with one-time console tokens, and the Avocent viewer jars under `/software/`. The viewer's native library is 32-bit (i386), so it needs a 32-bit Java 8. None of the existing tools (ADR-0001: Playwright against the AMI MegaRAC HTML5 viewer) can reach these boards.

## Options Considered
- **A hand-written TLS 1.0 client in Java.** Works, but adds a compiled class and a Java runtime even for a login or a power check. Rejected: OpenSSL 3 still contains the old protocols and ciphers and only disables them by default, so the system `curl` can do it with a configuration file.
- **Re-implement the Avocent console protocol.** Rejected: the protocol is undocumented and proprietary. The viewer the BMC serves is the only working client.
- **Download a Java runtime automatically.** Rejected: the tool would fetch and run software the user did not choose. The user installs a 32-bit Java 8 and names it in `IDRAC6_JAVA`.
- **System curl for the web side, the BMC's viewer under Xvfb for the screen (chosen).**

## Decision
- New command `idrac6` (thin bash wrapper) with the logic in PHP under `lib/idrac6/`, per ADR-0001's language rule. Actions: `auth-test`, `power-status`, `console-check`, `screenshot <out.png>`. All are read-only on the BMC. Power changes stay with `power` (IPMI), which works on these boards.
- Transport: the system `curl` with `lib/idrac6/openssl-legacy.cnf` set as `OPENSSL_CONF` for that child process only. It changes nothing system-wide. The password goes to `curl` on stdin, never on the command line.
- Sessions: these BMCs keep only a few web sessions and hold an abandoned one for about 30 minutes. Every run logs out before it exits. A screenshot logs out right after reading `viewer.jnlp`, because the viewer uses the one-time tokens, not the web session.
- Console: the viewer jars come from the BMC over HTTP, are checked to be zip files of a sensible size, and are unpacked into a private 0700 temporary directory that is removed at exit. The viewer runs under the user's `IDRAC6_JAVA` on a private Xvfb display chosen with `-displayfd`, with `setxkbmap us` first (without a keymap the viewer crashes on its first key event). Only processes the tool started are stopped. The main class comes from `viewer.jnlp` when it names a `com.avocent.*.Main`, else the known default. Ports must be numeric. Their signatures are not checked (Java Web Start would), and curl's `-k` would not authenticate the BMC over HTTPS either, so the trust placed in the viewer is trust in whatever answers at the BMC address; the README says to run `screenshot` on a machine where that is acceptable. The jnlp console token is passed to the viewer as a command-line argument, as Java Web Start does, so it is visible in `ps` while the viewer runs.
- Video is accepted when the viewer log shows decoding or repeated heartbeats. The largest of four frames is kept, because a decoded console compresses worse than a blank canvas.
- The console is view-only: in headless runs the viewer accepts keystrokes but does not forward them to the server.
- Tests: `tests/idrac6Test.php` covers the parsing, the argv builders, the command-line refusals and the jar download against a local HTTP stand-in, with no BMC. `tests/run.php` runs every `tests/*Test.php`.

## Consequences
- The web side (login, power state, the console checks) needs only `php` and `curl`. The screenshot also needs Xvfb, `setxkbmap`, ImageMagick `import`, `unzip`, i386 X libraries and a 32-bit Java 8.
- The tool runs vendor code that the BMC serves. Run it on a machine where that is acceptable.
- The one-time console tokens are on the viewer's command line while it runs, which other local users can see; they are valid for that one session only.
- Typing into the console is not supported. Use `power` or the server's own network access to act on it.

## References
- ADR-0001 (layout and languages)
- OpenSSL 3 configuration: https://docs.openssl.org/3.0/man5/config/
