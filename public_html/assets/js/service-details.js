(function () {
    'use strict';
    function revealService() {
        var id;
        try { id = decodeURIComponent(window.location.hash.slice(1)); } catch (_) { return; }
        if (id.indexOf('service-') !== 0) return;
        var target = document.getElementById(id);
        if (target && target.tagName === 'DETAILS') {
            target.open = true;
            target.scrollIntoView({block: 'start'});
        }
    }
    revealService();
    window.addEventListener('hashchange', revealService);
}());
