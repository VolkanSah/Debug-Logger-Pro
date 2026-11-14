/* Debug Logger – log viewer. Plain JavaScript, no jQuery.
 * All log text is inserted with textContent, never as HTML, so log lines can't run code. */
(function () {
    'use strict';

    var cfg = window.EDL;
    if (!cfg || !cfg.i18n) {
        return;
    }
    var T = cfg.i18n;
    var LEVELS = ['fatal', 'error', 'warning', 'notice', 'deprecated', 'request'];
    var STORE = 'edl.view.v1';

    /* ---- helpers ------------------------------------------------------- */

    function h(tag, props, kids) {
        var node = document.createElement(tag);
        props = props || {};
        Object.keys(props).forEach(function (k) {
            if (k === 'text') {
                node.textContent = props[k];
            } else if (k.slice(0, 2) === 'on') {
                node.addEventListener(k.slice(2), props[k]);
            } else {
                node.setAttribute(k, props[k]);
            }
        });
        (kids || []).forEach(function (child) {
            if (child) {
                node.appendChild(child);
            }
        });
        return node;
    }

    function loadView() {
        var view = {
            source: 'all',
            levels: { fatal: true, error: true, warning: true, notice: true, deprecated: true, request: false }
        };
        try {
            var saved = JSON.parse(localStorage.getItem(STORE) || 'null');
            if (saved) {
                if (saved.source === 'plugin' || saved.source === 'wp' || saved.source === 'all') {
                    view.source = saved.source;
                }
                LEVELS.forEach(function (lv) {
                    if (saved.levels && typeof saved.levels[lv] === 'boolean') {
                        view.levels[lv] = saved.levels[lv];
                    }
                });
            }
        } catch (e) { /* storage blocked or corrupt: use defaults */ }
        return view;
    }

    function saveView(view) {
        try {
            localStorage.setItem(STORE, JSON.stringify({ source: view.source, levels: view.levels }));
        } catch (e) { /* ignore */ }
    }

    function post(action, extra) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', cfg.nonce);
        Object.keys(extra || {}).forEach(function (k) {
            body.set(k, extra[k]);
        });
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); });
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        // Fallback for plain http (typical on local dev sites).
        return new Promise(function (resolve, reject) {
            var ta = h('textarea', { 'aria-hidden': 'true' });
            ta.value = text;
            ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            return ok ? resolve() : reject();
        });
    }

    function downloadText(text) {
        var stamp = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19);
        var url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
        var a = h('a', { href: url, download: 'debug-log-' + stamp + '.txt' });
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    /* ---- viewer -------------------------------------------------------- */

    function createViewer(onData) {
        var view = loadView();
        var data = { entries: [], counts: {}, wpLog: false };
        var visible = [];
        var timer = null;
        var running = false;
        var paused = false;
        var busy = false;
        var lastSig = '';
        var searchTimer = null;
        var noteTimer = null;
        var query = '';

        // Toolbar: source, level chips, search, actions.
        var sourceSel = h('select', { 'class': 'edl-select', 'aria-label': T.source });
        ['all', 'plugin', 'wp'].forEach(function (k) {
            var opt = h('option', { value: k, text: T.sources[k] });
            if (k === view.source) { opt.selected = true; }
            sourceSel.appendChild(opt);
        });
        sourceSel.addEventListener('change', function () {
            view.source = sourceSel.value;
            saveView(view);
            lastSig = '';
            load();
        });

        var countEls = {};
        var chips = h('div', { 'class': 'edl-chips', role: 'group', 'aria-label': T.levelsLabel });
        LEVELS.forEach(function (lv) {
            var count = h('span', { 'class': 'edl-count', text: '0' });
            var chip = h('button', {
                type: 'button',
                'class': 'edl-chip',
                'data-level': lv,
                'aria-pressed': String(view.levels[lv])
            }, [h('span', { text: T.levels[lv] }), count]);
            chip.addEventListener('click', function () {
                view.levels[lv] = !view.levels[lv];
                chip.setAttribute('aria-pressed', String(view.levels[lv]));
                saveView(view);
                render();
            });
            countEls[lv] = count;
            chips.appendChild(chip);
        });

        var search = h('input', { type: 'search', 'class': 'edl-search', placeholder: T.search, 'aria-label': T.search });
        search.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                query = search.value.trim().toLowerCase();
                render();
            }, 150);
        });

        var liveBtn = h('button', { type: 'button', 'class': 'edl-btn edl-live', 'aria-pressed': 'true', text: T.live });
        liveBtn.addEventListener('click', function () {
            paused = !paused;
            liveBtn.setAttribute('aria-pressed', String(!paused));
            liveBtn.textContent = paused ? T.paused : T.live;
            if (paused) {
                clearTimeout(timer);
            } else if (running) {
                tick();
            }
        });

        var copyBtn = h('button', { type: 'button', 'class': 'edl-btn', text: T.copy });
        copyBtn.addEventListener('click', function () {
            copyText(asText()).then(function () { note(T.copied); }, function () { note(T.copyFailed, true); });
        });

        var downloadBtn = h('button', { type: 'button', 'class': 'edl-btn', text: T.download });
        downloadBtn.addEventListener('click', function () { downloadText(asText()); });

        var clearBtn = h('button', { type: 'button', 'class': 'edl-btn', text: T.clear });
        clearBtn.addEventListener('click', function () {
            if (!window.confirm(T.clearConfirm)) { return; }
            post('edl_clear', { source: view.source }).then(function (res) {
                if (res && res.success) {
                    lastSig = '';
                    note(T.cleared);
                    load();
                } else {
                    note(T.clearFailed, true);
                }
            }).catch(function () { note(T.clearFailed, true); });
        });

        var bar = h('div', { 'class': 'edl-bar' }, [
            sourceSel, chips, search, h('span', { 'class': 'edl-spacer' }),
            liveBtn, copyBtn, downloadBtn, clearBtn
        ]);
        var list = h('div', { 'class': 'edl-list', tabindex: '0', role: 'log', 'aria-label': T.title });
        var countText = h('span');
        var noteText = h('span', { role: 'status', 'aria-live': 'polite' });
        var status = h('div', { 'class': 'edl-status' }, [countText, noteText]);
        var node = h('div', { 'class': 'edl-viewer' }, [bar, list, status]);

        function note(msg, isError) {
            clearTimeout(noteTimer);
            noteText.textContent = msg;
            noteText.className = isError ? 'edl-error' : '';
            if (msg && !isError) {
                noteTimer = setTimeout(function () { noteText.textContent = ''; }, 3000);
            }
        }

        function label(e) {
            return T.levels[e.level] || e.level;
        }

        function asText() {
            return visible.map(function (e) {
                return '[' + e.time + '] ' + label(e) + ': ' + e.msg + (e.loc ? '  (' + e.loc + ')' : '');
            }).join('\n');
        }

        function row(e) {
            var lv = LEVELS.indexOf(e.level) === -1 ? 'notice' : e.level;
            var body = h('div', { 'class': 'edl-body' }, [h('div', { 'class': 'edl-text', text: e.msg })]);
            if (e.loc) { body.appendChild(h('div', { 'class': 'edl-loc', text: e.loc })); }
            if (e.ip) { body.appendChild(h('div', { 'class': 'edl-loc', text: 'IP ' + e.ip })); }
            return h('div', { 'class': 'edl-row edl-lv-' + lv }, [
                h('span', { 'class': 'edl-time', title: e.time, text: e.time ? e.time.slice(5) : '' }),
                h('span', { 'class': 'edl-badge', text: label(e) }),
                body
            ]);
        }

        function render() {
            var entries = data.entries || [];
            var counts = data.counts || {};
            LEVELS.forEach(function (lv) { countEls[lv].textContent = String(counts[lv] || 0); });

            visible = [];
            for (var i = entries.length - 1; i >= 0; i--) { // newest first
                var e = entries[i];
                if (!view.levels[e.level]) { continue; }
                if (query && (e.msg + ' ' + e.loc).toLowerCase().indexOf(query) === -1) { continue; }
                visible.push(e);
            }

            var top = list.scrollTop;
            list.textContent = '';
            if (!visible.length) {
                list.appendChild(h('div', { 'class': 'edl-empty', text: entries.length ? T.emptyFiltered : T.empty }));
            } else {
                var frag = document.createDocumentFragment();
                visible.forEach(function (e) { frag.appendChild(row(e)); });
                list.appendChild(frag);
                list.scrollTop = top;
            }
            countText.textContent = visible.length + ' ' + T.of + ' ' + entries.length + ' ' + T.entries;
        }

        function load() {
            if (busy) { return Promise.resolve(); }
            busy = true;
            return post('edl_logs', { source: view.source }).then(function (res) {
                if (!res || !res.success) { throw new Error('request refused'); }
                var sig = JSON.stringify(res.data);
                if (sig !== lastSig) { // skip redraw when nothing changed: no flicker, selection survives
                    lastSig = sig;
                    data = res.data;
                    render();
                    if (onData) { onData(data); }
                }
                note('');
            }).catch(function () {
                note(T.loadError, true);
            }).then(function () {
                busy = false;
            });
        }

        function schedule() {
            clearTimeout(timer);
            timer = null;
            if (running && !paused) { timer = setTimeout(tick, cfg.refresh); }
        }

        function tick() {
            clearTimeout(timer);
            (document.hidden ? Promise.resolve() : load()).then(schedule);
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && running && !paused) { tick(); }
        });

        render();

        return {
            node: node,
            start: function () { running = true; tick(); },
            stop: function () { running = false; clearTimeout(timer); timer = null; },
            refresh: load
        };
    }

    /* ---- mounting ------------------------------------------------------ */

    function mountPage(host) {
        var viewer = createViewer(null);
        host.appendChild(viewer.node);
        viewer.start();
    }

    function mountConsole(host) {
        var badge = h('span', { 'class': 'edl-pill-count' });
        var pill = h('button', {
            type: 'button',
            'class': 'edl-pill',
            'aria-expanded': 'false',
            'aria-controls': 'edl-panel'
        }, [h('span', { text: T.title }), badge]);

        var viewer = createViewer(function (data) {
            var c = data.counts || {};
            var n = (c.fatal || 0) + (c.error || 0) + (c.warning || 0);
            badge.textContent = n ? String(n) : '';
            pill.className = 'edl-pill' + (c.fatal ? ' edl-has-fatal' : (n ? ' edl-has-issues' : ''));
            pill.setAttribute('aria-label', T.title + (n ? ', ' + n + ' ' + T.issues : ''));
        });

        var panel = h('div', { id: 'edl-panel', 'class': 'edl-panel', role: 'region', 'aria-label': T.title, hidden: '' }, [viewer.node]);

        function setOpen(open) {
            panel.hidden = !open;
            pill.setAttribute('aria-expanded', String(open));
            if (open) { viewer.start(); } else { viewer.stop(); }
        }

        pill.addEventListener('click', function () { setOpen(panel.hidden); });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !panel.hidden) {
                setOpen(false);
                pill.focus();
            }
        });

        host.appendChild(panel);
        host.appendChild(pill);
        viewer.refresh(); // one request on load so the button can show the error count
    }

    function mount() {
        var host = document.getElementById('edl-app');
        if (!host) { return; }
        if (cfg.mode === 'page') { mountPage(host); } else { mountConsole(host); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
