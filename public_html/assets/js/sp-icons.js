/**
 * Tourfecto - أيقونات السوشيال (SVG خطّي حديث + شعارات المنصات بألوانها).
 * SPI.i(name, size)  أيقونة واجهة (بتاخد لون النص currentColor)
 * SPI.brand(platform, size)  شعار المنصة في مربع ملوّن
 * SPI.hydrate(root)  بيحوّل <i data-ico="..."> و <i data-brand="..."> في الصفحة لأيقونات
 */
(function () {
    'use strict';
    const P = {
        plus: 'M12 5v14M5 12h14',
        story: '',
        report: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M16 13H8M16 17H8M10 9H8',
        overview: 'M4 4h6v8H4zM14 4h6v5h-6zM14 13h6v7h-6zM4 16h6v4H4z',
        posts: 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01',
        calendar: 'M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM16 2v4M8 2v4M3 10h18',
        planCheck: 'M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM16 2v4M8 2v4M3 10h18M9 16l2 2 4-4',
        chat: 'M7.9 20A9 9 0 1 0 4 16.1L2 22z',
        target: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 6a6 6 0 1 0 0 12 6 6 0 0 0 0-12zM12 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4z',
        link: 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
        gem: 'M6 3h12l4 6-10 13L2 9zM11 3 8 9l4 13 4-13-3-6M2 9h20',
        refresh: 'M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8M3 3v5h5M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16M16 16h5v5',
        upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
        image: 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM9 7a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM21 15l-3.1-3.1a2 2 0 0 0-2.8 0L6 21',
        images: 'M18 22H4a2 2 0 0 1-2-2V6M8 2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM12 6a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM22 13l-3-3-7 7',
        folder: 'M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z',
        sparkles: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM5 3v4M3 5h4M19 17v4M17 19h4',
        check: 'M20 6 9 17l-5-5',
        x: 'M18 6 6 18M6 6l12 12',
        alert: 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01',
        info: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 16v-4M12 8h.01',
        send: 'M22 2 11 13M22 2l-7 20-4-9-9-4z',
        edit: 'M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z',
        trash: 'M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2',
        copy: 'M10 8h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V10a2 2 0 0 1 2-2zM4 16a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2',
        lock: 'M5 11h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2zM7 11V7a5 5 0 0 1 10 0v4',
        users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        trend: 'M22 7 13.5 15.5 8.5 10.5 2 17M16 7h6v6',
        clock: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 6v6l4 2',
        trophy: 'M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22M18 2H6v7a6 6 0 0 0 12 0z',
        heart: 'M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7z',
        thumb: 'M7 10v12M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88z',
        share: 'M17 1l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3',
        eye: 'M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
        eyeOff: 'M9.88 9.88a3 3 0 1 0 4.24 4.24M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61M2 2l20 20',
        bars: 'M3 3v18h18M18 17V9M13 17V5M8 17v-3',
        pulse: 'M22 12h-4l-3 9L9 3l-3 9H2',
        receipt: 'M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1zM16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 17.5v-11',
        wallet: 'M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4z',
        inbox: 'M22 12h-6l-2 3h-4l-2-3H2M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z',
        mail: 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 7l-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7',
        bot: 'M5 11h14a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2zM12 3a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM12 7v4M8 16h.01M16 16h.01',
        video: 'M23 7l-7 5 7 5V7zM3 5h11a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z',
        hash: 'M4 9h16M4 15h16M10 3 8 21M16 3l-2 18',
        bookmark: 'M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z',
        play: 'M6 3l14 9-14 9z',
        star: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z',
        search: 'M11 3a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM21 21l-4.3-4.3',
        external: 'M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6',
        hand: 'M18 11V6a2 2 0 0 0-4 0M14 10V4a2 2 0 0 0-4 0v2M10 10.5V6a2 2 0 0 0-4 0v8M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15',
        undo: 'M9 14 4 9l5-5M4 9h10.5a5.5 5.5 0 0 1 0 11H11',
        hourglass: 'M5 22h14M5 2h14M17 22v-4.17a2 2 0 0 0-.59-1.42L12 12l-4.41 4.41A2 2 0 0 0 7 17.83V22M7 2v4.17a2 2 0 0 0 .59 1.42L12 12l4.41-4.41A2 2 0 0 0 17 6.17V2',
        plug: 'M12 22v-5M9 8V2M15 8V2M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z',
        layers: 'M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
        coins: 'M8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2zM18.09 10.37A6 6 0 1 1 10.34 18M7 6h1v4M16.71 13.88l.7.71-2.82 2.82'
    };
    function i(name, size) {
        const s = size || 18;
        if (name === 'story') {
            return '<svg class="spi" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke-dasharray="4 3"/><circle cx="12" cy="12" r="4"/></svg>';
        }
        const d = P[name];
        if (!d) return '';
        return '<svg class="spi" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + d + '"/></svg>';
    }
    const W = 'fill="none" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"';
    const B = {
        facebook: ['#1877F2', '<path fill="#fff" d="M15.2 8.4h-1.7c-.6 0-.9.4-.9 1v1.7h2.6l-.4 2.7h-2.2V20h-2.8v-6.2H7.6v-2.7h2.2V9.1c0-2.3 1.3-3.6 3.4-3.6h2z"/>'],
        instagram: ['url(#spiIg)', '<rect x="6" y="6" width="12" height="12" rx="3.6" ' + W + '/><circle cx="12" cy="12" r="2.9" ' + W + '/><circle cx="15.7" cy="8.4" r=".95" fill="#fff"/>'],
        tiktok: ['#111', '<path fill="#25F4EE" d="M13.3 5.2h2.2c.3 1.6 1.4 2.7 3 2.9v2.2c-1.1 0-2.1-.3-3-.9v5c0 2.6-2.1 4.4-4.5 4.4a4.4 4.4 0 0 1-.4-8.8v2.3a2.1 2.1 0 1 0 2.7 2z" transform="translate(-.6 -.4)"/><path fill="#FE2C55" d="M13.3 5.2h2.2c.3 1.6 1.4 2.7 3 2.9v2.2c-1.1 0-2.1-.3-3-.9v5c0 2.6-2.1 4.4-4.5 4.4a4.4 4.4 0 0 1-.4-8.8v2.3a2.1 2.1 0 1 0 2.7 2z" transform="translate(.6 .4)"/><path fill="#fff" d="M13.3 5.2h2.2c.3 1.6 1.4 2.7 3 2.9v2.2c-1.1 0-2.1-.3-3-.9v5c0 2.6-2.1 4.4-4.5 4.4a4.4 4.4 0 0 1-.4-8.8v2.3a2.1 2.1 0 1 0 2.7 2z"/>'],
        youtube: ['#FF0033', '<path fill="#fff" d="M10 8.6v6.8l5.8-3.4z"/>'],
        google_business: ['#1A73E8', '<path ' + W + ' d="M6.5 10.5 7.6 6.5h8.8l1.1 4M6.5 10.5c0 1 .8 1.7 1.8 1.7s1.9-.7 1.9-1.7c0 1 .8 1.7 1.8 1.7s1.8-.7 1.8-1.7c0 1 .9 1.7 1.9 1.7s1.8-.7 1.8-1.7M7.5 12.2V17.5h9v-5.3M10.8 17.5v-3h2.4v3"/>'],
        threads: ['#000', '<path ' + W + ' d="M15.6 11.6c-.3-2.3-1.7-3.3-3.6-3.3-1.6 0-2.8.8-3.3 2M15.6 11.6c-1.7-.6-5-.6-5 1.5 0 1.2 1 1.8 2.2 1.8 1.8 0 3-1.3 3-3.5M15.6 11.6c1.4.5 2.3 1.5 2.3 3 0 2.6-2.5 4.4-5.7 4.4-3.8 0-6.2-2.6-6.2-7s2.4-7 6.2-7c2.8 0 4.8 1.4 5.6 3.8"/>'],
        linkedin: ['#0A66C2', '<rect x="6.8" y="10.2" width="2.3" height="7" fill="#fff"/><circle cx="7.95" cy="7.6" r="1.35" fill="#fff"/><path fill="#fff" d="M11 10.2h2.2v1c.4-.7 1.3-1.2 2.4-1.2 2 0 2.6 1.3 2.6 3.2v4h-2.3v-3.6c0-.9-.2-1.6-1.1-1.6-1 0-1.5.7-1.5 1.7v3.5H11z"/>']
    };
    function brand(p, size) {
        const b = B[p];
        const s = size || 20;
        if (!b) return '<svg class="spb" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="6" fill="#94a3b8"/></svg>';
        return '<svg class="spb" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="6.5" fill="' + b[0] + '"/>' + b[1] + '</svg>';
    }
    // تدرّج إنستجرام مرة واحدة في الصفحة
    function defs() {
        if (document.getElementById('spiDefs')) return;
        const d = document.createElement('div');
        d.innerHTML = '<svg id="spiDefs" width="0" height="0" style="position:absolute" aria-hidden="true"><defs><linearGradient id="spiIg" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FEDA75"/><stop offset=".3" stop-color="#FA7E1E"/><stop offset=".6" stop-color="#D62976"/><stop offset=".85" stop-color="#962FBF"/><stop offset="1" stop-color="#4F5BD5"/></linearGradient></defs></svg>';
        document.body.appendChild(d.firstChild);
    }
    function hydrate(root) {
        (root || document).querySelectorAll('i[data-ico]').forEach(el => { el.outerHTML = i(el.dataset.ico, +el.dataset.size || undefined); });
        (root || document).querySelectorAll('i[data-brand]').forEach(el => { el.outerHTML = brand(el.dataset.brand, +el.dataset.size || undefined); });
    }
    window.SPI = { i, brand, hydrate, defs };
    const boot = () => { defs(); hydrate(document); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
