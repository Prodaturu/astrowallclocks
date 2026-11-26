// Dependency-free Chrome DevTools Protocol smoke check of the isolated local site.
import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = 'http://127.0.0.1:8088';
const chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const output = '/private/tmp/astro-visual-review';
const profile = await mkdtemp(join(tmpdir(), 'astro-chrome-'));
await mkdir(output, { recursive: true });
const process = spawn(chrome, ['--headless=new', '--no-first-run', '--disable-gpu',
  '--remote-debugging-port=9239', '--remote-allow-origins=*', `--user-data-dir=${profile}`,
  'about:blank'], { stdio: 'ignore' });

async function endpoint(path, method = 'GET') {
  const response = await fetch(`http://127.0.0.1:9239${path}`, { method });
  if (!response.ok) throw new Error(`${path}: ${response.status}`);
  return response.json();
}
async function ready() {
  for (let i = 0; i < 60; i++) {
    try { return await endpoint('/json/version'); } catch { await new Promise(r => setTimeout(r, 150)); }
  }
  throw new Error('Chrome debugging port unavailable');
}
async function tab() {
  const target = await endpoint('/json/new?about:blank', 'PUT');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let serial = 0;
  const pending = new Map();
  ws.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (!message.id) return;
    const item = pending.get(message.id);
    if (!item) return;
    pending.delete(message.id);
    message.error ? item.reject(new Error(message.error.message)) : item.resolve(message.result);
  };
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++serial;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params }));
  });
  await send('Page.enable');
  await send('Runtime.enable');
  return { ws, send, id: target.id };
}

try {
  await ready();
  const pages = ['/', '/shop/', '/product/astro-wall-clock/', '/cart/', '/checkout/', '/my-account/', '/track-order/'];
  const results = [];
  for (const [label, width, height, mobile] of [['desktop', 1440, 900, false], ['mobile', 390, 844, true]]) {
    for (const path of pages) {
      const session = await tab();
      await session.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
      await session.send('Page.navigate', { url: base + path });
      await new Promise(r => setTimeout(r, 1100));
      const { result } = await session.send('Runtime.evaluate', {
        expression: `({url: location.href, title: document.title, viewport: innerWidth,
          pageWidth: document.documentElement.scrollWidth,
          missingImages: [...document.images].filter(i => !i.complete || i.naturalWidth === 0).map(i => i.src),
          styles: [...document.styleSheets].map(s => s.href).filter(Boolean),
          menuWorks: ${mobile && path === '/'} ? (() => { const b=document.querySelector('.menu-toggle');
            b.click(); return b.getAttribute('aria-expanded') === 'true' && document.querySelector('.nav-links').classList.contains('is-open'); })() : null})`,
        returnByValue: true,
      });
      const name = path === '/' ? 'home' : path.split('/').filter(Boolean).pop();
      const { data } = await session.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      await writeFile(join(output, `${label}-${name}.png`), Buffer.from(data, 'base64'));
      const checked = { device: label, page: name, ...result.value };
      if (checked.pageWidth > width || checked.missingImages.length || checked.menuWorks === false) {
        throw new Error(`Visual smoke check failed: ${JSON.stringify(checked)}`);
      }
      results.push(checked);
      session.ws.close();
    }
    // Add a local synthetic product in the same browser tab to render checkout.
    const session = await tab();
    await session.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    const home = await fetch(base).then(r => r.text());
    const productId = home.match(/add-to-cart=(\d+)/)?.[1];
    if (!productId) throw new Error('Featured WooCommerce product missing');
    await session.send('Page.navigate', { url: `${base}/product/astro-wall-clock/?add-to-cart=${productId}&quantity=1` });
    await new Promise(r => setTimeout(r, 1100));
    await session.send('Page.navigate', { url: `${base}/checkout/` });
    await new Promise(r => setTimeout(r, 1100));
    const { result } = await session.send('Runtime.evaluate', {
      expression: `({url: location.href, viewport: innerWidth, pageWidth: document.documentElement.scrollWidth,
        checkout: !!document.querySelector('form.checkout'), cod: !!document.querySelector('#payment_method_cod')})`,
      returnByValue: true,
    });
    const { data } = await session.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    await writeFile(join(output, `${label}-checkout-with-cart.png`), Buffer.from(data, 'base64'));
    const checkout = { device: label, page: 'checkout-with-cart', ...result.value };
    if (checkout.url !== `${base}/checkout/` || checkout.pageWidth > width || !checkout.checkout || !checkout.cod) {
      throw new Error(`Checkout visual smoke check failed: ${JSON.stringify(checkout)}`);
    }
    results.push(checkout);
    session.ws.close();
  }
  await writeFile(join(output, 'results.json'), JSON.stringify(results, null, 2));
  for (const r of results) console.log(`${r.device} ${r.page}: viewport=${r.viewport} width=${r.pageWidth} imagesMissing=${r.missingImages?.length ?? 0} ${r.url}`);
  console.log(`Screenshots: ${output}`);
} catch (error) {
  console.error(error);
  throw error;
} finally {
  process.kill();
  await new Promise(r => setTimeout(r, 400));
  await rm(profile, { recursive: true, force: true, maxRetries: 8, retryDelay: 250 }).catch(() => {});
}
