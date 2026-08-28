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

    // Punkt 3 (Spec 43): Navigation + Lightbox. nav ∈ none|anchor|sidebar; lohnt erst ab 2 Kapiteln.
    $nav = $tokens['nav'] ?? 'none';
    $lightbox = ! empty($tokens['lightbox']);
    $navItems = [];
    if ($nav !== 'none') {
        foreach (($snapshot['content']['sections'] ?? []) as $i => $sec) {
            $t = trim((string) ($sec['title'] ?? ''));
            if ($t === '') { continue; }
            $navItems[] = [
                'id' => ! empty($sec['anker']) ? $sec['anker'] : 'pt-sec-' . $i,
                'title' => $t,
                'depth' => min((int) ($sec['depth'] ?? 0), 2),
            ];
        }
    }
    if (count($navItems) < 2) { $nav = 'none'; }
    $navLabel = ($snapshot['type'] ?? 'foodbook') === 'speisekarte' ? 'Rubriken' : 'Kapitel';
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
            --pt-wide: 1180px;
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
        .pt-wide { max-width: var(--pt-wide); margin-inline: auto; padding-inline: clamp(22px, 6vw, 48px); }
        h1,h2,h3,h4 { font-family: var(--pt-heading-font); color: var(--pt-text); margin: 0; line-height: 1.08; font-weight: 600; letter-spacing: -0.01em; }
        .pt-kicker { font-family: var(--pt-body-font); text-transform: uppercase; letter-spacing: .22em; font-size: .68rem; font-weight: 600; color: var(--pt-accent); }

        /* Cover / Hero */
        .pt-hero { position: relative; min-height: min(88vh, 820px); display: grid; place-items: center; text-align: center; overflow: hidden; }
        .pt-hero--h-klein { min-height: min(46vh, 420px); }
        .pt-hero--h-mittel { min-height: min(64vh, 600px); }
        .pt-hero--h-gross { min-height: min(88vh, 820px); }
        .pt-hero-media { position: absolute; inset: 0; }
        .pt-hero-media img { width: 100%; height: 100%; object-fit: cover; }
        /* Einpassen: ganzes Bild zeigen (kein Beschnitt), Rest bekommt eine dunkle Bühne. */
        .pt-hero--fit-contain { background: #14100c; }
        .pt-hero--fit-contain .pt-hero-media img { object-fit: contain; }
        .pt-hero--fit-contain .pt-hero-media::after { background: linear-gradient(180deg, rgba(0,0,0,.15), rgba(0,0,0,.35)); }
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

        /* Sections — breites Raster statt einer zentrierten Spalte */
        .pt-section { padding: clamp(40px, 8vw, 84px) 0 0; }
        .pt-section-grid { display: block; }
        .pt-section-head { margin-bottom: var(--pt-gap); }
        .pt-section-title { font-size: clamp(1.7rem, 4.4vw, 2.7rem); margin-top: 10px; }
        .pt-depth-1 .pt-section-title { font-size: clamp(1.4rem, 3.4vw, 2rem); }
        .pt-depth-2 .pt-section-title { font-size: clamp(1.2rem, 2.8vw, 1.5rem); }
        .pt-section-text { color: var(--pt-muted); font-size: 1.06rem; margin: 14px 0 0; max-width: 60ch; }
        .pt-section-img { width: 100%; border-radius: 6px; margin: var(--pt-gap) 0 0; aspect-ratio: 16/7; object-fit: cover; }
        .pt-section-gallery { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: var(--pt-gap); margin: var(--pt-gap) 0 0; }
        .pt-section-img--multi { margin: 0; aspect-ratio: 4/3; }
        .pt-section-aside { margin: 0; }
        .pt-section-aside .pt-section-img { margin: 0; aspect-ratio: 4/3; height: 100%; }
        /* Asymmetrischer Split: Kopf links, Bild rechts (nur breite Viewports) */
        @media (min-width: 880px) {
            .pt-section-grid--split { display: grid; grid-template-columns: 1.05fr 0.95fr; gap: clamp(28px, 5vw, 60px); align-items: center; }
            .pt-section-grid--split .pt-section-head { margin-bottom: 0; }
            .pt-section:nth-of-type(even) .pt-section-grid--split { grid-template-columns: 0.95fr 1.05fr; }
            .pt-section:nth-of-type(even) .pt-section-grid--split .pt-section-head { order: 2; }
            .pt-section:nth-of-type(even) .pt-section-grid--split .pt-section-aside { order: 1; }
        }
        /* Gericht-Zeilen mehrspaltig (Masonry via CSS-columns) */
        /* Standard: eine ruhige Lesespalte (nicht über die ganze Breite gezogen). */
        .pt-blocks { max-width: 760px; }
        /* Opt-in-Variante: sauberes 2-Spalten-Raster (echtes Grid, keine Masonry). */
        .pt-blocks.pt-cols-2 { max-width: none; display: grid; grid-template-columns: 1fr 1fr; column-gap: clamp(28px, 5vw, 52px); align-items: start; }
        @media (max-width: 720px) { .pt-blocks.pt-cols-2 { grid-template-columns: 1fr; } }
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

        /* Navigation — Sidebar (Chassis) */
        .pt-sidebar, .pt-burger, .pt-toc { display: none; }
        .pt-sidebar-brand { font-family: var(--pt-heading-font); font-weight: 600; font-size: 1.06rem; line-height: 1.2; margin-bottom: 22px; padding-right: 8px; }
        .pt-sidebar-brand img { max-height: 46px; max-width: 170px; object-fit: contain; margin-bottom: 12px; }
        .pt-sidebar-nav a { display: block; padding: 7px 10px; margin: 1px 0; border-radius: 6px; border-left: 2px solid transparent; color: var(--pt-muted); text-decoration: none; font-size: .9rem; line-height: 1.3; transition: color .15s ease, background .15s ease, border-color .15s ease; }
        .pt-sidebar-nav a:hover { color: var(--pt-text); background: color-mix(in srgb, var(--pt-text) 5%, transparent); }
        .pt-sidebar-nav a.pt-active { color: var(--pt-primary); border-left-color: var(--pt-primary); background: color-mix(in srgb, var(--pt-primary) 9%, transparent); }
        .pt-sidebar-nav a.pt-d1 { padding-left: 22px; font-size: .84rem; }
        .pt-sidebar-nav a.pt-d2 { padding-left: 32px; font-size: .8rem; opacity: .9; }
        @media (min-width: 1024px) {
            .pt-nav-sidebar .pt-sidebar { display: flex; flex-direction: column; position: fixed; top: 0; left: 0; width: 268px; height: 100vh; padding: 34px 20px; background: var(--pt-surface); border-right: 1px solid var(--pt-line); overflow-y: auto; z-index: 40; }
            .pt-nav-sidebar .pt-wrap { padding-left: 268px; }
        }
        /* Sidebar mobil = Off-Canvas-Drawer + Burger */
        @media (max-width: 1023px) {
            .pt-nav-sidebar .pt-burger { display: flex; }
            .pt-nav-sidebar .pt-sidebar { display: flex; flex-direction: column; position: fixed; top: 0; left: 0; width: min(84vw, 320px); height: 100vh; padding: 68px 20px 28px; background: var(--pt-bg); border-right: 1px solid var(--pt-line); overflow-y: auto; z-index: 60; transform: translateX(-100%); transition: transform .28s ease; box-shadow: 0 0 40px rgba(0,0,0,.18); }
            body.pt-drawer-open .pt-sidebar { transform: none; }
            body.pt-drawer-open .pt-scrim { display: block; }
        }
        .pt-burger { position: fixed; top: 14px; left: 14px; z-index: 70; width: 42px; height: 42px; border-radius: 10px; border: 1px solid var(--pt-line); background: var(--pt-bg); color: var(--pt-text); cursor: pointer; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 14px rgba(0,0,0,.1); }
        .pt-scrim { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 55; border: 0; }

        /* Navigation — Anker-Sprungmenü (light, in allen Vorlagen) */
        .pt-nav-anchor .pt-toc { display: block; position: fixed; bottom: 20px; right: 20px; z-index: 50; }
        .pt-toc-btn { display: inline-flex; align-items: center; gap: 8px; background: var(--pt-primary); color: #fff; border: 0; cursor: pointer; padding: 12px 20px; border-radius: 999px; font: 600 .86rem/1 var(--pt-body-font); box-shadow: 0 8px 24px color-mix(in srgb, var(--pt-primary) 34%, transparent); }
        .pt-toc-panel { display: none; position: absolute; bottom: calc(100% + 10px); right: 0; min-width: 220px; max-width: 78vw; max-height: 60vh; overflow-y: auto; padding: 8px; background: var(--pt-bg); border: 1px solid var(--pt-line); border-radius: 12px; box-shadow: 0 12px 40px rgba(0,0,0,.18); }
        .pt-toc.pt-open .pt-toc-panel { display: block; }
        .pt-toc-panel a { display: block; padding: 8px 12px; border-radius: 8px; color: var(--pt-text); text-decoration: none; font-size: .9rem; }
        .pt-toc-panel a:hover, .pt-toc-panel a.pt-active { background: color-mix(in srgb, var(--pt-primary) 10%, transparent); color: var(--pt-primary); }
        .pt-toc-panel a.pt-d1 { padding-left: 24px; font-size: .84rem; color: var(--pt-muted); }
        .pt-toc-panel a.pt-d2 { padding-left: 34px; font-size: .8rem; color: var(--pt-muted); }

        /* Lightbox */
        .pt-zoomable { cursor: zoom-in; transition: transform .45s ease; }
        @media (hover: hover) { .pt-zoomable:hover { transform: scale(1.014); } }
        .pt-lightbox { display: none; position: fixed; inset: 0; z-index: 90; background: rgba(0,0,0,.9); place-items: center; padding: 4vw; cursor: zoom-out; }
        .pt-lightbox.pt-open { display: grid; }
        .pt-lightbox img { max-width: 96vw; max-height: 92vh; width: auto; border-radius: 4px; box-shadow: 0 20px 80px rgba(0,0,0,.6); }

        /* Scroll-Reveal */
        .pt-reveal { opacity: 0; transform: translateY(18px); transition: opacity .7s ease, transform .7s ease; }
        .pt-reveal.pt-in { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) { .pt-reveal { opacity: 1 !important; transform: none !important; transition: none; } }

        @media print {
            .pt-cta, .pt-sidebar, .pt-burger, .pt-toc, .pt-scrim, .pt-lightbox { display: none !important; }
            .pt-nav-sidebar .pt-wrap { padding-left: 0; }
            .pt-hero { min-height: auto; }
            body { background: #fff; }
            .pt-reveal { opacity: 1 !important; transform: none !important; }
            .pt-blocks.pt-cols-2 { grid-template-columns: 1fr 1fr; }
        }
    </style>
    @php $customCss = $snapshot['resolved_design']['custom_css'] ?? null; @endphp
    @if(!empty($customCss))
        {{-- Stufe 2: KI/User-Design-CSS (sanitisiert: kein <, @import, expression). Überschreibt die Basis. --}}
        <style>{!! str_replace(['<', '>'], '', $customCss) !!}</style>
    @endif
</head>
<body data-foodalchemist-presentation="{{ $snapshot['type'] ?? '' }}" class="pt-nav-{{ $nav }}"@if($auto) data-kiosk-auto="1"@endif>
    @if($nav === 'sidebar')
        <button type="button" class="pt-burger" data-pt-drawer aria-label="Navigation">☰</button>
        <button type="button" class="pt-scrim" data-pt-drawer aria-label="Schließen"></button>
        <aside class="pt-sidebar">
            <div class="pt-sidebar-brand">
                @if(!empty($snapshot['branding']['logo']['url']))<img src="{{ $snapshot['branding']['logo']['url'] }}" alt="">@endif
                {{ $snapshot['title'] ?? '' }}
            </div>
            <nav class="pt-sidebar-nav">
                @foreach($navItems as $ni)
                    <a href="#{{ $ni['id'] }}" data-pt-navlink="{{ $ni['id'] }}" class="pt-d{{ $ni['depth'] }}">{{ $ni['title'] }}</a>
                @endforeach
            </nav>
        </aside>
    @endif

    <div class="pt-wrap">
        @yield('content')
        <footer class="pt-footer">
            @if(!empty($snapshot['branding']['footer'])){{ $snapshot['branding']['footer'] }}@endif
        </footer>
    </div>

    @if($nav === 'anchor')
        <div class="pt-toc" data-pt-toc>
            <button type="button" class="pt-toc-btn" data-pt-toc-btn>☰ {{ $navLabel }}</button>
            <div class="pt-toc-panel">
                @foreach($navItems as $ni)
                    <a href="#{{ $ni['id'] }}" data-pt-navlink="{{ $ni['id'] }}" class="pt-d{{ $ni['depth'] }}">{{ $ni['title'] }}</a>
                @endforeach
            </div>
        </div>
    @endif

    @if($lightbox)<div class="pt-lightbox" data-pt-lightbox><img src="" alt=""></div>@endif

    <script>
        (function () {
            // Scroll-Reveal
            var els = document.querySelectorAll('.pt-reveal');
            if ('IntersectionObserver' in window && els.length) {
                var io = new IntersectionObserver(function (entries) {
                    entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('pt-in'); io.unobserve(en.target); } });
                }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
                els.forEach(function (e) { io.observe(e); });
            } else { els.forEach(function (e) { e.classList.add('pt-in'); }); }

            // Off-Canvas-Drawer (Sidebar mobil)
            document.querySelectorAll('[data-pt-drawer]').forEach(function (b) {
                b.addEventListener('click', function () { document.body.classList.toggle('pt-drawer-open'); });
            });

            // Anker-TOC auf/zu
            var toc = document.querySelector('[data-pt-toc]');
            if (toc) {
                toc.querySelector('[data-pt-toc-btn]').addEventListener('click', function (e) { e.stopPropagation(); toc.classList.toggle('pt-open'); });
                document.addEventListener('click', function () { toc.classList.remove('pt-open'); });
            }

            // Smooth-Scroll + Drawer/TOC schließen bei Klick
            document.querySelectorAll('[data-pt-navlink]').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    var t = document.getElementById(a.getAttribute('data-pt-navlink'));
                    if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                    document.body.classList.remove('pt-drawer-open');
                    if (toc) { toc.classList.remove('pt-open'); }
                });
            });

            // Scroll-Spy: aktives Kapitel markieren
            var secs = Array.prototype.slice.call(document.querySelectorAll('[data-pt-section]'));
            var links = {};
            document.querySelectorAll('[data-pt-navlink]').forEach(function (a) { links[a.getAttribute('data-pt-navlink')] = a; });
            if (secs.length && Object.keys(links).length && 'IntersectionObserver' in window) {
                var spy = new IntersectionObserver(function (entries) {
                    entries.forEach(function (en) {
                        if (!en.isIntersecting) { return; }
                        var id = en.target.id, link = links[id];
                        if (!link) { return; }
                        Object.keys(links).forEach(function (k) { links[k].classList.remove('pt-active'); });
                        link.classList.add('pt-active');
                    });
                }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
                secs.forEach(function (s) { spy.observe(s); });
            }

            // Lightbox
            var lb = document.querySelector('[data-pt-lightbox]');
            if (lb) {
                var lbImg = lb.querySelector('img');
                document.querySelectorAll('.pt-zoomable').forEach(function (img) {
                    img.addEventListener('click', function () { lbImg.src = img.currentSrc || img.src; lb.classList.add('pt-open'); });
                });
                lb.addEventListener('click', function () { lb.classList.remove('pt-open'); lbImg.src = ''; });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { lb.classList.remove('pt-open'); } });
            }
        })();
    </script>
</body>
</html>
