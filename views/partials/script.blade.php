{{--
    The aMount front end.

    Deliberately dependency-free and deliberately dumb: the browser holds no
    state that matters. Runs live in the database and are advanced by a queue
    worker, so this page is a viewer that polls while something is active —
    closing it, reloading it or opening it elsewhere shows the same thing.
    All text goes in as text nodes (see `h()` in the core), never as HTML.

    One script, one scope: the sections are partials so each stays readable.
--}}
<script>
(function () {
    'use strict';

@include('aMount::partials.script-core')
@include('aMount::partials.script-mounts')
@include('aMount::partials.script-folders')
@include('aMount::partials.script-files')
@include('aMount::partials.script-server')

@verbatim
    // ------------------------------------------------------------------
    // Start
    // ------------------------------------------------------------------

    function start() {
        route();
        var first = CFG.boot && CFG.boot.ok !== false ? Promise.resolve(CFG.boot) : api('GET', '/bootstrap');
        first.then(function (r) {
            if (!r || r.ok === false) { showPageError(errMsg(r)); return; }
            applyBoot(r);
            route();
            backFromLendspare();
            // Anything already running (a scheduled run, another manager's) shows up and is followed.
            api('GET', '/runs').then(function (list) {
                if (list && list.ok !== false) {
                    S.recent = (list.runs || list.data || []).map(normRun);
                    S.runs = S.recent.filter(isActive);
                    updateActivity();
                    paintUsage();
                    kick();
                }
            });
        });
    }

    /**
     * Back from "Connect LendSpare" (`?lendspare=connected|failed&message=...` on this page's own URL):
     * say how it went, and after a connection open the computer form, which now lists the computers.
     */
    function backFromLendspare() {
        var q = {};
        String(location.search || '').replace(/^\?/, '').split('&').forEach(function (kv) {
            if (!kv) { return; }
            var p = kv.split('=');
            q[decodeURIComponent(p[0])] = decodeURIComponent((p[1] || '').replace(/\+/g, ' '));
        });
        if (!q.lendspare) { return; }
        if (window.history && history.replaceState) { history.replaceState(null, '', String(location.pathname || '') + String(location.hash || '')); }
        var ok = q.lendspare === 'connected';
        var el = clear($('am-page-error'));
        add(el, h('div', { 'class': 'am-alert ' + (ok ? 'am-alert-ok' : 'am-alert-error'), role: ok ? 'status' : 'alert', text: q.message || '' }));
        announce(q.message || '');
        if (ok && S.drivers.some(function (d) { return d.id === 'ai-computer' && d.available !== false; })) {
            navigate('mounts');
            mountDialog(null, 'ai-computer');
        }
    }

    start();
})();
@endverbatim
</script>
