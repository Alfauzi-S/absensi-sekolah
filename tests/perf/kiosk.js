/**
 * Uji kecepatan kiosk (Fase 4) dengan Playwright + Chromium. Skenario (MODE):
 *   latency  : N scan lewat ketikan scanner USB, jaringan lokal → t_feedback dan t_total p50/p95/p99
 *   slow     : jaringan lambat (RTT 300 ms, 3G) → tanda dari roster lokal tetap cepat; sinkron ≤ 5 detik
 *   queue36  : 36 scan secepat mungkin → selesai ≤ 60 detik, nol hilang
 *   offline  : putus jaringan, N scan, muat ulang halaman di tengah (tablet mati/nyala), sambung lagi → semua tersinkron
 * Variabel: BASE, SEED (json dari seed.php), MODE, N, OFFSET (indeks awal kartu), PAIR=1 bila perlu pairing, STATE (file storage state).
 * Keluaran: satu baris JSON hasil.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE || 'http://127.0.0.1:8766';
const seed = JSON.parse(fs.readFileSync(process.env.SEED, 'utf8')).kiosk;
const MODE = process.env.MODE || 'latency';
const N = parseInt(process.env.N || '100', 10);
const OFFSET = parseInt(process.env.OFFSET || '0', 10);
const pct = (a, p) => { if (!a.length) return 0; const s = [...a].sort((x, y) => x - y); return s[Math.max(0, Math.ceil(s.length * p / 100) - 1)]; };
const summary = (a) => ({ n: a.length, p50: Math.round(pct(a, 50)), p95: Math.round(pct(a, 95)), p99: Math.round(pct(a, 99)), max: Math.round(Math.max(0, ...a)) });

(async () => {
  const browser = await chromium.launch();
  const stateFile = process.env.STATE || '';
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, storageState: stateFile && fs.existsSync(stateFile) ? stateFile : undefined, serviceWorkers: 'allow' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(BASE + '/kiosk');
  if (await page.isVisible('#k-pair')) {
    await page.fill('#k-code', seed.pair);
    await page.click('#k-pair-form button');
  }
  await page.waitForSelector('#k-main:not([hidden])', { timeout: 10000 });
  await page.waitForFunction(() => window.__kiosk && window.__kiosk.rosterSize() > 0, null, { timeout: 15000 });
  if (stateFile) await ctx.storageState({ path: stateFile });
  const codes = [];
  for (let i = 0; i < N; i++) codes.push(seed.codes[(OFFSET + i) % seed.codes.length]);

  // Ketik seperti scanner USB lalu ukur dari dalam halaman (performance.now), bukan dari Playwright.
  async function wedge(code, waitSynced) {
    await page.keyboard.type(code, { delay: 0 });
    await page.keyboard.press('Enter');
    if (waitSynced) await page.waitForFunction(() => window.__kiosk.queue() === 0, null, { timeout: 20000 });
  }
  const state = () => page.$eval('#k-result', (el) => el.getAttribute('data-state'));
  const out = { mode: MODE, n: N };

  if (MODE === 'latency' || MODE === 'slow') {
    let cdp = null;
    if (MODE === 'slow') {
      cdp = await ctx.newCDPSession(page);
      await cdp.send('Network.enable');
      await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 300, downloadThroughput: 400 * 1024 / 8, uploadThroughput: 400 * 1024 / 8 });
    }
    const fb = [], tot = [], srv = [], states = {};
    let syncMax = 0;
    for (const c of codes) {
      const t0 = Date.now();
      await wedge(c, true);
      syncMax = Math.max(syncMax, Date.now() - t0);
      const s = await page.evaluate(() => window.__kiosk.samples().slice(-1)[0]);
      fb.push(s.t_feedback); tot.push(s.t_total); if (s.t_server) srv.push(s.t_server);
      const st = await state(); states[st] = (states[st] || 0) + 1;
    }
    Object.assign(out, { t_feedback: summary(fb), t_total: summary(tot), t_server: summary(srv), sinkron_terlama_ms: syncMax, tampilan: states });
  } else if (MODE === 'queue36') {
    const t0 = Date.now();
    for (const c of codes) { await wedge(c, false); }
    const typed = Date.now() - t0;
    await page.waitForFunction(() => window.__kiosk.queue() === 0, null, { timeout: 60000 });
    Object.assign(out, { selesai_ketik_ms: typed, selesai_tersimpan_ms: Date.now() - t0, sampel: await page.evaluate(() => window.__kiosk.samples().length) });
  } else if (MODE === 'offline') {
    await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller !== null || navigator.serviceWorker.ready.then(() => true), null, { timeout: 10000 });
    await page.reload(); // pastikan halaman dikendalikan service worker dan asetnya tersimpan
    await page.waitForFunction(() => window.__kiosk && window.__kiosk.rosterSize() > 0);
    await ctx.setOffline(true);
    const tStart = Date.now();
    const half = Math.floor(N / 2);
    const fb = [];
    for (let i = 0; i < half; i++) { await wedge(codes[i], false); }
    await page.waitForTimeout(300);
    const q1 = await page.evaluate(() => window.__kiosk.queue());
    await page.reload({ waitUntil: 'domcontentloaded' }); // "tablet dimatikan lalu dinyalakan" saat masih offline
    await page.waitForFunction(() => window.__kiosk && window.__kiosk.queue() > 0, null, { timeout: 10000 });
    const q2 = await page.evaluate(() => window.__kiosk.queue());
    const rosterAfterReload = await page.evaluate(() => window.__kiosk.rosterSize());
    for (let i = half; i < N; i++) { await wedge(codes[i], false); }
    await page.waitForTimeout(300);
    const q3 = await page.evaluate(() => window.__kiosk.queue());
    const tOnline = Date.now();
    await ctx.setOffline(false);
    await page.evaluate(() => window.__kiosk.flush());
    await page.waitForFunction(() => window.__kiosk.queue() === 0, null, { timeout: 120000 });
    for (const s of await page.evaluate(() => window.__kiosk.samples())) fb.push(s.t_feedback);
    Object.assign(out, { antre_sebelum_muat_ulang: q1, antre_setelah_muat_ulang: q2, roster_setelah_muat_ulang: rosterAfterReload, antre_sebelum_sambung: q3,
      sinkron_ms: Date.now() - tOnline, ditolak_server: await page.evaluate(() => window.__kiosk.rejected()), offline_mulai: tStart, online_lagi: tOnline, t_feedback: summary(fb) });
  }
  out.error_js = errors;
  console.log(JSON.stringify(out));
  await browser.close();
  process.exit(errors.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
