/** Chrome smoke check against authenticated staging; credentials stay in a private temp file. */
import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = 'https://staging.astrowallclocks.com';
const secret = (await readFile(process.env.ASTRO_STAGE_AUTH_FILE, 'utf8')).trim();
if (!/^astro_visual_[0-9]+:[0-9a-f]{40}$/.test(secret)) throw new Error('Temporary staging auth missing');
const authorization = `Basic ${Buffer.from(secret).toString('base64')}`;
const profile = await mkdtemp(join(tmpdir(), 'astro-stage-chrome-'));
const output = '/private/tmp/astro-staging-visual';
await mkdir(output, { recursive: true, mode: 0o700 });
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ['--headless=new', '--no-first-run', '--disable-gpu', '--remote-debugging-port=9241',
    '--remote-allow-origins=*', `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });

async function endpoint(route, method = 'GET') {
  const response = await fetch(`http://127.0.0.1:9241${route}`, { method });
  if (!response.ok) throw new Error(`Chrome endpoint ${response.status}`);
  return response.json();
}
async function tab() {
  const target = await endpoint('/json/new?about:blank', 'PUT');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let serial = 0;
  const pending = new Map();
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++serial;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params }));
  });
  ws.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Fetch.requestPaused') {
      const paused = message.params;
      const headers = Object.entries(paused.request.headers).map(([name, value]) => ({ name, value }));
      if (new URL(paused.request.url).hostname === 'staging.astrowallclocks.com') {
        headers.push({ name: 'Authorization', value: authorization });
      }
      send('Fetch.continueRequest', { requestId: paused.requestId, headers }).catch(() => {});
      return;
    }
    if (!message.id) return;
    const item = pending.get(message.id);
    if (!item) return;
    pending.delete(message.id);
    message.error ? item.reject(new Error(message.error.message)) : item.resolve(message.result);
  };
  await send('Page.enable');
  await send('Runtime.enable');
  await send('Fetch.enable', { patterns: [{ urlPattern: '*staging.astrowallclocks.com*' }] });
  return { ws, send };
}

try {
  for (let n = 0; n < 60; n++) {
    try { await endpoint('/json/version'); break; }
    catch { if (n === 59) throw new Error('Chrome unavailable'); await new Promise(r => setTimeout(r, 150)); }
  }
  const pages = ['/', '/shop/', '/product/astro-wall-clock/', '/cart/', '/checkout/', '/login/', '/register/', '/my-account/', '/track-order/'];
  for (const [device, width, height, mobile] of [['desktop', 1440, 900, false], ['mobile', 390, 844, true]]) {
    for (const route of pages) {
      const session = await tab();
      await session.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
      await session.send('Page.navigate', { url: base + route });
      let checked;
      for (let n = 0; n < 20; n++) {
        await new Promise(r => setTimeout(r, 500));
        const { result } = await session.send('Runtime.evaluate', { expression: `({url:location.href,
        width:innerWidth, pageWidth:document.documentElement.scrollWidth,
        title:document.title, missingImages:[...document.images].filter(i=>i.loading!=='lazy'&&(!i.complete||i.naturalWidth===0)).map(i=>i.src),
        css:[...document.styleSheets].some(s=>s.href?.includes('modern.css')),
        cssLink:[...document.querySelectorAll('link[rel=stylesheet]')].find(l=>l.href.includes('modern.css'))?.href})`, returnByValue: true });
        checked = result.value;
        if (checked.css && !checked.missingImages.length) break;
      }
      const page = route === '/' ? 'home' : route.split('/').filter(Boolean).pop();
      if (!checked.url.startsWith(base) || checked.pageWidth > width || checked.missingImages.length || !checked.css) {
        throw new Error(`Stage visual check failed: ${device} ${page} ${JSON.stringify(checked)}`);
      }
      const { result: assetResult } = await session.send('Runtime.evaluate', {
        expression: `(async()=>Promise.all([...new Set([...document.images].map(i=>i.src))]
          .filter(src=>new URL(src).hostname==='staging.astrowallclocks.com')
          .map(async src=>({src,status:(await fetch(src)).status}))))()`,
        awaitPromise: true, returnByValue: true,
      });
      const broken = assetResult.value.filter(asset => asset.status !== 200);
      if (broken.length) throw new Error(`Stage image HTTP check failed: ${device} ${page} ${JSON.stringify(broken)}`);
      const { data } = await session.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      await writeFile(join(output, `${device}-${page}.png`), Buffer.from(data, 'base64'));
      process.stdout.write(`PASS ${device} ${page}: width=${checked.pageWidth}, images=ok, CSS=ok\n`);
      session.ws.close();
    }
  }
  process.stdout.write(`Screenshots: ${output}\n`);
} finally {
  chrome.kill();
  await new Promise(r => setTimeout(r, 400));
  await rm(profile, { recursive: true, force: true, maxRetries: 8, retryDelay: 250 }).catch(() => {});
}
