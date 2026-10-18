{{-- Inlined so the module renders correctly before any publish step. --}}
<style>
    :root {
        --am-bg: #f6f7f9;
        --am-panel: #fff;
        --am-border: #dfe3e8;
        --am-text: #23282d;
        --am-muted: #6b7280;
        --am-accent: #2f6fd0;
        --am-accent-soft: #eaf1fb;
        --am-warn: #b45309;
        --am-warn-soft: #fef6e7;
        --am-danger: #c0392b;
        --am-danger-soft: #fdf3f2;
        --am-ok: #1e7d47;
        --am-ok-soft: #e8f6ee;
        --am-code-bg: #1f2937;
        --am-code-text: #e5e7eb;
    }

    * { box-sizing: border-box; }

    /* `hidden` is how this page shows and hides things; a class that sets
       `display` would otherwise outrank the UA rule and pin an element open. */
    [hidden] { display: none !important; }

    body {
        margin: 0;
        background: var(--am-bg);
        color: var(--am-text);
        font: 14px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    .am-shell { padding: 1.25rem 1.5rem 3rem; max-width: 1400px; margin: 0 auto; }

    .am-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .am-head h1 { margin: 0; font-size: 1.35rem; letter-spacing: -0.01em; }
    .am-sub { margin: 0.15rem 0 0; color: var(--am-muted); }
    .am-summary { color: var(--am-muted); font-size: 0.9rem; }
    .am-summary b { color: var(--am-text); }

    .am-alert {
        padding: 0.7rem 0.9rem; border-radius: 6px; margin-bottom: 1rem;
        display: flex; flex-direction: column; gap: 0.35rem; border-left: 3px solid;
    }
    .am-alert-warn { background: var(--am-warn-soft); border-color: var(--am-warn); color: var(--am-warn); }
    .am-alert-error { background: var(--am-danger-soft); border-color: var(--am-danger); color: var(--am-danger); }
    .am-alert-ok { background: var(--am-ok-soft); border-color: var(--am-ok); color: var(--am-ok); }
    .am-alert-info { background: var(--am-accent-soft); border-color: var(--am-accent); color: var(--am-text); }

    .am-cmd {
        display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
    }
    .am-cmd code, .am-code-inline {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.8rem;
        background: rgba(0, 0, 0, 0.06); padding: 0.15rem 0.45rem; border-radius: 4px; color: var(--am-text);
        word-break: break-all;
    }

    /* Tabs */
    .am-tabs { display: flex; gap: 0.25rem; border-bottom: 1px solid var(--am-border); margin-bottom: 1rem; overflow-x: auto; }
    .am-tab {
        font: inherit; background: none; border: 0; border-bottom: 2px solid transparent;
        padding: 0.6rem 0.9rem; cursor: pointer; color: var(--am-muted); white-space: nowrap;
    }
    .am-tab:hover { color: var(--am-text); }
    .am-tab[aria-selected="true"] { color: var(--am-accent); border-bottom-color: var(--am-accent); font-weight: 600; }
    .am-tab .am-count {
        display: inline-block; min-width: 1.3rem; padding: 0 0.35rem; margin-left: 0.3rem; border-radius: 999px;
        background: var(--am-accent); color: #fff; font-size: 0.7rem; text-align: center;
    }

    .am-panel {
        background: var(--am-panel); border: 1px solid var(--am-border);
        border-radius: 8px; padding: 1rem; margin-bottom: 1rem;
    }
    .am-panel h2 { margin: 0 0 0.6rem; font-size: 1rem; }
    .am-panel h3 { margin: 0.8rem 0 0.4rem; font-size: 0.9rem; }

    .am-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; margin-bottom: 1rem; }
    .am-toolbar .am-grow { flex: 1 1 auto; }

    .am-empty {
        text-align: center; padding: 2rem 1rem; color: var(--am-muted);
        border: 1px dashed var(--am-border); border-radius: 8px; background: var(--am-panel);
    }
    .am-empty i { font-size: 2rem; display: block; margin-bottom: 0.5rem; opacity: 0.6; }
    .am-empty p { margin: 0.2rem 0 0.8rem; }

    /* Cards */
    .am-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1rem; }
    .am-card {
        background: var(--am-panel); border: 1px solid var(--am-border); border-radius: 8px;
        padding: 0.9rem; display: flex; flex-direction: column; gap: 0.55rem; min-width: 0;
    }
    .am-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.6rem; }
    .am-card-title { margin: 0; font-size: 1rem; font-weight: 600; overflow-wrap: anywhere; }
    .am-card-badges { display: flex; flex-wrap: wrap; gap: 0.3rem; }
    .am-card-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: auto; padding-top: 0.3rem; }
    .am-kv { display: flex; justify-content: space-between; gap: 0.6rem; font-size: 0.85rem; }
    .am-kv > span:first-child { color: var(--am-muted); flex: 0 0 auto; }
    .am-kv > span:last-child { text-align: right; overflow-wrap: anywhere; min-width: 0; }
    .am-error-text { color: var(--am-danger); font-size: 0.82rem; overflow-wrap: anywhere; }

    /* Badges and chips */
    .am-badge {
        display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px;
        font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap;
        background: var(--am-bg); color: var(--am-muted); border: 1px solid var(--am-border);
    }
    .am-badge.is-ok, .am-badge.is-uploaded, .am-badge.is-done { background: var(--am-ok-soft); color: var(--am-ok); border-color: #bfe3cd; }
    .am-badge.is-error, .am-badge.is-failed { background: var(--am-danger-soft); color: var(--am-danger); border-color: #efc9c4; }
    .am-badge.is-pending, .am-badge.is-queued, .am-badge.is-scanning, .am-badge.is-uploading, .am-badge.is-running { background: var(--am-accent-soft); color: var(--am-accent); border-color: #c4d8f5; }
    .am-badge.is-warn, .am-badge.is-cancelled, .am-badge.is-expired, .am-badge.is-unknown { background: var(--am-warn-soft); color: var(--am-warn); border-color: #f0d9ae; }

    /* Buttons and fields */
    .am-btn {
        font: inherit; padding: 0.4rem 0.8rem; border-radius: 5px; cursor: pointer;
        border: 1px solid var(--am-border); background: #fff; color: inherit;
        display: inline-flex; align-items: center; gap: 0.35rem; text-decoration: none;
    }
    .am-btn:hover:not(:disabled) { background: var(--am-bg); }
    .am-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .am-btn-primary { background: var(--am-accent); border-color: var(--am-accent); color: #fff; }
    .am-btn-primary:hover:not(:disabled) { background: #2760b8; }
    .am-btn-danger { color: var(--am-danger); border-color: #e9c4c0; }
    .am-btn-small { padding: 0.2rem 0.55rem; font-size: 0.8rem; }
    .am-btn:focus-visible, .am-tab:focus-visible, .am-tree-item:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
        outline: 2px solid var(--am-accent); outline-offset: 1px;
    }

    select, input[type="text"], input[type="password"], input[type="number"], input[type="search"], textarea {
        font: inherit; padding: 0.4rem 0.55rem; border: 1px solid var(--am-border);
        border-radius: 5px; background: #fff; color: inherit; width: 100%; min-width: 0;
    }
    textarea { resize: vertical; }
    .am-field { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.8rem; min-width: 0; }
    .am-field > label, .am-field > .am-label { font-size: 0.8rem; font-weight: 600; }
    .am-field .am-req { color: var(--am-danger); }
    .am-help { color: var(--am-muted); font-size: 0.8rem; margin: 0; }
    .am-field-error { color: var(--am-danger); font-size: 0.8rem; margin: 0; }
    .am-check { display: flex; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.8rem; }
    .am-check input { margin-top: 0.25rem; }
    .am-check label { font-weight: 600; font-size: 0.85rem; }
    .am-check .am-help { display: block; font-weight: 400; }
    .am-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
    .am-row > input, .am-row > select { flex: 1 1 10rem; width: auto; }
    .am-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 0.9rem; }
    @media (max-width: 640px) { .am-grid2 { grid-template-columns: 1fr; } }
    fieldset { border: 1px solid var(--am-border); border-radius: 6px; margin: 0 0 0.9rem; padding: 0.6rem 0.8rem 0.2rem; min-width: 0; }
    legend { font-size: 0.8rem; font-weight: 600; padding: 0 0.3rem; }
    .am-secret-note { color: var(--am-ok); font-size: 0.8rem; }
    .am-multi { max-height: 9rem; overflow: auto; border: 1px solid var(--am-border); border-radius: 5px; padding: 0.35rem 0.55rem; }
    .am-multi label { display: flex; gap: 0.45rem; align-items: center; font-weight: 400; font-size: 0.85rem; }
    .am-multi input { width: auto; }

    /* Progress */
    .am-progress { display: flex; align-items: center; gap: 0.6rem; font-size: 0.8rem; color: var(--am-muted); }
    .am-progress-bar { flex: 1; height: 6px; background: var(--am-bg); border-radius: 999px; overflow: hidden; border: 1px solid var(--am-border); }
    .am-progress-bar i { display: block; height: 100%; background: var(--am-accent); width: 0; transition: width 0.4s ease; }
    .am-progress-bar.is-indeterminate i { width: 35%; animation: am-slide 1.2s ease-in-out infinite; }
    @keyframes am-slide { 0% { margin-left: -35%; } 100% { margin-left: 100%; } }
    @media (prefers-reduced-motion: reduce) { .am-progress-bar.is-indeterminate i { animation: none; width: 100%; opacity: 0.4; } }
    .am-usage { border-top: 1px dashed var(--am-border); padding-top: 0.5rem; display: flex; flex-direction: column; gap: 0.35rem; }
    .am-usage-head { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .am-usage-total { font-size: 1rem; font-weight: 600; }
    .am-usage-meta { color: var(--am-muted); font-size: 0.78rem; }
    .am-usage-rows { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.8rem; }
    .am-usage-row { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 0.6rem; }
    .am-usage-row span:nth-child(n+2) { color: var(--am-muted); text-align: right; white-space: nowrap; }
    .am-usage-row span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* Tables */
    .am-table-wrap { overflow-x: auto; background: var(--am-panel); border: 1px solid var(--am-border); border-radius: 8px; }
    table.am-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .am-table th, .am-table td { text-align: left; padding: 0.5rem 0.65rem; border-bottom: 1px solid var(--am-border); vertical-align: top; }
    .am-table th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--am-muted); white-space: nowrap; }
    .am-table tr:last-child td { border-bottom: 0; }
    .am-table td.am-num { text-align: right; white-space: nowrap; }
    .am-table td.am-wrap { overflow-wrap: anywhere; }
    .am-table .am-actions { display: flex; gap: 0.3rem; flex-wrap: wrap; justify-content: flex-end; }
    .am-pager { display: flex; justify-content: space-between; align-items: center; margin-top: 0.7rem; color: var(--am-muted); font-size: 0.85rem; }

    /* Runs */
    .am-run { padding: 0.7rem 0; border-bottom: 1px solid var(--am-border); display: flex; flex-direction: column; gap: 0.35rem; }
    .am-run:last-child { border-bottom: 0; }
    .am-run-head { display: flex; justify-content: space-between; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
    .am-run-title { font-weight: 600; }
    .am-run-meta { color: var(--am-muted); font-size: 0.8rem; overflow-wrap: anywhere; }

    /* Dialogs */
    .am-modal {
        position: fixed; inset: 0; z-index: 40; display: flex;
        align-items: center; justify-content: center; padding: 1.5rem;
        background: rgba(35, 40, 45, 0.45);
    }
    .am-modal-box {
        background: var(--am-panel); border: 1px solid var(--am-border); border-radius: 8px;
        width: min(760px, 100%); max-height: 100%; display: flex; flex-direction: column;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
    }
    .am-modal-box.is-narrow { width: min(520px, 100%); }
    .am-modal-head {
        display: flex; justify-content: space-between; align-items: center; gap: 1rem;
        padding: 0.75rem 1rem; border-bottom: 1px solid var(--am-border);
    }
    .am-modal-head h2 { margin: 0; font-size: 1rem; }
    .am-modal-body { padding: 1rem; overflow: auto; flex: 1 1 auto; }
    .am-modal-foot {
        display: flex; justify-content: flex-end; align-items: center; gap: 0.5rem; flex-wrap: wrap;
        padding: 0.7rem 1rem; border-top: 1px solid var(--am-border);
    }
    .am-modal-foot .am-msg { margin-right: auto; }

    .am-msg { color: var(--am-muted); font-size: 0.85rem; margin: 0; min-height: 1.1em; }
    .am-msg.is-error { color: var(--am-danger); }
    .am-msg.is-ok { color: var(--am-ok); }

    /* Folder picker */
    .am-picker { border: 1px solid var(--am-border); border-radius: 6px; background: #fff; margin-top: 0.4rem; }
    .am-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 0.15rem; padding: 0.4rem 0.55rem; border-bottom: 1px solid var(--am-border); font-size: 0.82rem; }
    .am-crumb { background: none; border: 0; padding: 0.1rem 0.25rem; font: inherit; color: var(--am-accent); cursor: pointer; border-radius: 3px; }
    .am-crumb:hover { background: var(--am-accent-soft); }
    .am-crumb[aria-current="true"] { color: var(--am-text); font-weight: 600; cursor: default; }
    .am-tree { max-height: 12rem; overflow: auto; padding: 0.25rem; }
    .am-tree-item {
        display: flex; align-items: center; gap: 0.5rem; width: 100%; text-align: left; font: inherit;
        background: none; border: 0; padding: 0.3rem 0.5rem; border-radius: 4px; cursor: pointer; color: inherit;
    }
    .am-tree-item:hover { background: var(--am-bg); }
    .am-tree-item i { color: var(--am-accent); }
    .am-tree-note { color: var(--am-muted); font-size: 0.82rem; padding: 0.4rem 0.55rem; }
    .am-picker-foot { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; padding: 0.4rem 0.55rem; border-top: 1px solid var(--am-border); font-size: 0.8rem; }

    /* Driver picker */
    .am-driver-group { margin-bottom: 1rem; }
    .am-driver-group h3 { margin: 0 0 0.4rem; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--am-muted); }
    .am-drivers { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 0.6rem; }
    .am-driver {
        text-align: left; font: inherit; color: inherit; background: #fff; border: 1px solid var(--am-border);
        border-radius: 7px; padding: 0.6rem 0.7rem; cursor: pointer; display: flex; flex-direction: column; gap: 0.2rem;
    }
    .am-driver:hover:not(.is-disabled) { border-color: var(--am-accent); background: var(--am-accent-soft); }
    .am-driver.is-disabled { opacity: 0.75; cursor: default; background: var(--am-bg); }
    .am-driver b { font-size: 0.9rem; }
    .am-driver span { color: var(--am-muted); font-size: 0.78rem; }
    .am-driver .am-cmd { margin-top: 0.3rem; }

    /* Code block */
    .am-code { position: relative; }
    .am-code pre {
        margin: 0; background: var(--am-code-bg); color: var(--am-code-text); border-radius: 6px;
        padding: 0.8rem 0.9rem; overflow: auto; font-size: 0.8rem; line-height: 1.5;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    .am-code .am-btn { position: absolute; top: 0.4rem; right: 0.4rem; }

    .am-probe { list-style: none; margin: 0.5rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.4rem; }
    .am-probe li { display: flex; gap: 0.6rem; align-items: flex-start; }
    .am-probe i.fa-check-circle { color: var(--am-ok); }
    .am-probe i.fa-times-circle { color: var(--am-danger); }

    .am-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

    @media (max-width: 760px) {
        .am-shell { padding: 1rem 0.75rem 3rem; }
        .am-cards { grid-template-columns: 1fr; }
    }
    .am-computers-box { margin: 12px 0 16px; }
    .am-computer { border-top: 1px solid var(--am-border); padding: 10px 0 4px; }
    .am-shares { margin: 6px 0; padding-left: 18px; color: var(--am-muted); }
    .am-shares b { color: var(--am-text); }
    .am-code { font-family: ui-monospace, Menlo, Consolas, monospace; letter-spacing: 1px; }
</style>
