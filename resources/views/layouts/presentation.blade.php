{{--
  Spec 43 — Chrome-freies, self-contained Layout für das öffentliche digitale Kundenbuch.
  Kein App-Nav, kein Vite/Livewire (login-frei, cache-freundlich). Token-getrieben:
  die aufgelösten Design-Tokens werden zu CSS-Custom-Properties.
--}}
@php
    $tokens = $snapshot['resolved_design']['tokens'] ?? [];
    $pal = $tokens['palette'] ?? [];
    $typo = $tokens['typography'] ?? [];
    $fontMap = [
        'serif' => 'Georgia, "Times New Roman", serif',
        'sans'  => 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
        'mono'  => 'ui-monospace, SFMono-Regular, Menlo, monospace',
    ];
    $headingFont = $fontMap[$typo['heading'] ?? 'sans'] ?? $fontMap['sans'];
    $bodyFont = $fontMap[$typo['body'] ?? 'sans'] ?? $fontMap['sans'];
    $scale = (float) ($typo['scale'] ?? 1.0);
    $gap = match ($tokens['spacing'] ?? 'comfortable') { 'compact' => '14px', 'roomy' => '40px', default => '24px' };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $snapshot['title'] ?? 'Präsentation' }}</title>
    <style>
        :root {
            --pt-primary: {{ $pal['primary'] ?? '#6d28d9' }};
            --pt-accent: {{ $pal['accent'] ?? '#6d28d9' }};
            --pt-bg: {{ $pal['bg'] ?? '#ffffff' }};
            --pt-text: {{ $pal['text'] ?? '#111827' }};
            --pt-muted: {{ $pal['muted'] ?? '#6b7280' }};
            --pt-heading-font: {{ $headingFont }};
            --pt-body-font: {{ $bodyFont }};
            --pt-base: {{ round(16 * $scale) }}px;
            --pt-gap: {{ $gap }};
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: var(--pt-bg);
            color: var(--pt-text);
            font-family: var(--pt-body-font);
            font-size: var(--pt-base);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        .pt-page { max-width: 900px; margin: 0 auto; padding: clamp(20px, 5vw, 56px); }
        h1, h2, h3, h4 { font-family: var(--pt-heading-font); color: var(--pt-text); margin: 0 0 .4em; line-height: 1.15; }
        .pt-cover { text-align: center; margin-bottom: var(--pt-gap); }
        .pt-cover-img { width: 100%; max-height: 42vh; object-fit: cover; border-radius: 14px; margin-bottom: var(--pt-gap); }
        .pt-logo { max-height: 64px; max-width: 220px; object-fit: contain; margin-bottom: 16px; }
        .pt-title { font-size: calc(var(--pt-base) * 2.4); letter-spacing: -0.01em; }
        .pt-subtitle { color: var(--pt-muted); font-size: calc(var(--pt-base) * 1.1); margin: 0; }
        .pt-meta { color: var(--pt-muted); font-size: calc(var(--pt-base) * 0.85); margin-top: 10px; }
        .pt-meta span { margin: 0 4px; }
        .pt-section { margin: var(--pt-gap) 0; }
        .pt-section-title { font-size: calc(var(--pt-base) * 1.5); color: var(--pt-primary); border-bottom: 2px solid color-mix(in srgb, var(--pt-accent) 30%, transparent); padding-bottom: 6px; }
        .pt-depth-1 .pt-section-title { font-size: calc(var(--pt-base) * 1.25); }
        .pt-depth-2 .pt-section-title { font-size: calc(var(--pt-base) * 1.1); }
        .pt-section-text { color: var(--pt-muted); margin: 0 0 12px; }
        .pt-block { margin: 12px 0; }
        .pt-block-header { font-size: calc(var(--pt-base) * 1.05); margin: 14px 0 6px; }
        .pt-block-sub { color: var(--pt-muted); font-size: calc(var(--pt-base) * 0.9); margin: 2px 0 8px; }
        .pt-line { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 3px 0; }
        .pt-line-label { flex: 1; }
        .pt-line-price { color: var(--pt-primary); font-weight: 600; white-space: nowrap; }
        .pt-item .pt-line-label { color: var(--pt-text); }
        .pt-codes { color: var(--pt-muted); font-size: .72em; font-weight: 600; vertical-align: super; }
        .pt-price-summary { text-align: center; margin: var(--pt-gap) 0; padding: 18px; border-top: 1px solid color-mix(in srgb, var(--pt-text) 12%, transparent); }
        .pt-price-big { display: block; font-family: var(--pt-heading-font); font-size: calc(var(--pt-base) * 2); color: var(--pt-primary); font-weight: 700; }
        .pt-price-label { color: var(--pt-muted); text-transform: uppercase; letter-spacing: .08em; font-size: .75em; }
        .pt-legend { margin-top: var(--pt-gap); border-top: 1px solid color-mix(in srgb, var(--pt-text) 12%, transparent); padding-top: 12px; color: var(--pt-muted); font-size: calc(var(--pt-base) * 0.8); }
        .pt-legend .pt-code { color: var(--pt-accent); font-weight: 700; }
        .pt-legend-note { margin-top: 6px; opacity: .8; font-size: .9em; }
        .pt-text { margin: 12px 0; }
        .pt-heading { font-size: calc(var(--pt-base) * 1.5); color: var(--pt-primary); margin: var(--pt-gap) 0 8px; }
        .pt-image { width: 100%; border-radius: 12px; margin: 12px 0; }
        .pt-cta { text-align: center; margin: var(--pt-gap) 0; }
        .pt-cta-btn { display: inline-block; background: var(--pt-primary); color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 999px; font-weight: 600; }
        .pt-footer { margin-top: var(--pt-gap); text-align: center; color: var(--pt-muted); font-size: calc(var(--pt-base) * 0.78); }
        @media print { .pt-cta { display: none; } body { background: #fff; } }
    </style>
</head>
<body data-foodalchemist-presentation="{{ $snapshot['type'] ?? '' }}">
    <main class="pt-page">
        @yield('content')
        <footer class="pt-footer">
            @if(!empty($snapshot['branding']['footer'])){{ $snapshot['branding']['footer'] }}@endif
        </footer>
    </main>
</body>
</html>
