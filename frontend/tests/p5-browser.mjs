// Real HTTP/browser acceptance against test-only Laravel and production Next.js.
// Run from frontend after npm run build: node tests/p5-browser.mjs
import { spawn, spawnSync } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import WebSocket from 'next/dist/compiled/ws/index.js';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const children = [];
let proxy, socket, seeded = false;
const artifacts = path.join(root, '.work/p5');
fs.mkdirSync(artifacts, { recursive: true });
function php(...args) {
    const result = spawnSync('php', ['backend/tests/Http/p5_fixture.php', ...args], { cwd: root, encoding: 'utf8' });
    if (result.status !== 0) throw Error(result.stderr + result.stdout);
    return result.stdout;
}
function launch(command, args, log, cwd = root) {
    const output = fs.openSync(path.join(artifacts, log), 'w');
    const child = spawn(command, args, { cwd, stdio: ['ignore', output, output] });
    children.push(child); fs.closeSync(output); return child;
}
async function ready(url) {
    for (let i = 0; i < 120; i++) { try { await fetch(url); return; } catch { await sleep(150); } }
    throw Error('Server did not start: ' + url);
}
(async () => {
    const fixture = JSON.parse(php()); seeded = true;
    launch('php', ['-S', 'localhost:8015', 'backend/tests/Http/p5_router.php'], 'http.log');
    launch('node', ['node_modules/next/dist/bin/next', 'start', '-p', '3006'], 'next.log', path.join(root, 'frontend'));
    await ready('http://localhost:8015/api/user'); await ready('http://localhost:3006');
    // Same-origin production-style proxy: API requests hit real Laravel, never mocks.
    const exports = [];
    proxy = http.createServer((request, response) => {
        const backend = request.url.startsWith('/api/') || request.url.startsWith('/sanctum/');
        const target = backend ? 8015 : 3006;
        const upstream = http.request({ hostname: 'localhost', port: target, path: request.url, method: request.method, headers: { ...request.headers, host: 'localhost:' + target } }, remote => {
            if (request.url.includes('/export')) exports.push({ url: request.url, status: remote.statusCode });
            response.writeHead(remote.statusCode, remote.headers); remote.pipe(response);
        });
        upstream.on('error', err => { response.writeHead(502); response.end(err.message); }); request.pipe(upstream);
    });
    await new Promise(resolve => proxy.listen(3005, 'localhost', resolve));
    const api = async (url, token = fixture.admin_token) => fetch('http://localhost:8015/api/admin/' + url, { headers: { Accept: 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) } });
    const before = php('--snapshot');
    for (const kind of ['kelulusan', 'pembayaran']) {
        const list = await api('data-' + kind); assert.equal(list.status, 200);
        const data = await list.json(); assert.equal(data.meta.total, kind === 'kelulusan' ? 6 : 7);
        assert.equal((await api('data-' + kind, null)).status, 401);
        assert.equal((await api('data-' + kind, fixture.guru_token)).status, 403);
        assert.equal((await api('data-' + kind + '/export', fixture.guru_token)).status, 403);
        const csv = await (await api('data-' + kind + '/export')).text();
        assert(!/proof_of_payment|bukti_bayar|password|registration_number|token/.test(csv));
        const detail = await api('data-' + kind + '/' + fixture.users[1].id); assert.equal(detail.status, 200);
        assert.equal((await api('data-' + kind + '/' + fixture.users[1].id, fixture.guru_token)).status, 403);
        for (const query of ['jenjang=SD', 'jalur_pendaftaran=INTERNAL_TK', 'search=peserta1%40p5-http.test']) {
            const filtered = await (await api('data-' + kind + '?' + query)).json(); assert(filtered.data.length > 0);
        }
    }
    for (const status of ['LULUS', 'TIDAK_LULUS']) { const d = await (await api('data-kelulusan?status=' + status)).json(); assert.equal(d.meta.total, 3); }
    for (const status of ['NO_PAYMENT', 'PENDING_MANUAL', 'SUCCESS', 'REJECTED', 'FAILED', 'EXPIRED', 'PENDING']) {
        const d = await (await api('data-pembayaran?status=' + status)).json(); assert.equal(d.meta.total, 1);
    }
    const proof = `data-pembayaran/${fixture.users[1].id}/payments/${fixture.users[1].payment_id}/proof`;
    assert.equal((await api(proof)).status, 200);
    assert.equal((await api(proof, null)).status, 401); assert.equal((await api(proof, fixture.guru_token)).status, 403);
    assert.equal((await api(proof.replace('/' + fixture.users[1].id + '/', '/' + fixture.users[2].id + '/'))).status, 404);
    assert.equal((await api(`data-pembayaran/${fixture.users[1].id}/payments/999999/proof`)).status, 404);
    assert.equal(php('--snapshot'), before);
    console.log('PASS real HTTP: list/filter/detail/export, all states, 401/403, bound proof/404, no mutation');

    launch('/usr/bin/google-chrome', ['--headless=new', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=9339', '--user-data-dir=' + fs.mkdtempSync('/tmp/p5-chrome-'), 'about:blank'], 'chrome.log');
    await ready('http://127.0.0.1:9339/json');
    const targets = await (await fetch('http://127.0.0.1:9339/json')).json();
    socket = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
    await new Promise(resolve => socket.on('open', resolve));
    let id = 0; const pending = new Map(), dialogs = [], errors = [];
    const send = (method, params = {}) => new Promise((resolve, reject) => { const n = ++id; pending.set(n, { resolve, reject }); socket.send(JSON.stringify({ id: n, method, params })); });
    socket.on('message', raw => {
        const m = JSON.parse(raw);
        if (m.id) { const p = pending.get(m.id); pending.delete(m.id); if (m.error) p.reject(m.error); else p.resolve(m.result); }
        if (m.method === 'Page.javascriptDialogOpening') { dialogs.push(m.params.message); send('Page.handleJavaScriptDialog', { accept: false }); }
        if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text);
    });
    await send('Page.enable'); await send('Runtime.enable');
    const evaluate = async expression => { const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw Error(JSON.stringify(r.exceptionDetails)); return r.result.value; };
    const act = code => evaluate('(()=>{' + code + '})()');
    const wait = async expression => { for (let i = 0; i < 100; i++) { if (await evaluate(expression)) return; await sleep(100); } throw Error('Browser condition timed out: ' + expression); };
    const click = text => act(`Array.from(document.querySelectorAll('button')).find(b=>b.textContent===${JSON.stringify(text)}).click()`);
    const loaded = () => wait('!!document.querySelector("tbody button") && document.querySelector("[aria-busy]").getAttribute("aria-busy")==="false"');
    const screenshot = async name => { const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); fs.writeFileSync(path.join(artifacts, name + '.png'), Buffer.from(r.data, 'base64')); };
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: 'http://localhost:3005/login' }); await sleep(600);
    // Real Sanctum session login; no intercepted API responses or injected browser tokens.
    const login = await evaluate(`(async()=>{await fetch('/sanctum/csrf-cookie',{credentials:'include'});const cookie=document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='));const r=await fetch('/api/login',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','Accept':'application/json','X-XSRF-TOKEN':decodeURIComponent(cookie.split('=').slice(1).join('='))},body:JSON.stringify({email:'admin@p5-http.test',password:'P5-test-password'})});return r.status})()`);
    assert.equal(login, 200);
    const browserBefore = php('--snapshot');
    for (const kind of ['kelulusan', 'pembayaran']) {
        await send('Page.navigate', { url: 'http://localhost:3005/dashboard/data-' + kind }); await loaded();
        assert.equal(await evaluate('document.querySelector("h1").textContent'), kind === 'kelulusan' ? 'Data Kelulusan' : 'Data Pembayaran');
        assert.equal(await evaluate('document.querySelectorAll("tbody tr").length'), kind === 'kelulusan' ? 6 : 7);
        assert(await evaluate(`!!document.querySelector('a[aria-label="Data Kelulusan"]') && !!document.querySelector('a[aria-label="Data Pembayaran"]')`));
        await screenshot(kind + '-desktop');
        const statusLabels = kind === 'kelulusan' ? ['Lulus', 'Tidak Lulus'] : ['Belum Bayar', 'Menunggu Verifikasi', 'Sudah Bayar', 'Ditolak', 'Gagal', 'Kedaluwarsa', 'Menunggu Pembayaran'];
        for (const label of statusLabels) {
            await click(label); await loaded();
            assert.equal(await evaluate('document.querySelectorAll("tbody tr").length'), kind === 'kelulusan' ? 3 : 1);
            assert((await evaluate('document.querySelector("tbody").textContent')).includes(label));
        }
        await click('Semua'); await loaded();
        await act('const s=document.querySelectorAll("section select")[0];s.value="SD";s.dispatchEvent(new Event("change",{bubbles:true}))'); await loaded();
        assert.equal(await evaluate('document.querySelectorAll("tbody tr").length'), kind === 'kelulusan' ? 3 : 4);
        await act('const s=document.querySelectorAll("section select")[0];s.value="";s.dispatchEvent(new Event("change",{bubbles:true}))'); await loaded();
        await act('const s=document.querySelectorAll("section select")[1];s.value="INTERNAL_TK";s.dispatchEvent(new Event("change",{bubbles:true}))'); await loaded();
        assert.equal(await evaluate('document.querySelectorAll("tbody tr").length'), 3);
        await act('const s=document.querySelectorAll("section select")[1];s.value="";s.dispatchEvent(new Event("change",{bubbles:true}))'); await loaded();
        await act('const e=document.querySelector("section input[type=search]");e.focus();e.select()'); await send('Input.insertText', { text: 'peserta1@p5-http.test' }); await loaded();
        assert.equal(await evaluate('document.querySelectorAll("tbody tr").length'), 1);
        await click('Detail'); await wait('!!document.querySelector("dialog[open]")');
        assert((await evaluate('document.querySelector("dialog").textContent')).includes('peserta1@p5-http.test'));
        if (kind === 'pembayaran') {
            await click('Lihat Bukti Pembayaran'); await wait('!!document.querySelector("dialog img") && document.querySelector("dialog img").naturalWidth>0');
            assert((await evaluate('document.querySelector("dialog img").src')).startsWith('blob:'));
            await screenshot('proof-desktop');
            await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }); await screenshot('proof-mobile');
            assert(await evaluate('document.querySelector("dialog").getBoundingClientRect().right <= innerWidth'));
            await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
        }
        for (let i = 0; i < 4; i++) { await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 }); assert(await evaluate('document.activeElement.closest("dialog")!==null')); }
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await wait('!document.querySelector("dialog")');
        assert.equal(await evaluate('document.activeElement.textContent'), 'Detail');
        const count = exports.length; await click('Export Data'); await wait('Array.from(document.querySelectorAll("button")).some(b=>b.textContent==="Export Data"&&!b.disabled)');
        assert.equal(exports.length, count + 1); assert.equal(exports.at(-1).status, 200); assert(exports.at(-1).url.includes('search=peserta1'));
        await act('const e=document.querySelector("section input[type=search]");e.focus();e.select()'); await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 }); await loaded();
        await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }); await screenshot(kind + '-mobile');
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'));
        await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
        console.log('PASS browser: ' + kind + ' filters/search/detail/export, keyboard modal, desktop/mobile');
    }
    assert.equal(dialogs.length, 0, 'Server text executed as JavaScript'); assert.equal(errors.length, 0, 'Browser exception');
    assert.equal(php('--snapshot'), browserBefore, 'Browser read-only reports mutated records');
    // Unauthenticated direct URLs must redirect, not render report data.
    await evaluate("fetch('/api/logout',{method:'POST',headers:{'X-XSRF-TOKEN':decodeURIComponent(document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN=')).split('=').slice(1).join('='))}})");
    await send('Page.navigate', { url: 'http://localhost:3005/dashboard/data-pembayaran' }); await wait('location.pathname==="/login"');
    console.log('PASS browser: unauthenticated direct page redirects to login; no XSS or runtime exceptions');
    console.log('Screenshots: .work/p5/*.png (real server data, production frontend)');
})().catch(err => { console.error(err); process.exitCode = 1; }).finally(async () => {
    if (socket) socket.close(); if (proxy) proxy.close();
    for (const child of children.reverse()) child.kill('SIGTERM');
    if (seeded) { try { php('--cleanup'); } catch (err) { console.error(err); process.exitCode = 1; } }
});
