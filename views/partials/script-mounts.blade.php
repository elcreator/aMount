@verbatim
    // ------------------------------------------------------------------
    // Usage block: cached snapshot, Calculate, live progress, stale/error
    // ------------------------------------------------------------------

    var usageEls = {};

    function findTarget(kind, uuid) {
        return kind === 'mount' ? mountByUuid(uuid) : ruleByUuid(uuid);
    }

    function usageBlock(kind, uuid) {
        var total = h('span', { 'class': 'am-usage-total' });
        var meta = h('span', { 'class': 'am-usage-meta' });
        var button = btn(t('ui_usage_calculate'), { small: true, icon: 'refresh' });
        var bar = h('i');
        var barWrap = h('div', { 'class': 'am-progress-bar is-indeterminate' }, bar);
        var progressText = h('span');
        var progress = h('div', { 'class': 'am-progress', hidden: true }, [barWrap, progressText]);
        var error = h('p', { 'class': 'am-error-text', role: 'alert', hidden: true });
        var rows = h('div', { 'class': 'am-usage-rows' });
        var el = h('div', { 'class': 'am-usage' }, [
            h('div', { 'class': 'am-usage-head' }, [h('div', null, [total, h('br'), meta]), button]),
            progress, error, rows
        ]);
        var entry = { el: el, kind: kind, uuid: uuid, total: total, meta: meta, button: button, progress: progress, progressText: progressText, error: error, rows: rows, rowsSig: '' };
        usageEls[kind + ':' + uuid] = entry;

        button.addEventListener('click', function () {
            button.disabled = true;
            entry.error.hidden = true;
            api('POST', (kind === 'mount' ? '/mounts/' : '/rules/') + encodeURIComponent(uuid) + '/measure', {}).then(function (r) {
                if (!r || r.ok === false) {
                    entry.error.textContent = errMsg(r);
                    entry.error.hidden = false;
                    button.disabled = false;
                    return;
                }
                announce(t('ui_usage_started'));
                kick();
            });
        });

        paintUsageEntry(entry);
        return el;
    }

    function usageRows(rows) {
        return (rows || []).slice(0, 12);
    }

    function paintUsageEntry(entry) {
        var obj = findTarget(entry.kind, entry.uuid);
        if (!obj) { return; }
        var run = activeMeasure(entry.kind, entry.uuid);
        var snap = obj.usage && typeof obj.usage === 'object' ? obj.usage : null;
        var idx = obj.index || null;
        var rows = snap ? snap.rows : [];

        if (snap) {
            entry.total.textContent = bytes(snap.bytes) + ' · ' + t('ui_files_n', { n: num(snap.files) });
            var age = (Date.now() - stamp(snap.measured_at)) / 1000;
            var text = t('ui_measured_ago', { when: ago(snap.measured_at) });
            if (age > STALE_SECONDS) { text += ' — ' + t('ui_usage_stale'); }
            entry.meta.textContent = text;
        } else if (idx && Number(idx.files) > 0) {
            entry.total.textContent = bytes(idx.bytes) + ' · ' + t('ui_files_n', { n: num(idx.files) });
            entry.meta.textContent = t('ui_usage_index_only');
        } else {
            entry.total.textContent = t('ui_usage_none');
            entry.meta.textContent = t('ui_usage_not_measured');
        }

        var failed = null;
        if (!run) {
            var last = lastMeasure(entry.kind, entry.uuid);
            if (last && (last.status === 'failed' || last.status === 'cancelled') && (!snap || stamp(last.finished_at || last.started_at) > stamp(snap.measured_at))) { failed = last; }
        }

        entry.progress.hidden = !run;
        if (run) {
            var partial = run.payload && run.payload.usage ? run.payload.usage : null;
            var done = bytes(run.bytes_done) + ' · ' + t('ui_files_n', { n: num(run.files_done) });
            entry.progressText.textContent = t('ui_usage_counting', { done: done }) + (run.current_file ? ' · ' + run.current_file : '');
            if (partial && partial.rows) { rows = partial.rows; }
            entry.button.querySelector('span').textContent = t('ui_usage_counting_short');
            entry.button.disabled = true;
        } else {
            entry.button.querySelector('span').textContent = snap ? t('ui_usage_recalculate') : t('ui_usage_calculate');
            entry.button.disabled = false;
        }

        if (failed) {
            entry.error.textContent = t('ui_usage_failed') + (failed.message ? ': ' + failed.message : '');
            entry.error.hidden = false;
        } else if (run) {
            entry.error.hidden = true;
        }

        var list = usageRows(rows);
        var sig = JSON.stringify(list);
        if (sig !== entry.rowsSig) {
            entry.rowsSig = sig;
            clear(entry.rows);
            list.forEach(function (row) {
                add(entry.rows, h('div', { 'class': 'am-usage-row' }, [
                    h('span', { text: row.label === '' ? t('ui_usage_root') : String(row.label), title: String(row.label) }),
                    h('span', { text: bytes(row.bytes) }),
                    h('span', { text: t('ui_files_n', { n: num(row.files) }) })
                ]));
            });
        }
    }

    function paintUsage() {
        Object.keys(usageEls).forEach(function (key) {
            var entry = usageEls[key];
            if (!document.body.contains(entry.el)) { delete usageEls[key]; return; }
            paintUsageEntry(entry);
        });
    }

    // ------------------------------------------------------------------
    // Mounts tab
    // ------------------------------------------------------------------

    var GROUP_ORDER = ['object', 'cloud', 'server', 'other'];

    function driverById(id) {
        for (var i = 0; i < S.drivers.length; i++) { if (S.drivers[i].id === id) { return S.drivers[i]; } }
        return null;
    }

    function statusKind(status) {
        if (status === 'ok') { return 'ok'; }
        if (status === 'error') { return 'error'; }
        return 'unknown';
    }

    /** A test answers {ok:true, result:{ok, message}}: the request can succeed while the connection does not. */
    function testVerdict(r) {
        return !!(r && r.ok !== false && r.result && r.result.ok);
    }

    function testMessage(r) {
        if (r && r.ok !== false && r.result && r.result.message) { return String(r.result.message); }
        return testVerdict(r) ? t('test_ok') : errMsg(r);
    }

    function rulesUsing(uuid) {
        return S.rules.filter(function (r) { return r.mount === uuid; }).length;
    }

    function mountCard(m) {
        var msg = h('p', { 'class': 'am-msg', role: 'status' });
        var scopeText = t('ui_scope_' + m.scope);
        var card = h('article', { 'class': 'am-card', 'aria-label': m.name }, [
            h('div', { 'class': 'am-card-head' }, [
                h('h3', { 'class': 'am-card-title', text: m.name }),
                h('div', { 'class': 'am-card-badges' }, [
                    badge(m.driver_label || m.driver),
                    badge(scopeText, m.scope === 'personal' ? '' : 'warn'),
                    badge(statusLabel(m.status || 'unknown'), statusKind(m.status))
                ])
            ]),
            m.credentials_unreadable ? h('p', { 'class': 'am-error-text', role: 'alert', text: t('ui_mount_unreadable') }) : null,
            m.last_error ? h('p', { 'class': 'am-error-text', text: m.last_error }) : null,
            h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_mount_checked') }), h('span', { text: m.last_checked_at ? ago(m.last_checked_at) : t('ui_never') })]),
            h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_mount_folders') }), h('span', { text: String(rulesUsing(m.uuid)) })]),
            m.options && m.options.root ? h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_mount_root') }), h('span', { text: '/' + m.options.root })]) : null,
            m.scope === 'config' ? h('p', { 'class': 'am-help', text: t('ui_mount_config_note') }) : null,
            usageBlock('mount', m.uuid),
            msg,
            h('div', { 'class': 'am-card-actions' }, [
                btn(t('ui_test'), { icon: 'plug', small: true, onclick: function (b) {
                    b.disabled = true;
                    setMsg(msg, t('ui_testing'));
                    api('POST', '/mounts/' + encodeURIComponent(m.uuid) + '/test', {}).then(function (r) {
                        b.disabled = false;
                        var good = testVerdict(r);
                        setMsg(msg, testMessage(r), good ? 'ok' : 'error');
                        announce(msg.textContent);
                        loadBoot(true);
                    });
                } }),
                m.editable ? btn(t('ui_edit'), { icon: 'pencil', small: true, onclick: function () { mountDialog(m); } }) : null,
                m.deletable ? btn(t('ui_delete'), { icon: 'trash', small: true, danger: true, onclick: function () { deleteMount(m, msg); } }) : null
            ])
        ]);
        return card;
    }

    function deleteMount(m, msg) {
        var used = rulesUsing(m.uuid);
        confirmBox({
            title: t('ui_mount_delete_title'),
            message: t('ui_mount_delete_confirm', { name: m.name }),
            warning: used ? t('ui_mount_delete_used', { n: used }) : null,
            confirm: t('ui_delete'),
            danger: true,
            check: used ? t('ui_mount_delete_force') : null
        }).then(function (c) {
            if (!c.ok) { return; }
            api('POST', '/mounts/' + encodeURIComponent(m.uuid) + '/delete', { force: c.checked }).then(function (r) {
                if (!r || r.ok === false) { setMsg(msg, errMsg(r), 'error'); return; }
                announce(t('ui_mount_deleted'));
                loadBoot();
            });
        });
    }

    // ------------------------------------------------------------------
    // The account's computers (ai.artur.work shared folders)
    // ------------------------------------------------------------------

    function computersBox() {
        var list = h('div', { 'class': 'am-computers' });
        var msg = h('p', { 'class': 'am-msg', role: 'status' });
        var code = h('b', { 'class': 'am-code' });
        var box = h('section', { 'class': 'am-card am-computers-box', 'aria-label': t('ui_computers_title') }, [
            h('div', { 'class': 'am-card-head' }, [
                h('h3', { 'class': 'am-card-title', text: t('ui_computers_title') }),
                btn(t('ui_computers_load'), { small: true, icon: 'refresh', onclick: function (b) { load(b); } })
            ]),
            h('p', { 'class': 'am-help', text: t('ui_computers_intro') }),
            h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_computers_code') }), code]),
            msg, list
        ]);

        function shareLine(sh) {
            var access = sh.access === 'write' ? t('ui_computers_access_write') : sh.access === 'read' ? t('ui_computers_access_read') : t('ui_computers_access_none');
            var quota = sh.quota ? ' · ' + t('ui_computers_quota', { used: sh.used >= 0 ? Math.round(sh.used / 1048576) : '?', quota: Math.round(sh.quota / 1048576) }) : '';
            return h('li', null, [h('b', { text: sh.name }), h('span', { text: ' - ' + access + quota })]);
        }

        function load(b) {
            if (b) { b.disabled = true; }
            setMsg(msg, t('ui_computers_loading'));
            api('GET', '/computers').then(function (r) {
                if (b) { b.disabled = false; }
                clear(list);
                if (!r || r.ok === false) { setMsg(msg, errMsg(r), 'error'); return; }
                code.textContent = r.site && r.site.code ? r.site.code : '';
                setMsg(msg, r.error ? r.error : (r.computers.length ? '' : t('ui_computers_none')), r.error ? 'error' : '');
                r.computers.forEach(function (c) {
                    add(list, h('div', { 'class': 'am-computer' }, [
                        h('div', { 'class': 'am-card-head' }, [
                            h('b', { text: c.name }),
                            h('div', { 'class': 'am-card-badges' }, [
                                badge(c.online ? t('ui_computers_online') : t('ui_computers_offline'), c.online ? 'ok' : ''),
                                c.own ? null : badge(t('ui_computers_shared'), 'warn')
                            ])
                        ]),
                        c.problem ? h('p', { 'class': 'am-error-text', text: c.problem }) : null,
                        h('ul', { 'class': 'am-shares' }, (c.shares || []).map(shareLine)),
                        h('div', { 'class': 'am-card-actions' }, [
                            c.mount ? null : btn(t('ui_computers_mount'), { small: true, icon: 'plus', onclick: function (bb) {
                                bb.disabled = true;
                                api('POST', '/computers/mount', { computer: String(c.id) }).then(function (res) {
                                    if (!res || res.ok === false) { bb.disabled = false; setMsg(msg, errMsg(res), 'error'); return; }
                                    announce(t('ui_computers_mounted', { name: c.name }));
                                    loadBoot();
                                });
                            } }),
                            c.problem && S.flags.may_manage ? btn(t('ui_computers_trust'), { small: true, onclick: function () {
                                confirmBox({ title: t('ui_computers_trust'), message: t('ui_computers_trust_confirm', { name: c.name }), confirm: t('ui_computers_trust') }).then(function (k) {
                                    if (!k.ok) { return; }
                                    api('POST', '/computers/' + c.id + '/trust', {}).then(function () { load(); });
                                });
                            } }) : null
                        ])
                    ]));
                });
            });
        }

        return box;
    }

    R.mounts = function () {
        var panel = clear($('am-panel-mounts'));
        add(panel, h('div', { 'class': 'am-toolbar' }, [
            h('div', { 'class': 'am-grow' }, h('p', { 'class': 'am-help', text: t('ui_mounts_intro') })),
            btn(t('ui_mount_add'), { primary: true, icon: 'plus', onclick: function () { mountDialog(null); } })
        ]));
        if (S.drivers.some(function (d) { return d.id === 'ai-computer' && d.available !== false; })) {
            add(panel, computersBox());
        }
        if (!S.mounts.length) {
            add(panel, emptyState('cloud-upload', t('ui_mounts_empty_title'), t('ui_mounts_empty_text'),
                btn(t('ui_mount_add'), { primary: true, icon: 'plus', onclick: function () { mountDialog(null); } })));
            return;
        }
        add(panel, h('div', { 'class': 'am-cards' }, S.mounts.map(mountCard)));
    };

    // ------------------------------------------------------------------
    // Mount dialog: driver picker, generated form, test, save
    // ------------------------------------------------------------------

    function groupLabel(group) {
        var key = 'ui_group_' + group;
        return Object.prototype.hasOwnProperty.call(L, key) ? t(key) : group;
    }

    function pickDriver(box, onPick) {
        clear(box.body);
        clear(box.foot);
        add(box.foot, btn(t('ui_cancel'), { onclick: function () { box.close(); } }));
        add(box.body, h('p', { 'class': 'am-help', text: t('ui_driver_pick') }));
        GROUP_ORDER.forEach(function (group) {
            var drivers = S.drivers.filter(function (d) { return (d.group || 'other') === group; });
            if (!drivers.length) { return; }
            add(box.body, h('section', { 'class': 'am-driver-group' }, [
                h('h3', { text: groupLabel(group) }),
                h('div', { 'class': 'am-drivers' }, drivers.map(function (d) {
                    var available = d.available !== false;
                    var card = h('div', { 'class': 'am-driver' + (available ? '' : ' is-disabled') }, [
                        h('b', { text: d.label }),
                        h('span', { text: d.description_text || tr(d.description || '') })
                    ]);
                    if (available) {
                        var pick = h('button', { type: 'button', 'class': 'am-btn am-btn-small', 'aria-label': t('ui_driver_choose', { name: d.label }) }, [icon('plus'), h('span', { text: t('ui_driver_choose_short') })]);
                        pick.addEventListener('click', function () { onPick(d); });
                        add(card, pick);
                    } else {
                        add(card, [
                            h('span', { text: t('ui_driver_missing', { packages: (d.packages || []).join(', ') }) }),
                            d.install ? commandLine(d.install) : null
                        ]);
                    }
                    return card;
                }))
            ]));
        });
    }

    function showWhen(fieldDef, values) {
        var cond = fieldDef.show;
        if (!cond) { return true; }
        return Object.keys(cond).every(function (k) {
            var want = cond[k];
            var have = values[k];
            return Array.isArray(want) ? want.indexOf(have) >= 0 : String(have) === String(want);
        });
    }

    /**
     * The form: every driver field from its schema, then the options every
     * mount shares. `existing` is a presented mount when editing.
     */
    function mountForm(box, driver, existing) {
        clear(box.body);
        clear(box.foot);

        var cfg = existing ? existing.config || {} : {};
        var opts = existing ? existing.options || {} : {};
        var secretsSet = existing ? existing.secrets_set || {} : {};
        var defaults = driver.defaults || {};
        var fields = {};     // name -> {el,input,error}
        var inputs = {};     // name -> control
        var configFields = {};
        var state = { uuid: existing ? existing.uuid : null, tested: false, busy: false };
        var isConfig = existing && existing.scope === 'config';

        var general = h('p', { 'class': 'am-msg', role: 'status' });
        var testMsg = h('p', { 'class': 'am-msg', role: 'status' });

        function current() {
            var v = {};
            Object.keys(inputs).forEach(function (k) {
                var c = inputs[k];
                v[k] = c.type === 'checkbox' ? c.checked : c.value;
            });
            return v;
        }

        function invalidate() {
            state.tested = false;
            drawButtons();
        }

        // --- Name, ownership
        var nameF = field(t('ui_mount_name'), h('input', { type: 'text', autocomplete: 'off', maxlength: '120' }), { required: true, name: 'name' });
        nameF.input.value = existing ? existing.name : driver.label;
        fields.name = nameF;
        add(box.body, h('p', { 'class': 'am-help', text: driver.description_text || tr(driver.description || '') }));
        add(box.body, nameF.el);
        nameF.input.addEventListener('input', invalidate);

        var scopeValue = existing ? existing.scope : 'personal';
        var groupBoxes = [];
        var groupsWrap = null;
        if (!existing && S.flags.may_manage) {
            var radios = h('div', { role: 'radiogroup', 'aria-label': t('ui_mount_scope') });
            ['personal', 'site'].forEach(function (value) {
                var id = 'am-scope-' + value;
                var r = h('input', { type: 'radio', name: 'am-scope', id: id, value: value });
                r.checked = value === scopeValue;
                r.addEventListener('change', function () { scopeValue = value; if (groupsWrap) { groupsWrap.hidden = value !== 'site'; } invalidate(); });
                add(radios, h('div', { 'class': 'am-check' }, [r, h('label', { 'for': id }, [t('ui_scope_' + value), h('span', { 'class': 'am-help', text: t('ui_scope_' + value + '_help') })])]));
            });
            add(box.body, h('div', { 'class': 'am-field' }, [h('span', { 'class': 'am-label', text: t('ui_mount_scope') }), radios]));
        } else if (existing) {
            add(box.body, h('p', { 'class': 'am-help', text: t('ui_mount_scope') + ': ' + t('ui_scope_' + existing.scope) }));
        }

        if (S.flags.may_manage && (!existing || existing.scope === 'site')) {
            var granted = existing ? existing.groups || [] : [];
            var grantIds = granted.map(function (g) { return String(g && typeof g === 'object' ? g.id : g); });
            groupsWrap = h('div', { 'class': 'am-field', hidden: !existing && scopeValue !== 'site' }, [
                h('span', { 'class': 'am-label', text: t('ui_mount_groups') }),
                h('div', { 'class': 'am-multi', role: 'group', 'aria-label': t('ui_mount_groups') }, S.groups.length ? S.groups.map(function (g) {
                    var id = 'am-grp-' + g.id;
                    var c = h('input', { type: 'checkbox', id: id, value: String(g.id) });
                    c.checked = grantIds.indexOf(String(g.id)) >= 0;
                    c.addEventListener('change', invalidate);
                    groupBoxes.push(c);
                    return h('label', { 'for': id }, [c, g.name]);
                }) : h('span', { 'class': 'am-help', text: t('ui_mount_groups_none') })),
                h('p', { 'class': 'am-help', text: t('ui_mount_groups_help') })
            ]);
            add(box.body, groupsWrap);
        }

        // --- Driver fields, generated from the schema
        var set = h('fieldset', null, h('legend', { text: driver.label }));
        add(box.body, set);

        function refreshVisibility() {
            var v = current();
            Object.keys(configFields).forEach(function (name) {
                configFields[name].el.hidden = !showWhen(configFields[name].def, v);
            });
        }

        (driver.fields || []).forEach(function (def) {
            var type = def.type || 'text';
            var isSecret = !!def.secret;
            var value;
            if (isSecret) { value = ''; }
            else if (existing && cfg[def.name] !== undefined) { value = cfg[def.name]; }
            else { value = defaults[def.name] !== undefined ? defaults[def.name] : (def.default !== undefined ? def.default : ''); }
            var control;
            var f;
            var label = def.label_text || tr(def.label || def.name);
            var help = def.help_text || (def.help ? tr(def.help) : '');

            if (type === 'bool') {
                f = checkbox(label, value === true || value === 1 || value === '1', help);
                control = f.input;
                f.error = h('p', { 'class': 'am-field-error', role: 'alert', hidden: true });
                add(f.el, f.error);
            } else {
                if (type === 'select') {
                    control = h('select');
                    var optionsMap = def.options || {};
                    Object.keys(optionsMap).forEach(function (k) {
                        add(control, h('option', { value: k, text: tr(String(optionsMap[k])) }));
                    });
                    control.value = String(value === null || value === undefined ? '' : value);
                } else if (type === 'textarea') {
                    control = h('textarea', { rows: '4', spellcheck: 'false', autocomplete: 'off' });
                    control.value = isSecret ? '' : String(value);
                } else {
                    control = h('input', {
                        type: type === 'password' ? 'password' : (type === 'number' ? 'number' : 'text'),
                        autocomplete: type === 'password' ? 'new-password' : 'off', spellcheck: 'false'
                    });
                    control.value = isSecret ? '' : String(value === null || value === undefined ? '' : value);
                }
                var secretSaved = isSecret && secretsSet[def.name];
                if (isSecret && secretSaved) { control.setAttribute('placeholder', t('ui_secret_saved_placeholder')); }
                f = field(label, control, { required: !!def.required && !(isSecret && secretSaved), help: help, name: 'config.' + def.name });
                if (secretSaved) { add(f.el, h('span', { 'class': 'am-secret-note', text: t('ui_secret_saved') })); }
            }

            f.def = def;
            fields[def.name] = f;
            fields['config.' + def.name] = f;
            inputs[def.name] = control;
            configFields[def.name] = f;
            control.addEventListener(type === 'select' || type === 'bool' ? 'change' : 'input', function () {
                invalidate();
                refreshVisibility();
                if (driver.id === 's3' && def.name === 'preset') { applyS3Preset(control.value); }
            });
            add(set, f.el);
        });

        function applyS3Preset(key) {
            var p = S.s3[key];
            if (!p) { return; }
            if (inputs.endpoint) { inputs.endpoint.value = p.endpoint || ''; }
            if (inputs.region) { inputs.region.value = p.region || ''; }
            if (inputs.path_style) { inputs.path_style.checked = !!p.path_style; }
        }

        // --- Options every mount has
        function opt(name, f) { fields['options.' + name] = f; if (!fields[name]) { fields[name] = f; } }
        var shared = h('fieldset', null, h('legend', { text: t('ui_mount_options') }));
        add(box.body, shared);

        var rootF = field(t('ui_mount_remote_root'), h('input', { type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: 'backups/site' }), { help: t('ui_mount_remote_root_help'), name: 'root' });
        rootF.input.value = opts.root || '';
        opt('root', rootF);
        var pickerHost = h('div');
        var browseBtn = btn(t('ui_browse'), { icon: 'folder-open', small: true, onclick: function () {
            if (!state.uuid) { return; }
            if (pickerHost.firstChild) { clear(pickerHost); return; }
            add(pickerHost, folderPicker({
                start: rootF.input.value,
                rootLabel: t('ui_picker_store_root'),
                load: function (p) { return api('GET', '/mounts/' + encodeURIComponent(state.uuid) + '/browse', null, { path: p }); },
                onPick: function (p) { rootF.input.value = p; clear(pickerHost); invalidate(); }
            }));
        } });
        browseBtn.disabled = !state.uuid;
        var browseHint = h('p', { 'class': 'am-help', text: state.uuid ? '' : t('ui_mount_browse_hint') });
        add(rootF.el, [h('div', { 'class': 'am-row' }, [browseBtn]), browseHint, pickerHost]);
        rootF.input.addEventListener('input', invalidate);
        add(shared, rootF.el);

        var urlF = field(t('ui_mount_public_url'), h('input', { type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: 'https://cdn.example.com' }), { help: t(driver.needs_public_url ? 'ui_mount_public_url_needed' : 'ui_mount_public_url_help'), name: 'public_url' });
        urlF.input.value = opts.public_url || '';
        urlF.input.addEventListener('input', invalidate);
        opt('public_url', urlF);
        add(shared, urlF.el);

        var serveSel = h('select');
        ['auto', 'redirect', 'proxy'].forEach(function (v) { add(serveSel, h('option', { value: v, text: t('ui_serve_' + v) })); });
        serveSel.value = opts.serve || 'auto';
        serveSel.addEventListener('change', invalidate);
        var serveF = field(t('ui_mount_serve'), serveSel, { help: t('ui_mount_serve_help'), name: 'serve' });
        opt('serve', serveF);
        add(shared, serveF.el);

        var visC = null;
        if (driver.supports_visibility) {
            visC = checkbox(t('ui_mount_apply_visibility'), !!opts.apply_visibility, t('ui_mount_apply_visibility_help'));
            visC.input.addEventListener('change', invalidate);
            add(shared, visC.el);
        }

        add(box.body, [testMsg, general]);
        refreshVisibility();

        // --- Read the form into the API payload
        function payload() {
            var v = current();
            var config = {};
            (driver.fields || []).forEach(function (def) {
                if (!showWhen(def, v)) { return; }
                var raw = v[def.name];
                if (def.secret && (raw === '' || raw === undefined)) { return; }
                config[def.name] = raw;
            });
            var body = {
                driver: driver.id,
                name: nameF.input.value.trim(),
                config: config,
                options: {
                    root: rootF.input.value.trim(),
                    public_url: urlF.input.value.trim(),
                    serve: serveSel.value,
                    apply_visibility: visC ? visC.input.checked : false
                }
            };
            if (!existing) { body.scope = scopeValue; }
            if (groupsWrap && (scopeValue === 'site')) {
                body.groups = groupBoxes.filter(function (c) { return c.checked; }).map(function (c) { return Number(c.value); });
            }
            return body;
        }

        function save() {
            general.textContent = '';
            return api('POST', state.uuid ? '/mounts/' + encodeURIComponent(state.uuid) : '/mounts', payload()).then(function (r) {
                if (!r || r.ok === false) {
                    var left = applyErrors(fields, r && r.errors);
                    setMsg(general, left.length ? left.join(' ') : errMsg(r), 'error');
                    return false;
                }
                applyErrors(fields, {});
                var saved = r.mount || r;
                if (saved && saved.uuid) {
                    state.uuid = saved.uuid;
                    browseBtn.disabled = false;
                    browseHint.textContent = '';
                }
                return true;
            });
        }

        function testNow() {
            state.busy = true;
            drawButtons();
            setMsg(testMsg, t('ui_testing'));
            // Stateless: the form is probed as it stands and nothing is stored until Save.
            var body = payload();
            if (state.uuid) { body.uuid = state.uuid; }
            general.textContent = '';
            api('POST', '/mounts/test', body).then(function (r) {
                state.busy = false;
                if (r && r.ok === false && r.errors) {
                    var left = applyErrors(fields, r.errors);
                    setMsg(testMsg, left.length ? left.join(' ') : errMsg(r), 'error');
                    state.tested = false;
                } else {
                    applyErrors(fields, {});
                    state.tested = testVerdict(r);
                    setMsg(testMsg, testMessage(r), state.tested ? 'ok' : 'error');
                }
                announce(testMsg.textContent);
                drawButtons();
            });
        }

        function finish() {
            state.busy = true;
            drawButtons();
            save().then(function (saved) {
                state.busy = false;
                if (!saved) { drawButtons(); return; }
                announce(t('ui_mount_saved'));
                box.close();
                loadBoot();
            });
        }

        function drawButtons() {
            clear(box.foot);
            if (isConfig) { add(box.foot, btn(t('ui_close'), { onclick: function () { box.close(); } })); return; }
            add(box.foot, btn(t('ui_cancel'), { onclick: function () { box.close(); } }));
            if (!existing) { add(box.foot, btn(t('ui_back'), { icon: 'arrow-left', onclick: function () { pickDriver(box, function (d) { mountForm(box, d, null); }); } })); }
            add(box.foot, btn(t('ui_test_connection'), { icon: 'plug', disabled: state.busy, primary: !state.tested, onclick: testNow }));
            if (state.tested || existing) {
                add(box.foot, btn(t('ui_save'), { icon: 'check', primary: state.tested, disabled: state.busy, onclick: finish }));
            }
        }

        if (isConfig) {
            Object.keys(inputs).forEach(function (k) { inputs[k].disabled = true; });
            [nameF.input, rootF.input, urlF.input, serveSel].forEach(function (c) { c.disabled = true; });
            if (visC) { visC.input.disabled = true; }
            add(box.body, h('p', { 'class': 'am-alert am-alert-info', text: t('ui_mount_config_note') }));
        }
        drawButtons();
    }

    function mountDialog(existing) {
        var box = openModal({ title: existing ? t('ui_mount_edit_title', { name: existing.name }) : t('ui_mount_add'), sticky: true });
        if (existing) {
            var d = driverById(existing.driver);
            if (!d) { add(box.body, h('p', { 'class': 'am-error-text', text: t('error_driver_unknown') })); return; }
            mountForm(box, d, existing);
        } else {
            pickDriver(box, function (d) { mountForm(box, d, null); });
        }
    }
@endverbatim
