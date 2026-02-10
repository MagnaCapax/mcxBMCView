// KVM screenshot capture via Playwright for AMI MegaRAC BMC
// Usage: node kvm-capture.js <host> <user> <pass> <output.jpg>
//
// Auth: Must login through browser form so window.open() clones sessionStorage.
// API-based login + cookie injection does NOT work (WebSocket handshake rejected).
// The SPA takes ~6s to populate all sessionStorage fields needed by the viewer.

const { chromium } = require('playwright');

const [host, user, pass, output] = process.argv.slice(2);
if (!host || !user || !pass || !output) {
    console.error('Usage: node kvm-capture.js <host> <user> <pass> <output.jpg>');
    process.exit(1);
}

// Validate host looks like an IP or hostname (prevent credential exfiltration via crafted URLs)
if (!/^[\w.\-:]+$/.test(host)) {
    console.error('Invalid host: must be IP address or hostname');
    process.exit(1);
}

const log = process.env.BMC_DEBUG ? (m => console.error(m)) : (() => {});
const chromiumArgs = ['--ignore-certificate-errors'];
if (process.getuid && process.getuid() === 0) chromiumArgs.push('--no-sandbox');

(async () => {
    const browser = await chromium.launch({ args: chromiumArgs });
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    try {
        // Login through browser form (BMC SPA at /#login)
        log('Loading login page...');
        await page.goto(`https://${host}/`, { waitUntil: 'networkidle', timeout: 20000 });
        await page.waitForSelector('#userid', { timeout: 10000 });
        await page.fill('#userid', user);
        await page.fill('#password', pass);
        await page.press('#password', 'Enter');

        // Wait for SPA to fully populate sessionStorage (features is last, takes ~6s)
        log('Waiting for session...');
        for (let i = 0; i < 15; i++) {
            await page.waitForTimeout(1000);
            const ready = await page.evaluate(() =>
                sessionStorage.getItem('garc') && sessionStorage.getItem('features')
            );
            if (ready) {
                log(`Session ready after ${i+1}s`);
                break;
            }
        }

        // Verify login succeeded
        const garc = await page.evaluate(() => sessionStorage.getItem('garc'));
        if (!garc) {
            console.error('Login failed: no CSRF token in sessionStorage');
            await page.screenshot({ path: output, type: 'jpeg', quality: 90 });
            process.exit(1);
        }
        log(`CSRF: ${garc.slice(0, 4)}...`);

        // Open viewer.html popup — window.open clones sessionStorage (all fields)
        log('Opening viewer...');
        const popupPromise = context.waitForEvent('page', { timeout: 10000 });
        await page.evaluate(() => { window.open('viewer.html', '_blank'); });
        const viewer = await popupPromise;

        // Wait for viewer to load and KVM to connect
        log('Waiting for KVM...');
        await viewer.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

        // Poll for KVM ready (toggle shows "Stop KVM" when WebSocket is connected)
        let kvmReady = false;
        for (let i = 0; i < 15; i++) {
            const toggleText = await viewer.$eval('#toggle', el => el.textContent).catch(() => '');
            if (toggleText.includes('Stop')) {
                kvmReady = true;
                log(`KVM connected after ${i}s`);
                break;
            }
            if (i === 0 && toggleText.includes('Start')) {
                log('Clicking Start KVM...');
                await viewer.click('#toggle').catch(() => {});
            }
            await viewer.waitForTimeout(1000);
        }

        // Extra wait for video frames to render on canvas
        if (kvmReady) await viewer.waitForTimeout(2000);
        else log('KVM did not connect, screenshotting anyway');

        // Screenshot the viewer page
        log('Screenshot...');
        await viewer.screenshot({ path: output, type: 'jpeg', quality: 90 });
        log('Done');

        // Cleanup: stop KVM, close viewer, delete API session
        try {
            await viewer.evaluate(() => {
                const t = document.getElementById('toggle');
                if (t && t.textContent.includes('Stop')) t.click();
            });
            await new Promise(r => setTimeout(r, 2000));
        } catch (e) {}
        try { await viewer.close(); } catch (e) {}
        try {
            await page.evaluate(async (token) => {
                await fetch('/api/session', {
                    method: 'DELETE',
                    headers: { 'X-CSRFTOKEN': token }
                });
            }, garc);
        } catch (e) {}
    } catch (err) {
        console.error('Capture error:', err.message);
        try { await page.screenshot({ path: output, type: 'jpeg', quality: 90 }); } catch (e) {}
        process.exit(1);
    } finally {
        await browser.close();
    }
})().catch(err => {
    console.error('Fatal:', err.message);
    process.exit(1);
});
