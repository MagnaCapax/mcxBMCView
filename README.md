# mcxBMCView

> *"Wondrous things might be constructed from the relics of this monster."*
> — Kalevala, Runo XL

Deterministic screenshot capture and keyboard input for AMI MegaRAC BMC HTML5 KVM consoles. **Built for AI agent integration** and headless server management — no human browser needed.

## Why This Exists

AI agents managing bare-metal servers hit a wall when SSH is gone: BIOS screens, boot failures, kernel panics, OS install wizards — all locked behind the BMC's HTML5 KVM viewer, which was designed for human eyes and human hands in a browser.

mcxBMCView bridges that gap. It gives your automation **deterministic, scriptable access** to the physical console:

- **`getscreen`** — captures the server's physical display as a JPEG. Pipe it to your vision model. Exit code 0 = success, 1 = failure. Screenshot path on stdout.
- **`sendkeys`** — types keyboard input into the server console. Login sequences, BIOS navigation, boot menu selections — anything a human would type.

Both tools authenticate, establish a KVM session, act, clean up, and exit. Fully stateless. One dependency: Playwright.

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

Other BMC vendors (Supermicro, iDRAC, iLO) use entirely different web interfaces and are **not supported**. Supermicro boards use different login form selectors and some have TLS cipher incompatibilities with modern Chromium — this is not a trivial adaptation.

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

Credentials via environment variables — no defaults, no hardcoding:

```bash
export BMC_USER=admin
export BMC_PASS=yourpassword
```

### Capture Screenshot

```bash
./getscreen <bmc-ip> [output.jpg]
# Returns path to JPEG on stdout. Exit 0 = success, 1 = failure.

./getscreen 10.0.0.100
# → /tmp/bmc-10.0.0.100.jpg

BMC_DEBUG=1 ./getscreen 10.0.0.100   # progress on stderr
```

### Send Keyboard Input

```bash
./sendkeys <bmc-ip> <keys>

./sendkeys 10.0.0.100 "root{Enter}"              # type + Enter
./sendkeys 10.0.0.100 "{Ctrl+Alt+Delete}"         # reboot
./sendkeys 10.0.0.100 "{F2}"                      # enter BIOS
./sendkeys 10.0.0.100 "root{Enter}{Delay:2000}password{Enter}"  # login sequence
```

Key notation: plain text typed as-is, `{Enter}` `{Tab}` `{Escape}` `{F1}`-`{F12}` for special keys, `{Ctrl+Alt+Delete}` for combos, `{Delay:ms}` for waits.

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
ipmitool -I lanplus -H <bmc-ip> -U <user> -P <pass> mc reset cold
# ~2 minutes for BMC to return
```

## Limitations

- **~25s per operation** — inherent to browser automation + BMC session setup
- **Single KVM session** — BMC allows one active viewer at a time
- **AMI MegaRAC only** — other vendors have different interfaces entirely
- **Self-signed TLS** — certificate validation is disabled (BMCs use self-signed certs)
- **No server-side screenshot API** — this firmware version has no REST endpoint for live capture

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

MIT — see [LICENSE](LICENSE).
