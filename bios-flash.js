// BIOS reflash via Playwright for AMI MegaRAC BMC
// Usage: node bios-flash.js <host> <user> <pass> <rom-file>
//
// The BMC REST API does not support BIOS updates (error 1010 on fw <=1.80).
// This script drives the browser-based SPA: login → navigate to BIOS Update →
// upload ROM → accept confirmations → monitor flash progress.
//
// The ASPEED BMC chip has direct SPI bus access to the BIOS flash chip on its
// own power domain. BIOS can be reflashed even when the host CPU is dead.
//
// Auth: Same browser login as kvm-capture.js — API cookie injection doesn't work.
// Flash modes: BMC_FLASH_MODE=immediate|next_boot|shutdown (default: immediate)

const { chromium } = require('playwright');
const path = require('path');

const [host, user, pass, romFile] = process.argv.slice(2);
if (!host || !user || !pass || !romFile) {
    console.error('Usage: node bios-flash.js <host> <user> <pass> <rom-file>');
    process.exit(1);
}
if (!/^[\w.\-:]+$/.test(host)) {
    console.error('Invalid host: must be IP address or hostname');
    process.exit(1);
}

const log = process.env.BMC_DEBUG ? (m => console.error(`[${new Date().toISOString()}] ${m}`)) : (() => {});
const SCREENSHOTS = !!process.env.BMC_SCREENSHOT;
const FLASH_MODE = (process.env.BMC_FLASH_MODE || 'immediate').toLowerCase();
const TIMEOUT_S = parseInt(process.env.BMC_TIMEOUT_S || '900', 10);

// Flash mode radio button IDs in the AMI MegaRAC BIOS Update page
const FLASH_MODE_MAP = {
    next_boot:  'flash_on_next_boot',     // value=1: flash after manual shutdown
    immediate:  'flash_on_the_fly',        // value=2: flash now, no power action
    shutdown:   'shutdwon_host_to_flash',  // value=3: shutdown then flash (BMC typo is real)
};
const flashRadioId = FLASH_MODE_MAP[FLASH_MODE];
if (!flashRadioId) {
    console.error(`Invalid BMC_FLASH_MODE="${FLASH_MODE}". Use: immediate, next_boot, shutdown`);
    process.exit(1);
}

const chromiumArgs = ['--ignore-certificate-errors'];
if (process.getuid && process.getuid() === 0) chromiumArgs.push('--no-sandbox');

(async () => {
    const browser = await chromium.launch({ args: chromiumArgs });
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    let stepNum = 0;
    const screenshot = async (label) => {
        if (!SCREENSHOTS) return;
        stepNum++;
        const f = `/tmp/bios-flash-${host}-step${stepNum}-${label}.jpg`;
        await page.screenshot({ path: f, type: 'jpeg', quality: 90, fullPage: true });
        log(`Screenshot: ${f}`);
    };

    // Auto-accept BMC confirmation dialogs (two appear during the flash flow)
    page.on('dialog', async (dialog) => {
        log(`Dialog [${dialog.type()}]: ${dialog.message()}`);
        await dialog.accept();
    });

    const deadline = Date.now() + TIMEOUT_S * 1000;
    const checkDeadline = () => {
        if (Date.now() > deadline) throw new Error(`Timeout (${TIMEOUT_S}s) exceeded`);
    };

    let garc = null;

    try {
        // Phase 1: Login (same flow as kvm-capture.js)
        log('Login...');
        await page.goto(`https://${host}/`, { waitUntil: 'networkidle', timeout: 30000 });
        await page.waitForSelector('#userid', { timeout: 10000 });
        await page.fill('#userid', user);
        await page.fill('#password', pass);
        await page.press('#password', 'Enter');

        for (let i = 0; i < 20; i++) {
            await page.waitForTimeout(1000);
            const ready = await page.evaluate(() =>
                sessionStorage.getItem('garc') && sessionStorage.getItem('features')
            );
            if (ready) { log(`Session ready after ${i+1}s`); break; }
        }
        garc = await page.evaluate(() => sessionStorage.getItem('garc'));
        if (!garc) { console.error('Login failed'); process.exit(1); }
        log('Logged in');
        await page.waitForTimeout(3000);

        // Phase 2: Navigate to BIOS Update
        // Route: #maintenance/bios_update (underscore, NOT hyphen — hyphen loads empty pane)
        log('Navigate to BIOS Update...');
        await page.click('a[href="#maintenance"]');
        await page.waitForTimeout(2000);
        await page.click('a[href="#maintenance/bios_update"]');
        await page.waitForTimeout(5000);
        await screenshot('bios-update-page');

        // Phase 3: Select flash mode
        log(`Flash mode: ${FLASH_MODE} (${flashRadioId})`);
        await page.evaluate((radioId) => {
            const radio = document.getElementById(radioId);
            if (radio) { radio.click(); radio.checked = true; }
        }, flashRadioId);
        await page.waitForTimeout(500);

        // Phase 4: Upload ROM
        // Two file inputs exist: #file_PublicKey (index 0, hidden — wrong) and
        // #fileBIOS_image (index 1, visible — correct)
        log('Upload ROM...');
        const fileInput = await page.$('#fileBIOS_image');
        if (!fileInput) {
            console.error('#fileBIOS_image not found — page may not have loaded');
            await screenshot('no-file-input');
            process.exit(1);
        }
        await fileInput.setInputFiles(path.resolve(romFile));
        await page.waitForTimeout(2000);

        const fileVal = await page.evaluate(() => {
            const inp = document.getElementById('fileBIOS_image');
            return inp ? { files: inp.files.length, value: inp.value } : null;
        });
        log(`File: ${JSON.stringify(fileVal)}`);
        await screenshot('file-selected');

        // Phase 5: Click Start
        log('Start BIOS update...');
        await page.click('#start');

        // Phase 6: Wait for upload 100% and click Proceed
        // After upload, page shows Current/New BIOS versions + Proceed/Cancel
        log('Waiting for upload + Proceed...');
        let proceedClicked = false;
        for (let i = 0; i < 120; i++) {
            checkDeadline();
            await page.waitForTimeout(5000);

            const state = await page.evaluate(() => {
                const body = document.body.innerText;
                const proceedBtn = [...document.querySelectorAll('button')].find(
                    b => b.textContent.trim() === 'Proceed'
                );
                const match = body.match(/Uploading\.\.\.\s*(\d+)%/);
                return {
                    uploadPercent: match ? parseInt(match[1]) : -1,
                    hasProceed: !!proceedBtn,
                    proceedVisible: proceedBtn ? proceedBtn.offsetParent !== null : false,
                };
            });

            if (i % 3 === 0) log(`Upload: ${state.uploadPercent}% proceed=${state.hasProceed}`);

            if (state.hasProceed && state.proceedVisible) {
                log('Upload complete — clicking Proceed');
                await screenshot('proceed-visible');
                await page.evaluate(() => {
                    const btn = [...document.querySelectorAll('button')].find(
                        b => b.textContent.trim() === 'Proceed'
                    );
                    if (btn) btn.click();
                });
                proceedClicked = true;
                log('Proceed clicked');
                await page.waitForTimeout(3000);
                await screenshot('after-proceed');
                break;
            }
        }
        if (!proceedClicked) {
            console.error('Proceed button never appeared');
            await screenshot('no-proceed');
            process.exit(1);
        }

        // Phase 7: Monitor flash progress (~2 min for 32MB ROM)
        log('Monitoring flash...');
        let result = 'timeout';
        for (let i = 0; i < 180; i++) {
            checkDeadline();
            await page.waitForTimeout(5000);

            const status = await page.evaluate(() => {
                const body = document.body.innerText;
                const spinner = document.getElementById('processing_layout');
                const flashMatch = body.match(/(?:Flash|Upgrad|Writ|Eras)(?:ing|e)?\.\.\.\s*(\d+)%/i);
                return {
                    processingVisible: spinner ? spinner.style.display !== 'none' : false,
                    flashPercent: flashMatch ? parseInt(flashMatch[1]) : -1,
                    hasComplete: /complet|success|finished|done/i.test(body),
                    hasError: /(?:^|[^a-z])(?:error|fail(?:ed|ure)?)\b/i.test(body),
                    hasStartButton: body.includes('Start BIOS update'),
                    hasProceed: body.includes('Proceed'),
                };
            });

            if (i % 3 === 0) log(`Flash: ${status.flashPercent}% complete=${status.hasComplete} error=${status.hasError}`);

            if (status.hasComplete && !status.processingVisible) {
                result = 'complete';
                break;
            }
            if (status.hasStartButton && !status.hasProceed && !status.processingVisible && i > 6) {
                result = 'complete'; // page auto-reset after flash
                break;
            }
            if (status.hasError && !status.processingVisible && i > 3) {
                result = 'error';
                break;
            }
        }

        await screenshot(`flash-${result}`);

        // Cleanup session
        try {
            await page.evaluate(async (token) => {
                await fetch('/api/session', { method: 'DELETE', headers: { 'X-CSRFTOKEN': token } });
            }, garc);
        } catch (_) {}

        if (result === 'complete') {
            console.log('BIOS_FLASH_OK');
            log('BIOS flash complete');
            process.exit(0);
        } else if (result === 'error') {
            console.error('BIOS flash failed');
            process.exit(1);
        } else {
            console.error('BIOS flash timed out');
            process.exit(1);
        }
    } catch (err) {
        console.error('Fatal:', err.message);
        await screenshot('fatal').catch(() => {});
        process.exit(1);
    } finally {
        await browser.close();
    }
})().catch(err => { console.error('Fatal:', err.message); process.exit(1); });
