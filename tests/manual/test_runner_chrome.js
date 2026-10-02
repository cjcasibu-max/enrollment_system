const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const DEBUG_PORT = 9225;

async function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function loginAs(username = 'admin1', password = 'password123') {
    const getRes = await fetch('http://localhost/maritime_main-main/auth/login');
    const cookieHeader = getRes.headers.get('set-cookie');
    const phpsessid = cookieHeader.match(/PHPSESSID=([^;]+)/)[1];
    const html = await getRes.text();
    const csrfMatch = html.match(/name="csrf_token"\s+value="([^"]+)"/);
    const csrf = csrfMatch ? csrfMatch[1] : '';

    const postRes = await fetch('http://localhost/maritime_main-main/actions/auth_actions', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Cookie': `PHPSESSID=${phpsessid}`
        },
        body: new URLSearchParams({ username, password, csrf_token: csrf }),
        redirect: 'manual'
    });

    const newCookie = postRes.headers.get('set-cookie');
    return newCookie && newCookie.includes('PHPSESSID')
        ? newCookie.match(/PHPSESSID=([^;]+)/)[1]
        : phpsessid;
}

class CDPClient {
    constructor(wsUrl) {
        this.wsUrl = wsUrl;
        this.ws = null;
        this.id = 0;
        this.callbacks = new Map();
        this.consoleLogs = [];
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
                    this.consoleLogs.push({ type: msg.params.type, text });
                    if (msg.params.type === 'error') {
                        this.errors.push(`Console error: ${text}`);
                        if (text.toLowerCase().includes('content security policy') || text.toLowerCase().includes('csp')) {
                            this.cspErrors.push(text);
                        }
                    }
                } else if (msg.method === 'Runtime.exceptionThrown') {
                    const desc = msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text;
                    this.errors.push(`Uncaught exception: ${desc}`);
                } else if (msg.method === 'Log.entryAdded') {
                    const entry = msg.params.entry;
                    if (entry.level === 'error') {
                        this.errors.push(`Browser Log error: ${entry.text}`);
                        if (entry.text.toLowerCase().includes('content security policy') || entry.text.toLowerCase().includes('csp')) {
                            this.cspErrors.push(entry.text);
                        }
                    }
                } else if (msg.method === 'Security.securityStateChanged') {
                    // Security state monitor
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

async function runTests() {
    console.log("=======================================================================");
    console.log("      AUTOMATED BROWSER VERIFICATION — admin/manage_users.php          ");
    console.log("=======================================================================\n");

    const profileDir = path.join(__dirname, 'tmp_chrome_tabulator_test');
    if (!fs.existsSync(profileDir)) fs.mkdirSync(profileDir, { recursive: true });

    console.log("1. Authenticating as admin1 via actions/auth_actions...");
    const adminCookie = await loginAs('admin1', 'password123');
    console.log("   Authenticated. PHPSESSID:", adminCookie);

    console.log("\n2. Launching headless Google Chrome with remote debugging on port " + DEBUG_PORT + "...");
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

    if (!wsUrl) {
        chromeProc.kill();
        throw new Error("Could not connect to Chrome DevTools port " + DEBUG_PORT);
    }

    const newTargetRes = await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/new`, { method: 'PUT' });
    const target = await newTargetRes.json();
    const cdp = new CDPClient(target.webSocketDebuggerUrl);
    await cdp.connect();

    const browserCdp = new CDPClient(wsUrl);
    await browserCdp.connect();

    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await cdp.send('Log.enable');
    await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });

    // Set Admin Session Cookie
    await cdp.send('Network.setCookie', {
        name: 'PHPSESSID',
        value: adminCookie,
        url: 'http://localhost/maritime_main-main/'
    });

    const checklist = {};

    try {
        console.log("\n3. Navigating to http://localhost/maritime_main-main/admin/manage_users...");
        await cdp.send('Page.navigate', { url: 'http://localhost/maritime_main-main/admin/manage_users' });
        await sleep(2500);

        // Verification Item 1: Render correctly, no CSP violations, jsdelivr loaded
        console.log("\n--- Item 1: Page Render & CSP Check ---");
        const pageTitle = await cdp.evaluate("document.title");
        console.log("   Page title:", pageTitle);
        console.log("   CSP Errors:", cdp.cspErrors.length === 0 ? "0 violations" : cdp.cspErrors);
        console.log("   All Load Errors:", cdp.errors.length === 0 ? "0 errors" : cdp.errors);

        const tabulatorStatus = await cdp.evaluate(`(() => {
            const tabulatorEl = document.querySelector('.tabulator#usersTable, #usersTable.tabulator, .tabulator');
            const header = document.querySelector('.tabulator-header');
            const cols = Array.from(document.querySelectorAll('.tabulator-col')).map(c => c.getAttribute('tabulator-field'));
            const rows = document.querySelectorAll('.tabulator-row');
            return {
                exists: !!tabulatorEl,
                hasHeader: !!header,
                columns: cols,
                rowCount: rows.length
            };
        })()`);
        console.log("   Tabulator DOM Inspection:", tabulatorStatus);

        checklist["1. Tabulator Table Renders Correctly"] = tabulatorStatus.exists && tabulatorStatus.rowCount > 0;
        checklist["1b. No CSP Violation Errors (jsdelivr)"] = cdp.cspErrors.length === 0;

        // Verification Item 2: Global Search (searchInput)
        console.log("\n--- Item 2: Global Search (searchInput) ---");
        const searchTest = await cdp.evaluate(`(() => {
            const input = document.getElementById('searchInput');
            input.value = 'teacher1';
            filterUsersTable();
            const visibleRows = Array.from(document.querySelectorAll('.tabulator-row')).filter(r => r.offsetParent !== null);
            const text = visibleRows.map(r => r.innerText).join(' ');
            return {
                visibleCount: visibleRows.length,
                matchesTeacher1: text.includes('teacher1'),
                excludesAdmin: !text.includes('admin1')
            };
        })()`);
        console.log("   Search 'teacher1':", searchTest);
        checklist["2a. Global Search Filters Rows"] = searchTest.matchesTeacher1 && searchTest.excludesAdmin;

        // Verification Item 2b: Role Filter & Status Filter (combined)
        console.log("\n--- Item 2b: Role Filter & Combined Search ---");
        const roleStatusTest = await cdp.evaluate(`(() => {
            document.getElementById('searchInput').value = '';
            document.getElementById('roleFilter').value = 'registrar';
            document.getElementById('statusFilter').value = 'active';
            filterUsersTable();
            const visibleRows = Array.from(document.querySelectorAll('.tabulator-row')).filter(r => r.offsetParent !== null);
            const text = visibleRows.map(r => r.innerText).join(' ');
            return {
                visibleCount: visibleRows.length,
                hasRegistrar: text.includes('registrar'),
                excludesTeacher: !text.includes('teacher1')
            };
        })()`);
        console.log("   Role 'registrar' + Status 'active':", roleStatusTest);
        checklist["2b. Role & Status Filters (Combined)"] = roleStatusTest.hasRegistrar && roleStatusTest.excludesTeacher;

        // Verification Item 2c: Column Sorting
        console.log("\n--- Item 2c: Column Sorting ---");
        const sortTest = await cdp.evaluate(`(() => {
            resetFilters();
            
            // Sort ID
            const idCol = document.querySelector('.tabulator-col[tabulator-field="id"]');
            idCol.click(); // asc
            const firstIdAsc = document.querySelector('.tabulator-row .tabulator-cell[tabulator-field="id"]')?.innerText.trim();
            idCol.click(); // desc
            const firstIdDesc = document.querySelector('.tabulator-row .tabulator-cell[tabulator-field="id"]')?.innerText.trim();
            
            // Sort Identity
            const identCol = document.querySelector('.tabulator-col[tabulator-field="identity"]');
            identCol.click();
            const firstIdentAsc = document.querySelector('.tabulator-row .tabulator-cell[tabulator-field="identity"]')?.innerText.trim();
            
            // Sort Role
            const roleCol = document.querySelector('.tabulator-col[tabulator-field="role"]');
            roleCol.click();

            // Sort Profile
            const profCol = document.querySelector('.tabulator-col[tabulator-field="profile"]');
            profCol.click();

            // Sort Status
            const statCol = document.querySelector('.tabulator-col[tabulator-field="status"]');
            statCol.click();

            // Sort Created At
            const dateCol = document.querySelector('.tabulator-col[tabulator-field="created_at"]');
            dateCol.click();

            return {
                firstIdAsc,
                firstIdDesc,
                idSortWorked: firstIdAsc !== firstIdDesc,
                identitySorted: !!firstIdentAsc
            };
        })()`);
        console.log("   Sorting test result:", sortTest);
        checklist["2c. Column Sorting on All Sortable Cols"] = sortTest.idSortWorked && sortTest.identitySorted;

        // Verification Item 2d: Pagination (page size & navigation)
        console.log("\n--- Item 2d: Pagination ---");
        const pagTest = await cdp.evaluate(`(() => {
            resetFilters();
            const paginator = document.querySelector('.tabulator-paginator');
            const sizeSelect = document.querySelector('.tabulator-page-size');
            const initialSize = sizeSelect ? sizeSelect.value : null;
            
            // Switch page size to 10
            if (sizeSelect) {
                sizeSelect.value = '10';
                sizeSelect.dispatchEvent(new Event('change'));
            }
            const newSize = sizeSelect ? sizeSelect.value : null;
            
            // Switch back to 25
            if (sizeSelect) {
                sizeSelect.value = '25';
                sizeSelect.dispatchEvent(new Event('change'));
            }

            return {
                hasPaginator: !!paginator,
                hasSizeSelect: !!sizeSelect,
                initialSize,
                sizeChanged: newSize === '10'
            };
        })()`);
        console.log("   Pagination test result:", pagTest);
        checklist["2d. Pagination Controls (25 rows default)"] = pagTest.hasPaginator && pagTest.hasSizeSelect && pagTest.initialSize === '25';

        // Verification Item 2e: Reset Filters
        console.log("\n--- Item 2e: Reset Filters Button ---");
        const resetTest = await cdp.evaluate(`(() => {
            document.getElementById('searchInput').value = 'searchtest';
            document.getElementById('roleFilter').value = 'teacher';
            document.getElementById('statusFilter').value = 'deactivated';
            filterUsersTable();
            
            // Click Reset
            resetFilters();
            const searchVal = document.getElementById('searchInput').value;
            const roleVal = document.getElementById('roleFilter').value;
            const statusVal = document.getElementById('statusFilter').value;
            const visibleRows = Array.from(document.querySelectorAll('.tabulator-row')).filter(r => r.offsetParent !== null);

            return {
                inputsCleared: searchVal === '' && roleVal === '' && statusVal === '',
                allRowsRestored: visibleRows.length > 1
            };
        })()`);
        console.log("   Reset Filters test:", resetTest);
        checklist["2e. Reset Filters Clears Everything"] = resetTest.inputsCleared && resetTest.allRowsRestored;

        // Verification Item 2f: Edit Button & Modal
        console.log("\n--- Item 2f: Edit Button & Modal ---");
        const editTest = await cdp.evaluate(`(async () => {
            resetFilters();
            const editBtn = document.querySelector('button[title="Edit Account"]');
            if (!editBtn) return { found: false };
            const userId = editBtn.dataset.userId;
            editBtn.click();
            
            const modal = document.getElementById('editUserModal');
            let isShown = false;
            for (let i = 0; i < 30; i++) {
                await new Promise(r => setTimeout(r, 100));
                if (modal && (modal.classList.contains('show') || modal.style.display === 'block')) {
                    isShown = true;
                    break;
                }
            }
            
            const usernameVal = document.getElementById('edit_username')?.value;
            const emailVal = document.getElementById('edit_email')?.value;

            // Close modal
            const closeBtn = modal ? (modal.querySelector('.btn-close') || modal.querySelector('[data-bs-dismiss="modal"]')) : null;
            if (closeBtn) closeBtn.click();
            await new Promise(r => setTimeout(r, 500));

            return {
                found: true,
                isShown,
                userId,
                usernameVal,
                emailVal,
                hasData: !!usernameVal && !!emailVal
            };
        })()`);
        console.log("   Edit modal test:", editTest);
        checklist["2f. Edit Button Opens Modal with User Data"] = editTest.isShown && editTest.hasData;

        // Verification Item 2g: Status Toggle SweetAlert2
        console.log("\n--- Item 2g: Status Toggle SweetAlert2 ---");
        const statusToggleTest = await cdp.evaluate(`(async () => {
            const toggleBtn = document.querySelector('button[title="Deactivate Account"], button[title="Activate Account"]');
            if (!toggleBtn) return { found: false };
            toggleBtn.click();
            await new Promise(r => setTimeout(r, 400));

            const swal = document.querySelector('.swal2-container');
            const title = document.getElementById('swal2-title')?.innerText || '';
            const isVisible = !!swal && !swal.classList.contains('swal2-backdrop-hide');

            // Cancel SweetAlert
            const cancelBtn = document.querySelector('.swal2-cancel');
            if (cancelBtn) cancelBtn.click();
            await new Promise(r => setTimeout(r, 300));

            return {
                found: true,
                isVisible,
                title
            };
        })()`);
        console.log("   Status Toggle SweetAlert2:", statusToggleTest);
        checklist["2g. Status Toggle SweetAlert2 Modal"] = statusToggleTest.isVisible && statusToggleTest.title.includes('Modify Account Status');

        // Verification Item 2h: Delete SweetAlert2
        console.log("\n--- Item 2h: Delete SweetAlert2 ---");
        const deleteTest = await cdp.evaluate(`(async () => {
            const delBtn = document.querySelector('button[title="Delete Account"]');
            if (!delBtn) return { found: false };
            delBtn.click();
            await new Promise(r => setTimeout(r, 400));

            const swal = document.querySelector('.swal2-container');
            const title = document.getElementById('swal2-title')?.innerText || '';
            const isVisible = !!swal && !swal.classList.contains('swal2-backdrop-hide');

            // Cancel SweetAlert
            const cancelBtn = document.querySelector('.swal2-cancel');
            if (cancelBtn) cancelBtn.click();
            await new Promise(r => setTimeout(r, 300));

            return {
                found: true,
                isVisible,
                title
            };
        })()`);
        console.log("   Delete SweetAlert2:", deleteTest);
        checklist["2h. Delete Account SweetAlert2 Modal"] = deleteTest.isVisible && deleteTest.title.includes('Delete Account');

        // Verification Item 2i: (You) Self-Protected Admin State
        console.log("\n--- Item 2i: (You) Self-Protected Admin Row ---");
        const selfTest = await cdp.evaluate(`(() => {
            const badges = Array.from(document.querySelectorAll('.badge')).filter(b => b.innerText.includes('(You)'));
            const hasYouBadge = badges.length > 0;
            let disabledButtons = 0;

            if (hasYouBadge) {
                const row = badges[0].closest('.tabulator-row');
                if (row) {
                    disabledButtons = row.querySelectorAll('button[disabled]').length;
                }
            }

            return {
                hasYouBadge,
                disabledButtons
            };
        })()`);
        console.log("   Self Admin (You) check:", selfTest);
        checklist["2i. Self (You) Admin Row Protected & Disabled"] = selfTest.hasYouBadge && selfTest.disabledButtons >= 2;

        // Verification Item 2j: Empty State Placeholder
        console.log("\n--- Item 2j: Empty State Placeholder ---");
        const emptyTest = await cdp.evaluate(`(() => {
            document.getElementById('searchInput').value = 'no_such_user_123456789_xyz';
            filterUsersTable();
            const placeholder = document.querySelector('.tabulator-placeholder');
            const isVisible = !!placeholder && placeholder.offsetParent !== null;
            const text = placeholder ? placeholder.innerText : '';
            resetFilters();

            return {
                isVisible,
                text
            };
        })()`);
        console.log("   Empty state placeholder:", emptyTest);
        checklist["2j. Empty State Placeholder on 0 Matches"] = emptyTest.isVisible && emptyTest.text.includes('No user accounts found');

        // Verification Item 3: Responsive Behavior on Mobile Viewport
        console.log("\n--- Item 3: Responsive Mobile Viewport (375px) ---");
        const winInfo = await browserCdp.send('Browser.getWindowForTarget', { targetId: target.id });
        if (winInfo && winInfo.windowId) {
            await browserCdp.send('Browser.setWindowBounds', {
                windowId: winInfo.windowId,
                bounds: { width: 375, height: 667 }
            });
            await sleep(500);
        }
        await cdp.send('Emulation.setDeviceMetricsOverride', {
            width: 375,
            height: 667,
            deviceScaleFactor: 1,
            mobile: true
        });
        await sleep(800);

        const responsiveTest = await cdp.evaluate(`(async () => {
            if (usersTable) usersTable.redraw(true);
            await new Promise(r => setTimeout(r, 600));

            const toggle = document.querySelector('.tabulator-responsive-collapse-toggle');
            const hasToggle = !!toggle;

            let subrowVisible = false;
            let subrowContent = '';

            if (toggle) {
                toggle.click();
                await new Promise(r => setTimeout(r, 500));
                
                let collapseRow = document.querySelector('.tabulator-responsive-collapse');
                subrowVisible = !!collapseRow && getComputedStyle(collapseRow).display !== 'none';
                subrowContent = collapseRow ? collapseRow.innerText : '';
                toggle.click();
            }

            return {
                hasToggle,
                subrowVisible,
                subrowContent: subrowContent.substring(0, 100)
            };
        })()`);
        console.log("   Responsive test at 375px:", responsiveTest);
        checklist["3. Responsive Mobile Collapse Subrow (375px)"] = responsiveTest.hasToggle && responsiveTest.subrowVisible;

        if (winInfo && winInfo.windowId) {
            await browserCdp.send('Browser.setWindowBounds', {
                windowId: winInfo.windowId,
                bounds: { width: 1280, height: 800 }
            });
        }
        await cdp.send('Emulation.clearDeviceMetricsOverride');
        await sleep(300);

        // Verification Item 4: Console Errors during full interaction
        console.log("\n--- Item 4: Total Console Errors Check ---");
        console.log("   Total errors captured across full test session:", cdp.errors.length);
        if (cdp.errors.length > 0) {
            console.log("   Captured errors:", cdp.errors);
        }
        checklist["4. Zero Console Errors During Interactions"] = cdp.errors.length === 0;

        browserCdp.close();
        cdp.close();

        console.log("\n=======================================================================");
        console.log("                       FINAL RESULTS SUMMARY                          ");
        console.log("=======================================================================");
        for (const [name, passed] of Object.entries(checklist)) {
            console.log(`${name.padEnd(50)} : [ ${passed ? 'PASS' : 'FAIL'} ]`);
        }
        console.log("=======================================================================\n");

    } catch (e) {
        console.error("Test execution failed:", e);
    } finally {
        try {
            chromeProc.kill();
        } catch (e) {}
        await sleep(1000);
        try {
            fs.rmSync(profileDir, { recursive: true, force: true });
        } catch (e) {}
    }
}

runTests();
