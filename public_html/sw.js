/**
 * Tourfecto - Service Worker
 * استراتيجية آمنة ومحافظة: بنخزّن الأصول الثابتة بس (CSS/JS/خطوط/أيقونات)
 * عشان الموقع يفتح بسرعة ويشتغل كـ PWA قابل للتثبيت. أي طلب API أو أي
 * صفحة HTML ديناميكية بيروح للشبكة مباشرة دايمًا - عمدًا - عشان محدش
 * يشوف بيانات قديمة (تحليلات، رسائل شات، إشعارات...) وهي فاكرة إنها
 * محدّثة. لو النت مقطوع، الصفحة اللي كانت مفتوحة قبل كده هتفضل شغالة
 * (مش هنعمل offline page كامل - مش مناسب لأداة SaaS بيانات حيّة).
 */
// إشعارات OneSignal: نفس الـ Service Worker بتاع الـ PWA (اتنين على نفس النطاق بيشيلوا بعض).
// لو الـ CDN واقع مانوقفش الـ PWA.
try { importScripts('https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js'); } catch (e) {}

const CACHE_VERSION = 'tourfecto-static-v5';
const STATIC_ASSETS = [
    '/assets/css/style.css',
    '/assets/css/panel.css',
    '/assets/css/compass.css',
    '/assets/js/panel.js',
    '/assets/icons/icon-192.png',
    '/assets/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) => cache.addAll(STATIC_ASSETS)).catch(() => {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // أي حاجة API أو أي طلب مش GET - على طول للشبكة، مفيش cache خالص
    if (url.pathname.startsWith('/api/') || event.request.method !== 'GET') {
        return;
    }

    // أصول ثابتة بس (CSS/JS/صور) - cache-first عشان السرعة
    const isStaticAsset = url.pathname.startsWith('/assets/') &&
        /\.(css|js|png|jpg|jpeg|svg|woff2?)$/i.test(url.pathname);

    if (isStaticAsset) {
        // ملف عليه رقم نسخة (?v=) مش هيتغير: من الكاش على طول عشان السرعة.
        // ملف من غير رقم: من الشبكة الأول عشان أي تحديث يوصل فورًا، والكاش لو النت فاصل بس.
        const versioned = url.searchParams.has('v');
        const save = (response) => {
            if (response && response.ok) {
                const clone = response.clone();
                caches.open(CACHE_VERSION).then((cache) => cache.put(event.request, clone));
            }
            return response;
        };
        event.respondWith(versioned
            ? caches.match(event.request).then((cached) => cached || fetch(event.request).then(save))
            : fetch(event.request).then(save).catch(() => caches.match(event.request))
        );
    }
    // أي صفحة HTML أو أي حاجة تانية: نسيبها تروح للشبكة عادي من غير تدخل
});
