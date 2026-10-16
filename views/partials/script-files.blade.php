@verbatim
    // ------------------------------------------------------------------
    // Files & activity tab
    // ------------------------------------------------------------------

    var runEls = {};
    var runsHost = null;
    var filesHost = null;
    var FILE_STATUSES = ['pending', 'uploading', 'uploaded', 'failed', 'expired'];

    function runView(run) {
        var title = h('span', { 'class': 'am-run-title' });
        var state = h('span', { 'class': 'am-badge' });
        var dir = h('span', { 'class': 'am-help' });
        var cancel = btn(t('ui_cancel_run'), { small: true, danger: true, icon: 'stop' });
        var bar = h('i');
        var barWrap = h('div', { 'class': 'am-progress-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100' }, bar);
        var text = h('span');
        var progress = h('div', { 'class': 'am-progress' }, [barWrap, text]);
        var meta = h('div', { 'class': 'am-run-meta' });
        var el = h('div', { 'class': 'am-run', 'data-run': run.uuid }, [
            h('div', { 'class': 'am-run-head' }, [h('div', { 'class': 'am-row' }, [title, state, dir]), cancel]),
            progress, meta
        ]);
        var entry = { el: el, title: title, state: state, dir: dir, cancel: cancel, bar: bar, barWrap: barWrap, text: text, meta: meta, progress: progress };
        cancel.addEventListener('click', function () {
            cancel.disabled = true;
            api('POST', '/runs/' + encodeURIComponent(entry.uuid) + '/cancel', {}).then(function (r) {
                if (!r || r.ok === false) { cancel.disabled = false; meta.textContent = errMsg(r); return; }
                announce(t('ui_run_cancelling'));
                kick();
            });
        });
        entry.uuid = run.uuid;
        return entry;
    }

    function paintRunEntry(entry, run) {
        var active = isActive(run);
        var pct = percent(run);
        entry.title.textContent = runName(run);
        entry.state.className = 'am-badge is-' + run.status;
        entry.state.textContent = statusLabel(run.status);
        entry.dir.textContent = run.direction === 'down' ? t('ui_run_dir_down') : t('ui_run_dir_up');
        entry.cancel.hidden = !active;
        entry.bar.style.width = pct + '%';
        entry.barWrap.setAttribute('aria-valuenow', String(pct));
        entry.barWrap.setAttribute('aria-label', runName(run));

        var parts = [pct + '%'];
        if (run.bytes_total > 0) { parts.push(bytes(run.bytes_done) + ' / ' + bytes(run.bytes_total)); }
        if (run.files_total > 0) { parts.push(t('ui_run_files', { done: num(run.files_done), total: num(run.files_total) })); }
        if (run.files_failed > 0) { parts.push(t('ui_run_failed_files', { n: num(run.files_failed) })); }
        if (active && run.status === 'uploading') {
            var key = run.uuid;
            var first = S.seen[key] || (S.seen[key] = { t: Date.now(), b: run.bytes_done });
            var started = stamp(run.started_at) || first.t;
            var rate = (Date.now() - started) > 3000 ? run.bytes_done / ((Date.now() - started) / 1000) : 0;
            if (rate > 0 && run.bytes_total > run.bytes_done) {
                parts.push(t('ui_run_rate', { rate: bytes(rate) }));
                parts.push(t('ui_run_eta', { time: duration((run.bytes_total - run.bytes_done) / rate) }));
            }
        }
        entry.text.textContent = parts.join(' · ');
        entry.progress.hidden = !active && run.status === 'cancelled';

        var info = [];
        if (active && run.current_file) { info.push(t('ui_run_current', { file: run.current_file })); }
        if (!active && run.message) { info.push(run.message); }
        if (!active && run.finished_at) { info.push(t('ui_run_finished_ago', { when: ago(run.finished_at) })); }
        if (active && run.status === 'queued') { info.push(t('ui_run_waiting')); }
        entry.meta.textContent = info.join(' · ');
    }

    /** Keyed update: existing rows are changed in place, so a focused Cancel button keeps focus. */
    function paintRuns() {
        if (!runsHost || !document.body.contains(runsHost)) { return; }
        var activeIds = {};
        var list = visibleRuns().concat(S.recent.filter(function (r) {
            return r.direction !== 'size' && !S.runs.some(function (x) { return x.uuid === r.uuid; });
        }).slice(0, 8));
        list.forEach(function (run) { activeIds[run.uuid] = true; });

        Object.keys(runEls).forEach(function (u) {
            if (!activeIds[u]) { if (runEls[u].el.parentNode) { runEls[u].el.parentNode.removeChild(runEls[u].el); } delete runEls[u]; }
        });

        var empty = runsHost.querySelector('.am-empty');
        if (!list.length) {
            if (!empty) { add(runsHost, emptyState('exchange', t('ui_runs_empty_title'), t('ui_runs_empty_text'), btn(t('ui_tab_folders'), { icon: 'folder-open', onclick: function () { navigate('folders'); } }))); }
            return;
        }
        if (empty) { runsHost.removeChild(empty); }

        list.forEach(function (run, i) {
            var entry = runEls[run.uuid];
            if (!entry) { entry = runEls[run.uuid] = runView(run); }
            paintRunEntry(entry, run);
            if (runsHost.children[i] !== entry.el) { runsHost.insertBefore(entry.el, runsHost.children[i] || null); }
        });
    }

    function loadRecent() {
        return api('GET', '/runs').then(function (r) {
            if (!r || r.ok === false) { return; }
            S.recent = (r.runs || r.data || []).map(normRun);
            S.runs = S.recent.filter(isActive).concat(S.runs.filter(function (x) { return !S.recent.some(function (y) { return y.uuid === x.uuid; }); }));
            updateActivity();
            if (S.runs.length) { schedulePoll(); }
        });
    }

    // --- Files table

    function ruleOptions(select, selected) {
        add(select, h('option', { value: '', text: t('ui_files_all_rules') }));
        S.rules.filter(function (r) { return !r.ephemeral; }).forEach(function (r) { add(select, h('option', { value: r.uuid, text: r.name })); });
        select.value = selected || '';
    }

    function selectedIds() {
        return Object.keys(S.files.selected).filter(function (k) { return S.files.selected[k]; }).map(Number);
    }

    function loadFiles() {
        if (!filesHost || !document.body.contains(filesHost)) { return Promise.resolve(); }
        var f = S.files;
        return api('GET', '/files', null, { rule: f.rule, status: f.status, q: f.q, page: f.page }).then(function (r) {
            if (!r || r.ok === false) {
                clear(filesHost);
                add(filesHost, h('div', { 'class': 'am-alert am-alert-error', role: 'alert', text: errMsg(r) }));
                return;
            }
            var page = r.files && !Array.isArray(r.files) ? r.files : r;
            f.data = {
                rows: page.data || (Array.isArray(r.files) ? r.files : []),
                page: page.page || 1, pages: page.pages || 1, total: page.total || 0
            };
            paintFiles();
        });
    }

    function backupLike(row) {
        return /\.(sql|sql\.gz)$/i.test(row.local_path || '') || /(^|\/)(backup|snapshot)/i.test(row.local_path || '');
    }

    function paintFiles() {
        var f = S.files;
        var data = f.data;
        clear(filesHost);
        if (!data) { return; }

        if (!data.rows.length) {
            var filtered = f.rule || f.status || f.q;
            add(filesHost, emptyState('files-o', filtered ? t('ui_files_empty_filtered_title') : t('ui_files_empty_title'),
                filtered ? t('ui_files_empty_filtered_text') : t('ui_files_empty_text'),
                filtered ? null : btn(t('ui_tab_folders'), { icon: 'folder-open', onclick: function () { navigate('folders'); } })));
            return;
        }

        var all = h('input', { type: 'checkbox', 'aria-label': t('ui_files_select_all') });
        var allChecked = data.rows.every(function (r) { return f.selected[r.id]; });
        all.checked = allChecked;
        all.addEventListener('change', function () {
            data.rows.forEach(function (r) { f.selected[r.id] = all.checked; });
            paintFiles();
        });

        var bulk = btn(t('ui_files_recall_selected', { n: selectedIds().length }), {
            icon: 'download', small: true, disabled: !selectedIds().length,
            onclick: function () { recallDialog(data.rows.filter(function (r) { return f.selected[r.id]; })); }
        });

        var body = h('tbody', null, data.rows.map(function (row) {
            var check = h('input', { type: 'checkbox', 'aria-label': t('ui_files_select', { file: row.local_path }) });
            check.checked = !!f.selected[row.id];
            check.addEventListener('change', function () { f.selected[row.id] = check.checked; paintFiles(); });
            var canGet = row.status === 'uploaded';
            var rowMsg = h('span', { 'class': 'am-msg', role: 'status' });
            var kinds = [badge(statusLabel(row.status), row.status)];
            if (row.visibility === 'public') { kinds.push(badge(t('ui_visibility_public'), 'warn')); }
            if (row.local_removed) { kinds.push(badge(t('ui_files_local_removed'))); }
            return h('tr', null, [
                h('td', null, check),
                h('td', { 'class': 'am-wrap' }, [
                    row.url ? h('a', { href: row.url, target: '_blank', rel: 'noopener', text: '/' + row.local_path }) : '/' + row.local_path,
                    row.error ? h('div', { 'class': 'am-error-text', text: row.error }) : null,
                    row.rule_name ? h('div', { 'class': 'am-help', text: row.rule_name }) : null
                ]),
                h('td', null, h('div', { 'class': 'am-card-badges' }, kinds)),
                h('td', { 'class': 'am-num', text: row.size_human || bytes(row.size) }),
                h('td', { 'class': 'am-num', title: when(row.uploaded_at), text: row.uploaded_at ? ago(row.uploaded_at) : '—' }),
                h('td', null, h('div', { 'class': 'am-actions' }, [
                    canGet ? btn(t('ui_files_recall'), { small: true, icon: 'download', onclick: function () { recallDialog([row]); } }) : null,
                    canGet && row.visibility !== 'public' ? h('a', { 'class': 'am-btn am-btn-small', href: BASE + '/files/' + row.id + '/download', target: '_blank', rel: 'noopener' }, [icon('cloud-download'), h('span', { text: t('ui_files_download') })]) : null,
                    row.status !== 'pending' ? btn(t('ui_files_delete'), { small: true, danger: true, icon: 'trash', onclick: function () { deleteEntry(row, rowMsg); } }) : null,
                    rowMsg
                ]))
            ]);
        }));

        add(filesHost, [
            h('div', { 'class': 'am-toolbar' }, [bulk]),
            h('div', { 'class': 'am-table-wrap' }, h('table', { 'class': 'am-table' }, [
                h('caption', { 'class': 'am-sr', text: t('ui_files_caption') }),
                h('thead', null, h('tr', null, [
                    h('th', null, all), h('th', { text: t('ui_files_col_file') }), h('th', { text: t('ui_files_col_status') }),
                    h('th', { 'class': 'am-num', text: t('ui_files_col_size') }), h('th', { 'class': 'am-num', text: t('ui_files_col_uploaded') }), h('th')
                ])),
                body
            ])),
            h('div', { 'class': 'am-pager' }, [
                h('span', { text: t('ui_files_page', { page: data.page, pages: data.pages, total: num(data.total) }) }),
                h('span', { 'class': 'am-row' }, [
                    btn(t('ui_prev'), { small: true, icon: 'chevron-left', disabled: data.page <= 1, onclick: function () { f.page = data.page - 1; loadFiles(); } }),
                    btn(t('ui_next'), { small: true, icon: 'chevron-right', disabled: data.page >= data.pages, onclick: function () { f.page = data.page + 1; loadFiles(); } })
                ])
            ])
        ]);
    }

    function deleteEntry(row, msg) {
        confirmBox({
            title: t('ui_files_delete_title'),
            message: t('ui_files_delete_confirm', { file: '/' + row.local_path }),
            warning: row.local_removed ? t('ui_files_delete_last_copy') : null,
            confirm: t('ui_files_delete'),
            danger: true
        }).then(function (c) {
            if (!c.ok) { return; }
            api('POST', '/files/' + row.id + '/delete', {}).then(function (r) {
                if (!r || r.ok === false) { setMsg(msg, errMsg(r), 'error'); return; }
                announce(t('ui_files_deleted'));
                delete S.files.selected[row.id];
                loadFiles();
                loadBoot(true);
            });
        });
    }

    // --- Download back

    function managerBackupUrl() {
        return '/manager/index.php?a=93';
    }

    function recallDialog(rows) {
        if (!rows.length) { return; }
        var box = openModal({ title: t('ui_recall_title'), narrow: true, sticky: true });
        var msg = h('p', { 'class': 'am-msg', role: 'alert' });
        var names = rows.length === 1 ? '/' + rows[0].local_path : t('ui_recall_many', { n: rows.length });
        add(box.body, h('p', { text: t('ui_recall_intro', { what: names }) }));

        var place = 'original';
        var radioWrap = h('div', { role: 'radiogroup', 'aria-label': t('ui_recall_to') });
        var folderInput = h('input', { type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: 'assets/restored' });
        var folderF = field(t('ui_recall_folder'), folderInput, { help: t('ui_rule_local_scope_help'), name: 'to' });
        folderF.el.hidden = true;
        var pickerHost = h('div');
        add(folderF.el, [btn(t('ui_browse'), { small: true, icon: 'folder-open', onclick: function () {
            if (pickerHost.firstChild) { clear(pickerHost); return; }
            add(pickerHost, folderPicker({ start: folderInput.value.trim(), rootLabel: t('ui_picker_site_root'), load: localLoader, onPick: function (p) { folderInput.value = p; clear(pickerHost); } }));
        } }), pickerHost]);
        ['original', 'other'].forEach(function (v) {
            var id = 'am-recall-' + v;
            var r = h('input', { type: 'radio', name: 'am-recall', id: id, value: v });
            r.checked = v === place;
            r.addEventListener('change', function () { place = v; folderF.el.hidden = v !== 'other'; });
            add(radioWrap, h('div', { 'class': 'am-check' }, [r, h('label', { 'for': id }, [t('ui_recall_' + v), h('span', { 'class': 'am-help', text: t('ui_recall_' + v + '_help') })])]));
        });
        add(box.body, [h('div', { 'class': 'am-field' }, [h('span', { 'class': 'am-label', text: t('ui_recall_to') }), radioWrap]), folderF.el]);

        var overwrite = checkbox(t('ui_recall_overwrite'), false, t('ui_recall_overwrite_help'));
        var removeRemote = checkbox(t('ui_recall_delete_remote'), false, t('ui_recall_delete_remote_help'));
        add(box.body, [overwrite.el, removeRemote.el]);

        if (rows.some(backupLike)) {
            add(box.body, h('div', { 'class': 'am-alert am-alert-info' }, [
                h('span', { text: t('ui_recall_backup_hint') }),
                h('a', { href: managerBackupUrl(), target: '_top', text: t('ui_recall_backup_link') })
            ]));
        }
        add(box.body, msg);

        add(box.foot, [
            btn(t('ui_cancel'), { onclick: function () { box.close(); } }),
            btn(t('ui_recall_start'), { primary: true, icon: 'download', onclick: function (b) {
                b.disabled = true;
                var to = place === 'other' ? folderInput.value.trim().replace(/^\/+|\/+$/g, '') : null;
                if (place === 'other' && !to) { b.disabled = false; showFieldError(folderF, t('error_field_required')); return; }
                api('POST', '/files/recall', { ids: rows.map(function (r) { return r.id; }), to: to, overwrite: overwrite.input.checked, delete_remote: removeRemote.input.checked }).then(function (r) {
                    b.disabled = false;
                    if (!r || r.ok === false) {
                        var left = applyErrors({ to: folderF }, r && r.errors);
                        setMsg(msg, left.length ? left.join(' ') : errMsg(r), 'error');
                        return;
                    }
                    announce(t('ui_recall_started'));
                    S.files.selected = {};
                    box.close();
                    kick().then(function () { loadFiles(); });
                } );
            } })
        ]);
    }

    // --- Tab

    R.files = function () {
        var panel = clear($('am-panel-files'));
        runsHost = h('div', { 'aria-label': t('ui_runs_title') });
        runEls = {};
        add(panel, h('section', { 'class': 'am-panel' }, [h('h2', { text: t('ui_runs_title') }), runsHost]));

        var ruleSel = h('select');
        ruleOptions(ruleSel, S.files.rule);
        var statusSel = h('select');
        add(statusSel, h('option', { value: '', text: t('ui_files_all_statuses') }));
        FILE_STATUSES.forEach(function (s) { add(statusSel, h('option', { value: s, text: statusLabel(s) })); });
        statusSel.value = S.files.status;
        var search = h('input', { type: 'search', placeholder: t('ui_files_search'), 'aria-label': t('ui_files_search') });
        search.value = S.files.q;

        function apply() {
            S.files.rule = ruleSel.value;
            S.files.status = statusSel.value;
            S.files.q = search.value.trim();
            S.files.page = 1;
            S.files.selected = {};
            // replaceState does not fire hashchange, so the page is not rebuilt under the focused filter.
            history.replaceState(null, '', '#files' + (S.files.rule ? '?rule=' + encodeURIComponent(S.files.rule) : ''));
            loadFiles();
        }
        ruleSel.addEventListener('change', apply);
        statusSel.addEventListener('change', apply);
        search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });

        filesHost = h('div', { 'aria-live': 'polite' });
        add(panel, h('section', { 'class': 'am-panel' }, [
            h('h2', { text: t('ui_files_title') }),
            h('div', { 'class': 'am-toolbar' }, [
                h('label', { 'class': 'am-sr', 'for': 'am-filter-rule', text: t('ui_files_filter_rule') }),
                h('div', { 'class': 'am-grow' }, ruleSel),
                h('label', { 'class': 'am-sr', 'for': 'am-filter-status', text: t('ui_files_filter_status') }),
                statusSel,
                h('div', { 'class': 'am-grow' }, search),
                btn(t('ui_files_search_button'), { icon: 'search', onclick: apply })
            ]),
            filesHost
        ]));
        ruleSel.id = 'am-filter-rule';
        statusSel.id = 'am-filter-status';

        paintRuns();
        loadRecent().then(paintRuns);
        loadFiles();
    };
@endverbatim
