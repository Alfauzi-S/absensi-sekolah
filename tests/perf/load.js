/**
 * Uji beban POST /api/scan (pengganti k6, tanpa dependensi): laju tetap (open loop) dari beberapa perangkat virtual, koneksi keep-alive.
 * Pakai: node tests/perf/load.js <seed.json> <base> <tahap…>   tahap = "laju:detik", mis. 20:60 100:30 200:10
 * Tiap tahap melaporkan p50/p95/p99 waktu server (t_server_ms dari jawaban) dan waktu bolak-balik, serta persen error.
 */
const http = require('http');
const fs = require('fs');
const seed = JSON.parse(fs.readFileSync(process.argv[2], 'utf8')).load;
const base = new URL(process.argv[3] || 'http://127.0.0.1:8766');
const stages = process.argv.slice(4).map((s) => s.split(':').map(Number));
const agent = new http.Agent({ keepAlive: true, maxSockets: 256 });
let seq = 0, codeIdx = 0;
const pct = (a, p) => { if (!a.length) return 0; const s = [...a].sort((x, y) => x - y); return s[Math.max(0, Math.ceil(s.length * p / 100) - 1)]; };

function shot(stat) {
  const token = seed.tokens[seq % seed.tokens.length];
  const code = seed.codes[codeIdx++ % seed.codes.length]; // lewat satu putaran → jalur duplikat
  const body = JSON.stringify({ scan_id: 'load-' + process.pid + '-' + (seq++).toString().padStart(8, '0'), code });
  const t0 = process.hrtime.bigint();
  stat.sent++;
  const req = http.request({ host: base.hostname, port: base.port, path: '/api/scan', method: 'POST', agent, timeout: 10000,
    headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body), 'X-Device-Token': token } }, (res) => {
    let buf = '';
    res.on('data', (c) => { buf += c; });
    res.on('end', () => {
      const ms = Number(process.hrtime.bigint() - t0) / 1e6;
      stat.done++;
      try {
        const d = JSON.parse(buf);
        if (res.statusCode !== 200 || !['ok', 'late', 'dup'].includes(d.state)) { stat.errors++; stat.lastErr = res.statusCode + ' ' + (d.state || ''); return; }
        stat.rt.push(ms); stat.srv.push(d.t_server_ms); stat.states[d.state] = (stat.states[d.state] || 0) + 1;
      } catch (e) { stat.errors++; stat.lastErr = 'json ' + res.statusCode; }
    });
  });
  req.on('timeout', () => { req.destroy(new Error('timeout')); });
  req.on('error', (e) => { stat.done++; stat.errors++; stat.lastErr = e.message; });
  req.end(body);
}

(async () => {
  const out = [];
  for (const [rate, secs] of stages) {
    const stat = { rate, secs, sent: 0, done: 0, errors: 0, rt: [], srv: [], states: {}, lastErr: '' };
    const start = Date.now(), total = rate * secs;
    await new Promise((resolve) => {
      const tick = setInterval(() => {
        const due = Math.min(total, Math.floor((Date.now() - start) / 1000 * rate));
        while (stat.sent < due) shot(stat);
        if (stat.sent >= total) { clearInterval(tick); resolve(); }
      }, 5);
    });
    const waitStart = Date.now();
    while (stat.done < stat.sent && Date.now() - waitStart < 15000) await new Promise((r) => setTimeout(r, 50));
    const elapsed = (Date.now() - start) / 1000;
    const row = { laju_target: rate, detik: secs, terkirim: stat.sent, selesai: stat.done, laju_nyata: +(stat.done / elapsed).toFixed(1), error_pct: +((stat.errors + (stat.sent - stat.done)) / stat.sent * 100).toFixed(2),
      server_p50: +pct(stat.srv, 50).toFixed(1), server_p95: +pct(stat.srv, 95).toFixed(1), server_p99: +pct(stat.srv, 99).toFixed(1),
      bolakbalik_p50: +pct(stat.rt, 50).toFixed(1), bolakbalik_p95: +pct(stat.rt, 95).toFixed(1), bolakbalik_p99: +pct(stat.rt, 99).toFixed(1), hasil: stat.states, error_terakhir: stat.lastErr };
    out.push(row);
    console.log(JSON.stringify(row));
  }
  agent.destroy();
  if (process.env.OUT) fs.writeFileSync(process.env.OUT, JSON.stringify(out, null, 2));
})();
