# mcxBMCView

> *"Wondrous things might be constructed from the relics of this monster."*
> — Kalevala, Runo XL

Deterministic screenshot capture, keyboard input, and **BIOS reflash** for AMI MegaRAC BMC HTML5 consoles. **Built for AI agent integration** and headless server management — no human browser needed.

## Why This Exists

AI agents managing bare-metal servers hit a wall when SSH is gone: BIOS screens, boot failures, kernel panics, OS install wizards — all locked behind the BMC's HTML5 KVM viewer, which was designed for human eyes and human hands in a browser.

mcxBMCView bridges that gap. It gives your automation **deterministic, scriptable access** to the physical console:

- **`getscreen`** — captures the server's physical display as a JPEG. Pipe it to your vision model. Exit code 0 = success, 1 = failure. Screenshot path on stdout.
- **`sendkeys`** — types keyboard input into the server console. Login sequences, BIOS navigation, boot menu selections — anything a human would type.
- **`flashbios`** — reflashes the BIOS via the BMC's SPI bus. Works even when the host CPU is completely dead — the ASPEED chip writes to the flash chip directly, independent of the host.
- **`power`** — `ipmitool chassis power` wrapper with confirm-gate friction. Read-only ops (status/sensors/sel) require no confirmation; destructive ops (on/off/cycle/reset) require `--confirm`. Logs every invocation to a per-day JSONL audit file. PHP + ipmitool — no Playwright dependency.
- **`idrac6`** — the same for iDRAC6-era BMCs, whose web interface is TLS 1.0-only and whose console is a Java viewer: credential check, power state, and a console screenshot through the BMC's own viewer under Xvfb. See [iDRAC6-era BMCs](#idrac6-era-bmcs).

All tools authenticate, act, clean up, and exit. Fully stateless. The Playwright-based tools (`getscreen`, `sendkeys`, `flashbios`) depend on Node.js + Playwright; `power` depends only on `ipmitool`.

## Agent Integration

These tools are designed for LLM/AI agent workflows:

- **Deterministic output** — screenshot path on stdout, debug on stderr (`BMC_DEBUG=1`). Parse with any language.
- **Simple exit codes** — 0 success, 1 failure. No ambiguous states.
- **Stateless** — each invocation is independent. No session management needed on your side.
- **Composable** — chain `getscreen` → vision model → `sendkeys` to build autonomous server recovery loops.

Example agent loop:
```bash
# Capture → analyze → act → repeat
./getscreen 10.0.0.100 /tmp/screen.jpg
# Feed /tmp/screen.jpg to vision model, get next action
./sendkeys 10.0.0.100 "root{Enter}{Delay:2000}password{Enter}"
```

## Compatibility

Tested on AMI MegaRAC BMCs with ASPEED video engine:

| Board | Firmware | Status |
|-------|----------|--------|
| ASRock Rack EPYCD8-2T | 2.20.00 | Works |
| ASRock Rack (various) | 1.10, 1.24 | Works |
| Gigabyte 1U12XL-EPYC | AMI MegaRAC | Expected to work (same firmware family) |

Other BMC vendors (Supermicro, iLO, newer iDRAC) use entirely different web interfaces and are **not supported** by the HTML5 tools. Supermicro boards use different login form selectors and some have TLS cipher incompatibilities with modern Chromium — this is not a trivial adaptation. **iDRAC6-era BMCs** (Dell iDRAC6 and Avocent-based boards with the same firmware, web server `Mbedthis-Appweb`) have their own tool, [`idrac6`](#idrac6-era-bmcs).

## Install

### Ubuntu 24.04 LTS (quick start)

```bash
# Prerequisites — Node.js 18+ ships with Ubuntu 24.04
sudo apt update && sudo apt install -y nodejs npm git

# Clone and install
git clone https://github.com/MagnaCapax/mcxBMCView.git
cd mcxBMCView
npm install

# Chromium needs system libraries (libnss3, libgbm1, etc.) — requires sudo
sudo npx playwright install-deps chromium

# Download standalone Chromium (~250 MB → ~/.cache/ms-playwright/)
npx playwright install chromium
```

### Verify it works

```bash
node -e "const{chromium}=require('playwright');(async()=>{const b=await chromium.launch();console.log('OK');await b.close()})()"
```

Should print `OK`. If it prints dependency errors, re-run `sudo npx playwright install-deps chromium`.

### What gets installed where

| What | Where | Size |
|------|-------|------|
| Playwright npm package | `./node_modules/` | ~30 MB |
| Standalone Chromium | `~/.cache/ms-playwright/chromium-*/` | ~250 MB |
| System shared libraries | `/usr/lib/` (via apt) | ~5 MB |

No global npm packages. No system browser touched. Everything self-contained.

### Other distros

The install-deps step is Debian/Ubuntu-specific. On other distros, install these packages manually:

```
libnss3 libnspr4 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libdbus-1-3
libatspi2.0-0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1
libxkbcommon0 libpango-1.0-0 libcairo2 libasound2
```

### Troubleshooting

**`npx: command not found`** — `sudo apt install npm` (npm includes npx)

**`EACCES permission denied`** — don't `sudo npm install` for project deps. Only `sudo` for system deps (`install-deps`).

**Network access** — the tools connect to BMCs on HTTPS port 443. Ensure your management network is reachable.

## Usage

Credentials come from environment variables — no built-in defaults, nothing hardcoded:

```bash
export BMC_USER=admin
export BMC_PASS=yourpassword
```

When `BMC_USER` is unset, `power` and `getscreen` try `BMC_USER_CANDIDATES` in order (default `admin root`: AMI boards use `admin`, many older boards `root`).

**Changing the password across many BMCs.** Set the new password as `BMC_PASS` and the old one as `BMC_PASS_PREVIOUS`. `power` and `getscreen` try `BMC_PASS` first, then `BMC_PASS_PREVIOUS`, and print a warning (and `power` writes an audit row) whenever a BMC still accepts only the old one. Unset `BMC_PASS_PREVIOUS` once every BMC is changed.

**Defaults file.** Unset variables can come from `~/.config/mcxbmcview/defaults` (or the file named by `BMC_DEFAULTS_FILE`): one `KEY=VALUE` per line, for `BMC_USER`, `BMC_PASS`, `BMC_PASS_PREVIOUS`, `BMC_USER_CANDIDATES`, `BMC_LOG_DIR`, `BMC_ADDRESS_PREFIX`, `BMC_HOST_PREFIX_MAP`, `BMC_SESSION_VAR`, `NODE_PATH` and `IDRAC6_JAVA`. The environment always wins, nothing in the file is executed, and the file is ignored unless it is yours and not writable by group or others (`chmod 600`). `BMC_ADDRESS_PREFIX` makes every tool refuse an address that does not start with it, which keeps a typo from sending your password to the wrong machine.

### Capture Screenshot

```bash
./getscreen [--wait MS] <bmc-ip> [output.jpg]
# Returns path to JPEG on stdout. Exit 0 = success, 1 = failure (the reason on stderr).

./getscreen 10.0.0.100
# → /tmp/bmc-10.0.0.100.jpg

./getscreen --wait 30000 10.0.0.100  # wait up to 30s for video frame
BMC_DEBUG=1 ./getscreen 10.0.0.100   # progress on stderr
```

**About render timing:** ASPEED BMC video encoders can take surprisingly long to deliver the first frame — especially after boot, resolution changes (BIOS → OS → initramfs), or fresh KVM connections. The tool polls the KVM canvas for non-black pixel content every 200ms and captures as soon as a frame appears. If no frame arrives within `--wait` (default: 5000ms), it captures anyway with a warning on stderr.

In production incidents, a black screenshot does **not** mean the screen is black. If you get a black capture, increase `--wait`. Waiting even 60 seconds is better than misdiagnosing a server as dead based on a false black screen.

**Which BMCs:** `getscreen` reads the BMC's web root first. A Supermicro ATEN BMC gets its read-only console preview (no browser needed; ImageMagick converts the image). An iDRAC6-era BMC (`Server: Mbedthis-Appweb`) goes to [`idrac6`](#idrac6-era-bmcs) when `IDRAC6_JAVA` is set. An old AMI board whose only console is the Java JViewer gets the HTML5 attempt and a failure that says so. Everything else uses the HTML5 KVM viewer.

### Send Keyboard Input

```bash
./sendkeys <bmc-ip> <keys>

./sendkeys 10.0.0.100 "root{Enter}"              # type + Enter
./sendkeys 10.0.0.100 "{Ctrl+Alt+Delete}"         # reboot
./sendkeys 10.0.0.100 "{F2}"                      # enter BIOS
./sendkeys 10.0.0.100 "root{Enter}{Delay:2000}password{Enter}"  # login sequence
```

Key notation: plain text typed as-is, `{Enter}` `{Tab}` `{Escape}` `{F1}`-`{F12}` for special keys, `{Ctrl+Alt+Delete}` for combos, `{Delay:ms}` for waits.

### Reflash BIOS

```bash
./flashbios <bmc-ip> <rom-file>

./flashbios 10.0.0.100 /path/to/BIOS.rom
# Prints BIOS_FLASH_OK on success. Exit 0 = success, 1 = failure.

BMC_DEBUG=1 ./flashbios 10.0.0.100 /path/to/BIOS.rom   # progress on stderr
```

The BMC REST API does not support BIOS updates (error 1010 on firmware <=1.80). This tool drives the browser-based BIOS Update page instead. The ASPEED BMC chip writes directly to the SPI flash chip on its own power domain — **this works even when the host CPU is completely dead** (no POST, no screen output).

Environment variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `BMC_FLASH_MODE` | `immediate` | `immediate` — flash now (use when CPU is dead); `next_boot` — flash after shutdown; `shutdown` — shutdown host then flash |
| `BMC_SCREENSHOT` | off | Set to `1` to save screenshots at each phase |
| `BMC_TIMEOUT_S` | `900` | Overall timeout in seconds |

Key implementation details for anyone reading the source:
- Route is `#maintenance/bios_update` (underscore) — `#maintenance/bios-update` (hyphen) silently loads an empty pane
- Two file inputs exist: `#file_PublicKey` (hidden, wrong) and `#fileBIOS_image` (visible, correct)
- Two confirmation dialogs are auto-accepted during the flash flow
- `shutdwon_host_to_flash` — the typo in the radio button ID is real, present in the BMC firmware
- Typical 32MB ROM flash takes ~2 minutes

### Power

```bash
./power status 10.0.0.100                     # also: sensors, sel
./power cycle 10.0.0.100 --confirm --host 192.0.2.10 --reason "hung at boot"
./power soft 10.0.0.100 --confirm             # ACPI power button: clean OS shutdown
./power --help
```

Destructive actions need `--confirm` and go to a JSON Lines audit log (`BMC_LOG_DIR`, default `~/.local/state/mcxbmcview`). With `--host` (or `BMC_HOST_PREFIX_MAP`), `off`, `cycle` and `reset` first ask the server's sshd for a banner: if the OS answers, the action is refused (exit 4) so a running system is shut down over SSH instead; `--host-up-ok` with `--reason` overrides. Each log row carries a session: `SESSION_ID`, or the variable named by `BMC_SESSION_VAR` (for example your job runner's run id), else `interactive-$USER`. The password reaches `ipmitool` through `IPMI_PASSWORD`, never its command line.

### iDRAC6-era BMCs

`idrac6` covers Dell iDRAC6 and the Avocent-based boards that share its firmware. Their web interface speaks only SSLv3/TLS 1.0, and their console is a Java viewer that the BMC serves, so the Playwright tools cannot reach them ([ADR-0002](docs/adr/0002-idrac6-legacy-tls-transport-and-java-viewer.md)).

```bash
export BMC_USER=root BMC_PASS='...'
./idrac6 10.0.0.120 auth-test        # ok = the credentials work
./idrac6 10.0.0.120 power-status     # on | off
./idrac6 10.0.0.120 console-check    # login, console tokens and viewer jars, without starting Java
IDRAC6_JAVA=/opt/java8-i686/bin/java ./idrac6 10.0.0.120 screenshot /tmp/console.png
```

The web side uses the system `curl` with `lib/idrac6/openssl-legacy.cnf` (OpenSSL 3 keeps the old protocols, disabled by default). It needs only `php` and `curl`. Every run logs out, because these BMCs keep only a few web sessions.

`screenshot` runs the BMC's own Avocent viewer headless. Its native library is 32-bit, so it needs a **32-bit Java 8** (for example an i686 build of Azul Zulu 8) named in `IDRAC6_JAVA`, plus the 32-bit X libraries:

```bash
sudo dpkg --add-architecture i386 && sudo apt update
sudo apt install -y xvfb x11-xkb-utils imagemagick unzip libx11-6:i386 libxext6:i386 libxrender1:i386 libxtst6:i386 libxi6:i386 libfreetype6:i386
```

The tool does not download Java and runs the viewer the BMC serves: the jars come from the BMC over plain HTTP (`/software/`) and their signatures are not checked, so whoever can answer for that BMC address can run code there. Run it on a machine where that is acceptable, ideally one kept for this. The console token from viewer.jnlp is on the java command line, visible in `ps`, while the viewer runs. The console is view-only: the headless viewer does not forward keystrokes. Use `power` for power actions.

## How It Works

The BMC serves its KVM viewer as an HTML5 SPA. No server-side screenshot API exists on this firmware. The only way to capture the screen is to establish a real KVM session through the browser.

1. Playwright drives headless Chromium to the BMC login page
2. Authenticates via the web form (`#userid`, `#password`, Enter)
3. Waits ~6s for the SPA to populate sessionStorage (12+ fields — the `features` field is last)
4. Opens `viewer.html` via `window.open()` — browser clones sessionStorage to the popup
5. The viewer establishes a WebSocket KVM session using ASPEED video compression
6. `getscreen` captures the viewport; `sendkeys` dispatches keyboard events
7. Cleanup: stops KVM, closes viewer, deletes the API session

API-based login with cookie injection does not work. The WebSocket handshake validates session state that only the full SPA login flow establishes correctly. This was discovered through methodical analysis of the firmware's authentication chain.

## Session Management

The BMC allows ~4-5 concurrent web sessions. Each tool run creates and cleans up one. If sessions are exhausted (blank 6KB screenshots):

```bash
IPMI_PASSWORD="$BMC_PASS" ipmitool -I lanplus -H <bmc-ip> -U <user> -E mc reset cold   # -E: password from the environment, not the process list
# ~2 minutes for BMC to return
```

## Limitations

- **~25s per operation** — inherent to browser automation + BMC session setup
- **Single KVM session** — BMC allows one active viewer at a time
- **AMI MegaRAC only** — other vendors have different interfaces entirely
- **Self-signed TLS** — certificate validation is disabled (BMCs use self-signed certs)
- **No server-side screenshot API** — this firmware version has no REST endpoint for live capture
- **First-character drop** — the first character sent right after a KVM connect is sometimes lost by the BMC before the KVM canvas has focus. Prefix commands with a harmless `{Enter}` or a throwaway character then `{BackSpace}` to absorb the drop. Pattern that works: `"{Enter}{Delay:500}<real command>{Enter}"` or `"xxx{BackSpace}{BackSpace}{BackSpace}<real command>{Enter}"`.
- **Keyboard layout follows the host** — sendkeys delivers Playwright key events; the guest OS interprets them through its active keymap. If the host is set to a non-US layout (e.g. Finnish, German), characters like `/` `-` `|` `@` can map to different printable characters. Verify with a test send (`echo test` then screenshot) before sending critical commands. Finnish keymap: `/` is `{Shift+7}`, `|` is `{AltGraph+<}`, `@` is `{AltGraph+2}`. When scripting a recovery, prefer ASCII-only commands or use `$HOME`/shell variables to avoid path separators in sendkeys strings.

## Attribution

Author: Aleksi Ursin / MCX (with Väinämöinen) — [Pulsed Media](https://pulsedmedia.com)

Developed through systematic analysis of AMI MegaRAC firmware behavior — five failed approaches before discovering the working authentication flow.

> *"If three words are missing, we fetch them."*

## Legal Notice

These tools automate standard browser interaction with BMC web interfaces served over HTTPS. No proprietary code is copied, decompiled, or distributed. No encryption or access controls are circumvented — the tools authenticate with valid credentials through the normal login form.

Developed through observation and testing of publicly-served web interfaces, exercising interoperability rights under:
- **DMCA Section 1201(f)** — reverse engineering for interoperability
- **EU Directive 2009/24/EC Articles 5(3) and 6** — observation, study, and interoperability
- **US Copyright Fair Use** — transformative tool creation (*Google v. Oracle*, *Sega v. Accolade*)

No AMI, ASRock, ASPEED, or other vendor code is included. "MegaRAC" is a trademark of American Megatrends International LLC. "ASRock" is a trademark of ASRock Inc. This project is not affiliated with or endorsed by these companies.

## License

Apache-2.0 — see [LICENSE](LICENSE).
