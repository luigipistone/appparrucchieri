const CACHE_NAME = 'barber-booking-v20260513-11';
const APP_SHELL = [
  './',
  './index.php',
  './manifest.webmanifest',
  './assets/styles.css?v=20260513-11',
  './assets/booking-app.js?v=20260513-11',
  './assets/icon.svg'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(APP_SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  if (request.method !== 'GET' || url.pathname.endsWith('/api.php')) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match('./index.php')));
    return;
  }

  event.respondWith(caches.match(request).then(cached => cached || fetch(request)));
});

self.addEventListener('push', event => {
  event.waitUntil(showLatestNotification());
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
      const existing = clients.find(client => 'focus' in client);
      if (existing) return existing.focus();
      if (self.clients.openWindow) return self.clients.openWindow('./');
      return undefined;
    })
  );
});

async function showLatestNotification() {
  let title = 'Nuova notifica';
  let options = { body: 'Apri Barber Booking per vedere gli aggiornamenti.', icon: 'assets/icon.svg', badge: 'assets/icon.svg' };

  try {
    const response = await fetch('./api.php?action=notifications', { credentials: 'include', cache: 'no-store' });
    const payload = await response.json();
    const notification = payload.notifications?.[0];
    if (notification) {
      title = notification.title || title;
      options = { ...options, body: notification.body || options.body, tag: `barber-notification-${notification.id}` };
    }
  } catch (error) {
    console.warn('Notifica push generica:', error);
  }

  return self.registration.showNotification(title, options);
}
