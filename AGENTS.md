# mcxBMCView: notes for contributors and coding agents

## What this is

Command-line tools for the HTML5 KVM console of AMI MegaRAC BMCs: `getscreen`, `sendkeys`, `enterbios` and `flashbios` drive the BMC web interface with Playwright in Node.js; `power` wraps `ipmitool`. Each tool logs in, acts, cleans up and exits. [ADR-0001](docs/adr/0001-architecture-layout-and-languages.md) describes the layout and why the browser layer is Node.

Start with [`development/`](development/README.md): the [engineering principles](development/engineering-principles.md) explain every rule below with its reason and the mistake that taught it, and list the sibling repositories' docs and decisions.

## Principles

KISS, DRY, YAGNI. Delete first: question the requirement, delete the part, simplify, then speed up, then automate (a needed but broken part is debugged, not deleted). Pit of success: safe defaults, risky paths explicit and loud. Never break old users. Tests that can fail. Files around 150 lines (a target, not a hard limit). Accepted ADRs are binding: follow them or supersede them with a new ADR, never work around them. Before committing, review the change with the [review unit](https://github.com/MagnaCapax/mcxPikkumies/tree/main/pikkumies/tools/development/code-review) against these rules and the ADRs.

## Rules

- **Language:** the browser layer (`*.js`) is Node.js with Playwright, the recorded exception in ADR-0001. Everything else follows the organisation rule: logic in PHP, shell only as thin glue (about 20 lines, no logic), no Python. Logic in an existing shell wrapper moves to PHP when that wrapper is changed beyond a small fix.
- **Naming:** camelCase for new variables, functions and files, read from the broadest context to the most specific. Existing file names and command names stay.
- **Interface:** command names, arguments, the result on stdout, diagnostics on stderr (`BMC_DEBUG=1`) and the exit codes are what other automation relies on. Keep them working.
- **Credentials** come from the environment only (`BMC_USER`, `BMC_PASS`). No real or default password in code or docs.
- **Destructive actions:** a new destructive action (power change, reboot) needs an explicit option and says what it does, as `power --confirm` and `enterbios --reboot` do.
- **Sessions:** every browser tool deletes its BMC web session before it exits; the BMC allows only a few.
- **ADRs:** a significant behaviour, interface, security or workflow change ships with an ADR in `docs/adr/` (template `0000-template.md`, one subject per ADR).
- **Tests:** `node --check` and `bash -n` on changed files, then a run against a real BMC with `BMC_DEBUG=1`, described in the commit. New logic that runs without a BMC gets a test that fails when the logic is broken.
- **Public repository.** Nothing that is not meant for the public: no credentials, no internal hostnames, addresses, networks or paths, no customer data, no model provider or model names. Screenshots stay out of git.
