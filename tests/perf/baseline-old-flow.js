// Baseline alur scan LAMA (smartcity-attendance): validate -> klik Simpan -> save -> Scan Berikutnya.
// Jalankan terhadap repo asal: node tests/perf/baseline-old-flow.js 25 '["SC...","SC..."]' (kode peserta berstatus belum).
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8000';
const N = parseInt(process.argv[2] || '40', 10);
const codes = JSON.parse(process.argv[3]);

function pct(a, p) { const s = [...a].sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor(p / 100 * s.length))]; }
function stats(name, a) { console.log(`${name}: n=${a.length} p50=${pct(a, 50).toFixed(0)}ms p95=${pct(a, 95).toFixed(0)}ms max=${Math.max(...a).toFixed(0)}ms`); }

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto(BASE + '/masuk');
  await page.fill('input[name=username]', 'petugas.a');
  await page.fill('input[name=password]', 'Baseline12345');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  await page.goto(BASE + '/petugas/scan');
  await page.waitForSelector('#manual-code');

  // 1) API-only: validate + save round trips as the browser does them
  const api = await page.evaluate(async (codes) => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const ev = parseInt(document.getElementById('scan-app').dataset.event, 10);
    const post = (u, b) => fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(Object.assign({ event_id: ev }, b)) }).then(r => r.json());
    const out = [];
    for (const code of codes) {
      const t0 = performance.now();
      const v = await post('/api/scan/validate', { code });
      const t1 = performance.now();
      let t2 = t1, st = v.state;
      if (v.state === 'valid') { const s = await post('/api/scan/save', { participant_id: v.participant.id, via: 'scan' }); t2 = performance.now(); st = s.state; }
      out.push({ validate: t1 - t0, save: t2 - t1, total: t2 - t0, state: st });
    }
    return out;
  }, codes.slice(0, N));
  stats('API validate', api.map(x => x.validate));
  stats('API save', api.filter(x => x.save > 0).map(x => x.save));
  stats('API validate+save', api.map(x => x.total));
  console.log('states', api.reduce((m, x) => (m[x.state] = (m[x.state] || 0) + 1, m), {}));

  // 2) UI flow: typed code (like a keyboard-wedge scanner) -> "QR valid" -> click Simpan -> "Kehadiran tersimpan" -> Scan Berikutnya
  const ui = [];
  for (const code of codes.slice(N, 2 * N)) {
    await page.fill('#manual-code input[name=code]', code);
    const t0 = Date.now();
    await page.press('#manual-code input[name=code]', 'Enter');
    await page.waitForSelector('#scan-result [data-act=save]');
    const t1 = Date.now();
    await page.click('#scan-result [data-act=save]');
    await page.waitForSelector('#scan-result .s-ok');
    const t2 = Date.now();
    await page.click('#scan-result [data-act=next]');
    await page.waitForFunction(() => /Siap memindai/.test(document.getElementById('scan-result').textContent));
    const t3 = Date.now();
    ui.push({ toValid: t1 - t0, toSaved: t2 - t1, toReady: t3 - t2, total: t3 - t0 });
  }
  stats('UI code->QR valid', ui.map(x => x.toValid));
  stats('UI click Simpan->tersimpan', ui.map(x => x.toSaved));
  stats('UI ->siap berikutnya', ui.map(x => x.toReady));
  stats('UI total (tanpa waktu manusia)', ui.map(x => x.total));
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
