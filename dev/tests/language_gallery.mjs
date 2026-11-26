/** Click all eight homepage language tabs in the isolated local storefront. */
import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = process.env.ASTRO_LANGUAGE_BASE || 'http://127.0.0.1:8088/';
const stageHost = new URL(base).hostname;
const staging = stageHost === 'staging.astrowallclocks.com';
const out = staging ? '/private/tmp/astro-language-gallery-staging' : '/private/tmp/astro-language-gallery';
const auth = staging ? `Basic ${Buffer.from((await readFile(process.env.ASTRO_STAGE_AUTH_FILE, 'utf8')).trim()).toString('base64')}` : null;
const profile = await mkdtemp(join(tmpdir(), 'astro-language-chrome-'));
await mkdir(out, { recursive: true });
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ['--headless=new', '--no-first-run', '--disable-gpu', '--remote-debugging-port=9243',
    '--remote-allow-origins=*', `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });

async function endpoint(route, method = 'GET') {
  const response = await fetch(`http://127.0.0.1:9243${route}`, { method });
  if (!response.ok) throw new Error(`Chrome endpoint ${response.status}`);
  return response.json();
}
async function tab() {
  const target = await endpoint('/json/new?about:blank', 'PUT');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let id = 0;
  const pending = new Map();
  ws.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Fetch.requestPaused') {
      const paused = message.params;
      const headers = Object.entries(paused.request.headers).map(([name, value]) => ({ name, value }));
      if (new URL(paused.request.url).hostname === stageHost) headers.push({ name: 'Authorization', value: auth });
      send('Fetch.continueRequest', { requestId: paused.requestId, headers }).catch(() => {});
      return;
    }
    const item = pending.get(message.id);
    if (!item) return;
    pending.delete(message.id);
    message.error ? item.reject(new Error(message.error.message)) : item.resolve(message.result);
  };
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const serial = ++id;
    pending.set(serial, { resolve, reject });
    ws.send(JSON.stringify({ id: serial, method, params }));
  });
  await send('Page.enable');
  await send('Runtime.enable');
  if (staging) await send('Fetch.enable', { patterns: [{ urlPattern: '*staging.astrowallclocks.com*' }] });
  return { ws, send };
}

try {
  for (let n = 0; n < 60; n++) {
    try { await endpoint('/json/version'); break; }
    catch { if (n === 59) throw new Error('Chrome unavailable'); await new Promise(r => setTimeout(r, 150)); }
  }
  const languages = ['English', 'Telugu', 'Hindi', 'Kannada', 'Marathi', 'Tamil', 'Gujarati', 'Malayalam'];
  for (const [device, width, height, mobile] of [['desktop', 1440, 900, false], ['mobile', 390, 844, true]]) {
    const session = await tab();
    await session.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    await session.send('Page.navigate', { url: base });
    let pageReady = false;
    for (let n = 0; n < 20; n++) {
      await new Promise(r => setTimeout(r, 500));
      const { result } = await session.send('Runtime.evaluate', {
        expression: `document.querySelectorAll('.language-tabs button').length === 8`, returnByValue: true,
      });
      if (result.value) { pageReady = true; break; }
    }
    if (!pageReady) throw new Error(`${device} homepage language tabs unavailable`);
    for (const language of languages) {
      const { result } = await session.send('Runtime.evaluate', {
        expression: `(() => {
          const buttons=[...document.querySelectorAll('.language-tabs button')];
          const button=buttons.find(item=>item.dataset.language===${JSON.stringify(language)});
          if(!button) return {error:'button missing',language:${JSON.stringify(language)}};
          button.click();
          document.querySelector('.languages').scrollIntoView();
          const image=document.getElementById('language-slide');
          const card=[...document.querySelectorAll('.language-promo')].find(item=>item.dataset.language===button.dataset.language);
          return {language:button.dataset.language,buttons:buttons.length,
            active:buttons.filter(item=>item.getAttribute('aria-pressed')==='true').length,
            imageVisible:!image.hidden,cardVisible:card?!card.hidden:false,
            cardText:card?.textContent.trim().length??0,
            cardOverflow:card?card.scrollWidth>card.clientWidth:false,
            imageSource:image.currentSrc,pageWidth:document.documentElement.scrollWidth};
        })()`, returnByValue: true,
      });
      const state = result.value;
      const original = languages.indexOf(language) < 4;
      if (state.error || state.buttons !== 8 || state.active !== 1 || state.imageVisible !== original
          || state.cardVisible === original || (!original && (!state.cardText || state.cardOverflow))
          || state.pageWidth > width) throw new Error(`${device} ${language}: ${JSON.stringify(state)}`);
      if (!original) {
        await new Promise(r => setTimeout(r, 180));
        const { data } = await session.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        await writeFile(join(out, `${device}-${language.toLowerCase()}.png`), Buffer.from(data, 'base64'));
      }
      console.log(`PASS ${device} ${language}`);
    }
    session.ws.close();
  }
  console.log(`Screenshots: ${out}`);
} finally {
  chrome.kill();
  await new Promise(r => setTimeout(r, 400));
  await rm(profile, { recursive: true, force: true, maxRetries: 8, retryDelay: 250 }).catch(() => {});
}
