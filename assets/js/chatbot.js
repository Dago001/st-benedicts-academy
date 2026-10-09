/* Chat assistant: talks to /api/chatbot.php; all content is rendered with textContent (no HTML injection). */
(function () {
    'use strict';
    var widget = document.getElementById('chatbotWidget');
    if (!widget) return;
    var btn = document.getElementById('chatbotButton'), box = document.getElementById('chatbotContainer');
    var closeBtn = document.getElementById('chatbotClose'), resetBtn = document.getElementById('chatbotReset');
    var log = document.getElementById('chatbotMessages'), form = document.getElementById('chatbotForm');
    var input = document.getElementById('chatbotInput'), send = document.getElementById('chatbotSend');
    var chips = document.getElementById('chatbotSuggestions');
    var KEY = 'stb_chat_v1', busy = false, history = [];
    var START = ['How to apply', 'School fees', 'Our programmes', 'Contact details'];

    function safeUrl(u) {
        return /^(https?:\/\/|tel:|mailto:|\/)/i.test(u) ? u : '#';
    }
    function time() { return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }

    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(history.slice(-30))); } catch (e) {} }
    function scroll(row) { log.scrollTop = (row && row.classList.contains('bot') && row.offsetHeight > log.clientHeight - 24) ? Math.max(0, row.offsetTop - 12) : log.scrollHeight; }

    function renderChips(list) {
        chips.textContent = '';
        (list || []).forEach(function (s) {
            var b = el('button', 'quick-reply', s); b.type = 'button';
            b.addEventListener('click', function () { ask(s); });
            chips.appendChild(b);
        });
    }

    function bubble(role, data, store) {
        var row = el('div', 'chat-msg ' + role), b = el('div', 'bubble');
        if (role === 'user') {
            b.appendChild(el('p', '', data.text));
        } else {
            (data.reply || []).forEach(function (p) { b.appendChild(el('p', '', p)); });
            if (data.list && data.list.length) { var ul = el('ul'); data.list.forEach(function (i) { ul.appendChild(el('li', '', i)); }); b.appendChild(ul); }
            if (data.note) b.appendChild(el('div', 'note', data.note));
            if (data.links && data.links.length) {
                var wrap = el('div', 'chat-links');
                data.links.forEach(function (l) {
                    var a = el('a', '', l.label); a.href = safeUrl(l.url);
                    if (/^https?:/i.test(a.href) && a.host !== location.host) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
                    wrap.appendChild(a);
                });
                b.appendChild(wrap);
            }
        }
        b.appendChild(el('span', 'chat-time', time()));
        row.appendChild(b); log.appendChild(row); scroll(row);
        if (store !== false) { history.push({ role: role, data: data }); save(); }
    }

    function typing() {
        var row = el('div', 'chat-msg bot'), b = el('div', 'bubble'), t = el('span', 'chat-typing');
        t.appendChild(el('span')); t.appendChild(el('span')); t.appendChild(el('span'));
        t.setAttribute('aria-label', 'Assistant is typing'); b.appendChild(t); row.appendChild(b); log.appendChild(row); scroll();
        return row;
    }

    function ask(text) {
        text = (text || '').trim();
        if (!text || busy) return;
        busy = true; send.disabled = true; renderChips([]);
        bubble('user', { text: text }); input.value = '';
        var t = typing();
        var started = Date.now();
        fetch((window.BASE_URL || '') + '/api/chatbot', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({ message: text })
        }).then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected response' }; }).then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (res) {
            // A short pause makes the reply feel natural without slowing real answers
            var wait = Math.max(0, 450 - (Date.now() - started));
            setTimeout(function () {
                t.remove();
                if (res.body && res.body.success) {
                    bubble('bot', res.body); renderChips(res.body.suggestions); scroll(log.lastElementChild);
                } else {
                    var msg = (res.body && res.body.message) || 'Something went wrong.';
                    if (res.status === 419) msg = 'Your session expired. Please refresh the page and try again.';
                    bubble('bot', { reply: [msg], links: [{ label: 'Call the school', url: 'tel:' + (widget.dataset.phone || '') }].filter(function (l) { return l.url.length > 4; }) }, false);
                    renderChips(START);
                }
                finish();
            }, wait);
        }).catch(function () {
            t.remove();
            bubble('bot', { reply: ['I could not reach the server. Please check your connection and try again.'] }, false);
            renderChips(START); finish();
        });
    }
    function finish() { busy = false; send.disabled = false; if (!matchMedia('(max-width: 575px)').matches) input.focus(); }

    function open() {
        box.hidden = false; widget.classList.add('open'); btn.setAttribute('aria-expanded', 'true');
        var n = btn.querySelector('.chatbot-notification'); if (n) n.style.display = 'none';
        if (!log.children.length) {
            try { history = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { history = []; }
            if (history.length) { history.forEach(function (m) { bubble(m.role, m.data, false); }); renderChips(START); }
            else greet();
        }
        setTimeout(function () { input.focus(); scroll(); }, 50);
    }
    function close() { box.hidden = true; widget.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); btn.focus(); }
    function greet() {
        history = [];
        bubble('bot', { reply: ['Hello! 👋 I am the school assistant. I can answer questions about admissions, fees, programmes, opening hours and contact details.', 'Everything I tell you comes from the school’s official information. If I am not sure, I will say so.'] });
        renderChips(START);
    }

    btn.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    resetBtn.addEventListener('click', function () { log.textContent = ''; try { sessionStorage.removeItem(KEY); } catch (e) {} greet(); input.focus(); });
    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden) close(); });
})();
