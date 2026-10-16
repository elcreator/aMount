<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('aMount::global.http_title') }}</title>
    <style>
        body { font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding: 2rem; color: #333; }
        .notice { max-width: 32rem; padding: 1rem 1.25rem; border-left: 3px solid #c0392b; background: #fdf3f2; }
    </style>
</head>
<body>
    <div class="notice">{{ __('aMount::global.http_denied') }}</div>
</body>
</html>
