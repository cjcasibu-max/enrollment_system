const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = 'C:\\xampp\\htdocs\\maritime_main-main\\audit_screenshots';
if (!fs.existsSync(ARTIFACT_DIR)) {
    fs.mkdirSync(ARTIFACT_DIR, { recursive: true });
}

async function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function startChrome() {
    const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const chrome = spawn(chromePath, [
        '--headless=new',
        '--remote-debugging-port=9223',
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--user-data-dir=C:\\xampp\\htdocs\\maritime_main-main\\tests\\manual\\tmp_chrome_verify_ui2'
    ]);

    let wsUrl = null;
    for (let i = 0; i < 30; i++) {
        await sleep(300);
        try {
            const res = await fetch('http://127.0.0.1:9223/json/version');
            const data = await res.json();
            wsUrl = data.webSocketDebuggerUrl;
            if (wsUrl) break;
        } catch (e) {}
    }
    return { chrome, wsUrl };
}

async function createClient(wsUrl) {
    const newTargetRes = await fetch('http://127.0.0.1:9223/json/new', { method: 'PUT' });
    const target = await newTargetRes.json();
    const ws = new WebSocket(target.webSocketDebuggerUrl);

    let id = 1;
    const callbacks = new Map();
    ws.onmessage = (msg) => {
        const data = JSON.parse(msg.data);
        if (data.id && callbacks.has(data.id)) {
            const cb = callbacks.get(data.id);
            callbacks.delete(data.id);
            cb(data);
        }
    };

    function send(method, params = {}) {
        return new Promise((resolve) => {
            const msgId = id++;
            callbacks.set(msgId, resolve);
            ws.send(JSON.stringify({ id: msgId, method, params }));
        });
    }

    await new Promise(r => ws.onopen = r);
    await send('Page.enable');
    await send('DOM.enable');
    await send('Runtime.enable');

    async function setViewport(width, height, mobile = false) {
        await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    }

    async function navigate(url) {
        await send('Page.navigate', { url });
        await sleep(1500);
    }

    async function evaluate(expression) {
        const res = await send('Runtime.evaluate', {
            expression: `(() => { try { return (${expression}); } catch(e) { return 'ERR: ' + e.message + ' ' + e.stack; } })()`,
            returnByValue: true,
            awaitPromise: true
        });
        return res?.result?.result?.value;
    }

    async function capture(filename) {
        const res = await send('Page.captureScreenshot', { format: 'png' });
        const buf = Buffer.from(res.result.data, 'base64');
        const filepath = path.join(ARTIFACT_DIR, filename);
        fs.writeFileSync(filepath, buf);
        console.log(`Saved screenshot: ${filename} (${buf.length} bytes)`);
        return filepath;
    }

    async function scrollTo(y) {
        await evaluate(`window.scrollTo(0, ${y})`);
        await sleep(500);
    }

    async function close() {
        ws.close();
    }

    return { send, setViewport, navigate, evaluate, capture, scrollTo, close };
}

module.exports = { startChrome, createClient, ARTIFACT_DIR, sleep };
