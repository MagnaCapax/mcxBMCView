// Persistent KVM session for deterministic BIOS entry
// Stays connected to the BMC KVM and polls canvas rapidly for POST detection.
// On detecting non-black content, immediately sends BIOS entry keys.
//
// Usage: node kvm-enterbios.js <host> <user> <pass> <timeout_s> [journal_dir]

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const [host, user, pass, timeoutStr, journalDir] = process.argv.slice(2);
if (!host || !user || !pass) {
    console.error('Usage: node kvm-enterbios.js <host> <user> <pass> <timeout_s> [journal_dir]');
    process.exit(1);
}

if (!/^[\w.\-:]+$/.test(host)) {
    console.error('Invalid host');
    process.exit(1);
}

const timeout = parseInt(timeoutStr || '600', 10) * 1000;
const log = (m) => console.error(`[${new Date().toTimeString().slice(0, 8)}] ${m}`);

if (journalDir && !fs.existsSync(journalDir)) {
    fs.mkdirSync(journalDir, { recursive: true });
}

const chromiumArgs = ['--ignore-certificate-errors'];
if (process.getuid && process.getuid() === 0) chromiumArgs.push('--no-sandbox');

// BIOS entry key sequences — covers major vendors
const BIOS_KEYS = [
    'F2',      // ASRock, ASUS, Intel
    'Delete',  // ASRock, MSI, Gigabyte, EVGA
    'F2',      // repeat for reliability
    'Delete',
    'F11',     // ASRock boot menu
    'F12',     // many vendors boot menu
];

(async () => {
    const browser = await chromium.launch({ args: chromiumArgs });
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    try {
        // Login
        log('Logging in to BMC...');
        await page.goto(`https://${host}/`, { waitUntil: 'networkidle', timeout: 20000 });
        await page.waitForSelector('#userid', { timeout: 10000 });
        await page.fill('#userid', user);
        await page.fill('#password', pass);
        await page.press('#password', 'Enter');

        // Wait for session
        log('Waiting for session...');
        for (let i = 0; i < 15; i++) {
            await page.waitForTimeout(1000);
            const ready = await page.evaluate(() =>
                sessionStorage.getItem('garc') && sessionStorage.getItem('features')
            );
            if (ready) { log(`Session ready after ${i + 1}s`); break; }
        }

        const garc = await page.evaluate(() => sessionStorage.getItem('garc'));
        if (!garc) { log('Login failed'); process.exit(1); }

        // Open KVM viewer
        log('Opening KVM viewer...');
        const popupPromise = context.waitForEvent('page', { timeout: 10000 });
        await page.evaluate(() => { window.open('viewer.html', '_blank'); });
        const viewer = await popupPromise;
        await viewer.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

        // Wait for KVM to connect
        let kvmReady = false;
        for (let i = 0; i < 15; i++) {
            const toggleText = await viewer.$eval('#toggle', el => el.textContent).catch(() => '');
            if (toggleText.includes('Stop')) { kvmReady = true; log('KVM connected'); break; }
            if (i === 0 && toggleText.includes('Start')) {
                await viewer.click('#toggle').catch(() => {});
            }
            await viewer.waitForTimeout(1000);
        }

        if (!kvmReady) { log('KVM failed to connect'); process.exit(1); }

        // Helper: check if canvas has non-black content
        const hasContent = async () => {
            return viewer.evaluate(() => {
                const canvas = document.querySelector('canvas#kvm') || document.querySelector('canvas');
                if (!canvas) return false;
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                if (!ctx) return false;
                const w = canvas.width, h = canvas.height;
                if (w === 0 || h === 0) return false;
                const strips = [0.1, 0.3, 0.5, 0.7, 0.9];
                for (const yPct of strips) {
                    const sy = Math.floor(h * yPct);
                    const sx = Math.floor(w * 0.1);
                    const sw = Math.floor(w * 0.8);
                    const data = ctx.getImageData(sx, sy, sw, 1).data;
                    for (let j = 0; j < data.length; j += 40 * 4) {
                        if (data[j] > 5 || data[j + 1] > 5 || data[j + 2] > 5) return true;
                    }
                }
                return false;
            }).catch(() => false);
        };

        // Helper: send BIOS entry keys
        const sendBiosKeys = async () => {
            for (const key of BIOS_KEYS) {
                await viewer.keyboard.press(key).catch(() => {});
                await viewer.waitForTimeout(200);
            }
        };

        // Helper: save screenshot
        let frameNum = 0;
        const saveScreenshot = async (label) => {
            frameNum++;
            const ts = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
            let outPath;
            if (journalDir) {
                outPath = path.join(journalDir, `${ts}-frame${frameNum}-${label}.jpg`);
            } else {
                outPath = `/tmp/enterbios-${host}-${frameNum}-${label}.jpg`;
            }
            await viewer.screenshot({ path: outPath, type: 'jpeg', quality: 90 });
            return outPath;
        };

        // === Main loop: poll canvas, detect POST, send keys ===
        log(`Polling canvas every 1s (timeout: ${timeout / 1000}s)...`);
        const startTime = Date.now();
        let postDetected = false;
        let keyBatches = 0;

        while (Date.now() - startTime < timeout) {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            const detected = await hasContent();

            if (!detected) {
                // Black screen — still in reboot/RAM check
                if (elapsed % 15 === 0) {
                    log(`${elapsed}s: black screen — waiting for POST...`);
                    if (journalDir && elapsed % 60 === 0) await saveScreenshot('black');
                }
                await viewer.waitForTimeout(1000);
                continue;
            }

            // Content detected!
            if (!postDetected) {
                postDetected = true;
                log(`${elapsed}s: POST DETECTED — content on screen! Sending BIOS entry keys...`);
                const img = await saveScreenshot('post-detected');
                log(`Screenshot: ${img}`);
            }

            // Send BIOS keys immediately
            await sendBiosKeys();
            keyBatches++;
            log(`${elapsed}s: sent BIOS key batch ${keyBatches}`);

            // Brief pause then check again
            await viewer.waitForTimeout(800);

            // After several batches, save a checkpoint screenshot
            if (keyBatches % 5 === 0) {
                const img = await saveScreenshot('checkpoint');
                log(`${elapsed}s: checkpoint screenshot: ${img}`);
            }

            // After 20 batches (~30s of key sending), we've either entered BIOS or missed it
            if (keyBatches >= 20) {
                const finalImg = await saveScreenshot('final');
                log(`Sent ${keyBatches} key batches. Final screenshot: ${finalImg}`);
                log('Check screenshot to verify BIOS entry. If OS login shown, reboot and retry.');
                console.log(finalImg);
                break;
            }
        }

        if (!postDetected) {
            log(`TIMEOUT: No POST detected after ${timeout / 1000}s`);
            const img = await saveScreenshot('timeout');
            log(`Final screenshot: ${img}`);
            process.exitCode = 1;
        }

        // Cleanup
        try {
            await viewer.evaluate(() => {
                const t = document.getElementById('toggle');
                if (t && t.textContent.includes('Stop')) t.click();
            });
            await new Promise(r => setTimeout(r, 1000));
        } catch (e) {}
        try { await viewer.close(); } catch (e) {}
        try {
            await page.evaluate(async (token) => {
                await fetch('/api/session', { method: 'DELETE', headers: { 'X-CSRFTOKEN': token } });
            }, garc);
        } catch (e) {}
    } catch (err) {
        log(`Error: ${err.message}`);
        process.exit(1);
    } finally {
        await browser.close();
    }
})().catch(err => {
    log(`Fatal: ${err.message}`);
    process.exit(1);
});
