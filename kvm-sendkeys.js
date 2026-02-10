// Send keyboard input via BMC KVM WebSocket for AMI MegaRAC BMC
// Usage: node kvm-sendkeys.js <host> <user> <pass> <keys>
//
// Key notation:
//   Plain text typed as-is: "root"
//   Special keys in braces: "{Enter}" "{Tab}" "{Escape}" "{F1}" "{Delete}"
//   Combos: "{Ctrl+Alt+Delete}" "{Alt+F4}"
//   Mixed: "root{Enter}password{Enter}"
//   Delay: "{Delay:2000}" (ms)

const { chromium } = require('playwright');

const [host, user, pass, keys] = process.argv.slice(2);
if (!host || !user || !pass || !keys) {
    console.error('Usage: node kvm-sendkeys.js <host> <user> <pass> <keys>');
    console.error('  Keys: plain text + {Enter} {Tab} {Escape} {F1} {Ctrl+Alt+Delete} {Delay:ms}');
    process.exit(1);
}

// Validate host looks like an IP or hostname
if (!/^[\w.\-:]+$/.test(host)) {
    console.error('Invalid host: must be IP address or hostname');
    process.exit(1);
}

const log = process.env.BMC_DEBUG ? (m => console.error(m)) : (() => {});
const chromiumArgs = ['--ignore-certificate-errors'];
if (process.getuid && process.getuid() === 0) chromiumArgs.push('--no-sandbox');

// Map brace notation to Playwright key names
const KEY_MAP = {
    'Enter': 'Enter', 'Return': 'Enter', 'Tab': 'Tab', 'Escape': 'Escape', 'Esc': 'Escape',
    'Backspace': 'Backspace', 'Delete': 'Delete', 'Insert': 'Insert',
    'Home': 'Home', 'End': 'End', 'PageUp': 'PageUp', 'PageDown': 'PageDown',
    'Up': 'ArrowUp', 'Down': 'ArrowDown', 'Left': 'ArrowLeft', 'Right': 'ArrowRight',
    'ArrowUp': 'ArrowUp', 'ArrowDown': 'ArrowDown', 'ArrowLeft': 'ArrowLeft', 'ArrowRight': 'ArrowRight',
    'F1': 'F1', 'F2': 'F2', 'F3': 'F3', 'F4': 'F4', 'F5': 'F5', 'F6': 'F6',
    'F7': 'F7', 'F8': 'F8', 'F9': 'F9', 'F10': 'F10', 'F11': 'F11', 'F12': 'F12',
    'Space': ' ', 'Ctrl': 'Control', 'Control': 'Control', 'Alt': 'Alt', 'Shift': 'Shift',
    'Win': 'Meta', 'Meta': 'Meta', 'CapsLock': 'CapsLock', 'NumLock': 'NumLock',
    'PrintScreen': 'PrintScreen', 'ScrollLock': 'ScrollLock', 'Pause': 'Pause',
};

// Parse key string into actions: [{type:'text',value:'root'}, {type:'key',value:'Enter'}, ...]
function parseKeys(str) {
    const actions = [];
    let i = 0;
    while (i < str.length) {
        if (str[i] === '{') {
            const end = str.indexOf('}', i);
            if (end === -1) { actions.push({ type: 'text', value: str.slice(i) }); break; }
            const inner = str.slice(i + 1, end);
            if (inner.startsWith('Delay:')) {
                actions.push({ type: 'delay', value: parseInt(inner.slice(6)) || 1000 });
            } else if (inner.includes('+')) {
                const parts = inner.split('+').map(p => KEY_MAP[p.trim()] || p.trim());
                actions.push({ type: 'combo', value: parts.join('+') });
            } else {
                actions.push({ type: 'key', value: KEY_MAP[inner] || inner });
            }
            i = end + 1;
        } else {
            let end = str.indexOf('{', i);
            if (end === -1) end = str.length;
            actions.push({ type: 'text', value: str.slice(i, end) });
            i = end;
        }
    }
    return actions;
}

(async () => {
    const browser = await chromium.launch({ args: chromiumArgs });
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    try {
        // Login (same flow as kvm-capture.js)
        log('Loading login page...');
        await page.goto(`https://${host}/`, { waitUntil: 'networkidle', timeout: 20000 });
        await page.waitForSelector('#userid', { timeout: 10000 });
        await page.fill('#userid', user);
        await page.fill('#password', pass);
        await page.press('#password', 'Enter');

        // Wait for full session
        log('Waiting for session...');
        for (let i = 0; i < 15; i++) {
            await page.waitForTimeout(1000);
            const ready = await page.evaluate(() =>
                sessionStorage.getItem('garc') && sessionStorage.getItem('features')
            );
            if (ready) { log(`Session ready after ${i+1}s`); break; }
        }

        const garc = await page.evaluate(() => sessionStorage.getItem('garc'));
        if (!garc) { console.error('Login failed'); process.exit(1); }

        // Open viewer popup
        log('Opening viewer...');
        const popupPromise = context.waitForEvent('page', { timeout: 10000 });
        await page.evaluate(() => { window.open('viewer.html', '_blank'); });
        const viewer = await popupPromise;

        // Wait for KVM to connect
        log('Waiting for KVM...');
        await viewer.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
        let kvmReady = false;
        for (let i = 0; i < 15; i++) {
            const toggleText = await viewer.$eval('#toggle', el => el.textContent).catch(() => '');
            if (toggleText.includes('Stop')) { kvmReady = true; log(`KVM ready`); break; }
            if (i === 0 && toggleText.includes('Start')) {
                await viewer.click('#toggle').catch(() => {});
            }
            await viewer.waitForTimeout(1000);
        }
        if (!kvmReady) { console.error('KVM did not connect'); process.exit(1); }

        // Focus the KVM canvas
        await viewer.click('canvas#kvm').catch(() => {});
        await viewer.waitForTimeout(500);

        // Send keys
        const actions = parseKeys(keys);
        log(`Sending ${actions.length} actions...`);
        for (const action of actions) {
            switch (action.type) {
                case 'text':
                    log(`Type: "${action.value}"`);
                    for (const ch of action.value) {
                        await viewer.keyboard.press(ch === ' ' ? 'Space' : ch);
                        await viewer.waitForTimeout(50);
                    }
                    break;
                case 'key':
                    log(`Press: ${action.value}`);
                    await viewer.keyboard.press(action.value);
                    break;
                case 'combo':
                    log(`Combo: ${action.value}`);
                    await viewer.keyboard.press(action.value);
                    break;
                case 'delay':
                    log(`Delay: ${action.value}ms`);
                    await viewer.waitForTimeout(action.value);
                    break;
            }
            await viewer.waitForTimeout(100);
        }

        log('Keys sent');

        // Cleanup
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
        console.error('Sendkeys error:', err.message);
        process.exit(1);
    } finally {
        await browser.close();
    }
})().catch(err => {
    console.error('Fatal:', err.message);
    process.exit(1);
});
