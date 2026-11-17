/**
 * E2E kiosk (Playwright + Chromium): pasangkan perangkat, lalu ketik kode 36 siswa seperti scanner USB (keyboard wedge + Enter).
 * Mengukur t_total dari ketikan sampai hasil tampil, memastikan 36 hasil Hadir/Terlambat, duplikat, salah kelas, kartu tidak berlaku.
 * Dijalankan oleh tests/e2e/run.sh (menyiapkan server SQLite + data demo). Variabel: BASE, PAIR_CODE, CODES (dipisah koma), WRONG_CODE, SHOTS (dir, opsional).
 */
const { chromium } = require('playwright');

(async () => {
  const BASE = process.env.BASE || 'http://127.0.0.1:8766';
  const codes = (process.env.CODES || '').split(',').filter(Boolean);
  const wrong = process.env.WRONG_CODE || '';
  const shots = process.env.SHOTS || '';
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1, permissions: [] });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/camera|getUserMedia|NotFound|html5-qrcode/i.test(m.text())) errors.push(m.text()); });

  await page.goto(BASE + '/kiosk');
  await page.waitForSelector('#k-pair:not([hidden])');
  if (shots) await page.screenshot({ path: shots + '/kiosk-pair.png' });
  await page.fill('#k-code', process.env.PAIR_CODE || '');
  await page.click('#k-pair-form button');
  await page.waitForSelector('#k-main:not([hidden])', { timeout: 10000 });
  await page.waitForFunction(() => document.querySelector('#k-net').classList.contains('on'), null, { timeout: 10000 });
  if (shots) await page.screenshot({ path: shots + '/kiosk-ready.png' });

  const results = [];
  const timings = [];
  async function wedge(code) {
    // Scanner USB: karakter beruntun cepat lalu Enter; fokus bisa di mana saja.
    const t0 = Date.now();
    await page.keyboard.type(code, { delay: 2 });
    await page.keyboard.press('Enter');
    await page.waitForFunction((prev) => {
      const s = document.querySelector('#k-result').getAttribute('data-state');
      return s !== 'ready' && s !== 'busy' && s !== prev;
    }, '__none__', { timeout: 8000 });
    const t = Date.now() - t0;
    const state = await page.$eval('#k-result', (el) => el.getAttribute('data-state'));
    const title = await page.$eval('#k-title', (el) => el.textContent);
    results.push({ code, state, title });
    timings.push(t);
    return state;
  }
  let i = 0;
  for (const c of codes) {
    const st = await wedge(c);
    if (shots && i === 0) await page.screenshot({ path: shots + '/kiosk-' + st + '.png' });
    i++;
    // siap scan otomatis: tunggu kembali ke ready (maks 3,5 detik), kecuali diuji beruntun
    if (i % 6 === 0) await page.waitForFunction(() => document.querySelector('#k-result').getAttribute('data-state') === 'ready', null, { timeout: 5000 });
    else await page.waitForTimeout(150);
  }
  // Duplikat, salah kelas, kartu tidak berlaku
  await page.waitForTimeout(3100);
  const dup = await wedge(codes[0]); if (shots) await page.screenshot({ path: shots + '/kiosk-dup.png' });
  await page.waitForTimeout(3100);
  const wc = wrong ? await wedge(wrong) : 'skip'; if (shots && wrong) await page.screenshot({ path: shots + '/kiosk-wrongclass.png' });
  await page.waitForTimeout(3100);
  const inv = await wedge('ZZZZZZZZZZZZ'); if (shots) await page.screenshot({ path: shots + '/kiosk-invalid.png' });

  const okCount = results.slice(0, codes.length).filter((r) => r.state === 'ok' || r.state === 'late').length;
  const sorted = timings.slice().sort((a, b) => a - b);
  const p95 = sorted[Math.max(0, Math.ceil(sorted.length * 0.95) - 1)];
  const avg = Math.round(timings.reduce((a, b) => a + b, 0) / timings.length);
  const stats = await page.$eval('#k-stats', (el) => el.textContent);
  console.log(`siswa tercatat: ${okCount}/${codes.length}; dup=${dup} wrongclass=${wc} invalid=${inv}`);
  console.log(`t_total (ketik 12 karakter + Enter → tampil) rata-rata ${avg} ms, p95 ${p95} ms; statistik kiosk: ${stats}`);
  await browser.close();
  let fail = 0;
  if (okCount !== codes.length) { console.log('FAIL: tidak semua siswa tercatat', results.filter((r) => r.state !== 'ok' && r.state !== 'late')); fail = 1; }
  if (dup !== 'dup') { console.log('FAIL: duplikat tidak terdeteksi: ' + dup); fail = 1; }
  if (wrong && wc !== 'wrongclass') { console.log('FAIL: salah kelas tidak terdeteksi: ' + wc); fail = 1; }
  if (inv !== 'invalid') { console.log('FAIL: kartu tidak berlaku tidak terdeteksi: ' + inv); fail = 1; }
  if (errors.length) { console.log('FAIL: error JavaScript di halaman:', errors); fail = 1; }
  process.exit(fail);
})().catch((e) => { console.error(e); process.exit(1); });
