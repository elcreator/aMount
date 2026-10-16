'use strict';
/*
 * A dependency-free smoke test of the manager page script.
 *
 * Runs the real script against a tiny fake DOM and a fetch() stub that answers
 * with the JSON shapes the controllers document, then drives a few screens.
 * It cannot prove the layout; it proves the script survives the real response
 * shapes (a contract that drifted shows up here as an exception or a missing
 * piece of text).
 *
 * usage: node smoke.cjs <script.js> <island.json>
 */
const fs = require('fs');

const [scriptFile, islandFile] = process.argv.slice(2);
const script = fs.readFileSync(scriptFile, 'utf8');
const island = JSON.parse(fs.readFileSync(islandFile, 'utf8'));

// ---------------------------------------------------------------- fake DOM

class Node_ {
    constructor(tag) {
        this.nodeType = tag === '#text' ? 3 : 1;
        this.tagName = String(tag).toUpperCase();
        this.children = [];
        this.parentNode = null;
        this.attrs = {};
        this.listeners = {};
        this.style = {};
        this.className = '';
        this.hidden = false;
        this.disabled = false;
        this.value = '';
        this.checked = false;
        this.tabIndex = 0;
        this._text = '';
    }
    get firstChild() { return this.children[0] || null; }
    get textContent() { return this.nodeType === 3 ? this._text : this.children.map(function (c) { return c.textContent; }).join(''); }
    set textContent(v) {
        if (this.nodeType === 3) { this._text = String(v); return; }
        this.children.forEach(function (c) { c.parentNode = null; });
        this.children = [];
        if (String(v) !== '') { this.appendChild(makeText(v)); }
    }
    get id() { return this.attrs.id || ''; }
    set id(v) { this.attrs.id = String(v); }
    appendChild(c) { if (c.parentNode) { c.parentNode.removeChild(c); } c.parentNode = this; this.children.push(c); return c; }
    removeChild(c) { const i = this.children.indexOf(c); if (i >= 0) { this.children.splice(i, 1); c.parentNode = null; } return c; }
    insertBefore(c, ref) {
        if (c.parentNode) { c.parentNode.removeChild(c); }
        const i = ref ? this.children.indexOf(ref) : -1;
        c.parentNode = this;
        if (i < 0) { this.children.push(c); } else { this.children.splice(i, 0, c); }
        return c;
    }
    setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'value') { this.value = String(v); } }
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; }
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
    focus() {}
    select() {}
    contains(n) { for (; n; n = n.parentNode) { if (n === this) { return true; } } return false; }
    closest() { return null; }
    all() { return this.children.reduce(function (a, c) { return a.concat([c], c.all ? c.all() : []); }, []); }
    querySelectorAll(sel) {
        const wanted = String(sel).split(',').map(function (s) { return s.trim().split(/[:\[]/)[0]; });
        return this.all().filter(function (n) {
            return n.nodeType === 1 && wanted.some(function (w) {
                return w.charAt(0) === '.' ? (' ' + n.className + ' ').indexOf(' ' + w.slice(1) + ' ') >= 0 : w.toUpperCase() === n.tagName;
            });
        });
    }
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}

function makeText(v) { const n = new Node_('#text'); n._text = String(v); return n; }

const body = new Node_('body');
const registry = {};
['am-live', 'am-active-count', 'am-summary', 'am-queue', 'am-page-error', 'am-tabs', 'am-modal-root',
    'am-tab-mounts', 'am-tab-folders', 'am-tab-files', 'am-tab-server',
    'am-panel-mounts', 'am-panel-folders', 'am-panel-files', 'am-panel-server'].forEach(function (id) {
    const el = new Node_('div');
    el.id = id;
    registry[id] = el;
    body.appendChild(el);
});
registry['am-tabs'].closest = function () { return null; };

const docListeners = {};
const document = {
    body: body,
    hidden: false,
    activeElement: null,
    documentElement: { getAttribute: function () { return 'en'; } },
    createElement: function (t) { return new Node_(t); },
    createTextNode: makeText,
    getElementById: function (id) { return registry[id] || body.all().filter(function (n) { return n.id === id; })[0] || null; },
    addEventListener: function (t, fn) { (docListeners[t] = docListeners[t] || []).push(fn); },
    execCommand: function () { return true; }
};

const winListeners = {};
const location = { hash: '' };
const calls = [];

const window = {
    AMOUNT: Object.assign({}, island, { boot: null }),
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    addEventListener: function (t, fn) { (winListeners[t] = winListeners[t] || []).push(fn); }
};

// ---------------------------------------------------------------- responses
// The documented shapes of the controllers (see Http/Controllers/*).

const mount = {
    uuid: 'm-1', name: 'Smoke Store', driver: 'local', driver_label: 'Local folder', scope: 'personal', status: 'untested',
    last_error: null, last_checked_at: null, options: { root: 'backups' }, config: { root: '/srv/x' }, secrets_set: {}, groups: [],
    credentials_unreadable: false, usage: null, index: { bytes: 0, files: 0, by_status: {} }, editable: true, deletable: true
};
const rule = {
    uuid: 'r-1', name: 'Smoke Images', mount: 'm-1', mount_name: 'Smoke Store', owner_id: 1, local_path: 'assets/images', remote_prefix: '',
    recursive: true, mode: 'copy', visibility: 'private', mirror_deletes: false, retention_days: 0, include: [], exclude: [], min_age: 60,
    min_size: 0, max_size: 0, interval_minutes: 0, enabled: true, ephemeral: false, origin: 'ui', last_run_at: null, next_run_at: null,
    usage: null, index: { bytes: 0, files: 0, by_status: {} }, last_run: null
};
const sizeRun = {
    uuid: 'run-size', rule: null, rule_name: null, direction: 'size', trigger: 'manual', status: 'scanning', active: true, finished: false,
    percent: 0, files_total: 0, files_done: 2, files_failed: 0, bytes_total: 0, bytes_done: 10, current_file: 'a', message: null,
    target: 'mount', mount: 'm-1', path: '', to: null, usage: { bytes: 10, files: 2, partial: true, rows: [{ label: 'a', bytes: 10, files: 2 }] },
    started_by: 1, started_at: null, finished_at: null, created_at: null
};
const server = {
    server: 'nginx', supported: true, directories: ['assets'], fingerprint: 'f', applied: null, applied_server: null, drift: true,
    needs_change: true, include_path: '/etc/nginx/amount.conf', writable: true, instructions: 'Include the file.'
};

const answers = [
    ['GET', /^\/bootstrap$/, function () {
        return {
            ok: true,
            drivers: [{
                id: 'local', label: 'Local folder', group: 'server', description: 'aMount::global.driver_local', description_text: 'Store on a folder of this machine',
                available: true, packages: [], install: null, defaults: {}, supports_visibility: false, needs_public_url: false,
                fields: [{ name: 'root', type: 'text', required: true, label: 'aMount::global.f_root', label_text: 'Folder on the server', help: null, help_text: null }]
            }],
            mounts: [mount], foreign_mounts: [], rules: [rule], presets: [], runs: [],
            queue: { driver: 'system', configured: 'auto', error: null, queues: { system: { available: true, ok: true, message: 'Task worker: healthy.' }, stask: { available: false, ok: false, message: 'n/a' } }, hints: [] },
            server: server, flags: { may_manage: true, is_admin: true, use_udperms: false, serve_enabled: true }, user_id: 1, groups: [{ id: 1, name: 'Editors' }],
            limits: { min_interval: 5, per_page: 50, max_attempts: 5, history_days: 60, stale_after: 900 }, csrf_token: 'TOKEN-FROM-BOOTSTRAP'
        };
    }],
    ['GET', /^\/runs$/, function () { return { ok: true, runs: [sizeRun] }; }],
    ['GET', /^\/runs\/[^/]+$/, function () { return { ok: true, run: Object.assign({}, sizeRun, { status: 'done', active: false, finished: true }) }; }],
    ['GET', /^\/files$/, function () {
        return { ok: true, data: [{ id: 7, rule_id: 1, local_path: 'assets/images/cat.png', remote_path: 'x', size: 10, size_human: '10 B', mtime: 1, visibility: 'private', status: 'uploaded', error: null, local_removed: false, uploaded_at: null, url: null }], page: 1, per_page: 50, total: 1, pages: 1 };
    }],
    ['GET', /^\/server$/, function () { return { ok: true, server: server, snippets: { nginx: 'location ~ ^/assets/ { SNIPPET-NGINX }', apache: 'RewriteRule SNIPPET-APACHE', frankenphp: '# nothing to change' } }; }],
    ['GET', /^\/local\/folders$/, function () { return { ok: true, path: '', parent: null, selectable: false, folders: [{ name: 'assets', path: 'assets', hasChildren: true }] }; }],
    ['GET', /^\/local\/check$/, function () { return { ok: true, path: 'manager', type: 'dir', offloadable: false, reason: 'protected', can_modify: false, publishable: false }; }],
    ['POST', /^\/mounts\/test$/, function () { return { ok: true, result: { ok: true, message: 'The connection works.' } }; }],
    ['POST', /^\/mounts\/[^/]+\/test$/, function () {
        // The service records the verdict on the row; the page reloads /bootstrap and shows it.
        mount.status = 'error';
        mount.last_error = 'Connection refused';
        return { ok: true, result: { ok: false, message: 'Connection refused' }, mount: mount };
    }]
];

function fetchStub(url, opts) {
    const path = String(url).replace(window.AMOUNT.baseUrl, '').replace(/\?.*$/, '');
    const method = (opts && opts.method) || 'GET';
    calls.push({ method: method, path: path, headers: (opts && opts.headers) || {}, body: opts && opts.body ? JSON.parse(opts.body) : null });
    const hit = answers.filter(function (a) { return a[0] === method && a[1].test(path); })[0];
    const json = hit ? hit[2]() : { ok: false, error: 'not_found', message: 'No stub for ' + method + ' ' + path };

    return Promise.resolve({ status: hit ? 200 : 404, json: function () { return Promise.resolve(json); } });
}

// ---------------------------------------------------------------- driving

const problems = [];
function expect(cond, what) { if (!cond) { problems.push(what); } }
const tick = function (ms) { return new Promise(function (r) { setTimeout(r, ms || 15); }); };
const find = function (root, pred) { return root.all().filter(pred); };
const textOf = function (el) { return el.textContent; };
const button = function (root, label) { return find(root, function (n) { return n.tagName === 'BUTTON' && textOf(n).trim() === label; })[0]; };
const click = function (el) { (el.listeners.click || []).forEach(function (fn) { fn({ target: el, preventDefault: function () {} }); }); };
const fire = function (el, type) { (el.listeners[type] || []).forEach(function (fn) { fn({ target: el, key: '' }); }); };
const goto = function (hash) { location.hash = hash; (winListeners.hashchange || []).forEach(function (fn) { fn(); }); return tick(); };
const L = window.AMOUNT.lang;

async function main() {
    new Function('window', 'document', 'location', 'history', 'navigator', 'fetch', script)(
        window, document, location, { replaceState: function () {} }, {}, fetchStub
    );
    await tick(60);

    const mounts = registry['am-panel-mounts'];
    expect(textOf(mounts).indexOf('Smoke Store') >= 0, 'the mount card is drawn from /bootstrap');
    expect(textOf(mounts).indexOf(L.ui_status_untested) >= 0, 'an untested mount has a human status');
    expect(textOf(registry['am-summary']).length > 0, 'the summary is drawn');
    expect(!find(registry['am-queue'], function (n) { return n.className.indexOf('am-alert') >= 0; }).length, 'a healthy queue shows no warning');
    expect(registry['am-tab-server'].hidden === false, 'managers see the server tab');

    // A card's Test button reads the nested result, and sends the token from /bootstrap.
    click(button(mounts, L.ui_test));
    await tick(30);
    const cardTest = calls.filter(function (c) { return c.path === '/mounts/m-1/test'; })[0];
    expect(cardTest && cardTest.headers['X-CSRF-TOKEN'] === 'TOKEN-FROM-BOOTSTRAP', 'writes carry the bootstrap csrf_token');
    expect(textOf(mounts).indexOf('Connection refused') >= 0, 'a failed test is visible on the card after the reload');
    expect(textOf(mounts).indexOf(L.ui_status_error) >= 0, 'the card shows the error status');

    // New mount: the test is stateless, nothing is posted to /mounts.
    click(button(mounts, L.ui_mount_add));
    const root = registry['am-modal-root'];
    click(find(root, function (n) { return n.tagName === 'BUTTON' && n.attrs['aria-label'] && /Local folder/.test(n.attrs['aria-label']); })[0]);
    expect(textOf(root).indexOf('Folder on the server') >= 0, 'driver fields use label_text');
    const rootInput = find(root, function (n) { return n.tagName === 'INPUT' && n.attrs.autocomplete === 'off' && n.attrs.maxlength !== '120'; })[0];
    rootInput.value = '/srv/new';
    click(button(root, L.ui_test_connection));
    await tick(30);
    const draft = calls.filter(function (c) { return c.method === 'POST' && c.path === '/mounts/test'; })[0];
    expect(draft && draft.body.driver === 'local' && draft.body.config.root === '/srv/new', 'Test connection posts the unsaved form to /mounts/test');
    expect(!calls.some(function (c) { return c.method === 'POST' && c.path === '/mounts'; }), 'Test connection saves nothing');
    expect(textOf(root).indexOf('The connection works.') >= 0, 'a good draft test shows result.message');
    expect(!!button(root, L.ui_save), 'Save appears after a good test');
    click(button(root, L.ui_cancel));

    // Folders tab and the local check with a refusal reason.
    mount.status = 'ok'; // rules can only target a mount that is not known to be broken
    mount.last_error = null;
    await goto('#folders');
    await tick(30);
    expect(textOf(registry['am-panel-folders']).indexOf('Smoke Images') >= 0, 'rule cards are drawn');
    click(button(registry['am-panel-folders'], L.ui_edit));
    const dlg = registry['am-modal-root'];
    const local = find(dlg, function (n) { return n.tagName === 'INPUT' && n.value === 'assets/images'; })[0];
    local.value = 'manager';
    fire(local, 'change');
    await tick(30);
    expect(textOf(dlg).indexOf(L.ui_reason_protected) >= 0, 'a refused folder is explained with the reason text');
    click(button(dlg, L.ui_cancel));

    // Files tab.
    await goto('#files');
    await tick(30);
    expect(textOf(registry['am-panel-files']).indexOf('assets/images/cat.png') >= 0, 'files are drawn from {data}');

    // Server tab.
    await goto('#server');
    await tick(30);
    expect(textOf(registry['am-panel-server']).indexOf('SNIPPET-NGINX') >= 0, 'the server snippet is drawn from {snippets}');
}

main().then(function () {
    if (problems.length) { console.error('SMOKE FAILED:\n - ' + problems.join('\n - ')); process.exit(1); }
    console.log('smoke ok (' + calls.length + ' requests)');
    process.exit(0);
}, function (e) {
    console.error('SMOKE CRASHED: ' + (e && e.stack || e));
    process.exit(2);
});
