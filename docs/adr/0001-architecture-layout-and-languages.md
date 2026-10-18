# ADR-0001: Architecture, layout and languages

## Metadata
- Author: Aleksi Ursin / MCX
- Date: 2026-10-18
- Status: Accepted

## Context
mcxBMCView gives scripts and automation access to a server's physical console through the HTML5 KVM viewer of AMI MegaRAC BMCs: capture the screen, type keys, enter the BIOS during POST and reflash the BIOS, plus a chassis power wrapper. Each tool is a command that logs in, acts, cleans up and exits (see the [README](../../README.md)).

The BMC firmware has no server-side screenshot or keyboard API on the supported versions, and its REST API does not support BIOS updates (README, "How It Works" and "Reflash BIOS"). The only working route is the browser one: log in through the web form, let the single-page app fill `sessionStorage`, open `viewer.html` with `window.open()` so the session is cloned into it, and let the viewer open its WebSocket KVM session. The code records that API login with cookie injection is rejected at the WebSocket handshake (`kvm-capture.js`, header comment). So the tools need a real browser engine that runs the BMC's own JavaScript, reads the KVM canvas and sends keyboard events.

The engineering principles say logic is written in PHP and shell is thin glue (principle 1). This record says where the repository follows that, where it cannot, and why.

## What is here

Tracked files on 2026-10-18 (`node_modules/` is installed by `npm install` and ignored):

| Path | What it owns | Language |
|---|---|---|
| `kvm-capture.js` | Logs in, opens the KVM viewer, polls the canvas until a frame with content appears or the wait runs out, saves a JPEG. | JavaScript (Node) |
| `kvm-sendkeys.js` | Logs in, opens the viewer, parses the key notation (`{Enter}`, `{Ctrl+Alt+Delete}`, `{Delay:ms}`) and sends the keys. | JavaScript (Node) |
| `kvm-enterbios.js` | Keeps one KVM session open, polls the canvas during POST and sends BIOS entry keys when content appears; optional screenshot journal. | JavaScript (Node) |
| `bios-flash.js` | Drives the BMC's BIOS Update page: upload the ROM, accept the confirmations, follow progress. | JavaScript (Node) |
| `getscreen`, `sendkeys`, `enterbios`, `flashbios` | Command-line entry points. They check arguments and the `BMC_USER`/`BMC_PASS` environment, then run the matching Node script. `getscreen` and `enterbios` also parse options; `enterbios --reboot` sends an IPMI power cycle first. | Bash |
| `power` | IPMI chassis power wrapper around `ipmitool`: read-only actions run directly, destructive ones need `--confirm` and are written to a JSON Lines audit log. No browser involved. | Bash |
| `package.json`, `package-lock.json` | The one dependency, Playwright, and the Node engine requirement (`>=18.0.0`). | JSON |

## Options Considered
- **Browser layer in PHP.** Rejected on capability. The browser driver this code is built on, Playwright, ships official bindings for Node.js, Python, Java and .NET, not for PHP. A PHP WebDriver client would add a separate driver service to run beside the browser, and would mean rewriting a login flow that took several failed approaches to find (README, "Attribution") and is listed as working on the boards in the README's compatibility table. Principles 5 and 7 weigh against that rewrite.
- **Browser layer in Python.** Rejected: no Python (principle 1).
- **Node.js with Playwright for the browser layer, everything else by principle 1 (chosen).**

## Decision

### Languages
- **The browser layer is Node.js with Playwright. This is the repository's stack exception** under principle 1: it needs a scripted real browser that runs the BMC's single-page app, and the driver used has no PHP binding. The reason is a capability, not a preference. It covers the `*.js` files and nothing else.
- **Logic that does not need the browser follows principle 1**: PHP, with shell only as thin glue. The `power` wrapper (about 160 lines of argument parsing, address validation, hand-built JSON audit lines and the confirm gate) has no browser dependency, so when it is next changed beyond a small fix, its logic moves to PHP and the shell part shrinks to an exec line. The wrappers' option parsing and the IPMI call in `enterbios` go the same way when those wrappers are changed. Until then they stay as they are.
- **The command-line wrappers stay shell**: check the environment, start `node` on the matching script, map the result to stdout and the exit code. About 20 lines, no logic.
- No Python, no new Perl.

### Layout
- One Node script per tool at the repository root and one wrapper per tool, named as the command the user types. The existing scripts are named `kvm-<action>.js` or `<subject>-<action>.js`; new ones are camelCase (principle 3).
- No shared module yet. The login and session steps (form login, wait for `sessionStorage`, read the CSRF token, delete the session at the end) are repeated in all four Node scripts. Principle 4 (DRY) applies: the next change to that flow moves it into one shared module that all four use, in that same change.

### Build, run and test
- Install: `npm install`, then `npx playwright install chromium` (and `sudo npx playwright install-deps chromium` on Debian and Ubuntu), as the README describes.
- Run: the wrappers, with `BMC_USER` and `BMC_PASS` in the environment and `BMC_DEBUG=1` for progress on stderr. The result goes to stdout; exit code 0 is success, 1 is failure.
- Test: there is no automated test suite and `package.json` has no test script. Local checks are `node --check` on the JavaScript and `bash -n` on the wrappers. Behaviour is checked against a real BMC. Logic that can be separated from the browser (key notation parsing, argument handling) should get tests that run without one when it is next changed.

### Existing conventions (as found)
- The browser tools take credentials only from the environment and have no defaults.
- Each Node script validates the host argument against a strict pattern before building a URL with it, so a crafted host cannot send the credentials elsewhere (`kvm-capture.js` says so in a comment).
- Results on stdout, diagnostics on stderr, silenced unless `BMC_DEBUG=1`.
- Every Node tool deletes its BMC web session at the end (`DELETE /api/session`), because the BMC allows only a few concurrent web sessions (README, "Session Management").
- JavaScript functions and local variables are camelCase (`parseKeys`, `sendBiosKeys`); module-level constants are UPPER_SNAKE_CASE (`KEY_MAP`, `FLASH_MODE`). File names are kebab-case.
- Comments explain firmware quirks the code depends on (the underscore route name, the misspelled radio button ID, the two file inputs); see `bios-flash.js` and the README.
- License: Apache-2.0 (`LICENSE`, `package.json`).

### How the principles apply here
- **1, PHP for logic:** as above, with the Node browser layer as the recorded exception.
- **2, files around 150 lines:** the Node scripts are 170 to 245 lines, each with one job. Split one when it grows or gains a second job; extracting the shared login flow is the first split.
- **3, names broad to specific in camelCase:** new JavaScript and PHP names are camelCase and read from the broadest context to the most specific. New code files are camelCase too. Existing kebab-case file names and the published command names stay as they are (principle 7).
- **4 and 5, KISS, DRY, YAGNI, delete first:** one dependency (Playwright), no framework. Do not add a tool the BMC's own interface or `ipmitool` already gives.
- **6, pit of success:** destructive actions are explicit: `power` refuses on, off, cycle and reset without `--confirm`, and `enterbios` power-cycles only with `--reboot`. New destructive actions follow that pattern.
- **7, never break old users:** the command names, their arguments, the stdout result and the exit codes are the interface other automation relies on (README, "Agent Integration"). Keep them working.
- **8, tests that can fail:** see "Build, run and test"; a test must be able to fail.
- **9, ADRs:** a significant behaviour, interface, security or workflow change ships with an ADR in `docs/adr/`.
- **10, fail soft and explain why:** a capture that sees no frame in time still saves the screenshot and warns on stderr rather than failing (README, "About render timing"). Comments record why the waits and firmware workarounds exist.

## Consequences
- The repository has two runtimes, Node for the browser layer and the shell or PHP side for the rest. The split is by capability and is written down here.
- The `power` wrapper and the option parsing in the wrappers are known departures from principle 1, to be moved to PHP when changed.
- Tests depend on hardware until the browser-free logic is separated out.

## References
- [README](../../README.md)
- [Engineering principles](../../development/engineering-principles.md)
- Playwright language bindings: https://playwright.dev/docs/languages
