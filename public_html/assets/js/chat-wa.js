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

    /* ---------- the reply box grows with the text (up to ~5 lines), like WhatsApp ---------- */
    function grow() { msgBox.style.height = 'auto'; msgBox.style.height = Math.min(140, Math.max(46, msgBox.scrollHeight)) + 'px'; }
    msgBox.addEventListener('input', grow);
    new MutationObserver(function () { if (!msgBox.value) msgBox.style.height = ''; }).observe(msgBox, { attributes: true, attributeFilter: ['style'] });
    msgBox.addEventListener('blur', function () { if (!msgBox.value) msgBox.style.height = ''; });

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
    // WhatsApp only plays voice notes that are Ogg/Opus. Firefox records that directly; Chrome and Edge record MP4/WebM
    // (WhatsApp answers "Media upload error"), so there the raw Opus packets from WebCodecs are wrapped in Ogg by ogg-opus.js.
    var voiceType = '', voiceKind = '';
    if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) {
        voiceType = 'audio/ogg;codecs=opus'; voiceKind = 'recorder';
    } else if (window.AudioEncoder && window.AudioData && window.OggOpus && (window.AudioContext || window.webkitAudioContext)) {
        voiceType = 'audio/ogg;codecs=opus'; voiceKind = 'webcodecs';
    }

    /** Records microphone audio with WebCodecs (Opus) and returns an Ogg Opus File through done(file|null). */
    function opusRecorder(stream, done) {
        var AC = window.AudioContext || window.webkitAudioContext;
        var ctx = new AC({ sampleRate: 48000 });
        if (ctx.sampleRate !== 48000) { ctx.close(); throw new Error('48 kHz audio is not available'); }
        if (ctx.state === 'suspended') ctx.resume();
        var src = ctx.createMediaStreamSource(stream);
        var proc = ctx.createScriptProcessor(4096, 1, 1);
        var packets = [], samples = [], head = null, ts = 0, closed = false, failed = false, peak = 0, level = 0;
        var enc = new AudioEncoder({
            output: function (chunk, meta) {
                var b = new Uint8Array(chunk.byteLength);
                chunk.copyTo(b);
                packets.push(b);
                samples.push(Math.round((chunk.duration || 20000) * 48 / 1000));
                if (!head && meta && meta.decoderConfig && meta.decoderConfig.description) head = new Uint8Array(meta.decoderConfig.description);
            },
            error: function () { failed = true; }
        });
        enc.configure({ codec: 'opus', sampleRate: 48000, numberOfChannels: 1, bitrate: 32000 });
        proc.onaudioprocess = function (e) {
            if (closed) return;
            var copy = new Float32Array(e.inputBuffer.getChannelData(0));
            var mx = 0;
            for (var q = 0; q < copy.length; q++) { var a = copy[q] < 0 ? -copy[q] : copy[q]; if (a > mx) mx = a; }
            level = mx; if (mx > peak) peak = mx;
            var ad = new AudioData({ format: 'f32-planar', sampleRate: 48000, numberOfFrames: copy.length, numberOfChannels: 1, timestamp: Math.round(ts * 1e6 / 48000), data: copy });
            ts += copy.length;
            try { enc.encode(ad); } finally { ad.close(); }
        };
        src.connect(proc);
        proc.connect(ctx.destination); // silent: nothing is written to the output buffer
        return {
            level: function () { return level; },
            peak: function () { return peak; },
            stop: function () {
                closed = true;
                try { src.disconnect(); proc.disconnect(); } catch (e) { /* already closed */ }
                enc.flush().then(function () {
                    enc.close(); ctx.close();
                    if (failed || !packets.length) { done(null); return; }
                    if (peak < 0.004) { done(null, 'silent'); return; }
                    done(new File([OggOpus.mux(packets, samples, { head: head, preSkip: 312 })], 'voice-note.ogg', { type: 'audio/ogg' }));
                }).catch(function () { done(null); });
            }
        };
    }
    if (voiceKind && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        var mic = el('button', 'wa-mic-btn', '🎤');
        mic.type = 'button';
        mic.title = 'Voice note';
        mic.setAttribute('aria-label', 'Voice note');
        formGroup.appendChild(mic);
        var rec = null, chunks = [], timer = null, started = 0, cancelled = false, sendNow = false;
        function say(msg) { if (window.Panel && Panel.toast) Panel.toast(msg, 'error'); else alert(msg); }
        var bar = el('div', 'wa-rec-bar');
        bar.hidden = true;
        var dot = el('span', 'wa-rec-dot');
        var clock = el('span', '', '0:00');
        var meter = el('span', 'wa-level'); meter.appendChild(el('i'));
        var devLabel = el('small', 'wa-dev');
        var stopRec = el('button', 'p-btn primary xs', 'Send');
        stopRec.type = 'button';
        var cancelRec = el('button', 'p-btn outline xs', 'Cancel');
        cancelRec.type = 'button';
        bar.appendChild(dot); bar.appendChild(clock); bar.appendChild(meter); bar.appendChild(devLabel); bar.appendChild(cancelRec); bar.appendChild(stopRec);
        replyCard.insertBefore(bar, replyCard.firstChild);
        var meterTimer = null;
        function endRec() { clearInterval(timer); clearInterval(meterTimer); meter.firstChild.style.width = '0%'; bar.hidden = true; mic.classList.remove('on'); }
        mic.addEventListener('click', function () {
            // a second tap on the microphone finishes the recording and sends it, like WhatsApp
            if (rec) { sendNow = true; rec.stop(); return; }
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                chunks = []; cancelled = false; sendNow = false;
                var track = stream.getAudioTracks()[0];
                devLabel.textContent = track && track.label ? track.label : '';
                function finish(note, why) {
                    stream.getTracks().forEach(function (t) { t.stop(); });
                    var wasCancelled = cancelled;
                    rec = null; endRec();
                    if (wasCancelled) return;
                    if (why === 'silent') { say('No sound was captured' + (track && track.label ? ' from "' + track.label + '"' : '') + '. Check that this microphone is the right one and not muted, then record again.'); return; }
                    if (!note) { say('The voice note could not be recorded. Try again, or use Firefox.'); return; }
                    if (sendNow && typeof window.sendAttachment === 'function') window.sendAttachment({ files: [note], value: '' });
                    else stage(note);
                }
                if (voiceKind === 'webcodecs') {
                    try { rec = opusRecorder(stream, finish); } catch (e) { stream.getTracks().forEach(function (t) { t.stop(); }); say('This browser cannot record a voice note WhatsApp accepts. Use Firefox or the phone app.'); return; }
                } else {
                    var mr = new MediaRecorder(stream, { mimeType: voiceType });
                    mr.ondataavailable = function (ev) { if (ev.data && ev.data.size) chunks.push(ev.data); };
                    mr.onstop = function () { finish(chunks.length ? new File(chunks, 'voice-note.ogg', { type: 'audio/ogg' }) : null); };
                    mr.start();
                    rec = mr;
                }
                started = Date.now();
                bar.hidden = false; mic.classList.add('on');
                timer = setInterval(function () {
                    var s = Math.floor((Date.now() - started) / 1000);
                    clock.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
                }, 500);
                meterTimer = setInterval(function () { var l = rec && rec.level ? rec.level() : 0; meter.firstChild.style.width = Math.min(100, Math.round(Math.sqrt(l) * 140)) + '%'; }, 120);
            }).catch(function (err) {
                var name = err && err.name;
                if (name === 'NotAllowedError' || name === 'SecurityError') say('The microphone is blocked for this site. Click the lock icon next to the address, allow the microphone, then try again.');
                else if (name === 'NotFoundError' || name === 'OverconstrainedError') say('No microphone was found on this device.');
                else say('The microphone could not be started.');
            });
        });
        stopRec.addEventListener('click', function () { if (rec) { sendNow = true; rec.stop(); } });
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
    // When the optional server table exists the marks follow the agent across devices; otherwise they stay in this browser.
    var server = null, serverTried = false;
    var FIELD = { pin: 'pinned', arch: 'archived', unread: 'unread' };
    function websiteId() { return window.Panel && Panel.getCurrentWebsiteId ? Panel.getCurrentWebsiteId() : null; }
    function loadServerPrefs() {
        var w = websiteId();
        if (!w || !window.Panel || !Panel.fetchJSON) return false;
        serverTried = true;
        Panel.fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(w) + '/conversation-prefs').then(function (res) {
            if (!res || !res.success || !res.data || !res.data.ready) return;
            var s = { pin: {}, arch: {}, unread: {} }, all = res.data.prefs || {};
            Object.keys(all).forEach(function (id) {
                if (all[id].pinned) s.pin[id] = 1;
                if (all[id].archived) s.arch[id] = 1;
                if (all[id].unread) s.unread[id] = 1;
            });
            server = s;
            decorate();
        }).catch(function () { /* stay local */ });
        return true;
    }
    function prefs() { return server || store(KEY) || { pin: {}, arch: {}, unread: {} }; }
    function setPref(kind, id, on) {
        var p = prefs();
        if (on) p[kind][id] = 1; else delete p[kind][id];
        if (server) {
            var w = websiteId(), body = {};
            body[FIELD[kind]] = on;
            if (w && window.Panel && Panel.fetchJSON) {
                Panel.fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(w) + '/conversations/' + encodeURIComponent(id) + '/prefs', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
                }).then(function (res) {
                    if (!res || !res.success) { if (on) delete server[kind][id]; else server[kind][id] = 1; decorate(); }
                }).catch(function () { /* the next load re-reads the server state */ });
            }
        } else {
            store(KEY, p);
        }
        decorate();
    }
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
        if (!serverTried && list.querySelector('.ai-chat-item')) loadServerPrefs();
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
            if (!document.body.classList.contains('wa-in-chat') && window.innerWidth <= 960) return;
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

    /* ---------- WhatsApp composer: [+] [ emoji | message | mic ] [attach] [send]; everything else lives in the + tray ---------- */
    (function composerShell() {
        var sendB = $('sendManualBtn'), attachB = $('attachBtn');
        var rows = [].slice.call(replyCard.children);
        var oldRow = rows.filter(function (n) { return n.contains(sendB); })[0];
        if (!sendB || !oldRow) return;
        var bar = el('div', 'wa-bar');
        var plus = el('button', 'wa-plus', '+');
        plus.type = 'button'; plus.title = 'More'; plus.setAttribute('aria-label', 'More actions'); plus.setAttribute('aria-expanded', 'false');
        var tray = el('div', 'wa-tray');
        tray.hidden = true;
        // the quote / payment-link buttons from the old card header, and the secondary buttons from the old button row
        var head = replyCard.querySelector('.p-card-head');
        if (head) { [].slice.call(head.querySelectorAll('button,a')).forEach(function (b) { tray.appendChild(b); }); head.classList.add('wa-gone'); }
        ['templateBtn', 'catalogBtn', 'interactiveBtn', 'suggestBtn'].forEach(function (id) { var b = $(id); if (b) tray.appendChild(b); });
        var leads = oldRow.querySelector('a.p-btn');
        if (leads) tray.appendChild(leads);
        bar.appendChild(plus);
        bar.appendChild(formGroup);
        if (attachB) bar.appendChild(attachB);
        bar.appendChild(sendB);
        replyCard.insertBefore(bar, oldRow);
        replyCard.insertBefore(tray, oldRow);
        oldRow.classList.add('wa-gone');
        plus.addEventListener('click', function (e) {
            e.stopPropagation();
            tray.hidden = !tray.hidden;
            plus.setAttribute('aria-expanded', String(!tray.hidden));
            plus.classList.toggle('on', !tray.hidden);
        });
        tray.addEventListener('click', function (e) { if (e.target.closest('button,a')) { tray.hidden = true; plus.classList.remove('on'); plus.setAttribute('aria-expanded', 'false'); } });
        document.addEventListener('click', function (e) { if (!tray.hidden && !tray.contains(e.target) && e.target !== plus) { tray.hidden = true; plus.classList.remove('on'); } });
        msgBox.title = msgBox.placeholder; // keep the Enter / Shift+Enter hint as a tooltip
        msgBox.placeholder = 'Message';
        document.body.classList.add('wa-composer');
    })();

    /* ---------- chat top bar: back arrow, avatar, name (phone); tap for the rest ---------- */
    (function headerShell() {
        var back = panel.querySelector('.wa-back');
        if (back) back.setAttribute('aria-label', 'Back to the conversations');
        var h = $('convHeader');
        if (!h) return;
        function paint() {
            var first = h.querySelector('.p-card-head');
            if (!first || first.querySelector('.wa-hav')) return;
            var name = (first.querySelector('h3') ? first.querySelector('h3').firstChild : null);
            var label = name && name.textContent ? name.textContent.trim() : '?';
            var av = el('button', 'wa-hav', (label.charAt(0) || '?').toUpperCase());
            av.type = 'button';
            av.title = 'Contact photo';
            av.setAttribute('aria-label', 'Change contact photo');
            var row = document.querySelector('.ai-chat-item.active');
            function showPhoto(id, v) {
                av.textContent = '';
                if (id && v && +v > 0) {
                    var img = el('img');
                    img.alt = ''; img.src = '/api/ai-chat/websites/' + websiteId() + '/conversations/' + id + '/photo?v=' + v;
                    av.appendChild(img);
                } else {
                    av.textContent = (label.charAt(0) || '?').toUpperCase();
                }
            }
            if (row) showPhoto(row.getAttribute('data-id'), row.getAttribute('data-photo'));
            av.addEventListener('click', function (e) {
                e.stopPropagation();
                var r = document.querySelector('.ai-chat-item.active');
                if (!r || !window.Panel) return;
                var id = r.getAttribute('data-id');
                var pick = el('input'); pick.type = 'file'; pick.accept = 'image/jpeg,image/png,image/webp';
                pick.addEventListener('change', function () {
                    var f = pick.files && pick.files[0];
                    if (!f) return;
                    var fd = new FormData(); fd.append('file', f);
                    Panel.fetchJSON('/api/ai-chat/websites/' + websiteId() + '/conversations/' + id + '/photo', { method: 'POST', body: fd }).then(function (res) {
                        if (!res || !res.success) { if (Panel.toast) Panel.toast((res && res.error) || 'The photo was not saved', 'error'); return; }
                        var v = res.data.photo_v;
                        r.setAttribute('data-photo', v);
                        var rav = r.querySelector('.ai-chat-avatar');
                        if (rav) { rav.textContent = ''; var im = el('img', 'wa-av-img'); im.alt = ''; im.src = '/api/ai-chat/websites/' + websiteId() + '/conversations/' + id + '/photo?v=' + v; rav.appendChild(im); }
                        showPhoto(id, v);
                    });
                });
                pick.click();
            });
            first.insertBefore(av, first.firstChild);
        }
        new MutationObserver(paint).observe(h, { childList: true });
        paint();
    })();

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
