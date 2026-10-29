/*
 * WhatsApp-style conveniences for the inbox (page /chat). Pure client side and defensive: it only looks at the DOM the
 * inbox already renders and calls the page's own window.sendAttachment() / existing buttons, so it never changes how
 * messages are sent. Everything optional; if an element is missing the feature silently does nothing.
 */
(function () {
    'use strict';
    var body = document.getElementById('ucBody');
    if (!body) return;

    var $ = function (id) { return document.getElementById(id); };
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; }
    function store(key, val) { try { if (val === undefined) return JSON.parse(localStorage.getItem(key) || 'null'); localStorage.setItem(key, JSON.stringify(val)); } catch (e) { return null; } }

    var panel = $('ucThreadPanel');
    var thread = $('convThread');
    var msgBox = $('manualMessage');
    var replyCard = $('ucReplyCard');
    if (!panel || !thread || !msgBox || !replyCard) return;
    panel.style.position = 'relative';

    /* ---------- emoji picker ---------- */
    var EMOJI = ('😀 😃 😄 😁 😆 😅 😂 🤣 🙂 😉 😊 😇 🥰 😍 🤩 😘 😋 😜 🤪 😎 🤗 🤔 😐 😴 😢 😭 😡 🥳 🙏 👍 👎 👏 🙌 💪 👌 ✌️ 🤝 ❤️ 🧡 💛 💚 💙 💜 🔥 ✨ ⭐ 🎉 💯 ✅ ❌ ⚠️ ❓ ❗ ' +
        '☀️ 🌅 🌄 🏜️ 🏖️ 🌴 ⛰️ 🗺️ 🧭 ⛵ 🚤 🚌 🚗 ✈️ 🏨 🛏️ 🍽️ ☕ 🍹 🎈 🐪 🐎 📍 📅 ⏰ 💳 💵 📞 📸 🎁 🛍️').split(' ');
    var emojiBtn = el('button', 'wa-emoji-btn', '🙂');
    emojiBtn.type = 'button';
    emojiBtn.title = 'Emoji';
    emojiBtn.setAttribute('aria-label', 'Emoji');
    var emojiPop = el('div', 'wa-emoji-pop');
    emojiPop.hidden = true;
    EMOJI.forEach(function (ch) {
        var b = el('button', '', ch);
        b.type = 'button';
        b.addEventListener('click', function () {
            var s = msgBox.selectionStart == null ? msgBox.value.length : msgBox.selectionStart;
            var e = msgBox.selectionEnd == null ? s : msgBox.selectionEnd;
            msgBox.value = msgBox.value.slice(0, s) + ch + msgBox.value.slice(e);
            msgBox.focus();
            msgBox.selectionStart = msgBox.selectionEnd = s + ch.length;
        });
        emojiPop.appendChild(b);
    });
    var formGroup = msgBox.closest('.form-group') || msgBox.parentNode;
    formGroup.style.position = 'relative';
    formGroup.appendChild(emojiBtn);
    formGroup.appendChild(emojiPop);
    emojiBtn.addEventListener('click', function () { emojiPop.hidden = !emojiPop.hidden; });
    document.addEventListener('click', function (e) {
        if (!emojiPop.hidden && !emojiPop.contains(e.target) && e.target !== emojiBtn) emojiPop.hidden = true;
    });

    /* ---------- paste / drop a file with a preview ---------- */
    var pending = null;
    var preview = el('div', 'wa-attach-preview');
    preview.hidden = true;
    var thumb = el('div', 'wa-attach-thumb');
    var info = el('div', 'wa-attach-info');
    var sendBtn = el('button', 'p-btn primary xs', 'Send');
    sendBtn.type = 'button';
    var cancelBtn = el('button', 'p-btn outline xs', 'Cancel');
    cancelBtn.type = 'button';
    preview.appendChild(thumb); preview.appendChild(info); preview.appendChild(cancelBtn); preview.appendChild(sendBtn);
    replyCard.insertBefore(preview, replyCard.firstChild);

    function clearPreview() { pending = null; preview.hidden = true; thumb.textContent = ''; info.textContent = ''; }
    function stage(file) {
        if (!file) return;
        pending = file;
        thumb.textContent = '';
        if (/^image\//.test(file.type)) {
            var img = el('img');
            img.alt = '';
            img.src = URL.createObjectURL(file);
            thumb.appendChild(img);
        } else {
            thumb.textContent = /^audio\//.test(file.type) ? '🎤' : /^video\//.test(file.type) ? '🎞️' : '📎';
        }
        info.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
        preview.hidden = false;
    }
    cancelBtn.addEventListener('click', clearPreview);
    sendBtn.addEventListener('click', function () {
        if (!pending || typeof window.sendAttachment !== 'function') return;
        var f = pending;
        clearPreview();
        window.sendAttachment({ files: [f], value: '' });
    });
    msgBox.addEventListener('paste', function (e) {
        var items = (e.clipboardData && e.clipboardData.files) || [];
        if (items.length) { e.preventDefault(); stage(items[0]); }
    });
    ['dragenter', 'dragover'].forEach(function (t) {
        panel.addEventListener(t, function (e) { if (e.dataTransfer && [].indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) { e.preventDefault(); panel.classList.add('wa-dropping'); } });
    });
    panel.addEventListener('dragleave', function (e) { if (e.target === panel) panel.classList.remove('wa-dropping'); });
    panel.addEventListener('drop', function (e) {
        panel.classList.remove('wa-dropping');
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) { e.preventDefault(); stage(f); }
    });

    /* ---------- voice notes (only where the browser records a format WhatsApp accepts) ---------- */
    var voiceType = '';
    if (window.MediaRecorder && MediaRecorder.isTypeSupported) {
        ['audio/ogg;codecs=opus', 'audio/mp4'].some(function (t) { if (MediaRecorder.isTypeSupported(t)) { voiceType = t; return true; } return false; });
    }
    if (voiceType && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        var mic = el('button', 'wa-mic-btn', '🎤');
        mic.type = 'button';
        mic.title = 'Voice note';
        mic.setAttribute('aria-label', 'Voice note');
        formGroup.appendChild(mic);
        var rec = null, chunks = [], timer = null, started = 0, cancelled = false;
        var bar = el('div', 'wa-rec-bar');
        bar.hidden = true;
        var dot = el('span', 'wa-rec-dot');
        var clock = el('span', '', '0:00');
        var stopRec = el('button', 'p-btn primary xs', 'Send');
        stopRec.type = 'button';
        var cancelRec = el('button', 'p-btn outline xs', 'Cancel');
        cancelRec.type = 'button';
        bar.appendChild(dot); bar.appendChild(clock); bar.appendChild(cancelRec); bar.appendChild(stopRec);
        replyCard.insertBefore(bar, replyCard.firstChild);
        function endRec() { clearInterval(timer); bar.hidden = true; mic.classList.remove('on'); }
        mic.addEventListener('click', function () {
            if (rec) return;
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                chunks = []; cancelled = false;
                rec = new MediaRecorder(stream, { mimeType: voiceType });
                rec.ondataavailable = function (ev) { if (ev.data && ev.data.size) chunks.push(ev.data); };
                rec.onstop = function () {
                    stream.getTracks().forEach(function (t) { t.stop(); });
                    var wasCancelled = cancelled;
                    rec = null; endRec();
                    if (wasCancelled || !chunks.length) return;
                    var ext = voiceType.indexOf('ogg') !== -1 ? 'ogg' : 'm4a';
                    stage(new File(chunks, 'voice-note.' + ext, { type: voiceType.split(';')[0] }));
                };
                rec.start();
                started = Date.now();
                bar.hidden = false; mic.classList.add('on');
                timer = setInterval(function () { var s = Math.floor((Date.now() - started) / 1000); clock.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }, 500);
            }).catch(function () { /* microphone blocked: nothing to do */ });
        });
        stopRec.addEventListener('click', function () { if (rec) rec.stop(); });
        cancelRec.addEventListener('click', function () { cancelled = true; if (rec) rec.stop(); });
    }

    /* ---------- scroll to the latest message ---------- */
    var down = el('button', 'wa-down', '⌄');
    down.type = 'button';
    down.title = 'Latest messages';
    down.setAttribute('aria-label', 'Latest messages');
    down.hidden = true;
    panel.appendChild(down);
    function placeDown() { down.style.bottom = (replyCard.offsetHeight + 16) + 'px'; }
    function updateDown() {
        placeDown();
        down.hidden = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 240;
    }
    thread.addEventListener('scroll', updateDown, { passive: true });
    down.addEventListener('click', function () { thread.scrollTo({ top: thread.scrollHeight, behavior: 'smooth' }); });

    /* ---------- unread divider (from the badge of the row that was opened) ---------- */
    var unreadOnOpen = 0;
    document.addEventListener('click', function (e) {
        var row = e.target.closest && e.target.closest('.ai-chat-item');
        if (!row) return;
        var ub = row.querySelector('.ub');
        unreadOnOpen = ub ? parseInt(ub.textContent, 10) || 0 : 0;
    }, true);
    function addUnreadDivider() {
        var old = thread.querySelector('.wa-unread-sep');
        if (old) old.remove();
        if (!unreadOnOpen) return;
        var ins = thread.querySelectorAll('.ai-bubble.in');
        if (!ins.length) return;
        var first = ins[Math.max(0, ins.length - unreadOnOpen)];
        var sep = el('div', 'wa-unread-sep', unreadOnOpen + (unreadOnOpen === 1 ? ' unread message' : ' unread messages'));
        first.parentNode.insertBefore(sep, first);
    }

    /* ---------- search inside the conversation ---------- */
    var sbar = el('div', 'wa-find');
    sbar.hidden = true;
    var sinput = el('input');
    sinput.type = 'search'; sinput.placeholder = 'Search in this conversation'; sinput.setAttribute('aria-label', 'Search in this conversation');
    var scount = el('span', 'wa-find-count');
    var sprev = el('button', '', '↑'); sprev.type = 'button'; sprev.setAttribute('aria-label', 'Previous');
    var snext = el('button', '', '↓'); snext.type = 'button'; snext.setAttribute('aria-label', 'Next');
    var sclose = el('button', '', '✕'); sclose.type = 'button'; sclose.setAttribute('aria-label', 'Close');
    [sinput, scount, sprev, snext, sclose].forEach(function (n) { sbar.appendChild(n); });
    panel.insertBefore(sbar, panel.firstChild);
    var findBtn = el('button', 'wa-find-btn', '🔍');
    findBtn.type = 'button'; findBtn.title = 'Search in conversation'; findBtn.setAttribute('aria-label', 'Search in conversation');
    panel.appendChild(findBtn);
    var hits = [], at = -1;
    function clearHits() { thread.querySelectorAll('.wa-hit,.wa-hit-on').forEach(function (b) { b.classList.remove('wa-hit', 'wa-hit-on'); }); hits = []; at = -1; scount.textContent = ''; }
    function runFind() {
        clearHits();
        var q = sinput.value.trim().toLowerCase();
        if (q.length < 2) return;
        thread.querySelectorAll('.ai-bubble').forEach(function (b) { if (b.textContent.toLowerCase().indexOf(q) !== -1) { b.classList.add('wa-hit'); hits.push(b); } });
        scount.textContent = hits.length ? '' : '0';
        if (hits.length) goHit(hits.length - 1);
    }
    function goHit(i) {
        if (!hits.length) return;
        if (at >= 0 && hits[at]) hits[at].classList.remove('wa-hit-on');
        at = (i + hits.length) % hits.length;
        hits[at].classList.add('wa-hit-on');
        hits[at].scrollIntoView({ block: 'center', behavior: 'smooth' });
        scount.textContent = (at + 1) + '/' + hits.length;
    }
    function toggleFind(on) {
        sbar.hidden = !on;
        if (on) { sinput.focus(); } else { sinput.value = ''; clearHits(); }
    }
    findBtn.addEventListener('click', function () { toggleFind(sbar.hidden); });
    sclose.addEventListener('click', function () { toggleFind(false); });
    sinput.addEventListener('input', runFind);
    sinput.addEventListener('keydown', function (e) { if (e.key === 'Enter') goHit(at + (e.shiftKey ? -1 : 1)); });
    sprev.addEventListener('click', function () { goHit(at - 1); });
    snext.addEventListener('click', function () { goHit(at + 1); });

    /* ---------- pin / archive / mark unread (kept in this browser only) ---------- */
    var list = $('ucList');
    var KEY = 'tf_wa_list_v1';
    function prefs() { return store(KEY) || { pin: {}, arch: {}, unread: {} }; }
    function setPref(kind, id, on) { var p = prefs(); if (on) p[kind][id] = 1; else delete p[kind][id]; store(KEY, p); decorate(); }
    var showArchived = false;
    var menu = el('div', 'wa-menu');
    menu.hidden = true;
    document.body.appendChild(menu);
    function openMenu(row, x, y) {
        var id = row.getAttribute('data-id');
        var p = prefs();
        menu.textContent = '';
        [['Pin chat', p.pin[id] ? 'Unpin chat' : 'Pin chat', function () { setPref('pin', id, !p.pin[id]); }],
         ['Archive', p.arch[id] ? 'Unarchive' : 'Archive', function () { setPref('arch', id, !p.arch[id]); }],
         ['Mark unread', p.unread[id] ? 'Mark as read' : 'Mark unread', function () { setPref('unread', id, !p.unread[id]); }]
        ].forEach(function (it) {
            var b = el('button', '', it[1]); b.type = 'button';
            b.addEventListener('click', function () { menu.hidden = true; it[2](); });
            menu.appendChild(b);
        });
        menu.style.left = Math.min(x, window.innerWidth - 190) + 'px';
        menu.style.top = Math.min(y, window.innerHeight - 140) + 'px';
        menu.hidden = false;
    }
    document.addEventListener('click', function () { menu.hidden = true; });
    if (list) {
        list.addEventListener('contextmenu', function (e) {
            var row = e.target.closest('.ai-chat-item');
            if (row) { e.preventDefault(); openMenu(row, e.clientX, e.clientY); }
        });
        var pressTimer = null;
        list.addEventListener('touchstart', function (e) {
            var row = e.target.closest('.ai-chat-item');
            if (!row) return;
            var t = e.touches[0];
            pressTimer = setTimeout(function () { openMenu(row, t.clientX, t.clientY); }, 550);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach(function (n) { list.addEventListener(n, function () { clearTimeout(pressTimer); }, { passive: true }); });
    }
    var busy = false, listObs = null;
    function decorate() {
        if (!list || busy) return;
        busy = true;
        if (listObs) listObs.disconnect();
        var p = prefs();
        var rows = [].slice.call(list.querySelectorAll('.ai-chat-item'));
        var anyArch = false;
        rows.forEach(function (row) {
            var id = row.getAttribute('data-id');
            row.classList.toggle('wa-pinned', !!p.pin[id]);
            row.classList.toggle('wa-marked-unread', !!p.unread[id]);
            var archived = !!p.arch[id];
            if (archived) anyArch = true;
            row.style.display = (archived && !showArchived) ? 'none' : '';
        });
        var pinned = rows.filter(function (r) { return r.classList.contains('wa-pinned'); });
        var firstRows = [].slice.call(list.querySelectorAll('.ai-chat-item')).slice(0, pinned.length);
        if (pinned.some(function (r, i) { return firstRows[i] !== r; })) {
            for (var i = pinned.length - 1; i >= 0; i--) list.insertBefore(pinned[i], list.firstChild);
        }
        var chip = list.querySelector('.wa-arch-chip');
        if (anyArch && !chip) {
            chip = el('button', 'wa-arch-chip');
            chip.type = 'button';
            chip.addEventListener('click', function () { showArchived = !showArchived; decorate(); });
            list.insertBefore(chip, list.firstChild);
        }
        if (chip) {
            if (!anyArch) chip.remove(); else chip.textContent = showArchived ? '↩ Hide archived chats' : '🗄 Archived chats';
        }
        if (listObs) listObs.observe(list, { childList: true });
        busy = false;
    }
    if (list) {
        listObs = new MutationObserver(function () { decorate(); });
        decorate();
    }

    /* ---------- keep helpers in step with the thread being re-rendered ---------- */
    new MutationObserver(function () {
        addUnreadDivider();
        updateDown();
        if (!sbar.hidden && sinput.value) runFind();
    }).observe(thread, { childList: true });
    window.addEventListener('resize', updateDown);

    /* ---------- phone: the chat header shows only the name; tap it to open the controls ---------- */
    var head = $('convHeader');
    if (head) {
        head.addEventListener('click', function (e) {
            if (!document.body.classList.contains('wa-in-chat')) return;
            if (e.target.closest && e.target.closest('select,button,a,input,textarea')) return;
            head.classList.toggle('wa-head-open');
        });
        new MutationObserver(function () { head.classList.remove('wa-head-open'); }).observe(panel, { attributes: true, attributeFilter: ['style'] });
    }

    /* ---------- touch: tap a bubble to show its reply / react buttons ---------- */
    thread.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.wa-actions,a,button,audio,video')) return;
        var b = e.target.closest && e.target.closest('.ai-bubble[data-mid]');
        thread.querySelectorAll('.wa-tap').forEach(function (x) { if (x !== b) x.classList.remove('wa-tap'); });
        if (b) b.classList.toggle('wa-tap');
    });

    /* ---------- image viewer (gallery of the conversation's pictures) ---------- */
    var lb = el('div', 'wa-lb');
    lb.hidden = true;
    var lbImg = el('img');
    lbImg.alt = '';
    var lbCount = el('span', 'wa-lb-count');
    var lbClose = el('button', 'wa-lb-x', '✕'); lbClose.type = 'button'; lbClose.setAttribute('aria-label', 'Close');
    var lbPrev = el('button', 'wa-lb-nav wa-lb-prev', '‹'); lbPrev.type = 'button'; lbPrev.setAttribute('aria-label', 'Previous picture');
    var lbNext = el('button', 'wa-lb-nav wa-lb-next', '›'); lbNext.type = 'button'; lbNext.setAttribute('aria-label', 'Next picture');
    var lbDl = el('a', 'wa-lb-dl', '⬇'); lbDl.setAttribute('download', ''); lbDl.setAttribute('aria-label', 'Download'); lbDl.rel = 'noopener';
    [lbImg, lbCount, lbDl, lbClose, lbPrev, lbNext].forEach(function (n) { lb.appendChild(n); });
    document.body.appendChild(lb);
    var gallery = [], gi = 0;
    function showPic(i) {
        if (!gallery.length) return;
        gi = (i + gallery.length) % gallery.length;
        lbImg.src = gallery[gi].src;
        lbDl.href = gallery[gi].src;
        lbCount.textContent = (gi + 1) + ' / ' + gallery.length;
        lbPrev.style.display = lbNext.style.display = gallery.length > 1 ? '' : 'none';
    }
    function closeLb() { lb.hidden = true; lbImg.removeAttribute('src'); }
    thread.addEventListener('click', function (e) {
        var img = e.target.closest && e.target.closest('img.ai-media-img');
        if (!img || e.ctrlKey || e.metaKey || e.shiftKey) return;
        e.preventDefault();
        gallery = [].slice.call(thread.querySelectorAll('img.ai-media-img')).filter(function (i) { return i.naturalWidth > 0; });
        var at0 = gallery.indexOf(img);
        if (at0 < 0) return;
        lb.hidden = false;
        showPic(at0);
    });
    lbClose.addEventListener('click', closeLb);
    lb.addEventListener('click', function (e) { if (e.target === lb) closeLb(); });
    lbPrev.addEventListener('click', function () { showPic(gi - 1); });
    lbNext.addEventListener('click', function () { showPic(gi + 1); });
    document.addEventListener('keydown', function (e) {
        if (lb.hidden) return;
        if (e.key === 'Escape') { closeLb(); e.stopPropagation(); }
        else if (e.key === 'ArrowLeft') showPic(gi - 1);
        else if (e.key === 'ArrowRight') showPic(gi + 1);
    }, true);

    /* ---------- voice-note speed and link chips (added after every render) ---------- */
    var URL_RE = /https?:\/\/[^\s<>"']+/g;
    function enhanceBubbles() {
        thread.querySelectorAll('audio.ai-media-audio').forEach(function (a) {
            if (a.nextElementSibling && a.nextElementSibling.classList.contains('wa-speed')) return;
            var b = el('button', 'wa-speed', '1×');
            b.type = 'button'; b.title = 'Playback speed'; b.setAttribute('aria-label', 'Playback speed');
            b.addEventListener('click', function () {
                var next = a.playbackRate >= 2 ? 1 : (a.playbackRate >= 1.5 ? 2 : 1.5);
                a.playbackRate = next;
                b.textContent = (next === 1 ? '1' : String(next)) + '×';
            });
            a.insertAdjacentElement('afterend', b);
        });
        thread.querySelectorAll('.ai-bubble').forEach(function (bub) {
            if (bub.dataset.waLinks) return;
            bub.dataset.waLinks = '1';
            var seen = {};
            bub.querySelectorAll(':scope > span:not(.who):not(.ai-tag):not(.wa-react-badge)').forEach(function (sp) {
                (sp.textContent.match(URL_RE) || []).slice(0, 2).forEach(function (raw) {
                    var u;
                    try { u = new URL(raw.replace(/[).,;!?]+$/, '')); } catch (e) { return; }
                    if ((u.protocol !== 'https:' && u.protocol !== 'http:') || seen[u.href]) return;
                    seen[u.href] = 1;
                    var a = el('a', 'wa-link');
                    a.href = u.href; a.target = '_blank'; a.rel = 'noopener noreferrer nofollow';
                    a.appendChild(el('b', '', u.hostname.replace(/^www\./, '')));
                    var path = u.pathname + u.search;
                    a.appendChild(el('span', '', path.length > 1 ? path.slice(0, 60) : ''));
                    bub.insertBefore(a, bub.querySelector(':scope > .bt') || null);
                });
            });
        });
    }
    new MutationObserver(enhanceBubbles).observe(thread, { childList: true });

    /* ---------- keyboard shortcuts ---------- */
    document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName) || '';
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            var s = $('ucSearch');
            if (s) { e.preventDefault(); s.focus(); s.select(); }
        } else if (e.key === 'Escape') {
            emojiPop.hidden = true; menu.hidden = true;
            if (!sbar.hidden) toggleFind(false);
            else if (/INPUT|TEXTAREA|SELECT/.test(tag)) e.target.blur();
        } else if (e.altKey && (e.key === 'ArrowDown' || e.key === 'ArrowUp') && list) {
            var rows = [].slice.call(list.querySelectorAll('.ai-chat-item')).filter(function (r) { return r.style.display !== 'none'; });
            var cur = rows.findIndex(function (r) { return r.classList.contains('active'); });
            var next = rows[cur + (e.key === 'ArrowDown' ? 1 : -1)];
            if (next) { e.preventDefault(); next.click(); next.scrollIntoView({ block: 'nearest' }); }
        }
    });
})();
