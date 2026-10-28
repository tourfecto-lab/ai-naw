/* Tourfecto — فقاعة شات الموقع. بتشتغل جوه Shadow DOM عشان ستايل موقع العميل مايأثرش عليها
 * ولا هي تأثر عليه. الإعدادات بتيجي من window.__TFCHAT (السيرفر بيحطها قبل الملف ده). */
(function () {
    var C = window.__TFCHAT;
    if (!C || window.__TFCHAT_ON) return;
    window.__TFCHAT_ON = 1;

    var lang = (document.documentElement.lang || navigator.language || 'en').slice(0, 2).toLowerCase();
    var S = {
        ar: { title: 'تواصل معنا', sub: 'بنرد عادةً في ثواني', ph: 'اكتب رسالتك…', send: 'إرسال', hi: 'أهلًا! 👋 إزاي نقدر نساعدك في رحلتك؟', chips: ['الأسعار', 'المواعيد المتاحة', 'عايز أحجز رحلة'], wait: 'وصلتنا رسالتك ✓ فريقنا هيرد عليك هنا قريب — ممكن تسيب رقم واتساب أو إيميل عشان نتابع معاك.', off: 'الشات مش متاح دلوقتي — جرّب كمان شوية.', slow: 'لحظة… بتبعت بسرعة شوية.', open: 'افتح الشات', close: 'اقفل', team: 'فريق العمل', by: 'بدعم من Tourfecto' },
        en: { title: 'Chat with us', sub: 'We usually reply in seconds', ph: 'Type your message…', send: 'Send', hi: 'Hi there! 👋 How can we help with your trip?', chips: ['Prices', 'Availability', 'I want to book'], wait: 'Got your message ✓ Our team will reply right here soon — feel free to leave your WhatsApp or email so we can follow up.', off: 'Chat is unavailable right now — please try again shortly.', slow: 'One moment… you’re sending quite fast.', open: 'Open chat', close: 'Close', team: 'Team', by: 'Powered by Tourfecto' },
        de: { title: 'Schreiben Sie uns', sub: 'Wir antworten meist in Sekunden', ph: 'Ihre Nachricht…', send: 'Senden', hi: 'Hallo! 👋 Wie können wir bei Ihrer Reise helfen?', chips: ['Preise', 'Verfügbarkeit', 'Ich möchte buchen'], wait: 'Nachricht erhalten ✓ Unser Team antwortet hier in Kürze — hinterlassen Sie gern WhatsApp oder E-Mail.', off: 'Der Chat ist gerade nicht verfügbar.', slow: 'Einen Moment…', open: 'Chat öffnen', close: 'Schließen', team: 'Team', by: 'Powered by Tourfecto' },
        fr: { title: 'Discutons', sub: 'Réponse en quelques secondes', ph: 'Votre message…', send: 'Envoyer', hi: 'Bonjour ! 👋 Comment pouvons-nous vous aider pour votre voyage ?', chips: ['Tarifs', 'Disponibilités', 'Je veux réserver'], wait: 'Message reçu ✓ Notre équipe vous répondra ici très vite — laissez votre WhatsApp ou e-mail si vous le souhaitez.', off: 'Le chat est indisponible pour le moment.', slow: 'Un instant…', open: 'Ouvrir le chat', close: 'Fermer', team: 'Équipe', by: 'Powered by Tourfecto' },
        es: { title: 'Escríbenos', sub: 'Solemos responder en segundos', ph: 'Escribe tu mensaje…', send: 'Enviar', hi: '¡Hola! 👋 ¿Cómo podemos ayudarte con tu viaje?', chips: ['Precios', 'Disponibilidad', 'Quiero reservar'], wait: 'Mensaje recibido ✓ Nuestro equipo te responderá aquí pronto — puedes dejar tu WhatsApp o email.', off: 'El chat no está disponible ahora.', slow: 'Un momento…', open: 'Abrir chat', close: 'Cerrar', team: 'Equipo', by: 'Powered by Tourfecto' },
        it: { title: 'Scrivici', sub: 'Rispondiamo in pochi secondi', ph: 'Scrivi un messaggio…', send: 'Invia', hi: 'Ciao! 👋 Come possiamo aiutarti con il tuo viaggio?', chips: ['Prezzi', 'Disponibilità', 'Voglio prenotare'], wait: 'Messaggio ricevuto ✓ Il nostro team ti risponderà qui a breve — lascia pure WhatsApp o email.', off: 'La chat non è disponibile ora.', slow: 'Un momento…', open: 'Apri chat', close: 'Chiudi', team: 'Team', by: 'Powered by Tourfecto' },
        ru: { title: 'Напишите нам', sub: 'Обычно отвечаем за секунды', ph: 'Ваше сообщение…', send: 'Отправить', hi: 'Здравствуйте! 👋 Чем помочь с вашей поездкой?', chips: ['Цены', 'Наличие мест', 'Хочу забронировать'], wait: 'Сообщение получено ✓ Наша команда скоро ответит здесь — можете оставить WhatsApp или email.', off: 'Чат сейчас недоступен.', slow: 'Минутку…', open: 'Открыть чат', close: 'Закрыть', team: 'Команда', by: 'Powered by Tourfecto' }
    };
    var T = S[lang] || S.en;
    var rtl = lang === 'ar';
    // الترحيب المحفوظ بيظهر بس لو بنفس لغة الصفحة (ترحيب عربي مايتقالش لزائر ألماني)
    var greet = C.greeting && /[\u0600-\u06FF]/.test(C.greeting) === rtl ? C.greeting : T.hi;
    var acc = C.color || getComputedStyle(document.documentElement).getPropertyValue('--wsx-acc').trim() || '#0F766E';

    function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
    var vid = store('tfchat_v');
    if (!vid || !/^[a-z0-9]{20,32}$/.test(vid)) {
        var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a);
        vid = Array.prototype.map.call(a, function (b) { return ('0' + b.toString(36)).slice(-2); }).join('').slice(0, 24);
        while (vid.length < 24) vid += '0';
        store('tfchat_v', vid);
    }
    var started = store('tfchat_s_' + C.site) === '1';

    var host = document.createElement('div');
    host.id = 'tf-chat';
    host.style.cssText = 'position:fixed;z-index:2147483000;bottom:0;' + (rtl ? 'left' : 'right') + ':0;';
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var lift = document.querySelector('.wsx-wa-float') ? 76 : 0; // فوق زرار الواتساب العائم في مواقعنا
    root.innerHTML = '<style>' +
        ':host,*{box-sizing:border-box}' +
        '.b{position:fixed;bottom:' + (20 + lift) + 'px;' + (rtl ? 'left' : 'right') + ':20px;width:58px;height:58px;border-radius:50%;border:0;cursor:pointer;background:' + acc + ';color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.22);display:flex;align-items:center;justify-content:center;transition:transform .18s}' +
        '.b:hover{transform:scale(1.06)}.b svg{width:28px;height:28px}' +
        '.n{position:absolute;top:-2px;' + (rtl ? 'left' : 'right') + ':-2px;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:#e11d48;color:#fff;font:700 12px/20px system-ui,sans-serif;display:none}' +
        '.p{position:fixed;bottom:' + (90 + lift) + 'px;' + (rtl ? 'left' : 'right') + ':20px;width:370px;max-width:calc(100vw - 24px);height:540px;max-height:calc(100vh - ' + (110 + lift) + 'px);background:#fff;border-radius:18px;box-shadow:0 18px 50px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden;font:14px/1.5 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;color:#1f2937;direction:' + (rtl ? 'rtl' : 'ltr') + '}' +
        '.p.on{display:flex;animation:u .2s ease-out}@keyframes u{from{opacity:0;transform:translateY(12px)}}' +
        '.h{background:' + acc + ';color:#fff;padding:14px 16px;display:flex;align-items:center;gap:10px}.h b{display:block;font-size:15px}.h small{opacity:.85;font-size:12px;display:flex;align-items:center;gap:6px}.h small:before{content:"";width:8px;height:8px;border-radius:50%;background:#4ade80}' +
        '.h .x{margin-inline-start:auto;background:rgba(255,255,255,.18);border:0;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:18px;line-height:1}' +
        '.m{flex:1;overflow-y:auto;padding:14px;background:#f6f7f9;display:flex;flex-direction:column;gap:8px}' +
        '.g{max-width:82%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}' +
        '.g.me{align-self:flex-end;background:' + acc + ';color:#fff;border-end-end-radius:4px}' +
        '.g.bot,.g.agent{align-self:flex-start;background:#fff;border:1px solid #e5e7eb;border-end-start-radius:4px}' +
        '.g.agent i{display:block;font-style:normal;font-size:11px;color:#6b7280;margin-bottom:2px}' +
        '.g a{color:inherit;text-decoration:underline}.g.note{align-self:center;background:#eef2ff;color:#3730a3;font-size:12.5px;text-align:center;max-width:94%}' +
        '.ty{align-self:flex-start;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:10px 14px;display:none}.ty span{display:inline-block;width:6px;height:6px;margin:0 2px;border-radius:50%;background:#9ca3af;animation:d 1s infinite}.ty span:nth-child(2){animation-delay:.15s}.ty span:nth-child(3){animation-delay:.3s}@keyframes d{50%{opacity:.25}}' +
        '.c{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 10px;background:#f6f7f9}.c button{border:1px solid ' + acc + ';color:' + acc + ';background:#fff;border-radius:999px;padding:6px 12px;font:inherit;font-size:13px;cursor:pointer}' +
        '.f{display:flex;gap:8px;padding:10px;border-top:1px solid #e5e7eb;background:#fff}.f textarea{flex:1;resize:none;border:1px solid #d1d5db;border-radius:12px;padding:9px 11px;font:inherit;max-height:96px;outline:none}.f textarea:focus{border-color:' + acc + '}' +
        '.f button{border:0;background:' + acc + ';color:#fff;border-radius:12px;padding:0 14px;cursor:pointer;font:inherit;font-weight:600}.f button:disabled{opacity:.5}' +
        '.by{text-align:center;font-size:10.5px;color:#9ca3af;padding:0 0 6px;background:#fff}' +
        '@media(max-width:480px){.p{' + (rtl ? 'left' : 'right') + ':0;bottom:0;width:100vw;max-width:100vw;height:100%;max-height:100%;border-radius:0}.p.on~.b{display:none}}' +
        '</style>' +
        '<div class="p" role="dialog" aria-label="' + T.title + '"><div class="h"><div><b></b><small>' + T.sub + '</small></div><button class="x" type="button" aria-label="' + T.close + '">×</button></div>' +
        '<div class="m" aria-live="polite"></div><div class="c"></div>' +
        '<form class="f"><textarea rows="1" maxlength="1000" placeholder="' + T.ph + '" aria-label="' + T.ph + '"></textarea><button type="submit">' + T.send + '</button></form><div class="by">' + T.by + '</div></div>' +
        '<button class="b" type="button" aria-label="' + T.open + '"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3C6.5 3 2 6.9 2 11.7c0 2.6 1.3 4.9 3.4 6.5L4.6 22l4.2-2.2c1 .3 2.1.4 3.2.4 5.5 0 10-3.9 10-8.7S17.5 3 12 3z"/></svg><span class="n"></span></button>';
    (document.body || document.documentElement).appendChild(host);

    var $ = function (s) { return root.querySelector(s); };
    var panel = $('.p'), msgs = $('.m'), chips = $('.c'), form = $('.f'), input = $('textarea'), sendBtn = $('.f button'), badge = $('.n');
    $('.h b').textContent = C.name || T.title;
    var typing = document.createElement('div'); typing.className = 'ty'; typing.innerHTML = '<span></span><span></span><span></span>';

    var known = {}, pending = [], seen = parseInt(store('tfchat_seen_' + C.site) || '0', 10) || 0, waitTimer = null, pollTimer = null, fastUntil = 0, noteShown = false;

    function bubble(cls, text, label) {
        var d = document.createElement('div'); d.className = 'g ' + cls;
        if (label) { var i = document.createElement('i'); i.textContent = label; d.appendChild(i); }
        String(text).split(/(https?:\/\/[^\s<]+)/g).forEach(function (part, k) {
            if (k % 2) { var a = document.createElement('a'); a.href = part; a.target = '_blank'; a.rel = 'noopener nofollow'; a.textContent = part; d.appendChild(a); }
            else d.appendChild(document.createTextNode(part));
        });
        return d;
    }
    function render(list) {
        msgs.innerHTML = '';
        msgs.appendChild(bubble('bot', greet));
        list.forEach(function (m) { msgs.appendChild(bubble(m.from, m.text, m.from === 'agent' ? T.team : '')); });
        pending.forEach(function (p) { msgs.appendChild(bubble('me', p)); });
        if (noteShown) msgs.appendChild(bubble('note', T.wait));
        msgs.appendChild(typing);
        chips.style.display = list.length || pending.length ? 'none' : 'flex';
        msgs.scrollTop = msgs.scrollHeight;
    }
    T.chips.forEach(function (c) { var b = document.createElement('button'); b.type = 'button'; b.textContent = c; b.onclick = function () { send(c); }; chips.appendChild(b); });

    var last = [];
    function poll() {
        if (!started) return;
        fetch(C.api + '/poll?site=' + C.site + '&t=' + encodeURIComponent(C.t) + '&v=' + vid, { credentials: 'omit' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) return;
                var list = j.messages || [], replies = 0, fresh = false;
                list.forEach(function (m) {
                    if (m.from !== 'me') replies++;
                    if (!known[m.id]) { known[m.id] = 1; fresh = true; }
                });
                // رسايل الزائر اللي وصلت للسيرفر تتشال من المؤقتة
                var mine = list.filter(function (m) { return m.from === 'me'; }).length;
                if (pending.length && mine >= pendingBase + pending.length) pending = [];
                if (replies > lastReplies) { typing.style.display = 'none'; noteShown = false; clearTimeout(waitTimer); }
                lastReplies = replies;
                last = list;
                if (fresh || panel.classList.contains('on')) render(list);
                var unread = replies - seen;
                if (panel.classList.contains('on')) { seen = replies; store('tfchat_seen_' + C.site, String(seen)); unread = 0; }
                badge.style.display = unread > 0 ? 'block' : 'none'; badge.textContent = unread;
            })
            .catch(function () {})
            .then(schedule);
    }
    var lastReplies = 0, pendingBase = 0;
    function schedule() {
        clearTimeout(pollTimer);
        if (!started) return;
        var open = panel.classList.contains('on');
        pollTimer = setTimeout(poll, Date.now() < fastUntil ? 2500 : (open ? 8000 : 30000));
    }

    function send(text) {
        text = String(text || '').trim();
        if (!text) return;
        pendingBase = last.filter(function (m) { return m.from === 'me'; }).length;
        pending.push(text);
        render(last);
        typing.style.display = 'block'; msgs.scrollTop = msgs.scrollHeight;
        sendBtn.disabled = true;
        var body = new URLSearchParams({ site: C.site, t: C.t, v: vid, text: text, lang: lang, page: location.href.slice(0, 300) });
        fetch(C.api + '/send', { method: 'POST', body: body, credentials: 'omit' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) throw new Error(j && j.error === 'slow' ? T.slow : T.off);
                started = true; store('tfchat_s_' + C.site, '1');
                fastUntil = Date.now() + 45000;
                clearTimeout(waitTimer);
                // لو مفيش رد خلال 25 ثانية (البوت مستني موافقة أو المحادثة اتحولت لموظف) نطمّن الزائر
                waitTimer = setTimeout(function () { typing.style.display = 'none'; noteShown = true; render(last); }, 25000);
                poll();
            })
            .catch(function (e) {
                pending.pop(); typing.style.display = 'none'; render(last);
                msgs.insertBefore(bubble('note', e.message || T.off), typing);
            })
            .then(function () { sendBtn.disabled = false; });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); var v = input.value; input.value = ''; input.style.height = ''; send(v); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); } });
    input.addEventListener('input', function () { input.style.height = ''; input.style.height = Math.min(input.scrollHeight, 96) + 'px'; });
    function toggle(on) {
        panel.classList.toggle('on', on);
        if (on) { render(last); input.focus(); badge.style.display = 'none'; seen = lastReplies; store('tfchat_seen_' + C.site, String(seen)); poll(); }
    }
    $('.b').addEventListener('click', function () { toggle(!panel.classList.contains('on')); });
    $('.x').addEventListener('click', function () { toggle(false); });
    root.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggle(false); });
    render([]);
    if (started) poll();
})();
