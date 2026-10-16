@verbatim
    // ------------------------------------------------------------------
    // Core: strings, DOM helpers, transport, dialogs, polling, routing
    // ------------------------------------------------------------------

    var CFG = window.AMOUNT || {};
    var L = CFG.lang || {};
    var BASE = String(CFG.baseUrl || '').replace(/\/$/, '');
    var LANG = (document.documentElement.getAttribute('lang') || 'en');
    var POLL_MS = 2000;
    var STALE_SECONDS = 24 * 3600;
    var TABS = ['mounts', 'folders', 'files', 'server'];

    var S = {
        tab: 'mounts',
        loaded: false,
        drivers: [],
        mounts: [],
        rules: [],
        presets: [],
        queue: null,
        server: null,
        flags: {},
        groups: [],
        limits: {},
        runs: [],
        recent: [],
        timer: null,
        seen: {},
        s3: CFG.s3Presets || {},
        files: { rule: '', status: '', q: '', page: 1, data: null, selected: {} },
        filterRule: ''
    };

    var R = {}; // tab renderers, filled by the sections below

    /** A translated string; placeholders are written :name. */
    function t(key, rep) {
        var s = Object.prototype.hasOwnProperty.call(L, key) ? String(L[key]) : String(key);
        if (rep) {
            s = s.replace(/:([a-z_]+)/g, function (m, k) { return Object.prototype.hasOwnProperty.call(rep, k) ? String(rep[k]) : m; });
        }
        return s;
    }

    /** A value the server sends either as a lang key (a namespaced key) or as plain text. */
    function tr(value) {
        if (typeof value !== 'string') { return ''; }
        var m = /^aMount::global\.(.+)$/.exec(value);
        return m ? t(m[1]) : value;
    }

    function $(id) { return document.getElementById(id); }

    /** Element builder. Text only ever goes in as text nodes, so nothing needs escaping. */
    function h(tag, attrs, kids) {
        var el = document.createElement(tag);
        var a = attrs || {};
        Object.keys(a).forEach(function (k) {
            var v = a[k];
            if (v === false || v === null || v === undefined) { return; }
            if (k === 'class') { el.className = v; }
            else if (k === 'text') { el.textContent = v; }
            else if (k.indexOf('on') === 0 && typeof v === 'function') { el.addEventListener(k.slice(2), v); }
            else if (v === true) { el.setAttribute(k, ''); }
            else { el.setAttribute(k, String(v)); }
        });
        add(el, kids);
        return el;
    }

    function add(el, kids) {
        if (kids === null || kids === undefined || kids === false) { return el; }
        if (Array.isArray(kids)) { kids.forEach(function (k) { add(el, k); }); return el; }
        el.appendChild(typeof kids === 'object' && kids.nodeType ? kids : document.createTextNode(String(kids)));
        return el;
    }

    function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } return el; }
    function icon(name) { return h('i', { 'class': 'fa fa-' + name, 'aria-hidden': 'true' }); }

    /** A button with an optional icon; `onclick` is called with the button. */
    function btn(label, o) {
        o = o || {};
        var b = h('button', {
            type: 'button',
            'class': 'am-btn' + (o.primary ? ' am-btn-primary' : '') + (o.danger ? ' am-btn-danger' : '') + (o.small ? ' am-btn-small' : '') + (o.cls ? ' ' + o.cls : ''),
            title: o.title,
            'aria-label': o.aria,
            disabled: !!o.disabled
        }, [o.icon ? icon(o.icon) : null, label ? h('span', { text: label }) : null]);
        if (o.onclick) { b.addEventListener('click', function () { o.onclick(b); }); }
        return b;
    }

    function badge(text, kind) { return h('span', { 'class': 'am-badge' + (kind ? ' is-' + kind : ''), text: text }); }

    function num(n) {
        try { return new Intl.NumberFormat(LANG).format(Number(n) || 0); } catch (e) { return String(Number(n) || 0); }
    }

    function bytes(n) {
        n = Number(n) || 0;
        var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        var i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return (i === 0 ? String(Math.round(n)) : n.toFixed(n >= 100 ? 0 : (n >= 10 ? 1 : 2))) + ' ' + units[i];
    }

    function duration(sec) {
        sec = Math.max(0, Math.round(sec));
        if (sec < 60) { return t('ui_dur_s', { n: sec }); }
        if (sec < 3600) { return t('ui_dur_m', { n: Math.round(sec / 60) }); }
        return t('ui_dur_h', { n: Math.round(sec / 360) / 10 });
    }

    function stamp(iso) {
        if (!iso) { return 0; }
        var ms = new Date(iso).getTime();
        return isNaN(ms) ? 0 : ms;
    }

    /** "3 minutes ago", in the page language. */
    function ago(iso) {
        var ms = stamp(iso);
        if (!ms) { return ''; }
        var diff = Math.round((ms - Date.now()) / 1000);
        var units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]];
        try {
            var rtf = new Intl.RelativeTimeFormat(LANG, { numeric: 'auto' });
            for (var i = 0; i < units.length; i++) {
                if (Math.abs(diff) >= units[i][1] || units[i][0] === 'second') {
                    return rtf.format(Math.round(diff / units[i][1]), units[i][0]);
                }
            }
        } catch (e) { /* fall through */ }
        return new Date(ms).toLocaleString();
    }

    function when(iso) {
        var ms = stamp(iso);
        return ms ? new Date(ms).toLocaleString() : '';
    }

    function intervalLabel(minutes) {
        minutes = Number(minutes) || 0;
        if (minutes <= 0) { return t('ui_schedule_manual'); }
        if (minutes % 1440 === 0) { return minutes === 1440 ? t('ui_schedule_daily') : t('ui_schedule_every_days', { n: minutes / 1440 }); }
        if (minutes % 60 === 0) { return minutes === 60 ? t('ui_schedule_hourly') : t('ui_schedule_every_hours', { n: minutes / 60 }); }
        return t('ui_schedule_every_minutes', { n: minutes });
    }

    function sizeParts(bytesValue) {
        return Math.round((Number(bytesValue) / 1048576) * 100) / 100;
    }

    /** Announce something to screen readers without moving focus. */
    function announce(text) {
        var el = $('am-live');
        el.textContent = '';
        window.setTimeout(function () { el.textContent = text; }, 30);
    }

    function setMsg(el, text, kind) {
        if (!el) { return; }
        el.textContent = text || '';
        el.className = 'am-msg' + (kind ? ' is-' + kind : '');
    }

    function errMsg(r) {
        return (r && r.message) ? String(r.message) : t('ui_error_generic');
    }

    /** Why a site path cannot be offloaded: the Scope / LocalController reason code, in words. */
    function reasonText(code) {
        var key = 'ui_reason_' + code;
        return Object.prototype.hasOwnProperty.call(L, key) ? t(key) : t('ui_reason_unknown');
    }

    function copyText(text, button) {
        var done = function () {
            if (!button) { return; }
            var span = button.querySelector('span');
            var old = span ? span.textContent : '';
            if (span) { span.textContent = t('ui_copied'); }
            announce(t('ui_copied'));
            window.setTimeout(function () { if (span) { span.textContent = old; } }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text); done(); });
        } else {
            legacyCopy(text);
            done();
        }
    }

    function legacyCopy(text) {
        var ta = h('textarea', { 'class': 'am-sr', 'aria-hidden': 'true', tabindex: '-1' });
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* nothing more to try */ }
        document.body.removeChild(ta);
    }

    /** A command with a Copy button. */
    function commandLine(command) {
        return h('div', { 'class': 'am-cmd' }, [
            h('code', { text: command }),
            btn(t('ui_copy'), { icon: 'files-o', small: true, onclick: function (b) { copyText(command, b); } })
        ]);
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    function api(method, path, body, params) {
        var u = BASE + path;
        if (params) {
            var q = Object.keys(params)
                .filter(function (k) { return params[k] !== '' && params[k] !== null && params[k] !== undefined; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
                .join('&');
            if (q) { u += '?' + q; }
        }

        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        };

        if (method !== 'GET') {
            // The manager's CSRF middleware fails closed, so every write carries the token.
            opts.headers['X-CSRF-TOKEN'] = CFG.csrf;
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body || {});
        }

        return fetch(u, opts).then(function (res) {
            return res.json().then(function (json) {
                if (json && typeof json === 'object') { return json; }
                return { ok: false, message: t('ui_error_http', { status: res.status }) };
            }, function () {
                return { ok: false, message: res.status === 419 ? t('ui_error_session') : t('ui_error_http', { status: res.status }) };
            });
        }, function () {
            return { ok: false, message: t('ui_error_network') };
        });
    }

    // ------------------------------------------------------------------
    // Dialogs
    // ------------------------------------------------------------------

    var modalStack = [];

    /**
     * Open a dialog. Returns {el, body, foot, close}. Escape closes the top
     * one, Tab stays inside it, and focus returns to what opened it.
     */
    function openModal(o) {
        var opener = document.activeElement;
        var titleId = 'am-modal-title-' + (modalStack.length + 1) + '-' + Date.now();
        var body = h('div', { 'class': 'am-modal-body' });
        var foot = h('div', { 'class': 'am-modal-foot' });
        var box = h('div', { 'class': 'am-modal-box' + (o.narrow ? ' is-narrow' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId }, [
            h('div', { 'class': 'am-modal-head' }, [
                h('h2', { id: titleId, text: o.title }),
                btn('', { icon: 'times', small: true, aria: t('ui_close'), title: t('ui_close'), onclick: function () { api_.close(); } })
            ]),
            body,
            foot
        ]);
        var el = h('div', { 'class': 'am-modal' }, box);
        var api_ = {
            el: el, body: body, foot: foot,
            close: function () {
                var i = modalStack.indexOf(api_);
                if (i >= 0) { modalStack.splice(i, 1); }
                if (el.parentNode) { el.parentNode.removeChild(el); }
                if (opener && opener.focus && document.body.contains(opener)) { opener.focus(); }
                if (o.onClose) { o.onClose(); }
            }
        };
        el.addEventListener('mousedown', function (e) { if (e.target === el && !o.sticky) { api_.close(); } });
        $('am-modal-root').appendChild(el);
        modalStack.push(api_);
        window.setTimeout(function () {
            var first = body.querySelector('input:not([type=hidden]), select, textarea, button') || box.querySelector('button');
            if (first) { first.focus(); }
        }, 20);
        return api_;
    }

    document.addEventListener('keydown', function (e) {
        var top = modalStack[modalStack.length - 1];
        if (!top) { return; }
        if (e.key === 'Escape') { e.preventDefault(); top.close(); return; }
        if (e.key === 'Tab') {
            var items = top.el.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled):not([type=hidden]), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])');
            if (!items.length) { return; }
            var first = items[0];
            var last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    /** A yes/no question. `check` adds an optional checkbox; resolves {ok, checked}. */
    function confirmBox(o) {
        return new Promise(function (resolve) {
            var decided = false;
            var box = null;
            var checkbox = null;
            var msg = h('p', { 'class': 'am-msg', role: 'alert' });
            var finish = function (ok) {
                if (decided) { return; }
                decided = true;
                var checked = checkbox ? checkbox.checked : false;
                box.close();
                resolve({ ok: ok, checked: checked });
            };
            box = openModal({ title: o.title, narrow: true, onClose: function () { finish(false); } });
            add(box.body, [h('p', { text: o.message }), o.warning ? h('div', { 'class': 'am-alert am-alert-warn' }, o.warning) : null]);
            if (o.check) {
                checkbox = h('input', { type: 'checkbox', id: 'am-confirm-check' });
                add(box.body, h('div', { 'class': 'am-check' }, [checkbox, h('label', { 'for': 'am-confirm-check', text: o.check })]));
            }
            add(box.body, msg);
            add(box.foot, [
                btn(t('ui_cancel'), { onclick: function () { finish(false); } }),
                btn(o.confirm, { primary: !o.danger, danger: !!o.danger, onclick: function () { finish(true); } })
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Folder picker (local tree or remote store), shared by every dialog
    // ------------------------------------------------------------------

    /**
     * `load(path)` resolves the API answer {ok, path, folders:[{name, path}]}.
     * `onPick(path)` is called with the folder the person settles on.
     */
    function folderPicker(o) {
        var current = String(o.start || '');
        var crumbs = h('nav', { 'class': 'am-crumbs', 'aria-label': t('ui_picker_path') });
        var tree = h('div', { 'class': 'am-tree', role: 'list' });
        var useBtn = btn(t('ui_picker_use'), { primary: true, small: true, icon: 'check' });
        var where = h('span', { 'class': 'am-help' });
        var root = h('div', { 'class': 'am-picker' }, [
            crumbs, tree,
            h('div', { 'class': 'am-picker-foot' }, [where, useBtn])
        ]);

        function drawCrumbs(path) {
            clear(crumbs);
            var parts = path ? path.split('/') : [];
            var acc = '';
            add(crumbs, h('button', {
                type: 'button', 'class': 'am-crumb', 'aria-current': parts.length ? false : 'true',
                onclick: function () { go(''); }
            }, [icon('home'), ' ', o.rootLabel || t('ui_picker_root')]));
            parts.forEach(function (part, i) {
                acc += (acc ? '/' : '') + part;
                var target = acc;
                add(crumbs, h('span', { 'aria-hidden': 'true', text: '/' }));
                add(crumbs, h('button', {
                    type: 'button', 'class': 'am-crumb', 'aria-current': i === parts.length - 1 ? 'true' : false,
                    onclick: function () { go(target); }
                }, part));
            });
        }

        function go(path) {
            clear(tree);
            add(tree, h('div', { 'class': 'am-tree-note', text: t('ui_loading') }));
            o.load(path).then(function (r) {
                clear(tree);
                if (!r || r.ok === false) {
                    add(tree, h('div', { 'class': 'am-tree-note am-error-text', role: 'alert', text: errMsg(r) }));
                    return;
                }
                current = typeof r.path === 'string' ? r.path : path;
                drawCrumbs(current);
                where.textContent = current === '' ? (o.rootLabel || t('ui_picker_root')) : current;
                var list = r.folders || [];
                if (!list.length) { add(tree, h('div', { 'class': 'am-tree-note', text: t('ui_picker_empty') })); }
                list.forEach(function (f) {
                    var item = typeof f === 'string' ? { name: f.split('/').pop(), path: f } : f;
                    add(tree, h('button', {
                        type: 'button', 'class': 'am-tree-item', role: 'listitem',
                        onclick: function () { go(item.path); }
                    }, [icon('folder'), h('span', { text: item.name })]));
                });
            });
        }

        useBtn.addEventListener('click', function () { o.onPick(current); });
        drawCrumbs(current);
        go(current);
        return root;
    }

    // ------------------------------------------------------------------
    // Form building blocks
    // ------------------------------------------------------------------

    var fieldSeq = 0;

    /** Label + control + help + error slot. Returns {el, input, error}. */
    function field(label, control, o) {
        o = o || {};
        var id = control.id || ('am-f-' + (++fieldSeq));
        control.id = id;
        var helpEl = o.help ? h('p', { 'class': 'am-help', id: id + '-help', text: o.help }) : null;
        if (helpEl) { control.setAttribute('aria-describedby', id + '-help'); }
        var error = h('p', { 'class': 'am-field-error', id: id + '-error', role: 'alert', hidden: true });
        var el = h('div', { 'class': 'am-field' + (o.cls ? ' ' + o.cls : ''), 'data-field': o.name || false }, [
            h('label', { 'for': id }, [label, o.required ? h('span', { 'class': 'am-req', 'aria-hidden': 'true', text: ' *' }) : null]),
            control, helpEl, error
        ]);
        return { el: el, input: control, error: error };
    }

    function checkbox(label, checked, help) {
        var id = 'am-c-' + (++fieldSeq);
        var input = h('input', { type: 'checkbox', id: id, 'aria-describedby': help ? id + '-help' : false });
        input.checked = !!checked;
        var el = h('div', { 'class': 'am-check' }, [
            input,
            h('label', { 'for': id }, [label, help ? h('span', { 'class': 'am-help', id: id + '-help', text: help }) : null])
        ]);
        return { el: el, input: input };
    }

    function showFieldError(f, text) {
        if (!f || !f.error) { return; }
        f.error.textContent = text || '';
        f.error.hidden = !text;
        if (f.input) { f.input.setAttribute('aria-invalid', text ? 'true' : 'false'); }
    }

    /** Put {field: message} errors under their inputs; returns those without a field. */
    function applyErrors(fields, errors) {
        var left = [];
        Object.keys(fields).forEach(function (k) { showFieldError(fields[k], ''); });
        Object.keys(errors || {}).forEach(function (k) {
            if (fields[k]) { showFieldError(fields[k], tr(String(errors[k]))); }
            else { left.push(tr(String(errors[k]))); }
        });
        return left;
    }

    function lines(text) {
        return String(text || '').split(/\r?\n|,/).map(function (s) { return s.trim(); }).filter(Boolean);
    }

    function mountByUuid(uuid) {
        for (var i = 0; i < S.mounts.length; i++) { if (S.mounts[i].uuid === uuid) { return S.mounts[i]; } }
        return null;
    }

    function ruleByUuid(uuid) {
        for (var i = 0; i < S.rules.length; i++) { if (S.rules[i].uuid === uuid) { return S.rules[i]; } }
        return null;
    }

    function emptyState(iconName, title, text, action) {
        return h('div', { 'class': 'am-empty' }, [icon(iconName), h('strong', { text: title }), h('p', { text: text }), action || null]);
    }

    // ------------------------------------------------------------------
    // Runs: normalising, polling, progress
    // ------------------------------------------------------------------

    function normRun(r) {
        r = r || {};
        // The API flattens the run's payload: target, mount, rule, path, to and usage sit on the run itself.
        r.payload = {
            target: r.target || null, mount: r.mount || null, rule: r.rule || null,
            path: r.path || null, to: r.to || null, usage: r.usage || null
        };
        r.direction = r.direction || 'up';
        r.bytes_done = Number(r.bytes_done) || 0;
        r.bytes_total = Number(r.bytes_total) || 0;
        r.files_done = Number(r.files_done) || 0;
        r.files_total = Number(r.files_total) || 0;
        r.files_failed = Number(r.files_failed) || 0;
        return r;
    }

    function isActive(run) { return run.active === true || ['queued', 'scanning', 'uploading'].indexOf(run.status) >= 0; }

    function percent(run) {
        if (run.status === 'done') { return 100; }
        if (typeof run.percent === 'number') { return Math.max(0, Math.min(100, run.percent)); }
        if (run.bytes_total > 0) { return Math.min(99, Math.floor(run.bytes_done * 100 / run.bytes_total)); }
        return run.files_total > 0 ? Math.min(99, Math.floor(run.files_done * 100 / run.files_total)) : 0;
    }

    /** The mount or rule a measuring run is counting: {kind, uuid} or null. */
    function sizeTarget(run) {
        if (run.direction !== 'size') { return null; }
        var p = run.payload || {};
        if (p.target === 'mount' && p.mount) { return { kind: 'mount', uuid: p.mount }; }
        if (p.rule) { return { kind: 'rule', uuid: p.rule }; }
        return null;
    }

    function activeMeasure(kind, uuid) {
        for (var i = 0; i < S.runs.length; i++) {
            var tg = sizeTarget(S.runs[i]);
            if (tg && tg.kind === kind && tg.uuid === uuid) { return S.runs[i]; }
        }
        return null;
    }

    function lastMeasure(kind, uuid) {
        var best = null;
        S.recent.forEach(function (run) {
            var tg = sizeTarget(run);
            if (tg && tg.kind === kind && tg.uuid === uuid && (!best || stamp(run.finished_at || run.started_at) > stamp(best.finished_at || best.started_at))) { best = run; }
        });
        return best;
    }

    function pollRuns() {
        return api('GET', '/runs', null, { active: 1 }).then(function (r) {
            if (!r || r.ok === false) { return; }
            var list = (r.runs || r.data || []).map(normRun);
            var before = S.runs.map(function (x) { return x.uuid; });
            S.runs = list;
            var gone = before.filter(function (u) { return !list.some(function (x) { return x.uuid === u; }); });
            updateActivity();
            if (gone.length) { return runsFinished(gone); }
        });
    }

    function runsFinished(uuids) {
        return Promise.all(uuids.map(function (u) { return api('GET', '/runs/' + encodeURIComponent(u)); })).then(function (answers) {
            answers.forEach(function (r) {
                var run = r && r.ok !== false ? normRun(r.run || r) : null;
                if (!run || !run.uuid) { return; }
                var kept = S.recent.filter(function (x) { return x.uuid !== run.uuid; });
                kept.unshift(run);
                S.recent = kept;
                announce(t('ui_run_finished', { name: runName(run), status: statusLabel(run.status) }));
            });
            return loadBoot(true).then(function () {
                if (S.tab === 'files') { loadFiles(); }
            });
        });
    }

    function schedulePoll(delay) {
        window.clearTimeout(S.timer);
        S.timer = null;
        if (document.hidden || !S.runs.length) { return; }
        S.timer = window.setTimeout(function () {
            pollRuns().then(function () { schedulePoll(POLL_MS); });
        }, delay || POLL_MS);
    }

    /** Called after something was started: look for it now, then keep looking while it lives. */
    function kick() {
        window.clearTimeout(S.timer);
        return pollRuns().then(function () { schedulePoll(POLL_MS); });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && S.loaded) { kick(); } else { window.clearTimeout(S.timer); }
    });

    function runName(run) {
        if (run.direction === 'down') { return t('ui_run_download'); }
        if (run.direction === 'size') { return t('ui_run_measure'); }
        return run.rule_name || (ruleByUuid(run.rule) || {}).name || t('ui_run_upload');
    }

    function statusLabel(status) {
        var key = 'ui_status_' + status;
        return Object.prototype.hasOwnProperty.call(L, key) ? t(key) : String(status || '');
    }

    function visibleRuns() {
        return S.runs.filter(function (r) { return r.direction !== 'size'; });
    }

    /** Refresh everything that shows live numbers, without rebuilding anything with focus in it. */
    function updateActivity() {
        var active = visibleRuns();
        var count = $('am-active-count');
        count.hidden = active.length === 0;
        count.textContent = String(active.length);
        paintSummary();
        paintUsage();
        if (S.tab === 'files') { paintRuns(); }
    }

    // ------------------------------------------------------------------
    // Header: summary and queue health
    // ------------------------------------------------------------------

    function queueLabel(name) {
        if (name === 'system') { return t('ui_queue_system'); }
        if (name === 'stask') { return t('ui_queue_stask'); }
        return String(name || '');
    }

    function paintSummary() {
        var el = $('am-summary');
        clear(el);
        if (!S.loaded) { return; }
        var files = 0;
        var size = 0;
        S.rules.forEach(function (r) {
            if (r.index) { files += Number(r.index.files) || 0; size += Number(r.index.bytes) || 0; }
        });
        add(el, [
            h('b', { text: num(files) }), ' ', t('ui_summary_files'), ' (', bytes(size), ')',
            ' · ', h('b', { text: num(visibleRuns().length) }), ' ', t('ui_summary_active'),
            S.queue && S.queue.driver ? [' · ', t('ui_summary_queue', { queue: queueLabel(S.queue.driver) })] : null
        ]);
    }

    function paintQueue() {
        var el = clear($('am-queue'));
        var q = S.queue;
        if (!q) { return; }
        var driver = q.driver;
        var health = driver && q.queues ? q.queues[driver] : null;
        var broken = !driver || !health || health.ok === false || health.available === false;
        if (!broken) { return; }

        var hints = (q.hints || []).filter(function (x) { return !driver || x.queue === driver || x.queue === 'cron'; });
        var box = h('div', { 'class': 'am-alert am-alert-warn', role: 'alert' }, [
            h('strong', { text: driver ? t('ui_queue_down', { queue: queueLabel(driver) }) : t('ui_queue_none') }),
            h('span', { text: (health && health.message) || q.error || t('ui_queue_down_help') })
        ]);
        hints.forEach(function (x) {
            add(box, [h('span', { text: tr(x.message || '') }), x.command ? commandLine(x.command) : null]);
        });
        add(el, box);
    }

    function showPageError(text) {
        var el = clear($('am-page-error'));
        if (text) { add(el, h('div', { 'class': 'am-alert am-alert-error', role: 'alert', text: text })); }
    }

    // ------------------------------------------------------------------
    // Data loading
    // ------------------------------------------------------------------

    function applyBoot(r) {
        S.drivers = r.drivers || [];
        S.mounts = r.mounts || [];
        S.rules = r.rules || [];
        S.presets = r.presets || [];
        S.queue = r.queue || null;
        S.server = r.server || null;
        S.flags = r.flags || {};
        S.groups = r.groups || [];
        S.limits = r.limits || {};
        if (r.csrf_token) { CFG.csrf = r.csrf_token; }
        S.loaded = true;
        $('am-tab-server').hidden = !S.flags.may_manage;
        paintQueue();
        paintSummary();
    }

    /** Reload the page data. `quiet` keeps the current view in place when nothing changed shape. */
    function loadBoot(quiet) {
        return api('GET', '/bootstrap').then(function (r) {
            if (!r || r.ok === false) { showPageError(errMsg(r)); return; }
            showPageError('');
            applyBoot(r);
            renderTab(quiet);
        });
    }

    // ------------------------------------------------------------------
    // Tabs and routing (the hash holds the tab and the Files filter)
    // ------------------------------------------------------------------

    function parseHash() {
        var raw = location.hash.replace(/^#/, '');
        var parts = raw.split('?');
        var params = {};
        (parts[1] || '').split('&').forEach(function (kv) {
            if (!kv) { return; }
            var p = kv.split('=');
            params[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || '');
        });
        return { tab: parts[0], params: params };
    }

    function navigate(tab, params) {
        var q = params ? Object.keys(params).filter(function (k) { return params[k]; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&') : '';
        var hash = '#' + tab + (q ? '?' + q : '');
        if (location.hash === hash) { route(); } else { location.hash = hash; }
    }

    function route() {
        var p = parseHash();
        var tab = TABS.indexOf(p.tab) >= 0 ? p.tab : 'mounts';
        if (tab === 'server' && S.loaded && !S.flags.may_manage) { tab = 'mounts'; }
        S.tab = tab;
        if (tab === 'files') {
            var rule = p.params.rule || '';
            if (rule !== S.files.rule) { S.files.rule = rule; S.files.page = 1; }
        }
        TABS.forEach(function (name) {
            var on = name === tab;
            var tabEl = $('am-tab-' + name);
            tabEl.setAttribute('aria-selected', on ? 'true' : 'false');
            tabEl.tabIndex = on ? 0 : -1;
            $('am-panel-' + name).hidden = !on;
        });
        if (S.loaded) { renderTab(false); }
    }

    function renderTab(quiet) {
        var renderer = R[S.tab];
        if (!renderer) { return; }
        // A refresh while a dialog is open must not rebuild the page under it.
        if (quiet && modalStack.length) { return; }
        renderer();
    }

    $('am-tabs').addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-tab]') : null;
        if (b) { navigate(b.getAttribute('data-tab')); }
    });

    $('am-tabs').addEventListener('keydown', function (e) {
        if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(e.key) < 0) { return; }
        var visible = TABS.filter(function (n) { return !$('am-tab-' + n).hidden; });
        var i = visible.indexOf(S.tab);
        if (e.key === 'ArrowRight') { i = (i + 1) % visible.length; }
        else if (e.key === 'ArrowLeft') { i = (i - 1 + visible.length) % visible.length; }
        else if (e.key === 'Home') { i = 0; }
        else { i = visible.length - 1; }
        e.preventDefault();
        navigate(visible[i]);
        $('am-tab-' + visible[i]).focus();
    });

    window.addEventListener('hashchange', route);
@endverbatim
