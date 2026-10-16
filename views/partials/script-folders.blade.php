@verbatim
    // ------------------------------------------------------------------
    // Folders tab (rules)
    // ------------------------------------------------------------------

    var INTERVALS = [0, 15, 60, 360, 1440];

    function ruleCard(r) {
        var msg = h('p', { 'class': 'am-msg', role: 'status' });
        var last = r.last_run;
        var running = S.runs.some(function (x) { return x.direction !== 'size' && x.rule === r.uuid; });
        var card = h('article', { 'class': 'am-card', 'aria-label': r.name }, [
            h('div', { 'class': 'am-card-head' }, [
                h('h3', { 'class': 'am-card-title', text: r.name }),
                h('div', { 'class': 'am-card-badges' }, [
                    badge(r.enabled ? t('ui_rule_enabled') : t('ui_rule_disabled'), r.enabled ? 'ok' : 'warn'),
                    badge(r.mode === 'move' ? t('ui_mode_move') : t('ui_mode_copy')),
                    badge(r.visibility === 'public' ? t('ui_visibility_public') : t('ui_visibility_private'), r.visibility === 'public' ? 'warn' : '')
                ])
            ]),
            h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_rule_local') }), h('span', { text: '/' + r.local_path })]),
            h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_rule_remote') }), h('span', { text: (r.mount_name || '?') + ' : /' + (r.remote_prefix || '') })]),
            h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_rule_schedule') }), h('span', { text: intervalLabel(r.interval_minutes) + (r.next_run_at && r.interval_minutes ? ' · ' + t('ui_rule_next', { when: ago(r.next_run_at) }) : '') })]),
            h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_rule_last_run') }), h('span', null, last ? [
                badge(statusLabel(last.status), last.status),
                ' ', r.last_run_at ? ago(r.last_run_at) : ''
            ] : t('ui_never'))]),
            last && last.message && last.status === 'failed' ? h('p', { 'class': 'am-error-text', text: last.message }) : null,
            usageBlock('rule', r.uuid),
            msg,
            h('div', { 'class': 'am-card-actions' }, [
                btn(t('ui_rule_run'), { icon: 'play', small: true, primary: true, disabled: running, onclick: function (b) {
                    b.disabled = true;
                    api('POST', '/rules/' + encodeURIComponent(r.uuid) + '/run', {}).then(function (res) {
                        if (!res || res.ok === false) { b.disabled = false; setMsg(msg, errMsg(res), 'error'); return; }
                        setMsg(msg, t('ui_rule_started'), 'ok');
                        announce(t('ui_rule_started'));
                        kick();
                    });
                } }),
                btn(t('ui_rule_files'), { icon: 'list', small: true, onclick: function () { navigate('files', { rule: r.uuid }); } }),
                btn(t('ui_edit'), { icon: 'pencil', small: true, onclick: function () { ruleDialog(r); } }),
                btn(t('ui_delete'), { icon: 'trash', small: true, danger: true, onclick: function () {
                    confirmBox({ title: t('ui_rule_delete_title'), message: t('ui_rule_delete_confirm', { name: r.name }), confirm: t('ui_delete'), danger: true }).then(function (c) {
                        if (!c.ok) { return; }
                        api('POST', '/rules/' + encodeURIComponent(r.uuid) + '/delete', {}).then(function (res) {
                            if (!res || res.ok === false) { setMsg(msg, errMsg(res), 'error'); return; }
                            announce(t('ui_rule_deleted'));
                            loadBoot();
                        });
                    });
                } })
            ])
        ]);
        return card;
    }

    function usableMounts(keep) {
        return S.mounts.filter(function (m) {
            return m.uuid === keep || (m.status !== 'error' && !m.credentials_unreadable);
        });
    }

    R.folders = function () {
        var panel = clear($('am-panel-folders'));
        var mounts = usableMounts();
        add(panel, h('div', { 'class': 'am-toolbar' }, [
            h('div', { 'class': 'am-grow' }, h('p', { 'class': 'am-help', text: t('ui_folders_intro') })),
            btn(t('ui_rule_add'), { primary: true, icon: 'plus', disabled: !mounts.length, onclick: function () { ruleDialog(null); } })
        ]));
        if (!mounts.length) {
            add(panel, emptyState('cloud', t('ui_folders_need_mount_title'), t('ui_folders_need_mount_text'),
                btn(t('ui_mount_add'), { primary: true, icon: 'plus', onclick: function () { navigate('mounts'); } })));
            return;
        }
        var visible = S.rules.filter(function (r) { return !r.ephemeral; });
        if (!visible.length) {
            add(panel, emptyState('folder-open', t('ui_folders_empty_title'), t('ui_folders_empty_text'),
                btn(t('ui_rule_add'), { primary: true, icon: 'plus', onclick: function () { ruleDialog(null); } })));
            return;
        }
        add(panel, h('div', { 'class': 'am-cards' }, visible.map(ruleCard)));
    };

    // ------------------------------------------------------------------
    // Rule dialog
    // ------------------------------------------------------------------

    function localLoader(path) {
        return api('GET', '/local/folders', null, { path: path });
    }

    function ruleDialog(existing) {
        var box = openModal({ title: existing ? t('ui_rule_edit_title', { name: existing.name }) : t('ui_rule_add'), sticky: true });
        var fields = {};
        var general = h('p', { 'class': 'am-msg', role: 'alert' });

        // --- Presets row (new rules only)
        var applyPreset = null;
        if (!existing && S.presets.length) {
            add(box.body, h('div', { 'class': 'am-field' }, [
                h('span', { 'class': 'am-label', text: t('ui_rule_presets') }),
                h('div', { 'class': 'am-row' }, S.presets.map(function (p) {
                    return btn(tr(p.name || p.key), { small: true, icon: 'magic', onclick: function () { applyPreset(p); } });
                })),
                h('p', { 'class': 'am-help', text: t('ui_rule_presets_help') })
            ]));
        }

        // --- Name
        var nameF = field(t('ui_rule_name'), h('input', { type: 'text', maxlength: '120', autocomplete: 'off' }), { help: t('ui_rule_name_help'), name: 'name' });
        nameF.input.value = existing ? existing.name : '';
        fields.name = nameF;
        add(box.body, nameF.el);

        // --- Local folder with picker and a check that explains refusals
        var localInput = h('input', { type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: 'assets/images' });
        localInput.value = existing ? existing.local_path : '';
        var localF = field(t('ui_rule_local_folder'), localInput, { required: true, name: 'local_path' });
        var localStatus = h('p', { 'class': 'am-msg', role: 'status' });
        var localHost = h('div');
        var checkTimer = null;
        var checkSeq = 0;
        function checkLocal() {
            var v = localInput.value.trim();
            setMsg(localStatus, '');
            if (!v) { return; }
            var seq = ++checkSeq;
            api('GET', '/local/check', null, { path: v }).then(function (r) {
                if (seq !== checkSeq) { return; }
                if (!r || r.ok === false) { setMsg(localStatus, errMsg(r), 'error'); return; }
                if (!r.offloadable) { setMsg(localStatus, reasonText(r.reason), 'error'); return; }
                if (visR && visR.value() === 'public' && !r.publishable) { setMsg(localStatus, t('ui_rule_local_not_publishable'), 'error'); return; }
                setMsg(localStatus, t('ui_rule_local_ok'), 'ok');
            });
        }
        localInput.addEventListener('input', function () { window.clearTimeout(checkTimer); checkTimer = window.setTimeout(checkLocal, 500); });
        localInput.addEventListener('change', checkLocal);
        add(localF.el, [
            h('div', { 'class': 'am-row' }, [localInput, btn(t('ui_browse'), { icon: 'folder-open', small: true, onclick: function () {
                if (localHost.firstChild) { clear(localHost); return; }
                add(localHost, folderPicker({
                    start: localInput.value.trim().replace(/\/[^/]*$/, ''),
                    rootLabel: t('ui_picker_site_root'),
                    load: localLoader,
                    onPick: function (p) { localInput.value = p; clear(localHost); checkLocal(); }
                }));
            } })]),
            h('p', { 'class': 'am-help', text: t('ui_rule_local_scope_help') }),
            localHost, localStatus
        ]);
        fields.local_path = localF;
        add(box.body, localF.el);

        // --- Mount and remote folder
        var mountSel = h('select');
        var mounts = usableMounts(existing ? existing.mount : null);
        mounts.forEach(function (m) { add(mountSel, h('option', { value: m.uuid, text: m.name + ' (' + (m.driver_label || m.driver) + ')' })); });
        if (existing) { mountSel.value = existing.mount; }
        var mountF = field(t('ui_rule_mount'), mountSel, { required: true, name: 'mount' });
        fields.mount = mountF;
        add(box.body, mountF.el);

        var remoteInput = h('input', { type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: 'backups' });
        remoteInput.value = existing ? existing.remote_prefix || '' : '';
        var remoteF = field(t('ui_rule_remote_folder'), remoteInput, { help: t('ui_rule_remote_help'), name: 'remote_prefix' });
        var remoteHost = h('div');
        add(remoteF.el, [h('div', { 'class': 'am-row' }, [btn(t('ui_browse'), { icon: 'folder-open', small: true, onclick: function () {
            if (remoteHost.firstChild) { clear(remoteHost); return; }
            add(remoteHost, folderPicker({
                start: remoteInput.value.trim(),
                rootLabel: t('ui_picker_store_root'),
                load: function (p) { return api('GET', '/mounts/' + encodeURIComponent(mountSel.value) + '/browse', null, { path: p }); },
                onPick: function (p) { remoteInput.value = p; clear(remoteHost); }
            }));
        } })]), remoteHost]);
        mountSel.addEventListener('change', function () { clear(remoteHost); });
        fields.remote_prefix = remoteF;
        add(box.body, remoteF.el);

        // --- Mode and visibility
        function radios(name, values, current, labelKey, helpKey) {
            var wrap = h('div', { role: 'radiogroup', 'aria-label': t(labelKey) });
            var inputs = {};
            values.forEach(function (v) {
                var id = 'am-' + name + '-' + v;
                var r = h('input', { type: 'radio', name: 'am-' + name, id: id, value: v });
                r.checked = v === current;
                inputs[v] = r;
                add(wrap, h('div', { 'class': 'am-check' }, [r, h('label', { 'for': id }, [t('ui_' + name + '_' + v), h('span', { 'class': 'am-help', text: t(helpKey + '_' + v) })])]));
            });
            return { el: h('div', { 'class': 'am-field' }, [h('span', { 'class': 'am-label', text: t(labelKey) }), wrap]), inputs: inputs, value: function () { return values.filter(function (v) { return inputs[v].checked; })[0]; }, set: function (v) { if (inputs[v]) { inputs[v].checked = true; } } };
        }
        var modeR = radios('mode', ['copy', 'move'], existing ? existing.mode : 'copy', 'ui_rule_mode', 'ui_rule_mode_help');
        var visR = radios('visibility', ['private', 'public'], existing ? existing.visibility : 'private', 'ui_rule_visibility', 'ui_rule_visibility_help');
        var modeWarn = h('p', { 'class': 'am-alert am-alert-warn', text: t('ui_rule_move_warning'), hidden: true });
        var pubInfo = h('p', { 'class': 'am-alert am-alert-info', text: t('ui_rule_public_info'), hidden: true });
        function syncWarnings() {
            modeWarn.hidden = modeR.value() !== 'move';
            pubInfo.hidden = visR.value() !== 'public';
        }
        Object.keys(modeR.inputs).forEach(function (k) { modeR.inputs[k].addEventListener('change', syncWarnings); });
        Object.keys(visR.inputs).forEach(function (k) { visR.inputs[k].addEventListener('change', function () { syncWarnings(); checkLocal(); }); });
        fields.mode = { error: h('p', { 'class': 'am-field-error', role: 'alert', hidden: true }), input: null };
        fields.visibility = { error: h('p', { 'class': 'am-field-error', role: 'alert', hidden: true }), input: null };
        add(box.body, [modeR.el, fields.mode.error, modeWarn, visR.el, fields.visibility.error, pubInfo]);

        // --- Filters
        var filters = h('fieldset', null, h('legend', { text: t('ui_rule_filters') }));
        var recursive = checkbox(t('ui_rule_recursive'), existing ? existing.recursive : true);
        var includeF = field(t('ui_rule_include'), h('textarea', { rows: '2', spellcheck: 'false', placeholder: '*.sql.gz' }), { help: t('ui_rule_globs_help'), name: 'include' });
        includeF.input.value = existing ? (existing.include || []).join('\n') : '';
        var excludeF = field(t('ui_rule_exclude'), h('textarea', { rows: '2', spellcheck: 'false', placeholder: '*.tmp' }), { name: 'exclude' });
        excludeF.input.value = existing ? (existing.exclude || []).join('\n') : '';
        var ageInput = h('input', { type: 'number', min: '0', step: '1' });
        ageInput.value = existing ? String(Math.round((existing.min_age || 0) / 60)) : '1';
        var ageF = field(t('ui_rule_min_age'), ageInput, { help: t('ui_rule_min_age_help'), name: 'min_age' });
        var minInput = h('input', { type: 'number', min: '0', step: 'any' });
        minInput.value = existing ? String(sizeParts(existing.min_size || 0)) : '0';
        var minF = field(t('ui_rule_min_size'), minInput, { help: t('ui_rule_size_help'), name: 'min_size' });
        var maxInput = h('input', { type: 'number', min: '0', step: 'any' });
        maxInput.value = existing ? String(sizeParts(existing.max_size || 0)) : '0';
        var maxF = field(t('ui_rule_max_size'), maxInput, { name: 'max_size' });
        fields.include = includeF; fields.exclude = excludeF; fields.min_age = ageF; fields.min_size = minF; fields.max_size = maxF;
        add(filters, [recursive.el, includeF.el, excludeF.el, h('div', { 'class': 'am-grid2' }, [ageF.el, minF.el, maxF.el])]);
        add(box.body, filters);

        // --- Schedule, retention, mirror
        var life = h('fieldset', null, h('legend', { text: t('ui_rule_lifecycle') }));
        var schedSel = h('select');
        var current = existing ? Number(existing.interval_minutes) || 0 : 0;
        var options = INTERVALS.slice();
        if (options.indexOf(current) < 0) { options.push(current); options.sort(function (a, b) { return a - b; }); }
        options.forEach(function (m) { add(schedSel, h('option', { value: String(m), text: intervalLabel(m) })); });
        schedSel.value = String(current);
        var schedF = field(t('ui_rule_schedule'), schedSel, { help: t('ui_rule_schedule_help'), name: 'interval_minutes' });
        var retInput = h('input', { type: 'number', min: '0', step: '1' });
        retInput.value = existing ? String(existing.retention_days || 0) : '0';
        var retF = field(t('ui_rule_retention'), retInput, { help: t('ui_rule_retention_help'), name: 'retention_days' });
        var mirror = checkbox(t('ui_rule_mirror'), existing ? existing.mirror_deletes : false, t('ui_rule_mirror_help'));
        var mirrorWarn = h('p', { 'class': 'am-alert am-alert-warn', text: t('ui_rule_mirror_warning'), hidden: !mirror.input.checked });
        mirror.input.addEventListener('change', function () { mirrorWarn.hidden = !mirror.input.checked; });
        var enabled = checkbox(t('ui_rule_enabled_label'), existing ? existing.enabled : true);
        fields.interval_minutes = schedF; fields.retention_days = retF;
        add(life, [h('div', { 'class': 'am-grid2' }, [schedF.el, retF.el]), mirror.el, mirrorWarn, enabled.el]);
        add(box.body, life);
        add(box.body, general);
        syncWarnings();

        applyPreset = function (p) {
            if (p.name) { nameF.input.value = tr(p.name); }
            if (p.local_path) { localInput.value = p.local_path; checkLocal(); }
            if (p.mode) { modeR.set(p.mode); }
            if (p.visibility) { visR.set(p.visibility); }
            if (p.interval_minutes !== undefined) {
                var m = Number(p.interval_minutes) || 0;
                if (!Array.prototype.some.call(schedSel.options, function (o) { return o.value === String(m); })) { add(schedSel, h('option', { value: String(m), text: intervalLabel(m) })); }
                schedSel.value = String(m);
            }
            if (p.include) { includeF.input.value = p.include.join('\n'); }
            syncWarnings();
            announce(t('ui_rule_preset_applied', { name: tr(p.name || p.key) }));
        };

        function submit(button) {
            button.disabled = true;
            var body = {
                name: nameF.input.value.trim(),
                mount: mountSel.value,
                local_path: localInput.value.trim().replace(/^\/+|\/+$/g, ''),
                remote_prefix: remoteInput.value.trim().replace(/^\/+|\/+$/g, ''),
                mode: modeR.value(),
                visibility: visR.value(),
                recursive: recursive.input.checked,
                include: lines(includeF.input.value),
                exclude: lines(excludeF.input.value),
                min_age: Math.round((Number(ageInput.value) || 0) * 60),
                min_size: Math.round((Number(minInput.value) || 0) * 1048576),
                max_size: Math.round((Number(maxInput.value) || 0) * 1048576),
                interval_minutes: Number(schedSel.value) || 0,
                retention_days: Number(retInput.value) || 0,
                mirror_deletes: mirror.input.checked,
                enabled: enabled.input.checked
            };
            api('POST', existing ? '/rules/' + encodeURIComponent(existing.uuid) : '/rules', body).then(function (r) {
                button.disabled = false;
                if (!r || r.ok === false) {
                    var left = applyErrors(fields, r && r.errors);
                    setMsg(general, left.length ? left.join(' ') : errMsg(r), 'error');
                    return;
                }
                announce(t('ui_rule_saved'));
                box.close();
                loadBoot().then(function () { if (S.flags.may_manage && body.visibility === 'public') { api('GET', '/server').then(function (s) { if (s && s.ok !== false) { S.server = s.status || s.server || S.server; } }); } });
            });
        }

        add(box.foot, [
            btn(t('ui_cancel'), { onclick: function () { box.close(); } }),
            btn(t('ui_save'), { primary: true, icon: 'check', onclick: submit })
        ]);
    }
@endverbatim
