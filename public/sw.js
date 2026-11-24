/**
 * Service worker för Tadega Lager.
 *
 * Medvetet enkel och säker:
 *   – Cachar bara appens skal (CSS, JS, ikoner) och en offline-sida.
 *   – Cachar ALDRIG data (sidor som kräver inloggning, JSON, bilder med mera).
 *   – Nätet går alltid först; cachen används bara när nätet inte svarar.
 *
 * Det gör att appen startar snabbt även med dålig täckning i en lagerlokal,
 * utan att någonsin visa gamla saldon.
 */
const VERSION = 'tdl-v4';

// Basvägen: service worker-filen ligger i public/ (eller i roten), och
// self.registration.scope är den katalog den registrerades från. Ligger
// appen i /lager blir scopen /lager/ – då måste skalets adresser ha samma
// prefix, annars cachas inget.
const BAS = new URL(self.registration.scope).pathname.replace(/\/$/, '');

const SKAL = [
    '/assets/app.css',
    '/assets/app.js',
    '/assets/qrcode.js',
    '/assets/ikoner/ikon-256.png',
    '/assets/ikoner/marke-32.png',
    '/offline'
].map((u) => BAS + u);

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(VERSION).then((c) => Promise.allSettled(SKAL.map((u) => c.add(u)))).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((nycklar) => Promise.all(nycklar.filter((n) => n !== VERSION).map((n) => caches.delete(n))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    const ärSkal = /\/(assets\/.+\.(css|js|png|svg|ico|webmanifest))$/.test(url.pathname);

    if (!ärSkal) {
        // Sidor och data: nätet först, offline-sidan om nätet är borta
        e.respondWith(
            fetch(req).catch(() =>
                req.mode === 'navigate' ? caches.match(BAS + '/offline') : Response.error()
            )
        );
        return;
    }

    e.respondWith(
        caches.match(req).then((cachad) => {
            const hämtning = fetch(req)
                .then((svar) => {
                    if (svar && svar.ok) {
                        const kopia = svar.clone();
                        caches.open(VERSION).then((c) => c.put(req, kopia));
                    }
                    return svar;
                })
                .catch(() => cachad);
            return cachad || hämtning;
        })
    );
});
