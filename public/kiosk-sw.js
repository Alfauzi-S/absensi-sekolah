/* Service worker kiosk (Fase 4): menyimpan halaman kiosk + CSS/JS agar scanner tetap bisa dibuka saat jaringan putus
   atau tablet dinyalakan ulang tanpa internet. API (/api/…) tidak pernah di-cache: scan memakai antrean IndexedDB di kiosk.js. */
var CACHE = 'absensi-kiosk-v1';
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('absensi-kiosk-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});
function put(req, res) {
  if (res && res.ok && res.type === 'basic') { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); }
  return res;
}
function timeout(ms) { return new Promise(function (_, reject) { setTimeout(function () { reject(new Error('timeout')); }, ms); }); }
self.addEventListener('fetch', function (e) {
  var req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;
  if (url.pathname.indexOf('/api/') !== -1) return;
  if (req.mode === 'navigate') {
    // Halaman: jaringan dulu (maks 3 detik) agar pembaruan cepat terpakai; gagal → salinan tersimpan
    e.respondWith(Promise.race([fetch(req).then(function (res) { return put(req, res); }), timeout(3000)])
      .catch(function () { return caches.match(req, { ignoreSearch: true }).then(function (hit) { return hit || Response.error(); }); }));
    return;
  }
  if (url.pathname.indexOf('/assets/') !== -1) {
    // Aset berversi (?v=mtime): salinan tersimpan dulu, perbarui di belakang
    e.respondWith(caches.match(req).then(function (hit) {
      var net = fetch(req).then(function (res) { return put(req, res); }).catch(function () { return hit || caches.match(req, { ignoreSearch: true }); });
      return hit || net;
    }));
    return;
  }
  if (url.pathname.indexOf('/kiosk/foto/') !== -1) {
    e.respondWith(caches.match(req).then(function (hit) { return hit || fetch(req).then(function (res) { return put(req, res); }); }));
  }
});
