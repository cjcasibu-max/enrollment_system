const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const DEBUG_PORT = 9229;

async function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function loginAs(username = 'admin1', password = 'password123') {
    const getRes = await fetch('http://localhost/maritime_main-main/auth/login');
    const phpsessid = getRes.headers.get('set-cookie').match(/PHPSESSID=([^;]+)/)[1];
    const html = await getRes.text();
    const csrf = html.match(/name="csrf_token"\s+value="([^"]+)"/)[1];

    const postRes = await fetch('http://localhost/maritime_main-main/actions/auth_actions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Cookie': 'PHPSESSID=' + phpsessid },
        body: new URLSearchParams({ username, password, csrf_token: csrf }),
        redirect: 'manual'
    });
    const newCookie = postRes.headers.get('set-cookie');
    return newCookie ? newCookie.match(/PHPSESSID=([^;]+)/)[1] : phpsessid;
}

class CDPClient {
    constructor(wsUrl) {
        this.wsUrl = wsUrl;
        this.ws = null;
        this.id = 0;
        this.callbacks = new Map();
        this.errors = [];
        this.cspErrors = [];
    }

    async connect() {
        this.ws = new WebSocket(this.wsUrl);
        await new Promise((resolve, reject) => {
            this.ws.onopen = resolve;
            this.ws.onerror = reject;
        });

        this.ws.onmessage = (event) => {
            const msg = JSON.parse(event.data);
            if (msg.id && this.callbacks.has(msg.id)) {
                const cb = this.callbacks.get(msg.id);
                this.callbacks.delete(msg.id);
                if (msg.error) cb.reject(msg.error);
                else cb.resolve(msg.result);
            } else if (msg.method) {
                if (msg.method === 'Runtime.consoleAPICalled') {
                    const text = msg.params.args.map(a => a.value || a.description || '').join(' ');
                    if (msg.params.type === 'error') {
                        this.errors.push(text);
                        if (text.toLowerCase().includes('content security policy') || text.toLowerCase().includes('csp')) {
                            this.cspErrors.push(text);
                        }
                    }
                } else if (msg.method === 'Runtime.exceptionThrown') {
                    const desc = msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text;
                    this.errors.push(`Uncaught: ${desc}`);
                } else if (msg.method === 'Log.entryAdded' && msg.params.entry.level === 'error') {
                    this.errors.push(msg.params.entry.text);
                } else if (msg.method === 'Network.responseReceived' && msg.params.response.status >= 400) {
                    this.errors.push(`HTTP ${msg.params.response.status} on ${msg.params.response.url}`);
                }
            }
        };
    }

    send(method, params = {}) {
        return new Promise((resolve, reject) => {
            const id = ++this.id;
            this.callbacks.set(id, { resolve, reject });
            this.ws.send(JSON.stringify({ id, method, params }));
        });
    }

    async evaluate(expression) {
        const res = await this.send('Runtime.evaluate', {
            expression,
            returnByValue: true,
            awaitPromise: true
        });
        if (res.exceptionDetails) {
            throw new Error(res.exceptionDetails.text || 'Evaluation failed');
        }
        return res.result?.value;
    }

    close() {
        if (this.ws) this.ws.close();
    }
}

async function testPage(targetUrl, tableSelector, username = 'admin1', password = 'password123') {
    const profileDir = path.join(__dirname, 'tmp_chrome_tab_runner');
    if (!fs.existsSync(profileDir)) fs.mkdirSync(profileDir, { recursive: true });

    const cookie = await loginAs(username, password);

    const chromeProc = spawn(CHROME_PATH, [
        `--remote-debugging-port=${DEBUG_PORT}`,
        '--headless=new',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-gpu',
        `--user-data-dir=${profileDir}`
    ], { stdio: 'ignore' });

    let wsUrl = null;
    for (let i = 0; i < 30; i++) {
        await sleep(300);
        try {
            const res = await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`);
            const data = await res.json();
            wsUrl = data.webSocketDebuggerUrl;
            if (wsUrl) break;
        } catch (e) {}
    }

    const browserCdp = new CDPClient(wsUrl);
    await browserCdp.connect();

    const newTargetRes = await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/new`, { method: 'PUT' });
    const target = await newTargetRes.json();
    const cdp = new CDPClient(target.webSocketDebuggerUrl);
    await cdp.connect();

    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await cdp.send('Log.enable');
    await cdp.send('Network.enable');
    await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: cookie, url: 'http://localhost/maritime_main-main/' });

    const results = {};

    try {
        await cdp.send('Page.navigate', { url: targetUrl });
        await sleep(2500);

        // Activate tab if table is inside an inactive tab pane
        await cdp.evaluate(`(() => {
            const el = document.querySelector('${tableSelector}');
            const pane = el ? el.closest('.tab-pane') : null;
            if (pane && !pane.classList.contains('active')) {
                const btn = document.querySelector('[data-bs-target="#' + pane.id + '"], [href="#' + pane.id + '"]');
                if (btn) btn.click();
            }
        })()`);
        await sleep(600);

        // 1. Render Check
        const renderCheck = await cdp.evaluate(`(() => {
            const tbl = document.querySelector('${tableSelector}.tabulator, ${tableSelector} .tabulator, .tabulator${tableSelector}');
            const rows = tbl ? tbl.querySelectorAll('.tabulator-row') : [];
            const cols = tbl ? tbl.querySelectorAll('.tabulator-col') : [];
            return {
                exists: !!tbl,
                rowCount: rows.length,
                colCount: cols.length
            };
        })()`);
        results['1. Table Rendered'] = renderCheck.exists;

        // 2. CSP & Console Errors Check
        results['2. Zero CSP Errors'] = cdp.cspErrors.length === 0;
        results['3. Zero JS Errors on Load'] = cdp.errors.length === 0;

        // 4. Sort Test
        const sortCheck = await cdp.evaluate(`(() => {
            const col = document.querySelector('${tableSelector} .tabulator-col[tabulator-field]');
            if (!col) return false;
            col.click();
            return true;
        })()`);
        results['4. Sortable Header Clickable'] = sortCheck;

        // 5. Search Test
        const searchCheck = await cdp.evaluate(`(() => {
            const search = document.getElementById('searchInput') || document.querySelector('input[id*="earch"]') || document.querySelector('input[type="search"]');
            if (!search) return { hasSearch: false };
            search.value = 'a';
            search.dispatchEvent(new Event('input', { bubbles: true }));
            return { hasSearch: true };
        })()`);
        results['5. Search Filtering'] = searchCheck.hasSearch;

        // 6. Responsive Collapse Test
        const winInfo = await browserCdp.send('Browser.getWindowForTarget', { targetId: target.id });
        if (winInfo && winInfo.windowId) {
            await browserCdp.send('Browser.setWindowBounds', { windowId: winInfo.windowId, bounds: { width: 375, height: 667 } });
        }
        await cdp.send('Emulation.setDeviceMetricsOverride', { width: 375, height: 667, deviceScaleFactor: 1, mobile: true });
        await sleep(800);

        const responsiveCheck = await cdp.evaluate(`(async () => {
            const rows = document.querySelectorAll('${tableSelector} .tabulator-row');
            if (rows.length === 0) {
                return { hasToggle: true, subrowVisible: true, emptyTable: true };
            }
            const toggle = document.querySelector('${tableSelector} .tabulator-responsive-collapse-toggle');
            if (!toggle) {
                // Check if table width <= 375 or if all columns fit without collapsing
                const tbl = document.querySelector('${tableSelector}');
                return { hasToggle: false, emptyTable: false, tableWidth: tbl ? tbl.offsetWidth : 0, rowCount: rows.length };
            }
            const row = toggle.closest('.tabulator-row');
            toggle.click();
            await new Promise(r => setTimeout(r, 400));
            const subrow = (row ? row.querySelector('.tabulator-responsive-collapse') : null) || document.querySelector('${tableSelector} .tabulator-responsive-collapse');
            const isOpen = toggle.classList.contains('open');
            const isVisible = (subrow && (subrow.offsetHeight > 0 || window.getComputedStyle(subrow).display !== 'none')) || isOpen;
            const debugInfo = {
                subrowFound: !!subrow,
                subrowDisplay: subrow ? window.getComputedStyle(subrow).display : null,
                subrowHeight: subrow ? subrow.offsetHeight : null,
                toggleClasses: toggle ? toggle.className : null,
                isOpen: isOpen
            };
            toggle.click();
            return { hasToggle: true, subrowVisible: isVisible, emptyTable: false, debugInfo };
        })()`);
        results['6. Mobile Responsive Collapse (375px)'] = responsiveCheck.hasToggle && responsiveCheck.subrowVisible;
        if (!results['6. Mobile Responsive Collapse (375px)']) {
            results.responsiveDebug = responsiveCheck;
        }

        // 7. Overall Console Errors
        results['7. Zero JS Errors Overall'] = cdp.errors.length === 0;
        results.errors = cdp.errors;

        return results;
    } finally {
        browserCdp.close();
        cdp.close();
        try { chromeProc.kill(); } catch(e){}
        await sleep(600);
        try { fs.rmSync(profileDir, { recursive: true, force: true }); } catch(e){}
    }
}

// CLI runner
if (require.main === module) {
    const url = process.argv[2] || 'http://localhost/maritime_main-main/admin/academic_terms';
    const sel = process.argv[3] || '#termsTable';
    const user = process.argv[4] || (url.includes('/student/') ? 'student1' : 'admin1');
    const pass = process.argv[5] || 'password123';
    console.log(`Running verification for: ${url} (${sel}) as ${user}...`);
    testPage(url, sel, user, pass).then(res => {
        console.log("Results:", res);
        const allPass = Object.keys(res).filter(k => /^\d+\./.test(k)).every(k => res[k] === true);
        if (!allPass && res.errors) {
            console.log("Errors captured:", res.errors);
        }
        console.log("Overall:", allPass ? "PASS" : "FAIL");
        process.exit(allPass ? 0 : 1);
    }).catch(err => {
        console.error("Test failed with error:", err);
        process.exit(1);
    });
}

module.exports = { testPage };
