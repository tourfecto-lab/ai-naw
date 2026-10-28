/**
 * Tourfecto - خريطة الهيرو (الصفحة الرئيسية)
 * نقاط العالم من tf-geo.js + دوائر على مراكز سياحية + أقواس رحلات.
 * الأرقام هنا توضيحية للتصميم (الصفحة نفسها بتوضّح إن الأمثلة توضيحية).
 */
(function () {
    'use strict';
    var host = document.getElementById('heroMap'), geo = window.TF_GEO;
    if (!host || !geo) return;
    var W = 1000, H = Math.round(W * 138 / 360);
    var X = function (lon) { return (lon + 180) / 360 * W; }, Y = function (lat) { return (80 - lat) / 138 * H; };
    var hubs = [['Dubai', 55.3, 25.2, 15], ['Paris', 2.35, 48.85, 13], ['Rome', 12.5, 41.9, 10], ['London', -0.12, 51.5, 11], ['New York', -74, 40.7, 14],
        ['Cairo', 31.2, 30.0, 12], ['Tokyo', 139.7, 35.7, 11], ['Bali', 115.2, -8.4, 8], ['Istanbul', 28.97, 41.0, 9], ['Bangkok', 100.5, 13.75, 9], ['Sydney', 151.2, -33.9, 8], ['Cape Town', 18.4, -33.9, 6]];
    var routes = [[4, 1], [1, 0], [0, 6], [5, 0], [3, 8], [0, 9], [9, 7], [4, 3], [6, 10]];
    var land = '';
    for (var i = 0; i < geo.dots.length; i += 2) land += 'M' + X(geo.dots[i]).toFixed(1) + ' ' + Y(geo.dots[i + 1]).toFixed(1) + 'h.01';
    var arcs = routes.map(function (r) {
        var a = hubs[r[0]], b = hubs[r[1]], x1 = X(a[1]), y1 = Y(a[2]), x2 = X(b[1]), y2 = Y(b[2]);
        var mx = (x1 + x2) / 2, my = (y1 + y2) / 2 - Math.hypot(x2 - x1, y2 - y1) * 0.22;
        return '<path class="arc" d="M' + x1.toFixed(1) + ' ' + y1.toFixed(1) + ' Q' + mx.toFixed(1) + ' ' + my.toFixed(1) + ' ' + x2.toFixed(1) + ' ' + y2.toFixed(1) + '"/>';
    }).join('');
    var dots = hubs.map(function (h, idx) {
        var x = X(h[1]).toFixed(1), y = Y(h[2]).toFixed(1);
        return '<circle class="halo" cx="' + x + '" cy="' + y + '" r="' + (h[3] * 1.8) + '"/>' + (idx === 0 ? '<circle class="ping" cx="' + x + '" cy="' + y + '" r="' + h[3] + '"/>' : '') + '<circle class="bub" cx="' + x + '" cy="' + y + '" r="' + h[3] * 0.6 + '"/>';
    }).join('');
    host.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet" aria-hidden="true"><path class="land" stroke-width="3.1" d="' + land + '"/>' + arcs + dots + '</svg>';
})();
