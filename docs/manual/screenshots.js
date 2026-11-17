/**
 * Tangkapan layar untuk Buku Panduan (docs/manual/img). Butuh instalasi berisi data demo + riwayat
 * (lihat docs/manual/README.md), server berjalan di BASE, dan KIOSK (JSON: pair, codes, wrong, session).
 *   BASE=http://127.0.0.1:8799 KIOSK=/path/kiosk.json OUT=docs/manual/img node docs/manual/screenshots.js
 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8799';
const OUT = process.env.OUT || 'docs/manual/img';
const K = JSON.parse(fs.readFileSync(process.env.KIOSK, 'utf8'));
const DESK = { width: 1280, height: 800 };
const PHONE = { width: 390, height: 844 };

(async () => {
  const b = await chromium.launch();
  const errs = [];
  async function ctx(vp, mobile = false) {
    const c = await b.newContext({ viewport: vp, deviceScaleFactor: mobile ? 2 : 1.5, timezoneId: process.env.TZ_ID || 'Asia/Makassar', isMobile: mobile, hasTouch: mobile });
    const p = await c.newPage();
    p.on('pageerror', (e) => errs.push(String(e)));
    return p;
  }
  async function login(p, u, pw) {
    await p.goto(BASE + '/masuk');
    await p.fill('[name=username]', u); await p.fill('[name=password]', pw);
    await Promise.all([p.waitForNavigation(), p.click('form[action$="masuk"] button[type=submit]')]);
  }
  const shot = async (p, name, opt = {}) => { await p.waitForTimeout(250); await p.screenshot({ path: `${OUT}/${name}.jpg`, type: 'jpeg', quality: 80, fullPage: !!opt.full, clip: opt.clip }); console.log('ok', name); };
  const go = async (p, path, name, opt = {}) => { await p.goto(BASE + path); if (opt.before) await opt.before(p); await shot(p, name, opt); };

  // Kiosk: pasangkan, siap, scan beruntun
  const k = await ctx({ width: 1280, height: 800 });
  k.on('response', async (r) => { if (r.url().includes('/api/scan')) { try { const j = await r.json(); if (j.session && j.session.id) K.session = j.session.id; } catch (e) {} } });
  await k.goto(BASE + '/kiosk'); await k.waitForSelector('#k-pair:not([hidden])');
  await k.fill('#k-code', String(K.pair)); await shot(k, 'kiosk-pasang');
  await k.click('#k-pair-form button'); await k.waitForSelector('#k-main:not([hidden])');
  await k.waitForFunction(() => document.querySelector('#k-net').classList.contains('on'));
  await shot(k, 'kiosk-siap');
  async function wedge(code) {
    await k.keyboard.type(code, { delay: 2 }); await k.keyboard.press('Enter');
    await k.waitForFunction(() => !['ready', 'busy'].includes(document.querySelector('#k-result').getAttribute('data-state')), null, { timeout: 8000 });
  }
  const codes = K.codes.filter((_, i) => i < 27);
  for (let i = 0; i < codes.length; i++) { await wedge(codes[i]); if (i === 3) await shot(k, 'kiosk-hadir'); await k.waitForTimeout(120); }
  await k.waitForTimeout(3200); await wedge(codes[0]); await shot(k, 'kiosk-sudah');
  await k.waitForTimeout(3200); await wedge(K.wrong); await shot(k, 'kiosk-salah-kelas');
  await k.waitForTimeout(3200); await wedge('ZZZZZZZZZZZZ'); await shot(k, 'kiosk-tidak-berlaku');
  if (process.env.LATE_HOOK) { require('child_process').execSync(process.env.LATE_HOOK); await k.waitForTimeout(16000); }
  await k.waitForTimeout(3200); await wedge(K.codes[28]); await shot(k, 'kiosk-terlambat');
  await k.waitForTimeout(3200); await wedge(K.codes[29]);

  // Super Admin
  const p = await ctx(DESK);
  await go(p, '/masuk', 'masuk');
  await login(p, 'superadmin', 'Dev12345678');
  await go(p, '/admin', 'dashboard-super', { full: true });
  await go(p, '/admin/pengaturan', 'pengaturan', { full: true });
  await go(p, '/admin/tahun-ajaran', 'tahun-ajaran');
  await go(p, '/admin/master/jam', 'master-jam');
  await go(p, '/admin/master/libur', 'master-libur');
  await go(p, '/admin/kelas', 'kelas');
  await go(p, '/admin/kelas/1', 'kelas-detail');
  await go(p, '/admin/siswa', 'siswa');
  await go(p, '/admin/siswa/' + K.sick, 'siswa-detail', { full: true });
  await go(p, '/admin/impor', 'impor');
  await go(p, '/admin/jadwal?kelas=1', 'jadwal');
  await go(p, '/admin/kartu', 'kartu');
  await go(p, '/admin/perangkat', 'perangkat');
  await go(p, '/admin/akun', 'akun');
  await go(p, '/admin/sesi', 'sesi');
  await go(p, '/admin/sesi/' + K.session, 'sesi-live', { full: true });
  await p.goto(BASE + '/admin/sesi/' + K.session); await p.click('[data-fix]'); await shot(p, 'sesi-koreksi');
  await go(p, '/admin/izin', 'izin');
  await go(p, '/admin/izin/baru?kelas=1', 'izin-form');
  await go(p, '/admin/rekap', 'rekap');
  await go(p, '/admin/rekap/kelas/1', 'rekap-bulanan');
  await go(p, '/admin/rekap/kelas/1?jenis=harian&tanggal=' + (process.env.DAY || ''), 'rekap-harian');
  await go(p, '/admin/rekap/kelas/1?jenis=semester', 'rekap-semester');
  await go(p, '/admin/rekap/siswa/' + K.sick, 'rekap-siswa');
  await go(p, '/admin/rekap/sekolah', 'rekap-sekolah');
  await go(p, '/admin/kecepatan?hari=7', 'kecepatan');
  await go(p, '/admin/notifikasi', 'notifikasi');
  await go(p, '/admin/kesehatan', 'kesehatan', { full: true });
  await go(p, '/admin/pilot', 'pilot', { full: true });
  await go(p, '/admin/pilot/banding/1?tanggal=' + (process.env.PILOT_DAY || ''), 'pilot-banding');
  await go(p, '/admin/masukan', 'masukan');
  await go(p, '/admin/audit', 'audit');
  // Kode portal siswa
  await p.goto(BASE + '/admin/siswa/' + K.sick);
  await p.click('form[action$="/kode-portal"] button');
  if (await p.isVisible('#confirm-ok')) await p.click('#confirm-ok'); // kode lama ada → konfirmasi buat ulang
  await p.waitForSelector('[data-portal-code]');
  const code = await p.$eval('[data-portal-code]', (e) => e.textContent.trim());
  await (await p.$('.card:has([data-portal-code])')).screenshot({ path: `${OUT}/portal-kode.jpg`, type: 'jpeg', quality: 80 });

  // Guru (wali kelas) dan TU
  const g = await ctx(DESK); await login(g, 'guru01', 'guru12345');
  await go(g, '/admin', 'dashboard-wali', { full: true });
  const t = await ctx(DESK); await login(t, 'tu.sekolah', 'tu12345678');
  await go(t, '/admin', 'dashboard-tu');

  // Ponsel
  const m = await ctx(PHONE, true); await login(m, 'guru01', 'guru12345');
  await go(m, '/admin', 'hp-dashboard');
  await go(m, '/admin/sesi/' + K.session, 'hp-sesi');
  const ks = await ctx(PHONE, true); await login(ks, 'kepsek', 'kepsek12345');
  await go(ks, '/admin', 'hp-kepsek');
  const o = await ctx(PHONE, true);
  await go(o, '/portal', 'portal-masuk');
  await o.fill('[name=nis]', K.sick_nis); await o.fill('[name=code]', code);
  await Promise.all([o.waitForNavigation(), o.click('form[action$="portal/masuk"] button')]);
  await shot(o, 'portal-anak', { full: true });
  if (errs.length) console.log('JS error:', errs);
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
