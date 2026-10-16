{{--
    The aMount manager page.

    Rendered into the manager's main frame by a routing module, so it owns the
    whole document: styles are inlined, the manager's icon set is linked, and
    everything the front end cannot derive (route prefix, CSRF token, the
    strings) arrives as one JSON island. The page data itself comes from
    GET /bootstrap, or from `$boot` when the controller already has it.

    Expected view data: $baseUrl, $csrfToken, $iconsUrl, optional $boot (array).
--}}
@php
    // `$translator` is only passed by tests; the CMS container supplies it otherwise.
    $translator = $translator ?? app('translator');
    $all = array_replace(
        (array) $translator->get('aMount::global', [], 'en'),
        (array) $translator->get('aMount::global')
    );
    // Only the families the page needs: its own strings plus the driver and
    // error texts the server returns as lang keys.
    $dictionary = array_filter(
        $all,
        static fn ($value, $key) => is_string($value)
            && (str_starts_with((string) $key, 'ui_')
                || preg_match('/^(f_|h_|driver_|preset_|error_|test_|probe_|server_instr|queue_hint|what_)/', (string) $key)),
        ARRAY_FILTER_USE_BOTH
    );

    // The S3 provider presets live with the driver; the form fills endpoint,
    // region and path style from them when a provider is chosen.
    $s3Presets = [];
    if (class_exists(\Elcreator\aMount\Drivers\S3Driver::class)) {
        foreach (\Elcreator\aMount\Drivers\S3Driver::PRESETS as $key => $preset) {
            $s3Presets[$key] = ['endpoint' => $preset[1], 'region' => $preset[2], 'path_style' => $preset[3]];
        }
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $translator->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('aMount::global.ui_title') }}</title>
    <link rel="stylesheet" href="{{ $iconsUrl }}">
    @include('aMount::partials.styles')
</head>
<body>
<div class="am-shell" id="am-shell">

    <header class="am-head">
        <div>
            <h1>{{ __('aMount::global.ui_title') }}</h1>
            <p class="am-sub">{{ __('aMount::global.ui_tagline') }}</p>
        </div>
        <div class="am-summary" id="am-summary" role="status"></div>
    </header>

    {{-- Queue health: which queue carries uploads, and when it is not running
         the exact line to start it. Hidden while everything is fine. --}}
    <div id="am-queue"></div>
    <div id="am-page-error"></div>

    <div class="am-tabs" role="tablist" aria-label="{{ __('aMount::global.ui_title') }}" id="am-tabs">
        <button type="button" class="am-tab" role="tab" id="am-tab-mounts" data-tab="mounts" aria-controls="am-panel-mounts" aria-selected="true">
            <i class="fa fa-cloud" aria-hidden="true"></i> {{ __('aMount::global.ui_tab_mounts') }}
        </button>
        <button type="button" class="am-tab" role="tab" id="am-tab-folders" data-tab="folders" aria-controls="am-panel-folders" aria-selected="false" tabindex="-1">
            <i class="fa fa-folder-open" aria-hidden="true"></i> {{ __('aMount::global.ui_tab_folders') }}
        </button>
        <button type="button" class="am-tab" role="tab" id="am-tab-files" data-tab="files" aria-controls="am-panel-files" aria-selected="false" tabindex="-1">
            <i class="fa fa-exchange" aria-hidden="true"></i> {{ __('aMount::global.ui_tab_files') }}<span class="am-count" id="am-active-count" hidden></span>
        </button>
        <button type="button" class="am-tab" role="tab" id="am-tab-server" data-tab="server" aria-controls="am-panel-server" aria-selected="false" tabindex="-1" hidden>
            <i class="fa fa-server" aria-hidden="true"></i> {{ __('aMount::global.ui_tab_server') }}
        </button>
    </div>

    <section role="tabpanel" id="am-panel-mounts" aria-labelledby="am-tab-mounts"></section>
    <section role="tabpanel" id="am-panel-folders" aria-labelledby="am-tab-folders" hidden></section>
    <section role="tabpanel" id="am-panel-files" aria-labelledby="am-tab-files" hidden></section>
    <section role="tabpanel" id="am-panel-server" aria-labelledby="am-tab-server" hidden></section>
</div>

{{-- Dialogs are built into this root by the script. --}}
<div id="am-modal-root"></div>
<div class="am-sr" id="am-live" aria-live="polite" role="status"></div>

<script>
    window.AMOUNT = @js([
        'baseUrl' => $baseUrl,
        'csrf' => $csrfToken,
        'boot' => $boot ?? null,
        's3Presets' => $s3Presets,
        'lang' => $dictionary,
    ]);
</script>
@include('aMount::partials.script')
</body>
</html>
