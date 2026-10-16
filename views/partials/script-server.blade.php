@verbatim
    // ------------------------------------------------------------------
    // Server tab (managers only)
    // ------------------------------------------------------------------

    var SERVERS = ['nginx', 'apache', 'frankenphp'];
    var serverState = { snippets: {}, selected: null, probe: null };

    function probeExplanation(res) {
        var status = Number(res.status) || 0;
        if (res.ok) { return ''; }
        if (status === 0) { return t('ui_probe_why_0'); }
        if (status === 404) { return t('ui_probe_why_404'); }
        if (status === 403) { return t('ui_probe_why_403'); }
        if (status >= 500) { return t('ui_probe_why_5xx'); }
        if (status >= 300 && status < 400) { return t('ui_probe_why_3xx'); }
        return t('ui_probe_why_other');
    }

    function serverLabel(id) {
        var key = 'ui_server_' + id;
        return Object.prototype.hasOwnProperty.call(L, key) ? t(key) : id;
    }

    function paintServer(host) {
        clear(host);
        var st = S.server;
        if (!st) { add(host, h('p', { 'class': 'am-help', text: t('ui_loading') })); return; }
        var detected = st.server || 'unknown';
        if (!serverState.selected) { serverState.selected = SERVERS.indexOf(detected) >= 0 ? detected : 'nginx'; }
        var dirs = st.directories || [];

        add(host, h('p', { 'class': 'am-help', text: t('ui_server_guidance') }));

        if (st.drift) {
            add(host, h('div', { 'class': 'am-alert am-alert-warn', role: 'alert' }, [
                h('strong', { text: t('ui_server_drift_title') }),
                h('span', { text: t('ui_server_drift_text') })
            ]));
        }

        add(host, h('div', { 'class': 'am-panel' }, [
            h('h2', { text: t('ui_server_detected') }),
            h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_server_detected') }), h('span', null, badge(serverLabel(detected), st.supported ? 'ok' : 'warn'))]),
            h('div', { 'class': 'am-kv' }, [h('span', { text: t('ui_server_dirs') }), h('span', null, dirs.length ? dirs.map(function (d) { return [h('code', { 'class': 'am-code-inline', text: d + '/' }), ' ']; }) : t('ui_server_dirs_none'))]),
            !dirs.length ? h('p', { 'class': 'am-help', text: t('ui_server_dirs_none_help') }) : null,
            st.instructions ? h('p', { text: st.instructions }) : null
        ]));

        // --- Snippet per server
        var picker = h('select', { id: 'am-server-pick' });
        SERVERS.forEach(function (id) { add(picker, h('option', { value: id, text: serverLabel(id) })); });
        picker.value = serverState.selected;
        var codeHost = h('div');
        function paintCode() {
            clear(codeHost);
            var code = serverState.snippets[serverState.selected];
            if (typeof code === 'string' && code !== '') {
                add(codeHost, h('div', { 'class': 'am-code' }, [h('pre', { text: code }), btn(t('ui_copy'), { small: true, icon: 'files-o', onclick: function (b) { copyText(code, b); } })]));
            } else {
                add(codeHost, h('p', { 'class': 'am-help', text: serverState.selected === 'frankenphp' ? t('ui_server_nothing_to_change') : t('ui_server_no_snippet') }));
            }
        }
        picker.addEventListener('change', function () { serverState.selected = picker.value; paintCode(); paintWriteState(); });

        var writeMsg = h('p', { 'class': 'am-msg', role: 'status' });
        var probeMsg = h('p', { 'class': 'am-msg', role: 'status' });
        var probeList = h('ul', { 'class': 'am-probe' });
        var writeBtn = btn(t('ui_server_write'), { primary: true, icon: 'save', onclick: function (b) {
            b.disabled = true;
            setMsg(writeMsg, t('ui_working'));
            api('POST', '/server/write', { server: serverState.selected }).then(function (r) {
                b.disabled = false;
                if (!r || r.ok === false) { setMsg(writeMsg, errMsg(r), 'error'); return; }
                var res = r.result || r;
                setMsg(writeMsg, (res.changed === false ? t('ui_server_unchanged') : t('ui_server_written')) + (res.path ? ' ' + res.path : ''), 'ok');
                announce(writeMsg.textContent);
                loadServer();
            });
        } });
        var writeNote = h('p', { 'class': 'am-help' });
        function paintWriteState() {
            var needs = serverState.selected !== 'frankenphp';
            var same = serverState.selected === detected;
            writeBtn.disabled = !needs || !st.supported;
            writeNote.textContent = !needs ? t('ui_server_nothing_to_change') : (same && st.include_path ? t('ui_server_target', { path: st.include_path }) + (st.writable === false ? ' ' + t('ui_server_not_writable') : '') : t('ui_server_other_target'));
        }

        add(host, h('div', { 'class': 'am-panel' }, [
            h('h2', { text: t('ui_server_snippet') }),
            h('div', { 'class': 'am-field' }, [h('label', { 'for': 'am-server-pick', text: t('ui_server_choose') }), picker]),
            codeHost,
            h('div', { 'class': 'am-toolbar' }, [writeBtn]),
            writeNote, writeMsg
        ]));
        paintCode();
        paintWriteState();

        // --- Probe
        function paintProbe() {
            clear(probeList);
            (serverState.probe || []).forEach(function (res) {
                var why = probeExplanation(res);
                add(probeList, h('li', null, [
                    icon(res.ok ? 'check-circle' : 'times-circle'),
                    h('div', null, [
                        h('strong', { text: '/' + res.dir + '/ ' }),
                        h('span', { text: res.ok ? t('ui_probe_ok') : t('ui_probe_failed') }),
                        h('div', { 'class': 'am-help', text: res.message || '' }),
                        why ? h('div', { 'class': 'am-help', text: why }) : null
                    ])
                ]));
            });
        }
        var checkBtn = btn(t('ui_server_check'), { icon: 'stethoscope', disabled: !dirs.length, onclick: function (b) {
            b.disabled = true;
            setMsg(probeMsg, t('ui_working'));
            api('POST', '/server/probe', {}).then(function (r) {
                b.disabled = !dirs.length;
                if (!r || r.ok === false) { setMsg(probeMsg, errMsg(r), 'error'); return; }
                serverState.probe = r.results || r.probes || r.directories || [];
                var failed = serverState.probe.filter(function (x) { return !x.ok; }).length;
                setMsg(probeMsg, failed ? t('ui_probe_summary_failed', { n: failed }) : t('ui_probe_summary_ok'), failed ? 'error' : 'ok');
                announce(probeMsg.textContent);
                paintProbe();
            });
        } });
        add(host, h('div', { 'class': 'am-panel' }, [
            h('h2', { text: t('ui_server_check') }),
            h('p', { 'class': 'am-help', text: t('ui_server_check_help') }),
            h('div', { 'class': 'am-toolbar' }, [checkBtn]),
            probeMsg, probeList
        ]));
        paintProbe();
    }

    function loadServer() {
        var host = $('am-panel-server');
        return api('GET', '/server').then(function (r) {
            if (!r || r.ok === false) { clear(host); add(host, h('div', { 'class': 'am-alert am-alert-error', role: 'alert', text: errMsg(r) })); return; }
            S.server = r.status || r.server || S.server;
            serverState.snippets = r.snippets || {};
            if (!modalStack.length) { paintServer(host); }
        });
    }

    R.server = function () {
        var host = clear($('am-panel-server'));
        paintServer(host);
        loadServer();
    };
@endverbatim
