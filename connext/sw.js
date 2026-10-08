



const CACHE = 'connext-v1';  
const CACHE_FILES = [
  './fonts/prompt-v12-latin_thai-regular.woff2',
  './fonts/prompt-v12-latin_thai-500.woff2',
  './fonts/prompt-v12-latin_thai-600.woff2',
  './icons/icon-192.png',
  './icons/icon-512.png'
];
const STATIC_RE = /\.(css|js|woff2?|ttf|png|jpg|jpeg|webp|svg|ico)$/i;

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE)
    .then(c => c.addAll(CACHE_FILES.map(u => new Request(u, { cache: 'reload' }))))
    .then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET') return;
   
  if (url.pathname.includes('/api/')) return;
   
  if (e.request.mode === 'navigate' && url.origin === self.location.origin) {
    e.respondWith(fetch(e.request).then(res => {
      if (res && res.ok) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {}); }
      return res;
    }).catch(() => caches.match(e.request, { ignoreSearch: true })));
    return;
  }
   
  e.respondWith(fetch(e.request).then(res => {
    if (res && res.ok && url.origin === self.location.origin && STATIC_RE.test(url.pathname)) {
      const copy = res.clone();
      caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
    }
    return res;
  }).catch(() =>
    caches.match(e.request).then(hit => hit || caches.match(e.request, { ignoreSearch: true }))
  ));
});
