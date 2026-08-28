{{--
  Spec 43 — Chrome-freies, self-contained Layout fürs öffentliche digitale Kundenbuch.
  Editorial-Magazin-Qualität: Web-Fonts, Vollbild-Cover-Hero, großzügiger Rhythmus,
  Scroll-Reveal. Token-getrieben (aufgelöste Design-Tokens → CSS-Custom-Properties).
--}}
@php
    $tokens = $snapshot['resolved_design']['tokens'] ?? [];
    $pal = $tokens['palette'] ?? [];
    $typo = $tokens['typography'] ?? [];
    $fontMap = [
        'display-serif' => '"Fraunces", Georgia, "Times New Roman", serif',
        'serif'         => 'Georgia, "Times New Roman", serif',
        'sans'          => '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
        'mono'          => 'ui-monospace, SFMono-Regular, Menlo, monospace',
    ];
    $headingFont = $fontMap[$typo['heading'] ?? 'display-serif'] ?? $fontMap['display-serif'];
    $bodyFont = $fontMap[$typo['body'] ?? 'sans'] ?? $fontMap['sans'];
    $scale = (float) ($typo['scale'] ?? 1.0);
    $gap = match ($tokens['spacing'] ?? 'comfortable') { 'compact' => '18px', 'roomy' => '56px', default => '34px' };
    $auto = ! empty($tokens['auto_advance']);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $snapshot['title'] ?? 'Präsentation' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --pt-primary: {{ $pal['primary'] ?? '#6d28d9' }};
            --pt-accent: {{ $pal['accent'] ?? '#b8874a' }};
            --pt-bg: {{ $pal['bg'] ?? '#fbfaf8' }};
            --pt-surface: {{ $pal['surface'] ?? 'rgba(0,0,0,0.02)' }};
            --pt-text: {{ $pal['text'] ?? '#1a1712' }};
            --pt-muted: {{ $pal['muted'] ?? '#8a8178' }};
            --pt-line: color-mix(in srgb, var(--pt-text) 14%, transparent);
            --pt-heading-font: {{ $headingFont }};
            --pt-body-font: {{ $bodyFont }};
            --pt-base: {{ round(16.5 * $scale, 2) }}px;
            --pt-gap: {{ $gap }};
            --pt-measure: 720px;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: var(--pt-bg);
            color: var(--pt-text);
            font-family: var(--pt-body-font);
            font-size: var(--pt-base);
            line-height: 1.62;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }
        img { max-width: 100%; display: block; }
        .pt-wrap { overflow-x: hidden; }
        .pt-measure { max-width: var(--pt-measure); margin-inline: auto; padding-inline: clamp(22px, 6vw, 40px); }
        h1,h2,h3,h4 { font-family: var(--pt-heading-font); color: var(--pt-text); margin: 0; line-height: 1.08; font-weight: 600; letter-spacing: -0.01em; }
        .pt-kicker { font-family: var(--pt-body-font); text-transform: uppercase; letter-spacing: .22em; font-size: .68rem; font-weight: 600; color: var(--pt-accent); }

        /* Cover / Hero */
        .pt-hero { position: relative; min-height: min(88vh, 820px); display: grid; place-items: center; text-align: center; overflow: hidden; }
        .pt-hero-media { position: absolute; inset: 0; }
        .pt-hero-media img { width: 100%; height: 100%; object-fit: cover; }
        .pt-hero-media::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,.28), rgba(0,0,0,.55)); }
        .pt-hero-inner { position: relative; z-index: 1; padding: clamp(28px, 8vw, 80px); max-width: 900px; }
        .pt-hero.has-media, .pt-hero.has-media .pt-hero-title, .pt-hero.has-media .pt-hero-sub, .pt-hero.has-media .pt-kicker { color: #fff; }
        .pt-hero.has-media .pt-kicker { color: color-mix(in srgb, var(--pt-accent) 60%, #fff); }
        .pt-hero-logo { max-height: 74px; max-width: 240px; object-fit: contain; margin: 0 auto clamp(18px,3vw,26px); }
        .pt-hero.has-media .pt-hero-logo { filter: drop-shadow(0 2px 8px rgba(0,0,0,.4)); }
        .pt-hero-title { font-family: var(--pt-heading-font); font-weight: 900; font-size: clamp(2.6rem, 8vw, 5.2rem); line-height: 1.02; margin: 18px 0 0; }
        .pt-hero-sub { font-size: clamp(1rem, 2.4vw, 1.28rem); color: var(--pt-muted); margin: 20px auto 0; max-width: 640px; }
        .pt-hero-meta { margin-top: 22px; font-size: .82rem; letter-spacing: .04em; color: var(--pt-muted); }
        .pt-hero.no-media { border-bottom: 1px solid var(--pt-line); min-height: min(70vh, 640px); }
        .pt-hero.no-media .pt-hero-sub { color: var(--pt-muted); }

        /* Sections */
        .pt-section { padding: clamp(40px, 8vw, 84px) 0 0; }
        .pt-section-head { margin-bottom: var(--pt-gap); }
        .pt-section-title { font-size: clamp(1.7rem, 4.4vw, 2.7rem); margin-top: 10px; }
        .pt-depth-1 .pt-section-title { font-size: clamp(1.4rem, 3.4vw, 2rem); }
        .pt-depth-2 .pt-section-title { font-size: clamp(1.2rem, 2.8vw, 1.5rem); }
        .pt-section-text { color: var(--pt-muted); font-size: 1.06rem; margin: 14px 0 0; max-width: 60ch; }
        .pt-section-img { width: 100%; border-radius: 4px; margin: var(--pt-gap) 0 0; aspect-ratio: 16/7; object-fit: cover; }
        .pt-section-gallery { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--pt-gap); margin: var(--pt-gap) 0 0; }
        .pt-section-img--multi { margin: 0; aspect-ratio: 4/3; }
        @media (max-width: 640px) { .pt-section-gallery { grid-template-columns: 1fr; } }

        /* Menü-Zeilen mit Punkt-Leadern (Magazin/Menü-Look) */
        .pt-block { margin-top: var(--pt-gap); }
        .pt-block-header { font-family: var(--pt-heading-font); font-size: 1.18rem; margin: calc(var(--pt-gap) * .8) 0 6px; }
        .pt-block-sub { color: var(--pt-muted); font-size: .96rem; margin: 2px 0 10px; }
        .pt-line { display: flex; align-items: baseline; gap: 2px; padding: 9px 0; border-bottom: 1px solid var(--pt-line); }
        .pt-line:last-child { border-bottom: 0; }
        .pt-line-label { font-weight: 500; }
        .pt-line-dots { flex: 1; margin: 0 8px; border-bottom: 1px dotted color-mix(in srgb, var(--pt-text) 32%, transparent); transform: translateY(-3px); min-width: 20px; }
        .pt-line-price { font-family: var(--pt-heading-font); color: var(--pt-primary); font-weight: 600; white-space: nowrap; }
        .pt-item .pt-line-label { color: var(--pt-text); font-weight: 400; }
        .pt-item.pt-indent-1 { padding-left: 18px; }
        .pt-item.pt-indent-2 { padding-left: 36px; }
        .pt-codes { color: var(--pt-muted); font-size: .62em; font-weight: 600; letter-spacing: .06em; vertical-align: super; margin-left: 4px; }

        /* Preis-Summe */
        .pt-price-summary { text-align: center; margin-top: calc(var(--pt-gap) * 1.4); padding: clamp(26px,5vw,42px); background: var(--pt-surface); border-radius: 6px; }
        .pt-price-big { display: block; font-family: var(--pt-heading-font); font-weight: 900; font-size: clamp(2.4rem,7vw,3.4rem); color: var(--pt-primary); line-height: 1; }
        .pt-price-label { display: block; margin-top: 8px; text-transform: uppercase; letter-spacing: .16em; font-size: .74rem; color: var(--pt-muted); }

        /* Legende / Fußzeile */
        .pt-legend { margin-top: calc(var(--pt-gap) * 1.2); padding-top: 16px; border-top: 1px solid var(--pt-line); color: var(--pt-muted); font-size: .82rem; line-height: 1.9; }
        .pt-legend .pt-code { color: var(--pt-accent); font-weight: 700; }
        .pt-legend-note { margin-top: 8px; opacity: .8; }
        .pt-cta { text-align: center; margin: calc(var(--pt-gap) * 1.4) 0 0; }
        .pt-cta-btn { display: inline-block; background: var(--pt-primary); color: #fff; text-decoration: none; padding: 15px 40px; border-radius: 999px; font-weight: 600; letter-spacing: .01em; transition: transform .15s ease, box-shadow .15s ease; }
        .pt-cta-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px color-mix(in srgb, var(--pt-primary) 35%, transparent); }
        .pt-text { margin-top: var(--pt-gap); font-size: 1.06rem; }
        .pt-heading { font-size: clamp(1.5rem,4vw,2.2rem); margin-top: calc(var(--pt-gap)*1.2); }
        .pt-image-band { margin: var(--pt-gap) 0 0; }
        .pt-image-band img { width: 100%; border-radius: 4px; aspect-ratio: 16/8; object-fit: cover; }
        .pt-spacer { height: var(--pt-gap); }
        .pt-footer { margin-top: calc(var(--pt-gap) * 1.6); padding: 40px 0 60px; text-align: center; color: var(--pt-muted); font-size: .8rem; letter-spacing: .04em; }

        /* Grid (Speiseplan) */
        .pt-grid { width: 100%; border-collapse: collapse; margin-top: var(--pt-gap); font-size: .95rem; }
        .pt-grid th { text-align: left; padding: 10px; color: var(--pt-primary); border-bottom: 2px solid color-mix(in srgb, var(--pt-accent) 40%, transparent); white-space: nowrap; font-family: var(--pt-heading-font); }
        .pt-grid td { padding: 10px; vertical-align: top; border-bottom: 1px solid var(--pt-line); }

        /* Scroll-Reveal */
        .pt-reveal { opacity: 0; transform: translateY(18px); transition: opacity .7s ease, transform .7s ease; }
        .pt-reveal.pt-in { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) { .pt-reveal { opacity: 1 !important; transform: none !important; transition: none; } }

        @media print {
            .pt-cta { display: none; }
            .pt-hero { min-height: auto; }
            body { background: #fff; }
            .pt-reveal { opacity: 1 !important; transform: none !important; }
        }
    </style>
    @php $customCss = $snapshot['resolved_design']['custom_css'] ?? null; @endphp
    @if(!empty($customCss))
        {{-- Stufe 2: KI/User-Design-CSS (sanitisiert: kein <, @import, expression). Überschreibt die Basis. --}}
        <style>{!! str_replace(['<', '>'], '', $customCss) !!}</style>
    @endif
</head>
<body data-foodalchemist-presentation="{{ $snapshot['type'] ?? '' }}"@if($auto) data-kiosk-auto="1"@endif>
    <div class="pt-wrap">
        @yield('content')
        <footer class="pt-footer">
            @if(!empty($snapshot['branding']['footer'])){{ $snapshot['branding']['footer'] }}@endif
        </footer>
    </div>
    <script>
        (function () {
            var els = document.querySelectorAll('.pt-reveal');
            if (!('IntersectionObserver' in window) || !els.length) { els.forEach(function (e) { e.classList.add('pt-in'); }); return; }
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('pt-in'); io.unobserve(en.target); } });
            }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
            els.forEach(function (e) { io.observe(e); });
        })();
    </script>
</body>
</html>
