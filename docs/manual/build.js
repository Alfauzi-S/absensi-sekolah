/**
 * Membuat docs/Manual-Book-Absensi-Sekolah.pdf dari bab Markdown di folder ini (NN-*.md) dan tangkapan layar di img/.
 * Format mengikuti manual book lama: sampul gelap, Daftar Isi, label "BAB N", subbab 1.1, "Gambar N.", footer "Halaman N dari M".
 *
 * Pakai:  NODE_PATH=$(npm root -g) node docs/manual/build.js [--version 1.0] [--date "November 2026"]
 * Butuh:  playwright (Chromium), marked (npm i marked), python3 + pypdf (untuk menggabung sampul tanpa nomor halaman).
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');
const { marked } = require('marked');

const DIR = __dirname;
const ROOT = path.resolve(DIR, '..', '..');
const OUT = path.join(ROOT, 'docs', 'Manual-Book-Absensi-Sekolah.pdf');
const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d; };
const VERSION = arg('version', '1.0');
const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const DATE = arg('date', MONTHS[new Date().getMonth()] + ' ' + new Date().getFullYear());
const APP = 'Absensi Sekolah';

const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const svg = (f) => fs.readFileSync(path.join(ROOT, 'public', 'assets', 'img', f), 'utf8');

// ---- Bab ----
const files = fs.readdirSync(DIR).filter((f) => /^\d\d-.*\.md$/.test(f)).sort();
let fig = 0;
const chapters = files.map((f, ci) => {
  const n = ci + 1;
  let src = fs.readFileSync(path.join(DIR, f), 'utf8');
  const title = (src.match(/^# (.+)$/m) || [, f])[1].trim();
  src = src.replace(/^# .+$/m, '');
  const sections = [];
  let sec = 0;
  const renderer = new marked.Renderer();
  renderer.heading = (text, level) => {
    if (level === 2) { sec++; sections.push(text); return `<h2><span class="no">${n}.${sec}</span> ${text}</h2>`; }
    return `<h${level}>${text}</h${level}>`;
  };
  renderer.image = (href, _t, alt) => {
    fig++;
    const file = path.join(DIR, href);
    const cls = /kiosk|hp-|portal-(masuk|anak)/.test(href) ? (href.includes('hp-') || href.includes('portal-') ? 'phone' : 'kiosk') : 'desk';
    return `<figure class="${cls}"><img src="file://${file}" alt="${esc(alt)}"><figcaption><b>Gambar ${fig}.</b> ${esc(alt)}.</figcaption></figure>`;
  };
  // Gambar sendirian di paragraf → jangan dibungkus <p>
  renderer.paragraph = (text) => (/^<figure/.test(text.trim()) ? text : `<p>${text}</p>`);
  const html = marked.parse(src, { renderer, gfm: true });
  return { n, title, sections, html };
});

// ---- HTML ----
const css = `
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@600;700&display=swap');
:root { --navy:#0B1220; --navy2:#142238; --cyan:#35D5F2; --teal:#23C7A5; --ink:#0f172a; --mute:#64748b; --line:#e2e8f0; --cyan-d:#0891b2; }
* { box-sizing: border-box; }
html, body { margin: 0; font-family: Inter, 'Inter Display', system-ui, sans-serif; color: var(--ink); font-size: 10.2pt; line-height: 1.6; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
h1, h2, h3, .toc-t { font-family: Sora, 'Inter Display', Inter, sans-serif; letter-spacing: -.01em; }
.page-break { break-before: page; }
/* Daftar Isi */
.toc h1 { font-size: 22pt; margin: 0 0 18pt; }
.toc-row { display: grid; grid-template-columns: 22pt 1fr; padding: 7pt 0; border-bottom: 1px solid var(--line); break-inside: avoid; }
.toc-row .no { color: var(--cyan-d); font-weight: 700; }
.toc-row .t { font-weight: 600; }
.toc-row .subs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1pt 14pt; color: #334155; font-size: 8.4pt; margin-top: 3pt; }
/* Bab */
.bab { break-before: page; }
.bab-l { color: var(--cyan-d); font-weight: 700; font-size: 8.5pt; letter-spacing: .18em; text-transform: uppercase; }
.bab h1 { font-size: 21pt; margin: 2pt 0 14pt; }
h2 { font-size: 13pt; margin: 18pt 0 6pt; padding-bottom: 4pt; border-bottom: 1px solid var(--line); break-after: avoid; }
h2 .no { color: var(--ink); }
h3 { font-size: 11pt; margin: 12pt 0 4pt; break-after: avoid; }
p { margin: 0 0 7pt; }
ul, ol { margin: 0 0 8pt; padding-left: 16pt; }
li { margin: 0 0 3pt; }
li strong:first-child { color: var(--ink); }
code { font-family: 'DejaVu Sans Mono', monospace; font-size: 8.6pt; background: #f1f5f9; border-radius: 3px; padding: 0 3px; }
pre { background: var(--navy); color: #e2e8f0; border-radius: 6px; padding: 8pt 10pt; font-size: 8.4pt; line-height: 1.5; break-inside: avoid; white-space: pre-wrap; }
pre code { background: none; color: inherit; padding: 0; }
table { width: 100%; border-collapse: collapse; margin: 4pt 0 10pt; font-size: 9pt; break-inside: auto; }
th { text-align: left; background: #f1f5f9; font-weight: 600; }
th, td { border: 1px solid var(--line); padding: 4pt 6pt; vertical-align: top; }
tr { break-inside: avoid; }
figure { margin: 8pt 0 12pt; text-align: center; break-inside: avoid; }
figure img { border: 1px solid var(--line); border-radius: 6px; max-width: 100%; max-height: 150mm; object-fit: contain; }
figure.kiosk img { max-height: 92mm; }
figure.phone img { max-height: 125mm; }
figcaption { font-size: 8.4pt; color: var(--mute); margin-top: 4pt; }
figcaption b { color: var(--ink); }
`;

const coverCss = `
@page { size: A4; margin: 0; }
html, body { margin: 0; height: 100%; font-family: Inter, 'Inter Display', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.cover { position: relative; width: 210mm; height: 297mm; overflow: hidden; background: radial-gradient(ellipse at 80% 85%, #10324a 0%, #0B1220 55%); color: #fff; }
.brand { position: absolute; top: 34mm; left: 22mm; display: flex; align-items: center; gap: 4mm; }
.brand .ic { width: 14mm; height: 14mm; }
.brand .ic svg { width: 100%; height: 100%; }
.brand b { font-family: Sora, 'Inter Display', sans-serif; font-size: 13pt; display: block; }
.brand span { font-size: 8pt; color: #94a3b8; }
.mid { position: absolute; top: 92mm; left: 22mm; right: 22mm; }
.kick { color: #35D5F2; font-weight: 700; font-size: 8.5pt; letter-spacing: .2em; }
h1 { font-family: Sora, 'Inter Display', sans-serif; font-size: 38pt; line-height: 1.08; margin: 6mm 0 6mm; letter-spacing: -.02em; }
.lead { color: #cbd5e1; font-size: 11pt; line-height: 1.6; max-width: 130mm; }
.city { position: absolute; right: -6mm; bottom: 18mm; width: 150mm; opacity: .95; }
.city svg { width: 100%; height: auto; }
.foot { position: absolute; left: 22mm; bottom: 20mm; font-size: 8.5pt; color: #cbd5e1; }
.foot b { color: #fff; }
`;

const cover = `<!doctype html><html lang="id"><head><meta charset="utf-8"><style>@import url('https://fonts.googleapis.com/css2?family=Sora:wght@600;700&display=swap');${coverCss}</style></head><body>
<div class="cover">
  <div class="brand"><span class="ic">${svg('icon.svg')}</span><div><b>${APP}</b><span>Absensi siswa dengan kartu QR</span></div></div>
  <div class="mid">
    <div class="kick">MANUAL BOOK · DOKUMENTASI PENGGUNA</div>
    <h1>Absensi<br>Sekolah</h1>
    <p class="lead">Panduan lengkap aplikasi absensi siswa dengan kartu QR: pemasangan, persiapan data, scanner kelas, izin dan koreksi, rekap, notifikasi orang tua, serta pilot dan go-live.</p>
  </div>
  <div class="city">${svg('city.svg')}</div>
  <div class="foot"><b>Versi ${esc(VERSION)}</b> · ${esc(DATE)}<br>Untuk Super Admin, TU, kepala sekolah, guru, dan wali kelas</div>
</div></body></html>`;

const toc = `<section class="toc"><h1 class="toc-t">Daftar Isi</h1>${chapters.map((c) => `<div class="toc-row"><span class="no">${c.n}</span><div><div class="t">${esc(c.title)}</div>${c.sections.length ? `<div class="subs">${c.sections.map((s) => `<span>${s}</span>`).join('')}</div>` : ''}</div></div>`).join('')}</section>`;
const body = chapters.map((c) => `<section class="bab"><div class="bab-l">Bab ${c.n}</div><h1>${esc(c.title)}</h1>${c.html}</section>`).join('\n');
const main = `<!doctype html><html lang="id"><head><meta charset="utf-8"><title>${APP} · Manual Book v${esc(VERSION)}</title><style>${css}</style></head><body>${toc}${body}</body></html>`;

const header = `<div style="width:100%;font-family:Inter,sans-serif;font-size:7pt;color:#64748b;padding:0 16mm;display:flex;justify-content:space-between"><span>${APP}</span><span>Manual Book v${esc(VERSION)}</span></div>`;
const footer = `<div style="width:100%;font-family:Inter,sans-serif;font-size:7pt;color:#64748b;text-align:center">Halaman <span class="pageNumber"></span> dari <span class="totalPages"></span></div>`;

(async () => {
  const tmp = fs.mkdtempSync(path.join(require('os').tmpdir(), 'manual-'));
  fs.writeFileSync(path.join(tmp, 'cover.html'), cover);
  fs.writeFileSync(path.join(tmp, 'main.html'), main);
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto('file://' + path.join(tmp, 'cover.html'), { waitUntil: 'networkidle' }).catch(() => {});
  await page.pdf({ path: path.join(tmp, 'cover.pdf'), format: 'A4', printBackground: true, preferCSSPageSize: true });
  await page.goto('file://' + path.join(tmp, 'main.html'), { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
  await page.pdf({ path: path.join(tmp, 'main.pdf'), format: 'A4', printBackground: true, displayHeaderFooter: true, headerTemplate: header, footerTemplate: footer,
    margin: { top: '20mm', bottom: '18mm', left: '16mm', right: '16mm' } });
  await browser.close();
  // Gabung sampul + isi; nomor halaman isi tidak menghitung sampul (sama dengan manual lama)
  execFileSync('python3', ['-c', `
import sys
from pypdf import PdfWriter, PdfReader
w = PdfWriter()
for f in sys.argv[1:3]:
    for p in PdfReader(f).pages: w.add_page(p)
w.add_metadata({'/Title': '${APP} · Manual Book v${VERSION}', '/Subject': 'Dokumentasi pengguna'})
w.compress_identical_objects() if hasattr(w, 'compress_identical_objects') else None
w.write(sys.argv[3])
`, path.join(tmp, 'cover.pdf'), path.join(tmp, 'main.pdf'), OUT]);
  fs.rmSync(tmp, { recursive: true, force: true });
  console.log('OK', path.relative(ROOT, OUT), `(${chapters.length} bab, ${fig} gambar)`);
})();
